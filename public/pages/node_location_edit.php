<?php
// Path: public/pages/node_location_edit.php

use App\Database;

$pdo = Database::getConnection();
$username = $_SESSION['username'] ?? '';

/**
 * VIKTIG:
 * - menu.php hos dere definerer normalize_list/has_any, så vi MÅ ikke redeclare.
 * - Denne filen kan likevel kjøres uten menu.php i noen kontekster -> derfor function_exists guards.
 */

if (!function_exists('normalize_list')) {
    function normalize_list($v): array {
        if (is_array($v)) return array_values(array_filter(array_map('strval', $v)));
        if (is_string($v) && trim($v) !== '') {
            $parts = preg_split('/[,\s;]+/', $v);
            return array_values(array_filter(array_map('strval', $parts)));
        }
        return [];
    }
}

if (!function_exists('has_any')) {
    function has_any(array $needles, array $haystack): bool {
        $haystack = array_map('strtolower', $haystack);
        foreach ($needles as $n) {
            if (in_array(strtolower($n), $haystack, true)) return true;
        }
        return false;
    }
}

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('slugify')) {
    function slugify(string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('~[^\pL\pN]+~u', '-', $s);
        $s = trim($s, '-');
        return $s ?: 'feltobjekt';
    }
}

/* ---------------- CSRF (robust / guards) ---------------- */
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        return (string)$_SESSION['_csrf'];
    }
}
if (!function_exists('csrf_validate')) {
    function csrf_validate(?string $token): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $sess = (string)($_SESSION['_csrf'] ?? '');
        return $token !== null && $sess !== '' && hash_equals($sess, (string)$token);
    }
}

// ---------------------------------------------------------
// Storage (utenfor public/)
// ---------------------------------------------------------
if (!function_exists('storageBaseDir')) {
    function storageBaseDir(): string {
        $base = realpath(__DIR__ . '/../../storage/node_locations');
        if ($base) return $base;
        return __DIR__ . '/../../storage/node_locations';
    }
}

if (!function_exists('ensureStorageDir')) {
    function ensureStorageDir(int $nodeId): string {
        $base = storageBaseDir();
        $dir = rtrim($base, '/\\') . '/' . $nodeId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('absPathFromStorageKey')) {
    function absPathFromStorageKey(string $key): string {
        $key = trim((string)$key);
        if ($key === '' || str_contains($key, '..') || $key[0] === '/' || str_contains($key, '\\')) return '';

        $base = storageBaseDir();
        $full = rtrim($base, '/\\') . '/' . $key;

        $rpBase = realpath($base);
        $rpFull = realpath($full);
        if (!$rpBase || !$rpFull) return '';

        if (strpos($rpFull, $rpBase) !== 0) return '';
        return $rpFull;
    }
}

if (!function_exists('exifTakenAt')) {
    function exifTakenAt(string $absPath): ?string {
        if (!is_file($absPath)) return null;
        if (!function_exists('exif_read_data')) return null;

        $exif = @exif_read_data($absPath, 'EXIF', true, false);
        if (!is_array($exif)) return null;

        $candidates = [
            $exif['EXIF']['DateTimeOriginal'] ?? null,
            $exif['EXIF']['DateTimeDigitized'] ?? null,
            $exif['IFD0']['DateTime'] ?? null,
        ];

        foreach ($candidates as $dt) {
            $dt = is_string($dt) ? trim($dt) : '';
            if ($dt === '') continue;
            $d = \DateTime::createFromFormat('Y:m:d H:i:s', $dt);
            if ($d instanceof \DateTime) return $d->format('Y-m-d H:i:s');
        }
        return null;
    }
}

// ---------------------------------------------------------
// Guard: admin eller node_write via user_roles
// ---------------------------------------------------------
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

$roles = normalize_list($_SESSION['roles'] ?? null);

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $currentUserId = (int)($stmt->fetchColumn() ?: 0);

    if ($currentUserId > 0) {
        $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $stmt->execute([':uid' => $currentUserId]);
        $dbRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $roles = array_merge($roles, normalize_list($dbRoles));
    }
} catch (\Throwable $e) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang (DB-feil).</div>
    <?php
    return;
}

$roles = array_values(array_unique(array_map('strtolower', $roles)));
$isAdmin      = has_any(['admin'], $roles);
$canNodeWrite = $isAdmin || has_any(['node_write'], $roles);

if (!$canNodeWrite) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Status (lagres som kode i DB, vises på norsk)
// ---------------------------------------------------------
$statusOptions = [
    'active'         => 'Aktiv',
    'inactive'       => 'Inaktiv',
    'planned'        => 'Planlagt',
    'decommissioned' => 'Avviklet',
];

$id = (int)($_GET['id'] ?? 0);

