<?php
/**
 * ============================================================
 *  Que Chilero Radio — Actualizador automático de historial
 * ============================================================
 *  ⭐ ESTE ARCHIVO RESUELVE EL PROBLEMA PRINCIPAL ⭐
 *
 *  Antes, el historial solo se actualizaba cuando un visitante
 *  tenía la web abierta (era el navegador de cada oyente quien
 *  registraba las canciones). Si nadie visitaba la web, la
 *  lista quedaba congelada y las canciones emitidas se perdían.
 *
 *  Este script corre en el SERVIDOR cada minuto vía cron y
 *  registra las canciones 24/7 aunque nadie esté visitando
 *  la página:
 *
 *    1. Consulta el endpoint NowPlaying del plugin Jellyfin
 *    2. Si la canción es nueva (o la playlist repitió un tema
 *       ya pasado su tiempo), la registra en history.json
 *    3. Es idempotente: puede ejecutarse cualquier cantidad de
 *       veces sin generar duplicados
 *
 *  Configuración del cron (cPanel / crontab):
 *
 *      * * * * * php /ruta/a/tu-web/api/cron-update.php >/dev/null 2>&1
 *
 *  También puede ejecutarse manualmente por CLI:
 *
 *      php cron-update.php              → usa la URL configurada
 *      php cron-update.php --url=URL    → usa otra URL (para pruebas)
 *      php cron-update.php --verbose    → muestra el detalle
 *
 *  O por HTTP (útil para health-checks externos):
 *
 *      https://tu-dominio.com/qc/api/cron-update.php?key=TU_CLAVE
 *
 *  ⚠️  Si lo llamas por HTTP, define CRON_SECRET_KEY abajo con
 *      una clave aleatoria y pásala como ?key=... (si la clave
 *      queda vacía, el acceso HTTP queda deshabilitado y solo
 *      funcionará por CLI, que es lo recomendado).
 * ============================================================
 */

// ── Configuración ────────────────────────────────────────────

// Valores privados opcionales (api/config.php NO se versiona;
// copia config.example.php). Ninguno es obligatorio.
if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Clave para llamadas por HTTP (deja vacía = solo CLI).
// Orden: variable de entorno → api/config.php → vacía.
// Genera una aleatoria con: php -r "echo bin2hex(random_bytes(16));"
define('CRON_SECRET_KEY', getenv('QCR_CRON_SECRET')
    ?: (defined('QCR_CRON_SECRET_VALUE') ? QCR_CRON_SECRET_VALUE : ''));

// ── Restricción de acceso ────────────────────────────────────

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    // Llamada por HTTP: exigir clave si está configurada
    if (CRON_SECRET_KEY === '' || !hash_equals(CRON_SECRET_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Acceso denegado. Este script está pensado para ejecutarse por CLI (cron).']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

// ── Carga de la librería compartida ──────────────────────────

require_once __DIR__ . '/history-lib.php';
require_once __DIR__ . '/covers-lib.php';   // incluye np-lib.php (URL configurada, nunca hardcodeada)

// Endpoint de metadatos del plugin RadioOnline.
// Orden: variable de entorno → api/config.php. ⭐ Sin valor por
// defecto: el dominio del servidor NUNCA va escrito en el código.
define('NOW_PLAYING_URL', qcrNowPlayingUrl());

// Timeout de la consulta al servidor de la radio (segundos)
define('HTTP_TIMEOUT', 8);

// ── Parámetros CLI ───────────────────────────────────────────

$verbose = false;
$url     = NOW_PLAYING_URL;

if ($isCli) {
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--verbose' || $arg === '-v') {
            $verbose = true;
        } elseif (str_starts_with($arg, '--url=')) {
            $url = substr($arg, 6);   // solo para pruebas
        }
    }
}

