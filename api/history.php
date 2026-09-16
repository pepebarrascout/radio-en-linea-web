<?php
/**
 * ============================================================
 *  Que Chilero Radio — API de historial de canciones
 * ============================================================
 *  GET   → Devuelve el historial de las últimas canciones
 *  POST  → Registra la canción actual (idempotente)
 *
 *  Este endpoint es IDEMPOTENTE: registra la canción que está
 *  sonando AHORA solo si no fue ya registrada por el cron del
 *  servidor. El navegador puede llamarlo como respaldo sin
 *  riesgo de duplicados ni corrupción del archivo.
 *
 *  El actualizador automático 24/7 es api/cron-update.php
 *  (ver README para configurar el cron).
 * ============================================================
 */

require_once __DIR__ . '/history-lib.php';
require_once __DIR__ . '/covers-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// GET - Obtener historial
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(
        ['history' => historyToPublicWithCovers(readHistoryLocked())],
        JSON_UNESCAPED_UNICODE
    );
    exit();
}

// POST - Registrar la canción actual (idempotente)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['title']) || !isset($input['artist'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos. Se requiere title y artist.']);
        exit();
    }

    [$registered, $history, $reason] = registerSong($input);

    // ⭐ Portada INMEDIATA: el navegador envía el artworkUrl que ya tiene
    // en pantalla, así que el servidor baja la copia local (150px) AHORA
    // en vez de esperar a la siguiente vuelta del cron. Idempotente: si
    // ya estaba en caché no descarga nada. El cron sigue siendo el
    // respaldo principal 24/7.
    $coverStatus = null;
    if ($registered) {
        // El navegador recibe el artwork a través del proxy local
        // (api/artwork.php — el dominio real nunca viaja al cliente);
        // aquí se traduce a la URL real configurada para poder bajarla.
        $artworkUrl = qcrResolveArtworkUrl((string)($input['artworkUrl'] ?? ''));
        if ($artworkUrl !== '') {
            try {
                $coverStatus = ensureCoverCached(
                    trim((string)$input['artist']),
                    trim((string)$input['title']),
                    $artworkUrl
                );
            } catch (Exception $e) {
                $coverStatus = 'failed';   // nunca rompe el registro
            }
        }
    }

    echo json_encode([
        'success'    => true,
        'registered' => $registered,
        'reason'     => $reason,
        'cover'      => $coverStatus,
        'message'    => $registered
            ? 'Canción registrada en el historial'
            : 'Canción ya registrada (misma emisión en curso)',
        'history'    => historyToPublicWithCovers($history),
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Método no permitido
http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
