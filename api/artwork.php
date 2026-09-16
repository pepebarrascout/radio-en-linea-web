<?php
/**
 * ============================================================
 *  Que Chilero Radio — Proxy de portadas (Artwork)
 * ============================================================
 *  Sirve al navegador la portada de la canción que suena sin
 *  revelar el dominio del servidor de la radio:
 *
 *    api/artwork.php?maxWidth=720
 *
 *   - Descarga la imagen del servidor configurado (QCR_NOWPLAYING_URL
 *     → endpoint …/NowPlaying/Artwork, ver np-lib.php) y la reenvía.
 *   - Valida que sean bytes de imagen reales (JPEG/PNG/WebP) antes
 *     de servirla: una página de error del upstream nunca llega al
 *     navegador (el <img> cae a su marcador de posición).
 *   - maxWidth se sanea (16–2048; fuera de rango → 720).
 *   - Cache-Control corto en el navegador: la web añade ?v= por
 *     canción, así que cada portada se descarga una sola vez.
 *
 *  Respuestas: 200 imagen · 502 sin imagen válida · 503 sin URL
 *  configurada. El <img> del cliente trata cualquier error con su
 *  marcador de posición (icono de la marca).
 * ============================================================
 */

require_once __DIR__ . '/np-lib.php';
require_once __DIR__ . '/covers-lib.php';

// ── Configuración ────────────────────────────────────────────
// Sin URL configurada no hay de dónde sacar la imagen.
if (qcrArtworkUrl() === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Artwork no configurado: define QCR_NOWPLAYING_URL (entorno) o QCR_NOWPLAYING_URL_VALUE (api/config.php).';
    exit;
}

// Ancho solicitado, saneado (el navegador usa 720 para el player
// y el historial baja su copia 150 por su cuenta al servidor).
$maxWidth = isset($_GET['maxWidth']) ? (int)$_GET['maxWidth'] : 720;
if ($maxWidth < 16 || $maxWidth > 2048) {
    $maxWidth = 720;
}

$url = buildCoverDownloadUrl(qcrArtworkUrl(), $maxWidth);
if ($url === '') {
    http_response_code(503);
    exit;
}

// ── Descarga y validación ────────────────────────────────────
[$bytes, , $err] = qcrHttpGet($url, 10, 'QueChileroRadio-Artwork/1.0');

if ($err !== null || !isValidImageBytes($bytes)) {
    http_response_code(502);
    exit;
}

// ── Content-Type según magic bytes (nunca el del upstream) ───
$head = substr((string)$bytes, 0, 8);
if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
    $contentType = 'image/jpeg';
} elseif (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
    $contentType = 'image/png';
} else {
    $contentType = 'image/webp';   // isValidImageBytes ya filtró los tres tipos
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($bytes));
// Corta: cambia con cada canción y la web usa cache-busting ?v=
header('Cache-Control: public, max-age=300');
echo $bytes;
