<?php
/**
 * ============================================================
 *  Que Chilero Radio — Clave pública VAPID para el navegador
 * ============================================================
 *  v1.5 — Endpoint JSON mínimo: devuelve la clave pública VAPID
 *  que applicationServerKey necesita para suscribirse. La clave
 *  pública NO es un secreto (así está diseñado Web Push); el
 *  service worker también la consulta al resuscribirse.
 *
 *  Respuestas:
 *    200 {"publicKey": "..."}     claves configuradas
 *    503 {"error":"push_disabled"} sin claves → la web oculta el botón
 * ============================================================
 */

require_once __DIR__ . '/push-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!pushVapidConfigured()) {
    http_response_code(503);
    echo json_encode(['error' => 'push_disabled'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(
    ['publicKey' => pushVapidPublicKey()],
    JSON_UNESCAPED_UNICODE
);
