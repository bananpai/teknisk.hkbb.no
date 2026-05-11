<?php
// public/pages/network_config_wizard.php
// Konfig-veiviser – steg-for-steg generering av nettverkskonfigurasjon
declare(strict_types=1);
use App\Database;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = null;
try { $pdo = Database::getConnection(); } catch (\Throwable $e) {}
if (!$pdo instanceof PDO) { echo '<div class="alert alert-danger mt-3">Mangler database-tilkobling.</div>'; return; }

if (!function_exists('wiz_esc')) {
    function wiz_esc(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('wiz_roles')) {
    function wiz_roles(PDO $pdo, int $uid): array {
        try { $st = $pdo->prepare("SELECT role FROM user_roles WHERE user_id=?"); $st->execute([$uid]); return array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN) ?: []); }
        catch (\Throwable $e) { return []; }
    }
}

$username = (string)($_SESSION['username'] ?? '');
if ($username === '') { http_response_code(401); echo '<div class="alert alert-danger mt-3">Ikke innlogget.</div>'; return; }
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    try { $st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1"); $st->execute([$username]); $userId = (int)($st->fetchColumn() ?: 0); if ($userId > 0) $_SESSION['user_id'] = $userId; } catch (\Throwable $e) {}
}
$userRoles = wiz_roles($pdo, $userId);
$isAdmin  = (bool)($_SESSION['is_admin'] ?? false) || in_array('admin', $userRoles, true);
$canWrite = $isAdmin || in_array('network', $userRoles, true) || in_array('network_write', $userRoles, true);
$canRead  = $canWrite || in_array('network_read', $userRoles, true) || in_array('support', $userRoles, true);
if (!$canRead) { http_response_code(403); echo '<div class="alert alert-danger mt-3">Ingen tilgang.</div>'; return; }