$templates = $pdo->query("SELECT id, name FROM node_location_templates WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$messages = [];

$node = [
    'id' => 0,
    'template_id' => (int)($_GET['template_id'] ?? 0),
    'name' => '',
    'slug' => '',
    'status' => 'active',
    'description' => '',
    'address_line1' => '',
    'address_line2' => '',
    'postal_code' => '',
    'city' => '',
    // skjulte felt (beholdes)
    'region' => '',
    'country' => '',         // DB: varchar(2) -> vi normaliserer
    'lat' => '',
    'lon' => '',
    'external_source' => '',
    'external_id' => '',
];

$valuesByFieldId = [];
$attachments = [];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM node_locations WHERE id=:id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $errors[] = "Fant ikke feltobjekt.";
    } else {
        $node = array_merge($node, $row);

        $stmt = $pdo->prepare("SELECT field_id, value_text, value_number, value_date, value_datetime, value_bool, value_json
                                 FROM node_location_custom_field_values
                                WHERE node_location_id=:id");
        $stmt->execute([':id' => $id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $valuesByFieldId[(int)$v['field_id']] = $v;
        }

        $stmt = $pdo->prepare("SELECT * FROM node_location_attachments WHERE node_location_id=:id ORDER BY created_at DESC");
        $stmt->execute([':id' => $id]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

function loadFields(PDO $pdo, int $templateId): array {
    if ($templateId <= 0) return [];
    $sql = "
      SELECT f.*, g.name AS group_name, g.sort_order AS group_sort
        FROM node_location_custom_fields f
        LEFT JOIN node_location_field_groups g ON g.id = f.group_id
       WHERE f.template_id = :tid
       ORDER BY COALESCE(g.sort_order, 999999), COALESCE(g.name,''), f.sort_order, f.label
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':tid' => $templateId]);
    $fields = $st->fetchAll(PDO::FETCH_ASSOC);

    $fieldIdsNeedingOptions = [];
    foreach ($fields as $f) {
        if (in_array($f['field_type'], ['select','multiselect'], true)) {
            $fieldIdsNeedingOptions[] = (int)$f['id'];
        }
    }

    $optionsByField = [];
    if ($fieldIdsNeedingOptions) {
        $in = implode(',', array_fill(0, count($fieldIdsNeedingOptions), '?'));
        $st2 = $pdo->prepare("SELECT * FROM node_location_custom_field_options WHERE field_id IN ($in) ORDER BY sort_order, opt_label");
        $st2->execute($fieldIdsNeedingOptions);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $optionsByField[(int)$o['field_id']][] = $o;
        }
    }

    foreach ($fields as &$f) {
        $f['options'] = $optionsByField[(int)$f['id']] ?? [];
    }
    unset($f);

    return $fields;
}

function upsertValue(PDO $pdo, int $nodeId, array $field, $raw, string $username): void {
    $fieldId = (int)$field['id'];
    $type = (string)$field['field_type'];

    $payload = [
        'value_text' => null,
        'value_number' => null,
        'value_date' => null,
        'value_datetime' => null,
        'value_bool' => null,
        'value_json' => null,
    ];

    if ($type === 'bool') {
        $payload['value_bool'] = ($raw ? 1 : 0);
    } elseif ($type === 'number') {
        $payload['value_number'] = ($raw === '' || $raw === null) ? null : (string)(0 + $raw);
    } elseif ($type === 'date') {
        $payload['value_date'] = ($raw === '' || $raw === null) ? null : $raw;
    } elseif ($type === 'datetime') {
        if ($raw === '' || $raw === null) $payload['value_datetime'] = null;
        else {
            $tmp = str_replace('T', ' ', (string)$raw);
            if (strlen($tmp) === 16) $tmp .= ':00';
            $payload['value_datetime'] = $tmp;
        }
    } elseif ($type === 'json') {
        if ($raw === '' || $raw === null) $payload['value_json'] = null;
        else {
            $decoded = json_decode((string)$raw, true);
            $payload['value_json'] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
    } elseif ($type === 'multiselect') {
        $arr = is_array($raw) ? array_values($raw) : [];
        $payload['value_json'] = json_encode($arr, JSON_UNESCAPED_UNICODE);
    } else {
        $payload['value_text'] = ($raw === '' ? null : (string)$raw);
    }

    $sql = "
      INSERT INTO node_location_custom_field_values
        (node_location_id, field_id, value_text, value_number, value_date, value_datetime, value_bool, value_json, updated_by)
      VALUES
        (:nid, :fid, :vt, :vn, :vd, :vdt, :vb, :vj, :ub)
      ON DUPLICATE KEY UPDATE
        value_text=VALUES(value_text),
        value_number=VALUES(value_number),
        value_date=VALUES(value_date),
        value_datetime=VALUES(value_datetime),
        value_bool=VALUES(value_bool),
        value_json=VALUES(value_json),
        updated_by=VALUES(updated_by)
    ";

    $pdo->prepare($sql)->execute([
        ':nid' => $nodeId,
        ':fid' => $fieldId,
        ':vt'  => $payload['value_text'],
        ':vn'  => $payload['value_number'],
        ':vd'  => $payload['value_date'],
        ':vdt' => $payload['value_datetime'],
        ':vb'  => $payload['value_bool'],
        ':vj'  => $payload['value_json'],
        ':ub'  => $username,
    ]);
}

function normalizeCountry2(?string $country): ?string {
    $country = trim((string)$country);
    if ($country === '') return null;
    $country = strtoupper($country);
    $country = preg_replace('/[^A-Z]/', '', $country);
    if ($country === '') return null;
    return substr($country, 0, 2);
}

function attachmentUrl(int $attId): string {
    return '/api/node_location_attachment_file.php?id=' . $attId;
}

$fields = loadFields($pdo, (int)$node['template_id']);

/* ---------------------------------------------------------
   POST: delete node (egen action)
--------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_node_location'])) {
    if ($id <= 0) {
        $errors[] = "Ugyldig ID for sletting.";
    } elseif (!csrf_validate($_POST['_csrf'] ?? null)) {
        $errors[] = "Ugyldig CSRF-token. Last siden på nytt og prøv igjen.";
    } else {
        try {
            $pdo->beginTransaction();

            // hent vedlegg for å slette filer
            $stmt = $pdo->prepare("SELECT id, file_path FROM node_location_attachments WHERE node_location_id=:id");
            $stmt->execute([':id' => $id]);
            $attRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // slett custom field values
            $pdo->prepare("DELETE FROM node_location_custom_field_values WHERE node_location_id=:id")
                ->execute([':id' => $id]);

            // slett vedlegg i DB først
            $pdo->prepare("DELETE FROM node_location_attachments WHERE node_location_id=:id")
                ->execute([':id' => $id]);

            // slett selve noden (kan feile hvis FK andre steder)
            $pdo->prepare("DELETE FROM node_locations WHERE id=:id LIMIT 1")
                ->execute([':id' => $id]);

            $pdo->commit();

            // slett filer etter commit (best-effort)
            foreach ($attRows as $a) {
                $abs = absPathFromStorageKey((string)($a['file_path'] ?? ''));
                if ($abs && is_file($abs)) @unlink($abs);
            }

            // prøv å rydde opp mappen hvis tom
            $dir = rtrim(storageBaseDir(), '/\\') . '/' . $id;
            if (is_dir($dir)) {
                $files = @scandir($dir);
                if (is_array($files)) {
                    $left = array_values(array_diff($files, ['.','..']));
                    if (count($left) === 0) @rmdir($dir);
                }
            }

            header("Location: /?page=node_locations&deleted=1");
            exit;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = "Kunne ikke slette feltobjektet. Det kan være i bruk et annet sted (FK), eller DB-feil: " . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------
// POST: save node + upload
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_node_location'])) {

    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $errors[] = "Ugyldig CSRF-token. Last siden på nytt og prøv igjen.";
    } else {

        $node['template_id']   = (int)($_POST['template_id'] ?? $node['template_id'] ?? 0);
        $node['name']          = trim($_POST['name'] ?? '');
        $node['status']        = trim($_POST['status'] ?? 'active');
        $node['description']   = trim($_POST['description'] ?? '');
        $node['address_line1'] = trim($_POST['address_line1'] ?? '');
        $node['address_line2'] = trim($_POST['address_line2'] ?? '');
        $node['postal_code']   = trim($_POST['postal_code'] ?? '');
        $node['city']          = trim($_POST['city'] ?? '');

        // Skjulte felt (beholdes via hidden inputs)
        $node['region']          = trim($_POST['region'] ?? (string)($node['region'] ?? ''));
        $node['country']         = trim($_POST['country'] ?? (string)($node['country'] ?? ''));
        $node['external_source'] = trim($_POST['external_source'] ?? (string)($node['external_source'] ?? ''));
        $node['external_id']     = trim($_POST['external_id'] ?? (string)($node['external_id'] ?? ''));

        $node['lat'] = trim($_POST['lat'] ?? '');
        $node['lon'] = trim($_POST['lon'] ?? '');

        if ($node['template_id'] <= 0) $errors[] = "Velg mal.";
        if ($node['name'] === '') $errors[] = "Navn er påkrevd.";

        // slug genereres automatisk og vises ikke
        $node['slug'] = slugify($node['name']);

        if (!isset($statusOptions[$node['status']])) $node['status'] = 'active';

        $fields = loadFields($pdo, (int)$node['template_id']);

        foreach ($fields as $f) {
            if ((int)$f['is_required'] !== 1) continue;
            $key  = (string)$f['field_key'];
            $type = (string)$f['field_type'];

            if ($type === 'multiselect') {
                $raw = $_POST['cf'][$key] ?? [];
                if (!is_array($raw) || count($raw) === 0) $errors[] = "Feltet '{$f['label']}' er påkrevd.";
            } elseif ($type === 'bool') {
                // ok
            } else {
                $raw = $_POST['cf'][$key] ?? '';
                if (trim((string)$raw) === '') $errors[] = "Feltet '{$f['label']}' er påkrevd.";
            }
        }

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                $country2 = normalizeCountry2($node['country']);

                if ($id > 0) {
                    // prøv med updated_by først
                    try {
                        $pdo->prepare("
                          UPDATE node_locations
                             SET template_id=:tid, name=:n, slug=:s, status=:st, description=:d,
                                 address_line1=:a1, address_line2=:a2, postal_code=:pc, city=:c,
                                 region=:r, country=:co,
                                 lat=:lat, lon=:lon,
                                 external_source=:es, external_id=:eid,
                                 updated_by=:ub
                           WHERE id=:id
                        ")->execute([
                            ':tid' => (int)$node['template_id'],
                            ':n'   => $node['name'],
                            ':s'   => $node['slug'],
                            ':st'  => $node['status'],
                            ':d'   => $node['description'],
                            ':a1'  => $node['address_line1'],
                            ':a2'  => $node['address_line2'],
                            ':pc'  => $node['postal_code'],
                            ':c'   => $node['city'],
                            ':r'   => ($node['region'] === '' ? null : $node['region']),
                            ':co'  => $country2,
                            ':lat' => ($node['lat'] === '' ? null : $node['lat']),
                            ':lon' => ($node['lon'] === '' ? null : $node['lon']),
                            ':es'  => ($node['external_source'] === '' ? null : $node['external_source']),
                            ':eid' => ($node['external_id'] === '' ? null : $node['external_id']),
                            ':ub'  => $username,
                            ':id'  => $id
                        ]);
                    } catch (\Throwable $e) {
                        // fallback hvis kolonnen ikke finnes
                        $pdo->prepare("
                          UPDATE node_locations
                             SET template_id=:tid, name=:n, slug=:s, status=:st, description=:d,
                                 address_line1=:a1, address_line2=:a2, postal_code=:pc, city=:c,
                                 region=:r, country=:co,
                                 lat=:lat, lon=:lon,
                                 external_source=:es, external_id=:eid
                           WHERE id=:id
                        ")->execute([
                            ':tid' => (int)$node['template_id'],
                            ':n'   => $node['name'],
                            ':s'   => $node['slug'],
                            ':st'  => $node['status'],
                            ':d'   => $node['description'],
                            ':a1'  => $node['address_line1'],
                            ':a2'  => $node['address_line2'],
                            ':pc'  => $node['postal_code'],
                            ':c'   => $node['city'],
                            ':r'   => ($node['region'] === '' ? null : $node['region']),
                            ':co'  => $country2,
                            ':lat' => ($node['lat'] === '' ? null : $node['lat']),
                            ':lon' => ($node['lon'] === '' ? null : $node['lon']),
                            ':es'  => ($node['external_source'] === '' ? null : $node['external_source']),
                            ':eid' => ($node['external_id'] === '' ? null : $node['external_id']),
                            ':id'  => $id
                        ]);
                    }
                } else {
                    // prøv med created_by + updated_by, fallback uten updated_by
                    try {
                        $pdo->prepare("
                          INSERT INTO node_locations
                            (template_id, name, slug, status, description,
                             address_line1, address_line2, postal_code, city, region, country,
                             lat, lon, external_source, external_id, created_by, updated_by)
                          VALUES
                            (:tid, :n, :s, :st, :d,
                             :a1, :a2, :pc, :c, :r, :co,
                             :lat, :lon, :es, :eid, :cb, :ub)
                        ")->execute([
                            ':tid' => (int)$node['template_id'],
                            ':n'   => $node['name'],
                            ':s'   => $node['slug'],
                            ':st'  => $node['status'],
                            ':d'   => $node['description'],
                            ':a1'  => $node['address_line1'],
                            ':a2'  => $node['address_line2'],
                            ':pc'  => $node['postal_code'],
                            ':c'   => $node['city'],
                            ':r'   => ($node['region'] === '' ? null : $node['region']),
                            ':co'  => $country2,
                            ':lat' => ($node['lat'] === '' ? null : $node['lat']),
                            ':lon' => ($node['lon'] === '' ? null : $node['lon']),
                            ':es'  => ($node['external_source'] === '' ? null : $node['external_source']),
                            ':eid' => ($node['external_id'] === '' ? null : $node['external_id']),
                            ':cb'  => $username,
                            ':ub'  => $username,
                        ]);
                    } catch (\Throwable $e) {
                        $pdo->prepare("
                          INSERT INTO node_locations
                            (template_id, name, slug, status, description,
                             address_line1, address_line2, postal_code, city, region, country,
                             lat, lon, external_source, external_id, created_by)
                          VALUES
                            (:tid, :n, :s, :st, :d,
                             :a1, :a2, :pc, :c, :r, :co,
                             :lat, :lon, :es, :eid, :cb)
                        ")->execute([
                            ':tid' => (int)$node['template_id'],
                            ':n'   => $node['name'],
                            ':s'   => $node['slug'],
                            ':st'  => $node['status'],
                            ':d'   => $node['description'],
                            ':a1'  => $node['address_line1'],
                            ':a2'  => $node['address_line2'],
                            ':pc'  => $node['postal_code'],
                            ':c'   => $node['city'],
                            ':r'   => ($node['region'] === '' ? null : $node['region']),
                            ':co'  => $country2,
                            ':lat' => ($node['lat'] === '' ? null : $node['lat']),
                            ':lon' => ($node['lon'] === '' ? null : $node['lon']),
                            ':es'  => ($node['external_source'] === '' ? null : $node['external_source']),
                            ':eid' => ($node['external_id'] === '' ? null : $node['external_id']),
                            ':cb'  => $username
                        ]);
                    }

                    $id = (int)$pdo->lastInsertId();
                    $node['id'] = $id;
                }

                // save custom fields
                foreach ($fields as $f) {
                    $key  = (string)$f['field_key'];
                    $type = (string)$f['field_type'];

                    if ($type === 'bool') $raw = isset($_POST['cf'][$key]) ? 1 : 0;
                    elseif ($type === 'multiselect') {
                        $raw = $_POST['cf'][$key] ?? [];
                        if (!is_array($raw)) $raw = [];
                    } else $raw = $_POST['cf'][$key] ?? '';

                    if ($type === 'json' && trim((string)$raw) !== '') {
                        json_decode((string)$raw, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new RuntimeException("Ugyldig JSON i feltet '{$f['label']}'.");
                        }
                    }

                    upsertValue($pdo, $id, $f, $raw, $username);
                }

                // uploads -> STORAGE (ikke public/)
                if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
                    $storageDir = ensureStorageDir($id);

                    for ($i = 0; $i < count($_FILES['photos']['name']); $i++) {
                        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;

                        $tmp  = $_FILES['photos']['tmp_name'][$i];
                        $orig = $_FILES['photos']['name'][$i];
                        $size = (int)$_FILES['photos']['size'][$i];

                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime  = $finfo->file($tmp) ?: 'application/octet-stream';

                        $allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
                        if (!in_array($mime, $allowed, true)) continue;

                        $ext = '';
                        if ($mime === 'image/jpeg') $ext = '.jpg';
                        elseif ($mime === 'image/png') $ext = '.png';
                        elseif ($mime === 'image/webp') $ext = '.webp';
                        elseif ($mime === 'application/pdf') $ext = '.pdf';

                        $bytes = @file_get_contents($tmp);
                        if ($bytes === false) continue;

                        $sha = hash('sha256', $bytes);

                        $filename = date('Ymd_His') . '_' . substr($sha, 0, 12) . $ext;
                        $destPath = rtrim($storageDir, '/\\') . '/' . $filename;

                        if (!move_uploaded_file($tmp, $destPath)) continue;

                        $storageKey = $id . '/' . $filename;

                        $takenAt = null;
                        if ($mime === 'image/jpeg') $takenAt = exifTakenAt($destPath);

                        try {
                            $pdo->prepare("
                              INSERT INTO node_location_attachments
                                (node_location_id, file_path, original_filename, mime_type, file_size, checksum_sha256, created_by, description, taken_at)
                              VALUES
                                (:id, :p, :o, :m, :s, :c, :u, NULL, :t)
                            ")->execute([
                                ':id' => $id,
                                ':p'  => $storageKey,
                                ':o'  => $orig,
                                ':m'  => $mime,
                                ':s'  => $size,
                                ':c'  => $sha,
                                ':u'  => $username,
                                ':t'  => ($takenAt ?: null),
                            ]);
                        } catch (\Throwable $e) {
                            $pdo->prepare("
                              INSERT INTO node_location_attachments
                                (node_location_id, file_path, original_filename, mime_type, file_size, checksum_sha256, created_by)
                              VALUES
                                (:id, :p, :o, :m, :s, :c, :u)
                            ")->execute([
                                ':id' => $id,
                                ':p'  => $storageKey,
                                ':o'  => $orig,
                                ':m'  => $mime,
                                ':s'  => $size,
                                ':c'  => $sha,
                                ':u'  => $username
                            ]);
                        }
                    }
                }

                $pdo->commit();

                header("Location: /?page=node_location_view&id=" . (int)$id . "&saved=1");
                exit;

            } catch (\Throwable $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Re-read values/attachments for display
$fields = loadFields($pdo, (int)$node['template_id']);

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT field_id, value_text, value_number, value_date, value_datetime, value_bool, value_json
                             FROM node_location_custom_field_values
                            WHERE node_location_id=:id");
    $stmt->execute([':id' => $id]);
    $valuesByFieldId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) $valuesByFieldId[(int)$v['field_id']] = $v;

    $stmt = $pdo->prepare("SELECT * FROM node_location_attachments WHERE node_location_id=:id ORDER BY created_at DESC");
    $stmt->execute([':id' => $id]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($attachments as &$a) {
        $mime = (string)($a['mime_type'] ?? '');
        if ($mime === 'image/jpeg' && empty($a['taken_at'])) {
            $abs = absPathFromStorageKey((string)($a['file_path'] ?? ''));
            if ($abs) {
                $ta = exifTakenAt($abs);
                if ($ta) $a['taken_at'] = $ta;
            }
        }
    }
    unset($a);
}

function fieldValueForForm(array $field, array $valuesByFieldId) {
    $fid = (int)$field['id'];
    $type = (string)$field['field_type'];
    $v = $valuesByFieldId[$fid] ?? null;

    if (!$v) return $type === 'multiselect' ? [] : '';

    if ($type === 'bool') return (int)($v['value_bool'] ?? 0);
    if ($type === 'number') return $v['value_number'] ?? '';
    if ($type === 'date') return $v['value_date'] ?? '';
    if ($type === 'datetime') {
        if (!$v['value_datetime']) return '';
        return str_replace(' ', 'T', substr((string)$v['value_datetime'], 0, 16));
    }
    if ($type === 'json') return $v['value_json'] ? json_encode(json_decode((string)$v['value_json'], true), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : '';
    if ($type === 'multiselect') {
        $arr = $v['value_json'] ? json_decode((string)$v['value_json'], true) : [];
        return is_array($arr) ? $arr : [];
    }
    return $v['value_text'] ?? '';
}

// Gruppér felter pr gruppe (for UI)
$grouped = [];
foreach ($fields as $f) {
    $gname = $f['group_name'] ?: 'Generelt';
    $grouped[$gname][] = $f;
}

$latVal = ($node['lat'] === '' || $node['lat'] === null) ? null : (float)$node['lat'];
$lonVal = ($node['lon'] === '' || $node['lon'] === null) ? null : (float)$node['lon'];
$defaultLat = 59.9139;
$defaultLon = 10.7522;

$imageCount = 0;
foreach ($attachments as $a) {
    $mime = (string)($a['mime_type'] ?? '');
    if (strpos($mime, 'image/') === 0) $imageCount++;
}

// UI state for accordions
$openBasics = true;
$openAddress = false;
$openMap = ($latVal !== null && $lonVal !== null);
$openDynamic = ((int)$node['template_id'] > 0);
$openAttachments = true;
$openDanger = false;
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">
<style>
  /* Kart */
  #nlMapPick { height: 340px; border-radius: .375rem; }

  /* Viewer */
  #nlViewerImgWrap {
    max-height: 72vh;
    overflow: auto;
    border-radius: .375rem;
  }
  #nlViewerImg {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 0 auto;
    cursor: zoom-in;
    user-select: none;
  }
  #nlViewerImg.zoomed {
    max-width: none;
    cursor: grab;
  }
  #nlViewerImg.zoomed:active {
    cursor: grabbing;
  }

  .nl-attach-card { position: relative; }
  .nl-attach-actions {
    position: absolute;
    top: .5rem;
    right: .5rem;
    display: flex;
    gap: .25rem;
  }

  /* Små forbedringer på accordion */
  .accordion-button .badge { margin-left: .5rem; }
