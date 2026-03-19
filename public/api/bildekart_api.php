<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\api\bildekart_api.php
// Bildekart API (REN endpoint) – brukes av /?page=bildekart
//
// Endepunkter:
// - GET  ?ajax=data                   -> JSON for alle bilder med lat/lon
// - GET  ?thumb=1&nl=..&id=..&s=..    -> thumbnail (node-bilder). Leser thumb_path/file_path fra DB
// - GET  ?thumb=1&u=..&s=..           -> thumbnail (unassigned). Leser thumb_path/file_path fra DB
// - GET  ?action=img&id=..            -> original node-bilde (REN streaming fra denne filen)
// - GET  ?action=img_u&id=..          -> original unassigned bilde
// - GET  ?action=meta_u&id=..         -> json metadata unassigned
// - GET  ?action=suggest_nodes&q=...  -> autosuggest for nodelokasjoner
// - POST ?action=map_to_node          -> map/remap bilde til valgt nodelokasjon
// - POST ?action=delete_image         -> sletter bilde (node/unassigned) hvis bruker har rettigheter
//
// Oppdatert 2026-03-09:
// - Bruker vanlig innlogging via core/auth.php
// - Ingen fallback til egen nodelokasjon-login
// - Node-originalbilder streames direkte fra denne API-filen via action=img&id=<attachment-id>
// - orig_url i ajax=data peker til denne filen
// - Leser file_path/thumb_path direkte fra DB for robust oppslag
// - Autosuggest returnerer nå ALLE nodelokasjoner, også uten lat/lon
// - Mapping fra bildeviser fungerer derfor også for noder uten kartkoordinater
// - Nytt: delete_image-endepunkt for sletting fra bildeviser

declare(strict_types=1);

/* ------------------ Helpers ------------------ */
function bildekart_api_normalize_int($v): int { return max(0, (int)$v); }

function bildekart_api_normalize_rel_path(string $p): string
{
    $p = trim(str_replace('\\', '/', $p));
    $p = preg_replace('~/+~', '/', $p) ?? $p;
    return trim((string)$p, '/');
}

function bildekart_api_json_response(array $data, int $status = 200): void {
    @ini_set('display_errors', '0');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/* ------------------ Security headers ------------------ */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* ------------------ Session ------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ------------------ Logging ------------------ */
$LOG_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_log';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$ERROR_LOG = $LOG_DIR . DIRECTORY_SEPARATOR . 'bildekart_api_error.log';

function bildekart_api_log_err(string $path, string $msg): void {
    @file_put_contents($path, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

/* ------------------ Auth ------------------ */
$authCandidate = __DIR__ . '/../../core/auth.php';
if (is_file($authCandidate)) {
    require_once $authCandidate;
    if (function_exists('require_login')) {
        require_login();
    }
}

/* ------------------ CSRF ------------------ */
function bildekart_api_csrf_check_or_json(): void {
    $ok = isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token']);
    if (!$ok) {
        bildekart_api_json_response(['ok'=>false,'error'=>'csrf','message'=>'CSRF-feil. Last siden på nytt.'], 403);
    }
}

/* ------------------ Permissions ------------------ */
function bildekart_api_normalize_list($value): array
{
    if (is_array($value)) {
        return array_values(array_filter(
            array_map(static fn($v) => trim((string)$v), $value),
            static fn($v) => $v !== ''
        ));
    }

    if (is_string($value) && trim($value) !== '') {
        $parts = preg_split('/[,\r\n;|]+/', $value);
        if (!$parts) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn($v) => trim((string)$v), $parts),
            static fn($v) => $v !== ''
        ));
    }

    return [];
}

function bildekart_api_user_can_delete_images(): bool
{
    $adminFlags = [
        (bool)($_SESSION['is_admin'] ?? false),
        (bool)($_SESSION['admin'] ?? false),
        (bool)($_SESSION['isAdmin'] ?? false),
    ];

    foreach ($adminFlags as $flag) {
        if ($flag) {
            return true;
        }
    }

    $roleSources = [
        $_SESSION['roles'] ?? null,
        $_SESSION['user_roles'] ?? null,
        $_SESSION['permissions'] ?? null,
        $_SESSION['perms'] ?? null,
    ];

    $tokens = [];
    foreach ($roleSources as $src) {
        foreach (bildekart_api_normalize_list($src) as $item) {
            $tokens[] = mb_strtolower(trim($item), 'UTF-8');
        }
    }

    $tokenSet = array_fill_keys($tokens, true);

    $allowed = [
        'admin',
        'administrator',
        'feltobjekter skriv',
        'feltobjekter_skriv',
        'feltobjekter-write',
        'feltobjekter write',
        'nodelokasjon skriv',
        'nodelokasjon_skriv',
        'write_feltobjekter',
        'feltobjects write',
    ];

    foreach ($allowed as $key) {
        if (isset($tokenSet[$key])) {
            return true;
        }
    }

    return false;
}

