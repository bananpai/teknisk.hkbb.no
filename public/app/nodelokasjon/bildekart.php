<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\app\nodelokasjon\bildekart.php
// Bildekart – raske thumbnails + grid nær posisjon + upload uten node + bla i bilder på samme posisjon
//
// FIX (2026-03-03):
// - Viktig: AJAX skal ALDRI få redirect/HTML ved manglende login -> returner JSON 401 (auth) i stedet.
// - requireLoginForAllOrRedirect() tar nå hensyn til wantsJson() og responderer med jsonResponse() ved AJAX.
// - fetch() sender X-Requested-With og detekterer redirect som ekstra robusthet.
// - Bedre feilmelding ved auth / redirect.
//
// Auth/DB: Samme som app\nodelokasjon\index.php via _auth.php + App\Support\pdo_from_env()

declare(strict_types=1);

/* ------------------ Security headers ------------------ */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* ------------------ Session + Auth gate (HARD) ------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/_auth.php';

function esc($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function normalizeInt($v): int { return max(0, (int)$v); }

function appBasePath(): string {
  $sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  if ($sn === '') return '/app/nodelokasjon';
  $dir = str_replace('\\', '/', dirname($sn));
  $dir = rtrim($dir, '/');
  return $dir === '' ? '/' : $dir;
}
function selfUrl(array $params = []): string {
  $base = appBasePath() . '/bildekart.php';
  $q = http_build_query($params);
  return $q ? ($base . '?' . $q) : $base;
}

/* ------------------ Logging ------------------ */
$LOG_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_log';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$ERROR_LOG = $LOG_DIR . DIRECTORY_SEPARATOR . 'nodelokasjon_error.log';
function logErr(string $path, string $msg): void {
  @file_put_contents($path, "[".date('Y-m-d H:i:s')."] ".$msg."\n", FILE_APPEND);
}

/* ------------------ JSON helpers (for ajax robustness) ------------------ */
function wantsJson(): bool {
  $acc = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
  if (stripos($acc, 'application/json') !== false) return true;
  if (!empty($_POST['ajax']) && (string)$_POST['ajax'] === '1') return true;
  if (!empty($_GET['ajax']) && (string)$_GET['ajax'] !== '') return true;
  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
  return false;
}
function jsonResponse(array $data, int $status = 200): void {
  // Viktig: sørg for at JSON ikke blir ødelagt av warnings/notices
  @ini_set('display_errors', '0');
  while (ob_get_level() > 0) { @ob_end_clean(); }

  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

/* ------------------ Auth gate (AJAX-safe) ------------------ */
function requireLoginForAllOrRedirect(): void {
  if (!nl_is_logged_in()) {
    // AJAX / fetch skal aldri få HTML/redirect (som ender som "upload_failed")
    if (wantsJson()) {
      jsonResponse([
        'ok' => false,
        'error' => 'auth',
        'message' => 'Ikke innlogget (session utløpt). Logg inn på nytt og prøv igjen.'
      ], 401);
    }

    $next = (string)($_SERVER['REQUEST_URI'] ?? (appBasePath().'/bildekart.php'));
    header('Location: ' . appBasePath() . '/login.php?next=' . rawurlencode($next));
    exit;
  }
}

/* ------------------ CSRF ------------------ */
function csrfToken(): string {
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf_token'];
}
function csrfCheckOrJson(): void {
  $ok = isset($_POST['csrf_token'], $_SESSION['csrf_token'])
    && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);

  if (!$ok) {
    if (wantsJson()) jsonResponse(['ok'=>false,'error'=>'csrf','message'=>'CSRF-feil. Last siden på nytt.'], 403);
    http_response_code(403);
    echo "CSRF-feil. Last siden på nytt.";
    exit;
  }
}

/* ------------------ DB (pdo_from_env) ------------------ */
try {
  require_once __DIR__ . '/../../../app/Support/Env.php';
  $pdo = \App\Support\pdo_from_env();
  if (!$pdo instanceof PDO) throw new RuntimeException('DB-tilkobling ga ikke PDO');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  logErr($ERROR_LOG, "PDO-fail (bildekart): " . $e->getMessage());
  if (wantsJson()) jsonResponse(['ok'=>false,'error'=>'db','message'=>$e->getMessage()], 500);
  http_response_code(500);
  echo "<h1>500</h1><p>DB-tilkobling feilet. Sjekk logg: <code>".esc($ERROR_LOG)."</code></p>";
  exit;
}

/* ------------------ Storage paths ------------------ */
$STORAGE_NODE_DIR   = rtrim('C:\\inetpub\\wwwroot\\teknisk.hkbb.no\\storage\\node_locations', "\\/");
$PUBLIC_UPLOADS_DIR = rtrim('C:\\inetpub\\wwwroot\\teknisk.hkbb.no\\public\\uploads\\node_locations', "\\/");
$UNASSIGNED_DIR = $STORAGE_NODE_DIR . DIRECTORY_SEPARATOR . '_unassigned';
$THUMB_DIR      = $STORAGE_NODE_DIR . DIRECTORY_SEPARATOR . '_thumbs';

function ensureDir(string $dir): void { if (!is_dir($dir)) @mkdir($dir, 0775, true); }
ensureDir($UNASSIGNED_DIR);
ensureDir($THUMB_DIR);

function uuid16(): string { return substr(bin2hex(random_bytes(16)), 0, 16); }
function todayYmd(): string { return (new DateTime('now'))->format('Y-m-d'); }

function attachmentBasename(?string $filePath): string {
  $p = trim((string)($filePath ?? ''));
  if ($p === '') return '';
  $u = @parse_url($p);
  if (is_array($u) && isset($u['path']) && $u['path']) $p = (string)$u['path'];
  $b = basename(str_replace('\\', '/', $p));
  return str_replace("\0", '', $b);
}
function attachmentLegacyNodeId(?string $filePath): int {
  $p = trim((string)($filePath ?? ''));
  if ($p === '') return 0;
  $u = @parse_url($p);
  if (is_array($u) && isset($u['path']) && $u['path']) $p = (string)$u['path'];
  $p = str_replace('\\', '/', $p);
  if (preg_match('~/(?:nodelokasjon|node_locations|uploads/node_locations)/(\d+)/[^/]+$~i', $p, $m)) return (int)$m[1];
  return 0;
}
function findAttachmentFilePath(string $basename, int $nlId, string $storageDir, string $publicDir): string {
  $basename = trim($basename);
  if ($basename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $basename)) return '';
  $candidates = [
    $storageDir . DIRECTORY_SEPARATOR . $basename,
    $nlId > 0 ? $storageDir . DIRECTORY_SEPARATOR . $nlId . DIRECTORY_SEPARATOR . $basename : '',
    $nlId > 0 ? $publicDir  . DIRECTORY_SEPARATOR . $nlId . DIRECTORY_SEPARATOR . $basename : '',
    $publicDir . DIRECTORY_SEPARATOR . $basename,
  ];
  foreach ($candidates as $cand) if ($cand && is_file($cand)) return $cand;
  return '';
}
function detectMimeFromPath(string $absPath): string {
  $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
  if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $m = (string)$finfo->file($absPath);
    if ($m) return $m;
  }
  return ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');
}

