<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$configPath = __DIR__ . '/../../repo-api/config/db.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config no encontrado']);
    exit;
}
require_once $configPath;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$cliente_id = isset($input['cliente_id']) ? intval($input['cliente_id']) : 0;
$detalle    = isset($input['detalle']) ? trim($input['detalle']) : '';

if ($detalle === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Detalle requerido']);
    exit;
}

try {
    $pdo = getDB();

    // Crear tabla si no existe
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cliente_id  INT UNSIGNED NOT NULL DEFAULT 0,
            detalle     VARCHAR(500) NOT NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare("INSERT INTO eventos (cliente_id, detalle) VALUES (:cliente_id, :detalle)");
    $stmt->execute([':cliente_id' => $cliente_id, ':detalle' => $detalle]);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al registrar evento']);
}
