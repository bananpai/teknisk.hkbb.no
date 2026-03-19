<?php

use App\Database;

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
        Du har ikke tilgang til L2VPN-kundekonfigurasjon.
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
            Du har ikke tilgang til L2VPN-kundekonfigurasjon.
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
            Du har ikke tilgang til L2VPN-kundekonfigurasjon.
        </div>
        <?php
        return;
    }

} catch (\Throwable $e) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til L2VPN-kundekonfigurasjon.
    </div>
    <?php
    return;
}


// ---------------------------------------------------------
// Hjelpefunksjoner for konfig-generering
// ---------------------------------------------------------

/**
 * Bygg en fornuftig beskrivelse:
 *  1) Bruk c.description hvis satt
 *  2) Ellers "Vendor Samband - Kundenavn" hvis vi har data
 *
 * Eksempel:
 *  BKK C100M-2382 - SpBankVest Stord
 */
function l2vpn_build_description(array $circuit): string
{
    $desc     = trim($circuit['description'] ?? '');
    $vendor   = trim($circuit['vendor_name'] ?? '');
    $samband  = trim($circuit['sambandsnr'] ?? '');
    $customer = trim($circuit['customer_name'] ?? '');

    if ($desc !== '') {
        // Manuelt satt beskrivelse vinner alltid
        return $desc;
    }

    // Bygg opp av deler
    $main = trim(($vendor !== '' ? $vendor . ' ' : '') . $samband); // "BKK C100M-2382"

    if ($main === '' && $customer === '') {
        return '';
    }

    if ($customer !== '') {
        // "BKK C100M-2382 - SpBankVest Stord"
        return $main !== '' ? "{$main} - {$customer}" : $customer;
    }

    return $main;
}

/**
 * SR-kundeconfig (Cisco XR) – basert på customer_l2vpn_circuits + grossist_service_router_vendor_ports.
 *
 * SR-interface skal være:
 *   <SR-vendor-port>.<NNI-VLAN>
 *
 * der:
 *   - SR-vendor-port hentes fra grossist_service_router_vendor_ports.port_name
 *     for (service_router_id = c.sr_id, vendor_id = c.vendor_id)
 *   - NNI-VLAN = sr_vlan_core
 *
 * Hvis vi ikke finner vendor-port, faller vi tilbake til sr_subif_name-logikken.
 */
function renderSrCustomerConfig(array $circuit): string
{
    $vlan    = (int)($circuit['sr_vlan_core'] ?? 0);   // f.eks. 2281
    $mtu     = (int)($circuit['mtu'] ?? 2052);
    $descStr = l2vpn_build_description($circuit);
    $desc    = $descStr !== '' ? "### {$descStr} ###" : '';
    $xcGroup = $circuit['pw_group_name'] ?? '';        // XC-HK-BKK-2281
    $p2pName = $circuit['pw_p2p_name'] ?? '';          // P2P-HK-BKK-2281
    $remoteIp = $circuit['pw_remote_ip'] ?? '';        // 10.x.x.x
    $pwId    = (int)($circuit['pw_id'] ?? 0);          // 32281
    $pwClass = $circuit['pw_class'] ?? '';             // PWC_HK-L2UNI-ETH

    // 1) Prøv å bruke porten mot grossist (fra grossist_service_router_vendor_ports)
    $baseIf = trim($circuit['sr_vendor_port'] ?? '');

    // 2) Hvis det ikke finnes, fall tilbake til sr_subif_name-logikk
    if ($baseIf === '') {
        $baseIf = trim($circuit['sr_subif_name'] ?? '');
    }

    // 3) Bygg subinterface-navn:
    //    - Hvis base allerede har ".", antar vi at det er komplett subif (Bundle-Ether69.2281)
    //    - Hvis ikke, og vi har VLAN, bygg <base>.<vlan>
    $ifSub = $baseIf;
    if ($baseIf !== '' && strpos($baseIf, '.') === false && $vlan > 0) {
        $ifSub = $baseIf . '.' . $vlan;
    }

    return <<<CFG
interface {$ifSub} l2transport
 description {$desc}
 encapsulation dot1q {$vlan}
 mtu {$mtu}

l2vpn xconnect group {$xcGroup}
l2vpn xconnect group {$xcGroup} p2p {$p2pName}
l2vpn xconnect group {$xcGroup} p2p {$p2pName} interface {$ifSub}
l2vpn xconnect group {$xcGroup} p2p {$p2pName} neighbor ipv4 {$remoteIp} pw-id {$pwId}
l2vpn xconnect group {$xcGroup} p2p {$p2pName} neighbor ipv4 {$remoteIp} pw-id {$pwId} pw-class {$pwClass}

CFG;
}

