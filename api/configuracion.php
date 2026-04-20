<?php
/**
 * API pública — Configuración
 *
 * GET /repo-app/api/configuracion.php
 * Devuelve los parámetros de configuración del sistema (clave → valor).
 * Usada por el frontend para conocer el pedido mínimo antes de permitir el checkout.
 * Si la tabla no existe o hay error, retorna defaults seguros (pedido_minimo = 0).
 *
 * Respuesta:
 *   { ok: true, data: { pedido_minimo: "0", ... } }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../repo-api/config/db.php';

try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT clave, valor FROM configuracion");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $config = [];
    foreach ($rows as $row) {
        $config[$row['clave']] = $row['valor'];
    }
    echo json_encode(['ok' => true, 'data' => $config]);
} catch (Exception $e) {
    echo json_encode(['ok' => true, 'data' => ['pedido_minimo' => '0']]);
}
