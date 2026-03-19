<?php
// public/pages/nni_customers.php
//
// Kombinert side for NNI-sluttkunder + L2VPN-konfig for samme samband.
// Kobling mellom tabellene skjer via:
//   nni_customers.circuit_id  <->  customer_l2vpn_circuits.sambandsnr

use App\Database;
use App\Network\CpeMgmtIpPool;

// ---------------------------------------------------------
// Tilgang: admin OR network (fra user_roles). Ingen hardkoding.
// NB: Dere har IKKE users.is_admin. Kun user_roles.
// Robust: trim + case-insensitivt username-oppslag.
// ---------------------------------------------------------
$username = trim((string)($_SESSION['username'] ?? ''));

if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til administrasjon av NNI-sluttkunder og L2VPN.
    </div>
    <?php
    return;
}

try {
    $pdo = Database::getConnection();

    // Finn current user_id (case-insensitiv match på username)
    $stmt = $pdo->prepare('
        SELECT id
          FROM users
         WHERE LOWER(TRIM(username)) = LOWER(TRIM(:u))
         LIMIT 1
    ');
    $stmt->execute([':u' => $username]);
    $currentUserId = (int)($stmt->fetchColumn() ?: 0);

    if ($currentUserId <= 0) {
        http_response_code(403);
        ?>
        <div class="alert alert-danger mt-3">
            Du har ikke tilgang til administrasjon av NNI-sluttkunder og L2VPN.
        </div>
        <?php
        return;
    }

    // Rolle-sjekk: admin eller network/nettverk
    $stmt = $pdo->prepare("
        SELECT 1
          FROM user_roles
         WHERE user_id = :uid
           AND LOWER(TRIM(role)) IN ('admin','network','nettverk')
         LIMIT 1
    ");
    $stmt->execute([':uid' => $currentUserId]);
    $hasAccess = (bool)$stmt->fetchColumn();

    if (!$hasAccess) {
        http_response_code(403);
        ?>
        <div class="alert alert-danger mt-3">
            Du har ikke tilgang til administrasjon av NNI-sluttkunder og L2VPN.
        </div>
        <?php
        return;
    }
} catch (\Throwable $e) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til administrasjon av NNI-sluttkunder og L2VPN.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// DB og hjelpeobjekter
// ---------------------------------------------------------
$ipPool  = new CpeMgmtIpPool($pdo);
$freeIps = $ipPool->getFreeIps(100);

$errors         = [];
$successMessage = null;

// Hvilken NNI-kunde redigerer vi?
$editId   = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$tab      = $_GET['tab'] ?? 'nni'; // 'nni' eller 'l2vpn'

// ---------------------------------------------------------
// Statisk liste over produkter og båndbredder (kan endres/utvides)
// ---------------------------------------------------------
$productOptions = [
    'Internettaksess',
    'L2VPN',
    'NNI',
    'MPLS',
    'IP-Transit',
    'Mobil backhaul',
];

$bandwidthOptions = [
    '10 Mbit/s',
    '20 Mbit/s',
    '50 Mbit/s',
    '100 Mbit/s',
    '200 Mbit/s',
    '500 Mbit/s',
    '1 Gbit/s',
    '2,5 Gbit/s',
    '10 Gbit/s',
];

// ---------------------------------------------------------
// Hjelpe-data: vendors, SR, ER, AR for dropdowns (L2VPN / NNI)
// ---------------------------------------------------------
$vendors        = [];
$serviceRouters = [];
$edgeRouters    = [];
$accessRouters  = [];

try {
    $stmt    = $pdo->query('SELECT id, name FROM grossist_vendors WHERE is_active = 1 ORDER BY sort_order, name');
    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $vendors = [];
}

try {
    $stmt           = $pdo->query('SELECT id, sr_name, sr_ip FROM grossist_service_routers ORDER BY sr_name');
    $serviceRouters = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $serviceRouters = [];
}

try {
    $stmt        = $pdo->query('SELECT id, name, mgmt_ip, edge_type FROM edge_routers ORDER BY name');
    $edgeRouters = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $edgeRouters = [];
}

try {
    // NB: inkluderer bundle_id og uplink_port nå
    $stmt          = $pdo->query('SELECT id, name, mgmt_ip, node_type, bundle_id, uplink_port FROM access_routers ORDER BY name');
    $accessRouters = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $accessRouters = [];
}

// Bygg mappinger for lookup (ikke kritisk, men greit å ha)
$vendorById     = [];
$srById         = [];
$erById         = [];
$arById         = [];
$arBundleIdById = [];

foreach ($vendors as $v) {
    $vendorById[(int)$v['id']] = $v;
}
foreach ($serviceRouters as $sr) {
    $srById[(int)$sr['id']] = $sr;
}
foreach ($edgeRouters as $er) {
    $erById[(int)$er['id']] = $er;
}
foreach ($accessRouters as $ar) {
    $id                    = (int)$ar['id'];
    $arById[$id]           = $ar;
    $arBundleIdById[$id]   = $ar['bundle_id'] ?? '';
}

// ---------------------------------------------------------
// POST-håndtering
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1) Lagre / oppdatere NNI-kunde (aksess)
    if ($action === 'save_nni_customer') {
        $id            = (int)($_POST['id'] ?? 0);
        $customerName  = trim($_POST['customer_name'] ?? '');
        $customerRef   = trim($_POST['customer_ref'] ?? '');
        $product       = trim($_POST['product'] ?? '');
        $circuitId     = trim($_POST['circuit_id'] ?? '');
        $streetAddress = trim($_POST['street_address'] ?? '');
        $postalCode    = trim($_POST['postal_code'] ?? '');
        $postalCity    = trim($_POST['postal_city'] ?? '');
        $vendorId      = (int)($_POST['vendor_id'] ?? 0);

        $serviceRouterId = (int)($_POST['service_router_id'] ?? 0);
        $edgeRouterId    = (int)($_POST['edge_router_id'] ?? 0);
        $accessRouterId  = (int)($_POST['access_router_id'] ?? 0);

        $accessSlot   = trim($_POST['access_slot'] ?? '');
        $accessPort   = trim($_POST['access_port'] ?? '');
        $cpeMac       = trim($_POST['cpe_mac'] ?? '');
        $cpeType      = trim($_POST['cpe_type'] ?? '');
        $nniVlan      = ($_POST['nni_vlan'] ?? '') !== '' ? (int)$_POST['nni_vlan'] : null;
        $cVlan        = ($_POST['c_vlan'] ?? '') !== '' ? (int)$_POST['c_vlan'] : null;
        $bandwidth    = trim($_POST['bandwidth'] ?? '');
        $description  = trim($_POST['description'] ?? '');

        $statusInstall    = !empty($_POST['status_install']) ? 1 : 0;
        $statusConfigured = !empty($_POST['status_configured']) ? 1 : 0;
        $isActive         = !empty($_POST['is_active']) ? 1 : 0;

        // Enkel validering
        if ($customerName === '') {
            $errors[] = 'Kundenavn må fylles ut.';
        }
        if ($vendorId <= 0) {
            $errors[] = 'Grossist-leverandør må velges.';
        }
        if ($circuitId === '') {
            $errors[] = 'Sambandsnummer / circuit-id må fylles ut.';
        }

        if (empty($errors)) {
            try {
                if ($id > 0) {
                    // UPDATE
                    $stmt = $pdo->prepare(
                        'UPDATE nni_customers
                            SET customer_name      = :customer_name,
                                customer_ref       = :customer_ref,
                                product            = :product,
                                circuit_id         = :circuit_id,
                                street_address     = :street_address,
                                postal_code        = :postal_code,
                                postal_city        = :postal_city,
                                vendor_id          = :vendor_id,
                                service_router_id  = :service_router_id,
                                edge_router_id     = :edge_router_id,
                                access_router_id   = :access_router_id,
                                access_slot        = :access_slot,
                                access_port        = :access_port,
                                cpe_mac            = :cpe_mac,
                                cpe_type           = :cpe_type,
                                nni_vlan           = :nni_vlan,
                                c_vlan             = :c_vlan,
                                bandwidth          = :bandwidth,
                                description        = :description,
                                status_install     = :status_install,
                                status_configured  = :status_configured,
                                is_active          = :is_active
                          WHERE id = :id'
                    );
                    $stmt->execute([
                        ':customer_name'     => $customerName,
                        ':customer_ref'      => $customerRef,
                        ':product'           => $product,
                        ':circuit_id'        => $circuitId,
                        ':street_address'    => $streetAddress,
                        ':postal_code'       => $postalCode,
                        ':postal_city'       => $postalCity,
                        ':vendor_id'         => $vendorId,
                        ':service_router_id' => $serviceRouterId ?: null,
                        ':edge_router_id'    => $edgeRouterId ?: null,
                        ':access_router_id'  => $accessRouterId ?: null,
                        ':access_slot'       => $accessSlot ?: null,
                        ':access_port'       => $accessPort ?: null,
                        ':cpe_mac'           => $cpeMac ?: null,
                        ':cpe_type'          => $cpeType ?: null,
                        ':nni_vlan'          => $nniVlan,
                        ':c_vlan'            => $cVlan,
                        ':bandwidth'         => $bandwidth ?: null,
                        ':description'       => $description ?: null,
                        ':status_install'    => $statusInstall,
                        ':status_configured' => $statusConfigured,
                        ':is_active'         => $isActive,
                        ':id'                => $id,
                    ]);

                    $successMessage = 'NNI-sluttkunden ble oppdatert.';
                    $editId         = $id;
                } else {
                    // INSERT
                    $stmt = $pdo->prepare(
                        'INSERT INTO nni_customers
                            (customer_name, customer_ref, product, circuit_id,
                             street_address, postal_code, postal_city,
                             vendor_id, service_router_id, edge_router_id, access_router_id,
                             access_slot, access_port, cpe_mac, cpe_type,
                             nni_vlan, c_vlan, bandwidth, description,
                             status_install, status_configured, is_active)
                         VALUES
                            (:customer_name, :customer_ref, :product, :circuit_id,
                             :street_address, :postal_code, :postal_city,
                             :vendor_id, :service_router_id, :edge_router_id, :access_router_id,
                             :access_slot, :access_port, :cpe_mac, :cpe_type,
                             :nni_vlan, :c_vlan, :bandwidth, :description,
                             :status_install, :status_configured, :is_active)'
                    );
                    $stmt->execute([
                        ':customer_name'     => $customerName,
                        ':customer_ref'      => $customerRef,
                        ':product'           => $product,
                        ':circuit_id'        => $circuitId,
                        ':street_address'    => $streetAddress,
                        ':postal_code'       => $postalCode,
                        ':postal_city'       => $postalCity,
                        ':vendor_id'         => $vendorId,
                        ':service_router_id' => $serviceRouterId ?: null,
                        ':edge_router_id'    => $edgeRouterId ?: null,
                        ':access_router_id'  => $accessRouterId ?: null,
                        ':access_slot'       => $accessSlot ?: null,
                        ':access_port'       => $accessPort ?: null,
                        ':cpe_mac'           => $cpeMac ?: null,
                        ':cpe_type'          => $cpeType ?: null,
                        ':nni_vlan'          => $nniVlan,
                        ':c_vlan'            => $cVlan,
                        ':bandwidth'         => $bandwidth ?: null,
                        ':description'       => $description ?: null,
                        ':status_install'    => $statusInstall,
                        ':status_configured' => $statusConfigured,
                        ':is_active'         => $isActive,
                    ]);

                    $newId          = (int)$pdo->lastInsertId();
                    $successMessage = 'NNI-sluttkunde ble opprettet.';
                    $editId         = $newId;
                }

                // Etter lagring holder vi oss på NNI-fanen
                $tab = 'nni';
            } catch (\Throwable $e) {
                $errors[] = 'Klarte ikke å lagre NNI-sluttkunde i databasen.';
                // $errors[] = $e->getMessage();
            }
        }
    }

    // 2) Lagre / oppdatere L2VPN-konfig for valgt NNI-kunde
    if ($action === 'save_l2vpn' && !empty($_POST['circuit_id'])) {
        $nniCircuitId = trim($_POST['circuit_id']);
        $l2Id         = (int)($_POST['l2vpn_id'] ?? 0);

        $vendorId   = (int)($_POST['l2_vendor_id'] ?? 0);
        $srId       = (int)($_POST['l2_sr_id'] ?? 0);
        $erId       = (int)($_POST['l2_er_id'] ?? 0);
        $arId       = (int)($_POST['l2_ar_id'] ?? 0);

        $arPlatform       = $_POST['ar_platform'] ?? 'cisco';
        $arIfName         = trim($_POST['ar_if_name'] ?? '');
        $arHuaweiUserPort = trim($_POST['ar_huawei_user_port'] ?? '');
        $arHuaweiUplink   = trim($_POST['ar_huawei_uplink_slotport'] ?? '');
        $arVlanService    = (int)($_POST['ar_vlan_service'] ?? 0);

        // Trygg håndtering av evt. manglende felter (for å unngå warnings)
        $raw               = $_POST['ar_vlan_mgmt'] ?? '';
        $arVlanMgmt        = $raw !== '' ? (int)$raw : null;
        $raw               = $_POST['ar_vlan_mgmt2'] ?? '';
        $arVlanMgmt2       = $raw !== '' ? (int)$raw : null;
        $raw               = $_POST['ar_sp_service'] ?? '';
        $arSpService       = $raw !== '' ? (int)$raw : null;
        $raw               = $_POST['ar_sp_init'] ?? '';
        $arSpInit          = $raw !== '' ? (int)$raw : null;
        $raw               = $_POST['ar_sp_mgmt'] ?? '';
        $arSpMgmt          = $raw !== '' ? (int)$raw : null;

        $erBundleIf   = trim($_POST['er_bundle_if'] ?? '');
        $erVlanAccess = (int)($_POST['er_vlan_access'] ?? 0);
        $erVlanCore   = (int)($_POST['er_vlan_core'] ?? 0);

        $srSubifName = trim($_POST['sr_subif_name'] ?? '');
        $srVlanCore  = (int)($_POST['sr_vlan_core'] ?? 0);

        $pwGroupName = trim($_POST['pw_group_name'] ?? '');
        $pwP2pName   = trim($_POST['pw_p2p_name'] ?? '');
        $pwRemoteIp  = trim($_POST['pw_remote_ip'] ?? '');
        $pwId        = (int)($_POST['pw_id'] ?? 0);
        $pwClass     = trim($_POST['pw_class'] ?? '');
        $mtu         = (int)($_POST['mtu'] ?? 2052);
        $descr       = trim($_POST['l2_description'] ?? '');
        $status      = $_POST['l2_status'] ?? 'active';

        if ($vendorId <= 0) {
            $errors[] = 'L2VPN: Grossist-leverandør må velges.';
        }
        if ($srId <= 0 || $erId <= 0 || $arId <= 0) {
            $errors[] = 'L2VPN: SR, ER og AR må settes.';
        }
        if ($arIfName === '' && $arPlatform === 'cisco') {
            $errors[] = 'L2VPN: AR-grensesnitt (ar_if_name) må fylles ut.';
        }
        if ($srSubifName === '') {
            $errors[] = 'L2VPN: SR subinterface-navn må fylles ut.';
        }
        if ($erBundleIf === '') {
            $errors[] = 'L2VPN: ER bundle/if må fylles ut.';
        }
        if ($pwRemoteIp === '' || $pwId <= 0) {
            $errors[] = 'L2VPN: PW remote IP og PW-ID må fylles ut.';
        }

        if (empty($errors)) {
            try {
                if ($l2Id > 0) {
                    // UPDATE
                    $stmt = $pdo->prepare(
                        'UPDATE customer_l2vpn_circuits
                            SET sambandsnr              = :sambandsnr,
                                vendor_id               = :vendor_id,
                                sr_id                   = :sr_id,
                                er_id                   = :er_id,
                                ar_id                   = :ar_id,
                                ar_platform             = :ar_platform,
                                ar_if_name              = :ar_if_name,
                                ar_huawei_user_port     = :ar_huawei_user_port,
                                ar_huawei_uplink_slotport = :ar_huawei_uplink_slotport,
                                ar_vlan_service         = :ar_vlan_service,
                                ar_vlan_mgmt            = :ar_vlan_mgmt,
                                ar_vlan_mgmt2           = :ar_vlan_mgmt2,
                                ar_sp_service           = :ar_sp_service,
                                ar_sp_init              = :ar_sp_init,
                                ar_sp_mgmt              = :ar_sp_mgmt,
                                er_bundle_if            = :er_bundle_if,
                                er_vlan_access          = :er_vlan_access,
                                er_vlan_core            = :er_vlan_core,
                                sr_subif_name           = :sr_subif_name,
                                sr_vlan_core            = :sr_vlan_core,
                                pw_group_name           = :pw_group_name,
                                pw_p2p_name             = :pw_p2p_name,
                                pw_remote_ip            = :pw_remote_ip,
                                pw_id                   = :pw_id,
                                pw_class                = :pw_class,
                                mtu                     = :mtu,
                                description             = :description,
                                status                  = :status
                          WHERE id = :id'
                    );
                    $stmt->execute([
                        ':sambandsnr'             => $nniCircuitId,
                        ':vendor_id'              => $vendorId,
                        ':sr_id'                  => $srId,
                        ':er_id'                  => $erId,
                        ':ar_id'                  => $arId,
                        ':ar_platform'            => $arPlatform,
                        ':ar_if_name'             => $arIfName,
                        ':ar_huawei_user_port'    => $arHuaweiUserPort ?: null,
                        ':ar_huawei_uplink_slotport' => $arHuaweiUplink ?: null,
                        ':ar_vlan_service'        => $arVlanService,
                        ':ar_vlan_mgmt'           => $arVlanMgmt,
                        ':ar_vlan_mgmt2'          => $arVlanMgmt2,
                        ':ar_sp_service'          => $arSpService,
                        ':ar_sp_init'             => $arSpInit,
                        ':ar_sp_mgmt'             => $arSpMgmt,
                        ':er_bundle_if'           => $erBundleIf,
                        ':er_vlan_access'         => $erVlanAccess,
                        ':er_vlan_core'           => $erVlanCore,
                        ':sr_subif_name'          => $srSubifName,
                        ':sr_vlan_core'           => $srVlanCore,
                        ':pw_group_name'          => $pwGroupName,
                        ':pw_p2p_name'            => $pwP2pName,
                        ':pw_remote_ip'           => $pwRemoteIp,
                        ':pw_id'                  => $pwId,
                        ':pw_class'               => $pwClass,
                        ':mtu'                    => $mtu,
                        ':description'            => $descr,
                        ':status'                 => $status,
                        ':id'                     => $l2Id,
                    ]);
                    $successMessage = 'L2VPN-konfig ble oppdatert.';
                } else {
                    // INSERT
                    $stmt = $pdo->prepare(
                        'INSERT INTO customer_l2vpn_circuits
                            (sambandsnr, vendor_id, sr_id, er_id, ar_id,
                             ar_platform, ar_if_name, ar_huawei_user_port, ar_huawei_uplink_slotport,
                             ar_vlan_service, ar_vlan_mgmt, ar_vlan_mgmt2,
                             ar_sp_service, ar_sp_init, ar_sp_mgmt,
                             er_bundle_if, er_vlan_access, er_vlan_core,
                             sr_subif_name, sr_vlan_core,
                             pw_group_name, pw_p2p_name, pw_remote_ip, pw_id, pw_class,
                             mtu, description, status)
                         VALUES
                            (:sambandsnr, :vendor_id, :sr_id, :er_id, :ar_id,
                             :ar_platform, :ar_if_name, :ar_huawei_user_port, :ar_huawei_uplink_slotport,
                             :ar_vlan_service, :ar_vlan_mgmt, :ar_vlan_mgmt2,
                             :ar_sp_service, :ar_sp_init, :ar_sp_mgmt,
                             :er_bundle_if, :er_vlan_access, :er_vlan_core,
                             :sr_subif_name, :sr_vlan_core,
                             :pw_group_name, :pw_p2p_name, :pw_remote_ip, :pw_id, :pw_class,
                             :mtu, :description, :status)'
                    );
                    $stmt->execute([
                        ':sambandsnr'             => $nniCircuitId,
                        ':vendor_id'              => $vendorId,
                        ':sr_id'                  => $srId,
                        ':er_id'                  => $erId,
                        ':ar_id'                  => $arId,
                        ':ar_platform'            => $arPlatform,
                        ':ar_if_name'             => $arIfName,
                        ':ar_huawei_user_port'    => $arHuaweiUserPort ?: null,
                        ':ar_huawei_uplink_slotport' => $arHuaweiUplink ?: null,
                        ':ar_vlan_service'        => $arVlanService,
                        ':ar_vlan_mgmt'           => $arVlanMgmt,
                        ':ar_vlan_mgmt2'          => $arVlanMgmt2,
                        ':ar_sp_service'          => $arSpService,
                        ':ar_sp_init'             => $arSpInit,
                        ':ar_sp_mgmt'             => $arSpMgmt,
                        ':er_bundle_if'           => $erBundleIf,
                        ':er_vlan_access'         => $erVlanAccess,
                        ':er_vlan_core'           => $erVlanCore,
                        ':sr_subif_name'          => $srSubifName,
                        ':sr_vlan_core'           => $srVlanCore,
                        ':pw_group_name'          => $pwGroupName,
                        ':pw_p2p_name'            => $pwP2pName,
                        ':pw_remote_ip'           => $pwRemoteIp,
                        ':pw_id'                  => $pwId,
                        ':pw_class'               => $pwClass,
                        ':mtu'                    => $mtu,
                        ':description'            => $descr,
                        ':status'                 => $status,
                    ]);
                    $successMessage = 'L2VPN-konfig ble opprettet.';
                }

                // Etter L2VPN-lagring holder vi oss på L2VPN-fanen
                $tab = 'l2vpn';
            } catch (\Throwable $e) {
                $errors[] = 'Klarte ikke å lagre L2VPN-konfig i databasen.';
                // $errors[] = $e->getMessage();
            }
        } else {
            $tab = 'l2vpn';
        }
    }
}