/* ------------------ DB ------------------ */
try {
    if (function_exists('pdo')) {
        $pdo = pdo();
    } else {
        $env1 = __DIR__ . '/../../app/Support/Env.php';
        $env2 = __DIR__ . '/../app/Support/Env.php';

        if (is_file($env1)) {
            require_once $env1;
        } elseif (is_file($env2)) {
            require_once $env2;
        }

        if (class_exists('\App\Support\Env') && function_exists('pdo_from_env')) {
            $pdo = pdo_from_env();
        } elseif (function_exists('\App\Support\pdo_from_env')) {
            $pdo = \App\Support\pdo_from_env();
        } elseif (function_exists('pdo_from_env')) {
            $pdo = pdo_from_env();
        } else {
            throw new RuntimeException('Fant ikke pdo()/pdo_from_env().');
        }
    }

    if (!$pdo instanceof PDO) {
        throw new RuntimeException('DB-tilkobling ga ikke PDO');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    bildekart_api_log_err($ERROR_LOG, "PDO-fail (bildekart_api): " . $e->getMessage());
    bildekart_api_json_response(['ok'=>false,'error'=>'db','message'=>$e->getMessage()], 500);
}

/* ------------------ Storage bases ------------------ */
$STORAGE_NODE_BASE = rtrim('C:\\inetpub\\wwwroot\\teknisk.hkbb.no\\storage\\node_locations', "\\/");
$STORAGE_UNASSIGNED_BASE = rtrim('C:\\inetpub\\wwwroot\\teknisk.hkbb.no\\storage\\unassigned', "\\/");
$PUBLIC_UPLOADS_NODE_BASE = rtrim('C:\\inetpub\\wwwroot\\teknisk.hkbb.no\\public\\uploads\\node_locations', "\\/");

/* ------------------ Utils ------------------ */
function bildekart_api_safe_real_within(string $base, string $candidate): string {
    $baseRp = realpath($base);
    if ($baseRp === false) return '';
    $candRp = realpath($candidate);
    if ($candRp === false) return '';
    $baseRp = rtrim(str_replace('/', '\\', $baseRp), "\\") . "\\";
    $candRp = str_replace('/', '\\', $candRp);
    if (stripos($candRp, $baseRp) !== 0) return '';
    return $candRp;
}

function bildekart_api_detect_mime_from_path(string $absPath): string {
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $m = (string)$finfo->file($absPath);
        if ($m) return $m;
    }
    return ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');
}

function bildekart_api_parse_caption_json(?string $caption): ?array {
    $cap = trim((string)$caption);
    if ($cap === '' || ($cap[0] !== '{' && $cap[0] !== '[')) return null;
    $decoded = json_decode($cap, true);
    return is_array($decoded) ? $decoded : null;
}

function bildekart_api_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function bildekart_api_column_exists(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $col]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function bildekart_api_unassigned_table_exists(PDO $pdo): bool {
    return bildekart_api_table_exists($pdo, 'node_location_unassigned_attachments');
}

function bildekart_api_node_thumb_column_exists(PDO $pdo): bool {
    return bildekart_api_column_exists($pdo, 'node_location_attachments', 'thumb_path');
}

function bildekart_api_stream_file(string $abs, ?string $forcedMime = null, bool $allowCache = false): void {
    if (!is_file($abs)) {
        http_response_code(404);
        echo "not_found";
        exit;
    }

    $mime = $forcedMime ?: bildekart_api_detect_mime_from_path($abs);

    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string)filesize($abs));

    if ($allowCache) {
        header('Cache-Control: private, max-age=86400');
    } else {
        header('Cache-Control: private, max-age=0, no-store');
        header('Pragma: no-cache');
    }

    readfile($abs);
    exit;
}

function bildekart_api_build_node_absolute_candidates(
    string $storageNodeBase,
    string $legacyNodeBase,
    int $nodeId,
    string $relPath
): array {
    $relPath = bildekart_api_normalize_rel_path($relPath);
    if ($relPath === '') return [];

    $basename = basename(str_replace('\\', '/', $relPath));
    $candidates = [];

    $candidates[] = $storageNodeBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    $candidates[] = $storageNodeBase . DIRECTORY_SEPARATOR . $nodeId . DIRECTORY_SEPARATOR . $basename;
    $candidates[] = $storageNodeBase . DIRECTORY_SEPARATOR . $basename;
    $candidates[] = $legacyNodeBase . DIRECTORY_SEPARATOR . $nodeId . DIRECTORY_SEPARATOR . $basename;
    $candidates[] = $legacyNodeBase . DIRECTORY_SEPARATOR . $basename;

    return array_values(array_unique($candidates));
}

function bildekart_api_resolve_existing_file_from_candidates(
    array $candidates,
    string $storageNodeBase,
    string $legacyNodeBase
): string {
    foreach ($candidates as $cand) {
        $candWin = str_replace('/', '\\', $cand);
        $legacyWin = str_replace('/', '\\', $legacyNodeBase);
        $base = (stripos($candWin, $legacyWin) === 0) ? $legacyNodeBase : $storageNodeBase;
        $rp = bildekart_api_safe_real_within($base, $cand);
        if ($rp !== '' && is_file($rp)) {
            return $rp;
        }
    }
    return '';
}

function bildekart_api_ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Klarte ikke å opprette mappe: ' . $dir);
    }
}

function bildekart_api_move_file_robust(string $from, string $to): void
{
    bildekart_api_ensure_dir(dirname($to));

    if (@rename($from, $to)) {
        return;
    }

    if (!@copy($from, $to)) {
        throw new RuntimeException('Klarte ikke å flytte fil: ' . $from . ' -> ' . $to);
    }

    @unlink($from);
}

function bildekart_api_delete_file_best_effort(string $absPath): void
{
    if ($absPath === '') {
        return;
    }

    if (is_file($absPath)) {
        @chmod($absPath, 0666);
        @unlink($absPath);
    }
}

function bildekart_api_try_delete_empty_dir_upwards(string $startDir, string $stopBase): void
{
    $base = realpath($stopBase);
    $dir = realpath($startDir);

    if ($base === false || $dir === false) {
        return;
    }

    $base = rtrim(str_replace('/', '\\', $base), '\\');
    $dir  = rtrim(str_replace('/', '\\', $dir), '\\');

    while ($dir !== '' && stripos($dir, $base) === 0 && mb_strlen($dir) >= mb_strlen($base)) {
        if ($dir === $base) {
            break;
        }

        $items = @scandir($dir);
        if (!is_array($items)) {
            break;
        }

        $items = array_values(array_diff($items, ['.', '..']));
        if (count($items) > 0) {
            break;
        }

        @rmdir($dir);
        $dir = rtrim(str_replace('/', '\\', dirname($dir)), '\\');
    }
}

function bildekart_api_build_mapped_node_rel_path_from_unassigned(int $nodeId, string $srcRel): string
{
    $srcRel = bildekart_api_normalize_rel_path($srcRel);
    $datePart = trim(dirname($srcRel), '/.');
    $base = basename($srcRel);

    if ($datePart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePart)) {
      return $nodeId . '/' . $datePart . '_' . $base;
    }

    return $nodeId . '/' . $base;
}

