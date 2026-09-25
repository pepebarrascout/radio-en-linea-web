<?php
/**
 * ============================================================
 *  Que Chilero Radio — Disparador de avisos push de programas
 * ============================================================
 *  v0.1.6 — Corre en el SERVIDOR cada minuto vía cron y envía el
 *  aviso «En 10 minutos empieza…» a los suscriptores:
 *
 *      * * * * * php /home/USUARIO/public_html/api/push-trigger.php >/dev/null 2>&1
 *
 *  Fuente de datos: programacion.json (la MISMA parrilla que
 *  muestra la web; zona horaria America/Guatemala).
 *
 *  Reglas anti-spam incorporadas (ver README):
 *   - 1 aviso por programa y día (estado idempotente en
 *     api/data/push-state.json; sobrevive a reinicios)
 *   - nunca avisos atrasados: la ventana es [inicio−10, inicio);
 *     si el servidor estuvo caído toda la ventana, se salta
 *   - el reintento de envío lo hace el propio servicio push
 *     (TTL: 300 s); solo se re-intenta la ONDA COMPLETA si no
 *     llegó nada a nadie (máx. 3) para nunca duplicar avisos
 *   - suscripciones muertas (404/410) se limpian solas
 *   - preferencias por suscriptor (franja según la HORA DE INICIO
 *     del programa): "all" todos · "morning" 06:00–14:00 ·
 *     "afternoon" 14:00–21:00 · "day" 06:00–21:00; el modo legado
 *     "evening" se interpreta como "afternoon"
 *
 *  Comandos CLI:
 *      php push-trigger.php                          → ciclo normal
 *      php push-trigger.php --verbose                → detalle
 *      php push-trigger.php --dry-run                → qué enviaría (sin enviar)
 *      php push-trigger.php --anuncio="Texto libre"  → aviso manual a TODOS
 *      php push-trigger.php --test-send              → notificación de prueba a TODOS
 *      php push-trigger.php --generate-keys          → claves VAPID (UNA vez)
 *
 *  También por HTTP con la misma clave del cron (health-checks):
 *      https://tu-dominio/api/push-trigger.php?key=TU_CLAVE
 * ============================================================
 */

require_once __DIR__ . '/push-lib.php';

// ── Configuración ────────────────────────────────────────────

// Estado idempotente de avisos del día (carpeta data/, protegida)
define('PUSH_STATE_FILE', getenv('QCR_PUSH_STATE_FILE') ?: __DIR__ . '/data/push-state.json');

// Parrilla semanal: la misma que muestra la web
define('PROGRAMACION_FILE', getenv('QCR_PROGRAMACION_FILE') ?: __DIR__ . '/../programacion.json');

// Clave para llamadas por HTTP (misma del cron-update). CLI siempre permitido.
define('CRON_SECRET_KEY', getenv('QCR_CRON_SECRET')
    ?: (defined('QCR_CRON_SECRET_VALUE') ? QCR_CRON_SECRET_VALUE : ''));

// Minutos de antelación del aviso (10 por defecto)
define('PUSH_LEAD_MINUTES', max(1, (int)(getenv('QCR_PUSH_LEAD_MINUTES') ?: 10)));

// Modo "evening" (legado) y ventanas horarias viven en push-lib.php
// (PUSH_MORNING_START/END, PUSH_DAY_END, pushModeAllows).

// Máximo de ondas completas si NUNCA llegó nada (error de red del hosting)
define('PUSH_MAX_WAVE_ATTEMPTS', 3);

// Log acumulativo (comenta la línea final si no lo quieres)
define('PUSH_LOG_FILE', getenv('QCR_PUSH_LOG_FILE') ?: __DIR__ . '/data/push-trigger.log');

// Días en español (N: 1=Lunes … 7=Domingo)
const PUSH_DAYS_ES = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

// ── Restricción de acceso (idéntica a cron-update.php) ───────

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    if (CRON_SECRET_KEY === '' || !hash_equals(CRON_SECRET_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Acceso denegado. Este script está pensado para ejecutarse por CLI (cron).']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

// ── Utilidades ───────────────────────────────────────────────

/** "HH:MM" → minutos desde medianoche; null si es inválido. */
function pushParseHoraMin(?string $hora): ?int
{
    $hora = trim((string)$hora);
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hora, $m)) {
        return null;
    }
    $h = (int)$m[1];
    $min = (int)$m[2];
    if ($h > 23 || $min > 59) {
        return null;
    }
    return $h * 60 + $min;
}

