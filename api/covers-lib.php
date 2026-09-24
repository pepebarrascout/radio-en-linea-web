<?php
/**
 * ============================================================
 *  Que Chilero Radio — Caché de portadas del historial
 * ============================================================
 *  Jellyfin solo expone la portada de la canción que suena
 *  AHORA (endpoint /RadioOnline/NowPlaying/Artwork). Para poder
 *  mostrar la portada de las canciones YA ESCUCHADAS, este
 *  módulo:
 *
 *    1. Descarga la portada redimensionada (maxWidth=150)
 *       en el momento en que la canción se registra.
 *    2. La guarda como api/covers/{clave}.jpg donde la clave
 *       es md5(artista|título) — estable para siempre.
 *    3. Borra (GC) las portadas de canciones que ya salieron
 *       del listado (top 10 del historial).
 *
 *  La web consume las portadas vía history.php (campo "cover").
 * ============================================================
 */

require_once __DIR__ . '/history-lib.php';
require_once __DIR__ . '/np-lib.php';   // URL real del artwork: QCR_NOWPLAYING_URL (env) o api/config.php

// ── Configuración ────────────────────────────────────────────

// Carpeta pública donde se guardan las portadas (auto-creada).
// QCR_COVERS_DIR permite redirigirla (p. ej. en pruebas).
define('COVERS_DIR', getenv('QCR_COVERS_DIR') ?: __DIR__ . '/covers');

// Resolución solicitada al servidor de la radio al descargar (px de ancho)
define('COVER_SIZE', 150);

// Timeout de descarga de portada (segundos)
define('COVER_HTTP_TIMEOUT', 10);

// Tiempo de espera entre intentos de retro-relleno de la MISMA
// portada fallida (evita martillar un item sin imagen 1440 veces/día)
define('COVERS_BACKFILL_RETRY_SECONDS', 6 * 3600);   // 6 horas

// Archivo de memoria de intentos de retro-relleno (carpeta data/,
// protegida por .htaccess; QCR_BACKFILL_STATE_FILE para pruebas)
define('COVERS_BACKFILL_STATE_FILE', getenv('QCR_BACKFILL_STATE_FILE') ?: __DIR__ . '/data/backfill-state.json');

// ── Utilidades ───────────────────────────────────────────────

/**
 * Convierte el historial a su forma pública añadiendo la portada
 * local ("cover") de cada canción cuando ya está en caché.
 * Si una canción no tiene portada, el campo es null y la web
 * muestra su marcador de posición.
 */
function historyToPublicWithCovers(array $history): array
{
    return array_values(array_map(
        static function ($entry) {
            unset($entry['ts']);
            $entry['cover'] = coverUrlFor(
                (string)($entry['artist'] ?? ''),
                (string)($entry['title'] ?? '')
            );
            return $entry;
        },
        $history
    ));
}

/**
 * Ruta absoluta del archivo de portada de una canción.
 */
function coverFilePath(string $artist, string $title): string
{
    return COVERS_DIR . '/' . songKey($artist, $title) . '.jpg';
}

/**
 * URL pública de la portada si ya está en caché, o null.
 * Ruta relativa a la página principal (index.php).
 */
function coverUrlFor(string $artist, string $title): ?string
{
    if (is_file(coverFilePath($artist, $title))) {
        return './api/covers/' . songKey($artist, $title) . '.jpg';
    }
    return null;
}

/**
 * Construye la URL de descarga de portada redimensionada:
 *  - fuerza el esquema que ya traiga la URL (server-to-server)
 *  - fija/sobrescribe maxWidth (por defecto COVER_SIZE, 150 px)
 *  - sin URL válida usa el endpoint Artwork configurado
 *    (QCR_NOWPLAYING_URL / config.php — nunca un dominio hardcodeado)
 *  - devuelve '' si no hay configuración (no hay de dónde descargar)
 */