/* ------------------ DB schema detection ------------------ */
function tableExists(PDO $pdo, string $table): bool {
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
  ");
  $stmt->execute([$table]);
  return ((int)$stmt->fetchColumn()) > 0;
}
function columnExists(PDO $pdo, string $table, string $col): bool {
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
  ");
  $stmt->execute([$table, $col]);
  return ((int)$stmt->fetchColumn()) > 0;
}
function metadataMode(PDO $pdo): string {
  if (columnExists($pdo, 'node_location_attachments', 'metadata_json')) return 'column';
  if (tableExists($pdo, 'node_location_attachment_metadata')) return 'table';
  return 'none';
}
function unassignedTableExists(PDO $pdo): bool {
  return tableExists($pdo, 'node_location_unassigned_attachments');
}

/* ------------------ GPS helpers ------------------ */
function parseExifRatio($x): ?float {
  if (is_numeric($x)) return (float)$x;
  if (is_string($x) && str_contains($x, '/')) {
    [$a,$b] = array_pad(explode('/', $x, 2), 2, '1');
    if (is_numeric($a) && is_numeric($b) && (float)$b != 0.0) return (float)$a / (float)$b;
  }
  return null;
}
function dmsToDecimal($dmsArr, $ref): ?float {
  if (!is_array($dmsArr) || count($dmsArr) < 2) return null;
  $deg = parseExifRatio($dmsArr[0] ?? null);
  $min = parseExifRatio($dmsArr[1] ?? null);
  $sec = parseExifRatio($dmsArr[2] ?? 0) ?? 0.0;
  if ($deg === null || $min === null) return null;
  $val = $deg + ($min/60.0) + ($sec/3600.0);
  $ref = strtoupper((string)$ref);
  if ($ref === 'S' || $ref === 'W') $val *= -1;
  return $val;
}
function try_extract_gps(array $meta): ?array {
  if (isset($meta['gps']) && is_array($meta['gps'])) {
    $lat = $meta['gps']['lat'] ?? $meta['gps']['latitude'] ?? null;
    $lon = $meta['gps']['lon'] ?? $meta['gps']['lng'] ?? null;
    if (is_numeric($lat) && is_numeric($lon)) return ['lat'=>(float)$lat, 'lon'=>(float)$lon];
  }
  $lat = $meta['lat'] ?? $meta['latitude'] ?? $meta['GPSLatitude'] ?? null;
  $lon = $meta['lon'] ?? $meta['lng'] ?? $meta['longitude'] ?? $meta['GPSLongitude'] ?? null;
  if (is_numeric($lat) && is_numeric($lon)) return ['lat'=>(float)$lat, 'lon'=>(float)$lon];

  if (isset($meta['exif']['GPS']) && is_array($meta['exif']['GPS'])) {
    $gps = $meta['exif']['GPS'];
    $latDec = dmsToDecimal($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? null);
    $lonDec = dmsToDecimal($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? null);
    if ($latDec !== null && $lonDec !== null) return ['lat'=>$latDec, 'lon'=>$lonDec];
  }
  return null;
}
function parseCaptionJson(?string $caption): ?array {
  $cap = trim((string)$caption);
  if ($cap === '' || ($cap[0] !== '{' && $cap[0] !== '[')) return null;
  $decoded = json_decode($cap, true);
  return is_array($decoded) ? $decoded : null;
}

/* ------------------ Thumbnail generation (cached) ------------------ */
function thumbCachePath(string $thumbDir, string $key, int $size): string {
  $size = max(40, min(220, $size));
  $sub = $thumbDir . DIRECTORY_SEPARATOR . (string)$size;
  if (!is_dir($sub)) @mkdir($sub, 0775, true);
  return $sub . DIRECTORY_SEPARATOR . $key . '.jpg';
}
function makeThumbJpeg(string $srcPath, string $dstPath, int $size): bool {
  if (!function_exists('imagecreatetruecolor')) return false;

  $mime = detectMimeFromPath($srcPath);
  $im = null;
  if ($mime === 'image/jpeg') { if (!function_exists('imagecreatefromjpeg')) return false; $im = @imagecreatefromjpeg($srcPath); }
  elseif ($mime === 'image/png') { if (!function_exists('imagecreatefrompng')) return false; $im = @imagecreatefrompng($srcPath); }
  elseif ($mime === 'image/webp') { if (!function_exists('imagecreatefromwebp')) return false; $im = @imagecreatefromwebp($srcPath); }
  else return false;

  if (!$im) return false;

  $w = imagesx($im); $h = imagesy($im);
  if ($w <= 0 || $h <= 0) { @imagedestroy($im); return false; }

  $side = min($w, $h);
  $sx = (int)floor(($w - $side)/2);
  $sy = (int)floor(($h - $side)/2);

  $dst = imagecreatetruecolor($size, $size);
  $white = imagecolorallocate($dst, 255, 255, 255);
  imagefilledrectangle($dst, 0, 0, $size, $size, $white);
  imagecopyresampled($dst, $im, 0, 0, $sx, $sy, $size, $size, $side, $side);

  $ok = @imagejpeg($dst, $dstPath, 82);
  @imagedestroy($dst);
  @imagedestroy($im);
  return (bool)$ok;
}

