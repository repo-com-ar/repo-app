<?php
/**
 * API — Notificaciones del cliente
 *
 * GET  /api/notificaciones                 → { ok, data: [...], unread: N }
 * PATCH /api/notificaciones                body: { id?: int, mark_all?: bool }
 *                                          → { ok, unread: N }
 *
 * Requiere JWT en Authorization header.
 */
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../repo-api/config/db.php';
require_once __DIR__ . '/../../repo-api/lib/pushservice.php';
require_once __DIR__ . '/lib/jwt.php';

try {
    $pdo = getDB();
    push_ensure_schema(); // asegura tabla notificaciones
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB: ' . $e->getMessage()]);
    exit;
}

$jwt = app_jwt_from_request();
if (!$jwt || empty($jwt['cliente_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}
$clienteId = (int) $jwt['cliente_id'];
$actorType = 'cliente';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        "SELECT id, titulo, cuerpo, data, estado, leida, created_at
         FROM notificaciones
         WHERE actor_type = ? AND actor_id = ?
         ORDER BY created_at DESC LIMIT 100"
    );
    $stmt->execute([$actorType, $clienteId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['data']  = $r['data']  ? json_decode($r['data'], true) : null;
        $r['leida'] = (int) $r['leida'] === 1;
    }
    unset($r);

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE actor_type = ? AND actor_id = ? AND leida = 0");
    $stmtCount->execute([$actorType, $clienteId]);
    $unread = (int) $stmtCount->fetchColumn();

    echo json_encode(['ok' => true, 'data' => $rows, 'unread' => $unread]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $id      = (int) ($body['id']       ?? 0);
    $markAll = (bool)($body['mark_all'] ?? false);

    if ($markAll) {
        $pdo->prepare(
            "UPDATE notificaciones SET leida = 1, leida_at = NOW()
             WHERE actor_type = ? AND actor_id = ? AND leida = 0"
        )->execute([$actorType, $clienteId]);
    } elseif ($id) {
        $pdo->prepare(
            "UPDATE notificaciones SET leida = 1, leida_at = NOW()
             WHERE id = ? AND actor_type = ? AND actor_id = ? AND leida = 0"
        )->execute([$id, $actorType, $clienteId]);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'id o mark_all requerido']);
        exit;
    }

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE actor_type = ? AND actor_id = ? AND leida = 0");
    $stmtCount->execute([$actorType, $clienteId]);
    $unread = (int) $stmtCount->fetchColumn();

    echo json_encode(['ok' => true, 'unread' => $unread]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
