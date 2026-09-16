<?php
/**
 * ============================================================
 *  Que Chilero Radio — Proxy de NowPlaying (JSON para el navegador)
 * ============================================================
 *  El reproductor consulta ESTE endpoint (mismo origen, sin CORS)
 *  en lugar de llamar directamente al servidor de la radio:
 *
 *   - El dominio real NUNCA se expone al navegador ni va escrito
 *     en el código del cliente. Se lee de QCR_NOWPLAYING_URL (env)
 *     o QCR_NOWPLAYING_URL_VALUE (api/config.php) — ver np-lib.php.
 *   - Caché breve (5 s por defecto) con lock exclusivo: muchos
 *     oyentes simultáneos no multiplican las consultas al servidor.
 *   - Si el upstream falla, sirve la última respuesta válida
 *     (mejor un dato ligeramente viejo que una web rota).
 *   - Reescribe artworkUrl al proxy local api/artwork.php y
 *     "limpia" el host real de cualquier campo de texto.
 *
 *  Respuestas:
 *    200  JSON de NowPlaying (o placeholder si aún no hay URL
 *         configurada → la web muestra "Esperando transmisión…")
 *    502  { error: nowplaying_no_disponible }  (sin caché previa)
 * ============================================================
 */

require_once __DIR__ . '/np-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ── Caché local (archivo protegido por api/data/.htaccess) ──
// QCR_NP_CACHE_TTL permite ajustarla (0 = sin caché, para pruebas).
$ttl = (int)(getenv('QCR_NP_CACHE_TTL') !== false ? getenv('QCR_NP_CACHE_TTL') : 5);
if ($ttl < 0) {
    $ttl = 0;
}

$cacheDir  = getenv('QCR_DATA_DIR') !== false && trim((string)getenv('QCR_DATA_DIR')) !== ''
    ? rtrim((string)getenv('QCR_DATA_DIR'), '/')
    : __DIR__ . '/data';
$cacheFile = $cacheDir . '/np-cache.json';

/** Envía el archivo de caché y termina. */
$serveCache = function () use ($cacheFile) {
    if (is_file($cacheFile)) {
        header('X-QCR-NowPlaying: cache');
        readfile($cacheFile);
    }
    exit;
};

// ── 1) Sin URL configurada: placeholder amable ───────────────
// La web funciona nada más descomprimir el ZIP (muestra
// "Esperando transmisión…" hasta definir la URL en config.php).
// ⭐ Se comprueba ANTES de la caché: un despliegue sin
// configurar nunca sirve respuestas cacheadas por otra config.
$upstream = qcrNowPlayingUrl();
if ($upstream === '') {
    echo json_encode([
        'isPlaying'  => false,
        'artist'     => 'Que Chilero Radio',
        'title'      => 'Esperando transmisión...',
        'album'      => '',
        'genre'      => '',
        'year'       => '',
        'duration'   => '',
        'artworkUrl' => 'api/artwork.php',
        'itemId'     => '',
    ], JSON_UNESCAPED_UNICODE);
    exit;   // (aún no hay lock abierto: nada que liberar)
}

// ── 2) Caché fresca → servir directamente ────────────────────
if ($ttl > 0 && is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $ttl) {
    $serveCache();
}

// ── 3) Refrescar bajo lock exclusivo (evita la estampida) ────
$lockFp = @fopen($cacheFile . '.lock', 'c');
if ($lockFp) {
    flock($lockFp, LOCK_EX);
    // Otro proceso pudo refrescar mientras tomábamos el lock
    if ($ttl > 0 && is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $ttl) {
        $serveCache();
    }
}

// ── 4) Consulta al servidor de la radio ──────────────────────
[$body, , $err] = qcrHttpGet($upstream, 8, 'QueChileroRadio-Web/1.0');
$data = ($err === null) ? json_decode((string)$body, true) : null;

if (!is_array($data)) {
    // Sin respuesta válida: mejor la caché vieja que una web rota
    if (is_file($cacheFile)) {
        $serveCache();
    }
    http_response_code(502);
    echo json_encode(['error' => 'nowplaying_no_disponible'], JSON_UNESCAPED_UNICODE);
    if ($lockFp) { flock($lockFp, LOCK_UN); fclose($lockFp); }
    exit;
}

// ── 5) Reescritura del artwork al proxy local + limpieza ─────
// artworkUrl apuntaba al servidor real: ahora lo sirve
// api/artwork.php (mismo origen). Además se limpia el host real
// de cualquier otro campo de texto por si el plugin lo añade.
$realHost = (string)(parse_url($upstream, PHP_URL_HOST) ?: '');
$data     = qcrScrubHost($data, $realHost);
$data['artworkUrl'] = 'api/artwork.php';

$json = json_encode($data, JSON_UNESCAPED_UNICODE);

// Escritura atómica: los lectores nunca ven un JSON a medias
if ($ttl > 0) {
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
        // Sin carpeta de caché: responder sin cachear (no es fatal)
        echo $json;
        if ($lockFp) { flock($lockFp, LOCK_UN); fclose($lockFp); }
        exit;
    }
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $cacheFile);
    }
}

echo $json;
if ($lockFp) { flock($lockFp, LOCK_UN); fclose($lockFp); }