/* ------------------ Endpoints: thumb + unassigned img/meta ------------------ */
if (isset($_GET['thumb']) && (string)$_GET['thumb'] === '1') {
  requireLoginForAllOrRedirect();

  $size = max(40, min(220, normalizeInt($_GET['s'] ?? 72)));
  $nlId = normalizeInt($_GET['nl'] ?? 0);
  $file = attachmentBasename((string)($_GET['file'] ?? ''));
  $uId  = normalizeInt($_GET['u'] ?? 0);

  $src = '';
  $cacheKey = '';

  if ($uId > 0) {
    if (!unassignedTableExists($pdo)) { http_response_code(404); echo "no_table"; exit; }
    $st = $pdo->prepare("SELECT file_path FROM node_location_unassigned_attachments WHERE id=? LIMIT 1");
    $st->execute([$uId]);
    $bn = attachmentBasename((string)($st->fetchColumn() ?: ''));
    if ($bn !== '') {
      $src = $UNASSIGNED_DIR . DIRECTORY_SEPARATOR . $bn;
      $cacheKey = 'u_' . $uId . '_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $bn);
    }
  } else {
    if ($file === '' || $nlId <= 0) { http_response_code(400); echo "bad"; exit; }
    $abs = findAttachmentFilePath($file, $nlId, $STORAGE_NODE_DIR, $PUBLIC_UPLOADS_DIR);
    if ($abs !== '') {
      $src = $abs;
      $cacheKey = 'nl_' . $nlId . '_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $file);
    }
  }

  if ($src === '' || !is_file($src)) { http_response_code(404); echo "not_found"; exit; }

  $dst = thumbCachePath($THUMB_DIR, $cacheKey, $size);
  if (!is_file($dst) || filemtime($dst) < filemtime($src)) @makeThumbJpeg($src, $dst, $size);
  if (!is_file($dst)) { http_response_code(500); echo "thumb_failed"; exit; }

  header('Content-Type: image/jpeg');
  header('Cache-Control: private, max-age=86400');
  header('X-Content-Type-Options: nosniff');
  header('Content-Length: ' . (string)filesize($dst));
  readfile($dst);
  exit;
}

