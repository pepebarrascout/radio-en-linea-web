<?php
/**
 * ============================================================
 *  Que Chilero Radio — Exportación de votos para Jellyfin
 * ============================================================
 *  ⚠️ PRIVADO: SIEMPRE requiere el token secreto. Sin token
 *  responde 403 (los números de votos no son públicos).
 *
 *  Modos (siempre con token):
 *
 *  A) VER sin consumir — para comprobar a mano, NO toca nada:
 *
 *      GET https://quechilero.com/api/votes.php?token=TU_TOKEN&ver=1
 *
 *  B) CONSUMO SEMANAL (el que debe llamar Jellyfin 1 vez/semana):
 *
 *      GET https://quechilero.com/api/votes.php?token=TU_TOKEN
 *
 *      → En UNA SOLA operación atómica:
 *          1. Devuelve TODO lo acumulado ordenado por popularidad
 *             (más "me gusta" primero). Cada canción incluye su
 *             itemId de Jellyfin para que el plugin pueda consultar
 *             la canción en su propia base de datos.
 *          2. Guarda copia de respaldo en api/data/votes-archivo-*.json
 *             (carpeta protegida; se conservan las últimas 12 ≈ 3 meses).
 *          3. Pone el contador a CERO y limpia también los votantes
 *             (votes.json + voters.json) → todos pueden volver a votar.
 *
 *  ⚠️ EL TOKEN NO SE HARDCODEA AQUÍ: se define en api/config.php
 *     (copia config.example.php; NO versionado) o en la variable de
 *     entorno QCR_VOTES_TOKEN. Sin token configurado el consumo
 *     queda deshabilitado (503). No lo compartas: cualquiera que
 *     llame con él reinicia los votos.
 * ============================================================
 */

// ── TOKEN SECRETO DE CONSUMO (nunca hardcodeado) ────────────
// Orden de resolución:
//   1. Variable de entorno QCR_VOTES_TOKEN
//   2. Constante QCR_VOTES_TOKEN_VALUE de api/config.php
//      (archivo local, NO versionado: copia config.example.php)
if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
define('VOTES_CONSUME_TOKEN', getenv('QCR_VOTES_TOKEN')
    ?: (defined('QCR_VOTES_TOKEN_VALUE') ? QCR_VOTES_TOKEN_VALUE : ''));

require_once __DIR__ . '/vote-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit();
}

$token = (string)($_GET['token'] ?? '');

if (VOTES_CONSUME_TOKEN === '') {
    // Sin token configurado el consumo queda deshabilitado (no es un
    // fallo de seguridad: es configuración pendiente del administrador)
    http_response_code(503);
    echo json_encode([
        'error'   => 'token_no_configurado',
        'detalle' => 'Copia api/config.example.php a api/config.php y define QCR_VOTES_TOKEN_VALUE (o usa la variable de entorno QCR_VOTES_TOKEN).',
    ]);
    exit();
}

if ($token === '' || !hash_equals(VOTES_CONSUME_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'token_requerido']);
    exit();
}

// ── Modo A: ver sin consumir (?ver=1) ───────────────────────
if (!empty($_GET['ver'])) {
    $songs = getVotesForJellyfin();
    echo json_encode([
        'generated' => date('Y-m-d H:i:s'),
        'count'     => count($songs),
        'songs'     => $songs,
        'consumido' => false,
        'nota'      => 'Solo lectura: nada fue reiniciado',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── Modo B: consumo semanal ─────────────────────────────────
$result = consumeAllVotes();

if ($result === false) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'storage_error',
        'detalle' => 'No se pudo respaldar o reiniciar. NO se perdió ningún voto: el contador no fue tocado. Revisa permisos de api/data/ (755).',
    ]);
    exit();
}

echo json_encode([
    'generated' => date('Y-m-d H:i:s'),
    'count'     => $result['count'],
    'songs'     => $result['songs'],
    'consumido' => true,
    'respaldo'  => $result['backup'],
    'ciclo'     => 'Contador reiniciado: nuevo ciclo de votación',
], JSON_UNESCAPED_UNICODE);
