<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\inc\menu.php
//
// MENY (sidebar)
// - Kompakt meny med collapse-seksjoner
// - Rettighetsmodell: admin + roller fra user_roles (+ bakoverkompatible session-roller/perms)
// - NB: Prosjektrom / projecthub er fjernet (ingen prosjektstyrings-modul)
//
// OPPDATERT (avatar + ryddigere brukerlinje):
// - Viser avatar (user_settings.avatar_path) helt til venstre i bruker-knappen
// - Fjerner liste/visning av roller under brukernavn (kun navn vises)
//
// OPPDATERT (API under Admin):
// - Legger "API" under Admin-seksjonen (/?page=api_admin)
// - Admin-seksjonen åpnes automatisk når currentPage = api_admin
//
// ✅ HENDELSER / ENDRINGER (NY MENY-SEKSJON):
// - Egen seksjon med collapse (som Admin/Nettverk)
// - Lenker:
//   - /?page=events
//   - /?page=events_dashboards (ny side vi lager senere)
//
// ✅ HENDELSER / ENDRINGER (FIX):
// - Menylenke peker nå til (/?page=events) slik at den matcher filnavnene:
//   events.php, events_view.php, events_new.php (og ev. events_edit.php via events_edit)
// - Tilgang styres av $canIncidents (admin + support/drift/incident-roller)
//
// ✅ FELTOBJEKTER (NYTT):
// - Legger "Bildekart" under Feltobjekter (/?page=bildekart)
// - Feltobjekter-seksjonen åpnes automatisk når currentPage = bildekart
//
// ✅ BILDEKART (NYTT):
// - Legger "Last opp bilder" under Bildekart (/?page=image_upload)

use App\Database;

$currentPage    = $_GET['page'] ?? 'start';
$grossistVendor = $_GET['vendor'] ?? '';

$username    = $_SESSION['username'] ?? '';
$fullname    = $_SESSION['fullname'] ?? $username;
$displayName = $fullname ?: $username;

$initials = '';
if (function_exists('mb_strtoupper') && function_exists('mb_substr')) {
    $initials = mb_strtoupper(mb_substr($displayName, 0, 1), 'UTF-8');
} else {
    $initials = strtoupper(substr($displayName, 0, 1));
}

$pageTitle = $pageTitle ?? 'Kembo';

// ---------------------------------------------------------
// Rettighetsmodell (Admin + roller fra user_roles)
//   - Ingen hardkoding på brukernavn
//   - user_roles er fasit for tilgang
//   - NB: denne fila kan bli inkludert flere steder -> guards + unike variabelnavn
// ---------------------------------------------------------

