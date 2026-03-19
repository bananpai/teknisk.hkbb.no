<?php
// public/lager/inc/bootstrap.php

declare(strict_types=1);

// For API-endepunkt: unngå HTML i JSON-respons ved warnings/notices
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Isoler cookie til /lager slik at den ikke kolliderer med hovedsystemet
    session_name('lager_session');

    // Må settes før session_start
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/lager',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// Forsøk å laste autoloader (tilpasser seg ulike katalognivå)
$autoloadCandidates = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];
foreach ($autoloadCandidates as $p) {
    if (file_exists($p)) {
        require_once $p;
        break;
    }
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function json_out(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_pdo(): PDO {
    if (class_exists(\App\Database::class)) {
        return \App\Database::getConnection();
    }
    throw new RuntimeException('Fant ikke App\\Database. Sørg for at autoload/Bootstrap er tilgjengelig for /lager.');
}

function lager_user(): ?array {
    return $_SESSION['lager_user'] ?? null;
}

function require_lager_login(): array {
    $u = lager_user();
    if (!$u) {
        header('Location: /lager/login');
        exit;
    }
    return $u;
}

function require_lager_admin(): array {
    $u = require_lager_login();
    if (empty($u['is_admin'])) {
        http_response_code(403);
        echo '<div style="font-family:system-ui;margin:20px">Du har ikke tilgang.</div>';
        exit;
    }
    return $u;
}

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

/**
 * TOTP (Google Authenticator) – uten eksterne biblioteker.
 * Basert på RFC 6238 (HMAC-SHA1, 30s step).
 */
function base32_decode_str(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $bits = '';
    for ($i=0; $i<strlen($b32); $i++) {
        $val = strpos($alphabet, $b32[$i]);
        if ($val === false) continue;
        $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i=0; $i+8 <= strlen($bits); $i+=8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function totp_code(string $secretB32, int $timeSlice): string {
    $key = base32_decode_str($secretB32);
    $time = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $trunc = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
    $code = $trunc % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $secretB32, string $code, int $window = 1): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;

    $slice = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secretB32, $slice + $i), $code)) {
            return true;
        }
    }
    return false;
}

function table_columns(PDO $pdo, string $table): array {
    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = $r['Field'];
    }
    return $cols;
}

function pick_first(array $candidates, array $available): ?string {
    foreach ($candidates as $c) {
        if (in_array($c, $available, true)) return $c;
    }
    return null;
}
