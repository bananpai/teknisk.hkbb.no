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

<style>
/* /public/inc/menu.php
   Justering:
   - Hovedrubrikker (toggle) IKKE fet
   - Underrubrikkene (nav-sub) litt mer luft mellom seg
   - Brukerfooter: avatar helt til venstre, ingen roller under navn */

.app-sidebar nav { padding-bottom: .25rem; }

.app-sidebar hr.my-2 {
    margin-top: .35rem !important;
    margin-bottom: .35rem !important;
}

.app-sidebar .nav-link {
    padding: .45rem .75rem;
    font-size: .92rem;
    line-height: 1.15rem;
}
.app-sidebar .nav-link i.bi {
    margin-right: .45rem;
}

.app-sidebar .btn-sidebar-toggle {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: .55rem .75rem;
    margin: .15rem 0;

    border: 0;
    background: transparent;
    text-align: left;

    font-size: .95rem;
    font-weight: 400;
    letter-spacing: .15px;
}
.app-sidebar .btn-sidebar-toggle .label {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
}
.app-sidebar .btn-sidebar-toggle .chevron {
    opacity: .8;
}

.app-sidebar .nav-link.nav-sub {
    padding: .46rem .75rem .46rem 1.65rem;
    margin: .10rem 0;
    font-size: .88rem;
    opacity: .95;
}
.app-sidebar .nav-link.nav-sub i.bi {
    margin-right: .45rem;
    opacity: .9;
}

/* Litt ekstra innrykk for "underpunkt under underpunkt" (bilde-upload under bildekart) */
.app-sidebar .nav-link.nav-sub.nav-sub-2 {
    padding-left: 2.25rem;
    font-size: .86rem;
    opacity: .92;
}

.app-sidebar .user-menu-toggle {
    padding: .55rem .65rem;
}
.app-sidebar .user-menu-actions a {
    padding: .45rem .65rem;
    display: flex;
    align-items: center;
    gap: .5rem;
}

