<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\pages\image_upload.php
// Unassigned image upload UI (AJAX + batch + progress)
//
// Lagring (UNASSIGNED):
//   Original : storage\unassigned\<dato>\<random>.<ext>
//   Thumb    : storage\unassigned\<dato>\<random>_thumb.<ext>
//
// DB (node_location_unassigned_attachments):
//   file_path  = "<dato>/<random>.<ext>"
//   thumb_path = "<dato>/<random>_thumb.<ext>"
//
// Visning:
//   /api/image_upload_api.php?action=thumb&id=123
//   /api/image_upload_api.php?action=img&id=123
//
// OPPDATERT 2026-03-09:
// - Batch-valg av flere bilder samtidig
// - Egen batch-kobling mot nodelokasjon med autosuggest
// - Gjenbrukbar mappe-funksjon for enkeltbilde og flerbilde
// - Mindre thumbnails i grid
// - Valgmarkering flyttet under bildet (ingen hvit overlay/slør)
// - "Velg alle på siden" / "Fjern valg"
// - "Velg alle fra samme dato" på bildekort
// - "Velg alle fra valgt dato" i batch-panelet

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ------------------ Konfig ------------------ */
const THUMB_MAX_DIM = 300;
const LIST_PAGE_SIZE = 120;

/* ------------------ Helpers (tidlig) ------------------ */
if (!function_exists('esc')) {
    function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('is_ajax_request_early')) {
    function is_ajax_request_early(): bool {
        $hdr = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        if (strcasecmp($hdr, 'xmlhttprequest') === 0) return true;
        if ((string)($_POST['ajax'] ?? '') === '1') return true;
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (stripos($accept, 'application/json') !== false) return true;
        return false;
    }
}

if (!function_exists('json_out')) {
    function json_out(array $payload, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$__IS_AJAX = is_ajax_request_early();

/* ------------------ Logging ------------------ */
$LOG_DIR = __DIR__ . DIRECTORY_SEPARATOR . '_log';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);
$ERR_LOG = $LOG_DIR . DIRECTORY_SEPARATOR . 'image_upload_error.log';

/* ------------------ Robust error handling for AJAX ------------------ */
set_exception_handler(function(\Throwable $e) use ($__IS_AJAX, $ERR_LOG) {
    @file_put_contents($ERR_LOG, "[" . date('c') . "] EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND);
    if ($__IS_AJAX && !headers_sent()) {
        json_out(['ok' => false, 'error' => 'Serverfeil (exception): ' . $e->getMessage()], 500);
    }
    http_response_code(500);
    echo "<div style='padding:16px;font-family:system-ui'>Serverfeil: " . esc($e->getMessage()) . "</div>";
    exit;
});

register_shutdown_function(function() use ($__IS_AJAX, $ERR_LOG) {
    $err = error_get_last();
    if (!$err) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$err['type'], $fatalTypes, true)) return;

    $msg = "FATAL: {$err['message']} in {$err['file']}:{$err['line']}";
    @file_put_contents($ERR_LOG, "[" . date('c') . "] " . $msg . "\n\n", FILE_APPEND);

    if ($__IS_AJAX && !headers_sent()) {
        json_out(['ok' => false, 'error' => 'Serverfeil (fatal): ' . $err['message']], 500);
    }
});

/* ------------------ Auth (robust) ------------------ */
if (!function_exists('require_login')) {
    foreach ([
        __DIR__ . '/../../core/auth.php',
        __DIR__ . '/../core/auth.php',
        __DIR__ . '/../../app/auth.php',
        __DIR__ . '/../inc/auth.php',
        __DIR__ . '/../../app/bootstrap.php',
        __DIR__ . '/../../app/Bootstrap.php',
    ] as $p) {
        if (is_file($p)) { require_once $p; break; }
    }
}

if (function_exists('require_login')) {
    require_login();
} else {
    if (empty($_SESSION['username'])) {
        header('Location: /?page=login');
        exit;
    }
}

/* ------------------ DB (robust) ------------------ */
if (!function_exists('get_pdo_connection')) {
    function get_pdo_connection(): PDO {
        if (function_exists('pdo')) {
            $pdo = pdo();
            if ($pdo instanceof PDO) return $pdo;
        }

        foreach ([
            dirname(__DIR__, 2) . '/app/database.php',
            dirname(__DIR__, 2) . '/app/Database.php',
            dirname(__DIR__, 2) . '/app/Db.php',
        ] as $p) {
            if (is_file($p)) { require_once $p; break; }
        }
        if (class_exists('App\\Database') && method_exists('App\\Database', 'getConnection')) {
            $pdo = \App\Database::getConnection();
            if ($pdo instanceof PDO) return $pdo;
        }
        if (class_exists('Database') && method_exists('Database', 'getConnection')) {
            $pdo = \Database::getConnection();
            if ($pdo instanceof PDO) return $pdo;
        }

        $envPath = dirname(__DIR__, 2) . '/app/Support/Env.php';
        if (is_file($envPath)) require_once $envPath;
        if (function_exists('pdo_from_env')) {
            $pdo = pdo_from_env();
            if ($pdo instanceof PDO) return $pdo;
        }

        throw new RuntimeException('Fant ingen DB-connector (pdo()/App\\Database::getConnection()/pdo_from_env()).');
    }
}

try {
    $pdo = get_pdo_connection();
} catch (\Throwable $e) {
    @file_put_contents($ERR_LOG, "[" . date('c') . "] DB_CONNECT_FAIL: " . $e->getMessage() . "\n\n", FILE_APPEND);
    if ($__IS_AJAX) json_out(['ok' => false, 'error' => 'DB-tilkobling feilet: ' . $e->getMessage()], 500);
    http_response_code(500);
    echo "<div style='padding:16px;font-family:system-ui'>DB-tilkobling feilet: " . esc($e->getMessage()) . "</div>";
    exit;
}

/* ------------------ Helpers (resten) ------------------ */
if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool { return is_ajax_request_early(); }
}

if (!function_exists('unassigned_base_dir')) {
    function unassigned_base_dir(): string {
        $root = dirname(__DIR__, 2);
        return rtrim($root, "\\/") . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'unassigned';
    }
}

if (!function_exists('node_locations_base_dir')) {
    function node_locations_base_dir(): string {
        $root = dirname(__DIR__, 2);
        return rtrim($root, "\\/") . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'node_locations';
    }
}

if (!function_exists('ensure_dir')) {
    function ensure_dir(string $dir): void {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }
}

if (!function_exists('random_token')) {
    function random_token(int $len = 16): string {
        return bin2hex(random_bytes($len));
    }
}

if (!function_exists('detect_mime')) {
    function detect_mime(string $tmp): string {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return (string)($finfo->file($tmp) ?: 'application/octet-stream');
    }
}

if (!function_exists('ext_from_mime_or_name')) {
    function ext_from_mime_or_name(string $mime, string $origName): string {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION) ?: '');
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        if (isset($map[$mime])) return $map[$mime];
        if (preg_match('/^(jpg|jpeg|png|webp|gif)$/i', $ext)) return ($ext === 'jpeg') ? 'jpg' : $ext;
        return 'bin';
    }
}

if (!function_exists('gd_load')) {
    function gd_load(string $file, string $mime) {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($file),
            'image/png'              => @imagecreatefrompng($file),
            'image/webp'             => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            'image/gif'              => @imagecreatefromgif($file),
            default                  => false,
        };
    }
}

if (!function_exists('gd_save')) {
    function gd_save($im, string $file, string $mime, int $qualityJpeg = 82): bool {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => @imagejpeg($im, $file, $qualityJpeg),
            'image/png'               => @imagepng($im, $file, 6),
            'image/webp'              => function_exists('imagewebp') ? @imagewebp($im, $file, 82) : false,
            'image/gif'               => @imagegif($im, $file),
            default                   => false,
        };
    }
}

if (!function_exists('gd_flip_fallback')) {
    function gd_flip_fallback($im, int $mode) {
        $w = imagesx($im);
        $h = imagesy($im);
        $dst = imagecreatetruecolor($w, $h);

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);

        if ($mode === IMG_FLIP_HORIZONTAL) {
            for ($x = 0; $x < $w; $x++) imagecopy($dst, $im, $w - $x - 1, 0, $x, 0, 1, $h);
        } elseif ($mode === IMG_FLIP_VERTICAL) {
            for ($y = 0; $y < $h; $y++) imagecopy($dst, $im, 0, $h - $y - 1, 0, $y, $w, 1);
        } elseif ($mode === IMG_FLIP_BOTH) {
            for ($x = 0; $x < $w; $x++) {
                for ($y = 0; $y < $h; $y++) {
                    $col = imagecolorat($im, $x, $y);
                    imagesetpixel($dst, $w - $x - 1, $h - $y - 1, $col);
                }
            }
        } else {
            imagedestroy($dst);
            return $im;
        }

        imagedestroy($im);
        return $dst;
    }
}

if (!function_exists('gd_flip')) {
    function gd_flip($im, int $mode) {
        if (function_exists('imageflip')) {
            @imageflip($im, $mode);
            return $im;
        }
        return gd_flip_fallback($im, $mode);
    }
}

if (!function_exists('gd_apply_exif_orientation_if_needed')) {
    function gd_apply_exif_orientation_if_needed($im, string $jpegFile) {
        if (!function_exists('exif_read_data')) return $im;

        $orientation = null;
        try {
            $exif = @exif_read_data($jpegFile, 'IFD0', true, false);
            if (is_array($exif) && isset($exif['IFD0']['Orientation'])) {
                $orientation = (int)$exif['IFD0']['Orientation'];
            }
        } catch (\Throwable $e) {
            $orientation = null;
        }

        if (!$orientation || $orientation === 1) return $im;

        switch ($orientation) {
            case 2: $im = gd_flip($im, IMG_FLIP_HORIZONTAL); break;
            case 3:
                $rot = @imagerotate($im, 180, 0);
                if ($rot) { imagedestroy($im); $im = $rot; }
                break;
            case 4: $im = gd_flip($im, IMG_FLIP_VERTICAL); break;
            case 5:
                $rot = @imagerotate($im, 270, 0);
                if ($rot) { imagedestroy($im); $im = $rot; }
                $im = gd_flip($im, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $rot = @imagerotate($im, 270, 0);
                if ($rot) { imagedestroy($im); $im = $rot; }
                break;
            case 7:
                $rot = @imagerotate($im, 90, 0);
                if ($rot) { imagedestroy($im); $im = $rot; }
                $im = gd_flip($im, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $rot = @imagerotate($im, 90, 0);
                if ($rot) { imagedestroy($im); $im = $rot; }
                break;
            default:
                break;
        }

        return $im;
    }
}

if (!function_exists('make_thumb_same_format')) {
    function make_thumb_same_format(string $srcFile, string $srcMime, string $dstFile, int $maxDim = THUMB_MAX_DIM): bool {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) return false;

        $im = gd_load($srcFile, $srcMime);
        if (!$im) return false;

        if (in_array(strtolower($srcMime), ['image/jpeg','image/jpg'], true)) {
            $im = gd_apply_exif_orientation_if_needed($im, $srcFile);
        }

        $w = imagesx($im);
        $h = imagesy($im);
        if ($w <= 0 || $h <= 0) { imagedestroy($im); return false; }

        $scale = min($maxDim / $w, $maxDim / $h, 1.0);
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));

        $thumb = imagecreatetruecolor($nw, $nh);
        if (!$thumb) { imagedestroy($im); return false; }

        if (in_array(strtolower($srcMime), ['image/png','image/webp'], true)) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefill($thumb, 0, 0, $transparent);
        } else {
            $white = imagecolorallocate($thumb, 255, 255, 255);
            imagefill($thumb, 0, 0, $white);
        }

        imagecopyresampled($thumb, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ensure_dir(dirname($dstFile));

        $ok = gd_save($thumb, $dstFile, $srcMime, 82);

        imagedestroy($thumb);
        imagedestroy($im);

        return (bool)$ok;
    }
}

