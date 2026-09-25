<?php
/**
 * ============================================================
 *  Que Chilero Radio — Web Push puro (sin Composer, sin Firebase)
 * ============================================================
 *  v0.1.4 — Avisos de programas 10 minutos antes de cada inicio.
 *
 *  Implementa los estándares que exige Chrome/Android:
 *    - RFC 8291  Message Encryption for Web Push (aes128gcm)
 *    - RFC 8292  VAPID (token JWT firmado ES256 con la clave P-256)
 *    - RFC 8188  codificación aes128gcm (cabecera sal|rs|idlen|clave)
 *
 *  Solo usa extensiones PHP universales (openssl + hash): se
 *  instala en cualquier hosting descomprimiendo el ZIP, igual que
 *  el resto del proyecto.
 *
 *  Flujo:
 *    1. php api/push-trigger.php --generate-keys  (UNA vez)
 *    2. Pegar las claves en api/config.php (QCR_VAPID_*)
 *    3. El navegador se suscribe contra push-config.php +
 *       push-subscribe.php (endpoint + claves + preferencias)
 *    4. push-trigger.php (cron cada minuto) cifra y envía
 * ============================================================
 */

// api/config.php es opcional y privado (no se versiona).
if (!defined('QCR_CONFIG_LOADED')) {
    if (is_file(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    }
    define('QCR_CONFIG_LOADED', true);
}

date_default_timezone_set('America/Guatemala');

// ── Configuración VAPID ──────────────────────────────────────

/** Clave pública VAPID (base64url del punto P-256 sin comprimir). */
function pushVapidPublicKey(): string
{
    $key = getenv('QCR_VAPID_PUBLIC_KEY');
    if ($key === false || trim($key) === '') {
        $key = defined('QCR_VAPID_PUBLIC_KEY_VALUE') ? (string)QCR_VAPID_PUBLIC_KEY_VALUE : '';
    }
    return trim($key);
}

/** Clave privada VAPID (PEM completo, multilínea). */
function pushVapidPrivateKey(): string
{
    $key = getenv('QCR_VAPID_PRIVATE_PEM');
    if ($key === false || trim($key) === '') {
        $key = defined('QCR_VAPID_PRIVATE_PEM_VALUE') ? (string)QCR_VAPID_PRIVATE_PEM_VALUE : '';
    }
    return trim($key);
}

/** Contacto mailto: del token VAPID (requerido por la spec). */
function pushVapidSubject(): string
{
    $sub = getenv('QCR_VAPID_SUBJECT');
    if ($sub === false || trim($sub) === '') {
        $sub = defined('QCR_VAPID_SUBJECT_VALUE') ? (string)QCR_VAPID_SUBJECT_VALUE : '';
    }
    $sub = trim($sub);
    return $sub === '' ? 'mailto:admin@localhost' : $sub;
}

/** ¿Están las claves VAPID listas (con valores reales, no placeholders)? */
function pushVapidConfigured(): bool
{
    $pub = pushVapidPublicKey();
    $priv = pushVapidPrivateKey();
    if ($pub === '' || $priv === '') {
        return false;
    }
    if (strpos($pub, 'PEGA_AQUI') !== false || strpos($priv, 'PEGA_AQUI') !== false) {
        return false;
    }
    // La pública debe decodificar a un punto P-256 sin comprimir
    $point = pushB64UrlDecode($pub);
    return strlen($point) === 65 && $point[0] === "\x04"
        && openssl_pkey_get_private($priv) !== false;
}

// ── Base64url ────────────────────────────────────────────────

function pushB64UrlEncode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function pushB64UrlDecode(string $data): string
{
    $rest = strlen($data) % 4;
    if ($rest) {
        $data .= str_repeat('=', 4 - $rest);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    return $decoded === false ? '' : $decoded;
}

/**
 * Genera un par de claves VAPID P-256.
 * Devuelve ['public_key' => base64url, 'private_pem' => PEM].
 * (La conversión punto→PEM del par PÚBLICO no se necesita aquí.)
 * Reintenta 3 veces: algunos hosts tienen OpenSSL caprichoso con
 * el archivo de estado del RNG en la primera llamada.
 */
function pushGenerateKeys(): array
{
    $res = false;
    $lastErr = '';
    for ($i = 0; $i < 3 && !$res; $i++) {
        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$res) {
            $lastErr = (string)openssl_error_string();
            usleep(50000);
        }
    }
    if (!$res) {
        throw new RuntimeException('openssl no pudo generar la clave EC: ' . $lastErr);
    }
    if (!openssl_pkey_export($res, $pem)) {
        throw new RuntimeException('openssl no pudo exportar la clave privada: ' . (string)openssl_error_string());
    }
    $details = openssl_pkey_get_details($res);
    if (!isset($details['ec']['x'], $details['ec']['y'])) {
        throw new RuntimeException('openssl no devolvió la clave pública EC');
    }
    $point = "\x04" . $details['ec']['x'] . $details['ec']['y'];

    return [
        'public_key'  => pushB64UrlEncode($point),
        'private_pem' => $pem,
    ];
}

/**
 * Recurso de clave PÚBLICA a partir del punto sin comprimir
 * (65 bytes, 0x04|x|y), envuelto en SPKI/PEM para openssl.
 */
function pushPublicKeyFromPoint(string $point)
{
    if (strlen($point) !== 65 || $point[0] !== "\x04") {
        return false;
    }
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    $pem = "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

// ── HKDF (RFC 5869, SHA-256) ─────────────────────────────────

function pushHkdf(string $ikm, string $salt, string $info, int $length): string
{
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $out = '';
    $t   = '';
    $i   = 1;
    while (strlen($out) < $length) {
        $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $out .= $t;
        $i++;
    }
    return substr($out, 0, $length);
}

// ── VAPID: token JWT firmado ES256 ───────────────────────────

/**
 * Convierte una firma ECDSA en formato DER (lo que produce
 * openssl_sign) al formato r||s de 64 bytes que exige ES256.
 */
function pushDerSignatureToRaw(string $der): string
{
    $len = strlen($der);
    if ($len < 8 || ord($der[0]) !== 0x30) {
        throw new RuntimeException('Firma DER inesperada');
    }
    $idx = 2;
    if (ord($der[$idx]) === 0x81) {
        $idx++;
    }
    // Primer INTEGER (r)
    if (ord($der[$idx]) !== 0x02) {
        throw new RuntimeException('Firma DER: falta r');
    }
    $rLen = ord($der[$idx + 1]);
    $r = substr($der, $idx + 2, $rLen);
    $idx += 2 + $rLen;
    // Segundo INTEGER (s)
    if (ord($der[$idx]) !== 0x02) {
        throw new RuntimeException('Firma DER: falta s');
    }
    $sLen = ord($der[$idx + 1]);
    $s = substr($der, $idx + 2, $sLen);

    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");

    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

/**
 * Token VAPID (JWT ES256) para el origen indicado.
 * Validez: 12 horas (los servicios push aceptan hasta 24 h).
 */
function pushVapidToken(string $audience): string
{
    $priv = openssl_pkey_get_private(pushVapidPrivateKey());
    if (!$priv) {
        throw new RuntimeException('La clave privada VAPID no es válida: ' . (string)openssl_error_string());
    }

    $header = pushB64UrlEncode((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = pushB64UrlEncode((string)json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => pushVapidSubject(),
    ]));
    $input = $header . '.' . $claims;

    if (!openssl_sign($input, $derSig, $priv, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('No se pudo firmar el token VAPID');
    }

    return $input . '.' . pushB64UrlEncode(pushDerSignatureToRaw($derSig));
}

// ── Cifrado del mensaje (RFC 8291, aes128gcm) ────────────────

/**
 * Cifra el payload para una suscripción concreta.
 * Devuelve:
 *   string  → cuerpo binario listo para POST (cabecera aes128gcm incluida)
 *   false   → suscripción/claves INVÁLIDAS (permanentemente inservible)
 *   null    → fallo INTERNO transitorio (RNG/ECDL/GCM): reintentar luego
 *
 * $ephForTest: SOLO para pruebas (inyecta la clave efímera para que
 * el banco de pruebas pueda descifrar y validar el formato byte a
 * byte). En producción nunca se pasa → clave efímera aleatoria.
 */
function pushEncrypt(string $p256dhB64, string $authB64, string $payload, $ephForTest = null)
{
    $userPub  = pushB64UrlDecode($p256dhB64);
    $authSec  = pushB64UrlDecode($authB64);

    if (strlen($userPub) !== 65 || $userPub[0] !== "\x04") {
        return false;   // clave pública del navegador inválida
    }
    if (strlen($authSec) < 8) {
        return false;   // secreto de autenticación inválido
    }

    // Clave efímera del servidor (una por mensaje; reintenta ante
    // fallos transitorios del RNG de OpenSSL en algunos hosts)
    $eph = $ephForTest;
    if (!$eph) {
        for ($i = 0; $i < 3 && !$eph; $i++) {
            $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if (!$eph) {
                usleep(50000);
            }
        }
        if (!$eph) {
            return null;
        }
    }
    $ephDetails = openssl_pkey_get_details($eph);
    $ephPub = "\x04" . $ephDetails['ec']['x'] . $ephDetails['ec']['y'];

    // Secreto compartido ECDH (orden: pública, privada — verificado en PHP 8.3)
    $userKey = pushPublicKeyFromPoint($userPub);
    if (!$userKey) {
        return false;
    }
    $shared = openssl_pkey_derive($userKey, $eph, 32);
    if ($shared === false) {
        return null;
    }

    // PRK y claves según RFC 8291 §3.3 (info con ambas claves, UA primero)
    $prk = pushHkdf($shared, $authSec, "WebPush: info\x00" . $userPub . $ephPub, 32);

    $salt = random_bytes(16);
    $cek   = pushHkdf($prk, $salt, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = pushHkdf($prk, $salt, "Content-Encoding: nonce\x00", 12);

    // Un solo registro: payload + delimitador final 0x02 (RFC 8188 §2)
    $body = $payload . "\x02";
    if (strlen($body) > 4096 - 17) {
        return false;   // excede el tamaño de registro declarado
    }

    $tag = '';
    $cipher = openssl_encrypt($body, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) {
        return null;
    }

    // Cabecera aes128gcm: salt(16) | rs(4) | idlen(1) | clave pública efímera
    $header = $salt . pack('N', 4096) . chr(65) . $ephPub;

    return $header . $cipher . $tag;
}

// ── Envío HTTP ───────────────────────────────────────────────

/**
 * POST binario con cabeceras. cURL primero, fallback a streams.
 * Devuelve [int|null $status, string|null $error].
 */
function pushHttpPost(string $url, array $headers, string $body, int $timeout = 10): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'QueChileroRadio-Push/1.0',
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return [null, "cURL error: {$err}"];
        }
        return [$code, null];
    }

    // Fallback sin cURL
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'timeout'       => $timeout,
            'user_agent'    => 'QueChileroRadio-Push/1.0',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    if ($resp === false && $code === 0) {
        return [null, 'file_get_contents falló (revisa allow_url_fopen)'];
    }
    return [$code, null];
}

// ── Preferencias de avisos (v0.1.6) ──────────────────────────
//
// El oyente elige una FRANJA horaria y solo recibe avisos de
// programas que EMPIEZAN dentro de ella (hora de inicio, zona
// horaria America/Guatemala):
//
//    all       → todos los programas (cualquier hora)
//    morning   → solo mañana    06:00 ≤ inicio < 14:00
//    afternoon → solo tarde     14:00 ≤ inicio < 21:00
//    day       → todo el día    06:00 ≤ inicio < 21:00
//
// Compatibilidad: las suscripciones hechas con v0.1.4/v0.1.5
// guardaron el modo "evening" (inicio ≥ 14:00 sin tope). Se
// sigue ACEPTANDO y se interpreta como "afternoon".

/** Modos válidos una vez normalizado el payload. */
const PUSH_MODES = ['all', 'morning', 'afternoon', 'day'];

/** Inicio de la franja mañana (minutos desde medianoche, incluido). */
const PUSH_MORNING_START = 6 * 60;   // 06:00

/** Fin de la franja mañana / inicio de la tarde (exclusivo). */
const PUSH_MORNING_END = 14 * 60;    // 14:00

/** Fin de la franja día/tarde (exclusivo). */
const PUSH_DAY_END = 21 * 60;        // 21:00

/**
 * Normaliza el modo recibido: minúsculas y alias legados.
 * "evening" (v0.1.4/v0.1.5) se convierte en "afternoon".
 */
function pushNormalizeMode(string $mode): string
{
    $mode = pushLower($mode);
    return $mode === 'evening' ? 'afternoon' : $mode;
}

/**
 * ¿Este suscriptor debe recibir el aviso de este programa según
 * su preferencia? La ventana se evalúa sobre la HORA DE INICIO
 * del programa. Modos desconocidos se tratan como "all".
 */
function pushModeAllows(array $subscriber, int $startMin): bool
{
    $mode = pushNormalizeMode((string)($subscriber['mode'] ?? 'all'));
    switch ($mode) {
        case 'morning':
            return $startMin >= PUSH_MORNING_START && $startMin < PUSH_MORNING_END;
        case 'afternoon':
            return $startMin >= PUSH_MORNING_END && $startMin < PUSH_DAY_END;
        case 'day':
            return $startMin >= PUSH_MORNING_START && $startMin < PUSH_DAY_END;
        case 'all':
        default:
            return true;
    }
}

// ── Almacén de suscriptores (compartido) ─────────────────────

/** Ruta del almacén de suscriptores (carpeta data/, protegida). */
function pushSubscribersFile(): string
{
    return getenv('QCR_SUBSCRIBERS_FILE') ?: __DIR__ . '/data/subscribers.json';
}

/** Minúsculas con fallback si el host no tiene mbstring. */
function pushLower(string $text): string
{
    $text = trim($text);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }
    return strtolower($text);
}

/** Lee la lista de suscriptores bajo lock compartido. */
function pushReadSubscribers(): array
{
    $file = pushSubscribersFile();
    if (!is_file($file)) {
        return [];
    }
    $fp = fopen($file, 'r');
    if (!$fp) {
        return [];
    }
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $list = json_decode((string)$content, true);
    return is_array($list) ? $list : [];
}

/** Escritura atómica de la lista completa (temporal + rename). */
function pushWriteSubscribers(array $list): bool
{
    $file = pushSubscribersFile();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $tmp  = $file . '.' . getmypid() . '.tmp';
    $json = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Elimina suscripciones muertas (por endpoint exacto).
 * Devuelve cuántas fueron borradas.
 */
function pushRemoveEndpoints(array $endpoints): int
{
    $endpoints = array_values(array_unique(array_filter(array_map('strval', $endpoints))));
    if (empty($endpoints)) {
        return 0;
    }
    $list = pushReadSubscribers();
    $before = count($list);
    $list = array_values(array_filter(
        $list,
        static function ($s) use ($endpoints) {
            return !in_array((string)($s['endpoint'] ?? ''), $endpoints, true);
        }
    ));
    if (count($list) === $before) {
        return 0;
    }
    return pushWriteSubscribers($list) ? $before - count($list) : 0;
}

/** ¿Es un host privado/loopback? (solo pruebas y servicios locales) */
function pushHostIsPrivate(string $host): bool
{
    if (pushLower($host) === 'localhost' || $host === '::1' || preg_match('/^127\./', $host)) {
        return true;
    }
    return (bool)preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
}

/**
 * Endpoint de suscripción válido. https:// siempre; http:// solo
 * aceptado para hosts loopback/privados (banco de pruebas / mock).
 */
function pushEndpointAllowed(string $endpoint): bool
{
    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
        return false;
    }
    if (preg_match('#^https://#i', $endpoint)) {
        return true;
    }
    if (preg_match('#^http://#i', $endpoint)) {
        $host = (string)(parse_url($endpoint, PHP_URL_HOST) ?? '');
        return $host !== '' && pushHostIsPrivate($host);
    }
    return false;
}

/**
 * Envía un payload (array → JSON) a UNA suscripción.
 *
 * Devuelve:
 *   ['ok'=>bool, 'status'=>int|null, 'gone'=>bool, 'retryable'=>bool, 'error'=>?string]
 *   gone     → suscripción muerta (404/410): borrarla del almacén
 *   retryable → error temporal (429/5xx/red): reintentar luego
 */
function pushSendToSubscription(array $sub, array $payload): array
{
    $result = ['ok' => false, 'status' => null, 'gone' => false, 'retryable' => false, 'error' => null];

    if (!pushVapidConfigured()) {
        $result['error'] = 'Claves VAPID sin configurar (php api/push-trigger.php --generate-keys)';
        return $result;
    }

    $endpoint = trim((string)($sub['endpoint'] ?? ''));
    $p256dh   = (string)($sub['keys']['p256dh'] ?? '');
    $auth     = (string)($sub['keys']['auth'] ?? '');

    if ($endpoint === '' || !pushEndpointAllowed($endpoint)) {
        $result['gone'] = true;
        $result['error'] = 'endpoint inválido';
        return $result;
    }

    $encrypted = pushEncrypt($p256dh, $auth, (string)json_encode($payload, JSON_UNESCAPED_UNICODE));
    if ($encrypted === false) {
        $result['gone'] = true;   // claves de la suscripción inservibles
        $result['error'] = 'no se pudo cifrar para esta suscripción';
        return $result;
    }
    if ($encrypted === null) {
        // Fallo interno TRANSITORIO: NO borrar la suscripción
        $result['retryable'] = true;
        $result['error'] = 'fallo interno de cifrado (transitorio)';
        return $result;
    }

    // Audiencia del token = origen del endpoint (esquema://host[:puerto])
    $parts = parse_url($endpoint);
    $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    if (isset($parts['port']) && !in_array($parts['port'], [80, 443], true)) {
        $audience .= ':' . $parts['port'];
    }

    $token = pushVapidToken($audience);

    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        // TTL corto: si el teléfono está apagado más de 5 min, el
        // aviso «en 10 minutos» ya no tiene sentido → no entregar.
        'TTL: 300',
        'Urgency: normal',
        'Authorization: vapid t=' . $token . ', k=' . pushVapidPublicKey(),
    ];

    [$status, $err] = pushHttpPost($endpoint, $headers, $encrypted);

    $result['status'] = $status;
    if ($err !== null) {
        $result['error'] = $err;
        $result['retryable'] = true;
        return $result;
    }

    if ($status >= 200 && $status < 300) {
        $result['ok'] = true;
        return $result;
    }

    if ($status === 404 || $status === 410) {
        $result['gone'] = true;
        $result['error'] = "suscripción expirada (HTTP {$status})";
        return $result;
    }

    if ($status === 429 || $status >= 500) {
        $result['retryable'] = true;
        $result['error'] = "error temporal del servicio push (HTTP {$status})";
        return $result;
    }

    // 400/401/403: problema permanente (claves VAPID, payload, etc.)
    $result['error'] = "rechazo del servicio push (HTTP {$status})";
    return $result;
}
