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
header('Access-Control-Allow-Methods: POST, GET, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$configPath = __DIR__ . '/../../repo-api/config/db.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config/db.php no encontrado', 'path' => realpath(__DIR__), 'expected' => $configPath]);
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

    $pedLat = isset($body['lat']) && $body['lat'] !== '' && $body['lat'] !== null ? (float)$body['lat'] : null;
    $pedLng = isset($body['lng']) && $body['lng'] !== '' && $body['lng'] !== null ? (float)$body['lng'] : null;
    $direccionId = isset($body['direccion_id']) && $body['direccion_id'] ? (int)$body['direccion_id'] : null;

    // Asegurar schema clientes_direcciones + columna direccion_id en pedidos
    ensure_direcciones_table($pdo);
    try { $pdo->query("SELECT direccion_id FROM pedidos LIMIT 1"); } catch (Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE pedidos ADD COLUMN direccion_id INT UNSIGNED NULL AFTER direccion, ADD INDEX idx_direccion_id (direccion_id)");
        } catch (Throwable $e2) { /* silencioso */ }
    }

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
        $clienteTel    = trim($body['celular']   ?? '');
        $clienteDir    = trim($body['direccion'] ?? '');
        $clienteCorreo = trim($body['correo']    ?? '');
        $clienteId     = null;

        // Preferir JWT si viene en el header (nuevo flujo)
        $jwtPayload = app_jwt_from_request();
        if ($jwtPayload && !empty($jwtPayload['cliente_id'])) {
            $clienteId = (int)$jwtPayload['cliente_id'];
            app_touch_last_seen($pdo, $clienteId);
            $pdo->prepare("UPDATE clientes SET nombre = ?, celular = ?, direccion = ?, correo = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$clienteNombre, $clienteTel, $clienteDir, $clienteCorreo ?: null, $clienteId]);
        } else {
            // Buscar por nombre + teléfono (flujo legacy)
            $stmtCli = $pdo->prepare("SELECT id FROM clientes WHERE nombre = ? AND celular = ? LIMIT 1");
            $stmtCli->execute([$clienteNombre, $clienteTel]);
            $existente = $stmtCli->fetch();

            if ($existente) {
                $clienteId = (int)$existente['id'];
                $pdo->prepare("UPDATE clientes SET direccion = ?, correo = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$clienteDir, $clienteCorreo ?: null, $clienteId]);
            } else {
                $pdo->prepare("INSERT INTO clientes (nombre, celular, direccion, correo) VALUES (?, ?, ?, ?)")
                    ->execute([$clienteNombre, $clienteTel, $clienteDir, $clienteCorreo ?: null]);
                $clienteId = (int)$pdo->lastInsertId();
            }
        }

        // Resolver dirección:
        //   1) si llega direccion_id, validar que pertenezca al cliente y tomar sus campos
        //   2) si no, y el cliente no tiene ninguna dirección, crear la primera con los datos del form
        if ($direccionId && $clienteId) {
            $stDir = $pdo->prepare("SELECT direccion, lat, lng FROM clientes_direcciones WHERE id = ? AND cliente_id = ?");
            $stDir->execute([$direccionId, $clienteId]);
            $dirRow = $stDir->fetch();
            if ($dirRow) {
                if (!empty($dirRow['direccion'])) $clienteDir = $dirRow['direccion'];
                // Si la dirección ya tiene coords guardadas, usarlas como base; si no, persistir las que llegaron
                if ($dirRow['lat'] !== null && $dirRow['lng'] !== null && $pedLat === null) {
                    $pedLat = (float)$dirRow['lat'];
                    $pedLng = (float)$dirRow['lng'];
                }
                if ($pedLat !== null && $pedLng !== null
                    && ($dirRow['lat'] === null || $dirRow['lng'] === null)) {
                    $geo = reverseGeocode($pedLat, $pedLng);
                    $pdo->prepare("UPDATE clientes_direcciones
                                   SET lat=?, lng=?, direccion_geo=?, localidad=?, provincia=?, pais=?
                                   WHERE id=?")
                        ->execute([
                            $pedLat, $pedLng,
                            $geo['direccion_geo'], $geo['localidad'], $geo['provincia'], $geo['pais'],
                            $direccionId,
                        ]);
                }
            } else {
                $direccionId = null; // no autorizada
            }
        }
        if ($direccionId === null && $clienteId && ($clienteDir !== '' || ($pedLat !== null && $pedLng !== null))) {
            $stCnt = $pdo->prepare("SELECT id FROM clientes_direcciones WHERE cliente_id = ? LIMIT 1");
            $stCnt->execute([$clienteId]);
            if (!$stCnt->fetch()) {
                $geo = ($pedLat !== null && $pedLng !== null)
                    ? reverseGeocode($pedLat, $pedLng)
                    : ['direccion_geo' => null, 'localidad' => null, 'provincia' => null, 'pais' => null];
                $pdo->prepare("INSERT INTO clientes_direcciones
                               (cliente_id, etiqueta, direccion, lat, lng, direccion_geo, localidad, provincia, pais, es_principal)
                               VALUES (?, 'Casa', ?, ?, ?, ?, ?, ?, ?, 1)")
                    ->execute([
                        $clienteId,
                        $clienteDir ?: null,
                        $pedLat, $pedLng,
                        $geo['direccion_geo'], $geo['localidad'], $geo['provincia'], $geo['pais'],
                    ]);
                $direccionId = (int)$pdo->lastInsertId();
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO pedidos (numero, cliente_id, cliente, correo, celular, direccion, direccion_id, notas, total, estado, lat, lng, distancia_km, tiempo_min)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $numero,
            $clienteId,
            $clienteNombre,
            $clienteCorreo ?: null,
            $clienteTel,
            $clienteDir,
            $direccionId,
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

        $pdo->commit();

        // Marcar carrito como exitoso (fuera de la transacción del pedido)
        try {
            $sessionId = trim($body['session_id'] ?? '');
            if ($sessionId !== '') {
                $stmtFindCart = $pdo->prepare(
                    "SELECT id FROM carritos WHERE usuario_id = ? AND session_id = ? AND estado IN ('activo','abandonado') ORDER BY updated_at DESC LIMIT 1"
                );
                $stmtFindCart->execute([$clienteId, $sessionId]);
            } else {
                $stmtFindCart = $pdo->prepare(
                    "SELECT id FROM carritos WHERE usuario_id = ? AND estado IN ('activo','abandonado') ORDER BY updated_at DESC LIMIT 1"
                );
                $stmtFindCart->execute([$clienteId]);
            }
            $cartRow = $stmtFindCart->fetch();

            if ($cartRow) {
                $pdo->prepare("UPDATE carritos SET estado = 'exitoso' WHERE id = ?")
                    ->execute([$cartRow['id']]);
            } else {
                // Pedido realizado sin carrito previo (guest): crear registro exitoso
                $pdo->prepare("INSERT INTO carritos (usuario_id, session_id, estado, total) VALUES (?, ?, 'exitoso', ?)")
                    ->execute([$clienteId, $sessionId, $total]);
                $newCartId = (int)$pdo->lastInsertId();
                $stmtCI = $pdo->prepare(
                    "INSERT INTO carritos_items (carrito_id, producto_id, nombre, precio, cantidad) VALUES (?, ?, ?, ?, ?)"
                );
                foreach ($body['items'] as $item) {
                    $stmtCI->execute([
                        $newCartId,
                        isset($item['id']) ? (int)$item['id'] : null,
                        $item['nombre'],
                        (float)$item['precio'],
                        (int)($item['cantidad'] ?? 1),
                    ]);
                }
            }
        } catch (Exception $e) { /* silencioso: el pedido ya fue creado */ }

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

        $token = app_jwt_encode([
            'cliente_id' => $clienteId,
            'iat'        => time(),
            'exp'        => time() + APP_JWT_TTL,
        ]);

        echo json_encode(['ok' => true, 'pedido' => $pedido, 'token' => $token]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Error al guardar pedido']);
    }
    exit;
}

// PATCH: cancelar un pedido propio (solo mientras esté en estado "pendiente")
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $jwtPayload = app_jwt_from_request();
    if (!$jwtPayload || empty($jwtPayload['cliente_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'No autenticado']);
        exit;
    }
    $clienteId = (int)$jwtPayload['cliente_id'];

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $pedidoId = (int)($body['id'] ?? 0);
    $accion   = trim($body['accion'] ?? '');

    if (!$pedidoId || $accion !== 'cancelar') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'id y accion=cancelar requeridos']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, estado, cliente_id FROM pedidos WHERE id = ?");
    $stmt->execute([$pedidoId]);
    $ped = $stmt->fetch();

    if (!$ped) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Pedido no encontrado']);
        exit;
    }
    if ((int)$ped['cliente_id'] !== $clienteId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No autorizado']);
        exit;
    }
    if ($ped['estado'] !== 'pendiente') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Solo se puede cancelar un pedido pendiente']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ?")->execute([$pedidoId]);

        // Devolver stock: restituir stock_actual y liberar stock_comprometido
        $items = $pdo->prepare("SELECT producto_id, cantidad FROM pedido_items WHERE pedido_id = ?");
        $items->execute([$pedidoId]);
        $restStmt = $pdo->prepare("
            UPDATE productos
            SET stock_actual       = stock_actual + ?,
                stock_comprometido = GREATEST(0, stock_comprometido - ?)
            WHERE id = ?
        ");
        foreach ($items->fetchAll() as $it) {
            if (!empty($it['producto_id'])) {
                $restStmt->execute([(int)$it['cantidad'], (int)$it['cantidad'], (int)$it['producto_id']]);
            }
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'id' => $pedidoId, 'estado' => 'cancelado']);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Error al cancelar el pedido']);
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
