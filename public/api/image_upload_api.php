<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\api\image_upload_api.php
// API: Unassigned images (node_location_unassigned_attachments)
// - action=img&id=123    -> streamer original via DB.file_path
// - action=thumb&id=123  -> streamer thumb via DB.thumb_path (fallback til img hvis mangler)
//
// Lagring:
//   storage\unassigned\<dato>\<random>.<ext>
//   storage\unassigned\<dato>\<random>_thumb.<ext>

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* -------------------- Bootstrap / DB (robust) -------------------- */
$ROOT_PUBLIC = realpath(__DIR__ . '/..');                 // ...\public
$ROOT_SITE   = $ROOT_PUBLIC ? realpath($ROOT_PUBLIC . '/..') : null; // ...\teknisk.hkbb.no

if ($ROOT_SITE) {
    foreach ([
        $ROOT_SITE . '/vendor/autoload.php',
        $ROOT_SITE . '/app/Bootstrap.php',
        $ROOT_SITE . '/app/bootstrap.php',
        $ROOT_SITE . '/app/database.php',
        $ROOT_SITE . '/bootstrap.php',
        $ROOT_PUBLIC . '/bootstrap.php',
    ] as $p) {
        if (is_file($p)) require_once $p;
    }
}

if (empty($_SESSION['username'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Not logged in";
    exit;
}

$pdo = null;
try {
    if (function_exists('pdo')) {
        $pdo = pdo();
    } elseif (class_exists('App\\Database') && method_exists('App\\Database', 'getConnection')) {
        $pdo = \App\Database::getConnection();
    }
} catch (\Throwable $e) {
    $pdo = null;
}
if (!$pdo instanceof PDO) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "DB connection not available";
    exit;
}

/* -------------------- Helpers -------------------- */
function unassigned_base_dir(?string $rootSite): string {
    // C:\inetpub\wwwroot\teknisk.hkbb.no\storage\unassigned
    return rtrim((string)$rootSite, "\\/") . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'unassigned';
}

function safe_join_and_real(string $baseDir, string $relative): ?string {
    $relative = str_replace(['..', "\0"], '', $relative);
    $relative = ltrim($relative, "\\/");

    $baseReal = realpath($baseDir);
    if ($baseReal === false) return null;

    $path = $baseDir . DIRECTORY_SEPARATOR . $relative;
    $real = realpath($path);
    if ($real === false) return null;

    if (strpos($real, $baseReal) !== 0) return null;
    if (!is_file($real)) return null;

    return $real;
}

function mime_from_ext(string $path, string $fallback): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
    return match ($ext) {
        'jpg','jpeg' => 'image/jpeg',
        'png'        => 'image/png',
        'webp'       => 'image/webp',
        'gif'        => 'image/gif',
        default      => ($fallback ?: 'application/octet-stream'),
    };
}

/* -------------------- Router -------------------- */
$action = (string)($_GET['action'] ?? '');
$id     = (int)($_GET['id'] ?? 0);

if (!in_array($action, ['img','thumb'], true) || $id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Bad request";
    exit;
}

$stmt = $pdo->prepare("SELECT file_path, thumb_path, mime_type, original_filename FROM node_location_unassigned_attachments WHERE id = :id");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit;
}

$base = unassigned_base_dir($ROOT_SITE);
$rel  = ($action === 'thumb') ? (string)($row['thumb_path'] ?? '') : (string)($row['file_path'] ?? '');

if ($rel === '' && $action === 'thumb') {
    // fallback til original hvis thumb_path mangler
    $rel = (string)($row['file_path'] ?? '');
    $action = 'img';
}

$real = safe_join_and_real($base, $rel);
if ($real === null) {
    // fallback: hvis thumb mangler, prøv original
    if ($action === 'thumb') {
        $rel2 = (string)($row['file_path'] ?? '');
        $real2 = ($rel2 !== '') ? safe_join_and_real($base, $rel2) : null;
        if ($real2) {
            $real = $real2;
            $action = 'img';
        } else {
            http_response_code(404);
            exit;
        }
    } else {
        http_response_code(404);
        exit;
    }
}

$mimeDb = (string)($row['mime_type'] ?? '');
$mime   = mime_from_ext($real, $mimeDb);
$name   = (string)($row['original_filename'] ?? 'image');

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');

readfile($real);
exit;