function buildCoverDownloadUrl(?string $artworkUrl, ?int $maxWidth = null): string
{
    $maxWidth = $maxWidth ?: COVER_SIZE;
    $url = trim((string)$artworkUrl);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        $url = qcrArtworkUrl();   // ⭐ endpoint configurado, sin hardcodear
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        $fallback = qcrArtworkUrl();
        return $fallback === '' ? '' : $fallback . '?maxWidth=' . $maxWidth;
    }

    $scheme = $parts['scheme'] ?? 'https';
    $host   = $parts['host'];
    $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path   = $parts['path'] ?? '/';

    parse_str($parts['query'] ?? '', $query);
    $query['maxWidth'] = (string)$maxWidth;
    $queryString = http_build_query($query);

    return "{$scheme}://{$host}{$port}{$path}?{$queryString}";
}

/**
 * Descarga una imagen por HTTP (cURL con fallback file_get_contents,
 * implementación compartida en np-lib.php).
 * Devuelve los bytes o null en caso de error.
 */
function fetchCoverBytes(string $url): ?string
{
    [$body, , $err] = qcrHttpGet($url, COVER_HTTP_TIMEOUT, 'QueChileroRadio-Covers/1.0');
    return $err === null ? $body : null;
}

/**
 * Valida que los bytes descargados sean una imagen real
 * (JPEG o PNG por magic bytes) y no una página de error.
 */
function isValidImageBytes(?string $bytes): bool
{
    if ($bytes === null || strlen($bytes) < 100) {
        return false;
    }
    $head = substr($bytes, 0, 8);
    $isJpeg = strncmp($head, "\xFF\xD8\xFF", 3) === 0;
    $isPng  = strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0;
    $isWebp = strlen($bytes) > 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP';
    return $isJpeg || $isPng || $isWebp;
}

/**
 * ¿Es un host privado/loopback? (Jellyfin en la LAN local sin TLS)
 * http:// solo se acepta para estos hosts; el resto exige https.
 */
function jellyfinHostIsPrivate(string $host): bool
{
    if (strtolower($host) === 'localhost' || $host === '::1' || preg_match('/^127\./', $host)) {
        return true;
    }
    // 10.0.0.0/8, 192.168.0.0/16, 172.16.0.0/12
    return (bool)preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
}

/**
 * URL de la imagen de un item de Jellyfin para el retro-relleno
 * de portadas viejas (la API de arte del plugin solo expone la
 * canción ACTUAL; con esta plantilla se repara cualquier fila
 * vieja por su itemId de Jellyfin).
 *
 * Configuración opcional (api/config.php o entorno):
 *   QCR_JELLYFIN_IMAGES_URL = https://TU-JELLYFIN/Items/{itemId}/Images/Primary
 *
 * Devuelve '' si no hay plantilla configurada, no es válida o el
 * itemId no es válido. El placeholder {itemId} es OBLIGATORIO.
 * https:// siempre permitido; http:// solo para hosts privados
 * (Jellyfin en la red local sin TLS).
 */
function jellyfinItemImagesUrl(string $itemId): string
{
    $itemId = sanitizeItemId($itemId);
    $tpl = getenv('QCR_JELLYFIN_IMAGES_URL');
    if ($tpl === false || trim($tpl) === '') {
        $tpl = defined('QCR_JELLYFIN_IMAGES_URL_VALUE') ? (string)QCR_JELLYFIN_IMAGES_URL_VALUE : '';
    }
    $tpl = trim($tpl);
    if ($tpl === '' || $itemId === '') {
        return '';
    }
    if (strpos($tpl, '{itemId}') === false) {
        return '';   // plantilla sin placeholder: configuración inválida
    }
    if (!filter_var(str_replace('{itemId}', 'x', $tpl), FILTER_VALIDATE_URL)) {
        return '';
    }

    $parts = parse_url($tpl);
    $scheme = strtolower($parts['scheme'] ?? '');
    $host = $parts['host'] ?? '';
    if ($host === '') {
        return '';
    }
    if ($scheme === 'https') {
        // ok
    } elseif ($scheme === 'http' && jellyfinHostIsPrivate($host)) {
        // ok: Jellyfin en la LAN local
    } else {
        return '';   // http a host público o esquema raro: rechazado
    }

    return str_replace('{itemId}', rawurlencode($itemId), $tpl);
}