/**
 * ER-kundeconfig (Cisco XR) – basert på customer_l2vpn_circuits.
 *
 * Ny modell:
 *  - er_bundle_if i DB er "hoved-bundle" (f.eks. 223 eller Bundle-Ether223)
 *  - er_vlan_access er access-VLAN mot AR
 *  => reelt subif for config = <Bundle-EtherX>.<er_vlan_access>
 */
function renderErCustomerConfig(array $circuit): string
{
    $ifBaseRaw  = trim($circuit['er_bundle_if'] ?? '');      // f.eks. "223" eller "Bundle-Ether223"
    $vlanAccess = (int)($circuit['er_vlan_access'] ?? 0);    // f.eks. 3721
    $vlanCore   = (int)($circuit['er_vlan_core'] ?? 0);      // f.eks. 2281
    $mtu        = (int)($circuit['mtu'] ?? 2052);
    $descStr    = l2vpn_build_description($circuit);
    $desc       = $descStr !== '' ? "### {$descStr} ###" : '';
    $xcGroup    = $circuit['pw_group_name'] ?? '';
    $p2pName    = $circuit['pw_p2p_name'] ?? '';
    $remoteIp   = $circuit['pw_remote_ip'] ?? '';
    $pwId       = (int)($circuit['pw_id'] ?? 0);
    $pwClass    = $circuit['pw_class'] ?? '';

    // Normaliser er_bundle_if til Bundle-EtherX hvis bare tall / id
    $ifBase = $ifBaseRaw;
    if ($ifBase !== '') {
        $lower = strtolower($ifBase);
        if (ctype_digit($ifBase)) {
            $ifBase = 'Bundle-Ether' . $ifBase;
        } elseif (strpos($lower, 'bundle-ether') === false && strpos($ifBase, '/') === false) {
            $ifBase = 'Bundle-Ether' . $ifBase;
        }
    }

    // Bygg subinterface-navn: Bundle-EtherX.Y
    $ifSub = $ifBase;
    if ($ifBase !== '' && strpos($ifBase, '.') === false && $vlanAccess > 0) {
        $ifSub = $ifBase . '.' . $vlanAccess;
    }

    return <<<CFG
interface {$ifSub} l2transport
 description {$desc}
 encapsulation dot1q {$vlanAccess}
 rewrite ingress tag translate 1-to-1 dot1q {$vlanCore} symmetric
 mtu {$mtu}

l2vpn xconnect group {$xcGroup}
l2vpn xconnect group {$xcGroup} p2p {$p2pName}
l2vpn xconnect group {$xcGroup} p2p {$p2pName} interface {$ifSub}
l2vpn xconnect group {$xcGroup} p2p {$p2pName} neighbor ipv4 {$remoteIp} pw-id {$pwId}
l2vpn xconnect group {$xcGroup} p2p {$p2pName} neighbor ipv4 {$remoteIp} pw-id {$pwId} pw-class {$pwClass}

CFG;
}

/**
 * AR-kundeconfig (Cisco) – basert på customer_l2vpn_circuits.
 * (speiler det gamle systemet: service-VLAN + 3620/3621)
 */
function renderArConfigCisco(array $circuit): string
{
    $svcVlan   = (int)($circuit['ar_vlan_service'] ?? 0); // f.eks. 3710 / 3910
    $mgmtVlan  = (int)($circuit['ar_vlan_mgmt']  ?? 3620);
    $mgmtVlan2 = (int)($circuit['ar_vlan_mgmt2'] ?? 3621);

    $ifName  = $circuit['ar_if_name'] ?? '';              // f.eks. GigabitEthernet5/46
    $descStr = l2vpn_build_description($circuit);
    $desc    = $descStr !== '' ? "### {$descStr} ###" : '';
    $samband = $circuit['sambandsnr'] ?? '';
    $mtu     = (int)($circuit['mtu'] ?? 2052);

    $allowedVlans = [];
    $allowedVlans[] = $mgmtVlan;
    if ($mgmtVlan2) {
        $allowedVlans[] = $mgmtVlan2;
    }
    if ($svcVlan) {
        $allowedVlans[] = $svcVlan;
    }
    $allowed = implode(',', $allowedVlans);

    return <<<CFG
vlan {$svcVlan}
 name {$samband}
!
vlan {$mgmtVlan}
 name INTENO-INIT
!
vlan {$mgmtVlan2}
 name INTENO-MGMT
!
interface {$ifName}
 description {$desc}
 switchport trunk native vlan {$mgmtVlan}
 switchport trunk allowed vlan {$allowed}
 switchport mode trunk
 mtu {$mtu}
 no snmp trap link-status
 storm-control action shutdown
 l2protocol-tunnel cdp
 l2protocol-tunnel stp
 l2protocol-tunnel vtp
 no keepalive
 no cdp enable
!
CFG;
}

