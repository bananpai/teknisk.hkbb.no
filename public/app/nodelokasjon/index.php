<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\app\nodelokasjon\index.php
// Nodelokasjon – adminside (teknisk.hkbb.no)
//
// SIKKERHET (2026-03-03):
// - ALL tilgang krever AD-pålogging (uten 2FA): liste, visning, bilder, lagring, opplasting.
//
// BILDER:
// - Ny lagring/lesing: C:\inetpub\wwwroot\teknisk.hkbb.no\storage\node_locations (utenfor public)
// - Serveres via: /<base>/index.php?action=img&file=<filename>&nl=<nodeId>
//
// METADATA (2026-03-03):
// - Metadata lagres som JSON i DB ved opplasting.
// - Visning av metadata skjer via: action=meta&att=<attachmentId>
//
// NYTT (2026-03-03):
// - Rotér bilde 90° i popup (↺/↻) og lagre automatisk (action=rotate)

declare(strict_types=1);

/* ------------------ Logging ------------------ */
$LOG_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_log';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$ERROR_LOG = $LOG_DIR . DIRECTORY_SEPARATOR . 'nodelokasjon_error.log';

set_error_handler(function($severity, $message, $file, $line) use ($ERROR_LOG) {
  $row = sprintf("[%s] PHP %s: %s in %s:%d\n", date('Y-m-d H:i:s'), $severity, $message, $file, $line);
  @file_put_contents($ERROR_LOG, $row, FILE_APPEND);
  return false;
});
register_shutdown_function(function() use ($ERROR_LOG) {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    $row = sprintf("[%s] FATAL %s: %s in %s:%d\n", date('Y-m-d H:i:s'), $e['type'], $e['message'], $e['file'], $e['line']);
    @file_put_contents($ERROR_LOG, $row, FILE_APPEND);
  }
});