</style>

<div class="d-flex align-items-center justify-content-between mt-3">
  <div>
    <h3 class="mb-0"><?= $id > 0 ? 'Rediger feltobjekt' : 'Nytt feltobjekt' ?></h3>
    <?php if ($id > 0): ?><div class="text-muted small">ID: <?= (int)$id ?></div><?php endif; ?>
  </div>
  <div class="d-flex gap-2">
    <?php if ($id > 0): ?>
      <a class="btn btn-outline-secondary" href="/?page=node_location_view&id=<?= (int)$id ?>">Avbryt</a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="/?page=node_locations">Til feltobjekter</a>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger mt-3">
    <b>Kunne ikke lagre</b>
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form class="mt-3" method="post" enctype="multipart/form-data" id="nlEditForm">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

  <!-- skjulte felter (ikke vis i skjema) -->
  <input type="hidden" id="nlRegion" name="region" value="<?= h((string)$node['region']) ?>">
  <input type="hidden" id="nlCountry" name="country" value="<?= h((string)$node['country']) ?>">
  <input type="hidden" name="external_source" value="<?= h((string)$node['external_source']) ?>">
  <input type="hidden" name="external_id" value="<?= h((string)$node['external_id']) ?>">

  <!-- ACCORDION: Tydelig gruppering -->
  <div class="accordion" id="nlEditAccordion">

    <!-- Grunninfo -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="nlAccHeadBasics">
        <button class="accordion-button <?= $openBasics ? '' : 'collapsed' ?>" type="button"
                data-bs-toggle="collapse" data-bs-target="#nlAccBasics" aria-expanded="<?= $openBasics ? 'true' : 'false' ?>"
                aria-controls="nlAccBasics">
          Grunninfo
          <?php if ((int)$node['template_id'] > 0): ?>
            <span class="badge text-bg-secondary">Mal valgt</span>
          <?php endif; ?>
        </button>
      </h2>
      <div id="nlAccBasics" class="accordion-collapse collapse <?= $openBasics ? 'show' : '' ?>"
           aria-labelledby="nlAccHeadBasics" data-bs-parent="#nlEditAccordion">
        <div class="accordion-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Objekttype (mal)</label>
              <select class="form-select" id="nlTemplate" name="template_id">
                <option value="0">Velg...</option>
                <?php foreach ($templates as $t): ?>
                  <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === (int)$node['template_id'] ? 'selected' : '') ?>>
                    <?= h($t['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Bytter du mal, lastes siden på nytt og viser feltene for den malen.</div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Navn</label>
              <input class="form-control" name="name" value="<?= h((string)$node['name']) ?>">
              <div class="form-text">Slug genereres automatisk.</div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select class="form-select" name="status">
                <?php foreach ($statusOptions as $value => $label): ?>
                  <option value="<?= h($value) ?>" <?= ((string)$node['status'] === (string)$value ? 'selected' : '') ?>>
                    <?= h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Beskrivelse</label>
              <textarea class="form-control" name="description" rows="2"><?= h((string)$node['description']) ?></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Adresse -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="nlAccHeadAddress">
        <button class="accordion-button <?= $openAddress ? '' : 'collapsed' ?>" type="button"
                data-bs-toggle="collapse" data-bs-target="#nlAccAddress" aria-expanded="<?= $openAddress ? 'true' : 'false' ?>"
                aria-controls="nlAccAddress">
          Adresse
        </button>
      </h2>
      <div id="nlAccAddress" class="accordion-collapse collapse <?= $openAddress ? 'show' : '' ?>"
           aria-labelledby="nlAccHeadAddress" data-bs-parent="#nlEditAccordion">
        <div class="accordion-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Adresse linje 1</label>
              <input class="form-control" id="nlAddr1" name="address_line1" value="<?= h((string)$node['address_line1']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Adresse linje 2</label>
              <input class="form-control" id="nlAddr2" name="address_line2" value="<?= h((string)$node['address_line2']) ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label">Postnr</label>
              <input class="form-control" id="nlPostal" name="postal_code" value="<?= h((string)$node['postal_code']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">By</label>
              <input class="form-control" id="nlCity" name="city" value="<?= h((string)$node['city']) ?>">
            </div>

            <div class="col-12">
              <div class="alert alert-info mb-0">
                Tips: Du kan fylle adresse automatisk via <b>Hent adresse fra koordinater</b> i kart-seksjonen.
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Kart og koordinater -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="nlAccHeadMap">
        <button class="accordion-button <?= $openMap ? '' : 'collapsed' ?>" type="button"
                data-bs-toggle="collapse" data-bs-target="#nlAccMap" aria-expanded="<?= $openMap ? 'true' : 'false' ?>"
                aria-controls="nlAccMap">
          Kart og koordinater
          <?php if ($latVal !== null && $lonVal !== null): ?>
            <span class="badge text-bg-success">Posisjon satt</span>
          <?php else: ?>
            <span class="badge text-bg-secondary">Ingen posisjon</span>
          <?php endif; ?>
        </button>
      </h2>
      <div id="nlAccMap" class="accordion-collapse collapse <?= $openMap ? 'show' : '' ?>"
           aria-labelledby="nlAccHeadMap" data-bs-parent="#nlEditAccordion">
        <div class="accordion-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Lat</label>
              <input class="form-control" id="nlLat" name="lat" value="<?= h((string)$node['lat']) ?>" placeholder="59.9139000">
            </div>
            <div class="col-md-3">
              <label class="form-label">Lon</label>
              <input class="form-control" id="nlLon" name="lon" value="<?= h((string)$node['lon']) ?>" placeholder="10.7522000">
            </div>

            <div class="col-md-6 d-flex align-items-end gap-2 flex-wrap">
              <button type="button" class="btn btn-outline-secondary" id="nlMapUseMyPos">
                <i class="bi bi-crosshair"></i> Finn min posisjon
              </button>
              <button type="button" class="btn btn-outline-secondary" id="nlMapClear">
                <i class="bi bi-x-circle"></i> Nullstill posisjon
              </button>
              <button type="button" class="btn btn-outline-secondary" id="nlLookupAddress">
                <i class="bi bi-geo-alt"></i> Hent adresse fra koordinater
              </button>
              <div class="text-muted small ms-auto">Klikk i kartet for å sette posisjon (markør kan dras)</div>
            </div>

            <div class="col-12">
              <div class="small text-muted" id="nlGeoStatus"></div>
            </div>

            <div class="col-12">
              <div id="nlMapPick" class="border"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Dynamiske felter -->
    <div class="accordion-item">
      <h2 class="accordion-header" id="nlAccHeadDyn">
        <button class="accordion-button <?= $openDynamic ? '' : 'collapsed' ?>" type="button"
                data-bs-toggle="collapse" data-bs-target="#nlAccDyn" aria-expanded="<?= $openDynamic ? 'true' : 'false' ?>"
                aria-controls="nlAccDyn">
          Dynamiske felter
          <?php if ((int)$node['template_id'] > 0): ?>
            <span class="badge text-bg-secondary"><?= (int)count($fields) ?> felt</span>
          <?php else: ?>
            <span class="badge text-bg-secondary">Velg mal</span>
          <?php endif; ?>
        </button>
      </h2>
      <div id="nlAccDyn" class="accordion-collapse collapse <?= $openDynamic ? 'show' : '' ?>"
           aria-labelledby="nlAccHeadDyn" data-bs-parent="#nlEditAccordion">
        <div class="accordion-body">
          <?php if ((int)$node['template_id'] <= 0): ?>
            <div class="text-muted">Velg en mal for å se dynamiske felter.</div>
          <?php else: ?>

            <?php if (!$fields): ?>
              <div class="text-muted">Ingen felter på denne malen.</div>
            <?php endif; ?>

            <!-- Under-accordion pr gruppe -->
            <div class="accordion" id="nlDynGroupsAccordion">
              <?php
                $gi = 0;
                foreach ($grouped as $gname => $gfields):
                  $gi++;
                  $gid = 'nlDynGroup_' . $gi;
                  $isOpen = ($gi === 1); // første gruppe åpen som standard
              ?>
                <div class="accordion-item">
                  <h2 class="accordion-header" id="<?= h($gid) ?>Head">
                    <button class="accordion-button <?= $isOpen ? '' : 'collapsed' ?>" type="button"
                            data-bs-toggle="collapse" data-bs-target="#<?= h($gid) ?>"
                            aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-controls="<?= h($gid) ?>">
                      <?= h((string)$gname) ?>
                      <span class="badge text-bg-secondary"><?= (int)count($gfields) ?></span>
                    </button>
                  </h2>
                  <div id="<?= h($gid) ?>" class="accordion-collapse collapse <?= $isOpen ? 'show' : '' ?>"
                       aria-labelledby="<?= h($gid) ?>Head" data-bs-parent="#nlDynGroupsAccordion">
                    <div class="accordion-body">
                      <div class="row g-3">
                        <?php foreach ($gfields as $f): ?>
                          <?php
                            $key  = (string)$f['field_key'];
                            $type = (string)$f['field_type'];
                            $val  = fieldValueForForm($f, $valuesByFieldId);
                            $req  = ((int)$f['is_required'] === 1);
                          ?>
                          <div class="col-md-6">
                            <label class="form-label">
                              <?= h((string)$f['label']) ?> <?= $req ? '<span class="text-danger">*</span>' : '' ?>
                            </label>

                            <?php if ($type === 'textarea'): ?>
                              <textarea class="form-control" name="cf[<?=h($key)?>]" rows="3"><?= h((string)$val) ?></textarea>
                            <?php elseif ($type === 'text'): ?>
                              <input class="form-control" name="cf[<?=h($key)?>]" value="<?= h((string)$val) ?>">
                            <?php elseif ($type === 'url'): ?>
                              <input class="form-control" type="url" name="cf[<?=h($key)?>]" value="<?= h((string)$val) ?>" placeholder="https://...">
                            <?php elseif ($type === 'number'): ?>
                              <input class="form-control" type="number" step="any" name="cf[<?=h($key)?>]" value="<?= h((string)$val) ?>">
                            <?php elseif ($type === 'date'): ?>
                              <input class="form-control" type="date" name="cf[<?=h($key)?>]" value="<?= h((string)$val) ?>">
                            <?php elseif ($type === 'datetime'): ?>
                              <input class="form-control" type="datetime-local" name="cf[<?=h($key)?>]" value="<?= h((string)$val) ?>">
                            <?php elseif ($type === 'bool'): ?>
                              <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="cf[<?=h($key)?>]" value="1" <?= ((int)$val === 1 ? 'checked' : '') ?>>
                                <label class="form-check-label">Ja</label>
                              </div>
                            <?php elseif ($type === 'select'): ?>
                              <select class="form-select" name="cf[<?=h($key)?>]">
                                <option value="">–</option>
                                <?php foreach (($f['options'] ?? []) as $o): ?>
                                  <option value="<?=h((string)$o['opt_value'])?>" <?= ((string)$val === (string)$o['opt_value'] ? 'selected' : '') ?>>
                                    <?= h((string)$o['opt_label']) ?>
                                  </option>
                                <?php endforeach; ?>
                              </select>
                            <?php elseif ($type === 'multiselect'): ?>
                              <select class="form-select" name="cf[<?=h($key)?>][]" multiple size="5">
                                <?php foreach (($f['options'] ?? []) as $o): ?>
                                  <?php $selected = (is_array($val) && in_array((string)$o['opt_value'], $val, true)); ?>
                                  <option value="<?=h((string)$o['opt_value'])?>" <?= $selected ? 'selected' : '' ?>>
                                    <?= h((string)$o['opt_label']) ?>
                                  </option>
                                <?php endforeach; ?>
                              </select>
                            <?php elseif ($type === 'json'): ?>
                              <textarea class="form-control font-monospace" name="cf[<?=h($key)?>]" rows="5" placeholder='{"key":"value"}'><?= h((string)$val) ?></textarea>
                            <?php else: ?>
                              <input class="form-control" name="cf[<?=h($key)?>]" value="<?= h((string)$val) ?>">
                            <?php endif; ?>

                            <?php if (!empty($f['help_text'])): ?>
                              <div class="form-text"><?= h((string)$f['help_text']) ?></div>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Vedlegg -->
    <div class="accordion-item" id="attachments">
      <h2 class="accordion-header" id="nlAccHeadAtt">
        <button class="accordion-button <?= $openAttachments ? '' : 'collapsed' ?>" type="button"
                data-bs-toggle="collapse" data-bs-target="#nlAccAtt" aria-expanded="<?= $openAttachments ? 'true' : 'false' ?>"
                aria-controls="nlAccAtt">
          Bilder / vedlegg
          <?php if ($id > 0): ?>
            <span class="badge text-bg-secondary"><?= (int)$imageCount ?> bilder</span>
            <span class="badge text-bg-secondary"><?= (int)count($attachments) ?> totalt</span>
          <?php endif; ?>
        </button>
      </h2>
      <div id="nlAccAtt" class="accordion-collapse collapse <?= $openAttachments ? 'show' : '' ?>"
           aria-labelledby="nlAccHeadAtt" data-bs-parent="#nlEditAccordion">
        <div class="accordion-body">
          <?php if ($id <= 0): ?>
            <div class="text-muted">Lagre feltobjektet først for å kunne laste opp vedlegg.</div>
          <?php else: ?>
            <div class="mb-3">
              <label class="form-label">Last opp (jpg/png/webp/pdf)</label>
              <input class="form-control" type="file" name="photos[]" multiple>
              <div class="form-text">
                Klikk på et bilde for visning. Piltaster blar. Klikk i bildet for zoom, musehjul for zoom og dra for panorering.
                Rotér, slett og lagre bildetekst i viseren.
              </div>
            </div>

            <?php if (!$attachments): ?>
              <div class="text-muted">Ingen vedlegg.</div>
            <?php else: ?>
              <div class="row g-2" id="nlThumbGrid">
                <?php foreach ($attachments as $a): ?>
                  <?php
                    $aid  = (int)($a['id'] ?? 0);
                    $mime = (string)($a['mime_type'] ?? '');
                    $isImg = (strpos($mime, 'image/') === 0);
                    $desc = (string)($a['description'] ?? '');
                    $createdAt = (string)($a['created_at'] ?? '');
                    $takenAt = (string)($a['taken_at'] ?? '');
                    $url = attachmentUrl($aid);
                  ?>
                  <div class="col-md-3" data-att-id="<?= (int)$aid ?>">
                    <div class="border rounded p-2 h-100 nl-attach-card">
                      <div class="small text-muted text-truncate"><?= h((string)($a['original_filename'] ?? '')) ?></div>

                      <div class="nl-attach-actions">
                        <button type="button"
                                class="btn btn-sm btn-outline-danger nlDelBtn"
                                title="Slett vedlegg"
                                data-id="<?= (int)$aid ?>">
                          <i class="bi bi-trash"></i>
                        </button>
                      </div>

                      <?php if ($isImg): ?>
                        <a href="#" class="nl-attach-thumb d-block mt-2"
                           data-id="<?= $aid ?>"
                           data-src="<?= h($url) ?>"
                           data-filename="<?= h((string)($a['original_filename'] ?? '')) ?>"
                           data-desc="<?= h($desc) ?>"
                           data-created="<?= h($createdAt) ?>"
                           data-taken="<?= h($takenAt) ?>"
                           data-mime="<?= h($mime) ?>">
                          <img src="<?= h($url) ?>" style="max-width:100%; height:auto; border-radius:.25rem;" alt="">
                        </a>
                        <div class="small text-muted mt-2">
                          Opplastet: <?= h($createdAt ?: '–') ?><br>
                          Tatt: <?= h($takenAt ?: '–') ?>
                        </div>
                      <?php else: ?>
                        <div class="mt-2 d-flex gap-2">
                          <a class="btn btn-sm btn-outline-secondary" href="<?= h($url) ?>" target="_blank" rel="noreferrer">Åpne</a>
                          <a class="btn btn-sm btn-outline-secondary" href="<?= h($url . '&download=1') ?>" target="_blank" rel="noreferrer">Last ned</a>
                        </div>
                        <div class="small text-muted mt-2">Opplastet: <?= h($createdAt ?: '–') ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Faresone -->
    <?php if ($id > 0): ?>
      <div class="accordion-item">
        <h2 class="accordion-header" id="nlAccHeadDanger">
          <button class="accordion-button <?= $openDanger ? '' : 'collapsed' ?>" type="button"
                  data-bs-toggle="collapse" data-bs-target="#nlAccDanger" aria-expanded="<?= $openDanger ? 'true' : 'false' ?>"
                  aria-controls="nlAccDanger">
            Faresone
            <span class="badge text-bg-danger">Sletting</span>
          </button>
        </h2>
        <div id="nlAccDanger" class="accordion-collapse collapse <?= $openDanger ? 'show' : '' ?>"
             aria-labelledby="nlAccHeadDanger" data-bs-parent="#nlEditAccordion">
          <div class="accordion-body">
            <div class="alert alert-warning">
              Sletting fjerner <b>feltobjekt</b>, <b>feltverdier</b> og <b>vedlegg</b>. Kan ikke angres.
            </div>

            <button type="button" class="btn btn-outline-danger" id="nlDeleteNodeBtn">
              <i class="bi bi-trash"></i> Slett feltobjekt
            </button>
          </div>
        </div>
      </div>
    <?php endif; ?>

  </div><!-- /accordion -->

  <!-- Footer actions (alltid synlig under) -->
  <div class="mt-3 d-flex gap-2 flex-wrap">
    <button class="btn btn-primary" type="submit">Lagre</button>

    <?php if ($id > 0): ?>
      <a class="btn btn-outline-secondary" href="/?page=node_location_view&id=<?= (int)$id ?>">Avbryt</a>
    <?php else: ?>
      <a class="btn btn-outline-secondary" href="/?page=node_locations">Avbryt</a>
    <?php endif; ?>

    <?php if ($id > 0): ?>
      <a class="btn btn-outline-secondary ms-auto" href="/?page=node_location_view&id=<?= (int)$id ?>">Til visning</a>
    <?php endif; ?>
  </div>
</form>

<?php if ($id > 0): ?>
  <!-- Egen delete-form (samme side) -->
  <form method="post" id="nlDeleteNodeForm" class="d-none">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="delete_node_location" value="1">
  </form>
<?php endif; ?>

<!-- Modal / Viewer -->
<div class="modal fade" id="nlViewer" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div class="w-100">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="fw-semibold" id="nlViewerTitle">Bilde</div>
              <div class="small text-muted" id="nlViewerMeta">–</div>
            </div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary" id="nlPrevBtn" title="Forrige (←)">←</button>
              <button type="button" class="btn btn-outline-secondary" id="nlNextBtn" title="Neste (→)">→</button>
              <button type="button" class="btn btn-outline-secondary" id="nlRotateCCW" title="Roter 90° mot klokka">⟲ 90°</button>
              <button type="button" class="btn btn-outline-secondary" id="nlRotateCW" title="Roter 90° med klokka">⟳ 90°</button>
              <button type="button" class="btn btn-outline-danger" id="nlDeleteBtn" title="Slett dette vedlegget">
                <i class="bi bi-trash"></i>
              </button>
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Lukk</button>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-body">
        <div id="nlViewerImgWrap">
          <img id="nlViewerImg" src="" alt="">
        </div>

        <div class="mt-3">
          <label class="form-label">Bildetekst</label>
          <textarea class="form-control" id="nlViewerDesc" rows="2" placeholder="Kort beskrivelse..."></textarea>
          <div class="d-flex justify-content-end gap-2 mt-2">
            <button type="button" class="btn btn-outline-primary" id="nlSaveDescBtn">Lagre bildetekst</button>
          </div>
          <div class="small text-muted mt-2" id="nlViewerStatus"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // -------------------------
  // Slett feltobjekt
  // -------------------------
  var delBtn = document.getElementById('nlDeleteNodeBtn');
  var delForm = document.getElementById('nlDeleteNodeForm');
  if (delBtn && delForm) {
    delBtn.addEventListener('click', function () {
      var name = <?= json_encode((string)($node['name'] ?? '')) ?>;
      var msg = 'Slette feltobjektet' + (name ? (' "' + name + '"') : '') + '?\n\nDette sletter også tilhørende vedlegg og feltverdier.\nKan ikke angres.';
      if (!confirm(msg)) return;
      delForm.submit();
    });
  }

  // -------------------------
  // Bytt mal via GET (ikke POST autosubmit)
  // -------------------------
  var templateSel = document.getElementById('nlTemplate');
  if (templateSel) {
    templateSel.addEventListener('change', function () {
      var tid = templateSel.value || '0';
      var url = '/?page=node_location_edit'
              + <?= ($id > 0 ? json_encode('&id=' . (int)$id) : "''") ?>
              + '&template_id=' + encodeURIComponent(tid);
      window.location.href = url;
    });
  }

  // -------------------------
  // Map
  // -------------------------
  var latInput = document.getElementById('nlLat');
  var lonInput = document.getElementById('nlLon');

  var addr1Input = document.getElementById('nlAddr1');
  var postalInput = document.getElementById('nlPostal');
  var cityInput = document.getElementById('nlCity');
  var regionInput = document.getElementById('nlRegion');   // hidden
  var countryInput = document.getElementById('nlCountry'); // hidden
  var geoBtn = document.getElementById('nlLookupAddress');
  var geoStatus = document.getElementById('nlGeoStatus');

  var mapCollapseEl = document.getElementById('nlAccMap'); // collapse container

  function setGeoStatus(msg, isError) {
    if (!geoStatus) return;
    geoStatus.textContent = msg || '';
    geoStatus.className = 'small ' + (isError ? 'text-danger' : 'text-muted');
  }

  function parseNum(v) {
    if (v === null || v === undefined) return null;
    var s = String(v).trim().replace(',', '.');
    if (s === '') return null;
    var n = Number(s);
    return Number.isFinite(n) ? n : null;
  }

  function setInputs(lat, lon) {
    if (lat === null || lon === null) return;
    latInput.value = (Math.round(lat * 10000000) / 10000000).toFixed(7);
    lonInput.value = (Math.round(lon * 10000000) / 10000000).toFixed(7);
  }

  var initialLat = <?= ($latVal !== null ? json_encode($latVal) : 'null') ?>;
  var initialLon = <?= ($lonVal !== null ? json_encode($lonVal) : 'null') ?>;

  var fallbackLat = <?= json_encode($defaultLat) ?>;
  var fallbackLon = <?= json_encode($defaultLon) ?>;

  var startLat = (initialLat !== null && initialLon !== null) ? initialLat : fallbackLat;
  var startLon = (initialLat !== null && initialLon !== null) ? initialLon : fallbackLon;
  var startZoom = (initialLat !== null && initialLon !== null) ? 14 : 6;

  var map = L.map('nlMapPick', { scrollWheelZoom: true }).setView([startLat, startLon], startZoom);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
  }).addTo(map);

  var marker = null;

  function ensureMarker(lat, lon) {
    if (!marker) {
      marker = L.marker([lat, lon], { draggable: true }).addTo(map);
      marker.on('dragend', function () {
        var p = marker.getLatLng();
        setInputs(p.lat, p.lng);
      });
    } else {
      marker.setLatLng([lat, lon]);
    }
  }

  if (initialLat !== null && initialLon !== null) ensureMarker(initialLat, initialLon);

  map.on('click', function (e) {
    ensureMarker(e.latlng.lat, e.latlng.lng);
    setInputs(e.latlng.lat, e.latlng.lng);
  });

  function syncFromInputs() {
    var lat = parseNum(latInput.value);
    var lon = parseNum(lonInput.value);
    if (lat === null || lon === null) return;
    ensureMarker(lat, lon);
    map.setView([lat, lon], Math.max(map.getZoom(), 14));
  }

  if (latInput) latInput.addEventListener('change', syncFromInputs);
  if (lonInput) lonInput.addEventListener('change', syncFromInputs);

  var btnMyPos = document.getElementById('nlMapUseMyPos');
  if (btnMyPos) {
    btnMyPos.addEventListener('click', function () {
      if (!navigator.geolocation) { alert('Geolocation støttes ikke i denne nettleseren.'); return; }
      navigator.geolocation.getCurrentPosition(function (pos) {
        var lat = pos.coords.latitude;
        var lon = pos.coords.longitude;
        ensureMarker(lat, lon);
        setInputs(lat, lon);
        map.setView([lat, lon], 16);
      }, function (err) {
        alert('Kunne ikke hente posisjon: ' + (err && err.message ? err.message : 'ukjent feil'));
      }, { enableHighAccuracy: true, timeout: 15000 });
    });
  }

  var btnClear = document.getElementById('nlMapClear');
  if (btnClear) {
    btnClear.addEventListener('click', function () {
      latInput.value = '';
      lonInput.value = '';
      if (marker) { map.removeLayer(marker); marker = null; }
      map.setView([fallbackLat, fallbackLon], 6);
      setGeoStatus('');
      if (regionInput) regionInput.value = '';
      if (countryInput) countryInput.value = '';
    });
  }

  // FIX: Leaflet + accordion (grått kart når det initieres skjult)
  function fixMapAfterShown() {
    // litt timeout lar nettleser fullføre layout
    setTimeout(function () {
      try { map.invalidateSize(true); } catch (e) {}
      // hvis ingen posisjon: sørg for at vi fortsatt har gyldig view
      var lat = parseNum(latInput.value);
      var lon = parseNum(lonInput.value);
      if (lat !== null && lon !== null) {
        map.setView([lat, lon], Math.max(map.getZoom(), 14));
        ensureMarker(lat, lon);
      } else {
        map.setView([fallbackLat, fallbackLon], Math.max(map.getZoom(), 6));
      }
    }, 120);
  }

  if (mapCollapseEl) {
    mapCollapseEl.addEventListener('shown.bs.collapse', fixMapAfterShown);
  }
  // og en gang ved load (i tilfelle den er åpen)
  setTimeout(fixMapAfterShown, 80);

  // -------------------------
  // Reverse geocode (lat/lon -> adresse)
  // -------------------------
  function normalizeCountry2(v) {
    var s = (v === null || v === undefined) ? '' : String(v);
    s = s.trim().toUpperCase().replace(/[^A-Z]/g, '');
    if (!s) return '';
    return s.substring(0, 2);
  }

  if (geoBtn) {
    geoBtn.addEventListener('click', function () {
      var lat = parseNum(latInput.value);
      var lon = parseNum(lonInput.value);
      if (lat === null || lon === null) {
        setGeoStatus('Fyll inn gyldig lat/lon først.', true);
        return;
      }

      setGeoStatus('Henter adresse…');
      geoBtn.disabled = true;

      var fd = new FormData();
      fd.append('lat', String(lat));
      fd.append('lon', String(lon));

      fetch('/api/node_location_reverse_geocode.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      }).then(function (r) {
        return r.text().then(function (txt) {
          try { return JSON.parse(txt); }
          catch (e) { throw new Error('Server svarte ikke med JSON: ' + txt.slice(0, 250)); }
        });
      }).then(function (res) {
        if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Ukjent feil');

        if (addr1Input && res.address_line1) addr1Input.value = res.address_line1;
        if (postalInput && res.postal_code) postalInput.value = res.postal_code;
        if (cityInput && res.city) cityInput.value = res.city;

        if (regionInput && res.region) regionInput.value = res.region;

        var cc = res.country_code ? normalizeCountry2(res.country_code) : normalizeCountry2(res.country);
        if (countryInput && cc) countryInput.value = cc;

        setGeoStatus(res.display_name ? ('Fant: ' + res.display_name) : 'Adresse oppdatert.');
      }).catch(function (err) {
        setGeoStatus(err && err.message ? err.message : 'Feil', true);
      }).finally(function () {
        geoBtn.disabled = false;
      });
    });
  }

  // -------------------------
  // Viewer (resten av koden din er uendret, men med CSRF i AJAX)
  // -------------------------
  var thumbs = Array.from(document.querySelectorAll('.nl-attach-thumb'));
  var delBtns = Array.from(document.querySelectorAll('.nlDelBtn'));
  if (!thumbs.length && !delBtns.length) return;

  var modalEl = document.getElementById('nlViewer');
  var modal = modalEl ? new bootstrap.Modal(modalEl) : null;

  var imgEl = document.getElementById('nlViewerImg');
  var wrapEl = document.getElementById('nlViewerImgWrap');
  var titleEl = document.getElementById('nlViewerTitle');
  var metaEl = document.getElementById('nlViewerMeta');
  var descEl = document.getElementById('nlViewerDesc');
  var statusEl = document.getElementById('nlViewerStatus');

  var prevBtn = document.getElementById('nlPrevBtn');
  var nextBtn = document.getElementById('nlNextBtn');
  var rotCCW = document.getElementById('nlRotateCCW');
  var rotCW = document.getElementById('nlRotateCW');
  var saveDescBtn = document.getElementById('nlSaveDescBtn');
  var deleteBtn = document.getElementById('nlDeleteBtn');

  var currentIndex = 0;

  var zoom = 1;
  var Z_MIN = 1;
  var Z_MAX = 5;
  var Z_STEP = 0.25;

  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

  function applyZoom(resetScroll) {
    if (!wrapEl) return;

    if (zoom <= 1) {
      zoom = 1;
      imgEl.classList.remove('zoomed');
      imgEl.style.width = '';
      imgEl.style.maxWidth = '100%';
      imgEl.style.maxHeight = '72vh';
      imgEl.style.cursor = 'zoom-in';

      if (resetScroll) {
        wrapEl.scrollTop = 0;
        wrapEl.scrollLeft = 0;
      }
    } else {
      imgEl.classList.add('zoomed');
      imgEl.style.maxWidth = 'none';
      imgEl.style.maxHeight = 'none';
      imgEl.style.width = (zoom * 100) + '%';
      imgEl.style.cursor = 'grab';
    }
  }

  function zoomToPoint(ratioX, ratioY) {
    if (!wrapEl) return;
    setTimeout(function () {
      var targetLeft = (wrapEl.scrollWidth * ratioX) - (wrapEl.clientWidth / 2);
      var targetTop  = (wrapEl.scrollHeight * ratioY) - (wrapEl.clientHeight / 2);
      wrapEl.scrollLeft = clamp(targetLeft, 0, wrapEl.scrollWidth - wrapEl.clientWidth);
      wrapEl.scrollTop  = clamp(targetTop,  0, wrapEl.scrollHeight - wrapEl.clientHeight);
    }, 0);
  }

  if (imgEl) {
    imgEl.addEventListener('click', function (e) {
      if (!wrapEl) return;

      var rect = imgEl.getBoundingClientRect();
      var rx = (e.clientX - rect.left) / rect.width;
      var ry = (e.clientY - rect.top) / rect.height;

      zoom = (zoom === 1) ? 2 : 1;
      applyZoom(false);

      if (zoom > 1) zoomToPoint(rx, ry);
    });
  }

  if (wrapEl) {
    wrapEl.addEventListener('wheel', function (e) {
      if (zoom <= 1) return;

      e.preventDefault();

      var rect = imgEl.getBoundingClientRect();
      var rx = (e.clientX - rect.left) / rect.width;
      var ry = (e.clientY - rect.top) / rect.height;

      var delta = (e.deltaY < 0) ? Z_STEP : -Z_STEP;
      zoom = clamp(zoom + delta, Z_MIN, Z_MAX);

      applyZoom(false);
      if (zoom > 1) zoomToPoint(rx, ry);
      else applyZoom(true);
    }, { passive: false });
  }

  var isPanning = false;
  var panStartX = 0, panStartY = 0;
  var panScrollLeft = 0, panScrollTop = 0;

  if (imgEl) {
    imgEl.addEventListener('mousedown', function (e) {
      if (!wrapEl) return;
      if (zoom <= 1) return;

      isPanning = true;
      panStartX = e.clientX;
      panStartY = e.clientY;
      panScrollLeft = wrapEl.scrollLeft;
      panScrollTop  = wrapEl.scrollTop;
      e.preventDefault();
    });
  }

  document.addEventListener('mousemove', function (e) {
    if (!isPanning || !wrapEl) return;
    var dx = e.clientX - panStartX;
    var dy = e.clientY - panStartY;
    wrapEl.scrollLeft = panScrollLeft - dx;
    wrapEl.scrollTop  = panScrollTop  - dy;
  });

  document.addEventListener('mouseup', function () {
    isPanning = false;
  });

  function getItem(i) {
    var a = thumbs[i];
    return {
      el: a,
      id: a.getAttribute('data-id'),
      src: a.getAttribute('data-src'),
      filename: a.getAttribute('data-filename') || '',
      desc: a.getAttribute('data-desc') || '',
      created: a.getAttribute('data-created') || '',
      taken: a.getAttribute('data-taken') || '',
      mime: a.getAttribute('data-mime') || ''
    };
  }

  function setStatus(msg, isError) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.className = 'small ' + (isError ? 'text-danger' : 'text-muted') + ' mt-2';
  }

  function showIndex(i) {
    if (!thumbs.length) return;
    if (i < 0) i = thumbs.length - 1;
    if (i >= thumbs.length) i = 0;
    currentIndex = i;

    var it = getItem(currentIndex);

    zoom = 1;
    applyZoom(true);

    var bust = Date.now();
    imgEl.src = it.src + (it.src.includes('?') ? '&' : '?') + 'v=' + bust;

    if (titleEl) titleEl.textContent = it.filename || ('Bilde #' + it.id);

    var createdTxt = it.created ? ('Opplastet: ' + it.created) : 'Opplastet: –';
    var takenTxt = it.taken ? ('Tatt: ' + it.taken) : 'Tatt: –';
    if (metaEl) metaEl.textContent = createdTxt + '   |   ' + takenTxt;

    if (descEl) descEl.value = it.desc || '';
    setStatus('');
  }

  function openFromThumb(aEl) {
    if (!modal) return;
    var idx = thumbs.indexOf(aEl);
    if (idx < 0) idx = 0;
    showIndex(idx);
    modal.show();
  }

  thumbs.forEach(function(a) {
    a.addEventListener('click', function(e) {
      e.preventDefault();
      openFromThumb(a);
    });
  });

  function keyHandler(e) {
    var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
    if (tag === 'textarea' || tag === 'input') return;

    if (e.key === 'ArrowLeft') { e.preventDefault(); showIndex(currentIndex - 1); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); showIndex(currentIndex + 1); }
  }

  if (modalEl) {
    modalEl.addEventListener('shown.bs.modal', function() { document.addEventListener('keydown', keyHandler); });
    modalEl.addEventListener('hidden.bs.modal', function() {
      document.removeEventListener('keydown', keyHandler);
      zoom = 1;
      applyZoom(true);
    });
  }

  if (prevBtn) prevBtn.addEventListener('click', function() { showIndex(currentIndex - 1); });
  if (nextBtn) nextBtn.addEventListener('click', function() { showIndex(currentIndex + 1); });

  function fetchJsonText(url, options) {
    return fetch(url, options).then(function(r){
      return r.text().then(function(txt){
        try { return JSON.parse(txt); }
        catch(e){ throw new Error('Server svarte ikke med JSON: ' + txt.slice(0, 250)); }
      });
    });
  }

  function postAjax(action, extra) {
    var it = thumbs.length ? getItem(currentIndex) : null;
    var fd = new FormData();
    fd.append('node_id', '<?= (int)$id ?>');
    if (it && it.id) fd.append('attachment_id', it.id);
    fd.append('action', action);

    // FIX: CSRF sendes med til API (slik at delete/rotate/save_desc ikke blir avvist)
    fd.append('_csrf', <?= json_encode(csrf_token()) ?>);

    if (extra && typeof extra === 'object') {
      Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); });
    }

    return fetchJsonText('/api/node_location_attachments.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });
  }

  function updateThumb(id, newDesc) {
    thumbs.forEach(function(a){
      if (a.getAttribute('data-id') === String(id)) {
        if (typeof newDesc === 'string') a.setAttribute('data-desc', newDesc);
        var img = a.querySelector('img');
        if (img) {
          img.src = a.getAttribute('data-src') + (a.getAttribute('data-src').includes('?') ? '&' : '?') + 'v=' + Date.now();
        }
      }
    });
  }

  function removeAttachmentFromGrid(attId) {
    var sel = '[data-att-id="' + String(attId) + '"]';
    var el = document.querySelector(sel);
    if (el && el.parentNode) el.parentNode.removeChild(el);

    thumbs = Array.from(document.querySelectorAll('.nl-attach-thumb'));
    if (currentIndex >= thumbs.length) currentIndex = Math.max(0, thumbs.length - 1);
  }

  if (rotCW) {
    rotCW.addEventListener('click', function() {
      setStatus('Roterer…');
      postAjax('rotate_cw', {}).then(function(res){
        if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Ukjent feil');
        setStatus(res.message || 'OK');

        var it = getItem(currentIndex);
        var base = (res.url || it.src);
        imgEl.src = base + (String(base).includes('?') ? '&' : '?') + 'v=' + Date.now();
        updateThumb(it.id, null);
      }).catch(function(err){
        setStatus(err.message || 'Feil', true);
      });
    });
  }

  if (rotCCW) {
    rotCCW.addEventListener('click', function() {
      setStatus('Roterer…');
      postAjax('rotate_ccw', {}).then(function(res){
        if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Ukjent feil');
        setStatus(res.message || 'OK');

        var it = getItem(currentIndex);
        var base = (res.url || it.src);
        imgEl.src = base + (String(base).includes('?') ? '&' : '?') + 'v=' + Date.now();
        updateThumb(it.id, null);
      }).catch(function(err){
        setStatus(err.message || 'Feil', true);
      });
    });
  }

  if (saveDescBtn) {
    saveDescBtn.addEventListener('click', function() {
      var desc = (descEl && descEl.value ? descEl.value : '').trim();
      setStatus('Lagrer…');
      postAjax('save_desc', { description: desc }).then(function(res){
        if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Ukjent feil');
        var newDesc = (res.description !== undefined) ? res.description : desc;
        setStatus(res.message || 'Lagret');

        var it = getItem(currentIndex);
        it.el.setAttribute('data-desc', newDesc);
        updateThumb(it.id, newDesc);
      }).catch(function(err){
        setStatus(err.message || 'Feil', true);
      });
    });
  }

  function doDeleteAttachment(attId) {
    if (!confirm('Slette vedlegget? Dette kan ikke angres.')) return;

    setStatus('Sletter…');

    postAjax('delete', { attachment_id: String(attId) }).then(function(res){
      if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Ukjent feil');

      setStatus(res.message || 'Slettet');
      removeAttachmentFromGrid(attId);

      if (modal && thumbs.length === 0) modal.hide();
      else if (modal && thumbs.length > 0) showIndex(currentIndex);

    }).catch(function(err){
      setStatus(err.message || 'Feil', true);
    });
  }

  if (deleteBtn) {
    deleteBtn.addEventListener('click', function() {
      if (!thumbs.length) return;
      var it = getItem(currentIndex);
      doDeleteAttachment(it.id);
    });
  }

  delBtns.forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      var attId = btn.getAttribute('data-id');
      if (!attId) return;
      doDeleteAttachment(attId);
    });
  });
});
</script>