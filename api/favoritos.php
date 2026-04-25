<?php
/**
 * API — Favoritos
 *
 * GET  /api/favoritos        → { ok: true, ids: [1, 2, ...] }
 * POST /api/favoritos        body: { producto_id: int }
 *                            → { ok: true, favorito: true|false }
 *                              (toggle: agrega si no existe, elimina si existe)
 *
 * Requiere JWT en Authorization header.
 * Auto-migración: crea la tabla favoritos si no existe.
 */
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

// Auto-migración
$pdo->exec("
    CREATE TABLE IF NOT EXISTS favoritos (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cliente_id  INT UNSIGNED NOT NULL,
        producto_id INT UNSIGNED NOT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cliente_producto (cliente_id, producto_id),
        INDEX idx_cliente (cliente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$jwt = app_jwt_from_request();
if (!$jwt || empty($jwt['cliente_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}
$clienteId = (int) $jwt['cliente_id'];

// GET: listar IDs de productos favoritos
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT producto_id FROM favoritos WHERE cliente_id = ?");
    $stmt->execute([$clienteId]);
    $ids = array_column($stmt->fetchAll(), 'producto_id');
    echo json_encode(['ok' => true, 'ids' => array_map('intval', $ids)]);
    exit;
}

// POST: toggle favorito
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $productoId = (int) ($body['producto_id'] ?? 0);

    if (!$productoId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'producto_id requerido']);
        exit;
    }

    $stmtCheck = $pdo->prepare("SELECT id FROM favoritos WHERE cliente_id = ? AND producto_id = ?");
    $stmtCheck->execute([$clienteId, $productoId]);
    $existe = $stmtCheck->fetch();

    if ($existe) {
        $pdo->prepare("DELETE FROM favoritos WHERE cliente_id = ? AND producto_id = ?")
            ->execute([$clienteId, $productoId]);
        echo json_encode(['ok' => true, 'favorito' => false]);
    } else {
        $pdo->prepare("INSERT INTO favoritos (cliente_id, producto_id) VALUES (?, ?)")
            ->execute([$clienteId, $productoId]);
        echo json_encode(['ok' => true, 'favorito' => true]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
