<?php
// Path: public/pages/node_locations.php
//
// Feltobjekter (node_locations) + NetBox-sync (sites)
//
// TILGANG (NYTT):
// - Les:  feltobjekter_les  (eller feltobjekter_skriv / admin)
// - Skriv: feltobjekter_skriv (eller admin)
// - Bakoverkompatibilitet: node_write / node_locations_admin gir skriv
//
// - Viser "Synkroniser X nye fra NetBox" kun hvis nye NetBox-sites ikke finnes lokalt (basert på name)
// - Ved sync: upsert til node_locations (name som nøkkel), fyller partner = tenant.name
// - VIKTIG: Alt som synkes fra NetBox skal settes til template_id=1 (Nodelokasjon)
//
// NYTT I DENNE FILEN:
// - Slett objekter direkte fra listen (CSRF + confirm + flash-melding + redirect)
// - Ikke vis "Slug" i listen (fjernet fra UI)
//
// NB: For 100% garanti mot dubletter anbefales UNIQUE KEY på node_locations.name i DB.

use App\Database;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = Database::getConnection();

$username = $_SESSION['username'] ?? '';

/* ---------------- Helpers (guarded) ---------------- */
if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Robust liste-leser:
 * - støtter array ["a","b"]
 * - støtter assoc ["admin"=>1,"node_write"=>true]
 * - støtter string "a,b,c" / "a b c" / "a;b"
 */
if (!function_exists('session_list')) {
    function session_list($v): array {
        if (is_array($v)) {
            $isAssoc = array_keys($v) !== range(0, count($v) - 1);
            if ($isAssoc) {
                $out = [];
                foreach ($v as $k => $val) {
                    if (is_string($k) && $k !== '' && $k !== '0') {
                        if ($val) $out[] = (string)$k;
                    } else {
                        if (is_string($val) && trim($val) !== '') $out[] = trim($val);
                    }
                }
                return array_values(array_filter(array_map('strval', $out)));
            }

            return array_values(array_filter(array_map(function ($x) {
                return is_string($x) ? trim($x) : (is_scalar($x) ? (string)$x : '');
            }, $v)));
        }

        if (is_string($v)) {
            $s = trim($v);
            if ($s === '') return [];
            $parts = preg_split('/[,\s;]+/', $s);
            return array_values(array_filter(array_map('trim', $parts)));
        }

        return [];
    }
}

if (!function_exists('has_any')) {
    function has_any(array $needles, array $haystack): bool {
        $hay = array_map('strtolower', array_map('strval', $haystack));
        foreach ($needles as $n) {
            if (in_array(strtolower((string)$n), $hay, true)) return true;
        }
        return false;
    }
}

/**
 * Hent roller fra DB på en robust måte (støtter user_roles(user_id, role) og user_roles(username, role/role_key))
 */
