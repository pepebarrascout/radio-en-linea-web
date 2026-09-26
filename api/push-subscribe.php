<?php
/**
 * ============================================================
 *  Que Chilero Radio — Alta y baja de suscripciones push
 * ============================================================
 *  v0.1.7 — Almacén JSON puro (sin base de datos), igual que los
 *  votos: api/data/subscribers.json (carpeta protegida por
 *  .htaccess; los navegadores NUNCA acceden a este archivo).
 *
 *    [ { endpoint, keys:{p256dh, auth}, mode, created, updated } ]
 *
 *  POST (suscribir / cambiar preferencias)
 *    body: { endpoint, keys:{p256dh, auth}, prefs:{mode} }
 *      mode acepta UNA franja, varias separadas por coma o un array:
 *        "noche"        → inicio del programa 22:00–04:59
 *        "manana"       → inicio del programa 05:00–13:59
 *        "tarde"        → inicio del programa 14:00–20:59
 *        "all"          → todos los programas (excluyente)
 *        "none"         → ninguno, solo anuncios manuales
 *                         (excluyente)
 *      Ejemplos: "noche,tarde", ["manana"], "all".
 *      Se guarda normalizado como CSV canónico en "mode". Los modos
 *      legados morning/afternoon/day (v0.1.6) y evening (v0.1.4/
 *      v0.1.5) se siguen aceptando y se convierten a las franjas
 *      nuevas al guardar.
 *    resp: { ok:true, mode:"noche,tarde", total }
 *
 *  GET (leer las preferencias de UNA suscripción — lo usa la web
 *  para prellenar el selector de «Cambiar franjas»)
 *    query: ?endpoint=https://…
 *    resp:  { ok:true, mode:"noche,tarde", total } · 404 si no existe
 *
 *  DELETE (baja — también la usa el botón «Silenciar avisos»
 *  dentro de la propia notificación, vía service worker)
 *    body: { endpoint }
 *    resp: { ok:true, total }
 *
 *  Anti-abuso: límite duro de suscripciones; entradas inválidas
 *  rechazadas con 400; respuestas no-cacheables.
 * ============================================================
 */

require_once __DIR__ . '/push-lib.php';

// ── Configuración ────────────────────────────────────────────

// Franjas válidas (PUSH_PREF_VALUES), ventanas horarias y
// normalización multi-franja viven en push-lib.php (las comparte
// con push-trigger.php).

// Límite anti-abuso
const MAX_SUBSCRIBERS = 2000;

// Tamaño máximo del cuerpo aceptado
const MAX_BODY_BYTES = 4096;

// ── Validación ───────────────────────────────────────────────

/**
 * Valida y normaliza el cuerpo de una suscripción.
 * Devuelve [array|null $record, string|null $error].
 */
function validateSubscriptionPayload(string $raw): array
{
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [null, 'cuerpo no es JSON válido'];
    }

    $endpoint = trim((string)($data['endpoint'] ?? ''));
    if ($endpoint === '' || strlen($endpoint) > 1000 || !pushEndpointAllowed($endpoint)) {
        return [null, 'endpoint inválido'];
    }

    $p256dh = (string)($data['keys']['p256dh'] ?? '');
    $auth   = (string)($data['keys']['auth'] ?? '');

    // p256dh: punto P-256 sin comprimir en base64url (65 bytes)
    $point = pushB64UrlDecode($p256dh);
    if (strlen($point) !== 65 || $point[0] !== "\x04") {
        return [null, 'keys.p256dh inválido'];
    }

    // auth: secreto de autenticación (16 bytes típicos, acepta 8-48)
    $authSec = pushB64UrlDecode($auth);
    if (strlen($authSec) < 8 || strlen($authSec) > 48) {
        return [null, 'keys.auth inválido'];
    }

    // v0.1.7: multi-franja. "mode" acepta un valor, CSV o array;
    // se normaliza en ESTRICTO y se guarda como CSV canónico.
    $prefs = $data['prefs'] ?? [];
    $modeRaw = is_array($prefs) ? ($prefs['mode'] ?? 'all') : 'all';
    $norm = pushNormalizePrefs($modeRaw, true);
    if (!$norm['ok']) {
        return [null, $norm['error']];
    }

    $record = [
        'endpoint' => $endpoint,
        'keys'     => ['p256dh' => $p256dh, 'auth' => $auth],
        'mode'     => pushPrefsToCsv($norm['prefs']),   // CSV canónico
    ];

    return [$record, null];
}

