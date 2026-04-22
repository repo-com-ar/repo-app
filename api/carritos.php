<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../repo-api/config/db.php';
require_once __DIR__ . '/lib/jwt.php';

try {
    $pdo = getDB();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB: ' . $e->getMessage()]);
    exit;
}

$payload = app_jwt_from_request();
if (!$payload || empty($payload['cliente_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}
$clienteId = (int)$payload['cliente_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true);
$items     = $body['items']      ?? [];
$total     = (float)($body['total']     ?? 0);
$sessionId = trim($body['session_id'] ?? '');

if ($sessionId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'session_id requerido']);
    exit;
}

// Buscar carrito activo/abandonado del usuario para esta sesión
$stmt = $pdo->prepare(
    "SELECT id FROM carritos WHERE usuario_id = ? AND session_id = ? AND estado IN ('activo','abandonado') ORDER BY updated_at DESC LIMIT 1"
);
$stmt->execute([$clienteId, $sessionId]);
$existing = $stmt->fetch();

if ($existing) {
    $carritoId = (int)$existing['id'];
    $pdo->prepare("UPDATE carritos SET total = ?, estado = 'activo', updated_at = NOW() WHERE id = ?")
        ->execute([$total, $carritoId]);
} else {
    $pdo->prepare("INSERT INTO carritos (usuario_id, session_id, estado, total) VALUES (?, ?, 'activo', ?)")
        ->execute([$clienteId, $sessionId, $total]);
    $carritoId = (int)$pdo->lastInsertId();
}

// Reemplazar todos los ítems
$pdo->prepare("DELETE FROM carritos_items WHERE carrito_id = ?")->execute([$carritoId]);

if (!empty($items)) {
    $stmtItem = $pdo->prepare(
        "INSERT INTO carritos_items (carrito_id, producto_id, nombre, precio, cantidad) VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($items as $item) {
        $stmtItem->execute([
            $carritoId,
            isset($item['id']) ? (int)$item['id'] : null,
            $item['nombre'] ?? '',
            (float)($item['precio'] ?? 0),
            (int)($item['cantidad'] ?? 1),
        ]);
    }
}

echo json_encode(['ok' => true, 'carrito_id' => $carritoId]);