if (!function_exists('load_db_roles_for_user')) {
    function load_db_roles_for_user(PDO $pdo, string $username, array $session): array {
        $roles = [];

        // Prøv å finne user_id i DB hvis session ikke har den
        $userId = (int)($session['user_id'] ?? 0);
        if ($userId <= 0 && $username !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
                $st->execute([':u' => $username]);
                $userId = (int)($st->fetchColumn() ?: 0);
            } catch (\Throwable $e) {
                $userId = 0;
            }
        }

        // Variant: user_roles(user_id, role)
        if ($userId > 0) {
            try {
                $st = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :uid");
                $st->execute([':uid' => $userId]);
                $roles = array_merge($roles, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (\Throwable $e) {
                // ignorer
            }
        }

        // Variant: user_roles(username, role)
        if ($username !== '') {
            try {
                $st = $pdo->prepare("SELECT role FROM user_roles WHERE username = :u");
                $st->execute([':u' => $username]);
                $roles = array_merge($roles, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (\Throwable $e) {
                // ignorer
            }
        }

        // Variant: user_roles(username, role_key)
        if ($username !== '') {
            try {
                $st = $pdo->prepare("SELECT role_key FROM user_roles WHERE username = :u");
                $st->execute([':u' => $username]);
                $roles = array_merge($roles, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (\Throwable $e) {
                // ignorer
            }
        }

        return array_values(array_filter(array_map('strval', $roles)));
    }
}

/* ---------------- Access control (NYTT: feltobjekter_les / feltobjekter_skriv) ---------------- */
// Samle roller/perms fra session + DB, normaliser til lowercase
$sessionRoles = array_merge(
    session_list($_SESSION['roles'] ?? null),
    session_list($_SESSION['permissions'] ?? null),
    session_list($_SESSION['user_groups'] ?? null),
    session_list($_SESSION['ad_groups'] ?? null)
);
$dbRoles = load_db_roles_for_user($pdo, (string)$username, $_SESSION);
$roles = array_values(array_unique(array_map('strtolower', array_merge($sessionRoles, $dbRoles))));

// Admin-sjekk (støtter både flagg + rolle)
$isAdmin = (bool)($_SESSION['is_admin'] ?? false);
if (!$isAdmin) {
    $isAdmin = has_any(['admin', 'administrator', 'superadmin'], $roles);
}
if ($username === 'rsv') { $isAdmin = true; } // beholdt ev. bypass
if ($isAdmin) $_SESSION['is_admin'] = true;

// Nye roller
$canWrite = $isAdmin || has_any([
    'feltobjekter_skriv',
    // bakoverkompatibilitet:
    'node_locations_admin',
    'node_locations_write',
    'node_write',
], $roles);

$canRead = $canWrite || $isAdmin || has_any([
    'feltobjekter_les',
    // bakoverkompatibilitet:
    'node_locations_read',
    'node_locations_view',
    'node_read',
], $roles);

if ($username === '' || !$canRead) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

/* ---------------- URL helpers ---------------- */
function buildUrl(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    $q['page'] = 'node_locations';
    return '/?' . http_build_query($q);
}

function formatDateOnly(?string $dt): string {
    if (!$dt) return '';
    $d = substr($dt, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : $dt;
}

/* ---------------- Flash (enkelt) ---------------- */
function flash_set(string $type, string $msg): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['_flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) return null;
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $f;
}

/* ---------------- CSRF (robust) ---------------- */
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
        return $token !== null && hash_equals($sess, (string)$token);
    }
}

/* ---------------- NetBox-konfig ---------------- */
$netboxApiUrlBase = 'https://netbox.hkbb.no/api/dcim/sites/';

// Anbefalt: flytt til config/miljøvariabel i produksjon
$netboxToken = 'b32e6cc0781eee3c332ea1730c671c3be2990ea6';

// Kun intern/test om self-signed
$netboxInsecureSkipTlsVerify = true;

// VIKTIG: Alt som synkes fra NetBox skal bruke template_id=1 (Nodelokasjon)
$netboxTemplateId = 1;

function netbox_get_json(string $url, string $token, bool $insecureSkipTlsVerify, int &$httpCode, ?string &$curlErr): ?array {
    $ch = curl_init($url);
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Token ' . $token,
            'User-Agent: HKBB-NetBox-Nodes-Sync/1.0',
        ],
    ];
    if ($insecureSkipTlsVerify) {
        $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
        $curlOpts[CURLOPT_SSL_VERIFYHOST] = 0;
    }
    curl_setopt_array($ch, $curlOpts);
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function netbox_fetch_all_sites(string $apiUrlBase, string $token, bool $insecureSkipTlsVerify, int $perPage, array &$meta): array {
    $all = [];
    $offset = 0;
    $pages = 0;
    $count = null;

    $meta = [
        'http_last' => 0,
        'curl_err'  => null,
        'errors'    => [],
        'count'     => 0,
        'pages'     => 0,
    ];

    while (true) {
        $url = $apiUrlBase . '?' . http_build_query([
            'limit'  => $perPage,
            'offset' => $offset,
        ]);

        $http = 0;
        $err = null;
        $data = netbox_get_json($url, $token, $insecureSkipTlsVerify, $http, $err);

        $meta['http_last'] = $http;
        $meta['curl_err']  = $err;

        if ($err) {
            $meta['errors'][] = "cURL-feil ved offset={$offset}: {$err}";
            break;
        }
        if ($http < 200 || $http >= 300) {
            $meta['errors'][] = "HTTP {$http} fra NetBox ved offset={$offset}";
            break;
        }
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            $meta['errors'][] = "Uventet responsformat ved offset={$offset}";
            break;
        }

        if ($count === null && isset($data['count']) && is_numeric($data['count'])) {
            $count = (int)$data['count'];
        }

        $batch = $data['results'];
        foreach ($batch as $row) $all[] = $row;

        $pages++;
        $offset += $perPage;

        if ($count !== null && count($all) >= $count) break;
        if (count($batch) < $perPage) break;

        if ($pages > 2000) {
            $meta['errors'][] = "Avbrøt: for mange sider (safety stop).";
            break;
        }
    }

    $meta['count'] = $count ?? count($all);
    $meta['pages'] = $pages;

    return $all;
}

/**
 * Upsert node_location basert på NetBox site.
 * Nøkkel: name (unik i praksis, og helst UNIQUE KEY i DB).
 */
function upsert_node_location(PDO $pdo, int $templateId, array $site, bool $hasPartnerCol, string $updatedBy = 'netbox_sync'): string {
    $name = trim((string)($site['name'] ?? ''));
    if ($name === '') return 'skipped';

    $slug        = trim((string)($site['slug'] ?? ''));
    $statusLabel = (string)($site['status']['value'] ?? $site['status']['label'] ?? $site['status'] ?? 'active');
    $description = $site['description'] ?? null;

    $region      = $site['region']['name'] ?? null;
    $tenantName  = $site['tenant']['name'] ?? null; // -> partner

    $externalId  = (string)($site['id'] ?? '');
    $address1    = $site['physical_address'] ?? null;
    $address2    = $site['shipping_address'] ?? null;
    $lat = isset($site['latitude']) && $site['latitude'] !== null ? (float)$site['latitude'] : null;
    $lon = isset($site['longitude']) && $site['longitude'] !== null ? (float)$site['longitude'] : null;

    $status = strtolower(trim((string)$statusLabel));
    if ($status === '') $status = 'active';

    // Finn eksisterende basert på name
    $stmt = $pdo->prepare("SELECT id FROM node_locations WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);

    if ($existingId > 0) {
        if ($hasPartnerCol) {
            $upd = $pdo->prepare("
                UPDATE node_locations
                   SET template_id     = ?,
                       slug            = ?,
                       status          = ?,
                       description     = ?,
                       address_line1   = ?,
                       address_line2   = ?,
                       region          = ?,
                       lat             = ?,
                       lon             = ?,
                       external_source = 'netbox',
                       external_id     = ?,
                       last_synced_at  = NOW(),
                       updated_by      = ?,
                       updated_at      = NOW(),
                       partner         = ?
                 WHERE id = ?
                 LIMIT 1
            ");
            $upd->execute([
                $templateId,
                $slug !== '' ? $slug : null,
                $status,
                $description,
                $address1,
                $address2,
                $region,
                $lat,
                $lon,
                $externalId !== '' ? $externalId : null,
                $updatedBy,
                $tenantName,
                $existingId
            ]);
        } else {
            $upd = $pdo->prepare("
                UPDATE node_locations
                   SET template_id     = ?,
                       slug            = ?,
                       status          = ?,
                       description     = ?,
                       address_line1   = ?,
                       address_line2   = ?,
                       region          = ?,
                       lat             = ?,
                       lon             = ?,
                       external_source = 'netbox',
                       external_id     = ?,
                       last_synced_at  = NOW(),
                       updated_by      = ?,
                       updated_at      = NOW()
                 WHERE id = ?
                 LIMIT 1
            ");
            $upd->execute([
                $templateId,
                $slug !== '' ? $slug : null,
                $status,
                $description,
                $address1,
                $address2,
                $region,
                $lat,
                $lon,
                $externalId !== '' ? $externalId : null,
                $updatedBy,
                $existingId
            ]);
        }
        return 'updated';
    }

    // Insert ny
    if ($hasPartnerCol) {
        $ins = $pdo->prepare("
            INSERT INTO node_locations
                (template_id, name, slug, status, description,
                 address_line1, address_line2, region, lat, lon,
                 external_source, external_id, last_synced_at, created_by, updated_by, partner)
            VALUES
                (?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?,
                 'netbox', ?, NOW(), ?, ?, ?)
        ");
        $ins->execute([
            $templateId,
            $name,
            $slug !== '' ? $slug : $name,
            $status,
            $description,
            $address1,
            $address2,
            $region,
            $lat,
            $lon,
            $externalId !== '' ? $externalId : null,
            $updatedBy,
            $updatedBy,
            $tenantName
        ]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO node_locations
                (template_id, name, slug, status, description,
                 address_line1, address_line2, region, lat, lon,
                 external_source, external_id, last_synced_at, created_by, updated_by)
            VALUES
                (?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?,
                 'netbox', ?, NOW(), ?, ?)
        ");
        $ins->execute([
            $templateId,
            $name,
            $slug !== '' ? $slug : $name,
            $status,
            $description,
            $address1,
            $address2,
            $region,
            $lat,
            $lon,
            $externalId !== '' ? $externalId : null,
            $updatedBy,
            $updatedBy
        ]);
    }

    return 'inserted';
}

/* ---------------- Status (vises på norsk) ---------------- */
$statusLabels = [
    'active'         => 'Aktiv',
    'inactive'       => 'Inaktiv',
    'planned'        => 'Planlagt',
    'decommissioned' => 'Avviklet',
];

/* ---------------- partner-kolonne finnes? ---------------- */
$hasPartnerCol = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM node_locations LIKE 'partner'")->fetch(PDO::FETCH_ASSOC);
    $hasPartnerCol = (bool)$chk;
} catch (Throwable $e) {
    $hasPartnerCol = false;
}

/* ---------------- Sletting (POST) ---------------- */
$isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');

if ($isPost && isset($_POST['delete_id'])) {
    if (!$canWrite) {
        flash_set('danger', 'Du har ikke tilgang til å slette feltobjekter.');
        header('Location: ' . buildUrl([]));
        exit;
    }

    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        flash_set('danger', 'Ugyldig CSRF-token. Prøv å laste siden på nytt.');
        header('Location: ' . buildUrl([]));
        exit;
    }

    $deleteId = (int)($_POST['delete_id'] ?? 0);
    if ($deleteId <= 0) {
        flash_set('danger', 'Ugyldig ID for sletting.');
        header('Location: ' . buildUrl([]));
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Slett vedlegg først (hvis tabellen finnes / FK)
        try {
            $pdo->prepare("DELETE FROM node_location_attachments WHERE node_location_id = ?")->execute([$deleteId]);
        } catch (Throwable $e) {
            // Ignorer hvis tabell ikke finnes eller andre miljøvarianter
        }

        // Selve objektet
        $del = $pdo->prepare("DELETE FROM node_locations WHERE id = ? LIMIT 1");
        $del->execute([$deleteId]);

        if ($del->rowCount() < 1) {
            $pdo->rollBack();
            flash_set('warning', 'Fant ikke objektet (kan allerede være slettet).');
            header('Location: ' . buildUrl([]));
            exit;
        }

        $pdo->commit();
        flash_set('success', 'Objektet ble slettet.');
        header('Location: ' . buildUrl([]));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('danger', 'Kunne ikke slette. Objektet kan være i bruk (FK) eller DB-feil: ' . $e->getMessage());
        header('Location: ' . buildUrl([]));
        exit;
    }
}

/* ---------------- Filters ---------------- */
$q         = trim($_GET['q'] ?? '');
$template  = (int)($_GET['template_id'] ?? 0);
$status    = trim($_GET['status'] ?? '');

// Sorting
$sort = trim($_GET['sort'] ?? 'updated_at');
$dir  = strtolower(trim($_GET['dir'] ?? 'desc'));
$dir  = ($dir === 'asc') ? 'asc' : 'desc';

// Whitelist sort keys -> SQL
$sortMap = [
    'name'         => 'nl.name',
    'slug'         => 'nl.slug',
    'partner'      => 'nl.partner',
    'template'     => 't.name',
    'status'       => 'nl.status',
    'city'         => 'nl.city',
    'postal_code'  => 'nl.postal_code',
    'country'      => 'nl.country',
    'images'       => 'image_count',
    'updated_at'   => 'nl.updated_at',
    'created_at'   => 'nl.created_at',
];

if (!isset($sortMap[$sort])) $sort = 'updated_at';
$orderBy = $sortMap[$sort] . ' ' . strtoupper($dir) . ', nl.id DESC';

/* ---------------- Templates ---------------- */
$templates = $pdo->query("SELECT id, name FROM node_location_templates WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- NetBox: finn "nye" sites ---------------- */
$netboxMeta = [];
$netboxPerPage = 200;
$netboxSites = [];
$netboxNewCount = 0;
$netboxNewNames = [];
$netboxError = null;

try {
    $netboxSites = netbox_fetch_all_sites($netboxApiUrlBase, $netboxToken, $netboxInsecureSkipTlsVerify, $netboxPerPage, $netboxMeta);

    $localNames = [];
    $st = $pdo->query("SELECT name FROM node_locations");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $nm = trim((string)($r['name'] ?? ''));
        if ($nm !== '') $localNames[$nm] = true;
    }

    foreach ($netboxSites as $s) {
        $nm = trim((string)($s['name'] ?? ''));
        if ($nm === '') continue;
        if (!isset($localNames[$nm])) {
            $netboxNewNames[$nm] = true;
        }
    }
    $netboxNewCount = count($netboxNewNames);
} catch (Throwable $e) {
    $netboxError = $e->getMessage();
}

/* ---------------- Sync-handling (POST) ---------------- */
$syncMsg = null;
$syncErr = null;

$doSync = $isPost && isset($_POST['netbox_sync']);
if ($doSync) {
    if (!$canWrite) {
        $syncErr = "Du har ikke tilgang til å synkronisere fra NetBox.";
    } elseif (!csrf_validate($_POST['_csrf'] ?? null)) {
        $syncErr = "Ugyldig CSRF-token. Prøv å laste siden på nytt.";
    } elseif (empty($netboxSites)) {
        $syncErr = "Fant ingen NetBox-data å synkronisere (NetBox kan være utilgjengelig).";
    } else {
        $ins = 0; $upd = 0; $skp = 0;

        try {
            $pdo->beginTransaction();

            foreach ($netboxSites as $site) {
                // VIKTIG: Bruk alltid template_id=1 for NetBox-data
                $res = upsert_node_location($pdo, $netboxTemplateId, $site, $hasPartnerCol, 'netbox_sync');
                if ($res === 'inserted') $ins++;
                elseif ($res === 'updated') $upd++;
                else $skp++;
            }

            $pdo->commit();

            $netboxNewCount = 0;
            $netboxNewNames = [];
            $syncMsg = "Synk OK. Nye: {$ins}, Oppdatert: {$upd}, Hoppet over: {$skp}.";

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $syncErr = "DB-feil under sync: " . $e->getMessage();
        }
    }
}

/* ---------------- Query liste ---------------- */
$params = [];
$where  = [];

if ($q !== '') {
    // VIKTIG FIX:
    // Ikke gjenbruk samme named placeholder (:q) flere ganger.
    // Noen PDO/MySQL-konfig (ekte prepares) kaster HY093 ved gjenbruk.
    $where[] = "(nl.name LIKE :q1 OR nl.slug LIKE :q2 OR nl.external_id LIKE :q3 OR nl.address_line1 LIKE :q4 OR nl.city LIKE :q5)";
    $like = "%{$q}%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
    $params[':q5'] = $like;
}
if ($template > 0) {
    $where[] = "nl.template_id = :tid";
    $params[':tid'] = $template;
}
if ($status !== '') {
    $where[] = "nl.status = :st";
    $params[':st'] = $status;
}

$sql = "
SELECT
    nl.*,
    t.name AS template_name,
    COALESCE(att.image_count, 0) AS image_count,
    COALESCE(NULLIF(nl.updated_by, ''), NULLIF(lastchg.last_username, ''), NULLIF(nl.created_by, ''), '') AS last_username
FROM node_locations nl
JOIN node_location_templates t ON t.id = nl.template_id
LEFT JOIN (
    SELECT
        node_location_id,
        SUM(CASE WHEN mime_type LIKE 'image/%' THEN 1 ELSE 0 END) AS image_count
    FROM node_location_attachments
    GROUP BY node_location_id
) att ON att.node_location_id = nl.id
LEFT JOIN (
    SELECT x.object_id, x.actor AS last_username
    FROM object_change_log x
    JOIN (
        SELECT object_id, MAX(created_at) AS max_created_at
        FROM object_change_log
        WHERE object_type = 'node_location'
        GROUP BY object_id
    ) m ON m.object_id = x.object_id AND m.max_created_at = x.created_at
    WHERE x.object_type = 'node_location'
) lastchg ON lastchg.object_id = nl.id
" . (count($where) ? " WHERE " . implode(" AND ", $where) : "") . "
ORDER BY {$orderBy}
LIMIT 500
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// helper for sortable header links
function sortLink(string $key, string $label, string $currentSort, string $currentDir): string {
    $nextDir = 'asc';
    if ($currentSort === $key) {
        $nextDir = ($currentDir === 'asc') ? 'desc' : 'asc';
    }
    $url = buildUrl(['sort' => $key, 'dir' => $nextDir]);
    $arrow = '';
    if ($currentSort === $key) {
        $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
    }
    return '<a class="text-decoration-none" href="' . h($url) . '">' . h($label) . $arrow . '</a>';
}

$flash = flash_get();
?>
<style>
  .nl-row { cursor: pointer; }
  .nl-row:hover { background: rgba(0,0,0,.03); }
</style>

<div class="d-flex align-items-center justify-content-between mt-3">
  <h3 class="mb-0">Feltobjekter</h3>

  <div class="d-flex gap-2">
    <?php if ($canWrite): ?>
      <?php if ($netboxError): ?>
        <span class="btn btn-outline-warning disabled">NetBox utilgjengelig</span>
      <?php elseif (!empty($netboxMeta['errors'])): ?>
        <span class="btn btn-outline-warning disabled">NetBox feil</span>
      <?php elseif ($netboxNewCount > 0): ?>
        <form method="post" class="m-0">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <button class="btn btn-success" type="submit" name="netbox_sync" value="1">
            Synkroniser <?= (int)$netboxNewCount ?> nye fra NetBox
          </button>
        </form>
      <?php else: ?>
        <span class="btn btn-outline-secondary disabled">Ingen nye fra NetBox</span>
      <?php endif; ?>

      <a class="btn btn-primary" href="/?page=node_location_edit">Nytt feltobjekt</a>
    <?php else: ?>
      <span class="btn btn-outline-secondary disabled">Kun lesetilgang</span>
    <?php endif; ?>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> mt-3 mb-0">
    <?= h($flash['msg'] ?? '') ?>
  </div>
<?php endif; ?>

<?php if (!$hasPartnerCol): ?>
  <div class="alert alert-warning mt-3">
    <strong>Merk:</strong> Kolonnen <code>node_locations.partner</code> finnes ikke i databasen.
    Sync vil fortsatt fungere, men <code>partner</code> blir ikke lagret før kolonnen er opprettet.
  </div>
<?php endif; ?>

<?php if ($syncMsg): ?>
  <div class="alert alert-success mt-3"><?= h($syncMsg) ?></div>
<?php endif; ?>
<?php if ($syncErr): ?>
  <div class="alert alert-danger mt-3"><?= h($syncErr) ?></div>
<?php endif; ?>

<?php if ($netboxError): ?>
  <div class="alert alert-warning mt-3">
    NetBox-feil: <?= h($netboxError) ?>
  </div>
<?php elseif (!empty($netboxMeta['errors'])): ?>
  <div class="alert alert-warning mt-3">
    <?php foreach ($netboxMeta['errors'] as $e): ?>
      <div><?= h((string)$e) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form class="card card-body mt-3" method="get" action="/">
  <input type="hidden" name="page" value="node_locations">
  <input type="hidden" name="sort" value="<?= h($sort) ?>">
  <input type="hidden" name="dir" value="<?= h($dir) ?>">
  <div class="row g-2">
    <div class="col-md-4">
      <label class="form-label">Søk</label>
      <input class="form-control" name="q" value="<?=h($q)?>" placeholder="Navn, adresse, by, ekstern ID...">
    </div>
    <div class="col-md-3">
      <label class="form-label">Objekttype</label>
      <select class="form-select" name="template_id">
        <option value="0">Alle</option>
        <?php foreach ($templates as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === $template ? 'selected' : '') ?>>
            <?= h($t['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Status</label>
      <select class="form-select" name="status">
        <option value="">Alle</option>
        <?php foreach ($statusLabels as $value => $label): ?>
          <option value="<?=h($value)?>" <?= ($status === $value ? 'selected' : '') ?>><?=h($label)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <button class="btn btn-secondary w-100" type="submit">Filtrer</button>
    </div>
  </div>
</form>

<div class="card mt-3">
  <div class="table-responsive">
    <table class="table table-striped table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th><?= sortLink('name', 'Feltobjekt', $sort, $dir) ?></th>
          <th>Adresse</th>
          <th><?= sortLink('postal_code', 'Poststed', $sort, $dir) ?></th>
          <?php if ($hasPartnerCol): ?>
            <th><?= sortLink('partner', 'Partner', $sort, $dir) ?></th>
          <?php endif; ?>
          <th><?= sortLink('template', 'Objekttype', $sort, $dir) ?></th>
          <th><?= sortLink('status', 'Status', $sort, $dir) ?></th>
          <th class="text-end"><?= sortLink('images', 'Bilder', $sort, $dir) ?></th>
          <th class="text-nowrap"><?= sortLink('updated_at', 'Sist Oppdatert', $sort, $dir) ?></th>
          <th class="text-end">Handling</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $colspan = $hasPartnerCol ? 9 : 8;
          if (!$rows):
        ?>
          <tr><td colspan="<?= (int)$colspan ?>" class="text-muted">Ingen treff.</td></tr>
        <?php endif; ?>

        <?php foreach ($rows as $r): ?>
          <?php
            $id  = (int)$r['id'];
            $href = '/?page=node_location_view&id=' . $id;
            $imgCount = (int)($r['image_count'] ?? 0);

            $addr1 = trim((string)($r['address_line1'] ?? ''));
            $addr2 = trim((string)($r['address_line2'] ?? ''));
            $addr  = $addr1;
            if ($addr2 !== '') $addr = ($addr !== '' ? ($addr . ', ' . $addr2) : $addr2);

            $pc = trim((string)($r['postal_code'] ?? ''));
            $city = trim((string)($r['city'] ?? ''));
            $poststed = trim($pc . ' ' . $city);

            $statusCode = (string)($r['status'] ?? '');
            $statusText = $statusLabels[$statusCode] ?? $statusCode;

            $lastDate = formatDateOnly((string)($r['updated_at'] ?? ''));
            $lastUser = trim((string)($r['last_username'] ?? ''));
            $lastUserShown = $lastUser !== '' ? $lastUser : 'ukjent';

            $partner = $hasPartnerCol ? trim((string)($r['partner'] ?? '')) : '';
          ?>
          <tr class="nl-row"
              data-href="<?= h($href) ?>"
              tabindex="0"
              role="button"
              aria-label="Åpne feltobjekt <?= h((string)$r['name']) ?>">
            <td style="min-width:260px;">
              <div class="fw-semibold"><?= h((string)$r['name']) ?></div>
            </td>

            <td style="min-width:220px;">
              <?php if ($addr !== ''): ?>
                <?= h($addr) ?>
              <?php else: ?>
                <span class="text-muted">–</span>
              <?php endif; ?>

              <div class="text-muted small">
                <?php if ($r['lat'] !== null && $r['lon'] !== null): ?>
                  <?= h((string)$r['lat']) ?>, <?= h((string)$r['lon']) ?>
                <?php else: ?>
                  Posisjon: –
                <?php endif; ?>
              </div>
            </td>

            <td class="text-nowrap" style="min-width:120px;">
              <?= $poststed !== '' ? h($poststed) : '<span class="text-muted">–</span>' ?>
            </td>

            <?php if ($hasPartnerCol): ?>
              <td style="min-width:160px;">
                <?= $partner !== '' ? h($partner) : '<span class="text-muted">–</span>' ?>
              </td>
            <?php endif; ?>

            <td><?= h((string)$r['template_name']) ?></td>

            <td class="text-nowrap"><?= h($statusText) ?></td>

            <td class="text-end text-nowrap">
              <?= $imgCount ?>
            </td>

            <td class="text-nowrap">
              <?php if ($lastDate !== ''): ?>
                <?= h($lastDate) ?> (<?= h($lastUserShown) ?>)
              <?php else: ?>
                <span class="text-muted">–</span>
              <?php endif; ?>
            </td>

            <td class="text-end text-nowrap">
              <?php if ($canWrite): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Slette dette objektet? Dette kan ikke angres.');">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="delete_id" value="<?= (int)$id ?>">
                  <button type="submit" class="btn btn-sm btn-danger">Slett</button>
                </form>
              <?php else: ?>
                <span class="text-muted small">–</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">
  <a class="btn btn-outline-secondary" href="/?page=node_location_templates">Objekttyper & felter</a>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('tr.nl-row').forEach(function (tr) {
    function go(e) {
      if (e && e.target && e.target.closest && e.target.closest('a,button,input,select,textarea,label,form')) return;
      var href = tr.getAttribute('data-href');
      if (href) window.location.href = href;
    }
    tr.addEventListener('click', go);
    tr.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        go(e);
      }
    });
  });
});
</script>