if (!function_exists('normalize_list')) {
    function normalize_list($v): array {
        if (is_array($v)) {
            return array_values(array_filter(array_map('strval', $v)));
        }
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

if (!function_exists('table_exists_menu')) {
    function table_exists_menu(PDO $pdo, string $table): bool {
        try {
            $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

$menuPdo       = null;
$menuUserId    = 0;
$avatarPath    = null; // fra user_settings.avatar_path
$avatarUrlSafe = '';   // safe for output

// Start med session-roller hvis de finnes (kan være tomt)
$roles = normalize_list($_SESSION['roles'] ?? null);
$perms = normalize_list($_SESSION['permissions'] ?? null);

// DB-oppslag: user_id + user_roles + avatar (user_settings)
if ($username !== '') {
    try {
        $menuPdo = Database::getConnection();

        // Finn user_id
        $stmt = $menuPdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $menuUserId = (int)($u['id'] ?? 0);
        }

        // Hent roller fra user_roles (viktig!)
        if ($menuUserId > 0) {
            $stmt = $menuPdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
            $stmt->execute([':uid' => $menuUserId]);
            $dbRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $roles = array_merge($roles, normalize_list($dbRoles));

            // Hent avatar om tabellen finnes
            if (table_exists_menu($menuPdo, 'user_settings')) {
                $stmt = $menuPdo->prepare('SELECT avatar_path FROM user_settings WHERE user_id = :uid LIMIT 1');
                $stmt->execute([':uid' => $menuUserId]);
                $avatarPath = $stmt->fetchColumn();
                if (is_string($avatarPath)) {
                    $avatarPath = trim($avatarPath);
                    if ($avatarPath === '') {
                        $avatarPath = null;
                    }
                } else {
                    $avatarPath = null;
                }
            }
        }
    } catch (\Throwable $e) {
        // DB nede => behold sessionverdier
        $avatarPath = null;
    }
}

// Normaliser roller/perms
$roles = array_values(array_unique(array_map('strtolower', $roles)));
$perms = array_values(array_unique(array_map('strtolower', $perms)));

// Admin via rolle (user_roles)
$isAdmin = has_any(['admin'], $roles);

// Seksjons-tilgang
$canUsers     = $isAdmin;

$canNetwork   = $isAdmin
    || has_any(['network','nettverk','support'], $roles)
    || has_any(['network','nettverk','support'], $perms);

$canGrossist  = $isAdmin
    || has_any(['grossist','wholesale','support'], $roles)
    || has_any(['grossist','wholesale','support'], $perms);

$canLogistikk = $isAdmin
    || has_any(['warehouse_read','warehouse_write','logistikk','lager','inventory','support'], $roles)
    || has_any(['warehouse_read','warehouse_write','logistikk','lager','inventory','support'], $perms);

$canInvoice = $isAdmin
    || has_any(['invoice','faktura','billing','crm','support'], $roles)
    || has_any(['invoice','faktura','billing','crm','support'], $perms);

// ✅ Feltobjekter: primært node_read/node_write, men behold bakoverkompatibilitet (canNetwork)
$canFeltobjekter = $isAdmin
    || has_any(['node_read','node_write','feltobjekt','feltobjekter','documentation','dokumentasjon','support'], $roles)
    || has_any(['node_read','node_write','feltobjekt','feltobjekter','documentation','dokumentasjon','support'], $perms)
    || $canNetwork;

// ✅ Avtaler & kontrakter – tilgang (synonymer støttes)
$canContracts = $isAdmin
    || has_any([
        'contracts_read','contracts_write','contracts_admin','contracts',
        'avtaler','kontrakter','contract','agreement',
        'agreements_read','agreements_write','agreements_admin'
    ], $roles)
    || has_any([
        'contracts_read','contracts_write','contracts_admin','contracts',
        'avtaler','kontrakter','contract','agreement',
        'agreements_read','agreements_write','agreements_admin'
    ], $perms);

// ✅ KPI/Mål – egen modul
$canKpi = $isAdmin
    || has_any(['report_admin','report_user','kpi','mål','maal','kpi_user','kpi_admin'], $roles)
    || has_any(['report_admin','report_user','kpi','mål','maal','kpi_user','kpi_admin'], $perms);

$canKpiAdmin = $isAdmin
    || has_any(['report_admin','kpi_admin'], $roles)
    || has_any(['report_admin','kpi_admin'], $perms);

// ✅ Hendelser / Endringer – tilgang
// (kundesenter/support skal kunne lese, drift/incident/maintenance kan lese/vedlikeholde)
$canIncidents = $isAdmin
    || has_any([
        'incidents_read','incidents_write','incidents_admin',
        'incident','incidents','hendelse','hendelser','endring','endringer',
        'maintenance','planned_work','outage',
        'support','drift','noc'
    ], $roles)
    || has_any([
        'incidents_read','incidents_write','incidents_admin',
        'incident','incidents','hendelse','hendelser','endring','endringer',
        'maintenance','planned_work','outage',
        'support','drift','noc'
    ], $perms);

// ---------------------------------------------------------
// Hent grossist-leverandører dynamisk (for alle med grossist-tilgang)
// ---------------------------------------------------------
$grossistVendors = [];
if ($canGrossist) {
    try {
        $menuPdo = $menuPdo ?? Database::getConnection();

        $allowedSlugs = normalize_list($_SESSION['grossist_vendors'] ?? null);

        if (!empty($allowedSlugs)) {
            $in = implode(',', array_fill(0, count($allowedSlugs), '?'));
            $stmt = $menuPdo->prepare(
                "SELECT slug, name
                   FROM grossist_vendors
                  WHERE is_active = 1
                    AND slug IN ($in)
                  ORDER BY sort_order, name"
            );
            $stmt->execute(array_values($allowedSlugs));
        } else {
            $stmt = $menuPdo->query(
                'SELECT slug, name
                   FROM grossist_vendors
                  WHERE is_active = 1
                  ORDER BY sort_order, name'
            );
        }

        $grossistVendors = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $grossistVendors = [
            ['slug' => 'eviny',         'name' => 'Eviny'],
            ['slug' => 'telia',         'name' => 'Telia'],
            ['slug' => 'globalconnect', 'name' => 'Global Connect'],
            ['slug' => 'hkf_iot',       'name' => 'HKF IoT'],
        ];
    }
}

// ---------------------------------------------------------
// Skal seksjoner være åpne ved last?
// ---------------------------------------------------------

$adminPages = [
    'users',
    'users_edit',
    'lager_users',
    'lager_users_edit',
    'security',
    'security_ip_filter',
    'api_admin',
    'auth_settings',
];
$adminOpen = in_array($currentPage, $adminPages, true);

$networkPages = [
    'access_routers',
    'edge_routers',
    'service_routers',
    'nni_customers',
    'customer_l2vpn_circuits',
];
$networkOpen = in_array($currentPage, $networkPages, true);

$feltPages = [
    'node_locations',
    'node_location_view',
    'node_location_edit',
    'node_location_templates',
    'node_location_template_edit',

    // ✅ NYTT: bildekart i felt-seksjonen
    'bildekart',

    // ✅ NYTT: opplasting av uassosierte bilder (under bildekart)
    'image_upload',
];
$feltOpen = in_array($currentPage, $feltPages, true);

$logistikkPages = [
    'logistikk',
    'logistikk_products',
    'logistikk_categories',
    'logistikk_movements',
    'logistikk_receipts',
    'inv_reports',
    'inv_out_shop',
    'logistikk_storage_admin',

    // behold disse under logistikk (lager/uttak-typen prosjekter)
    'projects_admin',

    'crm_accounts',
    'billing_invoice_new',
    'billing_invoice_edit',
    'billing_invoice_print',
    'billing_invoice_list',
    'billing_invoices',
    'billing_invoices',
];
$logistikkOpen = in_array($currentPage, $logistikkPages, true);

$grossistOpen = ($currentPage === 'grossist' || $currentPage === 'grossist_config');

$isInvoiceActive = in_array(
    $currentPage,
    [
        'billing_invoice_new',
        'billing_invoice_edit',
        'billing_invoice_print',
        'billing_invoice_list',
        'billing_invoices',
    ],
    true
);

$contractsPages = [
    'contracts',
    'contracts_new',
    'contracts_view',
    'contracts_edit',
    'contracts_alerts',
    'contracts_settings',
];
$contractsOpen = in_array($currentPage, $contractsPages, true);

// ✅ KPI/Mål: sider i egen modul
$kpiPages = [
    'report_kpi_dashboard',
    'report_kpi_entry',
    'report_kpi_admin',
];
$kpiOpen = in_array($currentPage, $kpiPages, true);

// ✅ Hendelser / Endringer: sider (for aktiv-markering) + åpning av seksjon
$eventPages = [
    'events',
    'events_new',
    'events_view',
    'events_edit',
    'events_dashboards',
];
$eventsActive = in_array($currentPage, $eventPages, true);
$eventsOpen   = $eventsActive;

// Avatar-url (enkelt + trygt): vi outputter path som en vanlig URL.
// (Antar at avatar_path lagres som relativ/absolutt web-path, f.eks "/uploads/avatars/x.jpg")
if (is_string($avatarPath) && $avatarPath !== '') {
    // Liten "sanity": fjern kontrolltegn
    $avatarUrlSafe = preg_replace('/[\x00-\x1F\x7F]/u', '', $avatarPath);
    $avatarUrlSafe = htmlspecialchars($avatarUrlSafe, ENT_QUOTES, 'UTF-8');
}
?>

<aside class="app-sidebar" id="appSidebar">

    <!-- Brand -->
    <a href="/?page=start" class="sidebar-brand">
        <div class="sidebar-brand-icon">
            <i class="bi bi-broadcast-pin"></i>
        </div>
        <div>
            <span class="sidebar-brand-name">Teknisk</span>
            <span class="sidebar-brand-sub">HKBB &middot; Administrasjon</span>
        </div>
    </a>

    <!-- Navigation -->
    <nav class="sidebar-nav" aria-label="Hovedmeny">

        <a href="/?page=start"
           class="nav-item <?= $currentPage === 'start' ? 'active' : '' ?>">
            <i class="bi bi-house nav-icon"></i>
            <span class="nav-label">Oversikt</span>
        </a>

        <?php if ($canIncidents): ?>
        <div class="nav-section-label">Drift</div>

        <button type="button" id="eventsToggle"
                class="nav-item nav-toggle"
                aria-expanded="<?= $eventsOpen ? 'true' : 'false' ?>"
                aria-controls="eventsMenu">
            <i class="bi bi-exclamation-triangle nav-icon"></i>
            <span class="nav-label">Hendelser &amp; endringer</span>
            <i class="bi bi-chevron-right nav-chevron"></i>
        </button>
        <div id="eventsMenu" class="nav-sub-group <?= $eventsOpen ? 'show' : '' ?>">
            <a href="/?page=events"
               class="nav-item nav-sub <?= in_array($currentPage, ['events','events_new','events_view','events_edit'], true) ? 'active' : '' ?>">
                <i class="bi bi-list-task nav-icon"></i>
                <span class="nav-label">Hendelsesliste</span>
            </a>
            <a href="/?page=events_dashboards"
               class="nav-item nav-sub <?= $currentPage === 'events_dashboards' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2 nav-icon"></i>
                <span class="nav-label">Dashboard</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($canKpi): ?>
        <div class="nav-section-label">Rapporter</div>

        <button type="button" id="kpiToggle"
                class="nav-item nav-toggle"
                aria-expanded="<?= $kpiOpen ? 'true' : 'false' ?>"
                aria-controls="kpiMenu">
            <i class="bi bi-bullseye nav-icon"></i>
            <span class="nav-label">Mål &amp; KPI</span>
            <i class="bi bi-chevron-right nav-chevron"></i>
        </button>
        <div id="kpiMenu" class="nav-sub-group <?= $kpiOpen ? 'show' : '' ?>">
            <a href="/?page=report_kpi_dashboard"
               class="nav-item nav-sub <?= $currentPage === 'report_kpi_dashboard' ? 'active' : '' ?>">
                <i class="bi bi-graph-up nav-icon"></i>
                <span class="nav-label">Dashboard</span>
            </a>
            <a href="/?page=report_kpi_entry"
               class="nav-item nav-sub <?= $currentPage === 'report_kpi_entry' ? 'active' : '' ?>">
                <i class="bi bi-pencil-square nav-icon"></i>
                <span class="nav-label">Innrapportering</span>
            </a>
            <?php if ($canKpiAdmin): ?>
            <a href="/?page=report_kpi_admin"
               class="nav-item nav-sub <?= $currentPage === 'report_kpi_admin' ? 'active' : '' ?>">
                <i class="bi bi-gear nav-icon"></i>
                <span class="nav-label">Administrasjon</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($canContracts): ?>
        <div class="nav-section-label">Kontrakter</div>

        <button type="button" id="contractsToggle"
                class="nav-item nav-toggle"
                aria-expanded="<?= $contractsOpen ? 'true' : 'false' ?>"
                aria-controls="contractsMenu">
            <i class="bi bi-file-earmark-text nav-icon"></i>
            <span class="nav-label">Avtaler &amp; kontrakter</span>
            <i class="bi bi-chevron-right nav-chevron"></i>
        </button>
        <div id="contractsMenu" class="nav-sub-group <?= $contractsOpen ? 'show' : '' ?>">
            <a href="/?page=contracts"
               class="nav-item nav-sub <?= $currentPage === 'contracts' ? 'active' : '' ?>">
                <i class="bi bi-journal-text nav-icon"></i>
                <span class="nav-label">Oversikt</span>
            </a>
            <a href="/?page=contracts_new"
               class="nav-item nav-sub <?= $currentPage === 'contracts_new' ? 'active' : '' ?>">
                <i class="bi bi-plus-circle nav-icon"></i>
                <span class="nav-label">Ny avtale</span>
            </a>
            <a href="/?page=contracts_alerts"
               class="nav-item nav-sub <?= $currentPage === 'contracts_alerts' ? 'active' : '' ?>">
                <i class="bi bi-bell nav-icon"></i>
                <span class="nav-label">Varsler &amp; fornyelser</span>
            </a>
            <a href="/?page=contracts_settings"
               class="nav-item nav-sub <?= $currentPage === 'contracts_settings' ? 'active' : '' ?>">
                <i class="bi bi-gear nav-icon"></i>
                <span class="nav-label">Innstillinger</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($canNetwork): ?>
        <div class="nav-section-label">Infrastruktur</div>

        <button type="button" id="netverkToggle"
                class="nav-item nav-toggle"
                aria-expanded="<?= $networkOpen ? 'true' : 'false' ?>"
                aria-controls="netverkMenu">
            <i class="bi bi-hdd-network nav-icon"></i>
            <span class="nav-label">Nettverk</span>
            <i class="bi bi-chevron-right nav-chevron"></i>
        </button>
        <div id="netverkMenu" class="nav-sub-group <?= $networkOpen ? 'show' : '' ?>">
            <a href="/?page=access_routers"
               class="nav-item nav-sub <?= $currentPage === 'access_routers' ? 'active' : '' ?>">
                <i class="bi bi-hdd-network nav-icon"></i>
                <span class="nav-label">Aksess-rutere</span>
            </a>
            <a href="/?page=edge_routers"
               class="nav-item nav-sub <?= $currentPage === 'edge_routers' ? 'active' : '' ?>">
                <i class="bi bi-diagram-2 nav-icon"></i>
                <span class="nav-label">Edge-rutere</span>
            </a>
            <a href="/?page=service_routers"
               class="nav-item nav-sub <?= $currentPage === 'service_routers' ? 'active' : '' ?>">
                <i class="bi bi-diagram-3 nav-icon"></i>
                <span class="nav-label">Service-rutere</span>
            </a>
            <a href="/?page=nni_customers"
               class="nav-item nav-sub <?= $currentPage === 'nni_customers' ? 'active' : '' ?>">
                <i class="bi bi-plug nav-icon"></i>
                <span class="nav-label">NNI-sluttkunder</span>
            </a>
            <a href="/?page=customer_l2vpn_circuits"
               class="nav-item nav-sub <?= $currentPage === 'customer_l2vpn_circuits' ? 'active' : '' ?>">
                <i class="bi bi-plug-fill nav-icon"></i>
                <span class="nav-label">L2VPN-kundekonfig</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($canFeltobjekter): ?>
        <?php if (!$canNetwork): ?><div class="nav-section-label">Infrastruktur</div><?php endif; ?>

        <button type="button" id="feltToggle"
                class="nav-item nav-toggle"
                aria-expanded="<?= $feltOpen ? 'true' : 'false' ?>"
                aria-controls="feltMenu">
            <i class="bi bi-pin-map nav-icon"></i>
            <span class="nav-label">Feltobjekter</span>
            <i class="bi bi-chevron-right nav-chevron"></i>
        </button>
        <div id="feltMenu" class="nav-sub-group <?= $feltOpen ? 'show' : '' ?>">
            <a href="/?page=node_locations"
               class="nav-item nav-sub <?= in_array($currentPage, ['node_locations','node_location_view','node_location_edit'], true) ? 'active' : '' ?>">
                <i class="bi bi-geo-alt nav-icon"></i>
                <span class="nav-label">Feltobjekter</span>
            </a>
            <a href="/?page=node_location_templates"
               class="nav-item nav-sub <?= in_array($currentPage, ['node_location_templates','node_location_template_edit'], true) ? 'active' : '' ?>">
                <i class="bi bi-ui-checks-grid nav-icon"></i>
                <span class="nav-label">Maler</span>
            </a>
            <a href="/?page=bildekart"
               class="nav-item nav-sub <?= $currentPage === 'bildekart' ? 'active' : '' ?>">
                <i class="bi bi-images nav-icon"></i>
                <span class="nav-label">Bildekart</span>
            </a>
            <a href="/?page=image_upload"
               class="nav-item nav-sub nav-sub-2 <?= $currentPage === 'image_upload' ? 'active' : '' ?>">
                <i class="bi bi-upload nav-icon"></i>
                <span class="nav-label">Last opp bilder</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($canGrossist && !empty($grossistVendors)): ?>

        <button type="button" id="grossistToggle"
                class="nav-item nav-toggle"
                aria-expanded="<?= $grossistOpen ? 'true' : 'false' ?>"
                aria-controls="grossistMenu">
            <i class="bi bi-diagram-3 nav-icon"></i>
            <span class="nav-label">Grossistaksess</span>
            <i class="bi bi-chevron-right nav-chevron"></i>
        </button>
        <div id="grossistMenu" class="nav-sub-group <?= $grossistOpen ? 'show' : '' ?>">
            <a href="/?page=grossist"
               class="nav-item nav-sub <?= ($currentPage === 'grossist' && $grossistVendor === '') ? 'active' : '' ?>">
                <i class="bi bi-list-task nav-icon"></i>
                <span class="nav-label">Oversikt</span>
            </a>
            <?php foreach ($grossistVendors as $v): ?>
            <?php
                $slug = (string)($v['slug'] ?? '');
                $name = (string)($v['name'] ?? $slug);
                $isActiveVendor = ($currentPage === 'grossist' && $grossistVendor === $slug);
            ?>
            <a href="/?page=grossist&vendor=<?= urlencode($slug) ?>"
               class="nav-item nav-sub <?= $isActiveVendor ? 'active' : '' ?>">
                <i class="bi bi-building nav-icon"></i>
                <span class="nav-label"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($canLogistikk): ?>
        <div class="nav-section-label">Lager &amp; Logistikk</div>

        <button type="button" id="logistikkToggle"
                class="nav-item nav-toggle"
                aria-expanded="<?= $logistikkOpen ? 'true' : 'false' ?>"
                aria-controls="logistikkMenu">
            <i class="bi bi-box-seam nav-icon"></i>
            <span class="nav-label">Varelager</span>
            <i class="bi bi-chevron-right nav-chevron"></i>
        </button>
        <div id="logistikkMenu" class="nav-sub-group <?= $logistikkOpen ? 'show' : '' ?>">
            <a href="/?page=logistikk"
               class="nav-item nav-sub <?= $currentPage === 'logistikk' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2 nav-icon"></i>
                <span class="nav-label">Oversikt</span>
            </a>
            <a href="/?page=logistikk_products"
               class="nav-item nav-sub <?= $currentPage === 'logistikk_products' ? 'active' : '' ?>">
                <i class="bi bi-box-seam nav-icon"></i>
                <span class="nav-label">Varer</span>
            </a>
            <a href="/?page=logistikk_categories"
               class="nav-item nav-sub <?= $currentPage === 'logistikk_categories' ? 'active' : '' ?>">
                <i class="bi bi-tags nav-icon"></i>
                <span class="nav-label">Kategorier</span>
            </a>
            <a href="/?page=logistikk_receipts"
               class="nav-item nav-sub <?= $currentPage === 'logistikk_receipts' ? 'active' : '' ?>">
                <i class="bi bi-box-arrow-in-down nav-icon"></i>
                <span class="nav-label">Varemottak</span>
            </a>
            <a href="/?page=logistikk_storage_admin"
               class="nav-item nav-sub <?= $currentPage === 'logistikk_storage_admin' ? 'active' : '' ?>">
                <i class="bi bi-geo-alt nav-icon"></i>
                <span class="nav-label">Lagerlokasjoner</span>
            </a>
            <a href="/?page=inv_reports"
               class="nav-item nav-sub <?= in_array($currentPage, ['inv_reports','logistikk_movements'], true) ? 'active' : '' ?>">
                <i class="bi bi-graph-up nav-icon"></i>
                <span class="nav-label">Uttakrapport</span>
            </a>
            <a href="/?page=inv_out_shop"
               class="nav-item nav-sub <?= $currentPage === 'inv_out_shop' ? 'active' : '' ?>">
                <i class="bi bi-cart-check nav-icon"></i>
                <span class="nav-label">Vareuttak</span>
            </a>
            <a href="/?page=projects_admin"
               class="nav-item nav-sub <?= $currentPage === 'projects_admin' ? 'active' : '' ?>">
                <i class="bi bi-kanban nav-icon"></i>
                <span class="nav-label">Prosjekter &amp; arbeidsordrer</span>
            </a>
            <div class="nav-section-label" style="padding-top:.5rem;">Faktura &amp; CRM</div>
            <a href="/?page=crm_accounts"
               class="nav-item nav-sub <?= $currentPage === 'crm_accounts' ? 'active' : '' ?>">
                <i class="bi bi-building nav-icon"></i>
                <span class="nav-label">Kunder &amp; partnere</span>
            </a>
            <a href="/?page=billing_invoice_new"
               class="nav-item nav-sub <?= $currentPage === 'billing_invoice_new' ? 'active' : '' ?>">
                <i class="bi bi-receipt nav-icon"></i>
                <span class="nav-label">Nytt fakturagrunnlag</span>
            </a>
            <a href="/?page=billing_invoices"
               class="nav-item nav-sub <?= $currentPage === 'billing_invoices' ? 'active' : '' ?>">
                <i class="bi bi-archive nav-icon"></i>
                <span class="nav-label">Fakturaarkiv</span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($canUsers): ?>
        <div class="nav-section-label">System</div>

        <button type="button" id="adminToggle"
                class="nav-item nav-toggle"
                aria-expanded="<?= $adminOpen ? 'true' : 'false' ?>"
                aria-controls="adminMenu">
            <i class="bi bi-shield-lock nav-icon"></i>
            <span class="nav-label">Administrasjon</span>
            <i class="bi bi-chevron-right nav-chevron"></i>
        </button>
        <div id="adminMenu" class="nav-sub-group <?= $adminOpen ? 'show' : '' ?>">
            <a href="/?page=users"
               class="nav-item nav-sub <?= in_array($currentPage, ['users','users_edit'], true) ? 'active' : '' ?>">
                <i class="bi bi-people nav-icon"></i>
                <span class="nav-label">Systembrukere</span>
            </a>
            <a href="/?page=lager_users"
               class="nav-item nav-sub <?= in_array($currentPage, ['lager_users','lager_users_edit'], true) ? 'active' : '' ?>">
                <i class="bi bi-boxes nav-icon"></i>
                <span class="nav-label">Lagerbrukere</span>
            </a>
            <a href="/?page=security"
               class="nav-item nav-sub <?= in_array($currentPage, ['security','security_ip_filter'], true) ? 'active' : '' ?>">
                <i class="bi bi-shield-check nav-icon"></i>
                <span class="nav-label">Sikkerhet</span>
            </a>
            <a href="/?page=api_admin"
               class="nav-item nav-sub <?= $currentPage === 'api_admin' ? 'active' : '' ?>">
                <i class="bi bi-key nav-icon"></i>
                <span class="nav-label">API</span>
            </a>
            <a href="/?page=auth_settings"
               class="nav-item nav-sub <?= $currentPage === 'auth_settings' ? 'active' : '' ?>">
                <i class="bi bi-person-badge nav-icon"></i>
                <span class="nav-label">Autentisering</span>
            </a>
        </div>
        <?php endif; ?>

    </nav>

    <!-- Bruker-seksjon -->
    <div class="sidebar-user">
        <button type="button" id="userMenuToggle"
                class="sidebar-user-btn"
                aria-expanded="false"
                aria-controls="userDropdown">
            <div class="user-avatar-wrap">
                <?php if ($avatarUrlSafe !== ''): ?>
                    <img src="<?= $avatarUrlSafe ?>" alt="Avatar">
                <?php else: ?>
                    <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="user-role-label"><?= $isAdmin ? 'Administrator' : 'Bruker' ?></span>
            </div>
            <i class="bi bi-chevron-up user-chevron"></i>
        </button>
        <div id="userDropdown" class="sidebar-user-dropdown">
            <a href="/?page=minside">
                <i class="bi bi-person-circle"></i>
                <span>Min side</span>
            </a>
            <a href="/?page=logout" class="link-danger">
                <i class="bi bi-box-arrow-right"></i>
                <span>Logg ut</span>
            </a>
        </div>
    </div>

</aside>

<div class="app-main">

<script>
document.addEventListener('DOMContentLoaded', function () {

    function setupToggle(btnId, menuId) {
        var btn  = document.getElementById(btnId);
        var menu = document.getElementById(menuId);
        if (!btn || !menu) return;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var isShown = menu.classList.toggle('show');
            btn.setAttribute('aria-expanded', isShown ? 'true' : 'false');
        });
    }

    setupToggle('eventsToggle',    'eventsMenu');
    setupToggle('kpiToggle',       'kpiMenu');
    setupToggle('contractsToggle', 'contractsMenu');
    setupToggle('netverkToggle',   'netverkMenu');
    setupToggle('feltToggle',      'feltMenu');
    setupToggle('grossistToggle',  'grossistMenu');
    setupToggle('logistikkToggle', 'logistikkMenu');
    setupToggle('adminToggle',     'adminMenu');

    (function () {
        var btn  = document.getElementById('userMenuToggle');
        var menu = document.getElementById('userDropdown');
        if (!btn || !menu) return;

        function setOpen(open) {
            menu.classList.toggle('show', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            setOpen(!menu.classList.contains('show'));
        });

        document.addEventListener('click', function () { setOpen(false); });
        menu.addEventListener('click', function (e) { e.stopPropagation(); });
    })();
});
</script>

<header class="app-topbar">
    <h1 class="topbar-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>

    <div class="topbar-right">
        <?php if ($isAdmin): ?>
        <span class="topbar-role-chip">Admin</span>
        <?php endif; ?>

        <a href="/?page=minside" class="topbar-avatar-link" title="Min side">
            <?php if ($avatarUrlSafe !== ''): ?>
                <img src="<?= $avatarUrlSafe ?>" alt="" class="topbar-avatar-img">
            <?php else: ?>
                <div class="topbar-avatar-initial"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <span class="d-none d-lg-inline"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
        </a>
    </div>
</header>

<main class="app-content">
