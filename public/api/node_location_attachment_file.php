<?php
// public/api/node_location_attachment_file.php
declare(strict_types=1);

use App\Database;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function jsonOut(int $code, array $payload): void {
    if (ob_get_length()) { @ob_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
if ($autoload && is_file($autoload)) {
    require_once $autoload;
}
if (!class_exists(Database::class)) {
    jsonOut(500, ['ok' => false, 'error' => 'Autoload feilet: App\\Database ble ikke lastet.']);
}

// Storage base (utenfor public/)
$STORAGE_BASE = realpath(__DIR__ . '/../../storage/node_locations');
if (!$STORAGE_BASE) {
    // fall back uten realpath (før katalog finnes)
    $STORAGE_BASE = __DIR__ . '/../../storage/node_locations';
}

function normalize_list($v): array {
    if (is_array($v)) return array_values(array_filter(array_map('strval', $v)));
    if (is_string($v) && trim($v) !== '') {
        $parts = preg_split('/[,\s;]+/', $v);
        return array_values(array_filter(array_map('strval', $parts)));
    }
    return [];
}
function has_any(array $needles, array $haystack): bool {
    $haystack = array_map('strtolower', $haystack);
    foreach ($needles as $n) {
        if (in_array(strtolower($n), $haystack, true)) return true;
    }
    return false;
}

function absPathFromStorageKey(string $storageBase, string $key): string {
    $key = trim((string)$key);

    // tillat ikke absolute paths eller traversal
    if ($key === '' || str_contains($key, '..') || $key[0] === '/' || str_contains($key, '\\')) return '';

    $full = $storageBase . '/' . $key;

    // realpath fungerer kun hvis fil finnes
    $rpBase = realpath($storageBase);
    $rpFull = realpath($full);
    if (!$rpBase || !$rpFull) return '';

    // sjekk at filen faktisk ligger under storage base
    if (strpos($rpFull, $rpBase) !== 0) return '';
    return $rpFull;
}

$username = $_SESSION['username'] ?? '';
if ($username === '') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Ikke innlogget.";
    exit;
}

$attId = (int)($_GET['id'] ?? 0);
if ($attId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Mangler id.";
    exit;
}

$download = (int)($_GET['download'] ?? 0);

$pdo = Database::getConnection();

// roller
$roles = normalize_list($_SESSION['roles'] ?? null);

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
$stmt->execute([':u' => $username]);
$currentUserId = (int)($stmt->fetchColumn() ?: 0);
if ($currentUserId <= 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Ukjent bruker.";
    exit;
}

$stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
$stmt->execute([':uid' => $currentUserId]);
$dbRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
$roles = array_values(array_unique(array_map('strtolower', array_merge($roles, normalize_list($dbRoles)))));

$isAdmin = has_any(['admin'], $roles);
$canNodeRead = $isAdmin || has_any(['node_read','node_write'], $roles);
if (!$canNodeRead) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Du har ikke tilgang.";
    exit;
}

// hent vedlegg
$st = $pdo->prepare('SELECT * FROM node_location_attachments WHERE id=:id LIMIT 1');
$st->execute([':id' => $attId]);
$att = $st->fetch(PDO::FETCH_ASSOC);

if (!$att) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Fant ikke vedlegg.";
    exit;
}

$key  = (string)($att['file_path'] ?? '');       // storage key, f.eks "12/fil.jpg"
$mime = (string)($att['mime_type'] ?? 'application/octet-stream');
$orig = (string)($att['original_filename'] ?? 'file');

$abs = absPathFromStorageKey($STORAGE_BASE, $key);
if ($abs === '' || !is_file($abs)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Fil ikke funnet på disk.";
    exit;
}

// stream fil
$size = filesize($abs);
if ($size === false) $size = null;

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

$disposition = $download ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $orig) . '"');
if ($size !== null) header('Content-Length: ' . (string)$size);

$fp = fopen($abs, 'rb');
if (!$fp) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Kunne ikke lese fil.";
    exit;
}
fpassthru($fp);
fclose($fp);
exit;