/** Slug sencillo para el tag de la notificación. */
function pushSlug(string $text): string
{
    $text = pushLower($text);
    // Transliterar acentos si el host lo permite (Miércoles → miercoles)
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii !== false && $ascii !== '') {
            $text = $ascii;
        }
    }
    $text = preg_replace('/[^a-z0-9]+/', '-', (string)$text);
    return trim((string)$text, '-') ?: 'programa';
}

/**
 * Lee el estado del día (idempotencia). Si el archivo es de otro
 * día se reinicia: { ymd, sent:{}, attempts:{}, flags:{} }
 */
function pushReadState(string $todayYmd): array
{
    $state = ['ymd' => $todayYmd, 'sent' => [], 'attempts' => [], 'flags' => []];
    if (!is_file(PUSH_STATE_FILE)) {
        return $state;
    }
    $fp = fopen(PUSH_STATE_FILE, 'r');
    if (!$fp) {
        return $state;
    }
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode((string)$content, true);
    if (!is_array($data) || ($data['ymd'] ?? '') !== $todayYmd) {
        return $state;   // otro día → estado fresco
    }
    return [
        'ymd' => $todayYmd,
        'sent' => is_array($data['sent'] ?? null) ? $data['sent'] : [],
        'attempts' => is_array($data['attempts'] ?? null) ? $data['attempts'] : [],
        'flags' => is_array($data['flags'] ?? null) ? $data['flags'] : [],
    ];
}

