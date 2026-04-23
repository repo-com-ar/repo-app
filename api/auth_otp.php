<?php
/**
 * OTP auth — login / registro por correo electrónico.
 *
 * POST { accion: "enviar",    correo }           → genera y envía OTP
 * POST { accion: "verificar", correo, codigo }   → valida OTP, devuelve JWT
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$configPath = __DIR__ . '/../../repo-api/config/db.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config no encontrado']);
    exit;
}
require_once $configPath;
require_once __DIR__ . '/lib/jwt.php';

try {
    $pdo = getDB();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB: ' . $e->getMessage()]);
    exit;
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS otp_codigos (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        correo     VARCHAR(150) NOT NULL,
        codigo     CHAR(6)      NOT NULL,
        expires_at DATETIME     NOT NULL,
        usado      TINYINT(1)   NOT NULL DEFAULT 0,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_correo (correo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$accion = trim($body['accion'] ?? '');

/* ── Checkout: verificar correo (nuevo flujo) ───────────────────────── */
if ($accion === 'checkout_email') {
    $correo = strtolower(trim($body['correo'] ?? ''));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Correo inválido']);
        exit;
    }

    // ¿El correo ya existe en clientes?
    $stmtCli = $pdo->prepare("SELECT id FROM clientes WHERE correo = ? LIMIT 1");
    $stmtCli->execute([$correo]);
    $clienteExistente = $stmtCli->fetch();

    if ($clienteExistente) {
        // Correo existente → enviar OTP
        $stmtRate = $pdo->prepare("
            SELECT COUNT(*) FROM otp_codigos
            WHERE correo = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        $stmtRate->execute([$correo]);
        if ((int)$stmtRate->fetchColumn() >= 3) {
            echo json_encode(['ok' => false, 'error' => 'Demasiados intentos. Esperá unos minutos.']);
            exit;
        }

        $pdo->prepare("UPDATE otp_codigos SET usado = 1 WHERE correo = ? AND usado = 0")->execute([$correo]);

        $codigo  = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $pdo->prepare("INSERT INTO otp_codigos (correo, codigo, expires_at) VALUES (?, ?, ?)")
            ->execute([$correo, $codigo, $expires]);

        $emailPayload = [
            'proyecto'  => 'repo',
            'canal'     => 'databox',
            'plantilla' => 'repo',
            'destino'   => $correo,
            'asunto'    => 'Tu código de acceso',
            'cuerpo'    => 'Tu código de verificación es: <strong>' . $codigo . '</strong><br>Válido por 15 minutos.',
            'variables' => json_encode(['codigo' => $codigo]),
        ];
        $ch = curl_init('https://api.databox.net.ar/v3/awsses/mensajes/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($emailPayload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer z9SACoW1SiHGiyan6JVMwudC73r7Y0An',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $apiResp  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode >= 400) {
            echo json_encode(['ok' => false, 'error' => 'No se pudo enviar el correo. Intentá de nuevo.']);
            exit;
        }

        echo json_encode(['ok' => true, 'existe' => true]);
        exit;
    } else {
        // Correo nuevo → crear cuenta mínima y generar JWT
        $pdo->prepare("INSERT INTO clientes (nombre, correo) VALUES ('', ?)")->execute([$correo]);
        $clienteId = (int)$pdo->lastInsertId();

        $token = app_jwt_encode([
            'cliente_id' => $clienteId,
            'iat'        => time(),
            'exp'        => time() + APP_JWT_TTL,
        ]);

        echo json_encode(['ok' => true, 'existe' => false, 'token' => $token]);
        exit;
    }
}

/* ── Enviar OTP ─────────────────────────────────────────────────────── */
if ($accion === 'enviar') {
    $correo = strtolower(trim($body['correo'] ?? ''));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Correo inválido']);
        exit;
    }

    // Rate limit: máximo 3 intentos en 10 minutos
    $stmtRate = $pdo->prepare("
        SELECT COUNT(*) FROM otp_codigos
        WHERE correo = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
    $stmtRate->execute([$correo]);
    if ((int)$stmtRate->fetchColumn() >= 3) {
        echo json_encode(['ok' => false, 'error' => 'Demasiados intentos. Esperá unos minutos.']);
        exit;
    }

    // Invalidar OTPs anteriores del mismo correo
    $pdo->prepare("UPDATE otp_codigos SET usado = 1 WHERE correo = ? AND usado = 0")->execute([$correo]);

    // Generar código de 6 dígitos
    $codigo  = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $pdo->prepare("INSERT INTO otp_codigos (correo, codigo, expires_at) VALUES (?, ?, ?)")
        ->execute([$correo, $codigo, $expires]);

    // Enviar por email via AWS SES
    $emailPayload = [
        'proyecto'    => 'repo',
        'canal'       => 'databox',
        'plantilla'   => 'repo',
        'destino'     => $correo,
        'asunto'      => 'Tu código de acceso',
        'cuerpo'      => 'Tu código de verificación es: <strong>' . $codigo . '</strong><br>Válido por 15 minutos.',
        'variables'   => json_encode(['codigo' => $codigo]),
    ];

    $ch = curl_init('https://api.databox.net.ar/v3/awsses/mensajes/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($emailPayload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer z9SACoW1SiHGiyan6JVMwudC73r7Y0An',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $apiResp  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $httpCode >= 400) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo enviar el correo. Intentá de nuevo.']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

/* ── Verificar OTP ──────────────────────────────────────────────────── */
if ($accion === 'verificar') {
    $correo = strtolower(trim($body['correo'] ?? ''));
    $codigo = trim($body['codigo'] ?? '');
    $nombre = trim($body['nombre'] ?? '');

    if (!$correo || strlen($codigo) !== 6) {
        echo json_encode(['ok' => false, 'error' => 'Datos incompletos']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id FROM otp_codigos
        WHERE correo = ? AND codigo = ? AND usado = 0 AND expires_at > NOW()
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$correo, $codigo]);
    $otp = $stmt->fetch();

    if (!$otp) {
        echo json_encode(['ok' => false, 'error' => 'Código inválido o expirado']);
        exit;
    }

    $pdo->prepare("UPDATE otp_codigos SET usado = 1 WHERE id = ?")->execute([$otp['id']]);

    // Buscar cliente existente por correo
    $stmtCli = $pdo->prepare("SELECT id FROM clientes WHERE correo = ? LIMIT 1");
    $stmtCli->execute([$correo]);
    $cliente = $stmtCli->fetch();
    $esNuevo = false;

    if ($cliente) {
        $clienteId = (int)$cliente['id'];
    } else {
        $nombreFinal = $nombre ?: explode('@', $correo)[0];
        $pdo->prepare("INSERT INTO clientes (nombre, correo) VALUES (?, ?)")
            ->execute([$nombreFinal, $correo]);
        $clienteId = (int)$pdo->lastInsertId();
        $esNuevo   = true;
    }

    $token = app_jwt_encode([
        'cliente_id' => $clienteId,
        'iat'        => time(),
        'exp'        => time() + APP_JWT_TTL,
    ]);

    echo json_encode(['ok' => true, 'token' => $token, 'nuevo' => $esNuevo]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Acción inválida']);
