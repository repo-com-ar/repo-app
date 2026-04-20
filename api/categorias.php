<?php
/**
 * API pública — Categorías
 *
 * GET /lider-app/api/categorias.php
 * Devuelve las categorías activas ordenadas por `orden ASC`.
 * Usada por el frontend de la app para armar el selector de categorías.
 *
 * Respuesta:
 *   { ok: true, data: [ { id, label, emoji, imagen } ] }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config/db.php';

try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT id, label, emoji, imagen FROM categorias WHERE activa = 1 ORDER BY orden ASC");
    $categorias = $stmt->fetchAll();

    echo json_encode(['ok' => true, 'data' => $categorias]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al consultar categorías']);
}
