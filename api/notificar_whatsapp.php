<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$body = json_decode(file_get_contents('php://input'), true);
if (empty($body)) {
    echo json_encode(['ok' => false, 'error' => 'Sin datos']);
    exit;
}

$ch = curl_init('https://api.databox.net.ar/v3/datarocket/mensajes/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer z9SACoW1SiHGiyan6JVMwudC73r7Y0An',
    ],
    CURLOPT_TIMEOUT        => 15,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['ok' => false, 'error' => $curlError]);
    exit;
}

// Registrar el mensaje en la tabla de mensajes del admin
$estado = ($httpCode >= 200 && $httpCode < 300) ? 'enviado' : 'error';
try {
    require_once __DIR__ . '/../../config/db.php';
    $pdo = getDB();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mensajes (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            canal        ENUM('email','whatsapp') NOT NULL,
            destinatario VARCHAR(255) NOT NULL,
            destino      VARCHAR(255) NOT NULL DEFAULT '',
            asunto       VARCHAR(500) NOT NULL DEFAULT '',
            mensaje      TEXT        NOT NULL,
            estado       VARCHAR(50)  NOT NULL DEFAULT 'enviado',
            created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $stmt = $pdo->prepare("
        INSERT INTO mensajes (canal, destinatario, destino, asunto, mensaje, estado)
        VALUES ('whatsapp', ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $body['destinatario'] ?? '',
        $body['destino']      ?? '',
        $body['asunto']       ?? '',
        $body['cuerpo']       ?? '',
        $estado,
    ]);
} catch (Exception $e) {
    // No interrumpir la respuesta si falla el registro
}

http_response_code($httpCode);
echo $response;
