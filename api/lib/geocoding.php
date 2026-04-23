<?php
/**
 * Reverse geocoding con Google Maps Geocoding API.
 *
 * Dado lat/lng, devuelve los componentes de la dirección en castellano.
 * Usa la misma API key que Distance Matrix. Si la API falla, devuelve null
 * en cada campo (nunca lanza).
 */

define('APP_GEOCODING_KEY', 'AIzaSyDXN7-CpoFdxh_6V-_7UQkPzWFbX6_T1p0');

function reverseGeocode(float $lat, float $lng): array {
    $empty = ['direccion_geo' => null, 'localidad' => null, 'provincia' => null, 'pais' => null];

    $url = 'https://maps.googleapis.com/maps/api/geocode/json'
         . '?latlng=' . urlencode($lat . ',' . $lng)
         . '&language=es'
         . '&key=' . APP_GEOCODING_KEY;

    $resp = @file_get_contents($url);
    if (!$resp) return $empty;

    $data = json_decode($resp, true);
    if (empty($data['results'][0])) return $empty;

    $result = $data['results'][0];
    $out = $empty;
    $out['direccion_geo'] = $result['formatted_address'] ?? null;

    foreach ($result['address_components'] as $c) {
        $types = $c['types'] ?? [];
        if (in_array('locality', $types, true)) {
            $out['localidad'] = $c['long_name'];
        } elseif ($out['localidad'] === null && in_array('sublocality', $types, true)) {
            $out['localidad'] = $c['long_name'];
        } elseif ($out['localidad'] === null && in_array('administrative_area_level_2', $types, true)) {
            $out['localidad'] = $c['long_name'];
        }
        if (in_array('administrative_area_level_1', $types, true)) {
            $out['provincia'] = $c['long_name'];
        }
        if (in_array('country', $types, true)) {
            $out['pais'] = $c['long_name'];
        }
    }
    return $out;
}

/**
 * Crea la tabla clientes_direcciones si no existe y, si está vacía,
 * migra las direcciones actuales que hay en clientes (direccion + lat/lng + geo)
 * como la dirección "Casa" principal de cada cliente.
 */
function ensure_direcciones_table(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS clientes_direcciones (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cliente_id    INT UNSIGNED NOT NULL,
                etiqueta      VARCHAR(50)  NOT NULL DEFAULT 'Casa',
                direccion     VARCHAR(255) NULL,
                lat           DECIMAL(10,7) NULL,
                lng           DECIMAL(10,7) NULL,
                direccion_geo VARCHAR(255) NULL,
                localidad     VARCHAR(100) NULL,
                provincia     VARCHAR(100) NULL,
                pais          VARCHAR(100) NULL,
                es_principal  TINYINT(1)   NOT NULL DEFAULT 0,
                created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cliente_id (cliente_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) { return; }

    // Migración idempotente: solo si la tabla está vacía
    try {
        $empty = (int)$pdo->query("SELECT COUNT(*) FROM clientes_direcciones")->fetchColumn() === 0;
    } catch (Throwable $e) { return; }

    if (!$empty) return;

    // Intento con todas las columnas geocodificadas
    try {
        $pdo->exec("
            INSERT INTO clientes_direcciones
                (cliente_id, etiqueta, direccion, lat, lng, direccion_geo, localidad, provincia, pais, es_principal)
            SELECT id, 'Casa', direccion, lat, lng, direccion_geo, localidad, provincia, pais, 1
            FROM clientes
            WHERE (direccion IS NOT NULL AND direccion <> '')
               OR (lat IS NOT NULL AND lng IS NOT NULL)
        ");
        return;
    } catch (Throwable $e) { /* fallback abajo */ }

    // Fallback si las columnas geocodificadas aún no existen en clientes
    try {
        $pdo->exec("
            INSERT INTO clientes_direcciones
                (cliente_id, etiqueta, direccion, lat, lng, es_principal)
            SELECT id, 'Casa', direccion, lat, lng, 1
            FROM clientes
            WHERE (direccion IS NOT NULL AND direccion <> '')
               OR (lat IS NOT NULL AND lng IS NOT NULL)
        ");
    } catch (Throwable $e) { /* silencioso */ }
}

/**
 * Guarda lat/lng + los 4 campos geocodificados en la ficha del cliente.
 *
 * Idempotente: si la ubicación no cambió y ya existen los campos geocodificados,
 * no hace nada. Si cambió o faltan datos, llama a Google y actualiza.
 *
 * Agrega las columnas localidad/provincia/pais/direccion_geo si no existen
 * (migración perezosa). Cualquier error al geocodificar se silencia para no
 * romper el flujo del pedido/perfil.
 */
function guardarUbicacionCliente(PDO $pdo, int $clienteId, ?float $lat, ?float $lng): void {
    if ($clienteId <= 0 || $lat === null || $lng === null) return;

    // Migración perezosa
    try { $pdo->query("SELECT localidad FROM clientes LIMIT 1"); } catch (Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE clientes
                ADD COLUMN direccion_geo VARCHAR(255) NULL DEFAULT NULL,
                ADD COLUMN localidad     VARCHAR(100) NULL DEFAULT NULL,
                ADD COLUMN provincia     VARCHAR(100) NULL DEFAULT NULL,
                ADD COLUMN pais          VARCHAR(100) NULL DEFAULT NULL");
        } catch (Throwable $e2) { /* silencioso: tal vez las columnas ya existen parcialmente */ }
    }

    // Ver si ya está geocodificado para estas coords
    try {
        $stmt = $pdo->prepare("SELECT lat, lng, localidad FROM clientes WHERE id = ?");
        $stmt->execute([$clienteId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) { return; }

    if (!$row) return;

    $samePlace = $row['lat'] !== null
              && abs((float)$row['lat'] - $lat) < 0.0001
              && abs((float)$row['lng'] - $lng) < 0.0001;

    if ($samePlace && !empty($row['localidad'])) {
        // Ya geocodificado para esta ubicación, nada que hacer
        return;
    }

    $geo = reverseGeocode($lat, $lng);

    try {
        $pdo->prepare("UPDATE clientes
                       SET lat = ?, lng = ?, direccion_geo = ?, localidad = ?, provincia = ?, pais = ?
                       WHERE id = ?")
            ->execute([
                $lat, $lng,
                $geo['direccion_geo'],
                $geo['localidad'],
                $geo['provincia'],
                $geo['pais'],
                $clienteId,
            ]);
    } catch (Throwable $e) { /* silencioso */ }
}