// ---------------------------------------------------------
// Hent alle NNI-kunder (liste)
// ---------------------------------------------------------
$stmt = $pdo->query(
    'SELECT nc.*, gv.name AS vendor_name
       FROM nni_customers nc
       JOIN grossist_vendors gv ON gv.id = nc.vendor_id
      ORDER BY nc.created_at DESC'
);
$nniCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// VLAN i bruk per grossist (til forslag)
$usedNniVlansByVendor = [];
$usedCVlansByVendor   = [];

foreach ($nniCustomers as $c) {
    $vid = (int)$c['vendor_id'];
    if (!is_null($c['nni_vlan'])) {
        $usedNniVlansByVendor[$vid][] = (int)$c['nni_vlan'];
    }
    if (!is_null($c['c_vlan'])) {
        $usedCVlansByVendor[$vid][] = (int)$c['c_vlan'];
    }
}

// ---------------------------------------------------------
// Hent valgt NNI-kunde + ev. L2VPN-circuit
// ---------------------------------------------------------
$editCustomer = null;
$l2vpnRow     = null;

if ($editId > 0) {
    foreach ($nniCustomers as $c) {
        if ((int)$c['id'] === $editId) {
            $editCustomer = $c;
            break;
        }
    }

    if ($editCustomer && !empty($editCustomer['circuit_id'])) {
        $stmt = $pdo->prepare(
            'SELECT *
               FROM customer_l2vpn_circuits
              WHERE sambandsnr = :sambandsnr
              LIMIT 1'
        );
        $stmt->execute([':sambandsnr' => $editCustomer['circuit_id']]);
        $l2vpnRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// Skal NNI-skjemaet (legg til / rediger aksess) vises?
$showNniForm = false;
if ($editCustomer || (($_POST['action'] ?? '') === 'save_nni_customer')) {
    $showNniForm = true;
}
?>

<div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">NNI-sluttkunder &amp; L2VPN</h1>
        <p class="text-muted small mb-0">
            Sluttkunder på grossist-NNI og tilhørende L2VPN-konfig for sambandet.
            NNI-kunde og L2VPN bindes sammen via sambandsnummer/circuit-id.
        </p>
    </div>

    <!-- Knapp som åpner/lukker NNI-skjemaet -->
    <button type="button" class="btn btn-sm btn-primary" id="toggleNniForm">
        <i class="bi bi-plus-circle me-1"></i> Legg til ny aksess
    </button>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger small">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success small">
        <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<section class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
            <h2 class="h6 mb-0">NNI-sluttkunder</h2>
            <div class="d-flex gap-2">
                <input
                    type="text"
                    id="nniSearch"
                    class="form-control form-control-sm"
                    style="max-width:260px;"
                    placeholder="Søk i navn, circuit, grossist..."
                >
            </div>
        </div>

        <?php if (empty($nniCustomers)): ?>
            <p class="text-muted small mb-0">Ingen NNI-sluttkunder er registrert ennå.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="nniTable">
                    <thead>
                        <tr>
                            <th>Kunde</th>
                            <th>Grossist</th>
                            <th>Produkt</th>
                            <th>Circuit / Samband</th>
                            <th>NNI-VLAN</th>
                            <th>C-VLAN</th>
                            <th>Status</th>
                            <th>Opprettet</th>
                            <th style="width:1%;white-space:nowrap;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($nniCustomers as $c): ?>
                        <?php
                        $id         = (int)$c['id'];
                        $hasCircuit = !empty($c['circuit_id']);
                        ?>
                        <tr class="nni-row">
                            <td><?= htmlspecialchars($c['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($c['vendor_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($c['product'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($hasCircuit): ?>
                                    <code><?= htmlspecialchars($c['circuit_id'], ENT_QUOTES, 'UTF-8') ?></code>
                                <?php else: ?>
                                    <span class="text-muted small">Ikke satt</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $c['nni_vlan'] !== null ? (int)$c['nni_vlan'] : '-' ?></td>
                            <td><?= $c['c_vlan']   !== null ? (int)$c['c_vlan']   : '-' ?></td>
                            <td>
                                <?php if (!empty($c['is_active'])): ?>
                                    <span class="badge text-bg-success">Aktiv</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inaktiv</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?= htmlspecialchars($c['created_at'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-nowrap">
                                <a
                                    href="/?page=nni_customers&edit_id=<?= $id ?>&tab=nni#edit-nni"
                                    class="btn btn-sm btn-outline-secondary py-0 px-2"
                                    title="Rediger NNI-kunde"
                                >
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                                <?php if ($hasCircuit): ?>
                                    <a
                                        href="/?page=nni_customers&edit_id=<?= $id ?>&tab=l2vpn#edit-l2vpn"
                                        class="btn btn-sm btn-outline-primary py-0 px-2"
                                        title="L2VPN for dette sambandet"
                                    >
                                        <i class="bi bi-diagram-3"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="card shadow-sm mb-3<?= $showNniForm ? '' : ' d-none' ?>" id="edit-nni">
    <div class="card-body">
        <h2 class="h6 mb-2">NNI-aksess (flere trinn)</h2>
        <p class="small text-muted mb-3">
            Legg til eller rediger NNI-aksess i flere steg. Videre steg er avhengige av informasjonen i steg 1.
        </p>

        <!-- En enkel “wizard”-indikator -->
        <div class="mb-3">
            <div class="small">
                <span id="nniStepLabel"><strong>Steg 1 av 3:</strong> Grunninfo</span>
            </div>
        </div>

        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_nni_customer">
            <input type="hidden" name="id" value="<?= $editCustomer ? (int)$editCustomer['id'] : 0 ?>">

            <!-- STEG 1: Kunde, grossist, produkt, circuit-id, adresse -->
            <div class="nni-step" data-step="1">
                <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm" for="customer_name">Kundenavn</label>
                    <input
                        type="text"
                        id="customer_name"
                        name="customer_name"
                        class="form-control form-control-sm"
                        required
                        value="<?= htmlspecialchars($_POST['customer_name'] ?? ($editCustomer['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm" for="customer_ref">Kundereferanse</label>
                    <input
                        type="text"
                        id="customer_ref"
                        name="customer_ref"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($_POST['customer_ref'] ?? ($editCustomer['customer_ref'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="product">Produkt</label>
                    <input
                        type="text"
                        id="product"
                        name="product"
                        class="form-control form-control-sm"
                        list="productList"
                        value="<?= htmlspecialchars($_POST['product'] ?? ($editCustomer['product'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <datalist id="productList">
                        <?php foreach ($productOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="circuit_id">Sambandsnummer / Circuit-ID</label>
                    <input
                        type="text"
                        id="circuit_id"
                        name="circuit_id"
                        class="form-control form-control-sm"
                        required
                        value="<?= htmlspecialchars($_POST['circuit_id'] ?? ($editCustomer['circuit_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="vendor_id">Grossist</label>
                    <select
                        id="vendor_id"
                        name="vendor_id"
                        class="form-select form-select-sm"
                        required
                    >
                        <option value="">Velg grossist...</option>
                        <?php
                        $currentVendorId = (int)($_POST['vendor_id'] ?? ($editCustomer['vendor_id'] ?? 0));
                        foreach ($vendors as $v):
                            $vid = (int)$v['id'];
                            ?>
                            <option value="<?= $vid ?>" <?= $vid === $currentVendorId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <hr class="my-2">
                    <h3 class="h6 mb-2">Adresse</h3>
                </div>

                <div class="col-12">
                    <label class="form-label form-label-sm" for="street_address">Gateadresse</label>
                    <input
                        type="text"
                        id="street_address"
                        name="street_address"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($_POST['street_address'] ?? ($editCustomer['street_address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label form-label-sm" for="postal_code">Postnummer</label>
                    <input
                        type="text"
                        id="postal_code"
                        name="postal_code"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($_POST['postal_code'] ?? ($editCustomer['postal_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label form-label-sm" for="postal_city">Poststed</label>
                    <input
                        type="text"
                        id="postal_city"
                        name="postal_city"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($_POST['postal_city'] ?? ($editCustomer['postal_city'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label form-label-sm" for="bandwidth">Båndbredde</label>
                    <input
                        type="text"
                        id="bandwidth"
                        name="bandwidth"
                        class="form-control form-control-sm"
                        list="bandwidthList"
                        value="<?= htmlspecialchars($_POST['bandwidth'] ?? ($editCustomer['bandwidth'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <datalist id="bandwidthList">
                        <?php foreach ($bandwidthOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="col-12 d-flex justify-content-end mt-2">
                    <button type="button" class="btn btn-primary btn-sm" id="nniNext1">
                        Neste steg
                    </button>
                </div>
            </div>

            <!-- STEG 2: VLAN + tilkobling + CPE -->
            <div class="nni-step d-none" data-step="2">
                <div class="col-12">
                    <h3 class="h6 mb-2">VLAN og tilkobling</h3>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label form-label-sm" for="nni_vlan">NNI VLAN</label>
                    <div class="input-group input-group-sm">
                        <input
                            type="number"
                            id="nni_vlan"
                            name="nni_vlan"
                            class="form-control"
                            min="1" max="4094"
                            value="<?= htmlspecialchars($_POST['nni_vlan'] ?? ($editCustomer['nni_vlan'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <button
                            class="btn btn-outline-secondary"
                            type="button"
                            id="suggestNniVlan"
                            title="Foreslå neste ledige VLAN for valgt grossist"
                        >
                            Foreslå
                        </button>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label form-label-sm" for="c_vlan">C-VLAN</label>
                    <div class="input-group input-group-sm">
                        <input
                            type="number"
                            id="c_vlan"
                            name="c_vlan"
                            class="form-control"
                            min="1" max="4094"
                            value="<?= htmlspecialchars($_POST['c_vlan'] ?? ($editCustomer['c_vlan'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <button
                            class="btn btn-outline-secondary"
                            type="button"
                            id="suggestCVlan"
                            title="Foreslå neste ledige C-VLAN for valgt grossist"
                        >
                            Foreslå
                        </button>
                    </div>
                </div>

                <div class="col-12">
                    <hr class="my-2">
                    <h3 class="h6 mb-2">Ruter-tilkobling</h3>
                </div>

                <?php
                $currSr = (int)($_POST['service_router_id'] ?? ($editCustomer['service_router_id'] ?? 0));
                $currEr = (int)($_POST['edge_router_id']    ?? ($editCustomer['edge_router_id'] ?? 0));
                $currAr = (int)($_POST['access_router_id']  ?? ($editCustomer['access_router_id'] ?? 0));
                ?>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="service_router_id">Service-router</label>
                    <input
                        type="text"
                        class="form-control form-control-sm mb-1 nni-filter"
                        data-target="service_router_id"
                        placeholder="Søk i service-rutere..."
                    >
                    <select
                        id="service_router_id"
                        name="service_router_id"
                        class="form-select form-select-sm"
                    >
                        <option value="">(valgfri)</option>
                        <?php foreach ($serviceRouters as $sr): ?>
                            <?php $id = (int)$sr['id']; ?>
                            <option value="<?= $id ?>" <?= $id === $currSr ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sr['sr_name'] . ' (' . $sr['sr_ip'] . ')', ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="edge_router_id">Edge-router</label>
                    <input
                        type="text"
                        class="form-control form-control-sm mb-1 nni-filter"
                        data-target="edge_router_id"
                        placeholder="Søk i edge-rutere..."
                    >
                    <select
                        id="edge_router_id"
                        name="edge_router_id"
                        class="form-select form-select-sm"
                    >
                        <option value="">(valgfri)</option>
                        <?php foreach ($edgeRouters as $er): ?>
                            <?php $id = (int)$er['id']; ?>
                            <option value="<?= $id ?>" <?= $id === $currEr ? 'selected' : '' ?>>
                                <?= htmlspecialchars($er['name'] . ' (' . $er['mgmt_ip'] . ')', ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="access_router_id">Access-router</label>
                    <input
                        type="text"
                        class="form-control form-control-sm mb-1 nni-filter"
                        data-target="access_router_id"
                        placeholder="Søk i access-rutere..."
                    >
                    <select
                        id="access_router_id"
                        name="access_router_id"
                        class="form-select form-select-sm"
                    >
                        <option value="">(valgfri)</option>
                        <?php foreach ($accessRouters as $ar): ?>
                            <?php $id = (int)$ar['id']; ?>
                            <option value="<?= $id ?>" <?= $id === $currAr ? 'selected' : '' ?>>
                                <?php
                                $label = $ar['name'] . ' (' . $ar['mgmt_ip'] . ')';
                                if (!empty($ar['bundle_id'])) {
                                    $label .= ' BE' . $ar['bundle_id'];
                                }
                                ?>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label form-label-sm" for="access_slot">Access-slot</label>
                    <input
                        type="text"
                        id="access_slot"
                        name="access_slot"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($_POST['access_slot'] ?? ($editCustomer['access_slot'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label form-label-sm" for="access_port">Access-port</label>
                    <input
                        type="text"
                        id="access_port"
                        name="access_port"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($_POST['access_port'] ?? ($editCustomer['access_port'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label form-label-sm" for="cpe_mac">CPE MAC</label>
                    <input
                        type="text"
                        id="cpe_mac"
                        name="cpe_mac"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($_POST['cpe_mac'] ?? ($editCustomer['cpe_mac'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label form-label-sm" for="cpe_type">CPE-type</label>
                    <input
                        type="text"
                        id="cpe_type"
                        name="cpe_type"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($_POST['cpe_type'] ?? ($editCustomer['cpe_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-12 d-flex justify-content-between mt-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="nniPrev2">
                        Tilbake
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" id="nniNext2">
                        Neste steg
                    </button>
                </div>
            </div>

            <!-- STEG 3: Kommentar + status + lagring -->
            <div class="nni-step d-none" data-step="3">
                <div class="col-12">
                    <h3 class="h6 mb-2">Kommentar og status</h3>
                </div>

                <div class="col-12">
                    <label class="form-label form-label-sm" for="description">Kommentar / beskrivelse</label>
                    <textarea
                        id="description"
                        name="description"
                        class="form-control form-control-sm"
                        rows="2"
                    ><?= htmlspecialchars($_POST['description'] ?? ($editCustomer['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <div class="col-12 col-md-4">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="status_install"
                            name="status_install"
                            value="1"
                            <?= !empty($_POST)
                                ? (!empty($_POST['status_install']) ? 'checked' : '')
                                : (!empty($editCustomer['status_install']) ? 'checked' : '')
                            ?>
                        >
                        <label class="form-check-label small" for="status_install">
                            Installert hos kunde
                        </label>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="status_configured"
                            name="status_configured"
                            value="1"
                            <?= !empty($_POST)
                                ? (!empty($_POST['status_configured']) ? 'checked' : '')
                                : (!empty($editCustomer['status_configured']) ? 'checked' : '')
                            ?>
                        >
                        <label class="form-check-label small" for="status_configured">
                            Ferdig konfigurert
                        </label>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="is_active"
                            name="is_active"
                            value="1"
                            <?= !empty($_POST)
                                ? (!empty($_POST['is_active']) ? 'checked' : '')
                                : (!empty($editCustomer['is_active'] ?? 1) ? 'checked' : '')
                            ?>
                        >
                        <label class="form-check-label small" for="is_active">
                            Aktiv
                        </label>
                    </div>
                </div>

                <div class="col-12 d-flex justify-content-between mt-2 gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="nniPrev3">
                        Tilbake
                    </button>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-save me-1"></i> Lagre NNI-kunde
                        </button>
                        <a href="/?page=nni_customers" class="btn btn-outline-secondary btn-sm">
                            Nullstill
                        </a>
                        <?php if ($editCustomer && !empty($editCustomer['circuit_id'])): ?>
                            <a href="/?page=nni_customers&edit_id=<?= (int)$editCustomer['id'] ?>&tab=l2vpn#edit-l2vpn"
                               class="btn btn-outline-primary btn-sm">
                                Gå til L2VPN for dette sambandet
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm mb-3" id="edit-l2vpn">
    <div class="card-body">
        <h2 class="h6 mb-2">L2VPN-konfig for dette sambandet</h2>
        <p class="small text-muted mb-2">
            Teknisk L2VPN-konfig mellom SR–ER–AR basert på samme sambandsnummer
            som NNI-kunden (circuit-id).
        </p>

        <?php if (!$editCustomer || empty($editCustomer['circuit_id'])): ?>
            <p class="small text-muted mb-0">
                Velg først en NNI-kunde og angi et <strong>circuit-id</strong>, så kan du legge inn L2VPN-konfig.
            </p>
        <?php else: ?>
            <?php
            // For utfylling: enten POST-verdier (ved valideringsfeil) eller eksisterende rad
            $l2 = $l2vpnRow ?: [];
            $postL2 = ($_POST['action'] ?? '') === 'save_l2vpn' ? $_POST : [];
            $val = function ($key, $default = '') use ($l2, $postL2) {
                if (!empty($postL2) && array_key_exists($key, $postL2)) {
                    return $postL2[$key];
                }
                return $l2[$key] ?? $default;
            };
            ?>

            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="save_l2vpn">
                <input type="hidden" name="l2vpn_id" value="<?= $l2vpnRow ? (int)$l2vpnRow['id'] : 0 ?>">
                <input type="hidden" name="circuit_id" value="<?= htmlspecialchars($editCustomer['circuit_id'], ENT_QUOTES, 'UTF-8') ?>">

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="l2_vendor_id">Grossist</label>
                    <select
                        id="l2_vendor_id"
                        name="l2_vendor_id"
                        class="form-select form-select-sm"
                        required
                    >
                        <option value="">Velg grossist...</option>
                        <?php
                        $currL2Vendor = (int)$val('vendor_id', $editCustomer['vendor_id']);
                        foreach ($vendors as $v):
                            $vid = (int)$v['id']; ?>
                            <option value="<?= $vid ?>" <?= $vid === $currL2Vendor ? 'selected' : '' ?>>
                                <?= htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php
                $currL2Sr = (int)$val('sr_id', $editCustomer['service_router_id'] ?? 0);
                $currL2Er = (int)$val('er_id', $editCustomer['edge_router_id']    ?? 0);
                $currL2Ar = (int)$val('ar_id', $editCustomer['access_router_id']  ?? 0);
                ?>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="l2_sr_id">Service-router (SR)</label>
                    <select
                        id="l2_sr_id"
                        name="l2_sr_id"
                        class="form-select form-select-sm"
                        required
                    >
                        <option value="">Velg SR...</option>
                        <?php foreach ($serviceRouters as $sr): ?>
                            <?php $id = (int)$sr['id']; ?>
                            <option value="<?= $id ?>" <?= $id === $currL2Sr ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sr['sr_name'] . ' (' . $sr['sr_ip'] . ')', ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="l2_er_id">Edge-router (ER)</label>
                    <select
                        id="l2_er_id"
                        name="l2_er_id"
                        class="form-select form-select-sm"
                        required
                    >
                        <option value="">Velg ER...</option>
                        <?php foreach ($edgeRouters as $er): ?>
                            <?php $id = (int)$er['id']; ?>
                            <option value="<?= $id ?>" <?= $id === $currL2Er ? 'selected' : '' ?>>
                                <?= htmlspecialchars($er['name'] . ' (' . $er['mgmt_ip'] . ')', ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="l2_ar_id">Access-router (AR)</label>
                    <select
                        id="l2_ar_id"
                        name="l2_ar_id"
                        class="form-select form-select-sm"
                        required
                    >
                        <option value="">Velg AR...</option>
                        <?php foreach ($accessRouters as $ar): ?>
                            <?php $id = (int)$ar['id']; ?>
                            <option value="<?= $id ?>" <?= $id === $currL2Ar ? 'selected' : '' ?>>
                                <?php
                                $label = $ar['name'] . ' (' . $ar['mgmt_ip'] . ')';
                                if (!empty($ar['bundle_id'])) {
                                    $label .= ' BE' . $ar['bundle_id'];
                                }
                                ?>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="ar_platform">AR-plattform</label>
                    <?php $currPlat = $val('ar_platform', 'cisco'); ?>
                    <select
                        id="ar_platform"
                        name="ar_platform"
                        class="form-select form-select-sm"
                    >
                        <option value="cisco"  <?= $currPlat === 'cisco'  ? 'selected' : '' ?>>Cisco (4500/9400)</option>
                        <option value="huawei" <?= $currPlat === 'huawei' ? 'selected' : '' ?>>Huawei MA5800</option>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="ar_if_name">AR if-name (CPE-port)</label>
                    <input
                        type="text"
                        id="ar_if_name"
                        name="ar_if_name"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($val('ar_if_name'), ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="F.eks. Gi1/0/1 eller Eth1/1"
                    >
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="ar_vlan_service">AR service-VLAN (C-VLAN)</label>
                    <input
                        type="number"
                        id="ar_vlan_service"
                        name="ar_vlan_service"
                        class="form-control form-control-sm"
                        min="1" max="4094"
                        value="<?= htmlspecialchars($val('ar_vlan_service', $editCustomer['c_vlan'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="er_bundle_if">ER bundle/if mot SR/AR</label>
                    <input
                        type="text"
                        id="er_bundle_if"
                        name="er_bundle_if"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($val('er_bundle_if'), ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="F.eks. Bundle-Ether10"
                    >
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm" for="er_vlan_access">ER access-VLAN (mot AR)</label>
                    <input
                        type="number"
                        id="er_vlan_access"
                        name="er_vlan_access"
                        class="form-control form-control-sm"
                        min="1" max="4094"
                        value="<?= htmlspecialchars($val('er_vlan_access', $editCustomer['c_vlan'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm" for="er_vlan_core">ER core-VLAN</label>
                    <input
                        type="number"
                        id="er_vlan_core"
                        name="er_vlan_core"
                        class="form-control form-control-sm"
                        min="1" max="4094"
                        value="<?= htmlspecialchars($val('er_vlan_core', $editCustomer['nni_vlan'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label form-label-sm" for="sr_subif_name">SR subinterface</label>
                    <input
                        type="text"
                        id="sr_subif_name"
                        name="sr_subif_name"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($val('sr_subif_name'), ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Auto: Bundle-Ether<bundle_id>.NNI-VLAN"
                    >
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm" for="sr_vlan_core">SR core-VLAN</label>
                    <input
                        type="number"
                        id="sr_vlan_core"
                        name="sr_vlan_core"
                        class="form-control form-control-sm"
                        min="1" max="4094"
                        value="<?= htmlspecialchars($val('sr_vlan_core', $editCustomer['nni_vlan'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-12">
                    <hr class="my-2">
                    <h3 class="h6 mb-2">Pseudowire / L2VPN</h3>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label form-label-sm" for="pw_group_name">PW group-name</label>
                    <input
                        type="text"
                        id="pw_group_name"
                        name="pw_group_name"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($val('pw_group_name'), ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="F.eks. GRS-1234"
                    >
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label form-label-sm" for="pw_p2p_name">PW p2p-name</label>
                    <input
                        type="text"
                        id="pw_p2p_name"
                        name="pw_p2p_name"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($val('pw_p2p_name'), ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="F.eks. L2VPN-KundeX"
                    >
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label form-label-sm" for="pw_remote_ip">PW remote IP</label>
                    <input
                        type="text"
                        id="pw_remote_ip"
                        name="pw_remote_ip"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($val('pw_remote_ip'), ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Loopback IP på motpart (ER/SR)"
                    >
                </div>

                <div class="col-6 col-md-1">
                    <label class="form-label form-label-sm" for="pw_id">PW-ID</label>
                    <input
                        type="number"
                        id="pw_id"
                        name="pw_id"
                        class="form-control form-control-sm"
                        min="1"
                        value="<?= htmlspecialchars($val('pw_id'), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm" for="pw_class">PW-class</label>
                    <input
                        type="text"
                        id="pw_class"
                        name="pw_class"
                        class="form-control form-control-sm"
                        value="<?= htmlspecialchars($val('pw_class'), ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="F.eks. DEFAULT-PW"
                    >
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm" for="mtu">MTU</label>
                    <input
                        type="number"
                        id="mtu"
                        name="mtu"
                        class="form-control form-control-sm"
                        min="1500" max="9216"
                        value="<?= htmlspecialchars($val('mtu', 2052), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm" for="l2_status">Status</label>
                    <?php $currStatus = $val('status', 'active'); ?>
                    <select
                        id="l2_status"
                        name="l2_status"
                        class="form-select form-select-sm"
                    >
                        <option value="planned"  <?= $currStatus === 'planned'  ? 'selected' : '' ?>>Planlagt</option>
                        <option value="active"   <?= $currStatus === 'active'   ? 'selected' : '' ?>>Aktiv</option>
                        <option value="disabled" <?= $currStatus === 'disabled' ? 'selected' : '' ?>>Deaktivert</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label form-label-sm" for="l2_description">Kommentar / beskrivelse</label>
                    <textarea
                        id="l2_description"
                        name="l2_description"
                        class="form-control form-control-sm"
                        rows="2"
                    ><?= htmlspecialchars($val('description'), ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i> Lagre L2VPN-konfig
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // -------------------------
    // Søk i NNI-tabell
    // -------------------------
    var input = document.getElementById('nniSearch');
    var table = document.getElementById('nniTable');
    if (input && table) {
        var rows = table.querySelectorAll('tbody tr.nni-row');
        input.addEventListener('input', function () {
            var q = input.value.toLowerCase();
            rows.forEach(function (row) {
                var text = row.textContent.toLowerCase();
                row.style.display = !q || text.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    // -------------------------
    // Toggle NNI-skjema ("Legg til ny aksess")
    // -------------------------
    var toggleNniBtn = document.getElementById('toggleNniForm');
    var nniCard      = document.getElementById('edit-nni');

    function resetNniWizardToStep1() {
        if (typeof window.NNIWizard !== 'undefined') {
            window.NNIWizard.showStep(1);
        }
    }

    if (toggleNniBtn && nniCard) {
        toggleNniBtn.addEventListener('click', function () {
            var wasHidden = nniCard.classList.contains('d-none');
            nniCard.classList.toggle('d-none');

            if (wasHidden) {
                resetNniWizardToStep1();
                nniCard.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        });
    }

    // -------------------------
    // Enkel wizard for NNI-skjema (3 steg)
    // -------------------------
    var steps   = Array.prototype.slice.call(document.querySelectorAll('#edit-nni .nni-step'));
    var labelEl = document.getElementById('nniStepLabel');
    var currentStep = 1;

    function updateStepLabel(step) {
        if (!labelEl) return;
        var text = '';
        if (step === 1) {
            text = 'Steg 1 av 3: Grunninfo';
        } else if (step === 2) {
            text = 'Steg 2 av 3: VLAN og tilkobling';
        } else if (step === 3) {
            text = 'Steg 3 av 3: Kommentar og status';
        }
        labelEl.innerHTML = '<strong>' + text + '</strong>';
    }

    function showStep(step) {
        currentStep = step;
        steps.forEach(function (s) {
            var sStep = parseInt(s.getAttribute('data-step'), 10);
            if (sStep === step) {
                s.classList.remove('d-none');
            } else {
                s.classList.add('d-none');
            }
        });
        updateStepLabel(step);
    }

    function validateStep1() {
        var customer = document.getElementById('customer_name');
        var vendor   = document.getElementById('vendor_id');
        var circuit  = document.getElementById('circuit_id');

        var missing = [];
        if (!customer.value.trim()) missing.push('Kundenavn');
        if (!vendor.value)          missing.push('Grossist');
        if (!circuit.value.trim())  missing.push('Circuit-ID');

        if (missing.length > 0) {
            alert('Følgende felt må fylles ut før du kan gå videre:\n- ' + missing.join('\n- '));
            return false;
        }
        return true;
    }

    function validateStep2() {
        return true;
    }

    window.NNIWizard = {
        showStep: showStep
    };

    if (steps.length > 0) {
        showStep(1);
    }

    var next1 = document.getElementById('nniNext1');
    var next2 = document.getElementById('nniNext2');
    var prev2 = document.getElementById('nniPrev2');
    var prev3 = document.getElementById('nniPrev3');

    if (next1) {
        next1.addEventListener('click', function () {
            if (!validateStep1()) return;
            showStep(2);
        });
    }
    if (next2) {
        next2.addEventListener('click', function () {
            if (!validateStep2()) return;
            showStep(3);
        });
    }
    if (prev2) {
        prev2.addEventListener('click', function () {
            showStep(1);
        });
    }
    if (prev3) {
        prev3.addEventListener('click', function () {
            showStep(2);
        });
    }

    var urlParams = new URLSearchParams(window.location.search);
    var tab = urlParams.get('tab');
    if (tab === 'l2vpn') {
        var l2 = document.getElementById('edit-l2vpn');
        if (l2) l2.scrollIntoView({behavior: 'smooth', block: 'start'});
    } else if (tab === 'nni') {
        if (nniCard) {
            nniCard.classList.remove('d-none');
        }
        showStep(1);
        if (nniCard) {
            nniCard.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
    }

    // -------------------------
    // Filter for SR/ER/AR-select (søk i dropdown)
    // -------------------------
    var filterInputs = document.querySelectorAll('#edit-nni .nni-filter');
    filterInputs.forEach(function (inp) {
        var targetId = inp.getAttribute('data-target');
        var select   = document.getElementById(targetId);
        if (!select) return;

        var options = Array.prototype.slice.call(select.options);

        inp.addEventListener('input', function () {
            var q = inp.value.toLowerCase();
            options.forEach(function (opt) {
                if (!q || opt.text.toLowerCase().indexOf(q) !== -1 || opt.value === '') {
                    opt.hidden = false;
                } else {
                    opt.hidden = true;
                }
            });
        });
    });

    // -------------------------
    // VLAN-forslag (per grossist)
    // -------------------------
    function suggestVlan(fieldId, usedMap) {
        var vendorSelect = document.getElementById('vendor_id');
        if (!vendorSelect || !vendorSelect.value) {
            alert('Velg grossist først.');
            return;
        }
        var vid  = vendorSelect.value;
        var used = usedMap[vid] || [];
        used = used.map(function (v) { return parseInt(v, 10); })
                   .filter(function (v) { return !isNaN(v); })
                   .sort(function (a, b) { return a - b; });

        var vlan = 100;
        while (used.indexOf(vlan) !== -1 && vlan <= 4094) {
            vlan++;
        }
        var field = document.getElementById(fieldId);
        if (field) {
            field.value = vlan;
        }
    }

    var btnSuggestNni = document.getElementById('suggestNniVlan');
    var btnSuggestC   = document.getElementById('suggestCVlan');

    if (btnSuggestNni) {
        btnSuggestNni.addEventListener('click', function () {
            suggestVlan('nni_vlan', window.USED_NNI_VLANS || {});
        });
    }
    if (btnSuggestC) {
        btnSuggestC.addEventListener('click', function () {
            suggestVlan('c_vlan', window.USED_C_VLANS || {});
        });
    }

    // -------------------------
    // Auto: SR subinterface = Bundle-Ether<bundle_id>.<NNI-VLAN>
    // og ER bundle-if = Bundle-Ether<bundle_id>
    // -------------------------
    var l2ArSelect      = document.getElementById('l2_ar_id');
    var erBundleInput   = document.getElementById('er_bundle_if');
    var srSubifInput    = document.getElementById('sr_subif_name');
    var nniVlanInput    = document.getElementById('nni_vlan');
    var srVlanCoreInput = document.getElementById('sr_vlan_core');
    var erVlanCoreInput = document.getElementById('er_vlan_core');

    function markAutoSubif(value) {
        if (!srSubifInput) return;
        srSubifInput.value = value;
        srSubifInput.dataset.autogenerated = '1';
    }

    function syncFromNniVlan() {
        if (!nniVlanInput) return;
        var v = nniVlanInput.value;
        if (!v) return;

        if (srVlanCoreInput && !srVlanCoreInput.value) {
            srVlanCoreInput.value = v;
        }
        if (erVlanCoreInput && !erVlanCoreInput.value) {
            erVlanCoreInput.value = v;
        }

        if (!l2ArSelect || !srSubifInput) return;
        var arId     = l2ArSelect.value;
        var bundleId = (window.AR_BUNDLE_ID || {})[arId];
        if (!bundleId) return;

        if (!srSubifInput.value || srSubifInput.dataset.autogenerated === '1') {
            markAutoSubif('Bundle-Ether' + bundleId + '.' + v);
        }
    }

    if (nniVlanInput) {
        nniVlanInput.addEventListener('input', syncFromNniVlan);
    }

    if (srSubifInput) {
        srSubifInput.addEventListener('input', function () {
            srSubifInput.dataset.autogenerated = '0';
        });
    }

    if (l2ArSelect && erBundleInput) {
        l2ArSelect.addEventListener('change', function () {
            var arId     = l2ArSelect.value;
            var bundleId = (window.AR_BUNDLE_ID || {})[arId];

            if (bundleId) {
                if (!erBundleInput.value) {
                    erBundleInput.value = 'Bundle-Ether' + bundleId;
                }

                var vlanVal = nniVlanInput ? nniVlanInput.value : '';
                if (srSubifInput && vlanVal && (!srSubifInput.value || srSubifInput.dataset.autogenerated === '1')) {
                    markAutoSubif('Bundle-Ether' + bundleId + '.' + vlanVal);
                }
            }
        });
    }

    if (l2ArSelect && l2ArSelect.value && nniVlanInput && nniVlanInput.value && srSubifInput && !srSubifInput.value) {
        var arIdInit = l2ArSelect.value;
        var bInit    = (window.AR_BUNDLE_ID || {})[arIdInit];
        if (bInit) {
            markAutoSubif('Bundle-Ether' + bInit + '.' + nniVlanInput.value);
        }
    }
});

// Disse settes av PHP under (per grossist: liste over brukte VLAN) + AR bundle-id map
window.USED_NNI_VLANS = <?= json_encode($usedNniVlansByVendor, JSON_UNESCAPED_UNICODE); ?>;
window.USED_C_VLANS   = <?= json_encode($usedCVlansByVendor,   JSON_UNESCAPED_UNICODE); ?>;
window.AR_BUNDLE_ID   = <?= json_encode($arBundleIdById,       JSON_UNESCAPED_UNICODE); ?>;
</script>
