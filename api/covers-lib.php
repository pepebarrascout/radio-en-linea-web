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

    // Crear la carpeta covers/ si falta
    if (!is_dir(COVERS_DIR) && !@mkdir(COVERS_DIR, 0755, true) && !is_dir(COVERS_DIR)) {
        return 'failed';
    }

    $url = buildCoverDownloadUrl($artworkUrl);
    if ($url === '') {
        return 'failed';   // sin URL configurada no hay descarga posible
    }

    $bytes = fetchCoverBytes($url);

    if (!isValidImageBytes($bytes)) {
        return 'failed';
    }

    // Escritura atómica: archivo temporal + rename
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
        return 'failed';
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return 'failed';
    }

    return 'downloaded';
}

/**
 * Recolector: borra las portadas de canciones que YA NO están
 * en el historial (salieron del top 10) y los .tmp huérfanos
 * con más de 1 hora. Devuelve el número de archivos borrados.
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
            continue;
        }

        $key = substr($base, 0, -4);
        if (!isset($validKeys[$key]) && @unlink($file)) {
            $deleted++;
        }
    }

    return $deleted;
}
