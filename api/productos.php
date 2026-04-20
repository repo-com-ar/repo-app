<?php
/**
 * API pública — Productos
 *
 * GET /repo-app/api/productos.php[?categoria={id}&q={texto}]
 * Devuelve los productos disponibles con filtros opcionales de categoría y búsqueda.
 * Usada por el frontend para renderizar el catálogo y el buscador.
 *
 * Parámetros GET:
 *   categoria (string) — slug de categoría; 'todos' para todas (default)
 *   q         (string) — texto libre para buscar por nombre
 *
 * Respuesta:
 *   { ok: true, data: [ { id, nombre, precio, categoria, emoji, imagen, unidad, stock, stock_actual, stock_minimo, stock_recomendado } ] }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../repo-api/config/db.php';

$cat = $_GET['categoria'] ?? 'todos';
$q   = trim($_GET['q'] ?? '');

try {
    $pdo = getDB();

    $sql    = "SELECT id, nombre, precio, categoria, emoji, imagen, unidad, stock_actual, stock_minimo, stock_recomendado FROM productos WHERE stock_actual > 0";
    $params = [];

    if ($cat !== 'todos') {
        $sql .= " AND categoria = ?";
        $params[] = $cat;
    }
    if ($q !== '') {
        $sql .= " AND nombre LIKE ?";
        $params[] = "%{$q}%";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll();

    foreach ($productos as &$p) {
        $p['stock']  = (int)$p['stock_actual'] > 0;
        $p['precio'] = (float)$p['precio'];
        $p['stock_actual'] = (int)$p['stock_actual'];
        $p['stock_minimo'] = (int)$p['stock_minimo'];
        $p['stock_recomendado'] = (int)$p['stock_recomendado'];
        if (!empty($p['imagen']) && strpos($p['imagen'], 'http') !== 0) {
            $p['imagen'] = 'https://media.repo.com.ar/productos/' . basename($p['imagen']);
        }
    }

    echo json_encode(['ok' => true, 'data' => $productos]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al consultar productos']);
}