/**
 * AR-kundeconfig (Huawei) – matcher prinsippene fra gamle systemet,
 * men bruker eksplisitte service-port-id-er fra databasen.
 */
function renderArConfigHuawei(array $circuit): string
{
    $samband  = $circuit['sambandsnr'] ?? '';
    $descStr  = l2vpn_build_description($circuit);
    $desc     = $descStr !== '' ? "### {$descStr} ###" : '';
    $userPort = $circuit['ar_huawei_user_port'] ?? '';       // "0/4/43"
    $uplink   = $circuit['ar_huawei_uplink_slotport'] ?? ''; // "0/8 0"

    $vlanSvc  = (int)($circuit['ar_vlan_service'] ?? 0);   // 3714 / 3910
    $vlanInit = (int)($circuit['ar_vlan_mgmt']  ?? 3620);
    $vlanMgmt = (int)($circuit['ar_vlan_mgmt2'] ?? 3621);

    $spSvc  = (int)($circuit['ar_sp_service'] ?? $vlanSvc);
    $spInit = (int)($circuit['ar_sp_init']    ?? 0);
    $spMgmt = (int)($circuit['ar_sp_mgmt']    ?? 0);

    $serviceProfileId   = 100;
    $serviceProfileName = 'L2-TRANSPARENT';

    $cfg = [];

    $cfg[] = 'conf';
    $cfg[] = 'btv';

    if ($spSvc > 0) {
        $cfg[] = "igmp user delete service-port {$spSvc}";
        $cfg[] = '';
        $cfg[] = 'y';
        $cfg[] = '';
    }

    $cfg[] = 'quit';

    if ($spInit > 0) {
        $cfg[] = "undo service-port {$spInit}";
    }
    if ($spSvc > 0) {
        $cfg[] = "undo service-port {$spSvc}";
    }
    if ($spMgmt > 0) {
        $cfg[] = "undo service-port {$spMgmt}";
    }

    $cfg[] = '';
    $cfg[] = "vlan {$vlanSvc} smart";
    $cfg[] = "vlan name {$vlanSvc} \"{$samband}\"";
    $cfg[] = "vlan attrib {$vlanSvc} common";
    $cfg[] = "port vlan {$vlanSvc} {$uplink}";
    if ($spSvc > 0) {
        $cfg[] = "service-port {$spSvc} vlan {$vlanSvc} eth {$userPort} multi-service user-vlan {$vlanSvc} tag-transform translate";
    }

    $cfg[] = '';
    $cfg[] = "vlan {$vlanInit} smart";
    $cfg[] = "vlan name {$vlanInit} \"INTENO-INIT\"";
    $cfg[] = "vlan attrib {$vlanInit} common";
    $cfg[] = "port vlan {$vlanInit} {$uplink}";
    if ($spInit > 0) {
        $cfg[] = "service-port {$spInit} vlan {$vlanInit} eth {$userPort} multi-service user-vlan untagged tag-transform default";
    }

    $cfg[] = '';
    $cfg[] = "vlan {$vlanMgmt} smart";
    $cfg[] = "vlan name {$vlanMgmt} \"INTENO-MGMT\"";
    $cfg[] = "vlan attrib {$vlanMgmt} common";
    $cfg[] = "port vlan {$vlanMgmt} {$uplink}";
    if ($spMgmt > 0) {
        $cfg[] = "service-port {$spMgmt} vlan {$vlanMgmt} eth {$userPort} multi-service user-vlan {$vlanMgmt} tag-transform translate";
    }

    if ($spSvc > 0) {
        $cfg[] = '';
        $cfg[] = "security anti-ipspoofing service-port {$spSvc} disable";
    }
    if ($spInit > 0) {
        $cfg[] = "security anti-ipspoofing service-port {$spInit} disable";
    }
    if ($spMgmt > 0) {
        $cfg[] = "security anti-ipspoofing service-port {$spMgmt} disable";
    }

    $cfg[] = '';
    $cfg[] = "port desc {$userPort} description \"{$desc}\"";
    $cfg[] = '';
    $cfg[] = "vlan service-profile profile-id {$serviceProfileId} profile-name \"{$serviceProfileName}\"";
    $cfg[] = "bpdu tunnel enable";
    $cfg[] = "rip tunnel enable";
    $cfg[] = "vtp-cdp tunnel enable";
    $cfg[] = "dhcp option82 disable";
    $cfg[] = "dhcp proxy disable";
    $cfg[] = "security anti-ipspoofing disable";
    $cfg[] = "security anti-macspoofing disable";
    $cfg[] = "dhcpv6 option disable";
    $cfg[] = "security anti-ipv6spoofing disable";
    $cfg[] = "commit";
    $cfg[] = '';
    $cfg[] = "vlan bind service-profile {$vlanSvc} profile-id {$serviceProfileId}";

    return implode("\n", $cfg) . "\n";
}