if (!function_exists('apply_badge_to_original')) {
    function apply_badge_to_original(string $file, string $mime, string $badgeText): bool {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagestring')) return false;

        $im = gd_load($file, $mime);
        if (!$im) return false;

        if (in_array(strtolower($mime), ['image/jpeg','image/jpg'], true)) {
            $im = gd_apply_exif_orientation_if_needed($im, $file);
        }

        $w = imagesx($im);
        $h = imagesy($im);
        if ($w <= 0 || $h <= 0) { imagedestroy($im); return false; }

        $font = 3;
        $txtW = imagefontwidth($font) * strlen($badgeText);
        $txtH = imagefontheight($font);

        $padX = 8; $padY = 6; $margin = 8;

        $boxW = $txtW + ($padX * 2);
        $boxH = $txtH + ($padY * 2);

        if ($boxW > ($w - 6)) {
            $font = 2;
            $txtW = imagefontwidth($font) * strlen($badgeText);
            $txtH = imagefontheight($font);
            $boxW = $txtW + ($padX * 2);
            $boxH = $txtH + ($padY * 2);
            if ($boxW > ($w - 6)) {
                $badgeText = '©HKF';
                $txtW = imagefontwidth($font) * strlen($badgeText);
                $boxW = $txtW + ($padX * 2);
            }
        }

        $x2 = $w - $margin;
        $y2 = $h - $margin;
        $x1 = max(0, $x2 - $boxW);
        $y1 = max(0, $y2 - $boxH);

        if (in_array(strtolower($mime), ['image/png','image/webp'], true)) {
            imagealphablending($im, true);
            imagesavealpha($im, true);
        }

        $bg = imagecolorallocatealpha($im, 0, 0, 0, 70);
        $fg = imagecolorallocatealpha($im, 255, 255, 255, 0);
        $shadow = imagecolorallocatealpha($im, 0, 0, 0, 40);

        imagefilledrectangle($im, $x1, $y1, $x2, $y2, $bg);

        $tx = $x1 + $padX;
        $ty = $y1 + $padY;
        imagestring($im, $font, $tx + 1, $ty + 1, $badgeText, $shadow);
        imagestring($im, $font, $tx,     $ty,     $badgeText, $fg);

        $ok = gd_save($im, $file, $mime, 86);

        imagedestroy($im);
        return (bool)$ok;
    }
}

