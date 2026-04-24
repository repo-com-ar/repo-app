<?php
/**
 * API pública — Clientes
 *
 * GET /repo-app/api/clientes.php?id={id}
 * Devuelve los datos de un cliente por ID.
 * Usada por el frontend para pre-rellenar el formulario de checkout
 * cuando el cliente ya compró anteriormente (cookie cliente_id).
 *
 * Parámetros GET:
 *   id (int, requerido) — ID del cliente
 *
 * Respuesta:
 *   { ok: true, data: { id, nombre, celular, direccion } }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$configPath = __DIR__ . '/../../repo-api/config/db.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config no encontrado']);
    exit;
}
require_once $configPath;
require_once __DIR__ . '/lib/jwt.php';
require_once __DIR__ . '/lib/geocoding.php';

try {
    $pdo = getDB();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB: ' . $e->getMessage()]);
    exit;
}

// GET: obtener cliente por JWT (Bearer) o por ?id
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Prioridad: Bearer JWT > ?id
    $payload = app_jwt_from_request();
    if ($payload && !empty($payload['cliente_id'])) {
        $id = (int)$payload['cliente_id'];
        app_touch_last_seen($pdo, $id);
    } else {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    }

    if (!$id) {
        echo json_encode(['ok' => false, 'error' => 'ID requerido']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, nombre, celular, direccion, correo, lat, lng FROM clientes WHERE id = ?");
    $stmt->execute([$id]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        echo json_encode(['ok' => false, 'error' => 'Cliente no encontrado']);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => $cliente]);
}

// POST: actualización silenciosa de ubicación (requiere JWT).
// Se usa al abrir la app cuando el permiso de geolocalización ya está concedido.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = app_jwt_from_request();
    if (!$payload || empty($payload['cliente_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'No autorizado']);
        exit;
    }
    $clienteId = (int)$payload['cliente_id'];
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $lat  = isset($body['lat']) && $body['lat'] !== null ? (float)$body['lat'] : null;
    $lng  = isset($body['lng']) && $body['lng'] !== null ? (float)$body['lng'] : null;

    if ($lat !== null && $lng !== null) {
        guardarUbicacionCliente($pdo, $clienteId, $lat, $lng);
    }
    app_touch_last_seen($pdo, $clienteId);

    echo json_encode(['ok' => true]);
    exit;
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
    $celular  = isset($body['celular'])  ? trim($body['celular'])  : null;
    $direccion = isset($body['direccion']) ? trim($body['direccion']) : null;
    $correo    = isset($body['correo'])    ? trim($body['correo'])    : null;
    $lat       = array_key_exists('lat', $body) ? ($body['lat'] !== null ? (float)$body['lat'] : null) : false;
    $lng       = array_key_exists('lng', $body) ? ($body['lng'] !== null ? (float)$body['lng'] : null) : false;

    if (!$nombre) {
        echo json_encode(['ok' => false, 'error' => 'El nombre es requerido']);
        exit;
    }

    $campos = ['nombre = ?', 'celular = ?', 'direccion = ?', 'correo = ?'];
    $params = [$nombre, $celular ?: null, $direccion ?: null, $correo ?: null];

    // Manejo de ubicación:
    //   - si el cliente borra su ubicación (lat/lng === null): limpiar todos los campos geo.
    //   - si la está seteando (lat/lng numéricos): no los escribimos aquí,
    //     lo hace guardarUbicacionCliente() junto con el reverse geocoding.
    $clearUbic = ($lat !== false && $lat === null) || ($lng !== false && $lng === null);
    $setUbic   = ($lat !== false && $lat !== null) && ($lng !== false && $lng !== null);

    if ($clearUbic) {
        $campos[] = 'lat = ?';           $params[] = null;
        $campos[] = 'lng = ?';           $params[] = null;
        $campos[] = 'direccion_geo = ?'; $params[] = null;
        $campos[] = 'localidad = ?';     $params[] = null;
        $campos[] = 'provincia = ?';     $params[] = null;
        $campos[] = 'pais = ?';          $params[] = null;
    }

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

    if ($setUbic) {
        guardarUbicacionCliente($pdo, $id, (float)$lat, (float)$lng);
    }

    echo json_encode(['ok' => true]);
}