/**
 * Velg AR-generator basert på plattform.
 */
function renderArConfig(array $circuit): string
{
    $platform = $circuit['ar_platform'] ?? 'cisco';

    if ($platform === 'huawei') {
        return renderArConfigHuawei($circuit);
    }

    return renderArConfigCisco($circuit);
}

// ---------------------------------------------------------
// Hent circuits + valgt circuit
// ---------------------------------------------------------
$pdo = Database::getConnection();

// Hent alle circuits for dropdown
$stmt = $pdo->query(
    'SELECT c.id, c.sambandsnr, c.description, c.status,
            v.name AS vendor_name
       FROM customer_l2vpn_circuits c
       JOIN grossist_vendors v ON v.id = c.vendor_id
      ORDER BY c.created_at DESC'
);
$circuitRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hent valgt circuit (hvis id er satt)
$selectedId      = isset($_GET['circuit_id']) ? (int)$_GET['circuit_id'] : 0;
$selectedCircuit = null;
$srName          = '';
$erName          = '';

if ($selectedId > 0) {
    $stmt = $pdo->prepare(
        'SELECT c.*,
                v.name  AS vendor_name,
                sr.sr_name AS sr_name,
                er.name AS er_name,
                gsvp.port_name AS sr_vendor_port,
                nc.customer_name AS customer_name
           FROM customer_l2vpn_circuits c
           JOIN grossist_vendors v          ON v.id  = c.vendor_id
           JOIN grossist_service_routers sr ON sr.id = c.sr_id
           JOIN edge_routers er             ON er.id = c.er_id
      LEFT JOIN grossist_service_router_vendor_ports gsvp
                 ON gsvp.service_router_id = c.sr_id
                AND gsvp.vendor_id        = c.vendor_id
      LEFT JOIN nni_customers nc
                 ON nc.vendor_id = c.vendor_id
                AND nc.circuit_id = c.sambandsnr
          WHERE c.id = :id'
    );
    $stmt->execute([':id' => $selectedId]);
    $selectedCircuit = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedCircuit) {
        $srName = $selectedCircuit['sr_name'];
        $erName = $selectedCircuit['er_name'];
    } else {
        $selectedId = 0;
    }
}

// Generer konfig hvis vi har et circuit
$srConfig = '';
$erConfig = '';
$arConfig = '';

if ($selectedCircuit) {
    $srConfig = renderSrCustomerConfig($selectedCircuit);
    $erConfig = renderErCustomerConfig($selectedCircuit);
    $arConfig = renderArConfig($selectedCircuit);
}
?>

<div class="mb-3">
    <h1 class="h4 mb-1">L2VPN-kundekonfig</h1>
    <p class="text-muted small mb-0">
        Velg et sambandsnummer for å generere konfigurasjon for Service Router (SR), Edge Router (ER)
        og Access Router (AR). Konfigen er basert på <code>customer_l2vpn_circuits</code>.
    </p>
</div>