/* ---------------------------------------------------------------
   Auto-migrering
--------------------------------------------------------------- */
$migrations = [
"CREATE TABLE IF NOT EXISTS config_networks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(64) NOT NULL, slug VARCHAR(32) NOT NULL,
  description TEXT NULL, params_json JSON NULL, sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(80) NULL, updated_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
  PRIMARY KEY (id), UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS config_platforms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, vendor VARCHAR(64) NOT NULL, platform VARCHAR(64) NOT NULL,
  description TEXT NULL, params_json JSON NULL, sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(80) NULL, updated_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
  PRIMARY KEY (id), UNIQUE KEY uniq_vendor_platform (vendor(32), platform(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS config_device_roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(64) NOT NULL, slug VARCHAR(32) NOT NULL,
  description TEXT NULL, params_json JSON NULL, sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(80) NULL, updated_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
  PRIMARY KEY (id), UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS config_sites (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(128) NOT NULL,
  description TEXT NULL, params_json JSON NULL, sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(80) NULL, updated_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
  PRIMARY KEY (id), UNIQUE KEY uniq_name (name(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS config_devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, hostname VARCHAR(128) NOT NULL,
  site_id INT UNSIGNED NULL, model_id INT UNSIGNED NULL, platform_id INT UNSIGNED NULL, role_id INT UNSIGNED NULL,
  management_ip VARCHAR(45) NULL, params_json JSON NULL, notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_by VARCHAR(80) NULL, updated_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
  PRIMARY KEY (id), KEY idx_site (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS config_generated (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(128) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'draft',
  network_id INT UNSIGNED NULL, network_name VARCHAR(64) NULL,
  platform_id INT UNSIGNED NULL, platform_name VARCHAR(64) NULL,
  model_id INT UNSIGNED NULL, model_name VARCHAR(128) NULL,
  role_id INT UNSIGNED NULL, role_name VARCHAR(64) NULL,
  site_id INT UNSIGNED NULL, site_name VARCHAR(128) NULL,
  device_id INT UNSIGNED NULL, device_hostname VARCHAR(128) NULL,
  params_manifest JSON NULL, module_ids_json JSON NULL,
  config_text LONGTEXT NULL, notes TEXT NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by VARCHAR(80) NULL, updated_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
  PRIMARY KEY (id), KEY idx_status (status), KEY idx_model (model_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($migrations as $sql) { try { $pdo->exec($sql); } catch (\Throwable $e) {} }

/* ---------------------------------------------------------------
   Seed standarddata (plattformar, rollar, nettverk)
--------------------------------------------------------------- */
$seedPlatforms = [
    ['Cisco','Cisco IOS','Legacy plattform for Catalyst 2960/3560/4500/6500.',
     ['ntp_server'=>'','logging_host'=>'','snmp_community'=>'','aaa_server'=>'','banner_motd'=>'Authorized access only']],
    ['Cisco','Cisco IOS-XE','Catalyst 9000-serien. Støtter NETCONF/RESTCONF og DNA Center.',
     ['ntp_server'=>'','logging_host'=>'','snmp_community'=>'','aaa_server'=>'','netconf_enabled'=>'true','restconf_enabled'=>'false']],
    ['Cisco','Cisco IOS-XR','SP-plattform for NCS 540/5500 og 8000-serien. SDR, segment routing.',
     ['ntp_server'=>'','isis_net'=>'','bgp_asn'=>'','mpls_ldp_enabled'=>'true','sr_mpls_enabled'=>'true']],
    ['Huawei','Huawei VRP','Huawei VRP-plattform for enterprise- og SP-switchar/rutarar.',
     ['ntp_server'=>'','snmp_community'=>'','stelnet_enabled'=>'true','lldp_enabled'=>'true']],
    ['Huawei','Huawei MA5800','OLT-spesifikk plattform for SmartAX MA5800-serien.',
     ['ntp_server'=>'','ont_auth_mode'=>'sn-auth','upstream_vlan_type'=>'smart']],
];
try {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM config_platforms")->fetchColumn();
    if ($c === 0) {
        $ins = $pdo->prepare("INSERT IGNORE INTO config_platforms (vendor,platform,description,params_json,sort_order,created_by) VALUES (?,?,?,?,?,?)");
        foreach ($seedPlatforms as $i => [$v,$p,$d,$params]) {
            $ins->execute([$v,$p,$d,json_encode($params),$i,'system']);
        }
    }
} catch (\Throwable $e) {}

$seedRoles = [
    ['Access switch','access_switch','Kantswitch i aksesslag.',['native_vlan'=>'1','spanning_tree_mode'=>'rapid-pvst','portfast_default'=>'true']],
    ['Distribution switch','dist_switch','Distribusjonswitch i distribusjonslag.',['default_route_metric'=>'','vlan_range'=>'']],
    ['Core switch','core_switch','Kjerneswitch, L3 routing og høy tilgjengelighet.',['bgp_asn'=>'','isis_metric_style'=>'wide']],
    ['Edge router','edge_router','Edge-ruter mot internett eller WAN-leverandør.',['bgp_asn'=>'','upstream_asn'=>'','upstream_ip'=>'']],
    ['Aggregation router','agg_router','Aggregasjons-ruter for metro/aksessnett.',['mpls_enabled'=>'true','isis_net'=>'','sr_enabled'=>'true']],
    ['Core router','core_router','Kjerne-ruter for SP-infrastruktur.',['mpls_enabled'=>'true','isis_net'=>'','bgp_asn'=>'','route_reflector'=>'false']],
    ['OLT','olt','Optical Line Terminal for GPON/XGS-PON-nett.',['gpon_profile'=>'','service_port_vlan'=>'','ont_auth_mode'=>'sn-auth']],
    ['Branch router','branch_router','Branch/kontor-ruter (ISR/CPE).',['wan_interface'=>'GigabitEthernet0/0/0','mgmt_vlan'=>'99','dhcp_pool'=>'']],
];
try {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM config_device_roles")->fetchColumn();
    if ($c === 0) {
        $ins = $pdo->prepare("INSERT IGNORE INTO config_device_roles (name,slug,description,params_json,sort_order,created_by) VALUES (?,?,?,?,?,?)");
        foreach ($seedRoles as $i => [$n,$s,$d,$p]) { $ins->execute([$n,$s,$d,json_encode($p),$i,'system']); }
    }
} catch (\Throwable $e) {}

// Fjern gamle feil-seedede nettverk
try { $pdo->exec("DELETE FROM config_networks WHERE slug IN ('prod','mgmt','lab','olt') AND created_by='system'"); } catch (\Throwable $e) {}

$seedNetworks = [
    ['Altibox Metro',       'altibox_metro',       'Altibox Metro-nettverk for kundelevert tjeneste.',  ['ntp_server'=>'','logging_host'=>'','snmp_community'=>'','mgmt_vlan'=>'']],
    ['AMS',                 'ams',                 'AMS-nettverk.',                                     ['ntp_server'=>'','logging_host'=>'','snmp_community'=>'','mgmt_vlan'=>'']],
    ['Internt nett - HKIT', 'hkit',                'Internt driftsnettverk for HKIT.',                 ['ntp_server'=>'','dns_server'=>'','default_gw'=>'','mgmt_vlan'=>'']],
    ['Driftkontroll Fagne', 'driftkontroll_fagne', 'Driftkontrollnettverk for Fagne.',                 ['ntp_server'=>'','snmp_community'=>'','mgmt_vlan'=>'']],
    ['Driftkontroll SKL',   'driftkontroll_skl',   'Driftkontrollnettverk for SKL.',                   ['ntp_server'=>'','snmp_community'=>'','mgmt_vlan'=>'']],
];
try {
    $ins = $pdo->prepare("INSERT IGNORE INTO config_networks (name,slug,description,params_json,sort_order,created_by) VALUES (?,?,?,?,?,?)");
    foreach ($seedNetworks as $i => [$n,$s,$d,$p]) { $ins->execute([$n,$s,$d,json_encode($p),$i,'system']); }
} catch (\Throwable $e) {}

/* ---------------------------------------------------------------
   AJAX – einingar per site, og lagre utkast
--------------------------------------------------------------- */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajaxAction = (string)($_GET['ajax'] ?? '');

    if ($ajaxAction === 'devices' && $canRead) {
        $siteId = (int)($_GET['site_id'] ?? 0);
        $rows = [];
        try {
            $st = $pdo->prepare("SELECT id, hostname, management_ip, params_json FROM config_devices WHERE is_active=1" . ($siteId > 0 ? " AND site_id=?" : "") . " ORDER BY hostname");
            $siteId > 0 ? $st->execute([$siteId]) : $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['params_json'] = is_string($r['params_json']) ? (json_decode($r['params_json'], true) ?: []) : ($r['params_json'] ?: []);
            }
        } catch (\Throwable $e) {}
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajaxAction === 'save' && $canWrite && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $csrf = (string)($body['csrf'] ?? '');
        if (!hash_equals((string)($_SESSION['csrf_wiz'] ?? ''), $csrf)) {
            echo json_encode(['ok'=>false,'error'=>'CSRF']); exit;
        }
        try {
            $st = $pdo->prepare("
                INSERT INTO config_generated
                    (title, status, network_id, network_name, platform_id, platform_name,
                     model_id, model_name, role_id, role_name, site_id, site_name,
                     device_id, device_hostname, params_manifest, module_ids_json,
                     config_text, notes, created_by, updated_by, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ");
            $st->execute([
                (string)($body['title'] ?? ''),
                'draft',
                ($body['network_id']  ?? null) ?: null, (string)($body['network_name']   ?? ''),
                ($body['platform_id'] ?? null) ?: null, (string)($body['platform_name']  ?? ''),
                ($body['model_id']    ?? null) ?: null, (string)($body['model_name']     ?? ''),
                ($body['role_id']     ?? null) ?: null, (string)($body['role_name']      ?? ''),
                ($body['site_id']     ?? null) ?: null, (string)($body['site_name']      ?? ''),
                ($body['device_id']   ?? null) ?: null, (string)($body['device_hostname']?? ''),
                json_encode($body['params_manifest'] ?? []),
                json_encode($body['module_ids'] ?? []),
                (string)($body['config_text'] ?? ''),
                (string)($body['notes'] ?? ''),
                $username, $username,
            ]);
            echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
        } catch (\Throwable $e) {
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
}

/* ---------------------------------------------------------------
   Last alle referansedata
--------------------------------------------------------------- */
if (empty($_SESSION['csrf_wiz'])) $_SESSION['csrf_wiz'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['csrf_wiz'];

$load = function(string $sql, array $p = []) use ($pdo): array {
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (\Throwable $e) { return []; }
};

$networks  = $load("SELECT id,name,slug,description,params_json FROM config_networks WHERE is_active=1 ORDER BY sort_order,name");
$platforms = $load("SELECT id,vendor,platform,description,params_json FROM config_platforms WHERE is_active=1 ORDER BY sort_order,vendor,platform");
$models    = $load("SELECT id,vendor,series,model_name,category,specs FROM network_device_models WHERE is_active=1 ORDER BY vendor,series,model_name");
$roles     = $load("SELECT id,name,slug,description,params_json FROM config_device_roles WHERE is_active=1 ORDER BY sort_order,name");
$sites     = $load("SELECT id, name,
    TRIM(CONCAT_WS(', ', NULLIF(TRIM(city),''), NULLIF(TRIM(partner),''), NULLIF(TRIM(region),'') )) AS description,
    NULL AS params_json
    FROM node_locations WHERE status='active' ORDER BY name");
$modules   = $load("SELECT id,model_id,network_id,name,description,template_text,variables_json FROM network_config_modules WHERE is_active=1 ORDER BY sort_order,name");

// Parse JSON-felter
foreach ($networks  as &$r) { $r['params_json']   = is_string($r['params_json'])   ? (json_decode($r['params_json'],   true) ?: []) : ($r['params_json']   ?: []); }
foreach ($platforms as &$r) { $r['params_json']   = is_string($r['params_json'])   ? (json_decode($r['params_json'],   true) ?: []) : ($r['params_json']   ?: []); }
foreach ($models    as &$r) { $r['specs']         = is_string($r['specs'])         ? (json_decode($r['specs'],         true) ?: []) : ($r['specs']         ?: []); }
foreach ($roles     as &$r) { $r['params_json']   = is_string($r['params_json'])   ? (json_decode($r['params_json'],   true) ?: []) : ($r['params_json']   ?: []); }
foreach ($sites     as &$r) { $r['params_json']   = is_string($r['params_json'])   ? (json_decode($r['params_json'],   true) ?: []) : ($r['params_json']   ?: []); }
foreach ($modules   as &$r) { $r['variables_json'] = is_string($r['variables_json']) ? (json_decode($r['variables_json'], true) ?: []) : ($r['variables_json'] ?: []); }
unset($r);

$totalSteps = 11;
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Konfig-veiviser</h1>
        <p class="text-muted small mb-0">Steg-for-steg generering av nettverkskonfigurasjon.</p>
    </div>
    <a href="/?page=network_config_drafts" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-collection me-1"></i>Lagrede konfigurasjoner
    </a>
</div>

<!-- Framdriftsindikator -->
<div class="wiz-progress mb-4">
    <div class="d-flex justify-content-between align-items-center" id="wizStepDots">
        <?php
        $stepLabels = ['Nettverk','Leverandør','Plattform','Modell','Rolle','Lokasjon','Enhet','Moduler','Parametere','Forhåndsvisning','Lagre'];
        foreach ($stepLabels as $i => $lbl):
        ?>
        <div class="wiz-dot text-center <?= $i === 0 ? 'active' : '' ?>" data-step="<?= $i+1 ?>">
            <div class="wiz-dot-circle"><?= $i+1 ?></div>
            <div class="wiz-dot-label d-none d-md-block"><?= wiz_esc($lbl) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="progress mt-2" style="height:4px;">
        <div class="progress-bar" id="wizProgressBar" style="width:9%"></div>
    </div>
</div>

<form id="wizForm">
<input type="hidden" name="csrf" value="<?= wiz_esc($csrf) ?>">

<!-- ================================================ STEP 1: Nettverk -->
<div class="wizard-step" id="step-1">
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-1">Steg 1 – Velg nettverk</h2>
            <p class="text-muted small mb-3">Hvilket logisk nettverk skal konfigurasjonen tilhøre?</p>
            <div class="row g-3" id="networkCards">
                <?php foreach ($networks as $n): ?>
                <div class="col-sm-6 col-lg-3">
                    <label class="card h-100 wiz-card-select cursor-pointer" data-type="network" data-id="<?= (int)$n['id'] ?>">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-diagram-2 text-primary"></i>
                                <strong><?= wiz_esc($n['name']) ?></strong>
                            </div>
                            <small class="text-muted"><?= wiz_esc($n['description'] ?? '') ?></small>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
                <div class="col-sm-6 col-lg-3">
                    <label class="card h-100 wiz-card-select cursor-pointer" data-type="network" data-id="0">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-slash-circle text-muted"></i>
                                <strong>Generisk / ingen</strong>
                            </div>
                            <small class="text-muted">Uten nettverkstilknytning</small>
                        </div>
                    </label>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================================================ STEP 2: Leverandør -->
<div class="wizard-step d-none" id="step-2">
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-1">Steg 2 – Velg leverandør</h2>
            <div class="row g-3 mt-1">
                <div class="col-sm-4">
                    <label class="card wiz-card-select cursor-pointer" data-type="vendor" data-id="Cisco">
                        <div class="card-body text-center py-4">
                            <i class="bi bi-hdd-network fs-2 text-primary d-block mb-2"></i>
                            <strong>Cisco</strong>
                        </div>
                    </label>
                </div>
                <div class="col-sm-4">
                    <label class="card wiz-card-select cursor-pointer" data-type="vendor" data-id="Huawei">
                        <div class="card-body text-center py-4">
                            <i class="bi bi-router fs-2 text-danger d-block mb-2"></i>
                            <strong>Huawei</strong>
                        </div>
                    </label>
                </div>
                <div class="col-sm-4">
                    <label class="card wiz-card-select cursor-pointer" data-type="vendor" data-id="other">
                        <div class="card-body text-center py-4">
                            <i class="bi bi-box fs-2 text-secondary d-block mb-2"></i>
                            <strong>Anna</strong>
                        </div>
                    </label>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================================================ STEP 3: Plattform -->
<div class="wizard-step d-none" id="step-3">
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-1">Steg 3 – Velg plattform</h2>
            <p class="text-muted small mb-3">OS-plattform bestemmer syntaks og standardparametere.</p>
            <div class="row g-3" id="platformCards">
                <?php foreach ($platforms as $pl): ?>
                <div class="col-sm-6 platform-option" data-vendor="<?= wiz_esc($pl['vendor']) ?>">
                    <label class="card h-100 wiz-card-select cursor-pointer" data-type="platform" data-id="<?= (int)$pl['id'] ?>">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge text-bg-light border"><?= wiz_esc($pl['vendor']) ?></span>
                                <strong><?= wiz_esc($pl['platform']) ?></strong>
                            </div>
                            <small class="text-muted"><?= wiz_esc($pl['description'] ?? '') ?></small>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ================================================ STEP 4: Modell -->
<div class="wizard-step d-none" id="step-4">
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-1">Steg 4 – Velg modell</h2>
            <div class="mb-3">
                <input type="text" id="modelSearch" class="form-control form-control-sm" placeholder="Filtrer modeller …">
            </div>
            <div id="modelList" class="row g-2">
                <?php foreach ($models as $m): ?>
                <div class="col-12 model-option" data-vendor="<?= wiz_esc($m['vendor']) ?>" data-model-name="<?= wiz_esc(strtolower($m['model_name'])) ?>" data-series="<?= wiz_esc(strtolower($m['series'])) ?>">
                    <label class="card wiz-card-select cursor-pointer" data-type="model" data-id="<?= (int)$m['id'] ?>">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= wiz_esc($m['model_name']) ?></strong>
                                    <span class="text-muted small ms-2"><?= wiz_esc($m['vendor']) . ($m['series'] ? ' · ' . $m['series'] : '') ?></span>
                                </div>
                                <span class="badge text-bg-light border small"><?= wiz_esc($m['category']) ?></span>
                            </div>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ================================================ STEP 5: Rolle -->
<div class="wizard-step d-none" id="step-5">
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-1">Steg 5 – Velg rolle</h2>
            <div class="row g-3 mt-1">
                <?php foreach ($roles as $r): ?>
                <div class="col-sm-6 col-lg-4">
                    <label class="card h-100 wiz-card-select cursor-pointer" data-type="role" data-id="<?= (int)$r['id'] ?>">
                        <div class="card-body py-2">
                            <strong class="small"><?= wiz_esc($r['name']) ?></strong><br>
                            <small class="text-muted"><?= wiz_esc($r['description'] ?? '') ?></small>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ================================================ STEP 6: Lokasjon -->
<div class="wizard-step d-none" id="step-6">
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-1">Steg 6 – Velg lokasjon</h2>
            <p class="text-muted small mb-3">Lokasjoner hentes fra feltobjekter. Søk på navn, by eller partner.</p>
            <div class="mb-3">
                <input type="text" id="siteSearch" class="form-control form-control-sm"
                       placeholder="Søk etter lokasjon …" autocomplete="off">
            </div>
            <div class="row g-2" id="siteCards">
                <?php foreach ($sites as $s): ?>
                <div class="col-sm-6 col-lg-4 site-option" data-name="<?= wiz_esc(mb_strtolower($s['name'] . ' ' . ($s['description'] ?? ''))) ?>">
                    <label class="card wiz-card-select cursor-pointer" data-type="site" data-id="<?= (int)$s['id'] ?>">
                        <div class="card-body py-2">
                            <div class="d-flex align-items-start gap-2">
                                <i class="bi bi-geo-alt text-warning mt-1 flex-shrink-0"></i>
                                <div class="wiz-min-w-0">
                                    <strong class="small"><?= wiz_esc($s['name']) ?></strong>
                                    <?php if (!empty($s['description'])): ?>
                                    <div class="text-muted" style="font-size:.72rem;line-height:1.3;"><?= wiz_esc($s['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
                <div class="col-12" id="siteNoResults" style="display:none;">
                    <p class="text-muted small">Ingen lokasjoner matcher søket.</p>
                </div>
            </div>
            <div class="mt-2 pt-2 border-top">
                <label class="card wiz-card-select cursor-pointer" data-type="site" data-id="0">
                    <div class="card-body py-2">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-slash-circle text-muted"></i>
                            <strong class="small">Uten lokasjon / ad hoc</strong>
                        </div>
                    </div>
                </label>
            </div>
        </div>
    </div>
</div>

<!-- ================================================ STEP 7: Enhet -->
<div class="wizard-step d-none" id="step-7">
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-1">Steg 7 – Eksisterende enhet eller ad hoc</h2>
            <div class="mb-3">
                <label class="card wiz-card-select cursor-pointer mb-2" data-type="device_mode" data-id="adhoc">
                    <div class="card-body py-2 d-flex align-items-center gap-3">
                        <i class="bi bi-plus-circle text-primary fs-5"></i>
                        <div><strong>Ad hoc-generering</strong><br><small class="text-muted">Generer konfig uten å knytte til en eksisterende enhet</small></div>
                    </div>
                </label>
                <label class="card wiz-card-select cursor-pointer" data-type="device_mode" data-id="existing">
                    <div class="card-body py-2 d-flex align-items-center gap-3">
                        <i class="bi bi-server text-success fs-5"></i>
                        <div><strong>Eksisterende enhet</strong><br><small class="text-muted">Velg en registrert enhet og arv dens parametere</small></div>
                    </div>
                </label>
            </div>
            <div id="devicePickerWrap" class="d-none mt-3">
                <label class="form-label small fw-semibold">Velg enhet</label>
                <select id="devicePicker" class="form-select form-select-sm">
                    <option value="">Lastar …</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- ================================================ STEP 8: Konfig-moduler -->
<div class="wizard-step d-none" id="step-8">
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-1">Steg 8 – Konfig-moduler</h2>
            <p class="text-muted small mb-3">System foreslår moduler basert på valgt modell og rolle. Velg hva som skal inkluderes.</p>
            <div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-xs btn-outline-secondary" id="selectAllModules">Velg alle</button>
                <button type="button" class="btn btn-xs btn-outline-secondary" id="deselectAllModules">Fjern alle</button>
            </div>
            <div id="moduleChecklist">
                <p class="text-muted small">Velg modell og rolle i tidligere steg for å se forslag.</p>
            </div>
        </div>
    </div>
</div>

<!-- ================================================ STEP 9: Parametere + Live preview -->
<div class="wizard-step d-none" id="step-9">
    <div class="row g-3 align-items-start">
        <!-- Venstre: parameter-tabell -->
        <div class="col-xl-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-1">Steg 9 – Parametere</h2>
                    <p class="text-muted small mb-3">
                        Arvede verdiar er forhåndsutfylte. Fyll inn <span class="badge text-bg-warning text-dark">Mangler</span>-felt.
                    </p>
                    <div id="paramTable"><p class="text-muted small">Laster parametere …</p></div>
                </div>
            </div>
        </div>
        <!-- Høgre: live konfig-terminal -->
        <div class="col-xl-7">
            <div class="card shadow-sm bg-dark border-0 wiz-terminal">
                <div class="card-header bg-transparent border-bottom border-secondary d-flex justify-content-between align-items-center py-2 px-3">
                    <div class="d-flex align-items-center gap-1">
                        <span class="wiz-term-dot" style="background:#ff5f57"></span>
                        <span class="wiz-term-dot" style="background:#febc2e"></span>
                        <span class="wiz-term-dot" style="background:#28c840"></span>
                        <small class="text-secondary ms-2 font-monospace">konfig — live</small>
                    </div>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-xs btn-outline-secondary text-secondary border-secondary" id="copyLiveBtn" title="Kopier konfig">
                            <i class="bi bi-clipboard"></i>
                        </button>
                        <button type="button" class="btn btn-xs btn-outline-secondary text-secondary border-secondary" id="downloadLiveBtn" title="Last ned som .txt">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
                <pre id="liveConfigPre" class="text-light p-3 mb-0 font-monospace" style="min-height:300px;max-height:65vh;overflow:auto;font-size:.78rem;white-space:pre;background:transparent;">(Fyll inn parametere for å se konfig …)</pre>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2 px-1" id="liveStats"></div>
        </div>
    </div>
</div>

<!-- ================================================ STEP 10: Forhåndsvisning -->
<div class="wizard-step d-none" id="step-10">
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h5 mb-0">Steg 10 – Forhåndsvisning</h2>
                <button type="button" class="btn btn-xs btn-outline-secondary" id="copyPreviewBtn">
                    <i class="bi bi-clipboard me-1"></i>Kopier
                </button>
            </div>
            <pre id="configPreview" class="bg-dark text-light p-3 rounded small" style="max-height:520px;overflow:auto;white-space:pre;font-size:.8rem;"></pre>
        </div>
    </div>
</div>

<!-- ================================================ STEP 11: Lagre -->
<div class="wizard-step d-none" id="step-11">
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-1">Steg 11 – Lagre som utkast</h2>
            <div class="row g-3 mt-1">
                <div class="col-sm-8">
                    <label class="form-label small fw-semibold">Tittel <span class="text-danger">*</span></label>
                    <input type="text" id="draftTitle" class="form-control form-control-sm"
                           placeholder="f.eks. sw-acc-01 konfig, OLT MA5800-X7 site Oslo …">
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Notat</label>
                    <textarea id="draftNotes" class="form-control form-control-sm" rows="3"
                              placeholder="Kvifor vart denne konfigurasjonen generert? Endringsreferanse, kontekst …"></textarea>
                </div>
                <!-- Oppsummering -->
                <div class="col-12">
                    <div class="table-responsive">
                        <table class="table table-sm small mb-0">
                            <tbody id="summaryTable"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="button" class="btn btn-primary btn-sm" id="saveDraftBtn" <?= !$canWrite ? 'disabled' : '' ?>>
                    <i class="bi bi-floppy me-1"></i>Lagre utkast
                </button>
                <span id="saveStatus" class="align-self-center small text-muted"></span>
            </div>
        </div>
    </div>
</div>

</form><!-- #wizForm -->

<!-- Navigasjonsknapper -->
<div class="d-flex justify-content-between mt-3">
    <button type="button" class="btn btn-outline-secondary btn-sm" id="wizBack" style="visibility:hidden;">
        <i class="bi bi-arrow-left me-1"></i>Forrige
    </button>
    <span class="text-muted small align-self-center" id="wizStepLabel">Steg 1 av <?= $totalSteps ?></span>
    <button type="button" class="btn btn-primary btn-sm" id="wizNext">
        Neste <i class="bi bi-arrow-right ms-1"></i>
    </button>
</div>

<!-- Embedded reference data -->
<script>
const WZ = {
  csrf:      <?= json_encode($csrf) ?>,
  networks:  <?= json_encode(array_values($networks),  JSON_UNESCAPED_UNICODE) ?>,
  platforms: <?= json_encode(array_values($platforms), JSON_UNESCAPED_UNICODE) ?>,
  models:    <?= json_encode(array_values($models),    JSON_UNESCAPED_UNICODE) ?>,
  roles:     <?= json_encode(array_values($roles),     JSON_UNESCAPED_UNICODE) ?>,
  sites:     <?= json_encode(array_values($sites),     JSON_UNESCAPED_UNICODE) ?>,
  modules:   <?= json_encode(array_values($modules),   JSON_UNESCAPED_UNICODE) ?>,
  ajaxUrl:   '/?page=network_config_wizard&ajax=',
  saveUrl:   '/?page=network_config_wizard&ajax=save',
  draftUrl:  '/?page=network_config_view&id=',
};
const TOTAL_STEPS = <?= $totalSteps ?>;
</script>

<script>
(function () {
  'use strict';

  /* ---- State ---- */
  const state = {
    step: 1,
    networkId: null, networkName: '',
    vendor: null,
    platformId: null, platformName: '',
    modelId: null, modelName: '',
    roleId: null, roleName: '',
    siteId: null, siteName: '',
    deviceMode: 'adhoc',
    deviceId: null, deviceHostname: '',
    deviceParams: {},
    moduleIds: [],
    params: {},  // {varName: {value, source, description, required}}
  };

  /* ---- Step navigation ---- */
  let currentStep = 1;

  function goStep(n) {
    if (n < 1 || n > TOTAL_STEPS) return;
    if (n > currentStep && !validateStep(currentStep)) return;

    document.getElementById('step-' + currentStep)?.classList.add('d-none');
    document.getElementById('step-' + n)?.classList.remove('d-none');
    currentStep = n;

    // Oppdater UI
    document.getElementById('wizStepLabel').textContent = 'Steg ' + n + ' av ' + TOTAL_STEPS;
    document.getElementById('wizBack').style.visibility = n > 1 ? 'visible' : 'hidden';
    document.getElementById('wizNext').style.display    = n < TOTAL_STEPS ? '' : 'none';
    document.getElementById('wizProgressBar').style.width = Math.round((n / TOTAL_STEPS) * 100) + '%';

    document.querySelectorAll('.wiz-dot').forEach(d => {
      const ds = parseInt(d.dataset.step);
      d.classList.toggle('active',    ds === n);
      d.classList.toggle('completed', ds < n);
    });

    // Steg-spesifikk initialisering
    if (n === 3) filterPlatforms();
    if (n === 4) filterModels();
    if (n === 6) { const s = document.getElementById('siteSearch'); if (s) { s.value = ''; filterSites(''); } }
    if (n === 8) buildModuleChecklist();
    if (n === 9) buildParamTable();
    if (n === 10) generatePreview();
    if (n === 11) buildSummary();
  }

  function validateStep(s) {
    const msg = {
      1: !state.networkId && state.networkId !== 0 ? 'Velg eit nettverk.' : null,
      2: !state.vendor ? 'Velg leverandør.' : null,
      3: !state.platformId ? 'Velg plattform.' : null,
      4: !state.modelId ? 'Velg modell.' : null,
      5: !state.roleId ? 'Velg rolle.' : null,
      6: state.siteId === null ? 'Velg lokasjon (eller «Uten lokasjon»).' : null,
      7: !state.deviceMode ? 'Velg modus.' : null,
      8: state.moduleIds.length === 0 ? 'Velg minst én konfig-modul.' : null,
      9: null,  // params filled later
      10: null,
      11: null,
    };
    const err = msg[s];
    if (err) { alert(err); return false; }
    return true;
  }

  document.getElementById('wizNext').addEventListener('click', () => goStep(currentStep + 1));
  document.getElementById('wizBack').addEventListener('click', () => goStep(currentStep - 1));

  /* ---- Card selection ---- */
  document.querySelectorAll('.wiz-card-select').forEach(card => {
    card.addEventListener('click', function () {
      const type = this.dataset.type;
      const id   = this.dataset.id;
      // Deselect others in same group
      document.querySelectorAll(`.wiz-card-select[data-type="${type}"]`).forEach(c => c.classList.remove('selected'));
      this.classList.add('selected');

      if (type === 'network') {
        state.networkId   = id === '0' ? 0 : parseInt(id);
        const n = WZ.networks.find(x => x.id == id);
        state.networkName = n ? n.name : 'Generisk';
      } else if (type === 'vendor') {
        state.vendor = id;
        state.platformId = null; state.platformName = '';
        state.modelId    = null; state.modelName    = '';
      } else if (type === 'platform') {
        state.platformId = parseInt(id);
        const p = WZ.platforms.find(x => x.id == id);
        state.platformName = p ? p.platform : '';
      } else if (type === 'model') {
        state.modelId = parseInt(id);
        const m = WZ.models.find(x => x.id == id);
        state.modelName = m ? m.model_name : '';
      } else if (type === 'role') {
        state.roleId = parseInt(id);
        const r = WZ.roles.find(x => x.id == id);
        state.roleName = r ? r.name : '';
      } else if (type === 'site') {
        state.siteId = id === '0' ? 0 : parseInt(id);
        const s = WZ.sites.find(x => x.id == id);
        state.siteName = s ? s.name : 'Uten lokasjon';
      } else if (type === 'device_mode') {
        state.deviceMode = id;
        const wrap = document.getElementById('devicePickerWrap');
        if (wrap) wrap.classList.toggle('d-none', id !== 'existing');
        if (id === 'existing') loadDeviceList();
        if (id === 'adhoc') { state.deviceId = null; state.deviceHostname = ''; state.deviceParams = {}; }
      }
    });
  });

  /* ---- Model search ---- */
  document.getElementById('modelSearch')?.addEventListener('input', function () { filterModels(this.value); });

  /* ---- Site search ---- */
  document.getElementById('siteSearch')?.addEventListener('input', function () { filterSites(this.value); });

  function filterPlatforms() {
    document.querySelectorAll('.platform-option').forEach(el => {
      el.style.display = (!state.vendor || el.dataset.vendor === state.vendor) ? '' : 'none';
    });
  }

  function filterModels(q = '') {
    const ql = q.toLowerCase();
    document.querySelectorAll('.model-option').forEach(el => {
      const vendorMatch = !state.vendor || state.vendor === 'other' || el.dataset.vendor === state.vendor;
      const qMatch = !ql || el.dataset.modelName.includes(ql) || el.dataset.series.includes(ql);
      el.style.display = (vendorMatch && qMatch) ? '' : 'none';
    });
  }

  function filterSites(q = '') {
    const ql = q.toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('.site-option').forEach(el => {
      const match = !ql || el.dataset.name.includes(ql);
      el.style.display = match ? '' : 'none';
      if (match) visible++;
    });
    const noResults = document.getElementById('siteNoResults');
    if (noResults) noResults.style.display = (visible === 0 && ql) ? '' : 'none';
  }

  /* ---- Device loader ---- */
  async function loadDeviceList() {
    const picker = document.getElementById('devicePicker');
    if (!picker) return;
    picker.innerHTML = '<option value="">Lastar …</option>';
    try {
      const res = await fetch(WZ.ajaxUrl + 'devices&site_id=' + (state.siteId || 0));
      const devices = await res.json();
      picker.innerHTML = '<option value="">-- Velg enhet --</option>';
      devices.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.hostname + (d.management_ip ? ' (' + d.management_ip + ')' : '');
        opt.dataset.params = JSON.stringify(d.params_json || {});
        picker.appendChild(opt);
      });
    } catch (e) { picker.innerHTML = '<option value="">Feil ved lasting</option>'; }
  }

  document.getElementById('devicePicker')?.addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    state.deviceId       = this.value ? parseInt(this.value) : null;
    state.deviceHostname = opt?.textContent?.split(' (')[0] || '';
    state.deviceParams   = state.deviceId ? JSON.parse(opt.dataset.params || '{}') : {};
  });

  /* ---- Modul-sjekkliste ---- */
  function buildModuleChecklist() {
    const container = document.getElementById('moduleChecklist');
    if (!container) return;

    const suggested = WZ.modules.filter(m => {
      const modelMatch   = !m.model_id   || m.model_id   == state.modelId;
      const networkMatch = !m.network_id || m.network_id == state.networkId;
      return modelMatch && networkMatch;
    });

    const allForModel = WZ.modules.filter(m => !m.model_id || m.model_id == state.modelId);
    const hiddenCount = allForModel.length - suggested.length;

    if (suggested.length === 0) {
      container.innerHTML = '<p class="text-muted small">Ingen maler funnet for valgt modell/nettverk.'
        + (hiddenCount > 0 ? ` (${hiddenCount} moduler finst for andre nettverk.)` : '')
        + ' <a href="/?page=network_config_module_edit">Opprett konfig-mal</a>.</p>';
      return;
    }

    const prevIds = new Set(state.moduleIds);
    const netName = state.networkId ? (WZ.networks.find(n => n.id == state.networkId)?.name || '') : '';

    container.innerHTML = '<div class="row g-2">' + suggested.map(m => {
      const checked     = prevIds.has(m.id) || !prevIds.size;
      const varCount    = (m.variables_json || []).length;
      const lineCount   = (m.template_text.match(/\n/g) || []).length + 1;
      const isNetSpecific = !!m.network_id;
      return `
      <div class="col-sm-6 col-lg-4">
        <label class="card module-card h-100 cursor-pointer ${checked ? 'selected' : ''}">
          <div class="card-body py-2 d-flex gap-2 align-items-start">
            <div class="pt-1 flex-shrink-0">
              <input class="form-check-input module-cb" type="checkbox" value="${m.id}" ${checked ? 'checked' : ''}>
            </div>
            <div class="flex-grow-1 wiz-min-w-0">
              <div class="fw-semibold small">${esc(m.name)}</div>
              ${m.description ? `<div class="text-muted" style="font-size:.72rem;line-height:1.3;">${esc(m.description)}</div>` : ''}
              <div class="mt-1 d-flex gap-1 flex-wrap">
                ${isNetSpecific ? `<span class="badge text-bg-info fw-normal" style="font-size:.68rem;">${esc(netName)}</span>` : ''}
                ${varCount > 0 ? `<span class="badge text-bg-primary fw-normal" style="font-size:.68rem;">${varCount} var.</span>` : ''}
                <span class="badge text-bg-light border fw-normal" style="font-size:.68rem;">${lineCount} linjer</span>
              </div>
            </div>
            <div class="align-self-center flex-shrink-0 module-icon-wrap" style="color:${checked ? '#0d6efd' : '#adb5bd'};">
              <i class="bi ${checked ? 'bi-check-circle-fill' : 'bi-circle'} module-icon fs-5"></i>
            </div>
          </div>
        </label>
      </div>`;
    }).join('') + '</div>'
    + (hiddenCount > 0 ? `<p class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>${hiddenCount} mal${hiddenCount !== 1 ? 'er' : ''} er skjult fordi de tilhører et annet nettverk.</p>` : '');

    container.querySelectorAll('.module-cb').forEach(cb => {
      cb.addEventListener('change', function () {
        const card = this.closest('.module-card');
        if (card) {
          card.classList.toggle('selected', this.checked);
          const iconWrap = card.querySelector('.module-icon-wrap');
          const icon     = card.querySelector('.module-icon');
          if (iconWrap) iconWrap.style.color = this.checked ? '#0d6efd' : '#adb5bd';
          if (icon)     icon.className = `bi ${this.checked ? 'bi-check-circle-fill' : 'bi-circle'} module-icon fs-5`;
        }
        syncModuleIds();
      });
    });

    syncModuleIds();
  }

  function syncModuleIds() {
    state.moduleIds = [...document.querySelectorAll('.module-cb:checked')].map(cb => parseInt(cb.value));
  }

  document.getElementById('selectAllModules')?.addEventListener('click',   () => { document.querySelectorAll('.module-cb').forEach(cb => cb.checked = true);  syncModuleIds(); });
  document.getElementById('deselectAllModules')?.addEventListener('click', () => { document.querySelectorAll('.module-cb').forEach(cb => cb.checked = false); syncModuleIds(); });

  /* ---- Parameter-manifest ---- */
  function buildParamTable() {
    const container = document.getElementById('paramTable');
    if (!container) return;

    // 1. Samle alle variabler fra valgte maler
    const varSet  = new Map(); // varName -> {description, default}
    state.moduleIds.forEach(id => {
      const mod = WZ.modules.find(m => m.id === id);
      if (!mod) return;
      (mod.template_text.match(/\{\{([^}]+)\}\}/g) || []).forEach(t => {
        const n = t.slice(2, -2).trim();
        if (!varSet.has(n)) varSet.set(n, {description:'', default:''});
      });
      (mod.variables_json || []).forEach(v => {
        varSet.set(v.name, {description: v.description || '', default: v.default || ''});
      });
    });

    if (varSet.size === 0) {
      container.innerHTML = '<p class="text-muted small">Ingen variabler funnet i valgte maler.</p>'; return;
    }

    // 2. Bygg arvekjede
    const chain = [];
    const platform = WZ.platforms.find(p => p.id === state.platformId);
    const model    = WZ.models.find(m => m.id === state.modelId);
    const network  = WZ.networks.find(n => n.id === state.networkId);
    const role     = WZ.roles.find(r => r.id === state.roleId);
    const site     = WZ.sites.find(s => s.id === state.siteId);

    if (platform?.params_json) chain.push({src:'platform', params: platform.params_json});
    if (model?.specs)          chain.push({src:'modell',   params: model.specs});
    if (network?.params_json)  chain.push({src:'nettverk', params: network.params_json});
    if (role?.params_json)     chain.push({src:'rolle',    params: role.params_json});
    if (site?.params_json)     chain.push({src:'site',     params: site.params_json});
    if (Object.keys(state.deviceParams).length) chain.push({src:'enhet', params: state.deviceParams});

    const resolved = {}, sources = {};
    chain.forEach(({src, params}) => {
      if (!params || typeof params !== 'object') return;
      Object.entries(params).forEach(([k, v]) => {
        if (v !== null && v !== undefined && String(v) !== '') {
          resolved[k] = String(v); sources[k] = src;
        }
      });
    });

    // 3. Bygg state.params
    state.params = {};
    varSet.forEach((meta, name) => {
      const inherited = resolved[name] || '';
      const defVal    = meta.default || '';
      const val       = inherited || defVal;
      state.params[name] = {
        value:       state.params[name]?.value || val,
        source:      inherited ? sources[name] : (defVal ? 'standard' : 'mangler'),
        description: meta.description,
        required:    !val,
      };
    });

    // Sorter: manglande først
    const sorted = [...varSet.keys()].sort((a, b) => {
      const am = state.params[a].source === 'mangler';
      const bm = state.params[b].source === 'mangler';
      return am === bm ? a.localeCompare(b) : (am ? -1 : 1);
    });

    const srcBadge = {
      platform:'text-bg-primary', modell:'text-bg-info', nettverk:'text-bg-success',
      rolle:'text-bg-purple', site:'text-bg-warning text-dark', enhet:'text-bg-danger',
      standard:'text-bg-secondary', mangler:'text-bg-warning text-dark',
    };

    container.innerHTML = `
      <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light"><tr>
          <th style="width:160px;">Variabel</th>
          <th style="width:90px;">Kjelde</th>
          <th>Verdi</th>
          <th class="d-none d-md-table-cell">Beskrivelse</th>
        </tr></thead>
        <tbody>
          ${sorted.map(name => {
            const p = state.params[name];
            const badge = srcBadge[p.source] || 'text-bg-secondary';
            return `<tr>
              <td><code class="small">${esc(name)}</code></td>
              <td><span class="badge ${badge} fw-normal small">${esc(p.source)}</span></td>
              <td>
                <input type="text" class="form-control form-control-sm param-input"
                       data-var="${esc(name)}"
                       value="${esc(p.value)}"
                       placeholder="${p.required ? '⚠ Påkrevd' : ''}"
                       style="${p.required ? 'border-color:#ffc107' : ''}">
              </td>
              <td class="d-none d-md-table-cell text-muted small">${esc(p.description)}</td>
            </tr>`;
          }).join('')}
        </tbody>
      </table></div>`;

    document.querySelectorAll('.param-input').forEach(inp => {
      inp.addEventListener('input', function () {
        const v = this.dataset.var;
        if (state.params[v]) {
          state.params[v].value  = this.value;
          state.params[v].source = 'brukar';
          this.style.borderColor = '';
        }
        generateLivePreview();
      });
    });
    generateLivePreview();
  }

  /* ---- Config-generering (delt hjelpar, live + forhåndsvisning) ---- */
  function buildConfigText() {
    const paramMap = {};
    Object.entries(state.params).forEach(([k, v]) => { paramMap[k] = v.value || ''; });
    const parts = [];
    state.moduleIds.forEach(id => {
      const mod = WZ.modules.find(m => m.id === id);
      if (!mod) return;
      let txt = mod.template_text;
      Object.entries(paramMap).forEach(([k, v]) => { txt = txt.split('{{' + k + '}}').join(v); });
      parts.push('! === ' + mod.name + ' ===\n' + txt);
    });
    return parts.join('\n!\n') || '(tom – ingen innhald i valde modular)';
  }

  function generateLivePreview() {
    const pre     = document.getElementById('liveConfigPre');
    const statsEl = document.getElementById('liveStats');
    if (!pre) return;

    let missingCount = 0, filledCount = 0;
    Object.values(state.params).forEach(p => {
      if (!p.value && p.required) missingCount++;
      else if (p.value) filledCount++;
    });

    const output = buildConfigText();
    pre.textContent = output;

    if (statsEl) {
      const totalLines  = output.split('\n').length;
      const statusBadge = missingCount > 0
        ? `<span class="badge text-bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>${missingCount} felt mangler</span>`
        : `<span class="badge text-bg-success"><i class="bi bi-check2 me-1"></i>Alle felt utfylte</span>`;
      statsEl.innerHTML = statusBadge
        + `<span class="text-muted small">${totalLines} linjer</span>`
        + `<span class="text-muted small">${state.moduleIds.length} modul${state.moduleIds.length !== 1 ? 'ar' : ''}</span>`;
    }
  }

  function generatePreview() {
    const pre = document.getElementById('configPreview');
    if (!pre) return;
    pre.textContent = buildConfigText();
  }

  document.getElementById('copyPreviewBtn')?.addEventListener('click', function () {
    const pre = document.getElementById('configPreview');
    if (!pre) return;
    navigator.clipboard.writeText(pre.textContent).then(() => {
      this.innerHTML = '<i class="bi bi-check2 me-1"></i>Kopiert!';
      setTimeout(() => { this.innerHTML = '<i class="bi bi-clipboard me-1"></i>Kopier'; }, 2000);
    });
  });

  document.getElementById('copyLiveBtn')?.addEventListener('click', function () {
    const pre = document.getElementById('liveConfigPre');
    if (!pre) return;
    navigator.clipboard.writeText(pre.textContent).then(() => {
      this.innerHTML = '<i class="bi bi-check2"></i>';
      setTimeout(() => { this.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 2000);
    });
  });

  document.getElementById('downloadLiveBtn')?.addEventListener('click', function () {
    const pre = document.getElementById('liveConfigPre');
    if (!pre || !pre.textContent) return;
    const base     = (state.deviceHostname || state.modelName || 'konfig').replace(/[^a-z0-9_-]/gi, '_').toLowerCase();
    const blob     = new Blob([pre.textContent], {type: 'text/plain'});
    const url      = URL.createObjectURL(blob);
    const a        = document.createElement('a');
    a.href = url; a.download = base + '.txt';
    document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
  });

  /* ---- Oppsummering ---- */
  function buildSummary() {
    const tbody = document.getElementById('summaryTable');
    if (!tbody) return;
    const rows = [
      ['Nettverk', state.networkName || '–'],
      ['Leverandør', state.vendor || '–'],
      ['Plattform', state.platformName || '–'],
      ['Modell', state.modelName || '–'],
      ['Rolle', state.roleName || '–'],
      ['Site', state.siteName || '–'],
      ['Enhet', state.deviceMode === 'existing' ? (state.deviceHostname || '–') : 'Ad hoc'],
      ['Moduler', state.moduleIds.length + ' valde'],
      ['Parametere', Object.keys(state.params).length + ' totalt'],
    ];
    tbody.innerHTML = rows.map(([k, v]) =>
      `<tr><th class="fw-normal text-muted" style="width:120px">${esc(k)}</th><td>${esc(v)}</td></tr>`
    ).join('');

    // Foreslå tittel
    const titleEl = document.getElementById('draftTitle');
    if (titleEl && !titleEl.value) {
      const parts = [state.modelName, state.roleName, state.siteName].filter(Boolean);
      titleEl.value = parts.join(' – ') || 'Ny konfigurasjon';
    }
  }

  /* ---- Lagre utkast ---- */
  document.getElementById('saveDraftBtn')?.addEventListener('click', async function () {
    const title = document.getElementById('draftTitle')?.value?.trim();
    if (!title) { alert('Tittel er påkrevd.'); return; }

    const pre = document.getElementById('configPreview');
    const configText = pre ? pre.textContent : '';

    const payload = {
      csrf: WZ.csrf, title,
      notes: document.getElementById('draftNotes')?.value || '',
      network_id: state.networkId,    network_name: state.networkName,
      platform_id: state.platformId,  platform_name: state.platformName,
      model_id: state.modelId,        model_name: state.modelName,
      role_id: state.roleId,          role_name: state.roleName,
      site_id: state.siteId,          site_name: state.siteName,
      device_id: state.deviceId,      device_hostname: state.deviceHostname,
      params_manifest: Object.entries(state.params).map(([name, p]) => ({name, value: p.value, source: p.source})),
      module_ids: state.moduleIds,
      config_text: configText,
    };

    const statusEl = document.getElementById('saveStatus');
    this.disabled = true;
    if (statusEl) statusEl.textContent = 'Lagrer ...';

    try {
      const res  = await fetch(WZ.saveUrl, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
      const data = await res.json();
      if (data.ok) {
        if (statusEl) statusEl.innerHTML = '<span class="text-success">Lagret!</span>';
        setTimeout(() => { window.location.href = WZ.draftUrl + data.id; }, 600);
      } else {
        if (statusEl) statusEl.textContent = 'Feil: ' + (data.error || 'Ukjend feil');
        this.disabled = false;
      }
    } catch (e) {
      if (statusEl) statusEl.textContent = 'Nettverksfeil.';
      this.disabled = false;
    }
  });

  /* ---- Hjelpefunksjonar ---- */
  function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

})();
</script>

<style>
.wiz-card-select { transition: border-color .15s, box-shadow .15s; cursor:pointer; border: 2px solid transparent; }
.wiz-card-select.selected { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13,110,253,.15); }
.wiz-card-select:hover { border-color: #86b7fe; }
.module-card { transition: border-color .15s, box-shadow .15s; cursor:pointer; border: 2px solid transparent; }
.module-card.selected { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13,110,253,.15); }
.module-card:hover:not(.selected) { border-color: #86b7fe; }
.wiz-dot { flex:1; }
.wiz-dot-circle { width:28px; height:28px; border-radius:50%; background:#dee2e6; color:#6c757d; font-size:.75rem; font-weight:600; display:flex; align-items:center; justify-content:center; margin:0 auto 2px; transition: background .2s; }
.wiz-dot.active   .wiz-dot-circle { background:#0d6efd; color:#fff; }
.wiz-dot.completed .wiz-dot-circle { background:#198754; color:#fff; }
.wiz-dot-label { font-size:.65rem; color:#6c757d; }
.wiz-dot.active   .wiz-dot-label { color:#0d6efd; font-weight:600; }
.text-bg-purple { background-color:#6f42c1!important; color:#fff!important; }
.cursor-pointer { cursor:pointer; }
.btn-xs { padding:.15rem .45rem; font-size:.75rem; line-height:1.4; }
.wiz-terminal { border-radius:.375rem; overflow:hidden; }
.wiz-term-dot { display:inline-block; width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.wiz-min-w-0 { min-width:0; }
</style>
