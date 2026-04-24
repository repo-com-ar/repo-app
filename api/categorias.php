<?php
/**
 * API pública — Categorías
 *
 * GET /repo-app/api/categorias.php
 * Devuelve las categorías activas ordenadas por `orden ASC`.
 * Usada por el frontend de la app para armar el selector de categorías.
 *
 * Respuesta:
 *   { ok: true, data: [ { id, label, emoji, imagen } ] }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../repo-api/config/db.php';

try {
    $pdo = getDB();
    // Solo raíces (parent_id IS NULL). Las subcategorías son un detalle interno;
    // el filtrado por raíz en repo-app incluye productos de sus subcategorías.
    $stmt = $pdo->query("
        SELECT id, label, emoji, imagen
        FROM categorias
        WHERE activa = 1 AND parent_id IS NULL
        ORDER BY orden ASC
    ");
    $categorias = $stmt->fetchAll();

    echo json_encode(['ok' => true, 'data' => $categorias]);
} catch (Exception $e) {
    // Fallback si aún no existe la columna parent_id (instalación vieja)
    try {
        $stmt = $pdo->query("SELECT id, label, emoji, imagen FROM categorias WHERE activa = 1 ORDER BY orden ASC");
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e2) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Error al consultar categorías']);
    }
}
