<?php
// Path: /public/index.php

declare(strict_types=1);

session_start();
ob_start();

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Auth\TwoFaStorage;
use OTPHP\TOTP;

// ---------------------------------------------------------
// IP-sikkerhetsfilter (Allowlist) – tidlig guard
// ---------------------------------------------------------

if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
        try {
            $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('get_client_ip')) {
    function get_client_ip(): string {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        // Minimal "trygg" proxy-støtte: stol kun på XFF hvis request kommer fra localhost-proxy
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '' && ($remote === '127.0.0.1' || $remote === '::1')) {
            // Ta første IP i listen
            $parts = array_map('trim', explode(',', $xff));
            foreach ($parts as $p) {
                if (filter_var($p, FILTER_VALIDATE_IP)) {
                    return $p;
                }
            }
        }

        return $remote;
    }
}

if (!function_exists('ip_matches_rule')) {
    function ip_matches_rule(string $ip, string $rule): bool {
        $ip = trim($ip);
        $rule = trim($rule);

        if ($ip === '' || $rule === '') return false;

        // Eksakt match (ingen /)
        if (strpos($rule, '/') === false) {
            return $ip === $rule;
        }

        // CIDR
        [$subnet, $bits] = explode('/', $rule, 2);
        $subnet = trim($subnet);
        $bits = (int)trim($bits);

        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);

        if ($ipBin === false || $subBin === false) return false;
        if (strlen($ipBin) !== strlen($subBin)) return false;

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0) $bits = 0;
        if ($bits > $maxBits) $bits = $maxBits;

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($bytes > 0) {
            if (substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
                return false;
            }
        }

        if ($rem === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rem)) & 0xFF;
        $ipByte  = ord($ipBin[$bytes] ?? "\0");
        $subByte = ord($subBin[$bytes] ?? "\0");

        return (($ipByte & $mask) === ($subByte & $mask));
    }
}

