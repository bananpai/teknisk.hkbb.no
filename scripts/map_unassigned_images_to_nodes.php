<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\scripts\map_unassigned_images_to_nodes.php
//
// Periodisk mapping av unassigned bilder -> node_location_attachments basert på GPS-avstand.
// - Leser node_location_unassigned_attachments der lat/lon != NULL
// - Finner nærmeste node_locations innen radius (default 20 meter)
// - Flytter filer på disk:
//     storage\unassigned\<dato>\<random>.<ext>  -> storage\node_locations\<nodeId>\<YYYY-MM-DD>_<token>.<ext>
//     storage\unassigned\<dato>\<random>_thumb.<ext> -> storage\node_locations\<nodeId>\<YYYY-MM-DD>_<token>_thumb.<ext>
// - Inserter i node_location_attachments
// - Sletter fra node_location_unassigned_attachments
//
// Kjøring (eksempler):
//   php map_unassigned_images_to_nodes.php
//   php map_unassigned_images_to_nodes.php --dry-run
//   php map_unassigned_images_to_nodes.php --radius=20 --limit=50
//   php map_unassigned_images_to_nodes.php --radius=25 --limit=200 --since-days=30
//
// Forutsetninger:
// - MySQL 8+ (bruker ST_Distance_Sphere). Hvis den ikke finnes, faller vi tilbake til PHP-haversine.
// - node_locations har kolonner lat og lon (decimal), med samme "lat/lon" betydning som unassigned-tabellen.
// - DB-tilkobling forsøkes via /app/database.php (App\Database::getConnection). Hvis ikke, leses .env.
//
// NB: Scriptet skriver til stdout. Bruk Task Scheduler til å logge output ved behov.

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

/* -------------------- CLI args -------------------- */
$opts = [
    'dry-run'    => false,
    'radius'     => 20.0,   // meter
    'limit'      => 200,
    'since-days' => 0,      // 0 = ingen filter
];

foreach ($argv as $i => $arg) {
    if ($i === 0) continue;
    if ($arg === '--dry-run') $opts['dry-run'] = true;
    if (preg_match('/^--radius=(.+)$/', $arg, $m)) $opts['radius'] = (float)$m[1];
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $opts['limit'] = (int)$m[1];
    if (preg_match('/^--since-days=(\d+)$/', $arg, $m)) $opts['since-days'] = (int)$m[1];
}

$RADIUS_M = max(1.0, $opts['radius']);
$LIMIT    = max(1, min(5000, $opts['limit']));
$DRY_RUN  = (bool)$opts['dry-run'];
$SINCE_DAYS = max(0, (int)$opts['since-days']);

echo "== Map unassigned images -> node locations ==\n";
echo "Radius: {$RADIUS_M} m | Limit: {$LIMIT} | Dry-run: " . ($DRY_RUN ? "YES" : "NO") . " | Since-days: {$SINCE_DAYS}\n\n";

/* -------------------- Paths -------------------- */
$PROJECT_ROOT = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$STORAGE_UNASSIGNED = $PROJECT_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'unassigned';
$STORAGE_NODELOC    = $PROJECT_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'node_locations';

if (!is_dir($STORAGE_UNASSIGNED)) {
    fwrite(STDERR, "ERROR: Fant ikke storage/unassigned: {$STORAGE_UNASSIGNED}\n");
    exit(2);
}
if (!is_dir($STORAGE_NODELOC)) {
    // best effort opprett
    @mkdir($STORAGE_NODELOC, 0775, true);
}
if (!is_dir($STORAGE_NODELOC)) {
    fwrite(STDERR, "ERROR: Fant ikke / kunne ikke opprette storage/node_locations: {$STORAGE_NODELOC}\n");
    exit(2);
}

/* -------------------- DB -------------------- */
$pdo = get_pdo($PROJECT_ROOT);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* -------------------- Helpers -------------------- */
function esc_cli(string $s): string {
    // Kun for logg
    return str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], $s);
}

function now_dt(): string {
    return date('Y-m-d H:i:s');
}

function random_token(int $bytes = 8): string {
    return bin2hex(random_bytes($bytes));
}

function ext_from_path_or_mime(string $path, string $mime): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
    if ($ext !== '') return $ext;

    $mime = strtolower(trim($mime));
    return match ($mime) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/png'              => 'png',
        'image/webp'             => 'webp',
        'image/gif'              => 'gif',
        default                  => 'jpg',
    };
}