function bildekart_api_build_mapped_node_rel_path_from_node(int $newNodeId, string $srcRel): string
{
    $srcRel = bildekart_api_normalize_rel_path($srcRel);
    $parts = explode('/', $srcRel);
    array_shift($parts);
    $rest = implode('/', $parts);
    if ($rest === '') $rest = basename($srcRel);
    return $newNodeId . '/' . $rest;
}

function bildekart_api_absolute_from_base_rel(string $base, string $rel): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, bildekart_api_normalize_rel_path($rel));
}

/* ------------------ GPS helpers ------------------ */
function bildekart_api_parse_exif_ratio($x): ?float {
    if (is_numeric($x)) return (float)$x;
    if (is_string($x) && str_contains($x, '/')) {
        [$a, $b] = array_pad(explode('/', $x, 2), 2, '1');
        if (is_numeric($a) && is_numeric($b) && (float)$b != 0.0) return (float)$a / (float)$b;
    }
    return null;
}

function bildekart_api_dms_to_decimal($dmsArr, $ref): ?float {
    if (!is_array($dmsArr) || count($dmsArr) < 2) return null;
    $deg = bildekart_api_parse_exif_ratio($dmsArr[0] ?? null);
    $min = bildekart_api_parse_exif_ratio($dmsArr[1] ?? null);
    $sec = bildekart_api_parse_exif_ratio($dmsArr[2] ?? 0) ?? 0.0;
    if ($deg === null || $min === null) return null;
    $val = $deg + ($min / 60.0) + ($sec / 3600.0);
    $ref = strtoupper((string)$ref);
    if ($ref === 'S' || $ref === 'W') $val *= -1;
    return $val;
}

function bildekart_api_try_extract_gps(array $meta): ?array {
    if (isset($meta['gps']) && is_array($meta['gps'])) {
        $lat = $meta['gps']['lat'] ?? $meta['gps']['latitude'] ?? null;
        $lon = $meta['gps']['lon'] ?? $meta['gps']['lng'] ?? null;
        if (is_numeric($lat) && is_numeric($lon)) return ['lat' => (float)$lat, 'lon' => (float)$lon];
    }

    $lat = $meta['lat'] ?? $meta['latitude'] ?? $meta['GPSLatitude'] ?? null;
    $lon = $meta['lon'] ?? $meta['lng'] ?? $meta['longitude'] ?? $meta['GPSLongitude'] ?? null;
    if (is_numeric($lat) && is_numeric($lon)) return ['lat' => (float)$lat, 'lon' => (float)$lon];

    if (isset($meta['exif']['GPS']) && is_array($meta['exif']['GPS'])) {
        $gps = $meta['exif']['GPS'];
        $latDec = bildekart_api_dms_to_decimal($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? null);
        $lonDec = bildekart_api_dms_to_decimal($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? null);
        if ($latDec !== null && $lonDec !== null) return ['lat' => $latDec, 'lon' => $lonDec];
    }

    return null;
}

/* ------------------ API base ------------------ */
$API_BASE = '/api/bildekart_api.php';

/* =========================================================================================
   THUMB endpoint
   ========================================================================================= */
if (isset($_GET['thumb']) && (string)$_GET['thumb'] === '1') {
    $nlId = bildekart_api_normalize_int($_GET['nl'] ?? 0);
    $uId  = bildekart_api_normalize_int($_GET['u'] ?? 0);

    if ($uId > 0) {
        if (!bildekart_api_unassigned_table_exists($pdo)) { http_response_code(404); echo "no_table"; exit; }

        $hasThumbPath = bildekart_api_column_exists($pdo, 'node_location_unassigned_attachments', 'thumb_path');

        $sql = $hasThumbPath
            ? "SELECT file_path, thumb_path FROM node_location_unassigned_attachments WHERE id=? LIMIT 1"
            : "SELECT file_path, NULL AS thumb_path FROM node_location_unassigned_attachments WHERE id=? LIMIT 1";

        $st = $pdo->prepare($sql);
        $st->execute([$uId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo "not_found"; exit; }

        $rel = trim((string)($row['thumb_path'] ?? ''));
        if ($rel === '') $rel = trim((string)($row['file_path'] ?? ''));
        if ($rel === '') { http_response_code(404); echo "not_found"; exit; }

        $cand = $STORAGE_UNASSIGNED_BASE . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, bildekart_api_normalize_rel_path($rel));
        $abs = bildekart_api_safe_real_within($STORAGE_UNASSIGNED_BASE, $cand);
        if ($abs === '' || !is_file($abs)) { http_response_code(404); echo "not_found"; exit; }

        bildekart_api_stream_file($abs, null, true);
    }

    if ($nlId <= 0) { http_response_code(400); echo "bad"; exit; }

    $attId = bildekart_api_normalize_int($_GET['id'] ?? 0);
    $row = null;

    if ($attId > 0) {
        $sql = bildekart_api_node_thumb_column_exists($pdo)
            ? "SELECT id, node_location_id, file_path, thumb_path, mime_type FROM node_location_attachments WHERE id=? LIMIT 1"
            : "SELECT id, node_location_id, file_path, NULL AS thumb_path, mime_type FROM node_location_attachments WHERE id=? LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$attId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['node_location_id'] !== $nlId) {
            $row = null;
        }
    }

    if (!$row) {
        http_response_code(404);
        echo "not_found";
        exit;
    }

    $thumbRel = trim((string)($row['thumb_path'] ?? ''));
    $fileRel  = trim((string)($row['file_path'] ?? ''));

    $candidates = [];
    if ($thumbRel !== '') {
        $candidates = array_merge(
            $candidates,
            bildekart_api_build_node_absolute_candidates($STORAGE_NODE_BASE, $PUBLIC_UPLOADS_NODE_BASE, $nlId, $thumbRel)
        );
    }
    if ($fileRel !== '') {
        $candidates = array_merge(
            $candidates,
            bildekart_api_build_node_absolute_candidates($STORAGE_NODE_BASE, $PUBLIC_UPLOADS_NODE_BASE, $nlId, $fileRel)
        );
    }

    if ($thumbRel === '' && $fileRel !== '') {
        $relNorm = bildekart_api_normalize_rel_path($fileRel);
        $dir = trim(dirname($relNorm), '/.');
        $ext = strtolower(pathinfo($relNorm, PATHINFO_EXTENSION));
        $name = pathinfo($relNorm, PATHINFO_FILENAME);
        $thumbRelBuilt = ($dir !== '' ? $dir . '/' : '') . $name . '_thumb' . ($ext !== '' ? '.' . $ext : '');
        $candidates = array_merge(
            $candidates,
            bildekart_api_build_node_absolute_candidates($STORAGE_NODE_BASE, $PUBLIC_UPLOADS_NODE_BASE, $nlId, $thumbRelBuilt)
        );
    }

    $abs = bildekart_api_resolve_existing_file_from_candidates($candidates, $STORAGE_NODE_BASE, $PUBLIC_UPLOADS_NODE_BASE);
    if ($abs === '') { http_response_code(404); echo "not_found"; exit; }

    bildekart_api_stream_file($abs, null, true);
}

