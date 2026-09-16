<?php
/**
 * ============================================================
 *  Que Chilero Radio — Librería compartida del historial
 * ============================================================
 *  Usada por:
 *    - api/history.php      (endpoint web GET/POST)
 *    - api/cron-update.php  (actualizador automático 24/7)
 *
 *  Contiene la configuración y la lógica de registro
 *  idempotente del historial de canciones.
 * ============================================================
 */

date_default_timezone_set('America/Guatemala');

// ── Configuración ────────────────────────────────────────────

// Archivo donde se guarda el historial (creado automáticamente).
// QCR_HISTORY_FILE permite redirigirlo (p. ej. fuera del webroot o en pruebas).
define('HISTORY_FILE', getenv('QCR_HISTORY_FILE') ?: __DIR__ . '/history.json');

// Máximo de canciones conservadas en el historial
define('MAX_HISTORY', 10);

// Margen (segundos) sobre la duración para decidir si una
// repetición consecutiva es un NUEVO pase o la misma emisión.
define('DURATION_MARGIN_SECONDS', 90);

// Si la canción no tiene duración conocida, ventana máxima
// antes de considerar una repetición como pase nuevo.
define('DEFAULT_MAX_DURATION_SECONDS', 600);

// Título que usa la web cuando no hay transmisión (no registrar)
define('PLACEHOLDER_TITLE', 'Esperando transmisión...');

// ── Utilidades ───────────────────────────────────────────────

/**
 * Normaliza un texto para usarlo como parte de la clave de una
 * canción (minúsculas, sin espacios en los extremos).
 * Compatible con hosts sin extensión mbstring.
 */
function normalizeForKey(string $text): string
{
    $text = trim($text);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }
    return strtolower($text);
}

/**
 * Clave estable e idéntica de una canción en TODA la plataforma:
 *  - nombre de archivo de la portada en caché (covers/)
 *  - identificador de votos (votes.json / voters.json)
 * Formato: md5(artista_normalizado|título_normalizado)
 */
function songKey(string $artist, string $title): string
{
    return md5(normalizeForKey($artist) . '|' . normalizeForKey($title));
}

/**
 * Lee el historial bajo lock compartido (LECTURA SEGURA).
 * Las lecturas concurrentes esperan a que terminen las escrituras
 * y nunca ven un JSON escrito a medias.
 */
function readHistoryLocked(): array
{
    if (!file_exists(HISTORY_FILE)) {
        return [];
    }

    $fp = fopen(HISTORY_FILE, 'r');
    if (!$fp) {
        return [];
    }

    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return [];
    }

    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $history = json_decode((string)$content, true);
    return is_array($history) ? $history : [];
}

/**
 * Elimina campos internos (ts) antes de exponer el historial.
 */
function historyToPublic(array $history): array
{
    return array_values(array_map(
        static function ($entry) {
            unset($entry['ts']);
            return $entry;
        },
        $history
    ));
}

/**
 * Analiza una duración con formato "m:ss" o "mm:ss" a segundos.
 * Devuelve 0 si no se puede interpretar.
 */
function durationToSeconds(?string $duration): int
{
    if (!$duration || !preg_match('/^(\d+):(\d{1,2})$/', trim($duration), $m)) {
        return 0;
    }
    return ((int)$m[1]) * 60 + (int)$m[2];
}

/**
 * Decide si la canción entrante ES LA MISMA EMISIÓN que la última
 * registrada (no duplicar) o es un pase nuevo (registrar).
 *
 * Reglas:
 *  - Coincide título+artista con history[0] Y no ha pasado su
 *    duración (+ margen) → misma emisión → NO registrar.
 *  - Coincide pero YA PASÓ su duración → la playlist la repitió
 *    → SÍ registrar como nuevo pase.
 */
function isSamePlayAsLast(array $lastEntry, string $title, string $artist): bool
{
    if (empty($lastEntry) || !isset($lastEntry['title'], $lastEntry['artist'])) {
        return false;
    }

    if ($lastEntry['title'] !== $title || $lastEntry['artist'] !== $artist) {
        return false;
    }

    $ts = isset($lastEntry['ts']) ? (int)$lastEntry['ts'] : 0;
    if ($ts <= 0) {
        // Entrada antigua sin ts (creada por la versión anterior):
        // comportamiento clásico → tratar como misma emisión.
        return true;
    }

    $elapsed = time() - $ts;

    $durationSec = durationToSeconds($lastEntry['duration'] ?? '');
    if ($durationSec <= 0) {
        $durationSec = DEFAULT_MAX_DURATION_SECONDS;
    }

    return $elapsed < ($durationSec + DURATION_MARGIN_SECONDS);
}

/**
 * Sanitiza el itemId de Jellyfin (GUID alfanumérico con guiones).
 * Devuelve '' si no es válido → se usará la clave md5 artista|título.
 */
function sanitizeItemId(string $itemId): string
{
    $itemId = trim($itemId);
    return preg_match('/^[A-Za-z0-9\-]{1,64}$/', $itemId) ? $itemId : '';
}

/**
 * Registra la canción actual en el historial si corresponde.
 * IDEMPOTENTE y segura frente a escrituras concurrentes (LOCK_EX).
 *
 * $song = ['title'=>.., 'artist'=>.., 'genre'=>.., 'duration'=>.., 'itemId'=>..]
 * Devuelve: [bool $registered, array $history, string $reason]
 */
function registerSong(array $song): array
{
    $title  = trim((string)($song['title'] ?? ''));
    $artist = trim((string)($song['artist'] ?? ''));

    if ($title === '' || $title === PLACEHOLDER_TITLE || $artist === '') {
        return [false, readHistoryLocked(), 'invalid_or_not_playing'];
    }

    // ── Escritura atómica con lock exclusivo ──
    // 'c+' crea el archivo si no existe sin truncarlo al abrir.
    $fp = fopen(HISTORY_FILE, 'c+');
    if (!$fp) {
        return [false, [], 'cannot_open_file'];
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return [false, [], 'cannot_lock_file'];
    }

    $content = stream_get_contents($fp);
    $history = json_decode((string)$content, true);
    $history = is_array($history) ? $history : [];

    // ¿Es la misma emisión que la última registrada?
    if (!empty($history) && isSamePlayAsLast($history[0], $title, $artist)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return [false, $history, 'already_recorded'];
    }

    // Nueva entrada: la canción EMPEZÓ a sonar ahora.
    $newSong = [
        'title'    => $title,
        'artist'   => $artist,
        'itemId'   => sanitizeItemId((string)($song['itemId'] ?? '')),   // ID de Jellyfin
        'genre'    => trim((string)($song['genre'] ?? '')),
        'duration' => trim((string)($song['duration'] ?? '')),
        'time'     => date('H:i'),   // hora de Guatemala
        'ts'       => time(),
    ];

    array_unshift($history, $newSong);

    // Mantener solo las últimas MAX_HISTORY canciones
    $history = array_slice($history, 0, MAX_HISTORY);

    // Escritura atómica
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return [true, $history, 'registered'];
}