if (isset($_GET['action']) && (string)$_GET['action'] === 'img_u') {
  requireLoginForAllOrRedirect();
  $id = normalizeInt($_GET['id'] ?? 0);
  if ($id <= 0 || !unassignedTableExists($pdo)) { http_response_code(404); echo "not_found"; exit; }

  $st = $pdo->prepare("SELECT file_path FROM node_location_unassigned_attachments WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $bn = attachmentBasename((string)($st->fetchColumn() ?: ''));
  if ($bn === '') { http_response_code(404); echo "not_found"; exit; }

  $abs = $UNASSIGNED_DIR . DIRECTORY_SEPARATOR . $bn;
  if (!is_file($abs)) { http_response_code(404); echo "not_found"; exit; }

  header('Content-Type: ' . detectMimeFromPath($abs));
  header('Cache-Control: private, max-age=0, no-store');
  header('X-Content-Type-Options: nosniff');
  header('Content-Length: ' . (string)filesize($abs));
  readfile($abs);
  exit;
}

if (isset($_GET['action']) && (string)$_GET['action'] === 'meta_u') {
  requireLoginForAllOrRedirect();
  $id = normalizeInt($_GET['id'] ?? 0);
  if ($id <= 0 || !unassignedTableExists($pdo)) { http_response_code(404); echo "not_found"; exit; }

  $st = $pdo->prepare("SELECT * FROM node_location_unassigned_attachments WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); echo "not_found"; exit; }

  $metaJson = (string)($row['metadata_json'] ?? '');
  jsonResponse([
    'ok'=>true,
    'id'=>(int)$row['id'],
    'created_at'=>(string)($row['created_at'] ?? ''),
    'created_by'=>(string)($row['created_by'] ?? ''),
    'mime_type'=>(string)($row['mime_type'] ?? ''),
    'file_size'=>(int)($row['file_size'] ?? 0),
    'checksum_sha256'=>(string)($row['checksum_sha256'] ?? ''),
    'caption'=>(string)($row['caption'] ?? ''),
    'description'=>(string)($row['description'] ?? ''),
    'lat'=>$row['lat'],
    'lon'=>$row['lon'],
    'metadata'=>$metaJson !== '' ? json_decode($metaJson, true) : null,
  ]);
}

/* ------------------ Upload helpers ------------------ */
$MAX_BYTES = 12 * 1024 * 1024;
$ALLOWED_MIME = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
];

function extractImageMetadata(string $filePath, string $mime): array {
  $meta = [
    'mime' => $mime,
    'filesize' => @filesize($filePath) ?: null,
    'sha256' => @hash_file('sha256', $filePath) ?: null,
    'image' => null,
    'exif' => null,
  ];
  $img = @getimagesize($filePath);
  if (is_array($img)) {
    $meta['image'] = ['width'=>$img[0] ?? null, 'height'=>$img[1] ?? null, 'type'=>$img[2] ?? null];
  }
  if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
    $exif = @exif_read_data($filePath, null, true, false);
    if (is_array($exif)) {
      foreach (['THUMBNAIL','MakerNote'] as $bad) if (isset($exif[$bad])) unset($exif[$bad]);
      $meta['exif'] = json_decode(json_encode($exif, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
    }
  }
  return $meta;
}

function normalizeUploadFiles(array $files): array {
  $out = [];
  if (isset($files['name']) && is_array($files['name'])) {
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
      $out[] = [
        'name' => (string)($files['name'][$i] ?? ''),
        'tmp_name' => (string)($files['tmp_name'][$i] ?? ''),
        'error' => (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int)($files['size'][$i] ?? 0),
      ];
    }
  } elseif (isset($files['name'])) {
    $out[] = [
      'name' => (string)($files['name'] ?? ''),
      'tmp_name' => (string)($files['tmp_name'] ?? ''),
      'error' => (int)($files['error'] ?? UPLOAD_ERR_NO_FILE),
      'size' => (int)($files['size'] ?? 0),
    ];
  }
  return array_values(array_filter($out, fn($f) => ($f['error'] ?? 0) !== UPLOAD_ERR_NO_FILE));
}

/* ------------------ POST routing ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Viktig: auth først, så CSRF (for AJAX returnerer vi JSON 401 før vi gjør noe mer)
  requireLoginForAllOrRedirect();
  csrfCheckOrJson();

  $postAction = (string)($_POST['action'] ?? '');
  $user = nl_current_user() ?: 'ukjent';

  if ($postAction === 'upload_unassigned') {
    if (!unassignedTableExists($pdo)) jsonResponse(['ok'=>false,'error'=>'missing_unassigned_table'], 500);

    $files = [];
    if (!empty($_FILES['images'])) $files = normalizeUploadFiles($_FILES['images']);
    elseif (!empty($_FILES['image'])) $files = normalizeUploadFiles($_FILES['image']);

    if (!$files) jsonResponse([
      'ok'=>false,
      'error'=>'no_files',
      'hint'=>'Sjekk post_max_size/upload_max_filesize eller at batch ikke er for stor.'
    ], 400);

    $caption = trim((string)($_POST['caption'] ?? '')) ?: null;
    $description = trim((string)($_POST['description'] ?? '')) ?: null;
    $takenAtRaw = trim((string)($_POST['taken_at'] ?? '')) ?: null;
    $takenAtDb = $takenAtRaw ? ($takenAtRaw . ' 00:00:00') : null;

    $manualLat = trim((string)($_POST['lat'] ?? ''));
    $manualLon = trim((string)($_POST['lon'] ?? ''));
    $lat = (is_numeric($manualLat) ? (float)$manualLat : null);
    $lon = (is_numeric($manualLon) ? (float)$manualLon : null);

    $uploaded = 0;
    $failed = 0;
    $reasons = [];

    foreach ($files as $f) {
      $tmp  = (string)($f['tmp_name'] ?? '');
      $size = (int)($f['size'] ?? 0);
      $uerr = (int)($f['error'] ?? UPLOAD_ERR_OK);
      $orig = (string)($f['name'] ?? 'image');

      if ($uerr !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) { $failed++; $reasons[] = "upload_err:$uerr"; continue; }
      if ($size <= 0 || $size > $MAX_BYTES) { $failed++; $reasons[] = "size"; continue; }

      // mime
      $mime = '';
      if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
      }
      if ($mime === '' || !isset($ALLOWED_MIME[$mime])) { $failed++; $reasons[] = "mime"; continue; }

      $ext = $ALLOWED_MIME[$mime];
      $filename = 'u_' . todayYmd() . '_' . uuid16() . '.' . $ext;
      $destAbs  = $UNASSIGNED_DIR . DIRECTORY_SEPARATOR . $filename;

      $metaArr = extractImageMetadata($tmp, $mime);
      $gps = try_extract_gps($metaArr);

      $latUse = $lat; $lonUse = $lon;
      if (($latUse === null || $lonUse === null) && $gps) { $latUse = $gps['lat']; $lonUse = $gps['lon']; }

      $metadataJson = json_encode($metaArr, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

      if (!@move_uploaded_file($tmp, $destAbs)) { $failed++; $reasons[] = "move"; continue; }

      $sha = @hash_file('sha256', $destAbs) ?: null;

      $ins = $pdo->prepare("
        INSERT INTO node_location_unassigned_attachments
          (file_path, original_filename, description, taken_at, mime_type, file_size, checksum_sha256, caption, created_by, metadata_json, lat, lon)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $ins->execute([
        $filename, $orig, $description, $takenAtDb, $mime, $size, $sha, $caption, $user, $metadataJson, $latUse, $lonUse
      ]);

      $uploaded++;
    }

    jsonResponse([
      'ok'=>true,
      'uploaded'=>$uploaded,
      'failed'=>$failed,
      'reasons'=>array_slice(array_values(array_unique($reasons)), 0, 10),
      'php_limits'=>[
        'upload_max_filesize'=>ini_get('upload_max_filesize'),
        'post_max_size'=>ini_get('post_max_size'),
        'max_file_uploads'=>ini_get('max_file_uploads'),
      ]
    ]);
  }

  jsonResponse(['ok'=>false,'error'=>'bad_action'], 400);
}

/* ------------------ AJAX: data (node + unassigned) ------------------ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'data') {
  requireLoginForAllOrRedirect();

  try {
    $mode = metadataMode($pdo);

    $metaSelect = "NULL AS metadata_json";
    $metaJoin   = "";
    if ($mode === 'column') $metaSelect = "a.metadata_json AS metadata_json";
    elseif ($mode === 'table') {
      $metaSelect = "m.metadata_json AS metadata_json";
      $metaJoin = "LEFT JOIN node_location_attachment_metadata m ON m.attachment_id = a.id";
    }

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
        a.caption AS caption,
        a.taken_at AS taken_at,
        a.created_at AS created_at,
        a.created_by AS created_by,
        {$metaSelect}
      FROM node_locations nl
      JOIN node_location_attachments a ON a.node_location_id = nl.id
      {$metaJoin}
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
      $capJson = parseCaptionJson($r['caption'] ?? null);

      $gps = null;
      if (is_array($capJson)) $gps = try_extract_gps($capJson);
      if (!$gps) $gps = try_extract_gps($meta);

      $lat = null; $lon = null;
      if ($gps) { $lat = $gps['lat']; $lon = $gps['lon']; }
      elseif (is_numeric($r['node_lat'] ?? null) && is_numeric($r['node_lon'] ?? null)) { $lat = (float)$r['node_lat']; $lon = (float)$r['node_lon']; }
      if ($lat === null || $lon === null) continue;

      $nodeId = (int)$r['node_id'];
      $attId  = (int)$r['att_id'];

      $file = attachmentBasename((string)$r['file_path']);
      if ($file === '') continue;

      $legacyNl = attachmentLegacyNodeId((string)$r['file_path']);
      $nlForImg = $legacyNl > 0 ? $legacyNl : $nodeId;

      $origUrl   = appBasePath() . "/index.php?action=img&nl=" . rawurlencode((string)$nlForImg) . "&file=" . rawurlencode($file);
      $metaUrl   = appBasePath() . "/index.php?action=meta&att=" . rawurlencode((string)$attId);
      $thumbBase = selfUrl(['thumb'=>1, 'nl'=>$nlForImg, 'file'=>$file]);

      $taken = '';
      if (!empty($r['taken_at'])) $taken = (string)$r['taken_at'];
      if ($taken === '' && !empty($r['created_at'])) $taken = (string)$r['created_at'];

      $items[] = [
        'kind'=>'node','node_id'=>$nodeId,'node_name'=>(string)($r['node_name'] ?? ''),
        'city'=>(string)($r['city'] ?? ''),'partner'=>(string)($r['partner'] ?? ''),
        'att_id'=>$attId,'u_id'=>0,'filename'=>$file,'lat'=>$lat,'lon'=>$lon,'taken'=>$taken,
        'created_by'=>(string)($r['created_by'] ?? ''),'orig_url'=>$origUrl,'meta_url'=>$metaUrl,'thumb_base'=>$thumbBase,
      ];
    }

    if (unassignedTableExists($pdo)) {
      $urows = $pdo->query("
        SELECT id, file_path, taken_at, created_at, created_by, metadata_json, caption, lat, lon
        FROM node_location_unassigned_attachments
        ORDER BY created_at DESC, id DESC
      ")->fetchAll();

      foreach ($urows as $u) {
        $lat = null; $lon = null;

        if (is_numeric($u['lat'] ?? null) && is_numeric($u['lon'] ?? null)) {
          $lat = (float)$u['lat']; $lon = (float)$u['lon'];
        } else {
          $meta = [];
          $mj = (string)($u['metadata_json'] ?? '');
          if ($mj !== '') {
            $decoded = json_decode($mj, true);
            if (is_array($decoded)) $meta = $decoded;
          }
          $capJson = parseCaptionJson($u['caption'] ?? null);
          $gps = null;
          if (is_array($capJson)) $gps = try_extract_gps($capJson);
          if (!$gps) $gps = try_extract_gps($meta);
          if ($gps) { $lat = $gps['lat']; $lon = $gps['lon']; }
        }

        if ($lat === null || $lon === null) continue;

        $uId = (int)$u['id'];
        $bn  = attachmentBasename((string)$u['file_path']);
        if ($bn === '') continue;

        $origUrl   = selfUrl(['action'=>'img_u','id'=>$uId]);
        $metaUrl   = selfUrl(['action'=>'meta_u','id'=>$uId]);
        $thumbBase = selfUrl(['thumb'=>1,'u'=>$uId]);

        $taken = '';
        if (!empty($u['taken_at'])) $taken = (string)$u['taken_at'];
        if ($taken === '' && !empty($u['created_at'])) $taken = (string)$u['created_at'];

        $items[] = [
          'kind'=>'unassigned','node_id'=>0,'node_name'=>'Uten node','city'=>'','partner'=>'',
          'att_id'=>0,'u_id'=>$uId,'filename'=>$bn,'lat'=>$lat,'lon'=>$lon,'taken'=>$taken,
          'created_by'=>(string)($u['created_by'] ?? ''),'orig_url'=>$origUrl,'meta_url'=>$metaUrl,'thumb_base'=>$thumbBase,
        ];
      }
    }

    jsonResponse(['ok'=>true,'count'=>count($items),'items'=>$items]);
  } catch (Throwable $e) {
    logErr($ERROR_LOG, "AJAX data fail (bildekart): ".$e->getMessage());
    jsonResponse(['ok'=>false,'error'=>'data','message'=>$e->getMessage()], 500);
  }
}

/* ------------------ Page requires login ------------------ */
requireLoginForAllOrRedirect();

/* ------------------ HTML ------------------ */
$title = "Bildekart";
?><!doctype html>
<html lang="no">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= esc($title) ?> – teknisk.hkbb.no</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">

  <style>
    html, body { height: 100%; }
    #map { height: calc(100vh - 56px); background:#e5e7eb; }
    .thumb-icon { border-radius: 12px; overflow: hidden; border: 2px solid rgba(0,0,0,.65); background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.28); }
    .thumb-icon img { width:100%; height:100%; object-fit:cover; display:block; }
    .map-fab { position: absolute; right: 12px; top: 68px; z-index: 999; display:flex; flex-direction:column; gap:8px; }
    .progress { height: 10px; }
    .small-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
    .errbox { white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; background:#111827; color:#e5e7eb; padding:10px; border-radius:8px; }
  </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= esc(appBasePath().'/index.php') ?>">Feltobjekter</a>
    <span class="navbar-text ms-2"><?= esc($title) ?></span>
    <div class="ms-auto d-flex align-items-center gap-2">
      <span class="text-muted small"><?= esc(nl_current_name()) ?></span>
      <a class="btn btn-outline-secondary btn-sm" href="<?= esc(appBasePath().'/index.php') ?>">Til nodelokasjon</a>
    </div>
  </div>
</nav>

<div id="map" style="position:relative;">
  <div class="map-fab">
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal">Last opp bilder</button>
  </div>
</div>

<!-- Upload modal (batch/queue) -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form class="modal-content" id="uploadForm" method="post" action="<?= esc(selfUrl()) ?>" enctype="multipart/form-data">
      <div class="modal-header">
        <div class="fw-semibold">Last opp bilder (uten node) – batch</div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Lukk"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= esc(csrfToken()) ?>">
        <input type="hidden" name="action" value="upload_unassigned">

        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Velg bilder (du kan velge mange)</label>
            <input class="form-control" type="file" name="images[]" id="imagesInput" accept="image/jpeg,image/png,image/webp" multiple required>
            <div class="form-text">Opplasting skjer i batcher automatisk.</div>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Caption</label>
            <input class="form-control" name="caption" id="captionInput" placeholder="Valgfritt">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Beskrivelse</label>
            <input class="form-control" name="description" id="descInput" placeholder="Valgfritt">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Tatt (valgfritt)</label>
            <input class="form-control" type="date" name="taken_at" id="takenInput">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Manuell posisjon (valgfritt)</label>
            <div class="input-group">
              <input class="form-control" name="lat" id="uplLat" placeholder="lat">
              <input class="form-control" name="lon" id="uplLon" placeholder="lon">
              <button type="button" class="btn btn-outline-secondary" id="pickPosBtn">Velg i kart</button>
            </div>
          </div>

          <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
              <div class="text-muted small" id="uploadStatus">Ikke startet</div>
              <div class="small-mono" id="uploadCounter"></div>
            </div>
            <div class="progress mt-2">
              <div class="progress-bar" id="uploadBar" role="progressbar" style="width:0%"></div>
            </div>
            <div class="mt-2 d-none" id="uploadErrorWrap">
              <div class="text-danger small mb-1">Server-respons:</div>
              <div class="errbox" id="uploadErrorBox"></div>
            </div>
          </div>
        </div>

        <?php if (!unassignedTableExists($pdo)): ?>
          <div class="alert alert-warning mt-3 mb-0">
            Tabellen <code>node_location_unassigned_attachments</code> finnes ikke. Kjør SQL-skriptet først.
          </div>
        <?php endif; ?>

      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" id="uploadBtn" type="submit" <?= unassignedTableExists($pdo) ? '' : 'disabled' ?>>Last opp</button>
      </div>
    </form>
  </div>
</div>

<!-- Image modal (stub - ikke endret i denne fixen) -->
<div class="modal fade" id="imgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <div class="me-2">
          <div class="fw-semibold" id="modalTitle">Bilde</div>
          <div class="text-muted small" id="modalSub"></div>
        </div>
        <div class="ms-auto d-flex gap-2 align-items-center">
          <button class="btn btn-outline-secondary btn-sm" id="groupPrevBtn" type="button">←</button>
          <button class="btn btn-outline-secondary btn-sm" id="groupNextBtn" type="button">→</button>
          <span class="text-muted small" id="groupCounter"></span>
          <a class="btn btn-outline-secondary btn-sm" id="modalMetaBtn" href="#" target="_blank" rel="noopener">Vis metadata</a>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Lukk"></button>
        </div>
      </div>
      <div class="modal-body">
        <div class="text-center">
          <img id="modalImg" src="" alt="" style="max-width: 100%; max-height: 78vh; border-radius: 12px; border: 1px solid rgba(0,0,0,.1);">
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>

<script>
(() => {
  const map = L.map('map');
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 20,
    attribution: '&copy; OpenStreetMap',
    updateWhenZooming: false,
    updateWhenIdle: true,
    keepBuffer: 4
  }).addTo(map);

  map.setView([59.41, 5.27], 10);

  const cluster = L.markerClusterGroup({
    spiderfyOnMaxZoom: true,
    showCoverageOnHover: false,
    maxClusterRadius: 55,
    disableClusteringAtZoom: 16
  });

  const markers = [];
  const offsetLayer = L.layerGroup().addTo(map);
  const hiddenBase = new Set();
  const GRID_ZOOM_MIN = 17;

  const defaultIcon = L.icon({
    iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
    iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
    shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
    shadowSize: [41, 41]
  });

  function thumbSizeForZoom(z) {
    if (z >= 19) return 120;
    if (z >= 18) return 104;
    if (z >= 17) return 90;
    if (z >= 16) return 74;
    if (z >= 15) return 60;
    if (z >= 14) return 52;
    if (z >= 13) return 46;
    return 0;
  }
  function thumbUrl(d, sizePx) {
    const base = d.thumb_base || '';
    const sep = base.includes('?') ? '&' : '?';
    return base ? (base + sep + 's=' + encodeURIComponent(String(sizePx))) : d.orig_url;
  }
  function thumbDivIcon(url, sizePx) {
    const size = sizePx;
    const html = `<div class="thumb-icon" style="width:${size}px;height:${size}px">
                    <img src="${url}" alt="" loading="lazy" decoding="async">
                  </div>`;
    return L.divIcon({
      className: '',
      html,
      iconSize: [size, size],
      iconAnchor: [Math.round(size/2), Math.round(size/2)],
      popupAnchor: [0, -Math.round(size/2)]
    });
  }

  // ---------- Grouping by proximity ----------
  function groupByProximity(items, zoom) {
    const radiusPx = (zoom >= 19) ? 26 : (zoom >= 18 ? 22 : 18);
    const used = new Set();
    const groups = [];

    const pts = items.map((d, idx) => {
      const p = map.project([d.lat, d.lon], zoom);
      return { d, idx, x: p.x, y: p.y };
    });

    for (let i = 0; i < pts.length; i++) {
      if (used.has(i)) continue;
      const g = [pts[i]];
      used.add(i);
      for (let j = i + 1; j < pts.length; j++) {
        if (used.has(j)) continue;
        const dx = pts[j].x - pts[i].x;
        const dy = pts[j].y - pts[i].y;
        if ((dx*dx + dy*dy) <= (radiusPx*radiusPx)) {
          g.push(pts[j]);
          used.add(j);
        }
      }
      if (g.length > 1) groups.push(g.map(x => x.d));
    }
    return groups;
  }

  function clearHiddenBase() {
    for (const idx of Array.from(hiddenBase)) {
      const m = markers[idx]?.marker;
      if (!m) continue;
      m.setOpacity(1);
      const el = m.getElement();
      if (el) el.style.pointerEvents = '';
      hiddenBase.delete(idx);
    }
  }

  function itemKey(d) { return d.kind + ':' + (d.kind === 'unassigned' ? d.u_id : d.att_id); }

  function refreshGridAndIcons() {
    const z = map.getZoom();
    const s = thumbSizeForZoom(z);
    const bounds = map.getBounds().pad(0.25);

    // update base marker icons in view
    for (let i = 0; i < markers.length; i++) {
      const d = markers[i].data;
      if (!bounds.contains([d.lat, d.lon])) continue;
      if (hiddenBase.has(i)) continue;
      markers[i].marker.setIcon(s > 0 ? thumbDivIcon(thumbUrl(d, s), s) : defaultIcon);
    }

    offsetLayer.clearLayers();
    if (z < GRID_ZOOM_MIN || s <= 0) { clearHiddenBase(); return; }

    const inView = [];
    for (let i = 0; i < markers.length; i++) {
      const d = markers[i].data;
      if (!bounds.contains([d.lat, d.lon])) continue;
      inView.push(d);
    }

    const groups = groupByProximity(inView, z);
    clearHiddenBase();

    for (const g of groups) {
      let latSum = 0, lonSum = 0;
      for (const d of g) { latSum += d.lat; lonSum += d.lon; }
      const anchor = [latSum / g.length, lonSum / g.length];
      const p0 = map.project(anchor, z);

      // hide base markers in group
      for (const d of g) {
        const mi = markers.findIndex(x => itemKey(x.data) === itemKey(d));
        if (mi >= 0) {
          const m = markers[mi].marker;
          m.setOpacity(0);
          const el = m.getElement();
          if (el) el.style.pointerEvents = 'none';
          hiddenBase.add(mi);
        }
      }

      const n = g.length;
      const cols = Math.ceil(Math.sqrt(n));
      const rows = Math.ceil(n / cols);
      const gap = Math.round(s + 14);
      const w = (cols - 1) * gap;
      const h = (rows - 1) * gap;

      let k = 0;
      for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
          if (k >= n) break;
          const d = g[k++];

          const dx = (c * gap) - Math.round(w / 2);
          const dy = (r * gap) - Math.round(h / 2);
          const p = L.point(p0.x + dx, p0.y + dy);
          const ll = map.unproject(p, z);

          offsetLayer.addLayer(L.polyline([anchor, ll], {weight:1, opacity:0.55}));

          const iconSize = Math.min(130, s + 14);
          const m = L.marker(ll, { icon: thumbDivIcon(thumbUrl(d, iconSize), iconSize) });
          m.on('click', () => alert('Klikk på base-marker for modal (grid-klikk kan kobles på senere).'));
          offsetLayer.addLayer(m);
        }
      }
    }
  }

  let refreshTimer = null;
  function scheduleRefresh() {
    if (refreshTimer) clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
      refreshTimer = null;
      refreshGridAndIcons();
    }, 90);
  }

  map.on('zoomend', scheduleRefresh);
  map.on('moveend', scheduleRefresh);

  // -------- Upload: pick position --------
  let pickingPos = false;
  const pickPosBtn = document.getElementById('pickPosBtn');
  const uplLat = document.getElementById('uplLat');
  const uplLon = document.getElementById('uplLon');

  pickPosBtn.addEventListener('click', () => {
    pickingPos = true;
    pickPosBtn.textContent = 'Klikk i kart…';
    pickPosBtn.disabled = true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('uploadModal')).hide();
  });

  map.on('click', (e) => {
    if (!pickingPos) return;
    uplLat.value = e.latlng.lat.toFixed(7);
    uplLon.value = e.latlng.lng.toFixed(7);
    pickingPos = false;
    pickPosBtn.textContent = 'Velg i kart';
    pickPosBtn.disabled = false;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('uploadModal')).show();
  });

  // -------- Batch uploader (large amounts) --------
  const uploadForm = document.getElementById('uploadForm');
  const imagesInput = document.getElementById('imagesInput');
  const uploadBtn = document.getElementById('uploadBtn');
  const uploadBar = document.getElementById('uploadBar');
  const uploadStatus = document.getElementById('uploadStatus');
  const uploadCounter = document.getElementById('uploadCounter');
  const errWrap = document.getElementById('uploadErrorWrap');
  const errBox = document.getElementById('uploadErrorBox');

  function setProgress(done, total) {
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    uploadBar.style.width = pct + '%';
    uploadCounter.textContent = total > 0 ? `${done}/${total}` : '';
  }

  async function uploadBatch(files, extraFields) {
    const fd = new FormData();
    fd.append('csrf_token', extraFields.csrf_token);
    fd.append('action', 'upload_unassigned');
    fd.append('ajax', '1');
    if (extraFields.caption) fd.append('caption', extraFields.caption);
    if (extraFields.description) fd.append('description', extraFields.description);
    if (extraFields.taken_at) fd.append('taken_at', extraFields.taken_at);
    if (extraFields.lat) fd.append('lat', extraFields.lat);
    if (extraFields.lon) fd.append('lon', extraFields.lon);

    for (const f of files) fd.append('images[]', f, f.name);

    const res = await fetch(uploadForm.action, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    // Ekstra robusthet: om serveren redirecter (login/oversikt), vis tydelig feilmelding
    if (res.redirected) {
      throw new Error('auth_redirect\nDu er trolig logget ut. Åpne siden på nytt og logg inn.\nRedirect til: ' + res.url);
    }

    const txt = await res.text();
    let json = null;
    try { json = JSON.parse(txt); } catch(e) {}

    if (!res.ok || !json || !json.ok) {
      if (json && json.error === 'auth') {
        throw new Error('auth\n' + (json.message || 'Ikke innlogget. Logg inn på nytt.'));
      }
      throw new Error((json && (json.error || json.message))
        ? (json.error + (json.message ? (': ' + json.message) : ''))
        : ('upload_failed\n' + txt.slice(0, 1200)));
    }
    return json;
  }

  function makeBatches(files) {
    const MAX_FILES_PER_BATCH = 8;
    const MAX_BYTES_PER_BATCH = 18 * 1024 * 1024;

    const batches = [];
    let cur = [];
    let curBytes = 0;

    for (const f of files) {
      const fBytes = f.size || 0;
      const wouldExceed = (cur.length >= MAX_FILES_PER_BATCH) || (curBytes + fBytes > MAX_BYTES_PER_BATCH);
      if (cur.length && wouldExceed) {
        batches.push(cur);
        cur = [];
        curBytes = 0;
      }
      cur.push(f);
      curBytes += fBytes;
    }
    if (cur.length) batches.push(cur);
    return batches;
  }

  uploadForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    errWrap.classList.add('d-none');
    errBox.textContent = '';

    const files = Array.from(imagesInput.files || []);
    if (!files.length) return;

    uploadBtn.disabled = true;
    imagesInput.disabled = true;
    uploadStatus.textContent = 'Starter opplasting…';
    setProgress(0, files.length);

    const extra = {
      csrf_token: uploadForm.querySelector('input[name="csrf_token"]').value,
      caption: document.getElementById('captionInput').value || '',
      description: document.getElementById('descInput').value || '',
      taken_at: document.getElementById('takenInput').value || '',
      lat: uplLat.value || '',
      lon: uplLon.value || ''
    };

    let processed = 0;
    let uploaded = 0;
    let failed = 0;

    try {
      const batches = makeBatches(files);

      for (let i = 0; i < batches.length; i++) {
        uploadStatus.textContent = `Laster opp batch ${i+1}/${batches.length}…`;
        const batch = batches[i];
        const r = await uploadBatch(batch, extra);

        uploaded += (r.uploaded || 0);
        failed += (r.failed || 0);

        processed += batch.length;
        setProgress(processed, files.length);

        if (r.php_limits) console.log('php_limits', r.php_limits);
        if (r.reasons && r.reasons.length) console.log('upload_reasons', r.reasons);
      }

      uploadStatus.textContent = failed > 0
        ? `Ferdig. Lastet opp ${uploaded}, feilet ${failed}.`
        : `Ferdig. Lastet opp ${uploaded}.`;

      await reloadData();
      scheduleRefresh();

    } catch (err) {
      console.error(err);
      uploadStatus.textContent = 'Feil under opplasting: ' + (err?.message || err);
      errWrap.classList.remove('d-none');
      errBox.textContent = String(err?.message || err);
    } finally {
      uploadBtn.disabled = false;
      imagesInput.disabled = false;
      imagesInput.value = '';
      setTimeout(() => setProgress(0, 0), 1200);
    }
  });

  // -------- Data load / reload --------
  async function reloadData() {
    cluster.clearLayers();
    for (const it of markers) { try { map.removeLayer(it.marker); } catch(e) {} }
    markers.length = 0;
    offsetLayer.clearLayers();
    hiddenBase.clear();

    const res = await fetch(`<?= esc(selfUrl(['ajax'=>'data'])) ?>`, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    });

    if (res.redirected) {
      throw new Error('auth_redirect\nDu er trolig logget ut. Åpne siden på nytt og logg inn.\nRedirect til: ' + res.url);
    }

    const txt = await res.text();
    let json = null;
    try { json = JSON.parse(txt); } catch(e) { throw new Error('data_parse_failed\n' + txt.slice(0, 1200)); }
    if (!json.ok) {
      if (json.error === 'auth') throw new Error('auth\n' + (json.message || 'Ikke innlogget.'));
      throw new Error(json.error || json.message || 'data_failed');
    }

    const items = json.items || [];
    const boundsArr = [];

    for (const d of items) {
      const s = thumbSizeForZoom(map.getZoom());
      const icon = s > 0 ? thumbDivIcon(thumbUrl(d, s), s) : defaultIcon;
      const m = L.marker([d.lat, d.lon], { icon });
      markers.push({ marker: m, data: d });
      cluster.addLayer(m);
      boundsArr.push([d.lat, d.lon]);
    }

    map.addLayer(cluster);
    if (boundsArr.length > 0) map.fitBounds(boundsArr, { padding: [30, 30] });

    setTimeout(() => {
      map.invalidateSize(true);
      scheduleRefresh();
    }, 60);
  }

  reloadData().catch(err => {
    console.error(err);
    alert('Kunne ikke laste bildedata: ' + (err?.message || err));
  });
})();
</script>

</body>
</html>