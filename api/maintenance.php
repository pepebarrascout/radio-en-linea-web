<?php
/**
 * ============================================================
 *  Que Chilero Radio — Mantenimiento CLI (v0.1.4)
 * ============================================================
 *  Herramienta de una línea para la salud del historial y las
 *  portadas. SOLO por CLI (nunca por HTTP: puede borrar archivos).
 *
 *  Comandos:
 *    covers:audit      Informe SIN borrar nada: archivos válidos,
 *                      inválidos, vacíos, duplicados por contenido
 *                      y filas del historial sin portada.
 *    covers:clean      Borra lo detectado: nombres inválidos,
 *                      .jpg de 0 bytes y duplicados por contenido
 *                      (conserva la copia más reciente).
 *    covers:backfill   Fuerza el retro-relleno de portadas nulas
 *                      por itemId (máx. 50 por pasada; añade
 *                      --force para ignorar la espera de 6 h).
 *    history:dedupe    Colapsa en el propio history.json las
 *                      emisiones duplicadas (rebote de metadatos).
 *
 *  Ejemplos:
 *      php api/maintenance.php covers:audit
 *      php api/maintenance.php covers:clean
 *      php api/maintenance.php covers:backfill --force
 *      php api/maintenance.php history:dedupe
 * ============================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Solo por CLI (puede borrar archivos).']);
    exit;
}

require_once __DIR__ . '/history-lib.php';
require_once __DIR__ . '/covers-lib.php';

const MAINT_MAX_BACKFILL = 50;

/** Salida con salto de línea. */
function say(string $text = ''): void
{
    echo $text . "\n";
}

/** Formato de bytes. */
function fmtBytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return sprintf('%.1f MB', $bytes / 1048576);
    }
    if ($bytes >= 1024) {
        return sprintf('%.1f KB', $bytes / 1024);
    }
    return $bytes . ' B';
}

/**
 * Escanea la carpeta de portadas y clasifica cada archivo.
 * Devuelve un informe completo (sin tocar nada).
 */
function auditCovers(array $history): array
{
    $report = [
        'total' => 0, 'bytes' => 0,
        'valid' => [], 'invalid_names' => [], 'zero_bytes' => [],
        'duplicates' => [], 'tmp' => [],
        'history_missing' => [], 'history_missing_no_itemid' => 0,
    ];

    $validKeys = [];
    foreach ($history as $entry) {
        $artist = (string)($entry['artist'] ?? '');
        $title  = (string)($entry['title'] ?? '');
        if ($artist === '' || $title === '') {
            continue;
        }
        $key = songKey($artist, $title);
        $validKeys[$key] = true;
        if (!is_file(coverFilePath($artist, $title)) || @filesize(coverFilePath($artist, $title)) === 0) {
            $report['history_missing'][] = [
                'artist'  => $artist,
                'title'   => $title,
                'itemId'  => (string)($entry['itemId'] ?? ''),
                'key'     => $key,
            ];
            if (sanitizeItemId((string)($entry['itemId'] ?? '')) === '') {
                $report['history_missing_no_itemid']++;
            }
        }
    }

    if (!is_dir(COVERS_DIR)) {
        $report['valid_keys_count'] = count($validKeys);
        return $report;
    }

    $byContent = [];
    $files = glob(COVERS_DIR . '/*') ?: [];

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $base = basename($file);
        $report['total']++;

        if (str_ends_with($base, '.tmp')) {
            $report['tmp'][] = $base;
            continue;
        }
        if (!str_ends_with($base, '.jpg') || !preg_match('/^[0-9a-f]{32}$/', substr($base, 0, -4))) {
            $report['invalid_names'][] = $base;
            continue;
        }

        $size = (int)@filesize($file);
        $report['bytes'] += $size;
        if ($size === 0) {
            $report['zero_bytes'][] = $base;
            continue;
        }

        if (isset($validKeys[substr($base, 0, -4)])) {
            $report['valid'][] = $base;
        }

        // Agrupación por contenido real (detecta duplicados de cualquier origen)
        $hash = md5_file($file);
        if ($hash !== false) {
            $byContent[$hash][] = ['file' => $base, 'mtime' => (int)@filemtime($file), 'size' => $size];
        }
    }

    foreach ($byContent as $group) {
        if (count($group) > 1) {
            // La más reciente manda; las demás son duplicados
            usort($group, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
            $report['duplicates'][] = [
                'keep'   => $group[0]['file'],
                'remove' => array_slice(array_column($group, 'file'), 1),
            ];
        }
    }

    $report['valid_keys_count'] = count($validKeys);
    return $report;
}

