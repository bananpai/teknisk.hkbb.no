<?php
// public/api/node_location_attachments.php
declare(strict_types=1);

use App\Database;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('node_location_attachments fatal: ' . ($err['message'] ?? 'unknown') . ' in ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? ''));
        if (ob_get_length()) { @ob_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode(['ok' => false, 'error' => 'En intern feil oppstod.'], JSON_UNESCAPED_UNICODE);
    }
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

// Autoload (hvis ikke allerede lastet)
$autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
if ($autoload && is_file($autoload)) {
    require_once $autoload;
}
if (!class_exists(Database::class)) {
    jsonOut(500, ['ok' => false, 'error' => 'Autoload feilet: App\\Database ble ikke lastet.']);
}

// ----------------------------
// Storage utenfor public/
// ----------------------------
function storageBaseDir(): string {
    $p = __DIR__ . '/../../storage/node_locations';
    if (!is_dir($p)) {
        @mkdir($p, 0775, true);
    }
    $rp = realpath($p);
    return $rp ?: $p;
}

function absPathFromStorageKey(string $key): string {
    $key = trim((string)$key);
    if ($key === '') return '';
    if (str_contains($key, '..')) return '';
    if ($key[0] === '/' || str_contains($key, '\\')) return '';

    $base = storageBaseDir();
    $full = rtrim($base, '/\\') . '/' . $key;

    $rpFull = realpath($full);
    if (!$rpFull) return '';

    $rpBase = realpath($base);
    if (!$rpBase) return '';

    if (strpos($rpFull, $rpBase) !== 0) return '';
    return $rpFull;
}

function gdDiag(string $mime): array {
    $need = ['imagerotate'];
    if ($mime === 'image/jpeg') $need[] = 'imagecreatefromjpeg';
    if ($mime === 'image/png')  $need[] = 'imagecreatefrompng';
    if ($mime === 'image/webp') {
        $need[] = 'imagecreatefromwebp';
        $need[] = 'imagewebp';
    }
    $missing = [];
    foreach ($need as $fn) {
        if (!function_exists($fn)) $missing[] = $fn;
    }
    return $missing;
}

function rotateImageFile90(string $absPath, string $mime, string $dir): bool {
    if (!is_file($absPath)) return false;
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) return false;

    if (!function_exists('imagerotate')) return false;

    if ($mime === 'image/jpeg') {
        if (!function_exists('imagecreatefromjpeg')) return false;
        $img = @imagecreatefromjpeg($absPath);
    } elseif ($mime === 'image/png') {
        if (!function_exists('imagecreatefrompng')) return false;
        $img = @imagecreatefrompng($absPath);
    } else {
        if (!function_exists('imagecreatefromwebp')) return false;
        $img = @imagecreatefromwebp($absPath);
    }
    if (!$img) return false;

    $angle = ($dir === 'cw') ? -90 : 90; // imagerotate roterer CCW

    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        $rot = imagerotate($img, $angle, $transparent);
        if ($rot) {
            imagealphablending($rot, false);
            imagesavealpha($rot, true);
        }
    } else {
        $rot = imagerotate($img, $angle, 0);
    }

    if (!$rot) {
        imagedestroy($img);
        return false;
    }

    $ok = false;
    if ($mime === 'image/jpeg') {
        $ok = @imagejpeg($rot, $absPath, 90);
    } elseif ($mime === 'image/png') {
        $ok = @imagepng($rot, $absPath, 6);
    } else {
        if (!function_exists('imagewebp')) $ok = false;
        else $ok = @imagewebp($rot, $absPath, 85);
    }

    imagedestroy($img);
    imagedestroy($rot);
    return (bool)$ok;
}

// ----------------------------
// Auth + input
// ----------------------------
$username = $_SESSION['username'] ?? '';
if ($username === '') jsonOut(403, ['ok' => false, 'error' => 'Ikke innlogget.']);

