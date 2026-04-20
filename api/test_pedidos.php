<?php
/**
 * Script de diagnóstico — Conexión y tablas de pedidos
 *
 * GET /lider-app/api/test_pedidos.php
 * Verifica paso a paso: PHP, existencia del config, conexión a la base de datos
 * y presencia de las tablas `pedidos` y `pedido_items`.
 * Solo para uso en desarrollo/depuración. No exponer en producción.
 */
header('Content-Type: application/json');
echo json_encode(['step' => 1, 'msg' => 'PHP funciona']);

// Test 2: config exists?
$configPath = __DIR__ . '/../../config/db.php';
echo "\n" . json_encode(['step' => 2, 'config_exists' => file_exists($configPath), 'dir' => __DIR__, 'config_path' => $configPath]);

// Test 3: include config
require_once $configPath;
echo "\n" . json_encode(['step' => 3, 'msg' => 'Config cargado OK']);

// Test 4: connect
$pdo = getDB();
echo "\n" . json_encode(['step' => 4, 'msg' => 'Conexión DB OK']);

// Test 5: table exists?
$tables = $pdo->query("SHOW TABLES LIKE 'pedidos'")->fetchAll();
echo "\n" . json_encode(['step' => 5, 'tabla_pedidos' => count($tables) > 0]);

$tables2 = $pdo->query("SHOW TABLES LIKE 'pedido_items'")->fetchAll();
echo "\n" . json_encode(['step' => 6, 'tabla_pedido_items' => count($tables2) > 0]);