// Sin URL configurada (ni por CLI de pruebas) no hay nada que
// consultar: avisa con la instrucción exacta y termina en error.
if ($url === '') {
    $msg = 'Falta configurar la URL de NowPlaying: define QCR_NOWPLAYING_URL (entorno) '
         . 'o QCR_NOWPLAYING_URL_VALUE en api/config.php (copia config.example.php).';
    if ($isCli) {
        fwrite(STDERR, "[ERROR] {$msg}\n");
        exit(1);
    }
    http_response_code(503);
    echo json_encode(['error' => 'nowplaying_no_configurado', 'mensaje' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Consulta del "Now Playing" ───────────────────────────────

/**
 * Descarga el JSON del endpoint NowPlaying.
 * Usa cURL si está disponible y cae a file_get_contents si no.
 * Devuelve [array|null $data, string|null $error].
 */
function fetchNowPlaying(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'QueChileroRadio-Cron/1.0',
        ]);
        // Constante no presente en algunas compilaciones de PHP: aplicar solo si existe
        if (defined('CURLOPT_MAX_REDIRECTS')) {
            curl_setopt($ch, CURLOPT_MAX_REDIRECTS, 3);
        }
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            return [null, "cURL error: {$err}"];
        }
        if ($code !== 200) {
            return [null, "HTTP {$code}"];
        }
    } else {
        // Fallback sin cURL (requiere allow_url_fopen = On)
        $ctx = stream_context_create([
            'http' => ['timeout' => HTTP_TIMEOUT, 'user_agent' => 'QueChileroRadio-Cron/1.0'],
            'ssl'  => ['verify_peer' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return [null, 'file_get_contents falló (revisa allow_url_fopen o instala cURL)'];
        }
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return [null, 'Respuesta no es JSON válido'];
    }

    return [$data, null];
}

[$data, $error] = fetchNowPlaying($url);

$log = [
    'timestamp'  => date('Y-m-d H:i:s'),
    'url'        => $url,
    'error'      => $error,
    'registered' => false,
    'reason'     => null,
    'song'       => null,
];

if ($error !== null) {
    $log['reason'] = 'fetch_error';
    if ($isCli) {
        fwrite(STDERR, "[" . $log['timestamp'] . "] ERROR consultando NowPlaying: {$error}\n");
        exit(1);
    }
    echo json_encode($log, JSON_UNESCAPED_UNICODE);
    exit;
}

// El endpoint devuelve {"isPlaying": false} cuando no hay transmisión
$isPlaying = !empty($data['isPlaying']);
$title     = trim((string)($data['title'] ?? ''));
$artist    = trim((string)($data['artist'] ?? ''));

if (!$isPlaying || $title === '' || $artist === '') {
    $log['reason'] = 'not_playing';
    if ($isCli) {
        if ($verbose) {
            echo "[" . $log['timestamp'] . "] Sin transmisión activa. Nada que registrar.\n";
        }
        exit(0);
    }
    echo json_encode($log, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Registro idempotente en el historial ─────────────────────

$artworkUrl = (string)($data['artworkUrl'] ?? '');

$song = [
    'title'    => $title,
    'artist'   => $artist,
    'itemId'   => (string)($data['itemId'] ?? ''),   // ID de Jellyfin (para votos y consultas)
    'genre'    => (string)($data['genre'] ?? ''),
    'duration' => (string)($data['duration'] ?? ''),
];

[$registered, $history, $reason] = registerSong($song);

// ── Portada de la canción ────────────────────────────────────
// Jellyfin solo expone la portada de la canción ACTUAL, así que
// se descarga redimensionada (150px) justo cuando suena y queda
// guardada en api/covers/ para poder mostrarla en el historial.

if ($registered) {
    $coverStatus = ensureCoverCached($artist, $title, $artworkUrl);
} else {
    // Auto-sanidad: si la portada de la canción actual falta
    // (p. ej. falló la descarga en su momento), se reintenta.
    $coverStatus = is_file(coverFilePath($artist, $title))
        ? 'cached'
        : ensureCoverCached($artist, $title, $artworkUrl);
}

// ── Limpieza de portadas fuera del listado ───────────────────
// Borra las portadas de canciones que ya salieron del top 10.
$coversDeleted = gcCovers($history);

// ── Retro-relleno de portadas viejas (v0.1.4) ───────────────────
// Repara filas del historial con portada nula usando la plantilla
// de imágenes de Jellyfin (config opcional QCR_JELLYFIN_IMAGES_URL
// en config.php; ver README). Máximo 2 descargas por pasada: el
// cron corre cada minuto, así la reparación es suave y constante.
// Sin plantilla configurada esta llamada es un no-op barato.
$backfill = backfillMissingCovers($history, 2);

$log['registered'] = $registered;
$log['reason']     = $reason;
$log['cover']      = $coverStatus;
$log['covers_gc']  = $coversDeleted;
$log['covers_backfill'] = $backfill;
$log['song']       = [
    'title'  => $title,
    'artist' => $artist,
    'time'   => date('H:i'),
];

// ── Salida / log ─────────────────────────────────────────────

if ($isCli) {
    // Log de una línea en el formato estándar de syslog/cron
    $status = $registered ? 'REGISTRADA' : strtoupper($reason);
    $line   = "[" . $log['timestamp'] . "] {$status}: {$artist} - {$title} [portada: {$coverStatus}]";

    if ($verbose) {
        echo $line . "\n";
        if ($coversDeleted > 0) {
            echo "Portadas fuera del listado borradas: {$coversDeleted}\n";
        }
        if ($backfill['attempted'] > 0) {
            echo "Retro-relleno de portadas: {$backfill['downloaded']} reparadas, "
               . "{$backfill['failed']} fallidas (de {$backfill['attempted']} intentos)\n";
        }
        echo "Historial actual (" . count($history) . " entradas):\n";
        foreach ($history as $i => $h) {
            echo sprintf(
                "  %2d. [%s] %s — %s (%s)\n",
                $i + 1,
                $h['time'] ?? '??:??',
                $h['artist'] ?? '?',
                $h['title'] ?? '?',
                $h['duration'] ?? '--:--'
            );
        }
    } else {
        echo $line . "\n";
    }

    // Log opcional a archivo (comenta la siguiente línea si no lo quieres)
    @file_put_contents(__DIR__ . '/cron-update.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(0);
}

// Respuesta HTTP (health-check)
echo json_encode($log, JSON_UNESCAPED_UNICODE);
