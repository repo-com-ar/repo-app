<?php
/**
 * POST /api/mp_webhook
 * Recibe notificaciones de pago de Mercado Pago (Checkout Pro).
 * Actualiza pagos.estado y pedidos.estado_pago según el resultado.
 */
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');

require_once __DIR__ . '/../../repo-api/config/db.php';

define('MP_ACCESS_TOKEN', 'APP_USR-7819990176589606-042500-ebb06adb445d3580e7a64e72ada9ea3f-831596820');

// ── Extraer payment_id ──────────────────────────────────────────────────────
$paymentId = null;
$rawBody   = file_get_contents('php://input');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rawBody) {
    $body = json_decode($rawBody, true) ?? [];
    if (($body['type'] ?? '') === 'payment') {
        $paymentId = $body['data']['id'] ?? null;
    }
}

// Soporte IPN legacy (topic=payment&id=xxx via GET)
if (!$paymentId && ($_GET['topic'] ?? '') === 'payment') {
    $paymentId = $_GET['id'] ?? null;
}

// Notificaciones que no son de pago — responder 200 y salir
if (!$paymentId) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'msg' => 'ignorado']);
    exit;
}

// ── Consultar el pago a la API de MP ────────────────────────────────────────
$ch = curl_init('https://api.mercadopago.com/v1/payments/' . intval($paymentId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . MP_ACCESS_TOKEN],
    CURLOPT_TIMEOUT        => 15,
]);
$mpResponse = curl_exec($ch);
$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(200); // Siempre 200 para que MP no reintente
    echo json_encode(['ok' => false, 'error' => 'No se pudo consultar el pago a MP']);
    exit;
}

$pago              = json_decode($mpResponse, true);
$mpStatus          = $pago['status']             ?? '';
$externalReference = $pago['external_reference'] ?? '';
$montoMp           = (float) ($pago['transaction_amount'] ?? 0);

// ── Mapeo de estados ────────────────────────────────────────────────────────
// pagos.estado:       pendiente | aprobado | rechazado | reembolsado
// pedidos.estado_pago: pendiente | pagado  | parcial   | reembolsado
$mapPagos = [
    'approved'     => 'aprobado',
    'refunded'     => 'reembolsado',
    'charged_back' => 'reembolsado',
    'rejected'     => 'rechazado',
    'cancelled'    => 'rechazado',
    'pending'      => 'pendiente',
    'in_process'   => 'pendiente',
    'authorized'   => 'pendiente',
];
$mapPedido = [
    'approved'     => 'pagado',
    'refunded'     => 'reembolsado',
    'charged_back' => 'reembolsado',
    'rejected'     => 'pendiente',
    'cancelled'    => 'pendiente',
    'pending'      => 'pendiente',
    'in_process'   => 'pendiente',
    'authorized'   => 'pendiente',
];

$estadoPago   = $mapPagos[$mpStatus]  ?? 'pendiente';
$estadoPedido = $mapPedido[$mpStatus] ?? 'pendiente';

try {
    $pdo = getDB();

    // Buscar pedido por numero (external_reference)
    $stmt = $pdo->prepare("SELECT id FROM pedidos WHERE numero = ?");
    $stmt->execute([$externalReference]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        http_response_code(200);
        echo json_encode(['ok' => false, 'error' => 'Pedido no encontrado: ' . $externalReference]);
        exit;
    }

    $pedidoId = $pedido['id'];

    // Actualizar fila en pagos (la más reciente para este pedido con metodo mercadopago)
    $stmtUpdate = $pdo->prepare(
        "UPDATE pagos
         SET estado = ?, mp_payment_id = ?, mp_status = ?, monto = ?
         WHERE pedido_id = ? AND metodo = 'mercadopago'
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmtUpdate->execute([$estadoPago, $paymentId, $mpStatus, $montoMp, $pedidoId]);

    // Si no había fila previa, insertar una nueva
    if ($stmtUpdate->rowCount() === 0) {
        $stmtInsert = $pdo->prepare(
            "INSERT INTO pagos (pedido_id, metodo, monto, estado, mp_payment_id, mp_status)
             VALUES (?, 'mercadopago', ?, ?, ?, ?)"
        );
        $stmtInsert->execute([$pedidoId, $montoMp, $estadoPago, $paymentId, $mpStatus]);
    }

    // Actualizar estado_pago del pedido
    $stmtPed = $pdo->prepare("UPDATE pedidos SET estado_pago = ? WHERE id = ?");
    $stmtPed->execute([$estadoPedido, $pedidoId]);

    http_response_code(200);
    echo json_encode(['ok' => true, 'estado' => $estadoPago]);

} catch (Exception $e) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