/* ------------------ Node original image ------------------ */
if (isset($_GET['action']) && (string)$_GET['action'] === 'img') {
    $id = bildekart_api_normalize_int($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(404); echo "not_found"; exit; }

    $st = $pdo->prepare("
        SELECT id, node_location_id, file_path, mime_type
        FROM node_location_attachments
        WHERE id = ?
        LIMIT 1
    ");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo "not_found"; exit; }

    $nodeId = (int)($row['node_location_id'] ?? 0);
    $fileRel = trim((string)($row['file_path'] ?? ''));
    if ($nodeId <= 0 || $fileRel === '') { http_response_code(404); echo "not_found"; exit; }

    $candidates = bildekart_api_build_node_absolute_candidates($STORAGE_NODE_BASE, $PUBLIC_UPLOADS_NODE_BASE, $nodeId, $fileRel);
    $abs = bildekart_api_resolve_existing_file_from_candidates($candidates, $STORAGE_NODE_BASE, $PUBLIC_UPLOADS_NODE_BASE);

    if ($abs === '') {
        bildekart_api_log_err($ERROR_LOG, "Node original not found for att_id={$id}, node_id={$nodeId}, file_path={$fileRel}");
        http_response_code(404);
        echo "not_found";
        exit;
    }

    bildekart_api_stream_file($abs, (string)($row['mime_type'] ?? '') ?: null, false);
}

/* ------------------ Unassigned original image ------------------ */
if (isset($_GET['action']) && (string)$_GET['action'] === 'img_u') {
    $id = bildekart_api_normalize_int($_GET['id'] ?? 0);
    if ($id <= 0 || !bildekart_api_unassigned_table_exists($pdo)) { http_response_code(404); echo "not_found"; exit; }

    $st = $pdo->prepare("SELECT file_path FROM node_location_unassigned_attachments WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $rel = trim((string)($st->fetchColumn() ?: ''));
    if ($rel === '') { http_response_code(404); echo "not_found"; exit; }

    $cand = $STORAGE_UNASSIGNED_BASE . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, bildekart_api_normalize_rel_path($rel));
    $abs = bildekart_api_safe_real_within($STORAGE_UNASSIGNED_BASE, $cand);
    if ($abs === '' || !is_file($abs)) { http_response_code(404); echo "not_found"; exit; }

    bildekart_api_stream_file($abs, null, false);
}

/* ------------------ Unassigned metadata ------------------ */
if (isset($_GET['action']) && (string)$_GET['action'] === 'meta_u') {
    $id = bildekart_api_normalize_int($_GET['id'] ?? 0);
    if ($id <= 0 || !bildekart_api_unassigned_table_exists($pdo)) { http_response_code(404); echo "not_found"; exit; }

    $st = $pdo->prepare("SELECT * FROM node_location_unassigned_attachments WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo "not_found"; exit; }

    $metaJson = (string)($row['metadata_json'] ?? '');
    bildekart_api_json_response([
        'ok' => true,
        'id' => (int)$row['id'],
        'created_at' => (string)($row['created_at'] ?? ''),
        'created_by' => (string)($row['created_by'] ?? ''),
        'mime_type' => (string)($row['mime_type'] ?? ''),
        'file_size' => (int)($row['file_size'] ?? 0),
        'checksum_sha256' => (string)($row['checksum_sha256'] ?? ''),
        'caption' => (string)($row['caption'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'lat' => $row['lat'] ?? null,
        'lon' => $row['lon'] ?? null,
        'metadata' => $metaJson !== '' ? json_decode($metaJson, true) : null,
    ]);
}

/* ------------------ Suggest nodes ------------------ */
if (isset($_GET['action']) && (string)$_GET['action'] === 'suggest_nodes') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        bildekart_api_json_response(['ok' => true, 'items' => []]);
    }

    $like = '%' . $q . '%';
    $prefix = $q . '%';

    $sql = "
        SELECT
            id,
            name,
            city,
            partner,
            lat,
            lon,
            CASE WHEN name = :qeq THEN 0 ELSE 1 END AS rank_exact,
            CASE WHEN name LIKE :prefix THEN 0 ELSE 1 END AS rank_prefix,
            CASE WHEN city LIKE :prefix THEN 0 ELSE 1 END AS rank_city
        FROM node_locations
        WHERE
            (
                name LIKE :like
                OR city LIKE :like
                OR partner LIKE :like
                OR postal_code LIKE :like
                OR slug LIKE :like
            )
        ORDER BY rank_exact, rank_prefix, rank_city, name ASC
        LIMIT 12
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':qeq' => $q,
        ':prefix' => $prefix,
        ':like' => $like,
    ]);

    $items = [];
    foreach ($st->fetchAll() as $row) {
        $lat = $row['lat'];
        $lon = $row['lon'];

        $items[] = [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'city' => (string)($row['city'] ?? ''),
            'partner' => (string)($row['partner'] ?? ''),
            'lat' => is_numeric($lat) ? (float)$lat : null,
            'lon' => is_numeric($lon) ? (float)$lon : null,
            'has_coords' => is_numeric($lat) && is_numeric($lon),
        ];
    }

    bildekart_api_json_response(['ok' => true, 'items' => $items]);
}