if (!function_exists('parse_exif_safe')) {
    function parse_exif_safe(string $file): array {
        if (!function_exists('exif_read_data')) return [];
        try {
            $exif = @exif_read_data($file, null, true, false);
            return is_array($exif) ? $exif : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('exif_to_taken_at')) {
    function exif_to_taken_at(array $exif): ?string {
        $candidates = [
            $exif['EXIF']['DateTimeOriginal'] ?? null,
            $exif['EXIF']['DateTimeDigitized'] ?? null,
            $exif['IFD0']['DateTime'] ?? null,
        ];
        foreach ($candidates as $v) {
            if (!is_string($v) || trim($v) === '') continue;
            $v = trim($v);
            if (preg_match('/^\d{4}:\d{2}:\d{2}\s+\d{2}:\d{2}:\d{2}$/', $v)) {
                $v = str_replace(':', '-', substr($v, 0, 10)) . substr($v, 10);
            }
            $dt = date_create($v);
            if ($dt) return $dt->format('Y-m-d H:i:s');
        }
        return null;
    }
}

if (!function_exists('exif_gps_to_decimal')) {
    function exif_gps_to_decimal($coord, $hemisphere): ?float {
        if (!is_array($coord) || count($coord) < 3) return null;

        $toFloat = function($x): ?float {
            if (is_string($x) && str_contains($x, '/')) {
                [$n, $d] = array_map('floatval', explode('/', $x, 2));
                if ($d == 0.0) return null;
                return $n / $d;
            }
            if (is_numeric($x)) return (float)$x;
            return null;
        };

        $deg = $toFloat($coord[0]); $min = $toFloat($coord[1]); $sec = $toFloat($coord[2]);
        if ($deg === null || $min === null || $sec === null) return null;

        $val = $deg + ($min / 60.0) + ($sec / 3600.0);
        $hemisphere = strtoupper((string)$hemisphere);
        if ($hemisphere === 'S' || $hemisphere === 'W') $val *= -1.0;
        return $val;
    }
}

if (!function_exists('exif_to_latlon')) {
    function exif_to_latlon(array $exif): array {
        $gps = $exif['GPS'] ?? null;
        if (!is_array($gps)) return [null, null];

        $lat = null; $lon = null;
        if (isset($gps['GPSLatitude'], $gps['GPSLatitudeRef'])) {
            $lat = exif_gps_to_decimal($gps['GPSLatitude'], $gps['GPSLatitudeRef']);
        }
        if (isset($gps['GPSLongitude'], $gps['GPSLongitudeRef'])) {
            $lon = exif_gps_to_decimal($gps['GPSLongitude'], $gps['GPSLongitudeRef']);
        }
        return [$lat, $lon];
    }
}

if (!function_exists('safe_join_under_base')) {
    function safe_join_under_base(string $baseDir, string $relativePath): ?string {
        $baseDir = rtrim($baseDir, "\\/") . DIRECTORY_SEPARATOR;

        $rel = str_replace(['..', '\\'], ['', '/'], (string)$relativePath);
        $rel = ltrim($rel, '/');
        if ($rel === '') return null;

        $full = $baseDir . str_replace('/', DIRECTORY_SEPARATOR, $rel);

        $baseReal = realpath($baseDir);
        $dirReal  = realpath(dirname($full));
        if (!$baseReal || !$dirReal) return null;

        $baseReal = rtrim($baseReal, "\\/") . DIRECTORY_SEPARATOR;
        $dirReal  = rtrim($dirReal, "\\/") . DIRECTORY_SEPARATOR;

        if (stripos($dirReal, $baseReal) !== 0) return null;

        return $full;
    }
}

if (!function_exists('badge_text_from_taken_or_created')) {
    function badge_text_from_taken_or_created(?string $takenAt, ?string $createdAt): string {
        $src = $takenAt ?: $createdAt ?: '';
        $ts = $src ? strtotime($src) : false;
        if (!$ts) return date('d.m.Y') . ' ©HKF';
        return date('d.m.Y', $ts) . ' ©HKF';
    }
}

/* ------------------ CSRF ------------------ */
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

/* ------------------ Node lookup helpers ------------------ */
if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
        $stmt->execute([':t' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('find_node_table')) {
    function find_node_table(PDO $pdo): ?string {
        foreach (['node_locations', 'node_location', 'nodelokasjoner', 'node_lokasjoner'] as $t) {
            if (table_exists($pdo, $t)) return $t;
        }
        return null;
    }
}

if (!function_exists('get_table_columns')) {
    function get_table_columns(PDO $pdo, string $table): array {
        $stmt = $pdo->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = :t
            ORDER BY ordinal_position
        ");
        $stmt->execute([':t' => $table]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}

if (!function_exists('pick_first_existing_col')) {
    function pick_first_existing_col(array $cols, array $candidates): ?string {
        $set = [];
        foreach ($cols as $c) $set[strtolower($c)] = $c;
        foreach ($candidates as $cand) {
            $k = strtolower($cand);
            if (isset($set[$k])) return $set[$k];
        }
        return null;
    }
}

if (!function_exists('node_search')) {
    function node_search(PDO $pdo, string $q, int $limit = 15): array {
        $q = trim($q);
        if ($q === '') return [];

        $table = find_node_table($pdo);
        if (!$table) return [];

        $cols = get_table_columns($pdo, $table);

        $idCol = pick_first_existing_col($cols, ['id', 'node_location_id', 'nodeid', 'pk', 'pk_id']);
        if (!$idCol) $idCol = 'id';

        $nameCol = pick_first_existing_col($cols, ['name', 'navn', 'node_name', 'nodelokasjon', 'title']);
        $cityCol = pick_first_existing_col($cols, ['city', 'by', 'poststed', 'town']);
        $partnerCol = pick_first_existing_col($cols, ['partner', 'partner_name', 'tenant', 'kunde']);

        $selectParts = [];
        $selectParts[] = "`$idCol` AS id";
        $selectParts[] = $nameCol ? "`$nameCol` AS name" : "'' AS name";
        $selectParts[] = $cityCol ? "`$cityCol` AS city" : "'' AS city";
        $selectParts[] = $partnerCol ? "`$partnerCol` AS partner" : "'' AS partner";

        $where = [];
        $params = [':lim' => max(1, min(50, $limit))];

        $isNumeric = preg_match('/^\d+$/', $q) === 1;
        if ($isNumeric) {
            $where[] = "`$idCol` = :idExact";
            $params[':idExact'] = (int)$q;
        }

        $like = '%' . $q . '%';
        if ($nameCol) { $where[] = "`$nameCol` LIKE :q"; $params[':q'] = $like; }
        if ($cityCol) { $where[] = "`$cityCol` LIKE :q2"; $params[':q2'] = $like; }
        if ($partnerCol) { $where[] = "`$partnerCol` LIKE :q3"; $params[':q3'] = $like; }

        if (!$where) return [];

        $sql = "SELECT " . implode(", ", $selectParts) . " FROM `$table` WHERE (" . implode(" OR ", $where) . ") ORDER BY `$idCol` DESC LIMIT :lim";
        $stmt = $pdo->prepare($sql);

        foreach ($params as $k => $v) {
            if ($k === ':lim') $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
            else $stmt->bindValue($k, $v);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)($r['id'] ?? 0),
                'name' => (string)($r['name'] ?? ''),
                'city' => (string)($r['city'] ?? ''),
                'partner' => (string)($r['partner'] ?? ''),
            ];
        }
        return array_values(array_filter($out, fn($x) => (int)$x['id'] > 0));
    }
}

/* ------------------ DB helpers ------------------ */
if (!function_exists('db_count_all_unassigned')) {
    function db_count_all_unassigned(PDO $pdo): int {
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM node_location_unassigned_attachments");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        return (int)($row['c'] ?? 0);
    }
}

if (!function_exists('db_fetch_unassigned_page')) {
    function db_fetch_unassigned_page(PDO $pdo, int $page, int $pageSize): array {
        $page = max(1, $page);
        $pageSize = max(1, min(500, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $sql = "
            SELECT id, file_path, thumb_path, original_filename, description, taken_at, mime_type, file_size,
                   checksum_sha256, caption, created_by, created_at, metadata_json, lat, lon
            FROM node_location_unassigned_attachments
            ORDER BY id DESC
            LIMIT :lim OFFSET :off
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':lim', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

/* ------------------ Reusable map helper ------------------ */
if (!function_exists('map_unassigned_image_to_node')) {
    function map_unassigned_image_to_node(PDO $pdo, int $imgId, int $nodeId, string $errLog = ''): array {
        if ($imgId <= 0 || $nodeId <= 0) {
            throw new RuntimeException('Ugyldig bilde-id eller node_location_id.');
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM node_location_unassigned_attachments
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $imgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException("Fant ikke bildet #$imgId (kan allerede være mappet/slettet).");
        }

        $nodeTable = find_node_table($pdo);
        if ($nodeTable) {
            $nodeCols = get_table_columns($pdo, $nodeTable);
            $nodeIdCol = pick_first_existing_col($nodeCols, ['id', 'node_location_id', 'nodeid', 'pk', 'pk_id']) ?: 'id';
            $chk = $pdo->prepare("SELECT `$nodeIdCol` FROM `$nodeTable` WHERE `$nodeIdCol` = :nid LIMIT 1");
            $chk->execute([':nid' => $nodeId]);
            if (!$chk->fetchColumn()) {
                throw new RuntimeException("Fant ikke nodelokasjon #$nodeId.");
            }
        }

        $unBase = unassigned_base_dir();
        $nodeBase = node_locations_base_dir();

        $filePath  = (string)($row['file_path'] ?? '');
        $thumbPath = (string)($row['thumb_path'] ?? '');

        $fullOrig  = $filePath  ? safe_join_under_base($unBase, $filePath)  : null;
        $fullThumb = $thumbPath ? safe_join_under_base($unBase, $thumbPath) : null;

        if (!$fullOrig || !is_file($fullOrig)) {
            throw new RuntimeException("Originalfil mangler på disk for bilde #$imgId.");
        }

        $mime = (string)($row['mime_type'] ?? 'application/octet-stream');
        $ext = ext_from_mime_or_name($mime, (string)($row['original_filename'] ?? 'file.bin'));

        $createdAt = (string)($row['created_at'] ?? '');
        $date = $createdAt ? date('Y-m-d', strtotime($createdAt)) : date('Y-m-d');

        $token = null;
        if ($filePath) {
            $base = basename(str_replace('\\', '/', $filePath));
            $token = preg_replace('/\.[^.]+$/', '', $base);
            $token = preg_replace('/[^a-f0-9]/i', '', (string)$token);
        }
        if (!$token) $token = substr(random_token(10), 0, 12);

        $nodeDir = $nodeBase . DIRECTORY_SEPARATOR . $nodeId;
        ensure_dir($nodeDir);

        $newBaseName  = $date . '_' . $token . '.' . $ext;
        $newThumbName = $date . '_' . $token . '_thumb.' . $ext;

        $dstOrig  = $nodeDir . DIRECTORY_SEPARATOR . $newBaseName;
        $dstThumb = $nodeDir . DIRECTORY_SEPARATOR . $newThumbName;

        $newFilePath  = $nodeId . '/' . $newBaseName;
        $newThumbPath = null;

        $movedOrig = @rename($fullOrig, $dstOrig);
        if (!$movedOrig) {
            if (!@copy($fullOrig, $dstOrig)) {
                throw new RuntimeException("Kunne ikke flytte originalfil til node-lagring for bilde #$imgId.");
            }
            @unlink($fullOrig);
        }

        if ($fullThumb && is_file($fullThumb)) {
            $movedThumb = @rename($fullThumb, $dstThumb);
            if (!$movedThumb) {
                if (@copy($fullThumb, $dstThumb)) @unlink($fullThumb);
            }
            if (is_file($dstThumb)) $newThumbPath = $nodeId . '/' . $newThumbName;
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("
                INSERT INTO node_location_attachments
                    (node_location_id, file_path, thumb_path, original_filename, description, taken_at, mime_type, file_size,
                     checksum_sha256, metadata_json, caption, created_by, created_at)
                VALUES
                    (:node_location_id, :file_path, :thumb_path, :original_filename, :description, :taken_at, :mime_type, :file_size,
                     :checksum_sha256, :metadata_json, :caption, :created_by, :created_at)
            ");
            $ins->execute([
                ':node_location_id'   => $nodeId,
                ':file_path'          => $newFilePath,
                ':thumb_path'         => $newThumbPath,
                ':original_filename'  => (string)($row['original_filename'] ?? ''),
                ':description'        => ($row['description'] ?? null),
                ':taken_at'           => ($row['taken_at'] ?? null),
                ':mime_type'          => (string)($row['mime_type'] ?? ''),
                ':file_size'          => (int)($row['file_size'] ?? 0),
                ':checksum_sha256'    => ($row['checksum_sha256'] ?? null),
                ':metadata_json'      => ($row['metadata_json'] ?? null),
                ':caption'            => ($row['caption'] ?? null),
                ':created_by'         => ($row['created_by'] ?? null),
                ':created_at'         => ($row['created_at'] ?? date('Y-m-d H:i:s')),
            ]);

            $del = $pdo->prepare("DELETE FROM node_location_unassigned_attachments WHERE id = :id LIMIT 1");
            $del->execute([':id' => $imgId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            if ($errLog !== '') {
                @file_put_contents($errLog, "[" . date('c') . "] MAP_DB_FAIL: " . $e->getMessage() . "\n\n", FILE_APPEND);
            }

            ensure_dir(dirname($fullOrig));
            if (is_file($dstOrig)) @rename($dstOrig, $fullOrig);

            if ($fullThumb) {
                ensure_dir(dirname($fullThumb));
                if (is_file($dstThumb)) @rename($dstThumb, $fullThumb);
            }

            throw new RuntimeException('DB-flytting feilet: ' . $e->getMessage(), 0, $e);
        }

        return [
            'image_id' => $imgId,
            'node_location_id' => $nodeId,
            'new_file_path' => $newFilePath,
            'new_thumb_path' => $newThumbPath,
        ];
    }
}

/* ------------------ API actions ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['action'] ?? '') === 'search_nodes') {
    if (!is_ajax_request()) {
        http_response_code(400);
        echo "Bad Request";
        exit;
    }
    $q = (string)($_GET['q'] ?? '');
    try {
        $nodes = node_search($pdo, $q, 15);
        json_out(['ok' => true, 'nodes' => $nodes]);
    } catch (\Throwable $e) {
        json_out(['ok' => false, 'error' => 'Søk feilet: ' . $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'map_to_node') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        json_out(['ok' => false, 'error' => 'Ugyldig CSRF-token. Last siden på nytt og prøv igjen.'], 400);
    }

    $imgId = (int)($_POST['id'] ?? 0);
    $nodeId = (int)($_POST['node_location_id'] ?? 0);

    try {
        $mapped = map_unassigned_image_to_node($pdo, $imgId, $nodeId, $ERR_LOG);
        json_out([
            'ok' => true,
            'message' => "Bilde #{$imgId} er koblet til nodelokasjon #{$nodeId}.",
            'mapped' => $mapped,
        ]);
    } catch (\Throwable $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'map_many_to_node') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        json_out(['ok' => false, 'error' => 'Ugyldig CSRF-token. Last siden på nytt og prøv igjen.'], 400);
    }

    $nodeId = (int)($_POST['node_location_id'] ?? 0);
    $rawIds = $_POST['ids'] ?? [];
    if (!is_array($rawIds)) $rawIds = [$rawIds];

    $ids = [];
    foreach ($rawIds as $id) {
        $id = (int)$id;
        if ($id > 0) $ids[$id] = $id;
    }
    $ids = array_values($ids);

    if ($nodeId <= 0 || !$ids) {
        json_out(['ok' => false, 'error' => 'Velg minst ett bilde og en gyldig nodelokasjon.'], 400);
    }

    $mapped = [];
    $failed = [];

    foreach ($ids as $imgId) {
        try {
            $mapped[] = map_unassigned_image_to_node($pdo, $imgId, $nodeId, $ERR_LOG);
        } catch (\Throwable $e) {
            $failed[] = [
                'image_id' => $imgId,
                'error' => $e->getMessage(),
            ];
        }
    }

    $okCount = count($mapped);
    $failCount = count($failed);

    if ($okCount === 0) {
        json_out([
            'ok' => false,
            'error' => 'Ingen bilder ble koblet.',
            'failed' => $failed,
        ], 500);
    }

    $msg = "$okCount bilde(r) koblet til nodelokasjon #$nodeId.";
    if ($failCount > 0) $msg .= " $failCount feilet.";

    json_out([
        'ok' => true,
        'message' => $msg,
        'mapped' => $mapped,
        'failed' => $failed,
        'node_location_id' => $nodeId,
    ]);
}

/* ------------------ Page state ------------------ */
$flash = ['ok' => null, 'err' => null];
$api = '/api/image_upload_api.php';

/* ---- Delete handler ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_image') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $msg = 'Ugyldig CSRF-token. Last siden på nytt og prøv igjen.';
        if (is_ajax_request()) json_out(['ok' => false, 'error' => $msg], 400);
        $flash['err'] = $msg;
    } else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $msg = 'Ugyldig bilde-id.';
            if (is_ajax_request()) json_out(['ok' => false, 'error' => $msg], 400);
            $flash['err'] = $msg;
        } else {
            $stmt = $pdo->prepare("
                SELECT id, file_path, thumb_path, original_filename
                FROM node_location_unassigned_attachments
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $msg = 'Fant ikke bildet (kan allerede være slettet).';
                if (is_ajax_request()) json_out(['ok' => false, 'error' => $msg], 404);
                $flash['err'] = $msg;
            } else {
                $base = unassigned_base_dir();

                $filePath  = (string)($row['file_path'] ?? '');
                $thumbPath = (string)($row['thumb_path'] ?? '');

                $fullOrig  = $filePath  ? safe_join_under_base($base, $filePath)  : null;
                $fullThumb = $thumbPath ? safe_join_under_base($base, $thumbPath) : null;

                if ($fullThumb && is_file($fullThumb)) @unlink($fullThumb);
                if ($fullOrig && is_file($fullOrig)) @unlink($fullOrig);

                $del = $pdo->prepare("DELETE FROM node_location_unassigned_attachments WHERE id = :id LIMIT 1");
                $del->execute([':id' => $id]);

                $msg = "Slettet bilde #$id.";
                if (is_ajax_request()) json_out(['ok' => true, 'message' => $msg]);
                $flash['ok'] = $msg;
            }
        }
    }
}

/* ---- Upload handler ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array((string)($_POST['action'] ?? ''), ['delete_image','map_to_node','map_many_to_node'], true)) {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $msg = 'Ugyldig CSRF-token. Last siden på nytt og prøv igjen.';
        if (is_ajax_request()) json_out(['ok' => false, 'error' => $msg], 400);
        $flash['err'] = $msg;
    } else {
        $files = $_FILES['images'] ?? null;
        if (!$files || !isset($files['name']) || !is_array($files['name'])) {
            $msg = 'Ingen filer mottatt.';
            if (is_ajax_request()) json_out(['ok' => false, 'error' => $msg], 400);
            $flash['err'] = $msg;
        } else {
            $username = (string)($_SESSION['username'] ?? 'unknown');
            $desc     = trim((string)($_POST['description'] ?? ''));
            $caption  = trim((string)($_POST['caption'] ?? ''));

            $allowed = ['image/jpeg','image/png','image/webp','image/gif'];

            $base = unassigned_base_dir();
            $dateFolder = date('Y-m-d');
            $targetDir = $base . DIRECTORY_SEPARATOR . $dateFolder;
            ensure_dir($targetDir);

            $total = count($files['name']);
            $saved = 0;
            $errors = [];
            $createdItems = [];

            for ($i=0; $i<$total; $i++) {
                $origName = (string)($files['name'][$i] ?? '');
                $tmpName  = (string)($files['tmp_name'][$i] ?? '');
                $err      = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                $size     = (int)($files['size'][$i] ?? 0);

                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if ($err !== UPLOAD_ERR_OK) { $errors[] = ($origName ?: "Fil #$i") . ": opplasting feilet (kode $err)."; continue; }
                if (!is_uploaded_file($tmpName)) { $errors[] = ($origName ?: "Fil #$i") . ": ugyldig upload."; continue; }
                if ($size <= 0) { $errors[] = ($origName ?: "Fil #$i") . ": tom fil."; continue; }
                if ($size > 40*1024*1024) { $errors[] = ($origName ?: "Fil #$i") . ": for stor (maks 40MB)."; continue; }

                $mime = detect_mime($tmpName);
                if (!in_array($mime, $allowed, true)) { $errors[] = ($origName ?: "Fil #$i") . ": ikke støttet filtype ($mime)."; continue; }

                $ext = ext_from_mime_or_name($mime, $origName);
                $rand = random_token(10);
                $baseName = $rand . '.' . $ext;
                $thumbName = $rand . '_thumb.' . $ext;

                $dstOriginal = $targetDir . DIRECTORY_SEPARATOR . $baseName;
                $dstThumb    = $targetDir . DIRECTORY_SEPARATOR . $thumbName;

                if (!@move_uploaded_file($tmpName, $dstOriginal)) {
                    $errors[] = ($origName ?: "Fil #$i") . ": kunne ikke lagre fil.";
                    continue;
                }

                $sha = @hash_file('sha256', $dstOriginal) ?: null;

                $exif = [];
                $takenAt = null;
                $lat = null; $lon = null;
                if ($mime === 'image/jpeg') {
                    $exif = parse_exif_safe($dstOriginal);
                    $takenAt = exif_to_taken_at($exif);
                    [$lat, $lon] = exif_to_latlon($exif);
                }

                $createdNow = date('Y-m-d H:i:s');
                $badgeText  = badge_text_from_taken_or_created($takenAt, $createdNow);

                @apply_badge_to_original($dstOriginal, $mime, $badgeText);

                $thumbOk = make_thumb_same_format($dstOriginal, $mime, $dstThumb, THUMB_MAX_DIM);
                if (!$thumbOk) {
                    @unlink($dstThumb);
                    $dstThumb = '';
                }

                $file_path  = $dateFolder . '/' . $baseName;
                $thumb_path = $thumbOk ? ($dateFolder . '/' . $thumbName) : null;

                $meta = [
                    'mime_detected' => $mime,
                    'original_filename' => $origName,
                    'uploaded_at' => date('c'),
                    'uploader' => $username,
                    'exif' => $exif ?: null,
                    'badge' => $badgeText,
                    'thumb_max_dim' => THUMB_MAX_DIM,
                ];

                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO node_location_unassigned_attachments
                            (file_path, thumb_path, original_filename, description, taken_at, mime_type, file_size,
                             checksum_sha256, caption, created_by, metadata_json, lat, lon)
                        VALUES
                            (:file_path, :thumb_path, :original_filename, :description, :taken_at, :mime_type, :file_size,
                             :checksum_sha256, :caption, :created_by, :metadata_json, :lat, :lon)
                    ");
                    $stmt->execute([
                        ':file_path' => $file_path,
                        ':thumb_path' => $thumb_path,
                        ':original_filename' => $origName,
                        ':description' => ($desc !== '' ? $desc : null),
                        ':taken_at' => $takenAt,
                        ':mime_type' => $mime,
                        ':file_size' => $size,
                        ':checksum_sha256' => $sha,
                        ':caption' => ($caption !== '' ? $caption : null),
                        ':created_by' => $username,
                        ':metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ':lat' => $lat,
                        ':lon' => $lon,
                    ]);

                    $newId = (int)$pdo->lastInsertId();
                    $saved++;

                    $createdItems[] = [
                        'id' => $newId,
                        'thumb_url' => '/api/image_upload_api.php?action=thumb&id=' . $newId,
                        'img_url'   => '/api/image_upload_api.php?action=img&id=' . $newId,
                        'taken_at'  => $takenAt,
                        'created_at'=> $createdNow,
                        'metadata_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                } catch (\Throwable $e) {
                    @unlink($dstOriginal);
                    if ($thumbOk) @unlink($targetDir . DIRECTORY_SEPARATOR . $thumbName);
                    $errors[] = ($origName ?: $baseName) . ": DB-feil: " . $e->getMessage();
                }
            }

            if ($saved > 0 && !$errors) {
                $msg = "Lastet opp $saved bilde(r).";
                if (is_ajax_request()) json_out(['ok' => true, 'message' => $msg, 'saved' => $saved, 'items' => $createdItems]);
                $flash['ok'] = $msg;
            } elseif ($saved > 0 && $errors) {
                $okMsg = "Lastet opp $saved bilde(r), men noen feilet.";
                $errMsg = implode("\n", $errors);
                if (is_ajax_request()) json_out(['ok' => true, 'message' => $okMsg, 'saved' => $saved, 'warnings' => $errMsg, 'items' => $createdItems]);
                $flash['ok'] = $okMsg;
                $flash['err'] = $errMsg;
            } else {
                $msg = $errors ? implode("\n", $errors) : 'Ingen bilder ble lastet opp.';
                if (is_ajax_request()) json_out(['ok' => false, 'error' => $msg], 400);
                $flash['err'] = $msg;
            }
        }
    }
}

/* ------------------ Paging + grouping ------------------ */
$page = (int)($_GET['p'] ?? 1);
$page = max(1, $page);
$pageSize = (int)($_GET['ps'] ?? LIST_PAGE_SIZE);
$pageSize = max(24, min(240, $pageSize));

$totalCount = db_count_all_unassigned($pdo);
$totalPages = max(1, (int)ceil($totalCount / $pageSize));
if ($page > $totalPages) $page = $totalPages;

$items = db_fetch_unassigned_page($pdo, $page, $pageSize);

$groups = [];
foreach ($items as $it) {
    $createdAt = (string)($it['created_at'] ?? '');
    $d = $createdAt ? date('Y-m-d', strtotime($createdAt)) : 'ukjent-dato';
    if (!isset($groups[$d])) $groups[$d] = [];
    $groups[$d][] = $it;
}
uksort($groups, fn($a, $b) => strcmp($b, $a));

if (!function_exists('page_url_image_upload')) {
    function page_url_image_upload(int $p, int $ps): string {
        $p = max(1, $p);
        $ps = max(24, min(240, $ps));
        $base = '/?page=image_upload';
        return $base . '&p=' . $p . '&ps=' . $ps;
    }
}
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h3 class="mb-1">Bildeopplasting (uten tilkobling til node)</h3>
      <div class="text-muted small">
        Dra og slipp bilder eller en mappe (inkl. undermapper) i feltet under for auto-opplasting.
        <span class="ms-2">Velg ett eller flere bilder og koble dem til nodelokasjon.</span>
      </div>
    </div>
    <div class="text-muted small">
      Totalt: <span class="fw-semibold"><?= (int)$totalCount ?></span> bilder • Side <span class="fw-semibold"><?= (int)$page ?></span> / <?= (int)$totalPages ?>
    </div>
  </div>

  <div id="uploadAlerts">
    <?php if (!empty($flash['ok'])): ?><div class="alert alert-success"><?= esc($flash['ok']) ?></div><?php endif; ?>
    <?php if (!empty($flash['err'])): ?><div class="alert alert-danger" style="white-space: pre-wrap;"><?= esc($flash['err']) ?></div><?php endif; ?>
  </div>

  <div id="progressCard" class="card mb-3" style="display:none;">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
          <div class="fw-semibold"><i class="bi bi-cloud-arrow-up me-1"></i>Opplasting</div>
          <div class="text-muted small" id="progressDetailLine">Klargjør…</div>
        </div>
        <div class="text-end">
          <div class="small text-muted" id="progressText">0%</div>
          <div class="small text-muted" id="progressSpeed">0 MB/s</div>
          <div class="small text-muted" id="progressEta">ETA: –</div>
        </div>
      </div>

      <div class="progress mt-2" style="height: 18px;">
        <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%"></div>
      </div>

      <div class="d-flex justify-content-between mt-2 small text-muted flex-wrap gap-2">
        <div id="progressCounts">0 / 0 filer</div>
        <div id="progressBytes">0 MB / 0 MB</div>
        <div id="progressBatches">Batch 0/0</div>
      </div>

      <div class="small text-muted mt-2" id="uploadStatus"></div>
    </div>
  </div>

  <div id="recentWrap" class="card mb-3" style="display:none;">
    <div class="card-header d-flex align-items-center justify-content-between">
      <div class="fw-semibold"><i class="bi bi-stars me-1"></i>Nylig lastet opp</div>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="btnHideRecent">Skjul</button>
    </div>
    <div class="card-body">
      <div id="recentRow" class="row g-2"></div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <div id="dropZone" class="border rounded p-4 text-center"
           style="border-style:dashed; cursor:pointer; user-select:none;">
        <div class="mb-1"><i class="bi bi-folder-plus" style="font-size:34px;"></i></div>
        <div class="fw-semibold">Dra og slipp bilder eller en mappe her</div>
        <div class="text-muted small">…eller klikk for å velge filer / mappe. Opplasting starter automatisk.</div>
      </div>

      <form id="uploadForm" method="post" enctype="multipart/form-data" class="row g-3 mt-3">
        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>" />
        <input type="hidden" name="ajax" value="0" />

        <div class="col-12 col-lg-4">
          <label class="form-label">Velg bilder</label>
          <input id="fileInput" class="form-control" type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple />
          <div class="form-text">Maks 40MB per bilde.</div>
        </div>

        <div class="col-12 col-lg-4">
          <label class="form-label">Velg mappe</label>
          <input id="folderInput" class="form-control" type="file" multiple webkitdirectory directory />
          <div class="form-text">Tar med undermapper (Chrome/Edge).</div>
        </div>

        <div class="col-12 col-lg-2">
          <label class="form-label">Beskrivelse (valgfritt)</label>
          <input class="form-control" type="text" name="description" maxlength="255" />
        </div>

        <div class="col-12 col-lg-2">
          <label class="form-label">Caption (valgfritt)</label>
          <input class="form-control" type="text" name="caption" maxlength="255" />
        </div>

        <div class="col-12">
          <button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Last opp (manuelt)</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card mb-3" id="batchMapCard">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="fw-semibold"><i class="bi bi-check2-square me-1"></i>Velg flere bilder og koble til nodelokasjon</div>
      <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSelectAllPage">Velg alle på siden</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSelectSameDate">Velg alle fra valgt dato</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearSelection">Fjern valg</button>
      </div>
    </div>
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="small text-muted">
          Valgte bilder: <span class="fw-semibold" id="selectedCount">0</span>
        </div>
        <div class="small text-muted" id="selectedPreviewText">Ingen bilder valgt.</div>
      </div>

      <div class="row g-2 mt-1">
        <div class="col-12 col-lg-8">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" id="batchNodeSearch" placeholder="Søk nodelokasjon (id, navn, by, partner)..." autocomplete="off" />
            <button class="btn btn-outline-secondary" type="button" id="btnBatchNodeClear">Tøm</button>
          </div>
          <div class="text-muted small mt-2" id="batchNodeSearchHint">Skriv for å søke etter nodelokasjon…</div>
          <div class="list-group mt-2" id="batchNodeSuggestList"></div>
        </div>

        <div class="col-12 col-lg-4">
          <div class="border rounded p-3 h-100 bg-light">
            <div class="small text-muted mb-1">Valgt nodelokasjon</div>
            <div class="fw-semibold" id="batchNodeSelected">Ingen</div>
            <div class="small text-muted mt-2">Når du kobler, flyttes alle valgte bilder fra unassigned til node-lagring.</div>
            <div class="small text-muted mt-2">Valgt dato: <span id="selectedDateValue">Ingen</span></div>
            <div class="d-grid mt-3">
              <button type="button" class="btn btn-success" id="btnDoBatchMap" disabled>
                <i class="bi bi-link-45deg me-1"></i>Koble valgte bilder
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="alert alert-danger mt-3 mb-0" id="batchMapErr" style="display:none; white-space:pre-wrap;"></div>
      <div class="alert alert-success mt-3 mb-0" id="batchMapOk" style="display:none; white-space:pre-wrap;"></div>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <div class="text-muted small">
      Viser <?= count($items) ?> av <?= (int)$totalCount ?> • Side <?= (int)$page ?>/<?= (int)$totalPages ?>
    </div>
    <nav aria-label="Paginering">
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= esc(page_url_image_upload(1, $pageSize)) ?>">« Første</a>
        </li>
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= esc(page_url_image_upload(max(1, $page-1), $pageSize)) ?>">‹ Forrige</a>
        </li>

        <?php
          $window = 2;
          $start = max(1, $page - $window);
          $end   = min($totalPages, $page + $window);
          if ($start > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          for ($p=$start; $p<=$end; $p++) {
              $active = $p === $page ? 'active' : '';
              echo '<li class="page-item ' . $active . '"><a class="page-link" href="' . esc(page_url_image_upload($p, $pageSize)) . '">' . $p . '</a></li>';
          }
          if ($end < $totalPages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        ?>

        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= esc(page_url_image_upload(min($totalPages, $page+1), $pageSize)) ?>">Neste ›</a>
        </li>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= esc(page_url_image_upload($totalPages, $pageSize)) ?>">Siste »</a>
        </li>
      </ul>
    </nav>
  </div>

  <?php if (!$items): ?>
    <div class="alert alert-info">Ingen opplastede bilder på denne siden.</div>
  <?php else: ?>
    <?php foreach ($groups as $dateYmd => $list): ?>
      <?php
        $dateLabel = $dateYmd !== 'ukjent-dato'
          ? date('d.m.Y', strtotime($dateYmd))
          : 'Ukjent dato';
      ?>
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div class="fw-semibold">
            <i class="bi bi-calendar3 me-1"></i><?= esc($dateLabel) ?>
          </div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="text-muted small"><?= esc((string)count($list)) ?> bilde(r)</div>
            <?php if ($dateYmd !== 'ukjent-dato'): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary js-select-date-group" data-date="<?= esc($dateYmd) ?>">
                Velg alle fra <?= esc($dateLabel) ?>
              </button>
            <?php endif; ?>
          </div>
        </div>

        <div class="card-body">
          <div class="row g-2">
            <?php foreach ($list as $it): ?>
              <?php
                $id = (int)$it['id'];
                $takenAt = $it['taken_at'] ? (string)$it['taken_at'] : null;
                $createdAt = $it['created_at'] ? (string)$it['created_at'] : null;

                $thumbSrc = $api . '?action=thumb&id=' . $id;
                $origSrc  = $api . '?action=img&id=' . $id;

                $label = $takenAt ? ('Tatt: ' . date('d.m.Y', strtotime($takenAt))) : ('Lastet opp: ' . ($createdAt ? date('d.m.Y', strtotime($createdAt)) : ''));
                $uploader = (string)($it['created_by'] ?? '');
                $itemDateYmd = $createdAt ? date('Y-m-d', strtotime($createdAt)) : '';
                $itemDateLabel = $itemDateYmd ? date('d.m.Y', strtotime($itemDateYmd)) : '';
              ?>
              <div class="col-6 col-sm-4 col-md-3 col-lg-2 col-xl-2 col-xxl-1 image-card-wrap"
                   data-img-card="<?= (int)$id ?>"
                   data-created-date="<?= esc($itemDateYmd) ?>">
                <div class="card h-100 shadow-sm image-card">
                  <div class="ratio ratio-1x1 bg-light thumb-tile">
                    <img
                      src="<?= esc($thumbSrc) ?>"
                      class="w-100 h-100 js-gallery-img"
                      style="object-fit: cover; cursor: zoom-in;"
                      loading="lazy"
                      data-bs-toggle="modal"
                      data-bs-target="#imgModal"
                      data-img-id="<?= (int)$id ?>"
                      data-img-src="<?= esc($origSrc) ?>"
                      data-title="<?= esc('Bilde #' . $id) ?>"
                      data-meta="<?= esc((string)($it['metadata_json'] ?? '')) ?>"
                      data-label="<?= esc($label) ?>"
                      data-uploader="<?= esc($uploader) ?>"
                      onerror="this.onerror=null; this.src='<?= esc($origSrc) ?>';"
                      alt="<?= esc('Bilde #' . $id) ?>"
                    />
                  </div>

                  <div class="card-body p-2">
                    <div class="form-check mb-1">
                      <input
                        class="form-check-input js-select-image"
                        type="checkbox"
                        value="<?= (int)$id ?>"
                        id="sel_img_<?= (int)$id ?>"
                        data-created-date="<?= esc($itemDateYmd) ?>"
                        data-created-date-label="<?= esc($itemDateLabel) ?>"
                      />
                      <label class="form-check-label small" for="sel_img_<?= (int)$id ?>">
                        Velg bilde
                      </label>
                    </div>

                    <div class="text-muted small"><?= esc($label) ?></div>
                    <?php if ($uploader !== ''): ?>
                      <div class="text-muted small text-truncate"><?= esc($uploader) ?></div>
                    <?php endif; ?>
                  </div>

                  <div class="card-footer p-2 d-flex justify-content-between align-items-center gap-1 flex-wrap">
                    <button type="button" class="btn btn-outline-primary btn-sm js-open-map-single" data-image-id="<?= (int)$id ?>" title="Koble">
                      <i class="bi bi-link-45deg"></i>
                    </button>

                    <?php if ($itemDateYmd !== ''): ?>
                      <button type="button" class="btn btn-outline-secondary btn-sm js-select-same-date" data-date="<?= esc($itemDateYmd) ?>" title="Velg alle fra samme dato">
                        <i class="bi bi-calendar-check"></i>
                      </button>
                    <?php endif; ?>

                    <form method="post" class="m-0"
                          onsubmit="return confirm('Slette dette bildet permanent? (DB + filer)');">
                      <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>" />
                      <input type="hidden" name="action" value="delete_image" />
                      <input type="hidden" name="id" value="<?= (int)$id ?>" />
                      <button class="btn btn-outline-danger btn-sm" type="submit" title="Slett">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<style>
  #imgStage {
    min-height: 320px;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    position: relative;
    user-select:none;
  }
  #imgModalImg {
    max-width: 100%;
    max-height: 75vh;
    object-fit: contain;
    transform-origin: center center;
    will-change: transform;
  }
  .zoom-hint { font-size: 12px; opacity: .75; }

  #dropZone { position: relative; }
  #dropZone * { pointer-events: none; }
  #dropZone.dz-active { background: rgba(13,110,253,.06); border-color: rgba(13,110,253,.5) !important; }

  #nodeMapBox { display:none; }
  #nodeSuggestList, #batchNodeSuggestList { max-height: 240px; overflow:auto; }
  .node-hit { cursor:pointer; }
  .node-hit:hover { background: rgba(0,0,0,.03); }

  .image-card.selected {
    outline: 2px solid rgba(13,110,253,.7);
    box-shadow: 0 0 0 .2rem rgba(13,110,253,.12);
  }

  .image-card-wrap .ratio {
    max-width: 140px;
    margin: 0 auto;
  }

  .thumb-tile {
    max-width: 100%;
    margin: 0 auto;
  }

  .form-check-input.js-select-image {
    cursor: pointer;
  }

  #batchMapCard {
    position: sticky;
    top: 10px;
    z-index: 5;
  }

  @media (max-width: 767.98px) {
    .image-card-wrap .ratio {
      max-width: 120px;
    }
  }
</style>

<div class="modal fade" id="imgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div class="d-flex flex-column">
          <h5 class="modal-title mb-0" id="imgModalTitle">Bilde</h5>
          <div class="text-muted small" id="imgModalSub"></div>
        </div>

        <div class="d-flex align-items-center gap-2">
          <div class="btn-group btn-group-sm" role="group" aria-label="Navigasjon">
            <button type="button" class="btn btn-outline-secondary" id="btnPrev" title="Forrige (←)">
              <i class="bi bi-chevron-left"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" id="btnNext" title="Neste (→)">
              <i class="bi bi-chevron-right"></i>
            </button>
          </div>

          <div class="btn-group btn-group-sm" role="group" aria-label="Zoom">
            <button type="button" class="btn btn-outline-secondary" id="btnZoomOut" title="Zoom ut (hjul)">
              <i class="bi bi-zoom-out"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" id="btnZoomReset" title="Reset zoom">100%</button>
            <button type="button" class="btn btn-outline-secondary" id="btnZoomIn" title="Zoom inn (hjul)">
              <i class="bi bi-zoom-in"></i>
            </button>
          </div>

          <button type="button" class="btn btn-outline-secondary btn-sm" id="btnToggleMeta">Metadata</button>

          <button type="button" class="btn btn-primary btn-sm" id="btnToggleNodeMap">
            <i class="bi bi-link-45deg me-1"></i>Koble til nodelokasjon
          </button>

          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Lukk"></button>
        </div>
      </div>

      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12 col-lg-8">
            <div class="bg-dark rounded" id="imgStage">
              <img id="imgModalImg" src="" alt="" />
            </div>
            <div class="text-muted mt-2 zoom-hint">
              Tips: Musehjul = zoom. Dra for å panorere ved zoom. Piltaster: ←/→ forrige/neste.
            </div>

            <div class="border rounded p-2 mt-3" id="nodeMapBox">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="fw-semibold">Koble bilde til nodelokasjon</div>
                <div class="text-muted small" id="nodeMapSub">Bilde #–</div>
              </div>

              <div class="input-group mt-2">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" id="nodeSearchInline" placeholder="Søk nodelokasjon (id, navn, by, partner)..." autocomplete="off" />
                <button class="btn btn-outline-secondary" type="button" id="btnNodeClearInline">Tøm</button>
              </div>

              <div class="text-muted small mt-2" id="nodeSearchHintInline">Skriv for å søke…</div>
              <div class="list-group mt-2" id="nodeSuggestList"></div>

              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                <div class="small text-muted">Valgt: <span class="fw-semibold" id="nodeSelectedInline">Ingen</span></div>
                <div class="d-flex gap-2">
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCancelNodeMap">Avbryt</button>
                  <button type="button" class="btn btn-success btn-sm" id="btnDoMapInline" disabled>
                    <i class="bi bi-check2-circle me-1"></i>Koble
                  </button>
                </div>
              </div>

              <div class="alert alert-danger mt-2 mb-0" id="mapErrInline" style="display:none; white-space: pre-wrap;"></div>
              <div class="alert alert-success mt-2 mb-0" id="mapOkInline" style="display:none;"></div>
            </div>
          </div>

          <div class="col-12 col-lg-4" id="metaCol" style="display:none;">
            <div class="border rounded p-2 bg-light">
              <div class="small text-muted mb-1">metadata_json</div>
              <pre class="mb-0" id="imgModalMeta" style="white-space: pre-wrap; font-size: 12px; max-height: 60vh; overflow:auto;"></pre>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <div class="me-auto text-muted small" id="imgModalFooter"></div>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Lukk</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const modalEl = document.getElementById('imgModal');
  const imgEl   = document.getElementById('imgModalImg');
  const stageEl = document.getElementById('imgStage');

  const titleEl = document.getElementById('imgModalTitle');
  const subEl   = document.getElementById('imgModalSub');
  const metaEl  = document.getElementById('imgModalMeta');
  const metaCol = document.getElementById('metaCol');
  const footEl  = document.getElementById('imgModalFooter');
  const btnMeta = document.getElementById('btnToggleMeta');

  const btnPrev = document.getElementById('btnPrev');
  const btnNext = document.getElementById('btnNext');
  const btnZoomIn = document.getElementById('btnZoomIn');
  const btnZoomOut = document.getElementById('btnZoomOut');
  const btnZoomReset = document.getElementById('btnZoomReset');

  const btnToggleNodeMap = document.getElementById('btnToggleNodeMap');
  const nodeMapBox = document.getElementById('nodeMapBox');
  const nodeMapSub = document.getElementById('nodeMapSub');

  const nodeSearchInline = document.getElementById('nodeSearchInline');
  const btnNodeClearInline = document.getElementById('btnNodeClearInline');
  const nodeSearchHintInline = document.getElementById('nodeSearchHintInline');
  const nodeSuggestList = document.getElementById('nodeSuggestList');
  const nodeSelectedInline = document.getElementById('nodeSelectedInline');
  const btnDoMapInline = document.getElementById('btnDoMapInline');
  const btnCancelNodeMap = document.getElementById('btnCancelNodeMap');
  const mapErrInline = document.getElementById('mapErrInline');
  const mapOkInline = document.getElementById('mapOkInline');

  const batchNodeSearch = document.getElementById('batchNodeSearch');
  const btnBatchNodeClear = document.getElementById('btnBatchNodeClear');
  const batchNodeSearchHint = document.getElementById('batchNodeSearchHint');
  const batchNodeSuggestList = document.getElementById('batchNodeSuggestList');
  const batchNodeSelected = document.getElementById('batchNodeSelected');
  const btnDoBatchMap = document.getElementById('btnDoBatchMap');
  const batchMapErr = document.getElementById('batchMapErr');
  const batchMapOk = document.getElementById('batchMapOk');
  const selectedCountEl = document.getElementById('selectedCount');
  const selectedPreviewText = document.getElementById('selectedPreviewText');
  const btnSelectAllPage = document.getElementById('btnSelectAllPage');
  const btnSelectSameDate = document.getElementById('btnSelectSameDate');
  const btnClearSelection = document.getElementById('btnClearSelection');
  const selectedDateValue = document.getElementById('selectedDateValue');

  let gallery = [];
  let currentIndex = -1;
  let currentImageId = 0;

  let zoom = 1.0;
  let panX = 0;
  let panY = 0;
  let isDragging = false;
  let dragStartX = 0;
  let dragStartY = 0;
  let panStartX = 0;
  let panStartY = 0;

  let selectedNodeId = 0;
  let selectedNodeLabel = '';

  let batchSelectedNodeId = 0;
  let batchSelectedNodeLabel = '';

  function applyTransform() {
    if (!imgEl) return;
    imgEl.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom})`;
    if (btnZoomReset) btnZoomReset.textContent = Math.round(zoom * 100) + '%';
    if (stageEl) stageEl.style.cursor = (zoom > 1.01) ? (isDragging ? 'grabbing' : 'grab') : 'default';
  }

  function resetZoom() {
    zoom = 1.0;
    panX = 0;
    panY = 0;
    applyTransform();
  }

  function setZoom(newZoom) {
    zoom = Math.max(1.0, Math.min(4.0, newZoom));
    if (zoom <= 1.01) { panX = 0; panY = 0; }
    applyTransform();
  }

  function zoomBy(delta) {
    const step = 0.15;
    setZoom(zoom + (delta * step));
  }

  function parseMetaPretty(metaStr) {
    try { return JSON.stringify(JSON.parse(metaStr), null, 2); }
    catch (e) { return metaStr || '(ingen metadata)'; }
  }

  function rebuildGallery() {
    gallery = Array.from(document.querySelectorAll('img.js-gallery-img')).map((el) => ({
      el,
      id: Number(el.getAttribute('data-img-id') || 0),
      src: el.getAttribute('data-img-src') || '',
      title: el.getAttribute('data-title') || 'Bilde',
      meta: el.getAttribute('data-meta') || '',
      label: el.getAttribute('data-label') || '',
      uploader: el.getAttribute('data-uploader') || ''
    }));
  }

  function openAt(index) {
    if (!gallery.length) rebuildGallery();
    if (!gallery.length) return;

    currentIndex = Math.max(0, Math.min(gallery.length - 1, index));
    const it = gallery[currentIndex];
    if (!it || !imgEl) return;

    currentImageId = Number(it.id || 0);

    const bust = (it.src.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now();
    imgEl.src = it.src + bust;
    imgEl.alt = it.title;

    if (titleEl) titleEl.textContent = it.title;

    const parts = [];
    if (it.label) parts.push(it.label);
    if (it.uploader) parts.push(it.uploader);
    if (subEl) subEl.textContent = parts.join(' • ');

    if (metaEl) metaEl.textContent = parseMetaPretty(it.meta);
    if (footEl) footEl.textContent = (currentIndex + 1) + ' / ' + gallery.length;

    resetZoom();
    if (btnPrev) btnPrev.disabled = (currentIndex <= 0);
    if (btnNext) btnNext.disabled = (currentIndex >= gallery.length - 1);

    hideNodeMap();
  }

  function openFromTrigger(triggerEl) {
    rebuildGallery();
    const idx = gallery.findIndex(x => x.el === triggerEl);
    openAt(idx >= 0 ? idx : 0);
  }

  function goPrev() { if (currentIndex > 0) openAt(currentIndex - 1); }
  function goNext() { if (currentIndex < gallery.length - 1) openAt(currentIndex + 1); }

  btnMeta?.addEventListener('click', function () {
    const visible = metaCol && metaCol.style.display !== 'none';
    if (metaCol) metaCol.style.display = visible ? 'none' : '';
  });

  btnPrev?.addEventListener('click', goPrev);
  btnNext?.addEventListener('click', goNext);
  btnZoomIn?.addEventListener('click', () => zoomBy(+1));
  btnZoomOut?.addEventListener('click', () => zoomBy(-1));
  btnZoomReset?.addEventListener('click', resetZoom);

  stageEl?.addEventListener('wheel', (e) => {
    e.preventDefault();
    const dir = (e.deltaY > 0) ? -1 : +1;
    zoomBy(dir);
  }, { passive: false });

  stageEl?.addEventListener('mousedown', (e) => {
    if (zoom <= 1.01) return;
    isDragging = true;
    dragStartX = e.clientX;
    dragStartY = e.clientY;
    panStartX = panX;
    panStartY = panY;
    applyTransform();
  });

  window.addEventListener('mousemove', (e) => {
    if (!isDragging) return;
    const dx = e.clientX - dragStartX;
    const dy = e.clientY - dragStartY;
    panX = panStartX + dx;
    panY = panStartY + dy;
    applyTransform();
  });

  window.addEventListener('mouseup', () => {
    if (!isDragging) return;
    isDragging = false;
    applyTransform();
  });

  stageEl?.addEventListener('dblclick', (e) => {
    e.preventDefault();
    if (zoom <= 1.01) setZoom(2.0);
    else resetZoom();
  });

  window.addEventListener('keydown', (e) => {
    if (!modalEl || !modalEl.classList.contains('show')) return;
    if (e.key === 'ArrowLeft') { e.preventDefault(); goPrev(); }
    if (e.key === 'ArrowRight') { e.preventDefault(); goNext(); }
  });

  document.addEventListener('click', (e) => {
    const checkbox = e.target && e.target.closest ? e.target.closest('.js-select-image') : null;
    if (checkbox) return;

    const singleMapBtn = e.target && e.target.closest ? e.target.closest('.js-open-map-single') : null;
    if (singleMapBtn) {
      const imageId = Number(singleMapBtn.getAttribute('data-image-id') || 0);
      if (imageId > 0) {
        const img = document.querySelector('img.js-gallery-img[data-img-id="' + imageId + '"]');
        if (img) {
          openFromTrigger(img);
          if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
          }
          setTimeout(showNodeMap, 80);
        }
      }
      return;
    }

    const sameDateBtn = e.target && e.target.closest ? e.target.closest('.js-select-same-date') : null;
    if (sameDateBtn) {
      selectAllByDate(sameDateBtn.getAttribute('data-date') || '');
      return;
    }

    const groupBtn = e.target && e.target.closest ? e.target.closest('.js-select-date-group') : null;
    if (groupBtn) {
      selectAllByDate(groupBtn.getAttribute('data-date') || '');
      return;
    }

    const t = e.target && e.target.closest ? e.target.closest('img.js-gallery-img') : null;
    if (!t) return;
    openFromTrigger(t);
  }, true);

  function setInlineMsg(kind, msg) {
    if (mapErrInline) mapErrInline.style.display = 'none';
    if (mapOkInline) mapOkInline.style.display = 'none';
    if (!msg) return;
    if (kind === 'err' && mapErrInline) { mapErrInline.textContent = msg; mapErrInline.style.display = ''; }
    if (kind === 'ok' && mapOkInline) { mapOkInline.textContent = msg; mapOkInline.style.display = ''; }
  }

  function showNodeMap() {
    if (!nodeMapBox) return;
    nodeMapBox.style.display = '';
    if (nodeMapSub) nodeMapSub.textContent = 'Bilde #' + (currentImageId || '–');
    setInlineMsg('', '');
    clearHits(nodeSuggestList);
    clearInlineSelection();
    if (nodeSearchInline) { nodeSearchInline.value = ''; nodeSearchInline.focus(); }
    if (nodeSearchHintInline) nodeSearchHintInline.textContent = 'Skriv for å søke…';
  }

  function hideNodeMap() {
    if (!nodeMapBox) return;
    nodeMapBox.style.display = 'none';
    setInlineMsg('', '');
    clearHits(nodeSuggestList);
    clearInlineSelection();
    if (nodeSearchInline) nodeSearchInline.value = '';
  }

  function clearHits(container) {
    if (container) container.innerHTML = '';
  }

  function clearInlineSelection() {
    selectedNodeId = 0;
    selectedNodeLabel = '';
    if (nodeSelectedInline) nodeSelectedInline.textContent = 'Ingen';
    if (btnDoMapInline) btnDoMapInline.disabled = true;
  }

  function setInlineSelection(id, label) {
    selectedNodeId = Number(id || 0);
    selectedNodeLabel = String(label || '');
    if (nodeSelectedInline) nodeSelectedInline.textContent = selectedNodeLabel || ('#' + selectedNodeId);
    if (btnDoMapInline) btnDoMapInline.disabled = !(selectedNodeId > 0 && currentImageId > 0);
  }

  function setBatchSelection(id, label) {
    batchSelectedNodeId = Number(id || 0);
    batchSelectedNodeLabel = String(label || '');
    if (batchNodeSelected) batchNodeSelected.textContent = batchSelectedNodeLabel || ('#' + batchSelectedNodeId);
    updateBatchActionState();
  }

  function clearBatchNodeSelection() {
    batchSelectedNodeId = 0;
    batchSelectedNodeLabel = '';
    if (batchNodeSelected) batchNodeSelected.textContent = 'Ingen';
    updateBatchActionState();
  }

  function getSelectedImageIds() {
    return Array.from(document.querySelectorAll('.js-select-image:checked'))
      .map(cb => Number(cb.value || 0))
      .filter(v => v > 0);
  }

  function getSelectedDates() {
    const dates = Array.from(document.querySelectorAll('.js-select-image:checked'))
      .map(cb => String(cb.getAttribute('data-created-date') || '').trim())
      .filter(Boolean);
    return Array.from(new Set(dates));
  }

  function updateSelectionVisuals() {
    document.querySelectorAll('.image-card-wrap').forEach((wrap) => {
      const cb = wrap.querySelector('.js-select-image');
      const card = wrap.querySelector('.image-card');
      if (!cb || !card) return;
      card.classList.toggle('selected', !!cb.checked);
    });

    const ids = getSelectedImageIds();
    const dates = getSelectedDates();

    if (selectedCountEl) selectedCountEl.textContent = String(ids.length);

    if (selectedPreviewText) {
      if (!ids.length) selectedPreviewText.textContent = 'Ingen bilder valgt.';
      else if (ids.length <= 6) selectedPreviewText.textContent = 'Valgt: #' + ids.join(', #');
      else selectedPreviewText.textContent = ids.length + ' bilder valgt.';
    }

    if (selectedDateValue) {
      if (!dates.length) selectedDateValue.textContent = 'Ingen';
      else if (dates.length === 1) {
        const d = dates[0];
        const parts = d.split('-');
        selectedDateValue.textContent = parts.length === 3 ? `${parts[2]}.${parts[1]}.${parts[0]}` : d;
      } else {
        selectedDateValue.textContent = dates.length + ' datoer';
      }
    }

    updateBatchActionState();
  }

  function updateBatchActionState() {
    const ids = getSelectedImageIds();
    if (btnDoBatchMap) btnDoBatchMap.disabled = !(ids.length > 0 && batchSelectedNodeId > 0);
  }

  function renderNodeHits(container, nodes, onPick) {
    if (!container) return;
    container.innerHTML = '';
    if (!nodes || !nodes.length) {
      container.innerHTML = '<div class="text-muted small p-2">Ingen treff.</div>';
      return;
    }
    for (const n of nodes) {
      const id = Number(n.id || 0);
      const name = String(n.name || '');
      const city = String(n.city || '');
      const partner = String(n.partner || '');
      const parts = [];
      if (name) parts.push(name);
      if (city) parts.push(city);
      if (partner) parts.push(partner);
      const label = '#' + id + (parts.length ? (' • ' + parts.join(' • ')) : '');
      const div = document.createElement('div');
      div.className = 'list-group-item list-group-item-action node-hit';
      div.innerHTML = `<div class="fw-semibold">${label}</div>`;
      div.addEventListener('click', () => onPick(id, label));
      container.appendChild(div);
    }
  }

  async function searchNodes(q) {
    const url = '/pages/image_upload.php?action=search_nodes&q=' + encodeURIComponent(String(q || '').trim());
    const res = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    if (!data || !data.ok) throw new Error((data && data.error) ? data.error : 'Ukjent feil');
    return Array.isArray(data.nodes) ? data.nodes : [];
  }

  function selectAllByDate(dateYmd) {
    const d = String(dateYmd || '').trim();
    if (!d) return;

    document.querySelectorAll('.js-select-image').forEach(cb => {
      if ((cb.getAttribute('data-created-date') || '') === d) cb.checked = true;
    });

    updateSelectionVisuals();
  }

  let inlineSearchTimer = null;
  nodeSearchInline?.addEventListener('input', () => {
    if (inlineSearchTimer) clearTimeout(inlineSearchTimer);
    inlineSearchTimer = setTimeout(async () => {
      const q = String(nodeSearchInline.value || '').trim();
      if (nodeSearchHintInline) nodeSearchHintInline.textContent = q ? 'Søker…' : 'Skriv for å søke…';
      if (!q) {
        clearHits(nodeSuggestList);
        clearInlineSelection();
        return;
      }
      try {
        const nodes = await searchNodes(q);
        renderNodeHits(nodeSuggestList, nodes, (id, label) => {
          setInlineSelection(id, label);
          setInlineMsg('', '');
        });
        if (nodeSearchHintInline) nodeSearchHintInline.textContent = 'Klikk på en nodelokasjon for å velge.';
      } catch (e) {
        if (nodeSearchHintInline) nodeSearchHintInline.textContent = 'Søk feilet.';
        setInlineMsg('err', 'Søk feilet: ' + (e && e.message ? e.message : e));
        clearHits(nodeSuggestList);
      }
    }, 160);
  });

  btnNodeClearInline?.addEventListener('click', () => {
    if (nodeSearchInline) nodeSearchInline.value = '';
    clearHits(nodeSuggestList);
    clearInlineSelection();
    setInlineMsg('', '');
    if (nodeSearchHintInline) nodeSearchHintInline.textContent = 'Skriv for å søke…';
    nodeSearchInline?.focus();
  });

  btnToggleNodeMap?.addEventListener('click', () => {
    if (!currentImageId) { alert('Klikk på et bilde først.'); return; }
    const visible = nodeMapBox && nodeMapBox.style.display !== 'none';
    if (visible) hideNodeMap(); else showNodeMap();
  });

  btnCancelNodeMap?.addEventListener('click', hideNodeMap);

  async function doMapNow() {
    if (!(currentImageId > 0 && selectedNodeId > 0)) return;

    setInlineMsg('', '');
    if (btnDoMapInline) {
      btnDoMapInline.disabled = true;
      btnDoMapInline.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Kobler…';
    }

    const fd = new FormData();
    fd.set('ajax', '1');
    fd.set('action', 'map_to_node');
    fd.set('csrf_token', <?= json_encode($csrf) ?>);
    fd.set('id', String(currentImageId));
    fd.set('node_location_id', String(selectedNodeId));

    try {
      const res = await fetch('/pages/image_upload.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      });
      const data = await res.json();
      if (!data || !data.ok) throw new Error((data && data.error) ? data.error : 'Ukjent feil');

      setInlineMsg('ok', data.message || ('Koblet bilde #' + currentImageId + ' til node #' + selectedNodeId));

      const card = document.querySelector('[data-img-card="' + currentImageId + '"]');
      if (card) card.remove();

      rebuildGallery();
      updateSelectionVisuals();
      hideNodeMap();

      const alerts = document.getElementById('uploadAlerts');
      if (alerts) {
        const div = document.createElement('div');
        div.className = 'alert alert-success';
        div.textContent = data.message || 'Mappet.';
        alerts.prepend(div);
        setTimeout(() => div.remove(), 4500);
      }
    } catch (e) {
      setInlineMsg('err', 'Mapping feilet: ' + (e && e.message ? e.message : e));
    } finally {
      if (btnDoMapInline) {
        btnDoMapInline.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Koble';
        btnDoMapInline.disabled = !(selectedNodeId > 0 && currentImageId > 0);
      }
    }
  }

  btnDoMapInline?.addEventListener('click', doMapNow);

  let batchSearchTimer = null;
  batchNodeSearch?.addEventListener('input', () => {
    if (batchSearchTimer) clearTimeout(batchSearchTimer);
    batchSearchTimer = setTimeout(async () => {
      const q = String(batchNodeSearch.value || '').trim();
      if (batchNodeSearchHint) batchNodeSearchHint.textContent = q ? 'Søker…' : 'Skriv for å søke etter nodelokasjon…';
      if (!q) {
        clearHits(batchNodeSuggestList);
        clearBatchNodeSelection();
        return;
      }
      try {
        const nodes = await searchNodes(q);
        renderNodeHits(batchNodeSuggestList, nodes, (id, label) => {
          setBatchSelection(id, label);
          setBatchMsg('', '');
        });
        if (batchNodeSearchHint) batchNodeSearchHint.textContent = 'Klikk på en nodelokasjon for å velge.';
      } catch (e) {
        if (batchNodeSearchHint) batchNodeSearchHint.textContent = 'Søk feilet.';
        setBatchMsg('err', 'Søk feilet: ' + (e && e.message ? e.message : e));
        clearHits(batchNodeSuggestList);
      }
    }, 160);
  });

  btnBatchNodeClear?.addEventListener('click', () => {
    if (batchNodeSearch) batchNodeSearch.value = '';
    clearHits(batchNodeSuggestList);
    clearBatchNodeSelection();
    setBatchMsg('', '');
    if (batchNodeSearchHint) batchNodeSearchHint.textContent = 'Skriv for å søke etter nodelokasjon…';
    batchNodeSearch?.focus();
  });

  function setBatchMsg(kind, msg) {
    if (batchMapErr) batchMapErr.style.display = 'none';
    if (batchMapOk) batchMapOk.style.display = 'none';
    if (!msg) return;
    if (kind === 'err' && batchMapErr) { batchMapErr.textContent = msg; batchMapErr.style.display = ''; }
    if (kind === 'ok' && batchMapOk) { batchMapOk.textContent = msg; batchMapOk.style.display = ''; }
  }

  async function doBatchMap() {
    const ids = getSelectedImageIds();
    if (!ids.length || !(batchSelectedNodeId > 0)) return;

    setBatchMsg('', '');
    btnDoBatchMap.disabled = true;
    btnDoBatchMap.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Kobler valgte…';

    const fd = new FormData();
    fd.set('ajax', '1');
    fd.set('action', 'map_many_to_node');
    fd.set('csrf_token', <?= json_encode($csrf) ?>);
    fd.set('node_location_id', String(batchSelectedNodeId));
    ids.forEach(id => fd.append('ids[]', String(id)));

    try {
      const res = await fetch('/pages/image_upload.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      });
      const data = await res.json();
      if (!data || !data.ok) throw new Error((data && data.error) ? data.error : 'Ukjent feil');

      const mapped = Array.isArray(data.mapped) ? data.mapped : [];
      const failed = Array.isArray(data.failed) ? data.failed : [];

      mapped.forEach(item => {
        const card = document.querySelector('[data-img-card="' + Number(item.image_id || 0) + '"]');
        if (card) card.remove();
      });

      rebuildGallery();
      updateSelectionVisuals();
      clearHits(batchNodeSuggestList);
      clearBatchNodeSelection();
      if (batchNodeSearch) batchNodeSearch.value = '';

      let msg = data.message || 'Bildene ble koblet.';
      if (failed.length) {
        const lines = failed.map(f => '#'+ f.image_id + ': ' + (f.error || 'ukjent feil'));
        msg += "\n\nFeilet:\n" + lines.join("\n");
      }
      setBatchMsg('ok', msg);

      const alerts = document.getElementById('uploadAlerts');
      if (alerts) {
        const div = document.createElement('div');
        div.className = 'alert alert-success';
        div.style.whiteSpace = 'pre-wrap';
        div.textContent = msg;
        alerts.prepend(div);
        setTimeout(() => div.remove(), 7000);
      }
    } catch (e) {
      setBatchMsg('err', 'Batch-mapping feilet: ' + (e && e.message ? e.message : e));
    } finally {
      btnDoBatchMap.innerHTML = '<i class="bi bi-link-45deg me-1"></i>Koble valgte bilder';
      updateBatchActionState();
    }
  }

  btnDoBatchMap?.addEventListener('click', doBatchMap);

  document.addEventListener('change', (e) => {
    const cb = e.target && e.target.matches ? (e.target.matches('.js-select-image') ? e.target : null) : null;
    if (!cb) return;
    updateSelectionVisuals();
  });

  btnSelectAllPage?.addEventListener('click', () => {
    document.querySelectorAll('.js-select-image').forEach(cb => { cb.checked = true; });
    updateSelectionVisuals();
  });

  btnSelectSameDate?.addEventListener('click', () => {
    const dates = getSelectedDates();
    if (dates.length === 1) {
      selectAllByDate(dates[0]);
      return;
    }

    if (dates.length === 0) {
      const first = document.querySelector('.js-select-image[data-created-date]');
      if (first) {
        const d = first.getAttribute('data-created-date') || '';
        if (d) selectAllByDate(d);
      }
      return;
    }

    alert('Velg først ett bilde fra datoen du vil markere.');
  });

  btnClearSelection?.addEventListener('click', () => {
    document.querySelectorAll('.js-select-image').forEach(cb => { cb.checked = false; });
    updateSelectionVisuals();
  });

  const dz = document.getElementById('dropZone');
  const form = document.getElementById('uploadForm');
  const fileInput = document.getElementById('fileInput');
  const folderInput = document.getElementById('folderInput');
  const alerts = document.getElementById('uploadAlerts');

  const progressCard = document.getElementById('progressCard');
  const progressBar = document.getElementById('progressBar');
  const progressText = document.getElementById('progressText');
  const progressCounts = document.getElementById('progressCounts');
  const progressBatches = document.getElementById('progressBatches');
  const statusEl = document.getElementById('uploadStatus');
  const progressBytes = document.getElementById('progressBytes');
  const progressSpeed = document.getElementById('progressSpeed');
  const progressEta = document.getElementById('progressEta');
  const progressDetailLine = document.getElementById('progressDetailLine');

  const recentWrap = document.getElementById('recentWrap');
  const recentRow = document.getElementById('recentRow');
  const btnHideRecent = document.getElementById('btnHideRecent');

  btnHideRecent?.addEventListener('click', () => {
    if (recentWrap) recentWrap.style.display = 'none';
    if (recentRow) recentRow.innerHTML = '';
  });

  rebuildGallery();
  updateSelectionVisuals();

  if (!dz || !form || !fileInput) return;

  const UPLOAD_ENDPOINT = '/pages/image_upload.php';
  const MAX_FILES_PER_BATCH = 20;
  const MAX_BYTES_PER_BATCH = 150 * 1024 * 1024;

  function fmtBytes(bytes) {
    const b = Math.max(0, Number(bytes || 0));
    const units = ['B','KB','MB','GB','TB'];
    let u = 0, v = b;
    while (v >= 1024 && u < units.length-1) { v /= 1024; u++; }
    return v.toFixed(u === 0 ? 0 : 1) + ' ' + units[u];
  }

  function fmtTime(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    if (!isFinite(sec) || sec <= 0) return '–';
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    if (h > 0) return `${h}t ${m}m`;
    if (m > 0) return `${m}m ${s}s`;
    return `${s}s`;
  }

  function showAlert(kind, msg, timeoutMs) {
    if (!alerts) return;
    const div = document.createElement('div');
    div.className = 'alert ' + (kind === 'ok' ? 'alert-success' : 'alert-danger');
    div.style.whiteSpace = 'pre-wrap';
    div.textContent = msg;
    alerts.prepend(div);
    const ms = typeof timeoutMs === 'number' ? timeoutMs : (kind === 'ok' ? 4500 : 9000);
    setTimeout(() => { div.remove(); }, ms);
  }

  function showProgress(show) {
    if (!progressCard) return;
    progressCard.style.display = show ? '' : 'none';
    if (show && progressBar) progressBar.classList.add('progress-bar-animated');
  }

  function hideProgressSoon() {
    setTimeout(() => {
      if (progressCard) progressCard.style.display = 'none';
      if (statusEl) statusEl.textContent = '';
    }, 1400);
  }

  function setProgress(pct, text) {
    const p = Math.max(0, Math.min(100, pct || 0));
    if (progressBar) progressBar.style.width = p.toFixed(1) + '%';
    if (progressText) progressText.textContent = (text || (p.toFixed(1) + '%'));
  }

  function setCounts(doneFiles, totalFiles, batchIndex, batchTotal) {
    if (progressCounts) progressCounts.textContent = doneFiles + ' / ' + totalFiles + ' filer';
    if (progressBatches) progressBatches.textContent = 'Batch ' + batchIndex + '/' + batchTotal;
  }

  function setStatus(msg) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
  }

  function setBytes(doneBytes, totalBytes) {
    if (!progressBytes) return;
    progressBytes.textContent = fmtBytes(doneBytes) + ' / ' + fmtBytes(totalBytes);
  }

  function setSpeedAndEta(bytesDone, totalBytes, startedAtMs) {
    const now = Date.now();
    const dt = Math.max(0.001, (now - startedAtMs) / 1000);
    const speed = bytesDone / dt;
    if (progressSpeed) progressSpeed.textContent = (speed > 0 ? (fmtBytes(speed) + '/s') : '0 B/s');

    const remaining = Math.max(0, totalBytes - bytesDone);
    const eta = speed > 1 ? (remaining / speed) : 0;
    if (progressEta) progressEta.textContent = 'ETA: ' + fmtTime(eta);
  }

  function addToRecent(item) {
    if (!recentWrap || !recentRow) return;
    recentWrap.style.display = '';

    const takenAt = item && item.taken_at ? item.taken_at : null;
    const createdAt = item && item.created_at ? item.created_at : null;
    const label = takenAt ? ('Tatt: ' + takenAt.substring(0,10).split('-').reverse().join('.'))
                          : (createdAt ? ('Lastet opp: ' + createdAt.substring(0,10).split('-').reverse().join('.')) : '');

    const createdDate = createdAt ? createdAt.substring(0,10) : '';
    const createdLabel = createdDate ? createdDate.split('-').reverse().join('.') : '';

    const col = document.createElement('div');
    col.className = 'col-6 col-sm-4 col-md-3 col-lg-2 col-xl-2 col-xxl-1 image-card-wrap';
    col.setAttribute('data-img-card', item.id);
    col.setAttribute('data-created-date', createdDate);

    const safeMeta = (item.metadata_json || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');

    col.innerHTML = `
      <div class="card h-100 shadow-sm image-card">
        <div class="ratio ratio-1x1 bg-light thumb-tile">
          <img
            src="${item.thumb_url || item.img_url}"
            class="w-100 h-100 js-gallery-img"
            style="object-fit: cover; cursor: zoom-in;"
            loading="lazy"
            data-bs-toggle="modal"
            data-bs-target="#imgModal"
            data-img-id="${item.id}"
            data-img-src="${item.img_url}"
            data-title="Bilde #${item.id}"
            data-meta="${safeMeta}"
            data-label="${label}"
            data-uploader=""
            onerror="this.onerror=null; this.src='${item.img_url}';"
            alt="Bilde #${item.id}"
          />
        </div>
        <div class="card-body p-2">
          <div class="form-check mb-1">
            <input class="form-check-input js-select-image" type="checkbox" value="${item.id}" id="sel_recent_${item.id}" data-created-date="${createdDate}" data-created-date-label="${createdLabel}" />
            <label class="form-check-label small" for="sel_recent_${item.id}">Velg bilde</label>
          </div>
          <div class="text-muted small">${label}</div>
        </div>
      </div>
    `;
    recentRow.prepend(col);
    rebuildGallery();
    updateSelectionVisuals();
  }

  function splitIntoBatches(files) {
    const batches = [];
    let current = [];
    let bytes = 0;

    for (const f of files) {
      const nextBytes = bytes + (f.size || 0);
      if (current.length >= MAX_FILES_PER_BATCH || nextBytes > MAX_BYTES_PER_BATCH) {
        if (current.length) batches.push(current);
        current = [];
        bytes = 0;
      }
      current.push(f);
      bytes += (f.size || 0);
    }
    if (current.length) batches.push(current);
    return batches;
  }

  function uploadBatchXHR(batchFiles, baseFormData, onProgress) {
    return new Promise((resolve) => {
      const fd = new FormData();
      for (const [k, v] of baseFormData.entries()) fd.append(k, v);

      fd.set('ajax', '1');
      fd.delete('images[]');
      for (const f of batchFiles) fd.append('images[]', f);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', UPLOAD_ENDPOINT, true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('Accept', 'application/json');

      xhr.upload.onprogress = function (evt) {
        if (!evt || !evt.lengthComputable) return;
        const pct = evt.total ? (evt.loaded / evt.total) : 0;
        onProgress && onProgress(pct, evt.loaded, evt.total);
      };

      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;

        const status = xhr.status || 0;
        const ct = (xhr.getResponseHeader('content-type') || '').toLowerCase();
        const isJson = ct.indexOf('application/json') !== -1;

        let data = null;
        if (isJson) {
          try { data = JSON.parse(xhr.responseText || 'null'); } catch (e) { data = null; }
        }

        if (status >= 200 && status < 300 && data) {
          resolve({ okHttp: true, data });
        } else {
          const msg =
            (data && (data.error || data.message)) ? (data.error || data.message) :
            ('Serverfeil. HTTP ' + status + (xhr.responseText ? (' – ' + xhr.responseText.substring(0, 200)) : ''));
          resolve({ okHttp: false, data: data, error: msg, status });
        }
      };

      xhr.send(fd);
    });
  }

  async function uploadFilesLarge(files) {
    if (!files || !files.length) return;

    const allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    const valid = files.filter(f => allowed.includes((f.type || '').toLowerCase()));
    const rejected = files.length - valid.length;
    if (rejected > 0) showAlert('err', rejected + ' fil(er) ble ignorert (ikke støttet filtype).');

    if (!valid.length) return;

    showProgress(true);
    setProgress(0, '0%');
    setStatus('');
    if (progressDetailLine) progressDetailLine.textContent = 'Klargjør opplasting…';

    const baseFD = new FormData(form);
    baseFD.set('ajax', '1');

    const batches = splitIntoBatches(valid);
    const batchTotal = batches.length;

    let doneFiles = 0;
    let okFiles = 0;
    let failFiles = 0;

    const totalBytes = valid.reduce((sum, f) => sum + (f.size || 0), 0);
    let bytesDone = 0;

    const startedAt = Date.now();

    setCounts(0, valid.length, 0, batchTotal);
    setBytes(0, totalBytes);
    setSpeedAndEta(0, totalBytes, startedAt);

    for (let b = 0; b < batches.length; b++) {
      const batch = batches[b];
      const batchBytes = batch.reduce((sum, f) => sum + (f.size || 0), 0);

      setCounts(doneFiles, valid.length, b + 1, batchTotal);
      if (progressDetailLine) progressDetailLine.textContent = 'Batch ' + (b + 1) + '/' + batchTotal + ' (' + batch.length + ' filer)';
      setStatus('Laster opp…');

      let lastBatchLoaded = 0;

      const res = await uploadBatchXHR(batch, baseFD, (pct, loaded, total) => {
        lastBatchLoaded = loaded || 0;
        const totalLoaded = bytesDone + lastBatchLoaded;
        const pctAll = totalBytes ? (totalLoaded / totalBytes) * 100 : 0;
        setProgress(pctAll, pctAll.toFixed(1) + '%');
        setBytes(totalLoaded, totalBytes);
        setSpeedAndEta(totalLoaded, totalBytes, startedAt);
      });

      bytesDone += batchBytes;
      doneFiles += batch.length;

      setCounts(doneFiles, valid.length, b + 1, batchTotal);
      const pctAll = totalBytes ? (bytesDone / totalBytes) * 100 : 100;
      setProgress(pctAll, pctAll.toFixed(1) + '%');
      setBytes(bytesDone, totalBytes);
      setSpeedAndEta(bytesDone, totalBytes, startedAt);

      if (!res.okHttp) {
        failFiles += batch.length;
        showAlert('err', 'Batch ' + (b + 1) + ' feilet: ' + (res.error || 'ukjent feil'), 12000);
        continue;
      }

      const data = res.data || {};
      if (data.ok) {
        const newItems = Array.isArray(data.items) ? data.items : [];
        okFiles += (data.saved ? Number(data.saved) : newItems.length);

        if (data.message) showAlert('ok', data.message);
        if (data.warnings) showAlert('err', data.warnings, 12000);

        for (const it of newItems) addToRecent(it);
      } else {
        failFiles += batch.length;
        showAlert('err', data.error || ('Batch ' + (b + 1) + ' feilet.'), 12000);
      }
    }

    setStatus('Ferdig.');
    if (progressDetailLine) progressDetailLine.textContent = 'OK: ' + okFiles + ' • Feil: ' + failFiles + ' • Totalt: ' + valid.length;
    setProgress(100, '100%');

    if (progressBar) progressBar.classList.remove('progress-bar-animated');

    fileInput.value = '';
    if (folderInput) folderInput.value = '';

    hideProgressSoon();
  }

  function readAllFilesFromEntry(entry) {
    return new Promise((resolve) => {
      if (!entry) return resolve([]);

      if (entry.isFile) {
        entry.file((file) => resolve([file]), () => resolve([]));
        return;
      }

      if (entry.isDirectory) {
        const reader = entry.createReader();
        const all = [];
        const readBatch = () => {
          reader.readEntries(async (entries) => {
            if (!entries || !entries.length) return resolve(all);
            for (const e of entries) {
              const files = await readAllFilesFromEntry(e);
              for (const f of files) all.push(f);
            }
            readBatch();
          }, () => resolve(all));
        };
        readBatch();
        return;
      }

      resolve([]);
    });
  }

  async function filesFromDataTransfer(dt) {
    const out = [];

    if (dt && dt.files && dt.files.length) {
      for (const f of Array.from(dt.files)) out.push(f);
    }

    const items = (dt && dt.items) ? Array.from(dt.items) : [];
    const entries = [];
    for (const it of items) {
      if (!it) continue;
      if (typeof it.webkitGetAsEntry === 'function') {
        const entry = it.webkitGetAsEntry();
        if (entry) entries.push(entry);
      }
    }

    if (entries.length) {
      const fromEntries = [];
      for (const e of entries) {
        const files = await readAllFilesFromEntry(e);
        for (const f of files) fromEntries.push(f);
      }
      if (fromEntries.length) return fromEntries;
    }

    return out;
  }

  fileInput.addEventListener('change', () => {
    const files = fileInput.files ? Array.from(fileInput.files) : [];
    if (files.length) uploadFilesLarge(files);
  });

  folderInput?.addEventListener('change', () => {
    const files = folderInput.files ? Array.from(folderInput.files) : [];
    if (files.length) uploadFilesLarge(files);
  });

  dz.addEventListener('click', () => fileInput.click());

  function elAtEventPoint(e) {
    try {
      const x = (typeof e.clientX === 'number') ? e.clientX : 0;
      const y = (typeof e.clientY === 'number') ? e.clientY : 0;
      return document.elementFromPoint(x, y);
    } catch (err) {
      return null;
    }
  }

  function isOverDropZoneByPoint(e) {
    const el = elAtEventPoint(e);
    if (!el) return false;
    return (el === dz || dz.contains(el));
  }

  function setDzActive(active) {
    dz.classList.toggle('dz-active', !!active);
  }

  document.addEventListener('dragover', (e) => {
    e.preventDefault();
    const over = isOverDropZoneByPoint(e);
    setDzActive(over);
    if (over && e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
  }, { capture: true, passive: false });

  document.addEventListener('dragenter', (e) => {
    e.preventDefault();
    setDzActive(isOverDropZoneByPoint(e));
  }, { capture: true, passive: false });

  document.addEventListener('dragleave', (e) => {
    e.preventDefault();
    setDzActive(isOverDropZoneByPoint(e));
  }, { capture: true, passive: false });

  document.addEventListener('drop', async (e) => {
    e.preventDefault();
    const over = isOverDropZoneByPoint(e);
    setDzActive(false);

    if (!over) return;

    try {
      const files = await filesFromDataTransfer(e.dataTransfer);
      uploadFilesLarge(files);
    } catch (err) {
      showAlert('err', 'Kunne ikke lese filer fra drop: ' + (err && err.message ? err.message : err));
    }
  }, { capture: true, passive: false });

})();
</script>