function safe_join(string $base, string $rel): string {
    $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
    $rel = ltrim($rel, DIRECTORY_SEPARATOR);
    return $base . DIRECTORY_SEPARATOR . $rel;
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
    // Earth radius in meters
    $R = 6371000.0;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlambda = deg2rad($lon2 - $lon1);

    $a = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dlambda/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

function get_pdo(string $projectRoot): PDO {
    // 1) Foretrekk App\Database::getConnection()
    $candidate = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'database.php';
    if (is_file($candidate)) {
        require_once $candidate;
        if (class_exists('App\\Database') && method_exists('App\\Database', 'getConnection')) {
            /** @var PDO $pdo */
            $pdo = App\Database::getConnection();
            return $pdo;
        }
    }

    // 2) Fallback: .env (DB_HOST, DB_NAME, DB_USER, DB_PASS)
    $envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';
    $env = [];
    if (is_file($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            $v = trim($v, "\"'");
            $env[$k] = $v;
        }
    }

    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $db   = $env['DB_NAME'] ?? ($env['MYSQL_DATABASE'] ?? 'teknisk');
    $user = $env['DB_USER'] ?? ($env['MYSQL_USER'] ?? 'root');
    $pass = $env['DB_PASS'] ?? ($env['MYSQL_PASSWORD'] ?? '');

    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/* -------------------- Fetch unassigned images -------------------- */
$where = "u.lat IS NOT NULL AND u.lon IS NOT NULL";
$params = [];

if ($SINCE_DAYS > 0) {
    $where .= " AND u.created_at >= (NOW() - INTERVAL :since_days DAY)";
    $params[':since_days'] = $SINCE_DAYS;
}

// kun bilder (mime starter med image/)
$where .= " AND u.mime_type LIKE 'image/%'";

$sqlUnassigned = "
    SELECT
        u.id, u.file_path, u.thumb_path, u.original_filename, u.description, u.taken_at,
        u.mime_type, u.file_size, u.checksum_sha256, u.caption, u.created_by, u.created_at,
        u.metadata_json, u.lat, u.lon
    FROM node_location_unassigned_attachments u
    WHERE {$where}
    ORDER BY u.created_at ASC
    LIMIT {$LIMIT}
";

$stmt = $pdo->prepare($sqlUnassigned);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$rows = $stmt->fetchAll();

echo "Fant " . count($rows) . " unassigned bilder med GPS (lat/lon).\n\n";
if (!$rows) exit(0);

/* -------------------- Prepare statements -------------------- */
$useDistanceSphere = true;
try {
    $pdo->query("SELECT ST_Distance_Sphere(POINT(0,0), POINT(0,0)) AS d")->fetch();
} catch (Throwable $e) {
    $useDistanceSphere = false;
    echo "NB: ST_Distance_Sphere ikke tilgjengelig. Bruker PHP-haversine fallback.\n";
}

$stmtNearestSql = $useDistanceSphere ? "
    SELECT
        nl.id,
        nl.name,
        nl.lat,
        nl.lon,
        ST_Distance_Sphere(POINT(:ulon, :ulat), POINT(nl.lon, nl.lat)) AS dist_m
    FROM node_locations nl
    WHERE nl.lat IS NOT NULL AND nl.lon IS NOT NULL
    HAVING dist_m <= :radius
    ORDER BY dist_m ASC
    LIMIT 1
" : null;

$stmtNearest = $useDistanceSphere ? $pdo->prepare($stmtNearestSql) : null;

$stmtInsert = $pdo->prepare("
    INSERT INTO node_location_attachments
    (node_location_id, file_path, thumb_path, original_filename, description, taken_at,
     mime_type, file_size, checksum_sha256, metadata_json, caption, created_by, created_at)
    VALUES
    (:node_location_id, :file_path, :thumb_path, :original_filename, :description, :taken_at,
     :mime_type, :file_size, :checksum_sha256, :metadata_json, :caption, :created_by, :created_at)
");

$stmtDeleteUnassigned = $pdo->prepare("DELETE FROM node_location_unassigned_attachments WHERE id = :id");

/* -------------------- Node cache for fallback -------------------- */
$nodesCache = null;
if (!$useDistanceSphere) {
    $nodesCache = $pdo->query("SELECT id, name, lat, lon FROM node_locations WHERE lat IS NOT NULL AND lon IS NOT NULL")->fetchAll();
}

/* -------------------- Process -------------------- */
$mapped = 0;
$skipped = 0;
$failed = 0;

foreach ($rows as $u) {
    $uid = (int)$u['id'];
    $ulat = (float)$u['lat'];
    $ulon = (float)$u['lon'];

    // Finn nærmeste node innen radius
    $best = null;

    if ($useDistanceSphere) {
        $stmtNearest->execute([
            ':ulat'   => $ulat,
            ':ulon'   => $ulon,
            ':radius' => $RADIUS_M,
        ]);
        $best = $stmtNearest->fetch() ?: null;
    } else {
        // PHP fallback
        $bestDist = null;
        foreach ($nodesCache as $nl) {
            $d = haversine_m($ulat, $ulon, (float)$nl['lat'], (float)$nl['lon']);
            if ($d <= $RADIUS_M && ($bestDist === null || $d < $bestDist)) {
                $bestDist = $d;
                $best = [
                    'id' => $nl['id'],
                    'name' => $nl['name'],
                    'lat' => $nl['lat'],
                    'lon' => $nl['lon'],
                    'dist_m' => $d,
                ];
            }
        }
    }

    if (!$best) {
        $skipped++;
        echo "[SKIP] u#{$uid} ingen node innen {$RADIUS_M}m. (lat/lon={$ulat},{$ulon})\n";
        continue;
    }

    $nodeId = (int)$best['id'];
    $dist   = (float)$best['dist_m'];
    $nodeName = (string)($best['name'] ?? '');

    $srcRel = (string)$u['file_path'];
    $srcThumbRel = (string)($u['thumb_path'] ?? '');

    $mime = (string)$u['mime_type'];
    $ext = ext_from_path_or_mime($srcRel, $mime);

    // Nytt filnavn under node (bruker dato fra opplasting)
    $uploadDate = '';
    try {
        $uploadDate = (new DateTime((string)$u['created_at']))->format('Y-m-d');
    } catch (Throwable $e) {
        $uploadDate = date('Y-m-d');
    }
    $token = random_token(6); // 12 hex
    $newBaseName = "{$uploadDate}_{$token}";
    $dstRel      = $nodeId . "/" . $newBaseName . "." . $ext;
    $dstThumbRel = $nodeId . "/" . $newBaseName . "_thumb." . $ext;

    $srcFull = safe_join($GLOBALS['STORAGE_UNASSIGNED'], $srcRel);
    $srcThumbFull = $srcThumbRel !== '' ? safe_join($GLOBALS['STORAGE_UNASSIGNED'], $srcThumbRel) : '';

    $dstDir = $GLOBALS['STORAGE_NODELOC'] . DIRECTORY_SEPARATOR . $nodeId;
    ensure_dir($dstDir);

    $dstFull = safe_join($GLOBALS['STORAGE_NODELOC'], $dstRel);
    $dstThumbFull = safe_join($GLOBALS['STORAGE_NODELOC'], $dstThumbRel);

    // Hvis thumb mangler, behold thumb_path NULL
    $hasThumb = ($srcThumbRel !== '' && is_file($srcThumbFull));

    // Sjekk at original finnes
    if (!is_file($srcFull)) {
        $failed++;
        echo "[FAIL] u#{$uid} mangler fil på disk: {$srcFull}\n";
        continue;
    }

    echo "[MAP] u#{$uid} -> node#{$nodeId} (" . esc_cli($nodeName) . "), dist=" . number_format($dist, 2, '.', '') . "m | {$srcRel} -> {$dstRel}\n";

    if ($DRY_RUN) {
        $mapped++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        // Flytt original
        if (!@rename($srcFull, $dstFull)) {
            // fallback: copy+unlink
            if (!@copy($srcFull, $dstFull) || !@unlink($srcFull)) {
                throw new RuntimeException("Klarte ikke flytte originalfil: {$srcFull} -> {$dstFull}");
            }
        }

        // Flytt thumb hvis finnes
        $thumbPathDb = null;
        if ($hasThumb) {
            if (!@rename($srcThumbFull, $dstThumbFull)) {
                if (!@copy($srcThumbFull, $dstThumbFull) || !@unlink($srcThumbFull)) {
                    throw new RuntimeException("Klarte ikke flytte thumb: {$srcThumbFull} -> {$dstThumbFull}");
                }
            }
            $thumbPathDb = $dstThumbRel;
        }

        // Insert attachments
        $stmtInsert->execute([
            ':node_location_id'   => $nodeId,
            ':file_path'          => $dstRel,
            ':thumb_path'         => $thumbPathDb,
            ':original_filename'  => (string)$u['original_filename'],
            ':description'        => $u['description'] !== null ? (string)$u['description'] : null,
            ':taken_at'           => $u['taken_at'] !== null ? (string)$u['taken_at'] : null,
            ':mime_type'          => (string)$u['mime_type'],
            ':file_size'          => (int)$u['file_size'],
            ':checksum_sha256'    => $u['checksum_sha256'] !== null ? (string)$u['checksum_sha256'] : null,
            ':metadata_json'      => $u['metadata_json'] !== null ? (string)$u['metadata_json'] : null,
            ':caption'            => $u['caption'] !== null ? (string)$u['caption'] : null,
            ':created_by'         => $u['created_by'] !== null ? (string)$u['created_by'] : null,
            ':created_at'         => (string)$u['created_at'], // behold original upload-tid
        ]);

        // Delete unassigned row
        $stmtDeleteUnassigned->execute([':id' => $uid]);

        $pdo->commit();
        $mapped++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $failed++;

        // Best effort revert hvis vi flyttet original men feilet senere (kan skje)
        // Vi prøver kun hvis dst finnes og src ikke finnes.
        try {
            if (is_file($dstFull) && !is_file($srcFull)) {
                @rename($dstFull, $srcFull);
            }
            if ($hasThumb && is_file($dstThumbFull) && !is_file($srcThumbFull)) {
                @rename($dstThumbFull, $srcThumbFull);
            }
        } catch (Throwable $e2) {
            // ignorer
        }

        echo "[FAIL] u#{$uid} exception: " . esc_cli($e->getMessage()) . "\n";
    }
}

echo "\n== Ferdig ==\n";
echo "Mapped: {$mapped} | Skipped (ingen treff): {$skipped} | Failed: {$failed}\n";
echo "Tid: " . now_dt() . "\n";
exit($failed > 0 ? 1 : 0);