/* ------------------ Delete image ------------------ */
if (isset($_GET['action']) && (string)$_GET['action'] === 'delete_image') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        bildekart_api_json_response(['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Kun POST er tillatt.'], 405);
    }

    bildekart_api_csrf_check_or_json();

    if (!bildekart_api_user_can_delete_images()) {
        bildekart_api_json_response([
            'ok' => false,
            'error' => 'forbidden',
            'message' => 'Du har ikke rettigheter til å slette bilder.'
        ], 403);
    }

    $kind = trim((string)($_POST['kind'] ?? ''));
    $id = bildekart_api_normalize_int($_POST['id'] ?? 0);

    if (!in_array($kind, ['unassigned', 'node'], true) || $id <= 0) {
        bildekart_api_json_response([
            'ok' => false,
            'error' => 'bad_request',
            'message' => 'Ugyldige parametere for sletting.'
        ], 400);
    }

    try {
        $pdo->beginTransaction();

        if ($kind === 'unassigned') {
            if (!bildekart_api_unassigned_table_exists($pdo)) {
                throw new RuntimeException('Tabell for umappede bilder finnes ikke.');
            }

            $hasThumbPath = bildekart_api_column_exists($pdo, 'node_location_unassigned_attachments', 'thumb_path');

            $sql = $hasThumbPath
                ? "SELECT id, file_path, thumb_path FROM node_location_unassigned_attachments WHERE id = ? LIMIT 1"
                : "SELECT id, file_path, NULL AS thumb_path FROM node_location_unassigned_attachments WHERE id = ? LIMIT 1";

            $st = $pdo->prepare($sql);
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new RuntimeException('Fant ikke bildet som skulle slettes.');
            }

            $origRel = bildekart_api_normalize_rel_path((string)($row['file_path'] ?? ''));
            $thumbRel = bildekart_api_normalize_rel_path((string)($row['thumb_path'] ?? ''));

            $origAbs = '';
            $thumbAbs = '';

            if ($origRel !== '') {
                $origAbs = bildekart_api_safe_real_within(
                    $STORAGE_UNASSIGNED_BASE,
                    bildekart_api_absolute_from_base_rel($STORAGE_UNASSIGNED_BASE, $origRel)
                );
            }

            if ($thumbRel !== '') {
                $thumbAbs = bildekart_api_safe_real_within(
                    $STORAGE_UNASSIGNED_BASE,
                    bildekart_api_absolute_from_base_rel($STORAGE_UNASSIGNED_BASE, $thumbRel)
                );
            }

            $del = $pdo->prepare("DELETE FROM node_location_unassigned_attachments WHERE id = ?");
            $del->execute([$id]);

            $pdo->commit();

            bildekart_api_delete_file_best_effort($origAbs);
            bildekart_api_delete_file_best_effort($thumbAbs);

            if ($origAbs !== '') {
                bildekart_api_try_delete_empty_dir_upwards(dirname($origAbs), $STORAGE_UNASSIGNED_BASE);
            }
            if ($thumbAbs !== '') {
                bildekart_api_try_delete_empty_dir_upwards(dirname($thumbAbs), $STORAGE_UNASSIGNED_BASE);
            }

            bildekart_api_json_response([
                'ok' => true,
                'message' => 'Bildet ble slettet.',
                'kind' => 'unassigned',
                'id' => $id
            ]);
        }

        $hasThumbPath = bildekart_api_node_thumb_column_exists($pdo);

        $sql = $hasThumbPath
            ? "SELECT id, node_location_id, file_path, thumb_path FROM node_location_attachments WHERE id = ? LIMIT 1"
            : "SELECT id, node_location_id, file_path, NULL AS thumb_path FROM node_location_attachments WHERE id = ? LIMIT 1";

        $st = $pdo->prepare($sql);
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Fant ikke nodebildet som skulle slettes.');
        }

        $nodeId = (int)($row['node_location_id'] ?? 0);
        $origRel = bildekart_api_normalize_rel_path((string)($row['file_path'] ?? ''));
        $thumbRel = bildekart_api_normalize_rel_path((string)($row['thumb_path'] ?? ''));

        $origAbs = '';
        $thumbAbs = '';

        if ($nodeId > 0 && $origRel !== '') {
            $origAbs = bildekart_api_resolve_existing_file_from_candidates(
                bildekart_api_build_node_absolute_candidates($STORAGE_NODE_BASE, $PUBLIC_UPLOADS_NODE_BASE, $nodeId, $origRel),
                $STORAGE_NODE_BASE,
                $PUBLIC_UPLOADS_NODE_BASE
            );
        }

        if ($nodeId > 0 && $thumbRel !== '') {
            $thumbAbs = bildekart_api_resolve_existing_file_from_candidates(
                bildekart_api_build_node_absolute_candidates($STORAGE_NODE_BASE, $PUBLIC_UPLOADS_NODE_BASE, $nodeId, $thumbRel),
                $STORAGE_NODE_BASE,
                $PUBLIC_UPLOADS_NODE_BASE
            );
        }

        $del = $pdo->prepare("DELETE FROM node_location_attachments WHERE id = ?");
        $del->execute([$id]);

        $pdo->commit();

        bildekart_api_delete_file_best_effort($origAbs);
        bildekart_api_delete_file_best_effort($thumbAbs);

        if ($origAbs !== '') {
            $base = (stripos(str_replace('/', '\\', $origAbs), str_replace('/', '\\', $PUBLIC_UPLOADS_NODE_BASE)) === 0)
                ? $PUBLIC_UPLOADS_NODE_BASE
                : $STORAGE_NODE_BASE;
            bildekart_api_try_delete_empty_dir_upwards(dirname($origAbs), $base);
        }

        if ($thumbAbs !== '') {
            $base = (stripos(str_replace('/', '\\', $thumbAbs), str_replace('/', '\\', $PUBLIC_UPLOADS_NODE_BASE)) === 0)
                ? $PUBLIC_UPLOADS_NODE_BASE
                : $STORAGE_NODE_BASE;
            bildekart_api_try_delete_empty_dir_upwards(dirname($thumbAbs), $base);
        }

        bildekart_api_json_response([
            'ok' => true,
            'message' => 'Bildet ble slettet.',
            'kind' => 'node',
            'id' => $id
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        bildekart_api_log_err($ERROR_LOG, 'delete_image failed: ' . $e->getMessage());

        bildekart_api_json_response([
            'ok' => false,
            'error' => 'delete_failed',
            'message' => $e->getMessage(),
        ], 500);
    }
}