// ── Endpoint ─────────────────────────────────────────────────

if (PHP_SAPI === 'cli') {
    // CLI: solo para pruebas manuales del propio archivo
    echo "Este endpoint es para el navegador (POST/DELETE JSON).\n";
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawBody = (string)file_get_contents('php://input');
if (strlen($rawBody) > MAX_BODY_BYTES) {
    http_response_code(413);
    echo json_encode(['error' => 'cuerpo demasiado grande'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── GET: preferencias de una suscripción (prellenar «Cambiar franjas») ──

if ($method === 'GET') {
    $endpoint = trim((string)($_GET['endpoint'] ?? ''));
    if ($endpoint === '' || strlen($endpoint) > 1000 || !pushEndpointAllowed($endpoint)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'endpoint requerido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $list = pushReadSubscribers();
    foreach ($list as $existing) {
        if (!is_array($existing) || ($existing['endpoint'] ?? '') !== $endpoint) {
            continue;
        }
        echo json_encode(
            [
                'ok' => true,
                // Mismo camino de normalización que usa el trigger →
                // el prellenado refleja el comportamiento real.
                'mode' => pushPrefsToCsv(pushPrefsOfSubscriber($existing)),
                'total' => count($list),
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'suscripción no encontrada'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    [$record, $error] = validateSubscriptionPayload($rawBody);
    if ($record === null) {
        http_response_code(400);
        echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Lectura-modificación-escritura atómica (lock exclusivo)
    $fp = fopen(pushSubscribersFile(), 'c+');
    if (!$fp) {
        http_response_code(500);
        echo json_encode(['error' => 'storage_error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        http_response_code(500);
        echo json_encode(['error' => 'cannot_lock_file'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $content = stream_get_contents($fp);
    $list = json_decode((string)$content, true);
    $list = is_array($list) ? $list : [];

    $found = false;
    foreach ($list as $i => $existing) {
        if (($existing['endpoint'] ?? '') === $record['endpoint']) {
            // Actualización: nuevas claves y/o modo (conserva created)
            $record['created'] = $existing['created'] ?? time();
            $list[$i] = $record;
            $found = true;
            break;
        }
    }
    if (!$found) {
        if (count($list) >= MAX_SUBSCRIBERS) {
            flock($fp, LOCK_UN);
            fclose($fp);
            http_response_code(503);
            echo json_encode(['error' => 'lista de suscriptores llena'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $record['created'] = time();
        $list[] = $record;
    }

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    echo json_encode(
        ['ok' => true, 'mode' => $record['mode'], 'total' => count($list)],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($method === 'DELETE') {
    $data = json_decode($rawBody, true);
    $endpoint = is_array($data) ? trim((string)($data['endpoint'] ?? '')) : '';
    if ($endpoint === '') {
        http_response_code(400);
        echo json_encode(['error' => 'endpoint requerido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $list = pushReadSubscribers();
    $before = count($list);
    $list = array_values(array_filter(
        $list,
        static fn ($s) => ($s['endpoint'] ?? '') !== $endpoint
    ));

    if (count($list) !== $before) {
        pushWriteSubscribers($list);
    }

    echo json_encode(
        ['ok' => true, 'total' => count($list), 'removed' => $before - count($list)],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

http_response_code(405);
header('Allow: GET, POST, DELETE');
echo json_encode(['error' => 'método no permitido'], JSON_UNESCAPED_UNICODE);