// ── Comandos ─────────────────────────────────────────────────

$command = trim((string)($argv[1] ?? ''));
$force   = in_array('--force', array_slice($argv, 2), true);

$history = readHistoryLocked();

switch ($command) {
    case 'covers:audit':
        say('═══ Informe de portadas (sin borrar nada) ═══');
        $report = auditCovers($history);

        say("Carpeta: " . COVERS_DIR);
        say("Archivos: {$report['total']} (" . fmtBytes($report['bytes']) . " en .jpg)");
        say("Vigentes (claves del historial actual): " . count($report['valid'])
            . " de {$report['valid_keys_count']} claves");
        say('');
        say("Nombres inválidos: " . count($report['invalid_names']));
        foreach (array_slice($report['invalid_names'], 0, 10) as $f) {
            say("  - {$f}");
        }
        say("Archivos vacíos: " . count($report['zero_bytes']));
        foreach (array_slice($report['zero_bytes'], 0, 10) as $f) {
            say("  - {$f}");
        }
        say("Temporales .tmp: " . count($report['tmp']));
        $dupGroups = count($report['duplicates']);
        $dupFiles = array_sum(array_map(static fn ($g) => count($g['remove']), $report['duplicates']));
        say("Grupos duplicados por contenido: {$dupGroups} ({$dupFiles} archivos de sobra)");
        foreach (array_slice($report['duplicates'], 0, 5) as $g) {
            say("  - conservar {$g['keep']}; borrar: " . implode(', ', $g['remove']));
        }
        $missing = count($report['history_missing']);
        say('');
        say("Filas del historial sin portada: {$missing}");
        foreach (array_slice($report['history_missing'], 0, 10) as $m) {
            $via = $m['itemId'] !== '' ? 'itemId ' . $m['itemId'] : 'SIN itemId (vía oportunista)';
            say("  - {$m['artist']} — {$m['title']} [{$via}]");
        }
        if ($missing > 0 && $report['history_missing_no_itemid'] === $missing
            && !jellyfinItemImagesUrl('x')) {
            say('');
            say('Nota: ninguna fila tiene itemId utilizable y no hay plantilla');
            say('QCR_JELLYFIN_IMAGES_URL configurada: el retro-relleno usará la');
            say('vía oportunista (cuando cada canción vuelva a sonar).');
        }
        break;

    case 'covers:clean':
        say('═══ Limpieza de portadas ═══');
        $report = auditCovers($history);
        $deleted = 0;
        $bytesFreed = 0;

        $toDelete = array_merge(
            $report['invalid_names'],
            $report['zero_bytes']
        );
        // .tmp: solo los viejos (> 1 h) — uno fresco puede pertenecer a
        // una descarga concurrente en curso (misma regla que el GC)
        foreach ($report['tmp'] as $f) {
            $path = COVERS_DIR . '/' . $f;
            if (@filemtime($path) < time() - 3600) {
                $toDelete[] = $f;
            }
        }
        foreach ($report['duplicates'] as $g) {
            foreach ($g['remove'] as $f) {
                $toDelete[] = $f;
            }
        }
        foreach (array_unique($toDelete) as $base) {
            $path = COVERS_DIR . '/' . $base;
            $size = (int)@filesize($path);
            if (@unlink($path)) {
                $deleted++;
                $bytesFreed += $size;
                say("  borrado: {$base}");
            }
        }
        say('');
        say("Archivos borrados: {$deleted} (" . fmtBytes($bytesFreed) . " liberados)");
        say('Portadas vigentes intactas; las de 0 bytes se re-descargarán solas.');
        break;

    case 'covers:backfill':
        if (!jellyfinItemImagesUrl('x')) {
            say('Retro-relleno DESHABILITADO: falta la plantilla de imágenes de Jellyfin.');
            say('');
            say('Añade en api/config.php (https y con el placeholder {itemId}):');
            say("  define('QCR_JELLYFIN_IMAGES_URL_VALUE', 'https://TU-JELLYFIN/Items/{itemId}/Images/Primary');");
            exit(1);
        }
        $missing = count(array_filter(
            $history,
            static function ($entry) {
                $p = coverFilePath((string)($entry['artist'] ?? ''), (string)($entry['title'] ?? ''));
                return !is_file($p) || @filesize($p) === 0;
            }
        ));
        say("═══ Retro-relleno por itemId ({$missing} filas sin portada) ═══");
        $result = backfillMissingCovers($history, MAINT_MAX_BACKFILL, $force);
        say("Intentos: {$result['attempted']} | reparadas: {$result['downloaded']} | fallidas: {$result['failed']}");
        if ($result['failed'] > 0 && !$force) {
            say('Las fallidas esperan 6 h antes de reintentar (usa --force para ignorar la espera).');
        }
        break;

    case 'history:dedupe':
        say('═══ Deduplicación del historial ═══');
        $before = count($history);
        $kept = [];
        // De más nuevo a más viejo: cada entrada se conserva solo si NO
        // es la misma emisión que una ya conservada (misma regla v0.1.4).
        foreach ($history as $entry) {
            $same = false;
            foreach ($kept as $k) {
                $artist = (string)($entry['artist'] ?? '');
                $title  = (string)($entry['title'] ?? '');
                $eKey = songKey($artist, $title);
                $kKey = songKey((string)($k['artist'] ?? ''), (string)($k['title'] ?? ''));
                $eItem = sanitizeItemId((string)($entry['itemId'] ?? ''));
                $kItem = sanitizeItemId((string)($k['itemId'] ?? ''));
                if ($eKey !== $kKey && ($eItem === '' || $eItem !== $kItem)) {
                    continue;
                }
                $ts = (int)($k['ts'] ?? 0);
                if ($ts <= 0) {
                    $same = true;
                    break;
                }
                // Distancia entre la entrada conservada (más nueva) y la
                // candidata (más vieja): dentro de duración+margen es la
                // MISMA emisión rebotada, no dos pases.
                $elapsed = $ts - (int)($entry['ts'] ?? 0);
                $dur = durationToSeconds((string)($k['duration'] ?? ''));
                if ($dur <= 0) {
                    $dur = DEFAULT_MAX_DURATION_SECONDS;
                }
                if ($elapsed >= 0 && $elapsed < $dur + DURATION_MARGIN_SECONDS) {
                    $same = true;
                    break;
                }
            }
            if (!$same) {
                $kept[] = $entry;
            }
        }
        $removed = $before - count($kept);
        if ($removed > 0) {
            $fp = fopen(HISTORY_FILE, 'c+');
            if ($fp && flock($fp, LOCK_EX)) {
                rewind($fp);
                ftruncate($fp, 0);
                fwrite($fp, json_encode(array_values($kept), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                fflush($fp);
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
        say("Entradas antes: {$before} | después: " . count($kept) . " | duplicados eliminados: {$removed}");
        break;

    default:
        say('Uso: php api/maintenance.php <comando> [--force]');
        say('');
        say('Comandos:');
        say('  covers:audit      Informe de portadas (sin borrar nada)');
        say('  covers:clean      Borra inválidas, vacías, .tmp y duplicadas');
        say('  covers:backfill   Retro-relleno por itemId (opcional --force)');
        say('  history:dedupe    Colapsa emisiones duplicadas del historial');
        exit(1);
}