/* ------------------ Map image to node ------------------ */
if (isset($_GET['action']) && (string)$_GET['action'] === 'map_to_node') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        bildekart_api_json_response(['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Kun POST er tillatt.'], 405);
    }

    bildekart_api_csrf_check_or_json();

    $kind = trim((string)($_POST['kind'] ?? ''));
    $id = bildekart_api_normalize_int($_POST['id'] ?? 0);
    $nodeLocationId = bildekart_api_normalize_int($_POST['node_location_id'] ?? 0);

    if (!in_array($kind, ['unassigned', 'node'], true) || $id <= 0 || $nodeLocationId <= 0) {
        bildekart_api_json_response(['ok' => false, 'error' => 'bad_request', 'message' => 'Ugyldige parametere.'], 400);
    }

    $chk = $pdo->prepare("SELECT id, name FROM node_locations WHERE id = ? LIMIT 1");
    $chk->execute([$nodeLocationId]);
    $node = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$node) {
        bildekart_api_json_response(['ok' => false, 'error' => 'node_not_found', 'message' => 'Fant ikke valgt nodelokasjon.'], 404);
    }

    try {
        $pdo->beginTransaction();

        if ($kind === 'unassigned') {
            if (!bildekart_api_unassigned_table_exists($pdo)) {
                throw new RuntimeException('Tabell for umappede bilder finnes ikke.');
            }

            $st = $pdo->prepare("
                SELECT *
                FROM node_location_unassigned_attachments
                WHERE id = ?
                LIMIT 1
            ");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Fant ikke bildet som skal mappes.');
            }

            $srcOrigRel = bildekart_api_normalize_rel_path((string)($row['file_path'] ?? ''));
            $srcThumbRel = bildekart_api_normalize_rel_path((string)($row['thumb_path'] ?? ''));

            if ($srcOrigRel === '') {
                throw new RuntimeException('Bildet mangler file_path.');
            }

            $srcOrigAbs = bildekart_api_safe_real_within($STORAGE_UNASSIGNED_BASE, bildekart_api_absolute_from_base_rel($STORAGE_UNASSIGNED_BASE, $srcOrigRel));
            if ($srcOrigAbs === '' || !is_file($srcOrigAbs)) {
                throw new RuntimeException('Originalfil for umappet bilde ble ikke funnet på disk.');
            }

            $srcThumbAbs = '';
            if ($srcThumbRel !== '') {
                $srcThumbAbs = bildekart_api_safe_real_within($STORAGE_UNASSIGNED_BASE, bildekart_api_absolute_from_base_rel($STORAGE_UNASSIGNED_BASE, $srcThumbRel));
                if ($srcThumbAbs !== '' && !is_file($srcThumbAbs)) {
                    $srcThumbAbs = '';
                }
            }

            $destOrigRel = bildekart_api_build_mapped_node_rel_path_from_unassigned($nodeLocationId, $srcOrigRel);
            $destThumbRel = $srcThumbRel !== '' ? bildekart_api_build_mapped_node_rel_path_from_unassigned($nodeLocationId, $srcThumbRel) : '';

            $destOrigAbs = bildekart_api_absolute_from_base_rel($STORAGE_NODE_BASE, $destOrigRel);
            $destThumbAbs = $destThumbRel !== '' ? bildekart_api_absolute_from_base_rel($STORAGE_NODE_BASE, $destThumbRel) : '';

            bildekart_api_move_file_robust($srcOrigAbs, $destOrigAbs);
            if ($srcThumbAbs !== '' && $destThumbAbs !== '') {
                bildekart_api_move_file_robust($srcThumbAbs, $destThumbAbs);
            } else {
                $destThumbRel = null;
            }

            $ins = $pdo->prepare("
                INSERT INTO node_location_attachments
                (
                    node_location_id,
                    file_path,
                    thumb_path,
                    original_filename,
                    description,
                    taken_at,
                    mime_type,
                    file_size,
                    checksum_sha256,
                    metadata_json,
                    caption,
                    created_by,
                    created_at
                )
                VALUES
                (
                    :node_location_id,
                    :file_path,
                    :thumb_path,
                    :original_filename,
                    :description,
                    :taken_at,
                    :mime_type,
                    :file_size,
                    :checksum_sha256,
                    :metadata_json,
                    :caption,
                    :created_by,
                    :created_at
                )
            ");

            $ins->execute([
                ':node_location_id' => $nodeLocationId,
                ':file_path' => $destOrigRel,
                ':thumb_path' => $destThumbRel,
                ':original_filename' => (string)($row['original_filename'] ?? basename($destOrigRel)),
                ':description' => $row['description'] ?? null,
                ':taken_at' => $row['taken_at'] ?? null,
                ':mime_type' => (string)($row['mime_type'] ?? bildekart_api_detect_mime_from_path($destOrigAbs)),
                ':file_size' => (int)($row['file_size'] ?? 0),
                ':checksum_sha256' => $row['checksum_sha256'] ?? null,
                ':metadata_json' => $row['metadata_json'] ?? null,
                ':caption' => $row['caption'] ?? null,
                ':created_by' => $row['created_by'] ?? null,
                ':created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
            ]);

            $newAttId = (int)$pdo->lastInsertId();

            $del = $pdo->prepare("DELETE FROM node_location_unassigned_attachments WHERE id = ?");
            $del->execute([$id]);

            $pdo->commit();
            bildekart_api_json_response([
                'ok' => true,
                'message' => 'Bildet ble koblet til nodelokasjonen.',
                'item_key' => 'node:' . $newAttId,
                'node_location_id' => $nodeLocationId,
                'node_name' => (string)$node['name'],
            ]);
        }

        $st = $pdo->prepare("
            SELECT *
            FROM node_location_attachments
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Fant ikke nodebildet som skal flyttes.');
        }

        $oldNodeId = (int)($row['node_location_id'] ?? 0);
        $srcOrigRel = bildekart_api_normalize_rel_path((string)($row['file_path'] ?? ''));
        $srcThumbRel = bildekart_api_normalize_rel_path((string)($row['thumb_path'] ?? ''));

        if ($oldNodeId <= 0 || $srcOrigRel === '') {
            throw new RuntimeException('Nodebildet mangler gyldige data.');
        }

        if ($oldNodeId === $nodeLocationId) {
            $pdo->commit();
            bildekart_api_json_response([
                'ok' => true,
                'message' => 'Bildet er allerede koblet til denne nodelokasjonen.',
                'item_key' => 'node:' . $id,
                'node_location_id' => $nodeLocationId,
                'node_name' => (string)$node['name'],
            ]);
        }

        $srcOrigAbs = bildekart_api_resolve_existing_file_from_candidates(
            bildekart_api_build_node_absolute_candidates($STORAGE_NODE_BASE, $PUBLIC_UPLOADS_NODE_BASE, $oldNodeId, $srcOrigRel),
            $STORAGE_NODE_BASE,
            $PUBLIC_UPLOADS_NODE_BASE
        );
        if ($srcOrigAbs === '' || !is_file($srcOrigAbs)) {
            throw new RuntimeException('Originalfil for nodebilde ble ikke funnet på disk.');
        }

        $srcThumbAbs = '';
        if ($srcThumbRel !== '') {
            $srcThumbAbs = bildekart_api_resolve_existing_file_from_candidates(
                bildekart_api_build_node_absolute_candidates($STORAGE_NODE_BASE, $PUBLIC_UPLOADS_NODE_BASE, $oldNodeId, $srcThumbRel),
                $STORAGE_NODE_BASE,
                $PUBLIC_UPLOADS_NODE_BASE
            );
            if ($srcThumbAbs !== '' && !is_file($srcThumbAbs)) {
                $srcThumbAbs = '';
            }
        }

        $destOrigRel = bildekart_api_build_mapped_node_rel_path_from_node($nodeLocationId, $srcOrigRel);
        $destThumbRel = $srcThumbRel !== '' ? bildekart_api_build_mapped_node_rel_path_from_node($nodeLocationId, $srcThumbRel) : '';

        $destOrigAbs = bildekart_api_absolute_from_base_rel($STORAGE_NODE_BASE, $destOrigRel);
        $destThumbAbs = $destThumbRel !== '' ? bildekart_api_absolute_from_base_rel($STORAGE_NODE_BASE, $destThumbRel) : '';

        bildekart_api_move_file_robust($srcOrigAbs, $destOrigAbs);
        if ($srcThumbAbs !== '' && $destThumbAbs !== '') {
            bildekart_api_move_file_robust($srcThumbAbs, $destThumbAbs);
        } else {
            $destThumbRel = null;
        }

        $upd = $pdo->prepare("
            UPDATE node_location_attachments
            SET node_location_id = ?, file_path = ?, thumb_path = ?
            WHERE id = ?
        ");
        $upd->execute([$nodeLocationId, $destOrigRel, $destThumbRel, $id]);

        $pdo->commit();
        bildekart_api_json_response([
            'ok' => true,
            'message' => 'Bildet ble flyttet til valgt nodelokasjon.',
            'item_key' => 'node:' . $id,
            'node_location_id' => $nodeLocationId,
            'node_name' => (string)$node['name'],
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        bildekart_api_log_err($ERROR_LOG, 'map_to_node failed: ' . $e->getMessage());
        bildekart_api_json_response([
            'ok' => false,
            'error' => 'map_failed',
            'message' => $e->getMessage(),
        ], 500);
    }
}

/* =========================================================================================
   AJAX: data
   ========================================================================================= */
if (isset($_GET['ajax']) && (string)$_GET['ajax'] === 'data') {
    try {
        $hasMetaCol = bildekart_api_column_exists($pdo, 'node_location_attachments', 'metadata_json');
        $metaSelect = $hasMetaCol ? "a.metadata_json AS metadata_json" : "NULL AS metadata_json";

        $rows = $pdo->query("
            SELECT
              nl.id AS node_id,
              nl.name AS node_name,
              nl.city AS city,
              nl.partner AS partner,
              nl.lat AS node_lat,
              nl.lon AS node_lon,
              a.id AS att_id,
              a.file_path AS file_path,
              a.thumb_path AS thumb_path,
              a.caption AS caption,
              a.taken_at AS taken_at,
              a.created_at AS created_at,
              a.created_by AS created_by,
              a.mime_type AS mime_type,
              {$metaSelect}
            FROM node_locations nl
            JOIN node_location_attachments a ON a.node_location_id = nl.id
            WHERE COALESCE(a.file_path,'') <> ''
            ORDER BY a.created_at DESC, a.id DESC
        ")->fetchAll();

        $items = [];

        foreach ($rows as $r) {
            $meta = [];
            $metaJson = (string)($r['metadata_json'] ?? '');
            if ($metaJson !== '') {
                $decoded = json_decode($metaJson, true);
                if (is_array($decoded)) $meta = $decoded;
            }

            $capJson = bildekart_api_parse_caption_json($r['caption'] ?? null);

            $gps = null;
            if (is_array($capJson)) $gps = bildekart_api_try_extract_gps($capJson);
            if (!$gps) $gps = bildekart_api_try_extract_gps($meta);

            $latV = null;
            $lonV = null;

            if ($gps) {
                $latV = $gps['lat'];
                $lonV = $gps['lon'];
            } elseif (is_numeric($r['node_lat'] ?? null) && is_numeric($r['node_lon'] ?? null)) {
                $latV = (float)$r['node_lat'];
                $lonV = (float)$r['node_lon'];
            }

            if ($latV === null || $lonV === null) continue;

            $nodeId = (int)$r['node_id'];
            $attId  = (int)$r['att_id'];

            $fp = str_replace('\\', '/', (string)($r['file_path'] ?? ''));
            $file = basename($fp);
            if ($file === '') continue;

            $origUrl = $API_BASE . "?action=img&id=" . rawurlencode((string)$attId);
            $metaUrl = "/app/nodelokasjon/index.php?action=meta&att=" . rawurlencode((string)$attId);
            $thumbBase = $API_BASE . "?thumb=1&nl=" . rawurlencode((string)$nodeId) . "&id=" . rawurlencode((string)$attId);

            $taken = '';
            if (!empty($r['taken_at'])) $taken = (string)$r['taken_at'];
            if ($taken === '' && !empty($r['created_at'])) $taken = (string)$r['created_at'];

            $items[] = [
                'kind' => 'node',
                'mapped' => true,
                'node_id' => $nodeId,
                'node_location_id' => $nodeId,
                'node_name' => (string)($r['node_name'] ?? ''),
                'city' => (string)($r['city'] ?? ''),
                'poststed' => '',
                'postcode' => '',
                'postnr' => '',
                'partner' => (string)($r['partner'] ?? ''),
                'att_id' => $attId,
                'u_id' => 0,
                'filename' => $file,
                'original_filename' => $file,
                'file_path' => (string)($r['file_path'] ?? ''),
                'thumb_path' => (string)($r['thumb_path'] ?? ''),
                'lat' => $latV,
                'lon' => $lonV,
                'taken' => $taken,
                'created_by' => (string)($r['created_by'] ?? ''),
                'orig_url' => $origUrl,
                'meta_url' => $metaUrl,
                'thumb_base' => $thumbBase,
            ];
        }

        if (bildekart_api_unassigned_table_exists($pdo)) {
            $urows = $pdo->query("
              SELECT id, file_path, thumb_path, taken_at, created_at, created_by, metadata_json, caption, lat, lon
              FROM node_location_unassigned_attachments
              ORDER BY created_at DESC, id DESC
            ")->fetchAll();

            foreach ($urows as $u) {
                $latV = null;
                $lonV = null;

                if (is_numeric($u['lat'] ?? null) && is_numeric($u['lon'] ?? null)) {
                    $latV = (float)$u['lat'];
                    $lonV = (float)$u['lon'];
                } else {
                    $meta = [];
                    $mj = (string)($u['metadata_json'] ?? '');
                    if ($mj !== '') {
                        $decoded = json_decode($mj, true);
                        if (is_array($decoded)) $meta = $decoded;
                    }
                    $capJson = bildekart_api_parse_caption_json($u['caption'] ?? null);
                    $gps = null;
                    if (is_array($capJson)) $gps = bildekart_api_try_extract_gps($capJson);
                    if (!$gps) $gps = bildekart_api_try_extract_gps($meta);
                    if ($gps) {
                        $latV = $gps['lat'];
                        $lonV = $gps['lon'];
                    }
                }

                if ($latV === null || $lonV === null) continue;

                $uId = (int)$u['id'];
                $fp  = str_replace('\\', '/', (string)($u['file_path'] ?? ''));
                $bn  = basename($fp);
                if ($bn === '') continue;

                $origUrl   = $API_BASE . "?action=img_u&id=" . rawurlencode((string)$uId);
                $metaUrl   = $API_BASE . "?action=meta_u&id=" . rawurlencode((string)$uId);
                $thumbBase = $API_BASE . "?thumb=1&u=" . rawurlencode((string)$uId);

                $taken = '';
                if (!empty($u['taken_at'])) $taken = (string)$u['taken_at'];
                if ($taken === '' && !empty($u['created_at'])) $taken = (string)$u['created_at'];

                $items[] = [
                    'kind' => 'unassigned',
                    'mapped' => false,
                    'node_id' => 0,
                    'node_location_id' => 0,
                    'node_name' => 'Uten node',
                    'city' => '',
                    'poststed' => '',
                    'postcode' => '',
                    'postnr' => '',
                    'partner' => '',
                    'att_id' => 0,
                    'u_id' => $uId,
                    'filename' => $bn,
                    'original_filename' => $bn,
                    'file_path' => (string)($u['file_path'] ?? ''),
                    'thumb_path' => (string)($u['thumb_path'] ?? ''),
                    'lat' => $latV,
                    'lon' => $lonV,
                    'taken' => $taken,
                    'created_by' => (string)($u['created_by'] ?? ''),
                    'orig_url' => $origUrl,
                    'meta_url' => $metaUrl,
                    'thumb_base' => $thumbBase,
                ];
            }
        }

        bildekart_api_json_response(['ok' => true, 'count' => count($items), 'items' => $items]);
    } catch (Throwable $e) {
        bildekart_api_log_err($ERROR_LOG, "AJAX data fail (bildekart_api): " . $e->getMessage());
        bildekart_api_json_response(['ok' => false, 'error' => 'data', 'message' => $e->getMessage()], 500);
    }
}

bildekart_api_json_response(['ok' => false, 'error' => 'bad_request', 'message' => 'Ukjent endepunkt'], 400);