$nodeId = (int)($_POST['node_id'] ?? 0);
$attId  = (int)($_POST['attachment_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));

if ($nodeId <= 0 || $attId <= 0) jsonOut(400, ['ok' => false, 'error' => 'Mangler node_id eller attachment_id.']);
if (!in_array($action, ['rotate_cw','rotate_ccw','save_desc','delete'], true)) {
    jsonOut(400, ['ok' => false, 'error' => 'Ugyldig action.']);
}

$pdo = Database::getConnection();

// roller fra session + db
$roles = normalize_list($_SESSION['roles'] ?? null);

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
$stmt->execute([':u' => $username]);
$currentUserId = (int)($stmt->fetchColumn() ?: 0);
if ($currentUserId <= 0) jsonOut(403, ['ok' => false, 'error' => 'Ukjent bruker.']);

$stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
$stmt->execute([':uid' => $currentUserId]);
$dbRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$roles = array_values(array_unique(array_map('strtolower', array_merge($roles, normalize_list($dbRoles)))));
$isAdmin = has_any(['admin'], $roles);
$canNodeWrite = $isAdmin || has_any(['node_write'], $roles);
if (!$canNodeWrite) jsonOut(403, ['ok' => false, 'error' => 'Du har ikke tilgang (node_write/admin).']);

// hent vedlegg (må tilhøre node)
$st = $pdo->prepare('SELECT * FROM node_location_attachments WHERE id=:aid AND node_location_id=:nid LIMIT 1');
$st->execute([':aid' => $attId, ':nid' => $nodeId]);
$att = $st->fetch(PDO::FETCH_ASSOC);
if (!$att) jsonOut(404, ['ok' => false, 'error' => 'Fant ikke vedlegget.']);

$key = (string)($att['file_path'] ?? ''); // storage key: "<nodeId>/<fil>"
$prefix = $nodeId . '/';
if ($key === '' || strpos($key, $prefix) !== 0) {
    jsonOut(400, ['ok' => false, 'error' => 'Ugyldig filsti (ikke under riktig nodelokasjon).', 'file_path' => $key]);
}

$abs = absPathFromStorageKey($key);
if ($abs === '') jsonOut(404, ['ok' => false, 'error' => 'Fil ikke funnet på disk.', 'file_path' => $key]);

// ----------------------------
// actions
// ----------------------------
if ($action === 'save_desc') {
    $desc = trim((string)($_POST['description'] ?? ''));
    if (mb_strlen($desc, 'UTF-8') > 255) $desc = mb_substr($desc, 0, 255, 'UTF-8');

    try {
        $pdo->prepare('UPDATE node_location_attachments SET description=:d WHERE id=:aid AND node_location_id=:nid')
            ->execute([':d' => ($desc === '' ? null : $desc), ':aid' => $attId, ':nid' => $nodeId]);
    } catch (\Throwable $e) {
        jsonOut(500, ['ok' => false, 'error' => 'DB-feil ved lagring av bildetekst: ' . $e->getMessage()]);
    }

    jsonOut(200, ['ok' => true, 'message' => 'Bildetekst lagret.', 'description' => $desc]);
}

if ($action === 'delete') {
    $pdo->beginTransaction();
    try {
        // slett DB først (så mister vi ikke referanse hvis filunlink feiler)
        $pdo->prepare('DELETE FROM node_location_attachments WHERE id=:aid AND node_location_id=:nid')
            ->execute([':aid' => $attId, ':nid' => $nodeId]);

        // slett fil
        if (is_file($abs)) {
            @unlink($abs);
        }

        $pdo->commit();
        jsonOut(200, ['ok' => true, 'message' => 'Bildet ble slettet.']);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonOut(500, ['ok' => false, 'error' => 'Kunne ikke slette: ' . $e->getMessage()]);
    }
}

// rotate
$mime = (string)($att['mime_type'] ?? '');
if (!str_starts_with($mime, 'image/')) {
    jsonOut(400, ['ok' => false, 'error' => 'Dette vedlegget er ikke et bilde.']);
}

$dir = ($action === 'rotate_cw') ? 'cw' : 'ccw';

$missing = gdDiag($mime);
if (!empty($missing)) {
    jsonOut(500, [
        'ok' => false,
        'error' => 'GD mangler funksjoner: ' . implode(', ', $missing),
        'mime' => $mime,
    ]);
}

// må være skrivbar
if (!is_writable($abs)) {
    jsonOut(500, [
        'ok' => false,
        'error' => 'Filen er ikke skrivbar for webserver-brukeren.',
        'path' => $abs,
    ]);
}

$ok = rotateImageFile90($abs, $mime, $dir);
if (!$ok) {
    jsonOut(500, [
        'ok' => false,
        'error' => 'Kunne ikke rotere bildet (GD/format/fil).',
        'mime' => $mime,
        'path' => $abs,
    ]);
}

// oppdater checksum/size
$bytes = @file_get_contents($abs);
if ($bytes !== false) {
    $sha = hash('sha256', $bytes);
    $size = @filesize($abs);
    $pdo->prepare('UPDATE node_location_attachments SET checksum_sha256=:c, file_size=:s WHERE id=:aid AND node_location_id=:nid')
        ->execute([
            ':c' => $sha,
            ':s' => ($size === false ? (int)($att['file_size'] ?? 0) : (int)$size),
            ':aid' => $attId,
            ':nid' => $nodeId
        ]);
}

$bust = time();
$url = '/api/node_location_attachment_file.php?id=' . $attId . '&v=' . $bust;

jsonOut(200, ['ok' => true, 'message' => 'Bildet ble rotert 90°.', 'url' => $url]);
