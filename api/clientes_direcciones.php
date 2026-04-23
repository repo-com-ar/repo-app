<?php
/**
 * API — Direcciones del cliente (multi-domicilio).
 *
 * Autenticado vía Bearer JWT. El cliente_id se toma del token.
 *
 *   GET    /repo-app/api/clientes_direcciones
 *     → { ok, data: [ { id, etiqueta, direccion, lat, lng, direccion_geo, localidad, provincia, pais, es_principal }, ... ] }
 *
 *   POST   /repo-app/api/clientes_direcciones
 *     Body: { etiqueta?, direccion?, lat?, lng?, es_principal? }
 *     → { ok, id }
 *     (si lat/lng vienen, hace reverse-geocoding automático)
 *
 *   PATCH  /repo-app/api/clientes_direcciones
 *     Body: { id, ...campos }
 *     → { ok }
 *
 *   DELETE /repo-app/api/clientes_direcciones?id={id}
 *     → { ok }
 *
 * Reglas:
 *   - La primera dirección creada queda como principal.
 *   - Solo puede haber una principal por cliente (el backend desmarca las demás).
 *   - Al borrar la principal, se promueve la siguiente (más antigua).
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
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

$payload = app_jwt_from_request();
if (!$payload || empty($payload['cliente_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}
$clienteId = (int)$payload['cliente_id'];
app_touch_last_seen($pdo, $clienteId);

ensure_direcciones_table($pdo);

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        $stmt = $pdo->prepare("
            SELECT id, etiqueta, direccion, lat, lng,
                   direccion_geo, localidad, provincia, pais, es_principal
            FROM clientes_direcciones
            WHERE cliente_id = ?
            ORDER BY es_principal DESC, id ASC
        ");
        $stmt->execute([$clienteId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['id']           = (int)$r['id'];
            $r['lat']          = $r['lat'] !== null ? (float)$r['lat'] : null;
            $r['lng']          = $r['lng'] !== null ? (float)$r['lng'] : null;
            $r['es_principal'] = (int)$r['es_principal'] === 1;
        }
        echo json_encode(['ok' => true, 'data' => $rows]);
        break;

    case 'POST': {
        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $etiqueta  = trim($body['etiqueta']  ?? '') ?: 'Casa';
        $direccion = trim($body['direccion'] ?? '');
        $lat = array_key_exists('lat', $body) && $body['lat'] !== null && $body['lat'] !== '' ? (float)$body['lat'] : null;
        $lng = array_key_exists('lng', $body) && $body['lng'] !== null && $body['lng'] !== '' ? (float)$body['lng'] : null;

        // Si es la primera dirección del cliente, siempre es principal
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM clientes_direcciones WHERE cliente_id = ?");
        $cnt->execute([$clienteId]);
        $esPrimera = (int)$cnt->fetchColumn() === 0;
        $esPrincipal = $esPrimera || !empty($body['es_principal']);

        if ($esPrincipal) {
            $pdo->prepare("UPDATE clientes_direcciones SET es_principal = 0 WHERE cliente_id = ?")
                ->execute([$clienteId]);
        }

        $geo = ['direccion_geo' => null, 'localidad' => null, 'provincia' => null, 'pais' => null];
        if ($lat !== null && $lng !== null) {
            $geo = reverseGeocode($lat, $lng);
        }

        $stmt = $pdo->prepare("
            INSERT INTO clientes_direcciones
                (cliente_id, etiqueta, direccion, lat, lng, direccion_geo, localidad, provincia, pais, es_principal)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $clienteId,
            $etiqueta,
            $direccion ?: null,
            $lat, $lng,
            $geo['direccion_geo'], $geo['localidad'], $geo['provincia'], $geo['pais'],
            $esPrincipal ? 1 : 0,
        ]);

        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;
    }

    case 'PATCH': {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'id requerido']);
            exit;
        }

        $chk = $pdo->prepare("SELECT cliente_id FROM clientes_direcciones WHERE id = ?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if (!$row || (int)$row['cliente_id'] !== $clienteId) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No autorizado']);
            exit;
        }

        $campos = [];
        $params = [];

        if (array_key_exists('etiqueta', $body)) {
            $campos[] = 'etiqueta = ?';
            $params[] = trim($body['etiqueta']) ?: 'Casa';
        }
        if (array_key_exists('direccion', $body)) {
            $campos[] = 'direccion = ?';
            $params[] = trim($body['direccion']) ?: null;
        }

        $cambiaLat = array_key_exists('lat', $body);
        $cambiaLng = array_key_exists('lng', $body);
        if ($cambiaLat || $cambiaLng) {
            $lat = ($cambiaLat && $body['lat'] !== null && $body['lat'] !== '') ? (float)$body['lat'] : null;
            $lng = ($cambiaLng && $body['lng'] !== null && $body['lng'] !== '') ? (float)$body['lng'] : null;
            $campos[] = 'lat = ?'; $params[] = $lat;
            $campos[] = 'lng = ?'; $params[] = $lng;
            if ($lat !== null && $lng !== null) {
                $geo = reverseGeocode($lat, $lng);
                $campos[] = 'direccion_geo = ?'; $params[] = $geo['direccion_geo'];
                $campos[] = 'localidad = ?';     $params[] = $geo['localidad'];
                $campos[] = 'provincia = ?';     $params[] = $geo['provincia'];
                $campos[] = 'pais = ?';          $params[] = $geo['pais'];
            } else {
                $campos[] = 'direccion_geo = ?'; $params[] = null;
                $campos[] = 'localidad = ?';     $params[] = null;
                $campos[] = 'provincia = ?';     $params[] = null;
                $campos[] = 'pais = ?';          $params[] = null;
            }
        }

        if (!empty($body['es_principal'])) {
            $pdo->prepare("UPDATE clientes_direcciones SET es_principal = 0 WHERE cliente_id = ?")
                ->execute([$clienteId]);
            $campos[] = 'es_principal = ?';
            $params[] = 1;
        }

        if (!$campos) {
            echo json_encode(['ok' => true]);
            exit;
        }

        $params[] = $id;
        $pdo->prepare("UPDATE clientes_direcciones SET " . implode(', ', $campos) . " WHERE id = ?")
            ->execute($params);

        echo json_encode(['ok' => true]);
        break;
    }

    case 'DELETE': {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'id requerido']);
            exit;
        }

        $chk = $pdo->prepare("SELECT cliente_id, es_principal FROM clientes_direcciones WHERE id = ?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if (!$row || (int)$row['cliente_id'] !== $clienteId) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No autorizado']);
            exit;
        }

        $pdo->prepare("DELETE FROM clientes_direcciones WHERE id = ?")->execute([$id]);

        // Si borré la principal, promuevo la más antigua restante
        if ((int)$row['es_principal'] === 1) {
            $next = $pdo->prepare("SELECT id FROM clientes_direcciones WHERE cliente_id = ? ORDER BY id ASC LIMIT 1");
            $next->execute([$clienteId]);
            $nextId = $next->fetchColumn();
            if ($nextId) {
                $pdo->prepare("UPDATE clientes_direcciones SET es_principal = 1 WHERE id = ?")->execute([(int)$nextId]);
            }
        }

        echo json_encode(['ok' => true]);
        break;
    }

    default:
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
}