<section class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="customer_l2vpn_circuits">

            <div class="col-12 col-md-6">
                <label for="circuit_id" class="form-label form-label-sm">Samband / kunde</label>
                <select
                    id="circuit_id"
                    name="circuit_id"
                    class="form-select form-select-sm"
                    required
                >
                    <option value="">Velg sambandsnummer...</option>
                    <?php foreach ($circuitRows as $row): ?>
                        <?php
                        $id   = (int)$row['id'];
                        $text = $row['sambandsnr'] . ' – ' . $row['vendor_name'];
                        ?>
                        <option
                            value="<?php echo $id; ?>"
                            <?php echo $id === $selectedId ? 'selected' : ''; ?>
                        >
                            <?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-3">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-gear-wide-connected me-1"></i> Vis konfig
                </button>
            </div>
        </form>
    </div>
</section>

<?php if ($selectedCircuit): ?>
    <section class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-2">
                <?php echo htmlspecialchars($selectedCircuit['sambandsnr'], ENT_QUOTES, 'UTF-8'); ?>
                <span class="text-muted">
                    – <?php echo htmlspecialchars($selectedCircuit['vendor_name'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </h2>
            <p class="small text-muted mb-2">
                SR: <strong><?php echo htmlspecialchars($srName, ENT_QUOTES, 'UTF-8'); ?></strong>,
                ER: <strong><?php echo htmlspecialchars($erName, ENT_QUOTES, 'UTF-8'); ?></strong>,
                AR-plattform:
                <strong><?php echo htmlspecialchars($selectedCircuit['ar_platform'] ?? 'cisco', ENT_QUOTES, 'UTF-8'); ?></strong>
            </p>

            <!-- Tabs – UI fra Bootstrap, logikk fra vår egen JS -->
            <ul class="nav nav-tabs nav-tabs-sm mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link active l2vpn-tab-btn"
                        data-l2vpn-tab="sr"
                        role="tab"
                    >
                        Service Router (SR)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link l2vpn-tab-btn"
                        data-l2vpn-tab="er"
                        role="tab"
                    >
                        Edge Router (ER)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link l2vpn-tab-btn"
                        data-l2vpn-tab="ar"
                        role="tab"
                    >
                        Access Router (AR)
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div
                    class="tab-pane l2vpn-tab-pane active"
                    data-l2vpn-pane="sr"
                    role="tabpanel"
                >
                    <label class="form-label form-label-sm">SR-konfig</label>
                    <textarea class="form-control form-control-sm" rows="16"
                              onclick="this.select();"><?php echo htmlspecialchars($srConfig, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div
                    class="tab-pane l2vpn-tab-pane"
                    data-l2vpn-pane="er"
                    role="tabpanel"
                    style="display:none;"
                >
                    <label class="form-label form-label-sm">ER-konfig</label>
                    <textarea class="form-control form-control-sm" rows="16"
                              onclick="this.select();"><?php echo htmlspecialchars($erConfig, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div
                    class="tab-pane l2vpn-tab-pane"
                    data-l2vpn-pane="ar"
                    role="tabpanel"
                    style="display:none;"
                >
                    <label class="form-label form-label-sm">
                        AR-konfig (<?php echo htmlspecialchars($selectedCircuit['ar_platform'] ?? 'cisco', ENT_QUOTES, 'UTF-8'); ?>)
                    </label>
                    <textarea class="form-control form-control-sm" rows="20"
                              onclick="this.select();"><?php echo htmlspecialchars($arConfig, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Enkel egen tab-logikk for L2VPN-panelet (uavhengig av Bootstrap JS)
    var tabButtons = document.querySelectorAll('.l2vpn-tab-btn');
    var panes      = document.querySelectorAll('.l2vpn-tab-pane');

    if (!tabButtons.length || !panes.length) return;

    function showTab(name) {
        panes.forEach(function (p) {
            var paneName = p.getAttribute('data-l2vpn-pane');
            if (paneName === name) {
                p.classList.add('active', 'show');
                p.style.display = '';
            } else {
                p.classList.remove('active', 'show');
                p.style.display = 'none';
            }
        });

        tabButtons.forEach(function (btn) {
            var btnName = btn.getAttribute('data-l2vpn-tab');
            if (btnName === name) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    }

    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var name = btn.getAttribute('data-l2vpn-tab');
            showTab(name);
        });
    });

    // Start alltid på SR-fanen
    showTab('sr');
});
</script>
