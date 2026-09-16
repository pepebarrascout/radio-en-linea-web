<?php
/**
 * ============================================================
 *  Que Chilero Radio — Endpoint de votos
 * ============================================================
 *  GET   → SOLO mis votos (según cookie anónima), sin conteos:
 *          { myVotes: { "artista||título": "like"|"dislike" } }
 *          (los números de votos NO son públicos)
 *
 *  POST  → Emitir / cambiar / anular un voto
 *          Cuerpo JSON: { artist, title, vote: "like"|"dislike"|"remove",
 *                         itemId: "<ID de Jellyfin>" (opcional pero recomendado) }
 *          Respuesta:   { success, key, myVote }   ← sin conteos
 *
 *  Un visitante = cookie anónima qc_vid (1 año, HttpOnly).
 *  Sin registro de usuario. El voto es cambiable (1 voto activo
 *  por visitante y canción).
 *
 *  Si llega itemId (GUID de Jellyfin de NowPlaying), es la clave
 *  del voto: Jellyfin podrá consultar su base de datos con ella.
 * ============================================================
 */

require_once __DIR__ . '/vote-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── GET: solo mis votos (SIN conteos: no son públicos) ──────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $vid     = getOrCreateVoterId();   // crea cookie si falta
    $myVotes = getMyVotesPublic($vid);

    // Garantizar objeto JSON ({}) incluso cuando está vacío
    echo json_encode([
        'myVotes' => $myVotes === [] ? new stdClass() : $myVotes,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── POST: emitir voto ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['artist'], $input['title'], $input['vote'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Datos inválidos. Se requiere artist, title y vote (like|dislike|remove).',
        ]);
        exit();
    }

    $vid = getOrCreateVoterId();   // crea cookie si falta (antes del echo)

    [$ok, $error, $result] = applyVote(
        $vid,
        (string)$input['artist'],
        (string)$input['title'],
        (string)$input['vote'],
        (string)($input['itemId'] ?? '')
    );

    if (!$ok) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $error]);
        exit();
    }

    // SIN conteos en la respuesta: no son públicos
    echo json_encode([
        'success' => true,
        'key'     => $result['key'],
        'myVote'  => $result['myVote'],
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Método no permitido
http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