/* ------------------ Helpers ------------------ */
if (!function_exists('esc')) {
  function esc($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function ensureDir(string $dir): void { if (!is_dir($dir)) @mkdir($dir, 0775, true); }
function normalizeInt($v): int { return max(0, (int)$v); }
function todayYmd(): string { return (new DateTime('now'))->format('Y-m-d'); }
function uuid16(): string { return substr(bin2hex(random_bytes(16)), 0, 16); }

function isValidLatLon(?string $lat, ?string $lon): bool {
  $lat = trim((string)$lat);
  $lon = trim((string)$lon);
  if ($lat === '' || $lon === '') return false;
  if (!is_numeric($lat) || !is_numeric($lon)) return false;
  $la = (float)$lat;
  $lo = (float)$lon;
  return ($la >= -90.0 && $la <= 90.0 && $lo >= -180.0 && $lo <= 180.0);
}

/** Dynamisk base path for denne modulen basert på faktisk URL. */
function appBasePath(): string {
  $sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  if ($sn === '') return '/app/nodelokasjon';
  $dir = str_replace('\\', '/', dirname($sn));
  $dir = rtrim($dir, '/');
  return $dir === '' ? '/' : $dir;
}
function selfUrl(array $params = []): string {
  $base = appBasePath() . '/index.php';
  $q = http_build_query($params);
  return $q ? ($base . '?' . $q) : $base;
}

/** Normaliser DB file_path til basenavn (støtter URL og /uploads/...). */
function attachmentBasename(?string $filePath): string {
  $p = trim((string)($filePath ?? ''));
  if ($p === '') return '';
  $u = @parse_url($p);
  if (is_array($u) && isset($u['path']) && $u['path']) $p = (string)$u['path'];
  $b = basename(str_replace('\\', '/', $p));
  $b = str_replace("\0", '', $b);
  return $b;
}

/** Prøv å hente node-id fra legacy path (URL/sti) */
function attachmentLegacyNodeId(?string $filePath): int {
  $p = trim((string)($filePath ?? ''));
  if ($p === '') return 0;
  $u = @parse_url($p);
  if (is_array($u) && isset($u['path']) && $u['path']) $p = (string)$u['path'];
  $p = str_replace('\\', '/', $p);

  if (preg_match('~/(?:nodelokasjon|node_locations|uploads/node_locations)/(\d+)/[^/]+$~i', $p, $m)) {
    return (int)$m[1];
  }
  return 0;
}
function logErr(string $path, string $msg): void {
  @file_put_contents($path, "[".date('Y-m-d H:i:s')."] ".$msg."\n", FILE_APPEND);
}

/** Normaliser $_FILES til en liste av filer (støtter både single og multiple upload). */
function normalizeUploadFiles(array $files): array {
  $out = [];

  if (isset($files['name']) && is_array($files['name'])) {
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
      $out[] = [
        'name' => (string)($files['name'][$i] ?? ''),
        'type' => (string)($files['type'][$i] ?? ''),
        'tmp_name' => (string)($files['tmp_name'][$i] ?? ''),
        'error' => (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int)($files['size'][$i] ?? 0),
      ];
    }
  } elseif (isset($files['name'])) {
    $out[] = [
      'name' => (string)($files['name'] ?? ''),
      'type' => (string)($files['type'] ?? ''),
      'tmp_name' => (string)($files['tmp_name'] ?? ''),
      'error' => (int)($files['error'] ?? UPLOAD_ERR_NO_FILE),
      'size' => (int)($files['size'] ?? 0),
    ];
  }

  return array_values(array_filter($out, fn($f) => !empty($f['tmp_name']) || ($f['error'] ?? 0) !== UPLOAD_ERR_NO_FILE));
}

/* ------------------ Security headers ------------------ */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* ------------------ Session + Auth gate (HARD) ------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/_auth.php';

function requireLoginForAllOrRedirect(): void {
  if (!nl_is_logged_in()) {
    $next = (string)($_SERVER['REQUEST_URI'] ?? (appBasePath().'/index.php'));
    header('Location: ' . appBasePath() . '/login.php?next=' . rawurlencode($next));
    exit;
  }
}
requireLoginForAllOrRedirect();

/* ------------------ CSRF ------------------ */
function csrfToken(): string {
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf_token'];
}
function csrfCheck(): void {
  $ok = isset($_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
  if (!$ok) { http_response_code(403); echo "CSRF-feil. Last siden på nytt."; exit; }
}

/* ------------------ DB (pdo_from_env) ------------------ */
try {
  require_once __DIR__ . '/../../../app/Support/Env.php';
  $pdo = \App\Support\pdo_from_env();
  if (!$pdo instanceof PDO) throw new RuntimeException('DB-tilkobling ga ikke PDO');
} catch (Throwable $e) {
  http_response_code(500);
  logErr($ERROR_LOG, "PDO-fail: " . $e->getMessage());
  echo "<h1>500</h1><p>DB-tilkobling feilet. Sjekk logg: <code>".esc($ERROR_LOG)."</code></p>";
  exit;
}

/* ------------------ Storage config (utenfor webroot) ------------------ */
$STORAGE_NODE_DIR = rtrim('C:\\inetpub\\wwwroot\\teknisk.hkbb.no\\storage\\node_locations', "\\/");
ensureDir($STORAGE_NODE_DIR);

$PUBLIC_UPLOADS_DIR = rtrim('C:\\inetpub\\wwwroot\\teknisk.hkbb.no\\public\\uploads\\node_locations', "\\/");

$MAX_BYTES = 12 * 1024 * 1024;
$ALLOWED_MIME = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
];

/* ------------------ Metadata storage (DB) ------------------ */
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
function ensureMetadataStorage(PDO $pdo, string $errorLog): array {
  try {
    if (columnExists($pdo, 'node_location_attachments', 'metadata_json')) {
      return ['mode' => 'column', 'table' => 'node_location_attachments', 'column' => 'metadata_json'];
    }

    if (!tableExists($pdo, 'node_location_attachment_metadata')) {
      $pdo->exec("
        CREATE TABLE node_location_attachment_metadata (
          attachment_id BIGINT UNSIGNED NOT NULL,
          metadata_json LONGTEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (attachment_id),
          CONSTRAINT fk_nl_att_meta_attachment
            FOREIGN KEY (attachment_id) REFERENCES node_location_attachments(id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      ");
    }

    if (tableExists($pdo, 'node_location_attachment_metadata')) {
      return ['mode' => 'table', 'table' => 'node_location_attachment_metadata', 'column' => 'metadata_json'];
    }
  } catch (Throwable $e) {
    logErr($errorLog, "METADATA storage ensure failed: " . $e->getMessage());
  }
  return ['mode' => 'none', 'table' => '', 'column' => ''];
}

function extractImageMetadata(string $filePath, string $mime): array {
  $meta = [
    'mime' => $mime,
    'filesize' => @filesize($filePath) ?: null,
    'sha256' => @hash_file('sha256', $filePath) ?: null,
    'image' => null,
    'exif' => null,
    'iptc' => null,
  ];

  $info = [];
  $img = @getimagesize($filePath, $info);
  if (is_array($img)) {
    $meta['image'] = [
      'width' => $img[0] ?? null,
      'height' => $img[1] ?? null,
      'type' => $img[2] ?? null,
      'bits' => $img['bits'] ?? null,
      'channels' => $img['channels'] ?? null,
    ];

    if (!empty($info['APP13'])) {
      $iptc = @iptcparse($info['APP13']);
      if (is_array($iptc)) {
        $clean = [];
        foreach ($iptc as $k => $v) {
          if (is_array($v)) $clean[$k] = array_values(array_map(fn($x) => is_string($x) ? $x : (string)$x, $v));
          else $clean[$k] = is_string($v) ? $v : (string)$v;
        }
        $meta['iptc'] = $clean;
      }
    }
  }

  if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
    $exif = @exif_read_data($filePath, null, true, false);
    if (is_array($exif)) {
      foreach (['THUMBNAIL', 'MakerNote'] as $bad) if (isset($exif[$bad])) unset($exif[$bad]);
      $meta['exif'] = json_decode(json_encode($exif, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
    }
  }

  return $meta;
}

/* ------------------ Watermark helpers ------------------ */
function ttfLooksUsable(string $fontPath): bool {
  if (!is_file($fontPath)) return false;
  if (!function_exists('imagettfbbox')) return false;
  $bb = @imagettfbbox(14, 0, $fontPath, "HKF 2026-03-03");
  if (!is_array($bb) || count($bb) < 8) return false;
  $w = abs($bb[2] - $bb[0]);
  $h = abs($bb[7] - $bb[1]);
  return ($w > 0 && $h > 0);
}
function pickTtfFont(): ?string {
  $candidates = [
    'C:\\Windows\\Fonts\\segoeui.ttf',
    'C:\\Windows\\Fonts\\segoeuib.ttf',
    'C:\\Windows\\Fonts\\arial.ttf',
    'C:\\Windows\\Fonts\\calibri.ttf',
    'C:\\Windows\\Fonts\\tahoma.ttf',
    'C:\\Windows\\Fonts\\verdana.ttf',
  ];
  foreach ($candidates as $p) if (ttfLooksUsable($p)) return $p;
  return null;
}
function toYmd(?string $dt): ?string {
  $dt = trim((string)($dt ?? ''));
  if ($dt === '') return null;
  $ts = @strtotime($dt);
  if (!$ts) return null;
  return date('Y-m-d', $ts);
}

function stampImageTwoLines(string $absPath, string $mime, string $objectName, string $dateText, string $errorLog): void {
  $objectName = trim($objectName) !== '' ? trim($objectName) : 'Objekt';
  $dateText   = trim($dateText) !== '' ? trim($dateText) : date('Y-m-d');

  $line1 = $objectName;
  $line2 = $dateText . " ©HKF"; // ingen mellomrom mellom © og HKF

  if (!function_exists('imagecreatetruecolor')) return;

  $im = null;
  try {
    if ($mime === 'image/jpeg') {
      if (!function_exists('imagecreatefromjpeg')) return;
      $im = @imagecreatefromjpeg($absPath);
    } elseif ($mime === 'image/png') {
      if (!function_exists('imagecreatefrompng')) return;
      $im = @imagecreatefrompng($absPath);
    } elseif ($mime === 'image/webp') {
      if (!function_exists('imagecreatefromwebp')) return;
      $im = @imagecreatefromwebp($absPath);
    } else return;

    if (!$im) return;

    $w = imagesx($im);
    $h = imagesy($im);
    if ($w <= 0 || $h <= 0) return;

    if (function_exists('imagealphablending') && function_exists('imagesavealpha')) {
      imagealphablending($im, true);
      imagesavealpha($im, true);
    }

    $pad = 9;
    $margin = 12;
    $bg = imagecolorallocatealpha($im, 0, 0, 0, 70);
    $fg = imagecolorallocatealpha($im, 255, 255, 255, 0);

    $fontPath = pickTtfFont();
    $useTtf = $fontPath && function_exists('imagettftext') && function_exists('imagettfbbox');

    if ($useTtf) {
      $base  = max(10, (int)round(min($w, $h) / 55));
      $size1 = $base + 1;
      $size2 = $base;

      $bbox1 = imagettfbbox($size1, 0, $fontPath, $line1);
      $bbox2 = imagettfbbox($size2, 0, $fontPath, $line2);

      $w1 = abs($bbox1[2] - $bbox1[0]);
      $h1 = abs($bbox1[7] - $bbox1[1]);
      $w2 = abs($bbox2[2] - $bbox2[0]);
      $h2 = abs($bbox2[7] - $bbox2[1]);

      $maxWidth = $w - 2*$margin - 2*$pad;

      $tries = 0;
      while (($w1 > $maxWidth || $w2 > $maxWidth) && $tries < 12) {
        $size1 = max(8, $size1 - 1);
        $size2 = max(7, $size2 - 1);
        $bbox1 = imagettfbbox($size1, 0, $fontPath, $line1);
        $bbox2 = imagettfbbox($size2, 0, $fontPath, $line2);
        $w1 = abs($bbox1[2] - $bbox1[0]);
        $h1 = abs($bbox1[7] - $bbox1[1]);
        $w2 = abs($bbox2[2] - $bbox2[0]);
        $h2 = abs($bbox2[7] - $bbox2[1]);
        $tries++;
      }

      if ($w1 > $maxWidth) {
        $ellipsis = '…';
        $short = $line1;
        for ($i = mb_strlen($short, 'UTF-8'); $i > 5; $i--) {
          $try = mb_substr($short, 0, $i, 'UTF-8') . $ellipsis;
          $bb = imagettfbbox($size1, 0, $fontPath, $try);
          $tw = abs($bb[2] - $bb[0]);
          if ($tw <= $maxWidth) { $line1 = $try; $w1 = $tw; break; }
        }
      }

      $boxW = max($w1, $w2) + 2*$pad;
      $boxH = $h1 + $h2 + $pad + 2*$pad;

      $x0 = max($margin, $w - $boxW - $margin);
      $y0 = max($margin, $h - $boxH - $margin);

      imagefilledrectangle($im, $x0, $y0, $x0 + $boxW, $y0 + $boxH, $bg);

      $textX  = $x0 + $pad;
      $yLine1 = $y0 + $pad + $h1;
      $yLine2 = $yLine1 + $pad + $h2;

      imagettftext($im, $size1, 0, $textX, $yLine1, $fg, $fontPath, $line1);
      imagettftext($im, $size2, 0, $textX, $yLine2, $fg, $fontPath, $line2);
    } else {
      $fallbackObj = @iconv('UTF-8', 'CP1252//TRANSLIT', $line1);
      if ($fallbackObj === false || $fallbackObj === '') $fallbackObj = $line1;
      $fallback2 = @iconv('UTF-8', 'CP1252//TRANSLIT', $line2);
      if ($fallback2 === false || $fallback2 === '') $fallback2 = $line2;

      $font = 2;
      $fw = imagefontwidth($font);
      $fh = imagefontheight($font);

      $w1 = $fw * strlen((string)$fallbackObj);
      $w2 = $fw * strlen((string)$fallback2);
      $boxW = max($w1, $w2) + 2*$pad;
      $boxH = ($fh * 2) + $pad + 2*$pad;

      $x0 = max($margin, $w - $boxW - $margin);
      $y0 = max($margin, $h - $boxH - $margin);

      imagefilledrectangle($im, $x0, $y0, $x0 + $boxW, $y0 + $boxH, $bg);
      imagestring($im, $font, $x0 + $pad, $y0 + $pad, (string)$fallbackObj, $fg);
      imagestring($im, $font, $x0 + $pad, $y0 + $pad + $fh + $pad, (string)$fallback2, $fg);
    }

    if ($mime === 'image/jpeg') {
      if (!function_exists('imagejpeg')) return;
      @imagejpeg($im, $absPath, 90);
    } elseif ($mime === 'image/png') {
      if (!function_exists('imagepng')) return;
      @imagepng($im, $absPath, 6);
    } elseif ($mime === 'image/webp') {
      if (!function_exists('imagewebp')) return;
      @imagewebp($im, $absPath, 85);
    }
  } catch (Throwable $e) {
    logErr($errorLog, "STAMP fail: " . $e->getMessage() . " file=" . $absPath);
  } finally {
    if (function_exists('imagedestroy') && $im) @imagedestroy($im);
  }
}

/** Finn og slett bildefil i både ny/legacy plasseringer */
function deleteAttachmentFileIfExists(string $basename, int $nlId, string $storageDir, string $publicDir): bool {
  if ($basename === '') return false;

  $candidates = [];
  $candidates[] = $storageDir . DIRECTORY_SEPARATOR . $basename;
  if ($nlId > 0) $candidates[] = $storageDir . DIRECTORY_SEPARATOR . $nlId . DIRECTORY_SEPARATOR . $basename;
  if ($nlId > 0) $candidates[] = $publicDir . DIRECTORY_SEPARATOR . $nlId . DIRECTORY_SEPARATOR . $basename;
  $candidates[] = $publicDir . DIRECTORY_SEPARATOR . $basename;

  foreach ($candidates as $p) {
    if (is_file($p)) return @unlink($p);
  }
  return false;
}

/** Finn faktisk filsti (samme kandidatlogikk som img-proxy). */
function findAttachmentFilePath(string $basename, int $nlId, string $storageDir, string $publicDir): string {
  $basename = trim($basename);
  if ($basename === '') return '';
  if (!preg_match('/^[a-zA-Z0-9._-]+$/', $basename)) return '';

  $candidates = [];
  $candidates[] = $storageDir . DIRECTORY_SEPARATOR . $basename;
  if ($nlId > 0) $candidates[] = $storageDir . DIRECTORY_SEPARATOR . $nlId . DIRECTORY_SEPARATOR . $basename;
  if ($nlId > 0) $candidates[] = $publicDir . DIRECTORY_SEPARATOR . $nlId . DIRECTORY_SEPARATOR . $basename;
  $candidates[] = $publicDir . DIRECTORY_SEPARATOR . $basename;

  foreach ($candidates as $cand) {
    if (is_file($cand)) return $cand;
  }
  return '';
}

/** Oppdater metadata_json ved rotasjon (kolonne eller egen tabell). */
function appendRotationToMetadata(PDO $pdo, int $attId, array $metaStore, string $direction, string $user, string $errorLog): void {
  try {
    $now = date('Y-m-d H:i:s');

    $json = '';
    if (($metaStore['mode'] ?? 'none') === 'column') {
      $stmt = $pdo->prepare("SELECT metadata_json FROM node_location_attachments WHERE id=? LIMIT 1");
      $stmt->execute([$attId]);
      $json = (string)($stmt->fetchColumn() ?: '');
    } elseif (($metaStore['mode'] ?? 'none') === 'table') {
      if (tableExists($pdo, 'node_location_attachment_metadata')) {
        $stmt = $pdo->prepare("SELECT metadata_json FROM node_location_attachment_metadata WHERE attachment_id=? LIMIT 1");
        $stmt->execute([$attId]);
        $json = (string)($stmt->fetchColumn() ?: '');
      }
    }

    $meta = [];
    if ($json !== '') {
      $decoded = json_decode($json, true);
      if (is_array($decoded)) $meta = $decoded;
    }

    if (!isset($meta['_edits']) || !is_array($meta['_edits'])) $meta['_edits'] = [];
    $meta['_edits'][] = [
      'type' => 'rotate',
      'direction' => $direction,
      'at' => $now,
      'by' => $user,
    ];
    // hold listen rimelig
    if (count($meta['_edits']) > 50) $meta['_edits'] = array_slice($meta['_edits'], -50);

    $newJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

    if (($metaStore['mode'] ?? 'none') === 'column') {
      $upd = $pdo->prepare("UPDATE node_location_attachments SET metadata_json=? WHERE id=? LIMIT 1");
      $upd->execute([$newJson, $attId]);
    } elseif (($metaStore['mode'] ?? 'none') === 'table') {
      if (tableExists($pdo, 'node_location_attachment_metadata')) {
        $upd = $pdo->prepare("
          INSERT INTO node_location_attachment_metadata (attachment_id, metadata_json)
          VALUES (?, ?)
          ON DUPLICATE KEY UPDATE metadata_json=VALUES(metadata_json)
        ");
        $upd->execute([$attId, $newJson]);
      }
    }
  } catch (Throwable $e) {
    logErr($errorLog, "META rotate append failed attId={$attId}: ".$e->getMessage());
  }
}

/** Rotér bildefil 90° (cw/ccw) og lagre. */
function rotateImageOnDisk(string $absPath, string $mime, string $direction, string $errorLog): bool {
  if (!function_exists('imagerotate')) return false;

  $direction = strtolower(trim($direction));
  if (!in_array($direction, ['cw','ccw'], true)) $direction = 'cw';

  // GD: angle is degrees CCW. For clockwise: -90.
  $angle = ($direction === 'cw') ? -90 : 90;

  $im = null;
  try {
    if ($mime === 'image/jpeg') {
      if (!function_exists('imagecreatefromjpeg')) return false;
      $im = @imagecreatefromjpeg($absPath);
    } elseif ($mime === 'image/png') {
      if (!function_exists('imagecreatefrompng')) return false;
      $im = @imagecreatefrompng($absPath);
    } elseif ($mime === 'image/webp') {
      if (!function_exists('imagecreatefromwebp')) return false;
      $im = @imagecreatefromwebp($absPath);
    } else {
      return false;
    }

    if (!$im) return false;

    // Preserve alpha for png/webp
    if (function_exists('imagealphablending') && function_exists('imagesavealpha')) {
      imagealphablending($im, false);
      imagesavealpha($im, true);
    }

    // Transparent background for PNG/WEBP, black for JPEG
    $bg = 0;
    if ($mime === 'image/png' || $mime === 'image/webp') {
      $bg = imagecolorallocatealpha($im, 0, 0, 0, 127);
    } else {
      $bg = imagecolorallocate($im, 0, 0, 0);
    }

    $rot = @imagerotate($im, $angle, $bg);
    if (!$rot) return false;

    if ($mime === 'image/png' || $mime === 'image/webp') {
      imagealphablending($rot, false);
      imagesavealpha($rot, true);
    }

    $ok = false;
    if ($mime === 'image/jpeg') {
      if (!function_exists('imagejpeg')) return false;
      $ok = @imagejpeg($rot, $absPath, 90);
    } elseif ($mime === 'image/png') {
      if (!function_exists('imagepng')) return false;
      $ok = @imagepng($rot, $absPath, 6);
    } elseif ($mime === 'image/webp') {
      if (!function_exists('imagewebp')) return false;
      $ok = @imagewebp($rot, $absPath, 85);
    }

    @imagedestroy($rot);
    return (bool)$ok;
  } catch (Throwable $e) {
    logErr($errorLog, "ROTATE fail: ".$e->getMessage()." file=".$absPath);
    return false;
  } finally {
    if ($im && function_exists('imagedestroy')) @imagedestroy($im);
  }
}

/* ------------------ Data helpers ------------------ */
function getTemplateIdForNodelokasjon(PDO $pdo): ?int {
  $stmt = $pdo->prepare("SELECT id FROM node_location_templates WHERE name='Nodelokasjon' LIMIT 1");
  $stmt->execute();
  $id = $stmt->fetchColumn();
  return $id ? (int)$id : null;
}

/** Hent liste med partner-verdier (til dropdown). */
function fetchPartners(PDO $pdo, ?int $templateId): array {
  if ($templateId !== null) {
    $stmt = $pdo->prepare("
      SELECT DISTINCT TRIM(COALESCE(nl.partner,'')) AS partner
      FROM node_locations nl
      WHERE nl.template_id = ?
      ORDER BY partner
    ");
    $stmt->execute([(int)$templateId]);
  } else {
    $stmt = $pdo->query("
      SELECT DISTINCT TRIM(COALESCE(nl.partner,'')) AS partner
      FROM node_locations nl
      ORDER BY partner
    ");
  }
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $out = [];
  foreach ($rows as $r) {
    $p = trim((string)($r['partner'] ?? ''));
    if ($p !== '') $out[] = $p;
  }
  return $out;
}

/** Liste – støtter q + partnerFilter. */
function fetchNodeLocations(PDO $pdo, ?int $templateId, string $q, string $partnerFilter): array {
  $q = trim($q);
  $partnerFilter = trim($partnerFilter);

  $where = [];
  $params = [];

  if ($templateId !== null) { $where[] = "nl.template_id = ?"; $params[] = (int)$templateId; }

  if ($q !== '') {
    $where[] = "(nl.name LIKE ? OR nl.slug LIKE ? OR nl.city LIKE ? OR nl.postal_code LIKE ? OR nl.partner LIKE ?)";
    $like = '%'.$q.'%';
    array_push($params, $like, $like, $like, $like, $like);
  }

  if ($partnerFilter !== '') {
    $where[] = "nl.partner = ?";
    $params[] = $partnerFilter;
  }

  $sql = "SELECT nl.* FROM node_locations nl";
  if ($where) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " ORDER BY nl.name LIMIT 2000";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchNodeLocation(PDO $pdo, int $id): ?array {
  $stmt = $pdo->prepare("SELECT * FROM node_locations WHERE id=? LIMIT 1");
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
function fetchTemplate(PDO $pdo, int $templateId): ?array {
  $stmt = $pdo->prepare("SELECT * FROM node_location_templates WHERE id=?");
  $stmt->execute([$templateId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
function fetchGroups(PDO $pdo, int $templateId): array {
  $stmt = $pdo->prepare("SELECT * FROM node_location_field_groups WHERE template_id=? ORDER BY sort_order, name");
  $stmt->execute([$templateId]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function fetchFields(PDO $pdo, int $templateId): array {
  $stmt = $pdo->prepare("
    SELECT * FROM node_location_custom_fields
    WHERE template_id=?
    ORDER BY COALESCE(group_id,0), sort_order, label
  ");
  $stmt->execute([$templateId]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function fetchOptions(PDO $pdo, array $fieldIds): array {
  if (!$fieldIds) return [];
  $in = implode(',', array_fill(0, count($fieldIds), '?'));
  $stmt = $pdo->prepare("SELECT * FROM node_location_custom_field_options WHERE field_id IN ($in) ORDER BY field_id, sort_order, opt_label");
  $stmt->execute(array_values($fieldIds));
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $out = [];
  foreach ($rows as $r) $out[(int)$r['field_id']][] = $r;
  return $out;
}
function fetchValues(PDO $pdo, int $nodeLocationId): array {
  $stmt = $pdo->prepare("SELECT * FROM node_location_custom_field_values WHERE node_location_id=?");
  $stmt->execute([$nodeLocationId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $out = [];
  foreach ($rows as $r) $out[(int)$r['field_id']] = $r;
  return $out;
}
function fetchAttachments(PDO $pdo, int $nodeLocationId): array {
  $stmt = $pdo->prepare("SELECT * FROM node_location_attachments WHERE node_location_id=? ORDER BY created_at DESC, id DESC");
  $stmt->execute([$nodeLocationId]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function fetchAttachment(PDO $pdo, int $attachmentId): ?array {
  $stmt = $pdo->prepare("SELECT * FROM node_location_attachments WHERE id=? LIMIT 1");
  $stmt->execute([$attachmentId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/* ------------------ Save helpers ------------------ */
function upsertFieldValue(PDO $pdo, int $nodeLocationId, int $fieldId, array $field, $rawValue, string $user): void {
  $stmt = $pdo->prepare("SELECT id FROM node_location_custom_field_values WHERE node_location_id=? AND field_id=? LIMIT 1");
  $stmt->execute([$nodeLocationId, $fieldId]);
  $existingId = $stmt->fetchColumn();

  $type = (string)$field['field_type'];
  $cols = [
    'value_text' => null,
    'value_number' => null,
    'value_date' => null,
    'value_datetime' => null,
    'value_bool' => null,
    'value_json' => null,
  ];

  if ($type === 'text' || $type === 'textarea' || $type === 'url') {
    $cols['value_text'] = ($rawValue === '' ? null : (string)$rawValue);
  } elseif ($type === 'number') {
    $v = trim((string)$rawValue);
    $cols['value_number'] = ($v === '' ? null : $v);
  } elseif ($type === 'date') {
    $v = trim((string)$rawValue);
    $cols['value_date'] = ($v === '' ? null : $v);
  } elseif ($type === 'datetime') {
    $v = trim((string)$rawValue);
    $cols['value_datetime'] = ($v === '' ? null : str_replace('T', ' ', $v) . ':00');
  } elseif ($type === 'bool') {
    $cols['value_bool'] = ($rawValue ? 1 : 0);
  } elseif ($type === 'select') {
    $v = trim((string)$rawValue);
    $cols['value_text'] = ($v === '' ? null : $v);
  } elseif ($type === 'multiselect') {
    $arr = is_array($rawValue) ? array_values(array_filter($rawValue, fn($x)=>$x!=='')) : [];
    $cols['value_json'] = $arr ? json_encode($arr, JSON_UNESCAPED_UNICODE) : null;
  } elseif ($type === 'json') {
    $v = trim((string)$rawValue);
    $cols['value_json'] = ($v === '' ? null : $v);
  } else {
    $cols['value_text'] = ($rawValue === '' ? null : (string)$rawValue);
  }

  if ($existingId) {
    $upd = $pdo->prepare("
      UPDATE node_location_custom_field_values
      SET value_text=?, value_number=?, value_date=?, value_datetime=?, value_bool=?, value_json=?,
          updated_by=?
      WHERE id=?
    ");
    $upd->execute([
      $cols['value_text'],
      $cols['value_number'],
      $cols['value_date'],
      $cols['value_datetime'],
      $cols['value_bool'],
      $cols['value_json'],
      $user,
      $existingId
    ]);
  } else {
    $ins = $pdo->prepare("
      INSERT INTO node_location_custom_field_values
        (node_location_id, field_id, value_text, value_number, value_date, value_datetime, value_bool, value_json, updated_by)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
      $nodeLocationId,
      $fieldId,
      $cols['value_text'],
      $cols['value_number'],
      $cols['value_date'],
      $cols['value_datetime'],
      $cols['value_bool'],
      $cols['value_json'],
      $user
    ]);
  }
}

function storeAttachment(PDO $pdo, int $nodeLocationId, string $fileToken, string $orig, ?string $caption, ?string $takenAt, string $mime, int $size, ?string $sha, string $user, ?string $metadataJson, array $metaStore, string $errorLog): int {
  $hasMetaColumn = ($metaStore['mode'] ?? 'none') === 'column';

  if ($hasMetaColumn) {
    $stmt = $pdo->prepare("
      INSERT INTO node_location_attachments
        (node_location_id, file_path, original_filename, caption, taken_at, mime_type, file_size, checksum_sha256, created_by, metadata_json)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
      $nodeLocationId,
      $fileToken,
      $orig,
      $caption,
      $takenAt ?: null,
      $mime,
      $size,
      $sha,
      $user,
      $metadataJson
    ]);
  } else {
    $stmt = $pdo->prepare("
      INSERT INTO node_location_attachments
        (node_location_id, file_path, original_filename, caption, taken_at, mime_type, file_size, checksum_sha256, created_by)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
      $nodeLocationId,
      $fileToken,
      $orig,
      $caption,
      $takenAt ?: null,
      $mime,
      $size,
      $sha,
      $user
    ]);
  }

  $attId = (int)$pdo->lastInsertId();

  if (($metaStore['mode'] ?? 'none') === 'table' && $metadataJson !== null && $metadataJson !== '') {
    try {
      $ins = $pdo->prepare("
        INSERT INTO node_location_attachment_metadata (attachment_id, metadata_json)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE metadata_json=VALUES(metadata_json)
      ");
      $ins->execute([$attId, $metadataJson]);
    } catch (Throwable $e) {
      logErr($errorLog, "METADATA insert failed attId={$attId}: " . $e->getMessage());
    }
  }

  return $attId;
}

/* ------------------ Routing ------------------ */
$action = (string)($_GET['action'] ?? 'list');
$id     = normalizeInt($_GET['id'] ?? 0);
$q      = (string)($_GET['q'] ?? '');
$partnerFilter = (string)($_GET['partner'] ?? '');

/* ------------------ Metadata storage init ------------------ */
$metaStore = ensureMetadataStorage($pdo, $ERROR_LOG);

/* ------------------ IMAGE proxy ------------------ */
if ($action === 'img') {
  requireLoginForAllOrRedirect();

  $file = attachmentBasename((string)($_GET['file'] ?? ''));
  $nlId = normalizeInt($_GET['nl'] ?? 0);

  if ($file === '') { http_response_code(400); header('Content-Type:text/plain; charset=utf-8'); echo "Missing file"; exit; }
  if (!preg_match('/^[a-zA-Z0-9._-]+$/', $file)) { http_response_code(400); header('Content-Type:text/plain; charset=utf-8'); echo "Bad filename"; exit; }

  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
  $allowedExt = ['jpg','jpeg','png','webp'];
  if (!in_array($ext, $allowedExt, true)) { http_response_code(415); header('Content-Type:text/plain; charset=utf-8'); echo "Unsupported file type"; exit; }

  $abs = findAttachmentFilePath($file, $nlId, $STORAGE_NODE_DIR, $PUBLIC_UPLOADS_DIR);
  if ($abs === '') {
    logErr($ERROR_LOG, "IMG 404: file={$file} nl={$nlId}");
    http_response_code(404);
    header('Content-Type:text/plain; charset=utf-8');
    echo "Not found";
    exit;
  }

  $mime = 'application/octet-stream';
  if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $m = (string)$finfo->file($abs);
    if ($m) $mime = $m;
  } else {
    $mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');
  }

  header('Cache-Control: private, max-age=0, no-store');
  header('Content-Type: ' . $mime);
  header('Content-Length: ' . (string)filesize($abs));
  header('X-Content-Type-Options: nosniff');

  $fp = fopen($abs, 'rb');
  if (!$fp) {
    logErr($ERROR_LOG, "IMG 500: failed open abs={$abs}");
    http_response_code(500);
    header('Content-Type:text/plain; charset=utf-8');
    echo "Failed to open";
    exit;
  }
  while (!feof($fp)) echo fread($fp, 8192);
  fclose($fp);
  exit;
}

/* ------------------ METADATA endpoint (JSON) ------------------ */
if ($action === 'meta') {
  requireLoginForAllOrRedirect();

  $attId = normalizeInt($_GET['att'] ?? 0);
  if ($attId <= 0) { http_response_code(400); header('Content-Type:application/json; charset=utf-8'); echo json_encode(['error'=>'bad_att'], JSON_UNESCAPED_UNICODE); exit; }

  $att = fetchAttachment($pdo, $attId);
  if (!$att) { http_response_code(404); header('Content-Type:application/json; charset=utf-8'); echo json_encode(['error'=>'not_found'], JSON_UNESCAPED_UNICODE); exit; }

  $metaJson = null;

  // 1) kolonne hvis finnes
  if (($metaStore['mode'] ?? 'none') === 'column' && array_key_exists('metadata_json', $att)) {
    $metaJson = (string)($att['metadata_json'] ?? '');
  }

  // 2) fallback-tabell
  if (($metaJson === null || $metaJson === '') && tableExists($pdo, 'node_location_attachment_metadata')) {
    $stmt = $pdo->prepare("SELECT metadata_json FROM node_location_attachment_metadata WHERE attachment_id=? LIMIT 1");
    $stmt->execute([$attId]);
    $metaJson = (string)($stmt->fetchColumn() ?: '');
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'attachment_id' => $attId,
    'node_location_id' => (int)($att['node_location_id'] ?? 0),
    'created_at' => (string)($att['created_at'] ?? ''),
    'created_by' => (string)($att['created_by'] ?? ''),
    'mime_type' => (string)($att['mime_type'] ?? ''),
    'file_size' => (int)($att['file_size'] ?? 0),
    'checksum_sha256' => (string)($att['checksum_sha256'] ?? ''),
    'metadata' => $metaJson !== '' ? json_decode($metaJson, true) : null,
    'metadata_raw' => $metaJson !== '' ? $metaJson : null,
    'storage_mode' => (string)($metaStore['mode'] ?? 'none'),
  ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

/* ------------------ POST ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrfCheck();
  requireLoginForAllOrRedirect();

  $postAction = (string)($_POST['action'] ?? '');
  $user = nl_current_user() ?: 'ukjent';

  if ($postAction === 'rotate_image') {
    header('Content-Type: application/json; charset=utf-8');

    $attId = normalizeInt($_POST['attachment_id'] ?? 0);
    $dir = (string)($_POST['dir'] ?? 'cw'); // cw|ccw

    if ($attId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_attachment_id'], JSON_UNESCAPED_UNICODE); exit; }

    $att = fetchAttachment($pdo, $attId);
    if (!$att) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not_found'], JSON_UNESCAPED_UNICODE); exit; }

    $nodeId = (int)($att['node_location_id'] ?? 0);
    if ($nodeId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_node'], JSON_UNESCAPED_UNICODE); exit; }

    $bn = attachmentBasename((string)($att['file_path'] ?? ''));
    if ($bn === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_filename'], JSON_UNESCAPED_UNICODE); exit; }

    $legacyNl = attachmentLegacyNodeId((string)($att['file_path'] ?? ''));
    $nlForFile = $legacyNl > 0 ? $legacyNl : $nodeId;

    $abs = findAttachmentFilePath($bn, $nlForFile, $STORAGE_NODE_DIR, $PUBLIC_UPLOADS_DIR);
    if ($abs === '') {
      logErr($ERROR_LOG, "ROTATE 404: att={$attId} file={$bn} nl={$nlForFile}");
      http_response_code(404);
      echo json_encode(['ok'=>false,'error'=>'file_not_found'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $mime = (string)($att['mime_type'] ?? '');
    if ($mime === '') {
      // fallback detect
      $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
      $mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');
    }

    $ok = rotateImageOnDisk($abs, $mime, $dir, $ERROR_LOG);
    if (!$ok) {
      http_response_code(500);
      echo json_encode(['ok'=>false,'error'=>'rotate_failed'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    // Update DB checksum + size (etter rotasjon)
    $newSize = @filesize($abs) ?: 0;
    $newSha  = @hash_file('sha256', $abs) ?: '';

    try {
      $upd = $pdo->prepare("UPDATE node_location_attachments SET file_size=?, checksum_sha256=? WHERE id=? LIMIT 1");
      $upd->execute([(int)$newSize, $newSha !== '' ? $newSha : null, $attId]);
    } catch (Throwable $e) {
      logErr($ERROR_LOG, "ROTATE DB update failed att={$attId}: ".$e->getMessage());
      // fortsetter likevel (filen er rotert)
    }

    // Append "rotate" i metadata (hvis metaStore finnes)
    if (($metaStore['mode'] ?? 'none') !== 'none') {
      appendRotationToMetadata($pdo, $attId, $metaStore, $dir, $user, $ERROR_LOG);
    }

    // Returner en cache-bust URL for å tvinge reload
    $imgUrl = selfUrl(['action'=>'img','file'=>$bn,'nl'=>$nlForFile, 'v'=>time()]);
    echo json_encode([
      'ok' => true,
      'attachment_id' => $attId,
      'dir' => $dir,
      'img_url' => $imgUrl,
      'file_size' => (int)$newSize,
      'checksum_sha256' => $newSha,
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($postAction === 'save') {
    $nodeId = normalizeInt($_POST['id'] ?? 0);
    if ($nodeId <= 0) { http_response_code(400); echo "Ugyldig ID."; exit; }

    $nl = fetchNodeLocation($pdo, $nodeId);
    if (!$nl) { http_response_code(404); echo "Fant ikke nodelokasjon."; exit; }

    $upd = $pdo->prepare("
      UPDATE node_locations
      SET description=?,
          updated_by=?
      WHERE id=?
    ");
    $upd->execute([
      trim((string)($_POST['description'] ?? '')) ?: null,
      $user,
      $nodeId
    ]);

    $templateId = (int)$nl['template_id'];
    $fields = fetchFields($pdo, $templateId);

    foreach ($fields as $f) {
      $fid = (int)$f['id'];
      $key = 'cf_' . $fid;

      if ($f['field_type'] === 'bool') $raw = isset($_POST[$key]) ? 1 : 0;
      elseif ($f['field_type'] === 'multiselect') $raw = $_POST[$key] ?? [];
      else $raw = (string)($_POST[$key] ?? '');

      upsertFieldValue($pdo, $nodeId, $fid, $f, $raw, $user);
    }

    header('Location: ' . selfUrl(['action'=>'view','id'=>$nodeId,'ok'=>1]));
    exit;
  }

  if ($postAction === 'upload') {
    $nodeId = normalizeInt($_POST['id'] ?? 0);
    if ($nodeId <= 0) { header('Location: ' . selfUrl(['action'=>'list','err'=>'bad_id'])); exit; }

    $nl = fetchNodeLocation($pdo, $nodeId);
    if (!$nl) { header('Location: ' . selfUrl(['action'=>'list','err'=>'not_found'])); exit; }

    $files = [];
    if (!empty($_FILES['images'])) $files = normalizeUploadFiles($_FILES['images']);
    elseif (!empty($_FILES['image'])) $files = normalizeUploadFiles($_FILES['image']);

    if (!$files) { header('Location: ' . selfUrl(['action'=>'view','id'=>$nodeId,'err'=>'upload'])); exit; }

    $caption = trim((string)($_POST['caption'] ?? '')) ?: null;

    $takenAtRaw = trim((string)($_POST['taken_at'] ?? '')) ?: null; // YYYY-MM-DD
    $takenAtDb = null;
    if ($takenAtRaw) $takenAtDb = $takenAtRaw . ' 00:00:00';

    $takenYmd  = toYmd($takenAtDb);
    $uploadYmd = todayYmd();
    $wmDate    = $takenYmd ?: $uploadYmd;
    $objectName = (string)($nl['name'] ?? 'Objekt');

    $uploadedAny = false;

    foreach ($files as $f) {
      $tmp  = (string)($f['tmp_name'] ?? '');
      $size = (int)($f['size'] ?? 0);
      $uerr = (int)($f['error'] ?? UPLOAD_ERR_OK);
      $orig = (string)($f['name'] ?? 'image');

      if ($uerr !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) continue;
      if ($size <= 0 || $size > $MAX_BYTES) continue;

      $mime = '';
      if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
      } else {
        $gi = @getimagesize($tmp);
        $mime = is_array($gi) && !empty($gi['mime']) ? (string)$gi['mime'] : '';
      }
      if ($mime === '' || !isset($ALLOWED_MIME[$mime])) continue;

      // 1) metadata fra ORIGINAL før move/watermark
      $metaArr = extractImageMetadata($tmp, $mime);
      $metadataJson = json_encode($metaArr, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

      $ext = $ALLOWED_MIME[$mime];
      $filename = 'nl_' . $nodeId . '_' . todayYmd() . '_' . uuid16() . '.' . $ext;

      ensureDir($STORAGE_NODE_DIR);
      $destAbs  = $STORAGE_NODE_DIR . DIRECTORY_SEPARATOR . $filename;

      if (!@move_uploaded_file($tmp, $destAbs)) continue;

      // 2) watermark
      stampImageTwoLines($destAbs, $mime, $objectName, $wmDate, $ERROR_LOG);

      $sha = @hash_file('sha256', $destAbs) ?: null;

      // 3) DB
      storeAttachment($pdo, $nodeId, $filename, $orig, $caption, $takenAtDb, $mime, $size, $sha, $user, $metadataJson, $metaStore, $ERROR_LOG);

      $uploadedAny = true;
    }

    header('Location: ' . selfUrl(['action'=>'view','id'=>$nodeId, $uploadedAny ? 'ok' : 'err' => $uploadedAny ? 'img' : 'upload']));
    exit;
  }

  if ($postAction === 'delete_image') {
    $nodeId = normalizeInt($_POST['id'] ?? 0);
    $attId  = normalizeInt($_POST['attachment_id'] ?? 0);

    if ($nodeId <= 0 || $attId <= 0) { header('Location: ' . selfUrl(['action'=>'list','err'=>'bad_delete'])); exit; }

    $att = fetchAttachment($pdo, $attId);
    if (!$att) { header('Location: ' . selfUrl(['action'=>'view','id'=>$nodeId,'err'=>'no_att'])); exit; }
    if ((int)$att['node_location_id'] !== $nodeId) { header('Location: ' . selfUrl(['action'=>'view','id'=>$nodeId,'err'=>'bad_att'])); exit; }

    $createdBy = (string)($att['created_by'] ?? '');
    $user = nl_current_user() ?: 'ukjent';
    if ($createdBy === '' || $createdBy !== $user) { header('Location: ' . selfUrl(['action'=>'view','id'=>$nodeId,'err'=>'not_owner'])); exit; }

    $bn = attachmentBasename((string)($att['file_path'] ?? ''));
    $legacyNl = attachmentLegacyNodeId((string)($att['file_path'] ?? ''));
    $nlForFile = $legacyNl > 0 ? $legacyNl : $nodeId;

    if ($bn !== '') deleteAttachmentFileIfExists($bn, $nlForFile, $STORAGE_NODE_DIR, $PUBLIC_UPLOADS_DIR);

    $del = $pdo->prepare("DELETE FROM node_location_attachments WHERE id=? LIMIT 1");
    $del->execute([$attId]);

    header('Location: ' . selfUrl(['action'=>'view','id'=>$nodeId,'ok'=>'deleted']));
    exit;
  }

  http_response_code(400);
  echo "Ugyldig handling.";
  exit;
}

/* ------------------ Page data ------------------ */
$templateId = getTemplateIdForNodelokasjon($pdo);

$view = null;
$template = null;
$groups = [];
$fields = [];
$options = [];
$values = [];
$attachments = [];
$navUrl = null;

if ($action === 'view' && $id > 0) {
  $view = fetchNodeLocation($pdo, $id);
  if (!$view) $action = 'list';
  else {
    $template = fetchTemplate($pdo, (int)$view['template_id']);
    $groups = fetchGroups($pdo, (int)$view['template_id']);
    $fields = fetchFields($pdo, (int)$view['template_id']);
    $fieldIds = array_map(fn($f)=>(int)$f['id'], $fields);
    $options = fetchOptions($pdo, $fieldIds);
    $values = fetchValues($pdo, (int)$view['id']);
    $attachments = fetchAttachments($pdo, (int)$view['id']);

    $lat = isset($view['lat']) ? (string)$view['lat'] : '';
    $lon = isset($view['lon']) ? (string)$view['lon'] : '';
    if (isValidLatLon($lat, $lon)) {
      $dest = rawurlencode(trim($lat) . ',' . trim($lon));
      $navUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . $dest;
    }
  }
}

$partners = fetchPartners($pdo, $templateId);

$list = [];
if ($action === 'list') $list = fetchNodeLocations($pdo, $templateId, $q, $partnerFilter);

function fieldValueDisplay(array $field, ?array $valRow) {
  $type = (string)$field['field_type'];
  if (!$valRow) return null;
  return match ($type) {
    'number'   => $valRow['value_number'],
    'date'     => $valRow['value_date'],
    'datetime' => $valRow['value_datetime'] ? str_replace(' ', 'T', substr((string)$valRow['value_datetime'], 0, 16)) : null,
    'bool'     => (int)($valRow['value_bool'] ?? 0),
    'json'     => $valRow['value_json'],
    'multiselect' => $valRow['value_json'] ? json_decode((string)$valRow['value_json'], true) : [],
    default    => $valRow['value_text'],
  };
}

function renderCustomField(array $field, ?array $valRow, array $optsByField, bool $editable): string {
  $fid = (int)$field['id'];
  $name = 'cf_' . $fid;
  $type = (string)$field['field_type'];
  $label = (string)$field['label'];
  $help = (string)($field['help_text'] ?? '');
  $required = ((int)$field['is_required'] === 1);

  $value = fieldValueDisplay($field, $valRow);
  $reqAttr = ($editable && $required) ? 'required' : '';
  $disAttr = $editable ? '' : 'disabled';

  $html = '<div class="cf">';
  $html .= '<label>' . esc($label) . ($required ? ' <span class="req">*</span>' : '') . '</label>';

  if ($type === 'textarea') {
    $html .= '<textarea class="inp" name="'.esc($name).'" '.$reqAttr.' '.$disAttr.'>'.esc((string)($value ?? '')).'</textarea>';
  } elseif ($type === 'number') {
    $html .= '<input class="inp" type="number" step="any" name="'.esc($name).'" value="'.esc((string)($value ?? '')).'" '.$reqAttr.' '.$disAttr.' />';
  } elseif ($type === 'date') {
    $html .= '<input class="inp" type="date" name="'.esc($name).'" value="'.esc((string)($value ?? '')).'" '.$reqAttr.' '.$disAttr.' />';
  } elseif ($type === 'datetime') {
    $html .= '<input class="inp" type="datetime-local" name="'.esc($name).'" value="'.esc((string)($value ?? '')).'" '.$reqAttr.' '.$disAttr.' />';
  } elseif ($type === 'bool') {
    $checked = ((int)($value ?? 0) === 1) ? 'checked' : '';
    $html .= '<div class="chk"><input type="checkbox" id="'.esc($name).'" name="'.esc($name).'" value="1" '.$checked.' '.$disAttr.' />'
          .  '<label for="'.esc($name).'" class="chklabel">Ja</label></div>';
  } elseif ($type === 'select') {
    $html .= '<select class="inp" name="'.esc($name).'" '.$reqAttr.' '.$disAttr.'>';
    $html .= '<option value="">— Velg —</option>';
    foreach (($optsByField[$fid] ?? []) as $o) {
      $ov = (string)$o['opt_value'];
      $ol = (string)$o['opt_label'];
      $sel = ((string)$value === $ov) ? 'selected' : '';
      $html .= '<option value="'.esc($ov).'" '.$sel.'>'.esc($ol).'</option>';
    }
    $html .= '</select>';
  } elseif ($type === 'multiselect') {
    $arr = is_array($value) ? $value : [];
    $html .= '<select class="inp" name="'.esc($name).'[]" multiple '.$disAttr.'>';
    foreach (($optsByField[$fid] ?? []) as $o) {
      $ov = (string)$o['opt_value'];
      $ol = (string)$o['opt_label'];
      $sel = in_array($ov, $arr, true) ? 'selected' : '';
      $html .= '<option value="'.esc($ov).'" '.$sel.'>'.esc($ol).'</option>';
    }
    $html .= '</select>';
    $html .= '<div class="hint">Hold Ctrl/⌘ for å velge flere</div>';
  } elseif ($type === 'url') {
    $html .= '<input class="inp" type="url" name="'.esc($name).'" value="'.esc((string)($value ?? '')).'" '.$reqAttr.' '.$disAttr.' placeholder="https://..." />';
  } elseif ($type === 'json') {
    $html .= '<textarea class="inp mono" name="'.esc($name).'" '.$reqAttr.' '.$disAttr.'>'.esc((string)($value ?? '')).'</textarea>';
  } else {
    $html .= '<input class="inp" type="text" name="'.esc($name).'" value="'.esc((string)($value ?? '')).'" '.$reqAttr.' '.$disAttr.' />';
  }

  if ($help !== '') $html .= '<div class="help">'.esc($help).'</div>';
  $html .= '</div>';
  return $html;
}

/* ------------------ HTML ------------------ */
?><!doctype html>
<html lang="no">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Nodelokasjon</title>
  <style>
    :root{--line:rgba(255,255,255,.12);--text:#e9eefc;--muted:rgba(233,238,252,.72);--btn:rgba(255,255,255,.08);--danger:#fb7185;--radius:14px}
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,#070b14,#0b1220);color:var(--text)}
    .wrap{max-width:1100px;margin:0 auto;padding:18px 14px 30px}
    .top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
    h1{margin:0;font-size:20px}
    .sub{margin:6px 0 0;color:var(--muted);font-size:13px}
    .card{background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.04));border:1px solid var(--line);border-radius:var(--radius);padding:14px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .btn{display:inline-flex;gap:10px;align-items:center;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:var(--btn);color:var(--text);text-decoration:none;cursor:pointer}
    .btn.primary{background:linear-gradient(90deg,rgba(96,165,250,.95),rgba(94,234,212,.95));color:#06101a;border-color:rgba(255,255,255,.18)}
    .btn.maps{background:linear-gradient(90deg,rgba(34,197,94,.95),rgba(59,130,246,.95));color:#06101a;border-color:rgba(255,255,255,.18)}
    .btn.danger{border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10);color:var(--text)}
    .btn.small{padding:7px 10px;border-radius:10px;font-size:12px}
    .inp{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:var(--text);outline:none}
    textarea.inp{min-height:92px;resize:vertical}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.10);text-align:left;font-size:13.5px}
    th{font-size:12px;text-transform:uppercase;letter-spacing:.35px;color:rgba(233,238,252,.82)}
    tr:hover td{background:rgba(255,255,255,.03)}
    .muted{color:var(--muted);font-size:13px}
    .grid2{display:grid;grid-template-columns:1fr;gap:10px}
    @media(min-width:900px){.grid2{grid-template-columns:1fr 1fr}}
    label{display:block;font-size:12px;color:rgba(233,238,252,.82);margin:6px 0 6px}
    .hr{height:1px;background:rgba(255,255,255,.10);margin:12px 0}
    .help,.hint{color:var(--muted);font-size:12px;margin-top:6px}
    .thumbs{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-top:10px}
    .thumb{border:1px solid rgba(255,255,255,.12);border-radius:12px;overflow:hidden;background:rgba(255,255,255,.05);color:inherit}
    .thumb img{width:100%;height:120px;object-fit:cover;display:block}
    .thumb .cap{padding:8px 10px;font-size:12px;color:rgba(233,238,252,.85);display:flex;justify-content:space-between;gap:8px;align-items:flex-start}
    .cap .meta{min-width:0}
    .cap .meta .t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
    .cap .meta .m{color:var(--muted);margin-top:2px}
    .chk{display:flex;gap:10px;align-items:center}
    .chklabel{margin:0;font-size:13px;color:rgba(233,238,252,.9)}
    .kv{display:grid;grid-template-columns:180px 1fr;gap:6px 12px;margin:10px 0 0}
    .kv .k{color:rgba(233,238,252,.82);font-size:12px}
    .kv .v{font-size:13.5px}
    .inline{display:inline}
    /* lightbox */
    .lb{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.78);z-index:9999;padding:18px}
    .lb.open{display:flex}
    .lb-panel{max-width:min(1100px, 96vw);max-height:92vh;width:100%;border:1px solid rgba(255,255,255,.16);border-radius:16px;background:rgba(10,16,28,.96);overflow:hidden;display:flex;flex-direction:column}
    .lb-top{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.10)}
    .lb-title{min-width:0}
    .lb-title .t{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .lb-title .m{font-size:12px;color:var(--muted);margin-top:2px}
    .lb-body{position:relative;display:flex;align-items:center;justify-content:center;background:#060a12;min-height:240px}
    .lb-img{max-width:100%;max-height:80vh;object-fit:contain;display:block}
    .lb-nav{position:absolute;top:0;bottom:0;width:20%;min-width:60px;display:flex;align-items:center}
    .lb-nav.prev{left:0;justify-content:flex-start}
    .lb-nav.next{right:0;justify-content:flex-end}
    .lb-nav button{margin:0 8px;border-radius:999px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.08);color:var(--text);padding:10px 12px;cursor:pointer}
    .lb-meta{display:none;padding:12px;border-top:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.04);max-height:34vh;overflow:auto}
    pre{margin:0;white-space:pre-wrap;word-break:break-word;font-size:12px;color:rgba(233,238,252,.92)}
    .toast{position:fixed;left:14px;bottom:14px;background:rgba(10,16,28,.96);border:1px solid rgba(255,255,255,.16);color:var(--text);padding:10px 12px;border-radius:12px;display:none;z-index:10000}
    .toast.show{display:block}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1>Nodelokasjon</h1>
      <div class="sub">Tilgang krever AD-pålogging (uten 2FA).</div>
    </div>
    <div class="row">
      <span class="muted">Innlogget: <?php echo esc(nl_current_name()); ?></span>
      <a class="btn" href="<?php echo esc(appBasePath() . '/login.php?logout=1'); ?>">Logg ut</a>

      <?php if (!empty($navUrl)): ?>
        <a class="btn.maps btn" href="<?php echo esc($navUrl); ?>" target="_blank" rel="noopener">🧭 Naviger til node</a>
      <?php endif; ?>

      <a class="btn" href="<?php echo esc(selfUrl(['action'=>'list'])); ?>">Liste</a>
    </div>
  </div>

  <div class="card">
  <?php if ($action === 'view' && $view): ?>
    <div class="row" style="justify-content:space-between">
      <div>
        <div class="muted">Detalj</div>
        <div style="font-size:16px;font-weight:650;margin-top:2px;"><?php echo esc($view['name']); ?></div>
        <div class="muted">ID: <?php echo (int)$view['id']; ?> • Template: <?php echo esc($template['name'] ?? (string)$view['template_id']); ?></div>

        <div class="kv">
          <div class="k">Navn</div><div class="v"><?php echo esc($view['name']); ?></div>
          <div class="k">Partner</div><div class="v"><?php echo esc((string)($view['partner'] ?? '')); ?></div>
          <div class="k">Postnr</div><div class="v"><?php echo esc((string)($view['postal_code'] ?? '')); ?></div>
          <div class="k">By</div><div class="v"><?php echo esc((string)($view['city'] ?? '')); ?></div>
        </div>
      </div>
      <div class="row">
        <a class="btn" href="<?php echo esc(selfUrl(['action'=>'list'])); ?>">Tilbake</a>
      </div>
    </div>

    <div class="hr"></div>

    <form method="post" action="?" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo esc(csrfToken()); ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo (int)$view['id']; ?>">

      <div class="grid2">
        <div style="grid-column:1/-1">
          <label>Beskrivelse</label>
          <textarea class="inp" name="description"><?php echo esc((string)($view['description'] ?? '')); ?></textarea>
        </div>
      </div>

      <?php if (!empty($fields)): ?>
        <div class="hr"></div>
        <div style="font-weight:650;margin-bottom:6px;">Custom fields</div>

        <?php
          $groupMap = [];
          foreach ($groups as $g) $groupMap[(int)$g['id']] = $g;
          $byGroup = [];
          foreach ($fields as $f) {
            $gid = (int)($f['group_id'] ?? 0);
            $byGroup[$gid][] = $f;
          }
          ksort($byGroup);
        ?>

        <?php foreach ($byGroup as $gid => $fList): ?>
          <div class="card" style="margin-top:10px;">
            <div style="font-weight:650;"><?php echo $gid && isset($groupMap[$gid]) ? esc($groupMap[$gid]['name']) : 'Øvrige'; ?></div>
            <div class="grid2" style="margin-top:8px;">
              <?php foreach ($fList as $f): ?>
                <?php
                  $fid = (int)$f['id'];
                  $valRow = $values[$fid] ?? null;
                  echo renderCustomField($f, $valRow, $options, true);
                ?>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="hr"></div>
      <button class="btn primary" type="submit">Lagre endringer</button>
    </form>

    <div class="hr"></div>
    <div style="font-weight:650;">Bilder</div>
    <div class="muted" style="margin-top:6px;">Klikk på et bilde for popup. Bruk ←/→ for å bla. "↺/↻" roterer 90° og lagrer automatisk. "Metadata" viser lagret metadata fra opplastingen.</div>

    <form method="post" action="?" enctype="multipart/form-data" style="margin-top:10px;">
      <input type="hidden" name="csrf_token" value="<?php echo esc(csrfToken()); ?>">
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="id" value="<?php echo (int)$view['id']; ?>">

      <div class="grid2">
        <div>
          <label>Velg bilder (JPG/PNG/WEBP)</label>
          <input class="inp" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required>
          <div class="help">Du kan velge flere bilder samtidig. Maks 12 MB per bilde.</div>
        </div>
        <div>
          <label>Bildetekst (caption)</label>
          <input class="inp" name="caption" placeholder="Gjelder alle bildene i denne opplastingen">
        </div>
        <div>
          <label>Tatt (valgfritt)</label>
          <input class="inp" type="date" name="taken_at">
          <div class="help">Dato brukes i vannmerke for alle bilder (ellers brukes opplastingsdato).</div>
        </div>
        <div>
          <label>&nbsp;</label>
          <button class="btn primary" type="submit">Last opp</button>
        </div>
      </div>
    </form>

    <?php if (!empty($attachments)): ?>
      <div class="thumbs" id="thumbs">
        <?php foreach ($attachments as $idx => $a): ?>
          <?php
            $cap = (string)($a['caption'] ?? '');
            $when = (string)($a['created_at'] ?? '');
            $who = (string)($a['created_by'] ?? '');

            $bn = attachmentBasename((string)($a['file_path'] ?? ''));
            $legacyNl = attachmentLegacyNodeId((string)($a['file_path'] ?? ''));
            $nlForImg = $legacyNl > 0 ? $legacyNl : (int)$view['id'];

            $imgUrl = $bn !== '' ? selfUrl(['action'=>'img','file'=>$bn,'nl'=>$nlForImg]) : '';
            $canDelete = ($who !== '') && ($who === (nl_current_user() ?: ''));
          ?>
          <?php if ($imgUrl !== ''): ?>
            <div class="thumb">
              <a href="#" class="js-open"
                 data-idx="<?php echo (int)$idx; ?>"
                 data-url="<?php echo esc($imgUrl); ?>"
                 data-att="<?php echo (int)$a['id']; ?>"
                 data-cap="<?php echo esc($cap !== '' ? $cap : 'Bilde'); ?>"
                 data-when="<?php echo esc($when); ?>"
                 data-who="<?php echo esc($who); ?>"
              >
                <img src="<?php echo esc($imgUrl); ?>" alt="<?php echo esc($cap); ?>" data-attimg="<?php echo (int)$a['id']; ?>">
              </a>
              <div class="cap">
                <div class="meta">
                  <div class="t"><?php echo $cap !== '' ? esc($cap) : 'Bilde'; ?></div>
                  <div class="m"><?php echo esc($when); ?> • <?php echo esc($who); ?></div>
                </div>

                <?php if ($canDelete): ?>
                  <form method="post" action="?" class="inline" onsubmit="return confirm('Slette dette bildet? Dette kan ikke angres.');">
                    <input type="hidden" name="csrf_token" value="<?php echo esc(csrfToken()); ?>">
                    <input type="hidden" name="action" value="delete_image">
                    <input type="hidden" name="id" value="<?php echo (int)$view['id']; ?>">
                    <input type="hidden" name="attachment_id" value="<?php echo (int)$a['id']; ?>">
                    <button class="btn danger small" type="submit">Slett</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="thumb">
              <div class="cap">
                <div class="meta">
                  <div class="t"><strong>Bilde</strong></div>
                  <div class="m">Mangler filsti/filnavn i DB</div>
                </div>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="muted" style="margin-top:10px;">Ingen bilder lastet opp enda.</div>
    <?php endif; ?>

    <!-- Lightbox -->
    <div class="lb" id="lb" aria-hidden="true">
      <div class="lb-panel" role="dialog" aria-modal="true">
        <div class="lb-top">
          <div class="lb-title">
            <div class="t" id="lbTitle">Bilde</div>
            <div class="m" id="lbMetaLine"></div>
          </div>
          <div class="row">
            <button class="btn small" type="button" id="lbRotL" title="Rotér 90° venstre">↺</button>
            <button class="btn small" type="button" id="lbRotR" title="Rotér 90° høyre">↻</button>
            <button class="btn small" type="button" id="lbMetaBtn">Metadata</button>
            <button class="btn small" type="button" id="lbClose">Lukk (Esc)</button>
          </div>
        </div>

        <div class="lb-body">
          <div class="lb-nav prev"><button type="button" id="lbPrev" aria-label="Forrige">←</button></div>
          <img class="lb-img" id="lbImg" alt="">
          <div class="lb-nav next"><button type="button" id="lbNext" aria-label="Neste">→</button></div>
        </div>

        <div class="lb-meta" id="lbMetaBox">
          <pre id="lbMetaPre">Laster…</pre>
        </div>
      </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
      (function(){
        const lb = document.getElementById('lb');
        const lbImg = document.getElementById('lbImg');
        const lbTitle = document.getElementById('lbTitle');
        const lbMetaLine = document.getElementById('lbMetaLine');
        const lbClose = document.getElementById('lbClose');
        const lbPrev = document.getElementById('lbPrev');
        const lbNext = document.getElementById('lbNext');
        const metaBtn = document.getElementById('lbMetaBtn');
        const metaBox = document.getElementById('lbMetaBox');
        const metaPre = document.getElementById('lbMetaPre');
        const rotL = document.getElementById('lbRotL');
        const rotR = document.getElementById('lbRotR');
        const toast = document.getElementById('toast');

        const csrf = <?php echo json_encode(csrfToken()); ?>;
        const rotateEndpoint = <?php echo json_encode(selfUrl()); ?>; // POST til samme fil

        const items = Array.from(document.querySelectorAll('.js-open')).map(a => (<?php /* keep in sync */ ?>{
          url: a.dataset.url,
          att: parseInt(a.dataset.att || '0', 10),
          cap: a.dataset.cap || 'Bilde',
          when: a.dataset.when || '',
          who: a.dataset.who || ''
        }));

        let idx = -1;
        let metaLoadedForAtt = 0;
        let busy = false;

        function showToast(msg){
          toast.textContent = msg;
          toast.classList.add('show');
          setTimeout(()=>toast.classList.remove('show'), 1400);
        }

        function withCacheBust(url){
          try{
            const u = new URL(url, window.location.origin);
            u.searchParams.set('v', String(Date.now()));
            return u.toString();
          }catch(e){
            const sep = url.includes('?') ? '&' : '?';
            return url + sep + 'v=' + Date.now();
          }
        }

        function openAt(i){
          if (!items.length) return;
          idx = (i + items.length) % items.length;

          const it = items[idx];
          lbTitle.textContent = it.cap || 'Bilde';
          lbMetaLine.textContent = (it.when ? it.when : '') + (it.who ? (' • ' + it.who) : '');

          lbImg.src = withCacheBust(it.url);
          lbImg.alt = it.cap || 'Bilde';

          metaBox.style.display = 'none';
          metaPre.textContent = 'Laster…';
          metaLoadedForAtt = 0;

          lb.classList.add('open');
          lb.setAttribute('aria-hidden','false');
        }

        function close(){
          lb.classList.remove('open');
          lb.setAttribute('aria-hidden','true');
          lbImg.src = '';
          metaBox.style.display = 'none';
          metaPre.textContent = '';
          metaLoadedForAtt = 0;
          idx = -1;
          busy = false;
        }

        function prev(){ if (idx >= 0) openAt(idx - 1); }
        function next(){ if (idx >= 0) openAt(idx + 1); }

        async function toggleMeta(){
          if (idx < 0) return;
          const it = items[idx];

          if (metaBox.style.display === 'block') {
            metaBox.style.display = 'none';
            return;
          }

          metaBox.style.display = 'block';

          if (metaLoadedForAtt === it.att && metaPre.textContent && metaPre.textContent !== 'Laster…') return;

          metaPre.textContent = 'Laster…';
          metaLoadedForAtt = it.att;

          try {
            const url = <?php echo json_encode(selfUrl(['action'=>'meta'])); ?> + '&att=' + encodeURIComponent(String(it.att));
            const res = await fetch(url, {headers: {'Accept':'application/json'}});
            const data = await res.json();
            metaPre.textContent = JSON.stringify(data, null, 2);
          } catch (e) {
            metaPre.textContent = 'Kunne ikke hente metadata.';
          }
        }

        function setBusy(on){
          busy = on;
          rotL.disabled = on;
          rotR.disabled = on;
          metaBtn.disabled = on;
          lbPrev.disabled = on;
          lbNext.disabled = on;
        }

        async function rotate(dir){
          if (idx < 0 || busy) return;
          const it = items[idx];
          if (!it.att) return;

          setBusy(true);
          showToast('Roterer…');

          try{
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'rotate_image');
            fd.append('attachment_id', String(it.att));
            fd.append('dir', dir);

            const res = await fetch(rotateEndpoint, {
              method: 'POST',
              body: fd,
              headers: {'Accept': 'application/json'}
            });

            const data = await res.json().catch(()=>null);
            if (!res.ok || !data || !data.ok){
              throw new Error((data && data.error) ? data.error : 'rotate_failed');
            }

            // Oppdater URL i items og refresh bilde + thumb
            it.url = data.img_url || withCacheBust(it.url);
            lbImg.src = withCacheBust(it.url);

            // Oppdater thumb som matcher attachment-id
            const thumbImg = document.querySelector('img[data-attimg="'+String(it.att)+'"]');
            if (thumbImg){
              thumbImg.src = withCacheBust(it.url);
            }

            showToast('Lagret ✓');
          }catch(e){
            showToast('Kunne ikke rotere');
          }finally{
            setBusy(false);
          }
        }

        document.addEventListener('click', (e) => {
          const a = e.target.closest('.js-open');
          if (!a) return;
          e.preventDefault();
          const i = parseInt(a.dataset.idx || '0', 10);
          openAt(i);
        });

        lbClose.addEventListener('click', close);
        lbPrev.addEventListener('click', prev);
        lbNext.addEventListener('click', next);
        metaBtn.addEventListener('click', toggleMeta);
        rotL.addEventListener('click', () => rotate('ccw'));
        rotR.addEventListener('click', () => rotate('cw'));

        lb.addEventListener('click', (e) => {
          if (e.target === lb) close();
        });

        document.addEventListener('keydown', (e) => {
          if (!lb.classList.contains('open')) return;
          if (e.key === 'Escape') { e.preventDefault(); close(); }
          else if (e.key === 'ArrowLeft') { e.preventDefault(); if (!busy) prev(); }
          else if (e.key === 'ArrowRight') { e.preventDefault(); if (!busy) next(); }
        });
      })();
    </script>

  <?php else: ?>
    <form method="get" action="?" class="row">
      <input type="hidden" name="action" value="list">

      <input class="inp" name="q" value="<?php echo esc($q); ?>" placeholder="Søk (navn, by, partner …)" style="max-width:420px">

      <select class="inp" name="partner" style="max-width:280px">
        <option value="">Alle partnere</option>
        <?php foreach ($partners as $p): ?>
          <option value="<?php echo esc($p); ?>" <?php echo ($partnerFilter === $p) ? 'selected' : ''; ?>><?php echo esc($p); ?></option>
        <?php endforeach; ?>
      </select>

      <button class="btn" type="submit">Filtrer</button>
      <a class="btn" href="<?php echo esc(selfUrl(['action'=>'list'])); ?>">Nullstill</a>
    </form>

    <table>
      <thead>
        <tr>
          <th>Navn</th>
          <th>By</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($list as $r): ?>
          <tr style="cursor:pointer" onclick="location.href='<?php echo esc(selfUrl(['action'=>'view','id'=>(int)$r['id']])); ?>'">
            <td><?php echo esc($r['name']); ?></td>
            <td><?php echo esc($r['city']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="muted" style="margin-top:10px;">
      Feilsøk: logg i <code><?php echo esc($ERROR_LOG); ?></code>
    </div>
  <?php endif; ?>
  </div>
</div>
</body>
</html>