<?php
/**
 * POST /api/mp_preference
 * Crea una preferencia de Checkout Pro en Mercado Pago.
 * Se ejecuta en el mismo origen que la app (sin CORS).
 * Body JSON: { pedido_id: int }
 * Devuelve: { ok: true, init_point: "https://..." }
 */
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

require_once __DIR__ . '/../../repo-api/config/db.php';

define('MP_ACCESS_TOKEN', 'APP_USR-7819990176589606-042500-ebb06adb445d3580e7a64e72ada9ea3f-831596820');
define('APP_URL',         'https://app.repo.com.ar');
define('WEBHOOK_URL',     'https://app.repo.com.ar/api/mp_webhook');

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$pedidoId = intval($body['pedido_id'] ?? 0);

if (!$pedidoId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'pedido_id requerido']);
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id, numero, cliente, correo, total FROM pedidos WHERE id = ?");
    $stmt->execute([$pedidoId]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Pedido no encontrado']);
        exit;
    }

    $stmtItems = $pdo->prepare("SELECT nombre, precio, cantidad FROM pedido_items WHERE pedido_id = ?");
    $stmtItems->execute([$pedidoId]);
    $items = $stmtItems->fetchAll();

    if ($items) {
        $mpItems = [];
        foreach ($items as $i) {
            $mpItems[] = [
                'title'       => $i['nombre'],
                'quantity'    => (int) $i['cantidad'],
                'unit_price'  => (float) $i['precio'],
                'currency_id' => 'ARS',
            ];
        }
    } else {
        $mpItems = [[
            'title'       => 'Pedido ' . $pedido['numero'],
            'quantity'    => 1,
            'unit_price'  => (float) $pedido['total'],
            'currency_id' => 'ARS',
        ]];
    }

    $preference = [
        'items'              => $mpItems,
        'payer'              => [
            'name'  => $pedido['cliente'],
            'email' => $pedido['correo'] ?: 'cliente@repo.com.ar',
        ],
        'back_urls'          => [
            'success' => APP_URL . '/?pago=ok',
            'failure' => APP_URL . '/?pago=error',
            'pending' => APP_URL . '/?pago=pendiente',
        ],
        'auto_return'        => 'approved',
        'notification_url'   => WEBHOOK_URL,
        'external_reference' => $pedido['numero'],
        'statement_descriptor' => 'REPO',
    ];

    $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . MP_ACCESS_TOKEN,
        ],
        CURLOPT_POSTFIELDS => json_encode($preference),
        CURLOPT_TIMEOUT    => 15,
    ]);
    $mpResponse = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $mpData = json_decode($mpResponse, true);

    if ($httpCode !== 201 || empty($mpData['init_point'])) {
        http_response_code(502);
        echo json_encode([
            'ok'         => false,
            'error'      => 'Error al crear preferencia en Mercado Pago',
            'mp_status'  => $httpCode,
            'mp_message' => $mpData['message'] ?? null,
            'mp_error'   => $mpData['error']   ?? null,
            'mp_cause'   => $mpData['cause']   ?? null,
            'mp_raw'     => $mpData,
        ]);
        exit;
    }

    // Registrar el pago pendiente en la tabla pagos
    $stmtPago = $pdo->prepare(
        "INSERT INTO pagos (pedido_id, metodo, monto, estado, mp_preference_id)
         VALUES (?, 'mercadopago', ?, 'pendiente', ?)"
    );
    $stmtPago->execute([$pedidoId, $pedido['total'], $mpData['id']]);

    echo json_encode(['ok' => true, 'init_point' => $mpData['init_point']]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