if (!function_exists('security_ip_log')) {
    function security_ip_log(?PDO $pdo, array $data): void {
        try {
            if (!$pdo) {
                error_log('SECURITY_IP_DENY: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }

            if (!table_exists($pdo, 'security_ip_log')) {
                error_log('SECURITY_IP_DENY (no table): ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }

            $stmt = $pdo->prepare("
                INSERT INTO security_ip_log
                    (action, ip, username, request_uri, page, method, user_agent, created_at)
                VALUES
                    (:action, :ip, :username, :request_uri, :page, :method, :user_agent, NOW())
            ");

            $stmt->execute([
                ':action'      => (string)($data['action'] ?? 'deny'),
                ':ip'          => (string)($data['ip'] ?? ''),
                ':username'    => (string)($data['username'] ?? ''),
                ':request_uri' => (string)($data['request_uri'] ?? ''),
                ':page'        => (string)($data['page'] ?? ''),
                ':method'      => (string)($data['method'] ?? ''),
                ':user_agent'  => (string)($data['user_agent'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            error_log('SECURITY_IP_DENY (log failed): ' . $e->getMessage());
        }
    }
}

// Finn side tidlig (brukes i logg + evt exceptions senere)
$page = $_GET['page'] ?? '';

$clientIp   = get_client_ip();
$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
$method     = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$userAgent  = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

// Prøv å koble DB tidlig (gjenbrukes senere)
$pdo = null;
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    // DB nede -> IP-filter fail-open (ikke blokker hele appen pga DB)
    $pdo = null;
}

/**
 * Enforced hvis:
 * - security_ip_settings + security_ip_allowlist finnes
 * - enabled=1 (fra settings)
 * - allowlist har aktive regler
 */
$ipFilterEnabled = false;
$allowRules = [];

try {
    if ($pdo && table_exists($pdo, 'security_ip_settings') && table_exists($pdo, 'security_ip_allowlist')) {
        $enabled = (int)($pdo->query("SELECT enabled FROM security_ip_settings WHERE id=1")->fetchColumn() ?: 0);
        $ipFilterEnabled = ($enabled === 1);

        if ($ipFilterEnabled) {
            $stmt = $pdo->query("SELECT ip_rule FROM security_ip_allowlist WHERE is_active = 1 ORDER BY id ASC");
            $allowRules = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
            $allowRules = array_values(array_filter(array_map('trim', $allowRules)));
        }
    }
} catch (\Throwable $e) {
    // Fail-open
    $ipFilterEnabled = false;
    $allowRules = [];
}

if ($ipFilterEnabled && !empty($allowRules)) {
    $matched = false;
    foreach ($allowRules as $rule) {
        if (ip_matches_rule($clientIp, $rule)) {
            $matched = true;
            break;
        }
    }

    if (!$matched) {
        security_ip_log($pdo, [
            'action'      => 'deny',
            'ip'          => $clientIp,
            'username'    => (string)($_SESSION['username'] ?? ''),
            'request_uri' => $requestUri,
            'page'        => (string)$page,
            'method'      => $method,
            'user_agent'  => $userAgent,
        ]);

        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!doctype html>
        <html lang="no">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Ingen tilgang</title>
            <style>
                body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:2rem;background:#fff;color:#111}
                .box{max-width:720px;border:1px solid #ddd;border-radius:12px;padding:1rem 1.25rem}
                .muted{color:#666;font-size:.95rem}
                code{background:#f5f5f5;padding:.15rem .35rem;border-radius:6px}
            </style>
        </head>
        <body>
            <div class="box">
                <h1 style="margin:0 0 .5rem 0;">Tilgang blokkert</h1>
                <p style="margin:.25rem 0 0 0;">
                    Din IP-adresse er ikke tillatt for denne løsningen.
                </p>
                <p class="muted" style="margin:.75rem 0 0 0;">
                    IP: <code><?= htmlspecialchars($clientIp, ENT_QUOTES, 'UTF-8') ?></code>
                </p>
                <p class="muted" style="margin:.75rem 0 0 0;">
                    Kontakt administrator hvis dette er feil.
                </p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// ---------------------------------------------------------
// Routing: logout / auth guard
// ---------------------------------------------------------

// LOGOUT
if ($page === 'logout') {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    header('Location: /login/');
    exit;
}

// Må være AD-innlogget
if (empty($_SESSION['username'])) {
    header('Location: /login/');
    exit;
}

$username = $_SESSION['username'];
$fullname = $_SESSION['fullname'] ?? $username;

// DB-tilkobling (gjenbruk tidlig PDO hvis vi allerede har den)
if (!$pdo) {
    $pdo = Database::getConnection();
}

// ---------------------------------------------------------
// Sjekk om konto er aktiv
// ---------------------------------------------------------

$accountIsActive = true;

$stmt = $pdo->prepare('SELECT is_active FROM users WHERE username = :u LIMIT 1');
$stmt->execute([':u' => $username]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || !(int)$row['is_active']) {
    $accountIsActive = false;
}

if (!$accountIsActive) {
    // Kontoen er IKKE aktivert → vis enkel beskjed, ingen 2FA osv.
    $pageTitle       = 'Teknisk – konto ikke aktivert';
    $twoFaScreen     = 'none';
    $twoFaError      = null;
    $twoFaSecret     = null;
    $twoFaOtpauthUri = null;

    require __DIR__ . '/inc/header.php';
    require __DIR__ . '/inc/menu.php';
    ?>
    <section class="card shadow-sm">
        <div class="card-body">
            <h1 class="h5 mb-2">Konto ikke aktivert</h1>
            <p class="mb-2">
                Kontoen din er opprettet i Teknisk, men er foreløpig ikke aktivert.
            </p>
            <p class="mb-2 small text-muted">
                Ta kontakt med en administrator for å bli aktivert. Inntil da har du ikke tilgang til denne løsningen.
            </p>
            <a href="/?page=logout" class="btn btn-sm btn-outline-danger mt-2">
                <i class="bi bi-box-arrow-right me-1"></i> Logg ut
            </a>
        </div>
    </section>
    <?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

// ---------------------------------------------------------
// Konto er aktiv → 2FA og resten av appen kan kjøre
// ---------------------------------------------------------

$twoFaStorage = new TwoFaStorage($pdo);

// 2FA – grunnlag
$twoFaScreen     = 'none';    // 'none' | 'setup' | 'code'
$twoFaError      = null;
$twoFaOtpauthUri = null;
$twoFaSecret     = null;

// Har denne økten allerede en godkjent 2FA?
$twoFaVerifiedSession = !empty($_SESSION['twofa_verified']);

// Hent status fra DB
$dbTwoFa = $twoFaStorage->loadTwoFa($username); // ['enabled' => bool, 'secret' => ?string]

$twoFaEnabledAccount = !empty($dbTwoFa['enabled']);
$twoFaSecret         = $dbTwoFa['secret'] ?? null;

// legg secret i sesjonen også (kan være nyttig til feilsøking)
$_SESSION['twofa_secret'] = $twoFaSecret;

// Hvis kontoen ikke har aktiv 2FA i databasen → tving nytt oppsett
if (!$twoFaEnabledAccount) {
    $_SESSION['twofa_verified'] = false;
    $twoFaVerifiedSession       = false;
}

// ---------------------------------------------------------
// POST: 6-sifret kode fra 2FA-overlay
// ---------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page !== 'login' && isset($_POST['totp_code'])) {
    $code = $_POST['totp_code'] ?? '';
    $code = preg_replace('/\s+/', '', $code);

    if ($code === '') {
        $twoFaError = 'Du må skrive inn koden fra autentiserings-appen.';
    } elseif (empty($twoFaSecret)) {
        $twoFaError = 'Fant ikke 2FA-nøkkel. Last siden på nytt og prøv igjen.';
    } else {
        $totp = TOTP::create($twoFaSecret);
        $totp->setLabel('Teknisk (' . $username . ')');
        $totp->setIssuer('Teknisk');

        // Aksepter litt klokkedrift (±1 tidsvindu)
        if ($totp->verify($code, null, 1)) {
            $_SESSION['twofa_verified'] = true;
            $twoFaVerifiedSession       = true;

            if (!$twoFaEnabledAccount) {
                $twoFaStorage->saveTwoFa($username, true, $twoFaSecret);
                $twoFaEnabledAccount = true;
            }

            $twoFaError = null;
        } else {
            $twoFaError = 'Feil kode. Prøv igjen (sjekk også tid/klokke på mobilen).';
        }
    }
}

// Les sesjonsstatus på nytt
$twoFaVerifiedSession = !empty($_SESSION['twofa_verified']);

// ---------------------------------------------------------
// Bestem hvilket 2FA-vindu vi skal vise
// ---------------------------------------------------------

if ($twoFaVerifiedSession) {
    $twoFaScreen = 'none';
} else {
    if ($twoFaEnabledAccount && !empty($twoFaSecret)) {
        $twoFaScreen = 'code';
    } else {
        $twoFaScreen = 'setup';

        if (empty($twoFaSecret)) {
            $totp = TOTP::create();
            $totp->setLabel('Teknisk (' . $username . ')');
            $totp->setIssuer('Teknisk');

            $twoFaSecret              = $totp->getSecret();
            $_SESSION['twofa_secret'] = $twoFaSecret;

            $twoFaStorage->saveTwoFa($username, false, $twoFaSecret);
        } else {
            $totp = TOTP::create($twoFaSecret);
            $totp->setLabel('Teknisk (' . $username . ')');
            $totp->setIssuer('Teknisk');
        }

        $twoFaOtpauthUri = $totp->getProvisioningUri();
    }
}

// ---------------------------------------------------------
// ✅ Rettigheter (brukes til å stoppe uautoriserte pages tidlig)
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

// Roller/perms fra session + user_roles (DB)
$roles = normalize_list($_SESSION['roles'] ?? null);
$perms = normalize_list($_SESSION['permissions'] ?? null);

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $userId = (int)($stmt->fetchColumn() ?: 0);

    if ($userId > 0 && table_exists($pdo, 'user_roles')) {
        $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $dbRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $roles = array_merge($roles, normalize_list($dbRoles));
    }
} catch (\Throwable $e) {
    // fail-open: behold sessionverdier
}

$roles = array_values(array_unique(array_map('strtolower', $roles)));
$perms = array_values(array_unique(array_map('strtolower', $perms)));

$isAdmin = has_any(['admin'], $roles);

// ✅ Avtaler & kontrakter – tilgang (samme logikk som i menu.php)
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

// ✅ KPI/Mål – tilgang (ny modul)
$canKpi = $isAdmin
    || has_any(['report_admin','report_user','kpi','mål','maal','kpi_user','kpi_admin'], $roles)
    || has_any(['report_admin','report_user','kpi','mål','maal','kpi_user','kpi_admin'], $perms);

$canKpiAdmin = $isAdmin
    || has_any(['report_admin','kpi_admin'], $roles)
    || has_any(['report_admin','kpi_admin'], $perms);

// ✅ Hendelser / Endringer – tilgang
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
// Routing av sider
// ---------------------------------------------------------

if ($page === '' || $page === 'login') {
    $page = 'start';
}

// ✅ Avtale-sider samlet (brukes både for whitelist og access-check)
$contractsPages = [
    'contracts',
    'contracts_new',
    'contracts_view',
    'contracts_edit',
    'contracts_alerts',
    'contracts_settings',
];

// ✅ KPI/Mål-sider samlet (whitelist + access-check)
$kpiPages = [
    'report_kpi_dashboard',
    'report_kpi_entry',
    'report_kpi_admin',
];

// ✅ Hendelser / Endringer – sider (whitelist + access-check)  (FIX: events*)
$eventPages = [
    'events',
    'events_new',
    'events_view',
    'events_edit',
    'events_map',
    'events_dashboards',
];

// ✅ Konto/profil-sider (ingen ekstra rollekrav – kun innlogget + 2FA)
$accountPages = [
    'minside',
    'change_password',
];

// Tillatte sider
$allowedPages = [

    // Test
    'ldap_tls_test',

    'start',
    'minside',
    'change_password', // ✅ NY: tillat ny side

    // ✅ Hendelser / Endringer (FIX: events*)
    'events',
    'events_new',
    'events_view',
    'events_edit',
    'events_map',
    'events_dashboards',


    'users',
    'users_edit',

    // ✅ Admin → Sikkerhet
    'security',
    'security_ip_filter',
    'api_admin',

    // ✅ Avtaler & kontrakter
    'contracts',
    'contracts_new',
    'contracts_view',
    'contracts_edit',
    'contracts_alerts',
    'contracts_settings',

    // ✅ KPI / Mål (ny modul)
    'report_kpi_dashboard',
    'report_kpi_entry',
    'report_kpi_admin',

    'grossist',
    'grossist_config',
    'access_routers',
    'edge_routers',
    'service_routers',
    'nni_customers',
    'customer_circuit_config',
    'customer_l2vpn_circuits',

    //Objekter
    'node_locations',
    'node_location_view',
    'node_location_edit',
    'node_location_templates',
    'node_location_template_edit',
    'bildekart',
    'image_upload',


    // Logistikk
    'lager_users',
    'logistikk',
    'logistikk_products',
    'logistikk_categories',
    'logistikk_movements',
    'logistikk_receipts',
    'logistikk_storage_admin',
    'inv_reports',
    'inv_out_shop',
    'inv_stocktake',
    'projects_admin', // ✅ lager/arbeidsordre-prosjekter skal ligge her

    // CRM / faktura
    'crm_accounts',
    'billing_invoice_new',
    'billing_invoice_edit',
    'billing_invoice_print',
    'billing_invoices',
];

if (!in_array($page, $allowedPages, true)) {
    $page = 'start';
}

// ✅ Tilgangscheck: Hendelser / Endringer (FIX: events*)
if (in_array($page, $eventPages, true) && !$canIncidents) {
    $page = 'start';
}

// ✅ Tilgangscheck: Avtaler & kontrakter (send uautoriserte til start)
if (in_array($page, $contractsPages, true) && !$canContracts) {
    $page = 'start';
}

// ✅ Tilgangscheck: KPI/Mål (send uautoriserte til start)
if (in_array($page, $kpiPages, true) && !$canKpi) {
    $page = 'start';
}

// Ekstra: admin-siden i KPI krever report_admin/admin
if ($page === 'report_kpi_admin' && !$canKpiAdmin) {
    $page = 'start';
}

// (accountPages har ingen ekstra access-check – bare innlogging + 2FA håndteres senere)

// Side-tittel
switch ($page) {
    case 'minside':
        $pageTitle = 'Teknisk – Min side';
        break;

    case 'change_password':
        $pageTitle = 'Teknisk – Bytt passord';
        break;

    // ✅ Hendelser / Endringer (FIX: events*)
    case 'events':
        $pageTitle = 'Teknisk – Hendelser / Endringer';
        break;
    case 'events_new':
        $pageTitle = 'Teknisk – Ny hendelse / endring';
        break;
    case 'events_view':
        $pageTitle = 'Teknisk – Hendelse / endring (visning)';
        break;
    case 'events_edit':
        $pageTitle = 'Teknisk – Hendelse / endring (rediger)';
        break;

    case 'users':
        $pageTitle = 'Teknisk – Brukeradministrasjon';
        break;
    case 'users_edit':
        $pageTitle = 'Teknisk – Rediger bruker';
        break;

    case 'security':
    case 'security_ip_filter':
        $pageTitle = 'Teknisk – Sikkerhet';
        break;

    // ✅ Avtaler & kontrakter
    case 'contracts':
        $pageTitle = 'Teknisk – Avtaler & kontrakter';
        break;
    case 'contracts_new':
        $pageTitle = 'Teknisk – Ny avtale';
        break;
    case 'contracts_view':
        $pageTitle = 'Teknisk – Avtale (visning)';
        break;
    case 'contracts_edit':
        $pageTitle = 'Teknisk – Avtale (rediger)';
        break;
    case 'contracts_alerts':
        $pageTitle = 'Teknisk – Varsler & fornyelser';
        break;
    case 'contracts_settings':
        $pageTitle = 'Teknisk – Avtaler (innstillinger)';
        break;

    // ✅ KPI / Mål
    case 'report_kpi_dashboard':
        $pageTitle = 'Teknisk – Mål & KPI (dashboard)';
        break;
    case 'report_kpi_entry':
        $pageTitle = 'Teknisk – Mål & KPI (innrapportering)';
        break;
    case 'report_kpi_admin':
        $pageTitle = 'Teknisk – Mål & KPI (admin)';
        break;

    case 'grossist':
        $pageTitle = 'Teknisk – Grossistaksess';
        break;

    case 'access_routers':
        $pageTitle = 'Teknisk – Aksess-rutere';
        break;
    case 'edge_routers':
        $pageTitle = 'Teknisk – Edge-rutere';
        break;
    case 'service_routers':
        $pageTitle = 'Teknisk – Service-rutere';
        break;
    case 'nni_customers':
        $pageTitle = 'Teknisk – NNI-sluttkunder';
        break;

    // Logistikk
    case 'logistikk':
        $pageTitle = 'Teknisk – Logistikk';
        break;
    case 'logistikk_products':
        $pageTitle = 'Teknisk – Varer';
        break;
    case 'logistikk_categories':
        $pageTitle = 'Teknisk – Kategorier';
        break;
    case 'logistikk_receipts':
        $pageTitle = 'Teknisk – Vareleveringer';
        break;
    case 'logistikk_movements':
        $pageTitle = 'Teknisk – Bevegelser';
        break;
    case 'inv_reports':
        $pageTitle = 'Teknisk – Uttakrapport';
        break;
    case 'inv_out_shop':
        $pageTitle = 'Teknisk – Uttak / Flytt (butikk)';
        break;
    case 'projects_admin':
        $pageTitle = 'Teknisk – Prosjekter & arbeidsordrer';
        break;

    // CRM / faktura
    case 'crm_accounts':
        $pageTitle = 'Teknisk – Kunder & partnere';
        break;
    case 'billing_invoice_new':
        $pageTitle = 'Teknisk – Nytt fakturagrunnlag';
        break;
    case 'billing_invoice_edit':
        $pageTitle = 'Teknisk – Fakturagrunnlag';
        break;
    case 'billing_invoice_print':
        $pageTitle = 'Teknisk – Fakturagrunnlag (utskrift)';
        break;

    case 'start':
    default:
        $pageTitle = 'Teknisk – Oversikt';
        break;
}

$contentFile = __DIR__ . '/pages/' . $page . '.php';
if (!is_file($contentFile)) {
    $contentFile = __DIR__ . '/pages/start.php';
}

// gjør variabler tilgjengelig for header/menu/content
$loggedIn = true;

// ---------------------------------------------------------
// VIKTIG: Print-side bypasser vanlig layout
// (fordi billing_invoice_print.php er en full HTML-side)
// ---------------------------------------------------------
if ($page === 'billing_invoice_print') {
    if ($twoFaVerifiedSession) {
        require $contentFile;
    }
    exit;
}

require __DIR__ . '/inc/header.php';
require __DIR__ . '/inc/menu.php';

/**
 * Vis kun sideinnhold hvis 2FA er verifisert.
 * Hvis ikke, rendres kun layout + 2FA-overlay (som styres av $twoFaScreen).
 */
if ($twoFaVerifiedSession) {
    require $contentFile;
}

require __DIR__ . '/inc/footer.php';