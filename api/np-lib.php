<?php
/**
 * ============================================================
 *  Que Chilero Radio — Configuración del servidor de la radio
 * ============================================================
 *  Punto ÚNICO donde se resuelve la dirección del servidor de
 *  la radio (endpoint NowPlaying del plugin RadioOnline).
 *
 *  ⭐ PRIVACIDAD POR DISEÑO: el dominio del servidor NUNCA va
 *  escrito en el código (ni en el JS del navegador ni en el PHP
 *  distribuido). Se lee de:
 *
 *    1. Variable de entorno  QCR_NOWPLAYING_URL
 *    2. api/config.php       QCR_NOWPLAYING_URL_VALUE
 *       (copia config.example.php; NO se versiona)
 *
 *  La usan: cron-update.php (historial 24/7), nowplaying.php
 *  (proxy JSON para el navegador), artwork.php (proxy de
 *  portadas), history.php (traducción del artwork del navegador)
 *  y covers-lib.php (descarga de portadas al historial).
 * ============================================================
 */

// api/config.php es opcional y privado (no se versiona). Se
// carga una sola vez por proceso desde aquí para que todos los
// endpoints compartan la misma resolución.
if (!defined('QCR_CONFIG_LOADED')) {
    if (is_file(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    }
    define('QCR_CONFIG_LOADED', true);
}

/**
 * URL base del endpoint NowPlaying del plugin RadioOnline.
 * Devuelve '' si no está configurada (los endpoints que la
 * necesitan degradan con elegancia y el cron avisa por CLI).
 */
function qcrNowPlayingUrl(): string
{
    $url = getenv('QCR_NOWPLAYING_URL');
    if ($url === false || trim($url) === '') {
        $url = defined('QCR_NOWPLAYING_URL_VALUE') ? (string)QCR_NOWPLAYING_URL_VALUE : '';
    }
    return trim($url);
}

/**
 * URL del endpoint Artwork (portada de la canción que suena),
 * derivada de la base: …/RadioOnline/NowPlaying → …/NowPlaying/Artwork
 * Devuelve '' si no hay configuración previa.
 */
function qcrArtworkUrl(): string
{
    $base = rtrim(qcrNowPlayingUrl(), '/');
    return $base === '' ? '' : $base . '/Artwork';
}

/**
 * Traduce la URL de portada que llega del navegador:
 *   - absoluta http(s)               → se respeta (clientes antiguos)
 *   - ruta del proxy 'api/artwork.php' → URL real configurada en el
 *     servidor (el navegador nunca conoció el dominio real)
 *   - cualquier otra cosa            → '' (nada que descargar)
 */
function qcrResolveArtworkUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url)) {
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }
    if (strpos($url, 'api/artwork.php') !== false) {
        return qcrArtworkUrl();
    }
    return '';
}

/**
 * Descarga un recurso por HTTP (cURL con fallback a
 * file_get_contents). Devuelve [bytes|null, códigoHTTP|null, error|null].
 * error === null significa éxito.
 */
function qcrHttpGet(string $url, int $timeout, string $userAgent): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => $userAgent,
        ]);
        // Constante no presente en algunas compilaciones de PHP: aplicar solo si existe
        if (defined('CURLOPT_MAX_REDIRECTS')) {
            curl_setopt($ch, CURLOPT_MAX_REDIRECTS, 3);
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [null, null, "cURL error: {$err}"];
        }
        if ($code < 200 || $code >= 300) {
            return [null, $code, "HTTP {$code}"];
        }
        return [$body, $code, null];
    }

    // Fallback sin cURL (requiere allow_url_fopen = On)
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'user_agent' => $userAgent],
        'ssl'  => ['verify_peer' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return [null, null, 'file_get_contents falló (revisa allow_url_fopen o instala cURL)'];
    }
    return [$body, 200, null];
}

/**
 * Elimina el host del servidor de la radio de TODOS los campos
 * de texto de la respuesta (defensa en profundidad: aunque el
 * plugin añada mañana algún campo nuevo con la URL, el dominio
 * real nunca llega al navegador).
 */
function qcrScrubHost($value, string $host)
{
    if ($host === '') {
        return $value;
    }
    if (is_string($value)) {
        return str_replace($host, '', $value);
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = qcrScrubHost($v, $host);
        }
        return $out;
    }
    return $value;
}