/* Avatar */
.app-sidebar .user-avatar,
.app-sidebar .user-avatar-fallback {
    width: 28px;
    height: 28px;
    flex: 0 0 28px;
    border-radius: 999px;
    overflow: hidden;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.app-sidebar .user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

/* FIX: collapse */
.app-sidebar .admin-collapse,
.app-sidebar .contracts-collapse,
.app-sidebar .netverk-collapse,
.app-sidebar .felt-collapse,
.app-sidebar .grossist-collapse,
.app-sidebar .logistikk-collapse,
.app-sidebar .kpi-collapse,
.app-sidebar .events-collapse,
.app-sidebar .user-menu-collapse {
    display: none;
}
.app-sidebar .admin-collapse.show,
.app-sidebar .contracts-collapse.show,
.app-sidebar .netverk-collapse.show,
.app-sidebar .felt-collapse.show,
.app-sidebar .grossist-collapse.show,
.app-sidebar .logistikk-collapse.show,
.app-sidebar .kpi-collapse.show,
.app-sidebar .events-collapse.show,
.app-sidebar .user-menu-collapse.show {
    display: block;
}
</style>

<aside class="app-sidebar">
    <div class="app-sidebar-header d-flex align-items-center">
        <div class="d-inline-flex align-items-center justify-content-center bg-dark text-white me-2"
             style="width:32px;height:32px;font-weight:bold;">
            FD
        </div>
        <div class="d-flex flex-column">
            <span class="fw-semibold">Kembo 2.0</span>
            <span class="small" style="opacity:.75;">Administrasjon og fiberdrift</span>
        </div>
    </div>

    <nav class="mt-2">
        <a href="/?page=start" class="nav-link <?= $currentPage === 'start' ? 'active' : '' ?>">
            <i class="bi bi-house"></i><span>Oversikt</span>
        </a>

        <a href="/?page=minside" class="nav-link <?= $currentPage === 'minside' ? 'active' : '' ?>">
            <i class="bi bi-person"></i><span>Min side</span>
        </a>

        <?php if ($canIncidents): ?>
            <hr class="my-2" style="opacity:.25;">

            <button class="btn-sidebar-toggle" type="button" id="eventsToggle"
                    aria-expanded="<?= $eventsOpen ? 'true' : 'false' ?>" aria-controls="eventsMenu">
                <span class="label"><i class="bi bi-exclamation-triangle"></i><span>Hendelser &amp; endringer</span></span>
                <i class="bi <?= $eventsOpen ? 'bi-chevron-up' : 'bi-chevron-down' ?> chevron"></i>
            </button>

            <div id="eventsMenu" class="events-collapse <?= $eventsOpen ? 'show' : '' ?>">
                <a href="/?page=events"
                   class="nav-link nav-sub <?= in_array($currentPage, ['events','events_new','events_view','events_edit'], true) ? 'active' : '' ?>">
                    <i class="bi bi-list-task"></i><span>Oversikt</span>
                </a>

                <a href="/?page=events_dashboards"
                   class="nav-link nav-sub <?= $currentPage === 'events_dashboards' ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2"></i><span>Events Dashboards</span>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($canUsers): ?>
            <hr class="my-2" style="opacity:.25;">

            <button class="btn-sidebar-toggle" type="button" id="adminToggle"
                    aria-expanded="<?= $adminOpen ? 'true' : 'false' ?>" aria-controls="adminMenu">
                <span class="label"><i class="bi bi-shield-lock"></i><span>Admin</span></span>
                <i class="bi <?= $adminOpen ? 'bi-chevron-up' : 'bi-chevron-down' ?> chevron"></i>
            </button>

            <div id="adminMenu" class="admin-collapse <?= $adminOpen ? 'show' : '' ?>">
                <a href="/?page=users"
                   class="nav-link nav-sub <?= in_array($currentPage, ['users','users_edit'], true) ? 'active' : '' ?>">
                    <i class="bi bi-people"></i><span>Systembrukere</span>
                </a>

                <a href="/?page=lager_users"
                   class="nav-link nav-sub <?= in_array($currentPage, ['lager_users','lager_users_edit'], true) ? 'active' : '' ?>">
                    <i class="bi bi-boxes"></i><span>Lagerbrukere</span>
                </a>

                <a href="/?page=security"
                   class="nav-link nav-sub <?= in_array($currentPage, ['security','security_ip_filter'], true) ? 'active' : '' ?>">
                    <i class="bi bi-shield-check"></i><span>Sikkerhet</span>
                </a>

                <a href="/?page=api_admin"
                   class="nav-link nav-sub <?= $currentPage === 'api_admin' ? 'active' : '' ?>">
                    <i class="bi bi-key"></i><span>API</span>
                </a>

                <a href="/?page=auth_settings"
                   class="nav-link nav-sub <?= $currentPage === 'auth_settings' ? 'active' : '' ?>">
                    <i class="bi bi-person-badge"></i><span>Autentisering</span>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($canKpi): ?>
            <hr class="my-2" style="opacity:.25;">

            <button class="btn-sidebar-toggle" type="button" id="kpiToggle"
                    aria-expanded="<?= $kpiOpen ? 'true' : 'false' ?>" aria-controls="kpiMenu">
                <span class="label"><i class="bi bi-bullseye"></i><span>Mål &amp; KPI</span></span>
                <i class="bi <?= $kpiOpen ? 'bi-chevron-up' : 'bi-chevron-down' ?> chevron"></i>
            </button>

            <div id="kpiMenu" class="kpi-collapse <?= $kpiOpen ? 'show' : '' ?>">
                <a href="/?page=report_kpi_dashboard"
                   class="nav-link nav-sub <?= $currentPage === 'report_kpi_dashboard' ? 'active' : '' ?>">
                    <i class="bi bi-graph-up"></i><span>Dashboard</span>
                </a>

                <a href="/?page=report_kpi_entry"
                   class="nav-link nav-sub <?= $currentPage === 'report_kpi_entry' ? 'active' : '' ?>">
                    <i class="bi bi-pencil-square"></i><span>Innrapportering</span>
                </a>

                <?php if ($canKpiAdmin): ?>
                    <a href="/?page=report_kpi_admin"
                       class="nav-link nav-sub <?= $currentPage === 'report_kpi_admin' ? 'active' : '' ?>">
                        <i class="bi bi-gear"></i><span>Admin (KPI)</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($canContracts): ?>
            <hr class="my-2" style="opacity:.25;">

            <button class="btn-sidebar-toggle" type="button" id="contractsToggle"
                    aria-expanded="<?= $contractsOpen ? 'true' : 'false' ?>" aria-controls="contractsMenu">
                <span class="label"><i class="bi bi-file-earmark-text"></i><span>Avtaler &amp; kontrakter</span></span>
                <i class="bi <?= $contractsOpen ? 'bi-chevron-up' : 'bi-chevron-down' ?> chevron"></i>
            </button>

            <div id="contractsMenu" class="contracts-collapse <?= $contractsOpen ? 'show' : '' ?>">
                <a href="/?page=contracts"
                   class="nav-link nav-sub <?= $currentPage === 'contracts' ? 'active' : '' ?>">
                    <i class="bi bi-journal-text"></i><span>Oversikt</span>
                </a>

                <a href="/?page=contracts_new"
                   class="nav-link nav-sub <?= $currentPage === 'contracts_new' ? 'active' : '' ?>">
                    <i class="bi bi-plus-circle"></i><span>Ny avtale</span>
                </a>

                <a href="/?page=contracts_alerts"
                   class="nav-link nav-sub <?= $currentPage === 'contracts_alerts' ? 'active' : '' ?>">
                    <i class="bi bi-bell"></i><span>Varsler &amp; fornyelser</span>
                </a>

                <a href="/?page=contracts_settings"
                   class="nav-link nav-sub <?= $currentPage === 'contracts_settings' ? 'active' : '' ?>">
                    <i class="bi bi-gear"></i><span>Innstillinger</span>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($canNetwork): ?>
            <hr class="my-2" style="opacity:.25;">

            <button class="btn-sidebar-toggle" type="button" id="netverkToggle"
                    aria-expanded="<?= $networkOpen ? 'true' : 'false' ?>" aria-controls="netverkMenu">
                <span class="label"><i class="bi bi-hdd-network"></i><span>Nettverk</span></span>
                <i class="bi <?= $networkOpen ? 'bi-chevron-up' : 'bi-chevron-down' ?> chevron"></i>
            </button>

            <div id="netverkMenu" class="netverk-collapse <?= $networkOpen ? 'show' : '' ?>">
                <a href="/?page=access_routers" class="nav-link nav-sub <?= $currentPage === 'access_routers' ? 'active' : '' ?>">
                    <i class="bi bi-hdd-network"></i><span>Aksess-rutere</span>
                </a>
                <a href="/?page=edge_routers" class="nav-link nav-sub <?= $currentPage === 'edge_routers' ? 'active' : '' ?>">
                    <i class="bi bi-diagram-2"></i><span>Edge-rutere</span>
                </a>
                <a href="/?page=service_routers" class="nav-link nav-sub <?= $currentPage === 'service_routers' ? 'active' : '' ?>">
                    <i class="bi bi-diagram-3"></i><span>Service-rutere</span>
                </a>
                <a href="/?page=nni_customers" class="nav-link nav-sub <?= $currentPage === 'nni_customers' ? 'active' : '' ?>">
                    <i class="bi bi-plug"></i><span>NNI-sluttkunder</span>
                </a>
                <a href="/?page=customer_l2vpn_circuits" class="nav-link nav-sub <?= $currentPage === 'customer_l2vpn_circuits' ? 'active' : '' ?>">
                    <i class="bi bi-plug-fill"></i><span>L2VPN-kundekonfig</span>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($canFeltobjekter): ?>
            <hr class="my-2" style="opacity:.25;">

            <button class="btn-sidebar-toggle" type="button" id="feltToggle"
                    aria-expanded="<?= $feltOpen ? 'true' : 'false' ?>" aria-controls="feltMenu">
                <span class="label"><i class="bi bi-pin-map"></i><span>Feltobjekter</span></span>
                <i class="bi <?= $feltOpen ? 'bi-chevron-up' : 'bi-chevron-down' ?> chevron"></i>
            </button>

            <div id="feltMenu" class="felt-collapse <?= $feltOpen ? 'show' : '' ?>">
                <a href="/?page=node_locations"
                   class="nav-link nav-sub <?= in_array($currentPage, ['node_locations','node_location_view','node_location_edit'], true) ? 'active' : '' ?>">
                    <i class="bi bi-geo-alt"></i><span>Feltobjekter</span>
                </a>

                <a href="/?page=node_location_templates"
                   class="nav-link nav-sub <?= in_array($currentPage, ['node_location_templates','node_location_template_edit'], true) ? 'active' : '' ?>">
                    <i class="bi bi-ui-checks-grid"></i><span>Maler (feltobjekt)</span>
                </a>

                <!-- ✅ Bildekart -->
                <a href="/?page=bildekart"
                   class="nav-link nav-sub <?= $currentPage === 'bildekart' ? 'active' : '' ?>">
                    <i class="bi bi-images"></i><span>Bildekart</span>
                </a>

                <!-- ✅ Under Bildekart: Last opp bilder (uassosierte) -->
                <a href="/?page=image_upload"
                   class="nav-link nav-sub nav-sub-2 <?= $currentPage === 'image_upload' ? 'active' : '' ?>">
                    <i class="bi bi-upload"></i><span>Last opp bilder</span>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($canGrossist && !empty($grossistVendors)): ?>
            <hr class="my-2" style="opacity:.25;">

            <button class="btn-sidebar-toggle" type="button" id="grossistToggle"
                    aria-expanded="<?= $grossistOpen ? 'true' : 'false' ?>" aria-controls="grossistMenu">
                <span class="label"><i class="bi bi-diagram-3"></i><span>Grossistaksess</span></span>
                <i class="bi <?= $grossistOpen ? 'bi-chevron-up' : 'bi-chevron-down' ?> chevron"></i>
            </button>

            <div id="grossistMenu" class="grossist-collapse <?= $grossistOpen ? 'show' : '' ?>">
                <a href="/?page=grossist"
                   class="nav-link nav-sub <?= ($currentPage === 'grossist' && $grossistVendor === '') ? 'active' : '' ?>">
                    <i class="bi bi-list-task"></i><span>Oversikt</span>
                </a>

                <?php foreach ($grossistVendors as $v): ?>
                    <?php
                    $slug = (string)($v['slug'] ?? '');
                    $name = (string)($v['name'] ?? $slug);
                    $isActiveVendor = ($currentPage === 'grossist' && $grossistVendor === $slug);
                    ?>
                    <a href="/?page=grossist&vendor=<?= urlencode($slug) ?>"
                       class="nav-link nav-sub <?= $isActiveVendor ? 'active' : '' ?>">
                        <i class="bi bi-building"></i>
                        <span><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($canLogistikk): ?>
            <hr class="my-2" style="opacity:.25;">

            <button class="btn-sidebar-toggle" type="button" id="logistikkToggle"
                    aria-expanded="<?= $logistikkOpen ? 'true' : 'false' ?>" aria-controls="logistikkMenu">
                <span class="label"><i class="bi bi-box-seam"></i><span>Logistikk &amp; varelager</span></span>
                <i class="bi <?= $logistikkOpen ? 'bi-chevron-up' : 'bi-chevron-down' ?> chevron"></i>
            </button>

            <div id="logistikkMenu" class="logistikk-collapse <?= $logistikkOpen ? 'show' : '' ?>">
                <a href="/?page=logistikk" class="nav-link nav-sub <?= $currentPage === 'logistikk' ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2"></i><span>Oversikt</span>
                </a>
                <a href="/?page=logistikk_products" class="nav-link nav-sub <?= $currentPage === 'logistikk_products' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam"></i><span>Varer</span>
                </a>
                <a href="/?page=logistikk_categories" class="nav-link nav-sub <?= $currentPage === 'logistikk_categories' ? 'active' : '' ?>">
                    <i class="bi bi-tags"></i><span>Kategorier</span>
                </a>

                <a href="/?page=logistikk_receipts" class="nav-link nav-sub <?= $currentPage === 'logistikk_receipts' ? 'active' : '' ?>">
                    <i class="bi bi-box-arrow-in-down"></i><span>Varemottak</span>
                </a>

                <a href="/?page=logistikk_storage_admin" class="nav-link nav-sub <?= $currentPage === 'logistikk_storage_admin' ? 'active' : '' ?>">
                    <i class="bi bi-geo-alt"></i><span>Lagerlokasjoner</span>
                </a>

                <a href="/?page=inv_reports" class="nav-link nav-sub <?= in_array($currentPage, ['inv_reports','logistikk_movements'], true) ? 'active' : '' ?>">
                    <i class="bi bi-graph-up"></i><span>Uttakrapport</span>
                </a>

                <a href="/?page=inv_out_shop" class="nav-link nav-sub <?= $currentPage === 'inv_out_shop' ? 'active' : '' ?>">
                    <i class="bi bi-cart-check"></i><span>Vareuttak</span>
                </a>

                <a href="/?page=projects_admin" class="nav-link nav-sub <?= $currentPage === 'projects_admin' ? 'active' : '' ?>">
                    <i class="bi bi-kanban"></i><span>Prosjekter &amp; arbeidsordrer</span>
                </a>

                <hr class="my-2" style="opacity:.25; margin-left:1rem; margin-right:1rem;">

                <a href="/?page=crm_accounts" class="nav-link nav-sub <?= $currentPage === 'crm_accounts' ? 'active' : '' ?>">
                    <i class="bi bi-building"></i><span>Kunder &amp; partnere</span>
                </a>

                <a href="/?page=billing_invoice_new" class="nav-link nav-sub <?= $currentPage === 'billing_invoice_new' ? 'active' : '' ?>">
                    <i class="bi bi-receipt"></i><span>Nytt fakturagrunnlag</span>
                </a>

                <a href="/?page=billing_invoices" class="nav-link nav-sub <?= $currentPage === 'billing_invoices' ? 'active' : '' ?>">
                    <i class="bi bi-archive"></i><span>Fakturaarkiv</span>
                </a>
            </div>
        <?php endif; ?>
    </nav>

    <div class="app-sidebar-footer">
        <button id="userMenuToggle" type="button" class="user-menu-toggle"
                aria-expanded="false" aria-controls="userMenu">
            <?php if ($avatarUrlSafe !== ''): ?>
                <span class="user-avatar me-2">
                    <img src="<?= $avatarUrlSafe ?>" alt="Avatar">
                </span>
            <?php else: ?>
                <span class="user-avatar-fallback bg-dark text-white me-2" aria-hidden="true" style="font-size:.8rem;">
                    <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                </span>
            <?php endif; ?>

            <div class="d-flex flex-column flex-grow-1" style="min-width:0;">
                <span class="fw-semibold text-truncate"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <i class="bi bi-chevron-down chevron"></i>
        </button>

        <div id="userMenu" class="user-menu-collapse">
            <div class="user-menu-actions">
                <a href="/?page=minside"><i class="bi bi-person"></i><span>Min side</span></a>
                <a class="danger" href="/?page=logout"><i class="bi bi-box-arrow-right"></i><span>Logg ut</span></a>
            </div>
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

            var icon = btn.querySelector('.chevron');
            if (icon) {
                icon.classList.toggle('bi-chevron-up', isShown);
                icon.classList.toggle('bi-chevron-down', !isShown);
            }
        });
    }

    setupToggle('adminToggle', 'adminMenu');
    setupToggle('kpiToggle', 'kpiMenu');
    setupToggle('contractsToggle', 'contractsMenu');
    setupToggle('netverkToggle', 'netverkMenu');
    setupToggle('feltToggle', 'feltMenu');
    setupToggle('grossistToggle', 'grossistMenu');
    setupToggle('logistikkToggle', 'logistikkMenu');
    setupToggle('eventsToggle', 'eventsMenu');

    (function setupUserMenu() {
        var btn  = document.getElementById('userMenuToggle');
        var menu = document.getElementById('userMenu');
        if (!btn || !menu) return;

        function setOpen(open) {
            menu.classList.toggle('show', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');

            var icon = btn.querySelector('.chevron');
            if (icon) {
                icon.classList.toggle('bi-chevron-up', open);
                icon.classList.toggle('bi-chevron-down', !open);
            }
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            e.preventDefault();
            setOpen(!menu.classList.contains('show'));
        });

        document.addEventListener('click', function () {
            setOpen(false);
        });

        menu.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    })();
});
</script>

<header class="app-topbar">
    <div class="d-flex flex-column">
        <span class="fw-semibold"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="small" style="opacity:.8;">Teknisk administrasjon</span>
    </div>

    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-light text-primary d-none d-md-inline">
            <?= $isAdmin ? 'Admin' : 'Bruker' ?>
        </span>

        <a href="/?page=minside" class="btn btn-sm btn-outline-light d-flex align-items-center">
            <i class="bi bi-person me-1"></i> Min side
        </a>
    </div>
</header>

<main class="app-content container-fluid py-3">