/**
 * Guarda bytes de imagen ya validados como portada de una canción
 * (escritura atómica: archivo temporal + rename).
 * Devuelve true si el archivo quedó escrito.
 */
function storeCoverBytes(string $artist, string $title, ?string $bytes): bool
{
    $artist = trim($artist);
    $title  = trim($title);
    if ($artist === '' || $title === '' || !isValidImageBytes($bytes)) {
        return false;
    }

    // Crear la carpeta covers/ si falta
    if (!is_dir(COVERS_DIR) && !@mkdir(COVERS_DIR, 0755, true) && !is_dir(COVERS_DIR)) {
        return false;
    }

    $path = coverFilePath($artist, $title);

    // Escritura atómica: archivo temporal + rename
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * Garantiza que la portada de la canción esté en caché,
 * descargándola de una URL concreta (también usada por el
 * retro-relleno con la plantilla de imágenes de Jellyfin).
 * Si ya existe NO vuelve a descargarla (idempotente).
 * Devuelve: 'cached' | 'downloaded' | 'failed'
 */
function ensureCoverFromUrl(string $artist, string $title, string $url): string
{
    $artist = trim($artist);
    $title  = trim($title);
    if ($artist === '' || $title === '' || $url === '') {
        return 'failed';
    }

    $path = coverFilePath($artist, $title);
    if (is_file($path) && filesize($path) > 0) {
        return 'cached';
    }

    $bytes = fetchCoverBytes($url);
    return storeCoverBytes($artist, $title, $bytes) ? 'downloaded' : 'failed';
}

/**
 * Garantiza que la portada de la canción esté en caché.
 * Si ya existe NO vuelve a descargarla (idempotente).
 *
 * $artworkUrl = campo artworkUrl devuelto por NowPlaying
 * Devuelve: 'cached' | 'downloaded' | 'failed'
 */
function ensureCoverCached(string $artist, string $title, ?string $artworkUrl): string
{
    $artist = trim($artist);
    $title  = trim($title);
    if ($artist === '' || $title === '') {
        return 'failed';
    }

    $path = coverFilePath($artist, $title);
    if (is_file($path) && filesize($path) > 0) {
        return 'cached';
    }

    $url = buildCoverDownloadUrl($artworkUrl);
    if ($url === '') {
        return 'failed';   // sin URL configurada no hay descarga posible
    }

    return ensureCoverFromUrl($artist, $title, $url);
}

/**
 * Lee la memoria de intentos de retro-relleno.
 * Formato: { "clave_cancion": ts_del_último_intento }
 */
function readBackfillState(): array
{
    if (!is_file(COVERS_BACKFILL_STATE_FILE)) {
        return [];
    }
    $fp = @fopen(COVERS_BACKFILL_STATE_FILE, 'r');
    if (!$fp) {
        return [];
    }
    @flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
    $state = json_decode((string)$content, true);
    return is_array($state) ? $state : [];
}

/**
 * Escribe la memoria de intentos (atómica, lock exclusivo).
 */
function writeBackfillState(array $state): void
{
    $dir = dirname(COVERS_BACKFILL_STATE_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return;
    }
    $fp = @fopen(COVERS_BACKFILL_STATE_FILE, 'c+');
    if (!$fp) {
        return;
    }
    @flock($fp, LOCK_EX);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * v0.1.4 — Retro-relleno de portadas nulas del historial.
 *
 * Repara las filas del historial que quedaron con portada
 * descargando su imagen por itemId desde la plantilla
 * QCR_JELLYFIN_IMAGES_URL (ver jellyfinItemImagesUrl()).
 *
 * Sin esa configuración no hay de dónde bajar arte viejo: las
 * filas sin itemId (o con la descarga fallida) se reparan por vía
 * oportunista — cuando la canción vuelve a sonar, el cron de
 * siempre la reintenta con el arte de la canción actual.
 *
 * Memoria de intentos: cada clave fallida espera
 * COVERS_BACKFILL_RETRY_SECONDS antes de reintentar (un item sin
 * imagen en Jellyfin no se martilla 1440 veces al día).
 *
 * $maxAttempts limita las descargas por invocación (el cron pasa
 * cada minuto: 2 por pasada es suficiente y suave).
 * $force ignora el tiempo de espera (uso manual desde maintenance).
 * Devuelve: ['attempted'=>int, 'downloaded'=>int, 'failed'=>int]
 */
function backfillMissingCovers(array $history, int $maxAttempts = 2, bool $force = false): array
{
    $result = ['attempted' => 0, 'downloaded' => 0, 'failed' => 0];
    if ($maxAttempts <= 0 || empty($history)) {
        return $result;
    }

    $state = readBackfillState();
    $stateChanged = false;
    $now = time();

    foreach ($history as $entry) {
        if ($result['attempted'] >= $maxAttempts) {
            break;
        }

        $artist = (string)($entry['artist'] ?? '');
        $title  = (string)($entry['title'] ?? '');
        if ($artist === '' || $title === '') {
            continue;
        }

        // Solo filas que de verdad carecen de portada en caché
        $path = coverFilePath($artist, $title);
        if (is_file($path) && filesize($path) > 0) {
            continue;
        }

        $key = songKey($artist, $title);

        // Memoria de intentos: no reintentar demasiado pronto
        if (!$force && isset($state[$key]) && ($now - (int)$state[$key]) < COVERS_BACKFILL_RETRY_SECONDS) {
            continue;
        }

        $url = jellyfinItemImagesUrl((string)($entry['itemId'] ?? ''));
        if ($url === '') {
            continue;   // sin itemId o sin plantilla: vía oportunista
        }

        $state[$key] = $now;
        $stateChanged = true;
        $result['attempted']++;

        if (ensureCoverFromUrl($artist, $title, $url) === 'downloaded') {
            $result['downloaded']++;
        } else {
            $result['failed']++;
        }
    }

    if ($stateChanged) {
        // Poda: solo conservar claves con intento en los últimos 30 días
        foreach ($state as $k => $ts) {
            if (!is_int($ts) && !ctype_digit((string)$ts)) {
                unset($state[$k]);
            } elseif ((int)$ts < $now - 30 * 86400) {
                unset($state[$k]);
            }
        }
        writeBackfillState($state);
    }

    return $result;
}

/**
 * Recolector: borra las portadas de canciones que YA NO están
 * en el historial (salieron del top 10) y los .tmp huérfanos
 * con más de 1 hora. Devuelve el número de archivos borrados.
 *
 * v0.1.4 — GC endurecido: también elimina
 *   - archivos con nombre inválido (no es 32 hex + .jpg)
 *   - .jpg de 0 bytes (incluso si su canción sigue en la lista:
 *     se re-descargará sola en el siguiente pase)
 */
function gcCovers(array $history): int
{
    if (!is_dir(COVERS_DIR)) {
        return 0;
    }

    // Claves vigentes según el historial actual
    $validKeys = [];
    foreach ($history as $entry) {
        $artist = (string)($entry['artist'] ?? '');
        $title  = (string)($entry['title'] ?? '');
        if ($artist !== '' && $title !== '') {
            $validKeys[songKey($artist, $title)] = true;
        }
    }

    $deleted = 0;
    $files   = glob(COVERS_DIR . '/*') ?: [];

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;   // ignora subdirectorios
        }
        $base = basename($file);

        // Limpieza de temporales huérfanos (> 1 hora)
        if (str_ends_with($base, '.tmp')) {
            if (@filemtime($file) < time() - 3600) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
            continue;
        }

        if (!str_ends_with($base, '.jpg')) {
            // Restos con cualquier otra extensión: basura, fuera
            if (@unlink($file)) {
                $deleted++;
            }
            continue;
        }

        $key = substr($base, 0, -4);

        // Nombre inválido (una portada válida SIEMPRE es md5 de 32 hex)
        if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
            if (@unlink($file)) {
                $deleted++;
            }
            continue;
        }

        // Archivo vacío/corrupto: inútil aunque su clave sea vigente
        if (@filesize($file) === 0) {
            if (@unlink($file)) {
                $deleted++;
            }
            continue;
        }

        if (!isset($validKeys[$key]) && @unlink($file)) {
            $deleted++;
        }
    }

    return $deleted;
}
