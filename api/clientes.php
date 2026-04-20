<?php
/**
 * API pública — Clientes
 *
 * GET /lider-app/api/clientes.php?id={id}
 * Devuelve los datos de un cliente por ID.
 * Usada por el frontend para pre-rellenar el formulario de checkout
 * cuando el cliente ya compró anteriormente (cookie cliente_id).
 *
 * Parámetros GET:
 *   id (int, requerido) — ID del cliente
 *
 * Respuesta:
 *   { ok: true, data: { id, nombre, telefono, direccion } }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$configPath = __DIR__ . '/../../config/db.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config no encontrado']);
    exit;
}
require_once $configPath;

try {
    $pdo = getDB();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB: ' . $e->getMessage()]);
    exit;
}

// GET: obtener cliente por ID
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) {
        echo json_encode(['ok' => false, 'error' => 'ID requerido']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, nombre, telefono, direccion, correo, lat, lng FROM clientes WHERE id = ?");
    $stmt->execute([$id]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        echo json_encode(['ok' => false, 'error' => 'Cliente no encontrado']);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => $cliente]);
}

// PATCH: actualizar datos del cliente
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = isset($body['id']) ? (int)$body['id'] : 0;
    if (!$id) {
        echo json_encode(['ok' => false, 'error' => 'ID requerido']);
        exit;
    }

    $nombre    = isset($body['nombre'])    ? trim($body['nombre'])    : null;
    $telefono  = isset($body['telefono'])  ? trim($body['telefono'])  : null;
    $direccion = isset($body['direccion']) ? trim($body['direccion']) : null;
    $correo    = isset($body['correo'])    ? trim($body['correo'])    : null;
    $lat       = array_key_exists('lat', $body) ? ($body['lat'] !== null ? (float)$body['lat'] : null) : false;
    $lng       = array_key_exists('lng', $body) ? ($body['lng'] !== null ? (float)$body['lng'] : null) : false;

    if (!$nombre) {
        echo json_encode(['ok' => false, 'error' => 'El nombre es requerido']);
        exit;
    }

    $campos = ['nombre = ?', 'telefono = ?', 'direccion = ?', 'correo = ?'];
    $params = [$nombre, $telefono ?: null, $direccion ?: null, $correo ?: null];

    if ($lat !== false) { $campos[] = 'lat = ?'; $params[] = $lat; }
    if ($lng !== false) { $campos[] = 'lng = ?'; $params[] = $lng; }

    $params[] = $id;
    $stmt = $pdo->prepare("UPDATE clientes SET " . implode(', ', $campos) . " WHERE id = ?");
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        // Verificar si el cliente existe
        $check = $pdo->prepare("SELECT id FROM clientes WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Cliente no encontrado']);
            exit;
        }
    }

    echo json_encode(['ok' => true]);
}
