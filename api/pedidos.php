<?php
/**
 * API pública — Pedidos
 *
 * POST /repo-app/api/pedidos.php
 *   Crea un nuevo pedido. Busca o crea el cliente por nombre+teléfono,
 *   inserta el pedido y sus ítems en una transacción, y calcula
 *   distancia/tiempo desde el centro de distribución via Google Distance Matrix API.
 *   Body JSON: { items, cliente, celular, direccion, notas, lat?, lng? }
 *   Respuesta: { ok: true, pedido: { numero, fecha, cliente, ... } }
 *
 * GET /repo-app/api/pedidos.php
 *   Lista los últimos 20 pedidos con sus ítems.
 *   Respuesta: { ok: true, data: [ { id, numero, cliente, items, total, estado, ... } ] }
 *
 * Auto-migraciones: agrega columnas lat, lng, distancia_km, tiempo_min si no existen.
 */
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$configPath = __DIR__ . '/../../repo-api/config/db.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config/db.php no encontrado', 'path' => realpath(__DIR__), 'expected' => $configPath]);
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

// Obtener distancia y tiempo por calles usando Google Distance Matrix API
function calcDistanciaYTiempo($lat1, $lng1, $lat2, $lng2) {
    $apiKey = 'AIzaSyDXN7-CpoFdxh_6V-_7UQkPzWFbX6_T1p0';
    $url = 'https://maps.googleapis.com/maps/api/distancematrix/json?'
         . 'origins=' . $lat1 . ',' . $lng1
         . '&destinations=' . $lat2 . ',' . $lng2
         . '&mode=driving&language=es&key=' . $apiKey;
    $resp = @file_get_contents($url);
    if ($resp) {
        $data = json_decode($resp, true);
        $el = isset($data['rows'][0]['elements'][0]) ? $data['rows'][0]['elements'][0] : null;
        if ($el && isset($el['distance']['value']) && isset($el['duration']['value'])) {
            return array(
                'km'  => round($el['distance']['value'] / 1000, 2),
                'min' => round($el['duration']['value'] / 60)
            );
        }
    }
    return array('km' => 0, 'min' => 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body['items']) || empty($body['cliente'])) {
        echo json_encode(['ok' => false, 'msg' => 'Faltan datos del pedido.']);
        exit;
    }

    $numero = 'PED-' . strtoupper(substr(uniqid(), -6));
    $total  = 0;
    foreach ($body['items'] as $it) {
        $total += $it['precio'] * $it['cantidad'];
    }

    $pedLat = isset($body['lat']) && $body['lat'] !== '' ? (float)$body['lat'] : null;
    $pedLng = isset($body['lng']) && $body['lng'] !== '' ? (float)$body['lng'] : null;

    // Calcular distancia y tiempo desde centro de distribución
    $distanciaKm = 0;
    $tiempoMin = 0;
    if ($pedLat !== null && $pedLng !== null) {
        $stmtCfg = $pdo->prepare("SELECT clave, valor FROM configuracion WHERE clave IN ('centro_dist_lat','centro_dist_lng')");
        $stmtCfg->execute();
        $cfgRows = $stmtCfg->fetchAll();
        $cfg = [];
        foreach ($cfgRows as $r) { $cfg[$r['clave']] = $r['valor']; }
        if (!empty($cfg['centro_dist_lat']) && !empty($cfg['centro_dist_lng'])) {
            $result = calcDistanciaYTiempo(
                (float)$cfg['centro_dist_lat'], (float)$cfg['centro_dist_lng'],
                $pedLat, $pedLng
            );
            $distanciaKm = $result['km'];
            $tiempoMin = $result['min'];
        }
    }

    $pdo->beginTransaction();
    try {
        // Buscar o crear cliente
        $clienteNombre = trim($body['cliente']);
        $clienteTel = trim($body['celular'] ?? '');
        $clienteDir = trim($body['direccion'] ?? '');
        $clienteCorreo = trim($body['correo'] ?? '');
        $clienteId = null;

        // Buscar por nombre + teléfono
        $stmtCli = $pdo->prepare("SELECT id FROM clientes WHERE nombre = ? AND celular = ? LIMIT 1");
        $stmtCli->execute([$clienteNombre, $clienteTel]);
        $existente = $stmtCli->fetch();

        if ($existente) {
            $clienteId = (int)$existente['id'];
            $pdo->prepare("UPDATE clientes SET direccion = ?, correo = ?, updated_at = NOW() WHERE id = ?")->execute([$clienteDir, $clienteCorreo ?: null, $clienteId]);
        } else {
            $pdo->prepare("INSERT INTO clientes (nombre, celular, direccion, correo) VALUES (?, ?, ?, ?)")->execute([$clienteNombre, $clienteTel, $clienteDir, $clienteCorreo ?: null]);
            $clienteId = (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("
            INSERT INTO pedidos (numero, cliente_id, cliente, correo, celular, direccion, notas, total, estado, lat, lng, distancia_km, tiempo_min)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $numero,
            $clienteId,
            $clienteNombre,
            $clienteCorreo ?: null,
            $clienteTel,
            $clienteDir,
            $body['notas'] ?? '',
            $total,
            $pedLat,
            $pedLng,
            $distanciaKm,
            $tiempoMin,
        ]);
        $pedidoId = $pdo->lastInsertId();

        $stmtItem = $pdo->prepare("
            INSERT INTO pedido_items (pedido_id, producto_id, nombre, precio, cantidad)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmtStock = $pdo->prepare("
            UPDATE productos
            SET stock_actual       = GREATEST(0, stock_actual - ?),
                stock_comprometido = stock_comprometido + ?
            WHERE id = ? AND stock_actual >= ?
        ");
        foreach ($body['items'] as $item) {
            $productoId = $item['id'] ?? null;
            $cantidad   = (int)($item['cantidad'] ?? 1);
            $stmtItem->execute([
                $pedidoId,
                $productoId,
                $item['nombre'],
                $item['precio'],
                $cantidad,
            ]);
            if ($productoId) {
                $stmtStock->execute([$cantidad, $cantidad, $productoId, $cantidad]);
            }
        }

        // Guardar ubicación GPS en la ficha del cliente
        if ($pedLat !== null && $pedLng !== null) {
            $pdo->prepare("UPDATE clientes SET lat = ?, lng = ? WHERE id = ?")
                ->execute([$pedLat, $pedLng, $clienteId]);
        }

        $pdo->commit();

        $pedido = [
            'numero'    => $numero,
            'fecha'     => date('Y-m-d H:i:s'),
            'cliente'   => $clienteNombre,
            'cliente_id' => $clienteId,
            'celular'  => $clienteTel,
            'direccion' => $clienteDir,
            'notas'     => $body['notas'] ?? '',
            'items'     => $body['items'],
            'total'     => $total,
            'estado'    => 'pendiente',
        ];

        echo json_encode(['ok' => true, 'pedido' => $pedido]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Error al guardar pedido']);
    }
    exit;
}

// GET: pedidos de un cliente específico
if (isset($_GET['cliente_id'])) {
    $cliId = (int)$_GET['cliente_id'];
    $stmt  = $pdo->prepare("
        SELECT id, numero, total, estado, created_at as fecha
        FROM pedidos WHERE cliente_id = ? ORDER BY id DESC LIMIT 50
    ");
    $stmt->execute([$cliId]);
    $pedidos = $stmt->fetchAll();
    foreach ($pedidos as &$ped) {
        $si = $pdo->prepare("SELECT nombre, precio, cantidad FROM pedido_items WHERE pedido_id = ?");
        $si->execute([$ped['id']]);
        $ped['items'] = $si->fetchAll();
        $ped['total'] = (float)$ped['total'];
    }
    echo json_encode(['ok' => true, 'data' => $pedidos]);
    exit;
}

// GET: listar últimos pedidos
$stmt = $pdo->query("
    SELECT id, numero, cliente, celular, direccion, notas, total, estado, created_at as fecha
    FROM pedidos ORDER BY id DESC LIMIT 20
");
$pedidos = $stmt->fetchAll();

foreach ($pedidos as &$ped) {
    $stmtItems = $pdo->prepare("SELECT nombre, precio, cantidad FROM pedido_items WHERE pedido_id = ?");
    $stmtItems->execute([$ped['id']]);
    $ped['items'] = $stmtItems->fetchAll();
    $ped['total'] = (float)$ped['total'];
}

echo json_encode(['ok' => true, 'data' => $pedidos]);
