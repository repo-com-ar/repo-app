<?php
define('APP_JWT_SECRET', 'repo-app-cl13nt3-s3cr3t-2026-zM7vQ4rL');
define('APP_JWT_TTL',    60 * 60 * 24 * 365); // 1 año

function app_jwt_encode(array $payload): string {
    $header = _app_b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body   = _app_b64u(json_encode($payload));
    $sig    = _app_b64u(hash_hmac('sha256', "$header.$body", APP_JWT_SECRET, true));
    return "$header.$body.$sig";
}

function app_jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = _app_b64u(hash_hmac('sha256', "$header.$body", APP_JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode(_app_b64d($body), true);
    if (!$data) return null;
    if (isset($data['exp']) && $data['exp'] < time()) return null;
    return $data;
}

function app_jwt_from_request(): ?array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return app_jwt_decode($m[1]);
    }
    return null;
}

function _app_b64u(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function _app_b64d(string $s): string {
    return base64_decode(strtr($s, '-_', '+/'));
}