/** Escritura atómica del estado. */
function pushWriteState(array $state): void
{
    $dir = dirname(PUSH_STATE_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $fp = fopen(PUSH_STATE_FILE, 'c+');
    if (!$fp) {
        return;
    }
    flock($fp, LOCK_EX);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * ⭐ Núcleo del disparador (función PURA, testeable).
 *
 * Devuelve los programas de HOY cuyo aviso corresponde enviar
 * AHORA MISMO: ventana [inicio − leadMin, inicio) y aún sin avisar.
 * Nunca incluye programas ya empezados (el mensaje no puede mentir).
 *
 * $schedule  = contenido de programacion_radio
 * $sentMap   = estado['sent'] del día
 * $nowTs     = timestamp actual (America/Guatemala)
 */
function computeDuePrograms(array $schedule, array $sentMap, int $nowTs, int $leadMin): array
{
    $nowMin  = (int)date('G', $nowTs) * 60 + (int)date('i', $nowTs);
    $todayEs = PUSH_DAYS_ES[(int)date('N', $nowTs)] ?? '';
    $todayYmd = date('Y-m-d', $nowTs);

    $due = [];
    foreach ($schedule as $program) {
        if (!is_array($program)) {
            continue;
        }
        if (pushLower((string)($program['dia'] ?? '')) !== pushLower($todayEs)) {
            continue;
        }
        $start = pushParseHoraMin($program['hora_inicio'] ?? null);
        if ($start === null) {
            continue;
        }
        // Ventana del aviso: [inicio − lead, inicio)
        if ($nowMin < $start - $leadMin || $nowMin >= $start) {
            continue;
        }

        $nombre = trim((string)($program['programa'] ?? ''));
        if ($nombre === '') {
            continue;
        }

        // Clave idempotente del día: fecha | hora | programa
        $key = $todayYmd . '|' . $program['hora_inicio'] . '|' . pushLower($nombre);
        if (!empty($sentMap[$key])) {
            continue;   // ya avisado hoy
        }

        $due[] = [
            'programa'    => $nombre,
            'hora_inicio' => (string)$program['hora_inicio'],
            'start_min'   => $start,
            'key'         => $key,
        ];
    }

    return $due;
}

/** Log de una línea al archivo acumulativo. */
function pushLogLine(string $line): void
{
    @file_put_contents(PUSH_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Envía un payload a TODOS los suscriptores que pasen el filtro.
 * $goneEndpoints acumula los endpoints muertos para limpiarlos al final.
 * Devuelve ['sent','gone','failed','retryable','targeted'].
 */
function pushSendWave(array $subscribers, array $payload, callable $filter, array &$goneEndpoints): array
{
    $out = ['sent' => 0, 'gone' => 0, 'failed' => 0, 'retryable' => 0, 'targeted' => 0];
    foreach ($subscribers as $sub) {
        if (!is_array($sub) || !$filter($sub)) {
            continue;
        }
        $out['targeted']++;
        $res = pushSendToSubscription($sub, $payload);
        if ($res['ok']) {
            $out['sent']++;
        } elseif ($res['gone']) {
            $out['gone']++;
            $goneEndpoints[] = (string)($sub['endpoint'] ?? '');
        } elseif ($res['retryable']) {
            $out['retryable']++;
        } else {
            $out['failed']++;
        }
    }
    return $out;
}

// ── Parámetros CLI ───────────────────────────────────────────

$verbose  = false;
$dryRun   = false;
$anuncio  = null;
$testSend = false;

if ($isCli) {
    $argvFlat = array_values(array_slice($argv ?? [], 1));
    for ($i = 0; $i < count($argvFlat); $i++) {
        $arg = $argvFlat[$i];
        if ($arg === '--verbose' || $arg === '-v') {
            $verbose = true;
        } elseif ($arg === '--dry-run') {
            $dryRun = true;
        } elseif ($arg === '--test-send') {
            $testSend = true;
        } elseif ($arg === '--anuncio') {
            $anuncio = $argvFlat[$i + 1] ?? '';
            $i++;
        } elseif (str_starts_with($arg, '--anuncio=')) {
            $anuncio = substr($arg, strlen('--anuncio='));
        } elseif ($arg === '--generate-keys') {
            // ── Generación de claves VAPID (UNA vez) ──
            $keys = pushGenerateKeys();
            echo "\nClaves VAPID generadas (una sola vez; guardarlas y no volver a generar):\n\n";
            echo "En api/config.php:\n\n";
            echo "define('QCR_VAPID_PUBLIC_KEY_VALUE', '{$keys['public_key']}');\n\n";
            // ⚠️ La privada usa \\n literales → COMILLAS DOBLES (PHP las
            // interpreta como saltos de línea). En simples NO funcionaría.
            echo "define('QCR_VAPID_PRIVATE_PEM_VALUE', \"";
            echo str_replace(["\r\n", "\n"], '\n', trim($keys['private_pem']));
            echo "\");\n\n";
            echo "Pega ambas líneas tal cual (la privada va entre comillas DOBLES\n";
            echo "para que PHP convierta los \\n literales en saltos de línea).\n";
            echo "Después ejecuta una vez el disparador para verificar:\n";
            echo "  php api/push-trigger.php --verbose\n";
            exit(0);
        }
    }
}

// ── Sin claves VAPID: terminar en silencio (el cron no debe llenar logs) ──

if (!pushVapidConfigured()) {
    if ($isCli && $verbose) {
        echo "Push deshabilitado: faltan las claves VAPID en api/config.php.\n";
        echo "Genera las claves una vez con: php api/push-trigger.php --generate-keys\n";
    }
    exit(0);
}

// ── Aviso manual y notificación de prueba (a TODOS, sin filtro de modo) ──

if ($anuncio !== null || $testSend) {
    $payload = $anuncio !== null
        ? [
            'title' => 'Que Chilero Radio',
            'body'  => trim($anuncio),
            'url'   => './',
            'tag'   => 'anuncio-' . date('Ymd-His'),
        ]
        : [
            'title' => 'Que Chilero Radio',
            'body'  => 'Prueba de los avisos de programas: si lees esto, todo funciona.',
            'url'   => './',
            'tag'   => 'prueba-' . date('Ymd-His'),
        ];

    if (trim((string)$payload['body']) === '') {
        if ($isCli) {
            fwrite(STDERR, "[ERROR] --anuncio requiere el texto: --anuncio=\"Tu aviso importante\"\n");
            exit(1);
        }
        echo json_encode(['error' => 'texto requerido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $subscribers = pushReadSubscribers();
    $gone = [];
    $summary = pushSendWave($subscribers, $payload, static fn () => true, $gone);
    if ($gone) {
        pushRemoveEndpoints($gone);
    }

    $line = "ANUNCIO: \"{$payload['body']}\" → enviados {$summary['sent']}/{$summary['targeted']}"
          . ", limpiadas {$summary['gone']}, fallos {$summary['failed']}";
    if ($isCli) {
        echo $line . "\n";
        if ($summary['retryable'] > 0) {
            echo "({$summary['retryable']} con error temporal: el servicio push reintentará durante la ventana TTL)\n";
        }
    }
    pushLogLine($line);
    exit(0);
}

// ── Ciclo normal: avisar los programas que empiezan en ~10 minutos ──

$nowTs  = time();
$todayYmd = date('Y-m-d', $nowTs);

// Parrilla semanal
if (!is_file(PROGRAMACION_FILE)) {
    $msg = 'No se encontró programacion.json (ruta: ' . PROGRAMACION_FILE . ')';
    if ($isCli) {
        if ($verbose) {
            fwrite(STDERR, "[ERROR] {$msg}\n");
        }
        pushLogLine("ERROR: {$msg}");
        exit(1);
    }
    echo json_encode(['error' => 'programacion_no_encontrada'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = (string)file_get_contents(PROGRAMACION_FILE);
$data = json_decode($raw, true);
$schedule = is_array($data) ? ($data['programacion_radio'] ?? []) : [];
if (!is_array($schedule)) {
    $schedule = [];
}

$state = pushReadState($todayYmd);
$due = computeDuePrograms($schedule, $state['sent'], $nowTs, PUSH_LEAD_MINUTES);

// ── Envío ──
$subscribers = pushReadSubscribers();
$goneEndpoints = [];
$report = [];

foreach ($due as $program) {
    $key = $program['key'];

    $payload = [
        'title' => 'Que Chilero Radio',
        'body'  => 'Te invitamos a escuchar «' . $program['programa'] . '», que empieza a las '
                 . $program['hora_inicio'] . '. ¡Te esperamos!',
        'url'   => './?autoplay=1',
        'tag'   => 'programa-' . date('Ymd', $nowTs) . '-' . pushSlug($program['programa']),
    ];

    if ($dryRun) {
        $report[] = ['programa' => $program['programa'], 'hora' => $program['hora_inicio'], 'accion' => 'dry-run'];
        continue;
    }

    $filter = static fn (array $sub): bool => pushModeAllows($sub, $program['start_min']);
    $wave = pushSendWave($subscribers, $payload, $filter, $goneEndpoints);

    // Idempotencia: el aviso queda marcado SIEMPRE que al menos una
    // entrega llegó (o no había a quién). Solo se re-intenta la onda
    // completa si NO llegó nada a nadie por error temporal del hosting,
    // con un tope de 3 intentos → jamás se duplica un aviso.
    $attempts = (int)($state['attempts'][$key] ?? 0) + 1;
    $state['attempts'][$key] = $attempts;

    $waveFinal = $wave['sent'] > 0
        || $wave['retryable'] === 0
        || $attempts >= PUSH_MAX_WAVE_ATTEMPTS;

    if ($waveFinal) {
        $state['sent'][$key] = 1;
        unset($state['attempts'][$key]);
    }

    $report[] = [
        'programa' => $program['programa'],
        'hora'     => $program['hora_inicio'],
        'enviados' => $wave['sent'],
        'dirigidos' => $wave['targeted'],
        'limpiadas' => $wave['gone'],
        'fallos'   => $wave['failed'] + $wave['retryable'],
        'reintento_onda' => !$waveFinal,
    ];

    pushLogLine(
        "AVISO: \"{$program['programa']}\" ({$program['hora_inicio']}) → "
        . "enviados {$wave['sent']}/{$wave['targeted']}, limpiadas {$wave['gone']}, fallos "
        . ($wave['failed'] + $wave['retryable'])
        . ($waveFinal ? '' : " — reintentando onda (intento {$attempts}/" . PUSH_MAX_WAVE_ATTEMPTS . ')')
    );
}

if (!$dryRun) {
    if ($goneEndpoints) {
        pushRemoveEndpoints($goneEndpoints);
    }
    pushWriteState($state);
}

// ── Salida ──

if ($isCli) {
    if ($verbose) {
        $subTotal = count($subscribers);
        echo "Avisos push — " . date('Y-m-d H:i:s') . " (America/Guatemala)\n";
        echo "Suscriptores: {$subTotal} | Programas por avisar ahora: " . count($due) . "\n";
        foreach ($report as $r) {
            if (isset($r['accion'])) {
                echo "  [dry-run] {$r['programa']} ({$r['hora']})\n";
            } else {
                echo "  {$r['programa']} ({$r['hora']}): enviados {$r['enviados']}/{$r['dirigidos']}"
                   . ", limpiadas {$r['limpiadas']}, fallos {$r['fallos']}\n";
            }
        }
        if (!count($report)) {
            echo "Nada que avisar en este minuto.\n";
        }
    } elseif (count($report)) {
        // Línea única para el log del cron
        $parts = [];
        foreach ($report as $r) {
            $parts[] = isset($r['accion'])
                ? "{$r['programa']} [dry-run]"
                : "{$r['programa']} {$r['enviados']}/{$r['dirigidos']}";
        }
        echo "[" . date('Y-m-d H:i:s') . "] AVISOS: " . implode(' | ', $parts) . "\n";
    }
    exit(0);
}

// Respuesta HTTP (health-check)
echo json_encode([
    'timestamp' => date('c'),
    'due'       => count($due),
    'report'    => $report,
    'dry_run'   => $dryRun,
], JSON_UNESCAPED_UNICODE);
