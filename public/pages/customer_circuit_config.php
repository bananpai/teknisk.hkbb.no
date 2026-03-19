<?php
// public/pages/customer_circuit_config.php

use App\Database;

// ---------------------------------------------------------
// Admin-guard
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';
$isAdmin  = $_SESSION['is_admin'] ?? false;
if ($username === 'rsv') {
    $isAdmin = true;
}

if (!$isAdmin) {
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
 * SR-kundeconfig (Cisco) – basert på customer_l2vpn_circuits.
 */
function renderSrCustomerConfig(array $circuit): string
{
    $ifSub     = $circuit['sr_subif_name'];     // f.eks. Gi0/0/0/0.3040
    $vlan      = (int)$circuit['sr_vlan_core']; // f.eks. 2118 / 3040
    $mtu       = (int)$circuit['mtu'];
    $desc      = $circuit['description'];
    $xcGroup   = $circuit['pw_group_name'];     // XC-HK-BKK-2118
    $p2pName   = $circuit['pw_p2p_name'];       // P2P-HK-BKK-2118
    $remoteIp  = $circuit['pw_remote_ip'];      // 10.x.x.x
    $pwId      = (int)$circuit['pw_id'];        // 32118 / 33040
    $pwClass   = $circuit['pw_class'];          // PWC_HK-L2UNI-ETH

    return <<<CFG
interface {$ifSub} l2transport
 description ### {$desc} ###
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
 * ER-kundeconfig (Cisco) – basert på customer_l2vpn_circuits.
 */
function renderErCustomerConfig(array $circuit): string
{
    $ifSub      = $circuit['er_bundle_if'];          // f.eks. Bundle-Ether219.3910
    $vlanAccess = (int)$circuit['er_vlan_access'];   // f.eks. 3710 / 3910
    $vlanCore   = (int)$circuit['er_vlan_core'];     // f.eks. 2118 / 3040
    $mtu        = (int)$circuit['mtu'];
    $desc       = $circuit['description'];
    $xcGroup    = $circuit['pw_group_name'];
    $p2pName    = $circuit['pw_p2p_name'];
    $remoteIp   = $circuit['pw_remote_ip'];
    $pwId       = (int)$circuit['pw_id'];
    $pwClass    = $circuit['pw_class'];

    return <<<CFG
interface {$ifSub} l2transport
 description ### {$desc} ###
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
 *
 * Matcher stilen:
 *
 * vlan 3710
 *  name C101M-1894
 * ...
 * interface GigabitEthernet5/46
 *  description ###
 *  switchport trunk native vlan 3620
 *  switchport trunk allowed vlan 3620,3621,3710
 *  switchport mode trunk
 *  mtu 2052
 */
function renderArConfigCisco(array $circuit): string
{
    $svcVlan   = (int)$circuit['ar_vlan_service'];       // f.eks. 3710 / 3910
    $mgmtVlan  = (int)($circuit['ar_vlan_mgmt']  ?? 3620);
    $mgmtVlan2 = (int)($circuit['ar_vlan_mgmt2'] ?? 3621);

    $ifName    = $circuit['ar_if_name'];                 // f.eks. GigabitEthernet5/46
    $desc      = $circuit['description'];
    $samband   = $circuit['sambandsnr'];
    $mtu       = (int)$circuit['mtu'];

    // Allowed VLAN-list (mgmt, mgmt2, service)
    $allowedVlans = [];
    $allowedVlans[] = $mgmtVlan;
    if ($mgmtVlan2) {
        $allowedVlans[] = $mgmtVlan2;
    }
    $allowedVlans[] = $svcVlan;
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
 description ### {$desc} ###
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
 * AR-kundeconfig (Huawei) – matcher eksemplene dine.
 *
 * Forventer at følgende felter er satt i $circuit:
 *  - sambandsnr         (for vlan-navn / evt. del av beskrivelse)
 *  - description        (for port desc)
 *  - ar_vlan_service    (kunde-VLAN, f.eks. 3714 / 3910)
 *  - ar_vlan_mgmt       (3620)
 *  - ar_vlan_mgmt2      (3621)
 *  - ar_sp_service      (f.eks. 3714 / 3910)
 *  - ar_sp_init         (f.eks. 100443 / 100624)
 *  - ar_sp_mgmt         (f.eks. 130443 / 130624)
 *  - ar_huawei_user_port       (f.eks. "0/4/43")
 *  - ar_huawei_uplink_slotport (f.eks. "0/8 0")
 */
function renderArConfigHuawei(array $circuit): string
{
    $samband   = $circuit['sambandsnr'];              // f.eks. C101M-5701 / S-80034
    $desc      = $circuit['description'];            // f.eks. "BKK C101M-5701 - Dot-..."
    $userPort  = $circuit['ar_huawei_user_port'];    // "0/4/43"
    $uplink    = $circuit['ar_huawei_uplink_slotport']; // "0/8 0"

    $vlanSvc   = (int)$circuit['ar_vlan_service'];   // 3714 / 3910
    $vlanInit  = (int)($circuit['ar_vlan_mgmt']  ?? 3620);
    $vlanMgmt  = (int)($circuit['ar_vlan_mgmt2'] ?? 3621);

    $spSvc     = (int)($circuit['ar_sp_service'] ?? $vlanSvc);
    $spInit    = (int)($circuit['ar_sp_init']    ?? 0);
    $spMgmt    = (int)($circuit['ar_sp_mgmt']    ?? 0);

    // Enkel fallback for service-profile
    $serviceProfileId   = 100;
    $serviceProfileName = 'L2-TRANSPARENT';

    $config = <<<CFG
conf
btv
igmp user delete service-port {$spSvc}

y

quit

undo service-port {$spInit}
undo service-port {$spSvc}
undo service-port {$spMgmt}

vlan {$vlanSvc} smart
vlan name {$vlanSvc} "{$samband}"
vlan attrib {$vlanSvc} common
port vlan {$vlanSvc} {$uplink}
service-port {$spSvc} vlan {$vlanSvc} eth {$userPort} multi-service user-vlan {$vlanSvc} tag-transform translate

vlan {$vlanInit} smart
vlan name {$vlanInit} "INTENO-INIT"
vlan attrib {$vlanInit} common
port vlan {$vlanInit} {$uplink}
service-port {$spInit} vlan {$vlanInit} eth {$userPort} multi-service user-vlan untagged tag-transform default

vlan {$vlanMgmt} smart
vlan name {$vlanMgmt} "INTENO-MGMT"
vlan attrib {$vlanMgmt} common
port vlan {$vlanMgmt} {$uplink}
service-port {$spMgmt} vlan {$vlanMgmt} eth {$userPort} multi-service user-vlan {$vlanMgmt} tag-transform translate

security anti-ipspoofing service-port {$spSvc} disable
security anti-ipspoofing service-port {$spInit} disable
security anti-ipspoofing service-port {$spMgmt} disable

port desc {$userPort} description "### {$desc} ###"

vlan service-profile profile-id {$serviceProfileId} profile-name "{$serviceProfileName}"
bpdu tunnel enable
rip tunnel enable
vtp-cdp tunnel enable
dhcp option82 disable
dhcp proxy disable
security anti-ipspoofing disable
security anti-macspoofing disable
dhcpv6 option disable
security anti-ipv6spoofing disable
commit

vlan bind service-profile {$vlanSvc} profile-id {$serviceProfileId}

CFG;

    return $config;
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
                er.name AS er_name
           FROM customer_l2vpn_circuits c
           JOIN grossist_vendors v          ON v.id  = c.vendor_id
           JOIN grossist_service_routers sr ON sr.id = c.sr_id
           JOIN edge_routers er             ON er.id = c.er_id
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
            <input type="hidden" name="page" value="customer_circuit_config">

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

            <ul class="nav nav-tabs nav-tabs-sm mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-sr" data-bs-toggle="tab"
                            data-bs-target="#pane-sr" type="button" role="tab">
                        Service Router (SR)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-er" data-bs-toggle="tab"
                            data-bs-target="#pane-er" type="button" role="tab">
                        Edge Router (ER)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-ar" data-bs-toggle="tab"
                            data-bs-target="#pane-ar" type="button" role="tab">
                        Access Router (AR)
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="pane-sr" role="tabpanel">
                    <label class="form-label form-label-sm">SR-konfig</label>
                    <textarea class="form-control form-control-sm" rows="16"
                              onclick="this.select();"><?php echo htmlspecialchars($srConfig, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="tab-pane fade" id="pane-er" role="tabpanel">
                    <label class="form-label form-label-sm">ER-konfig</label>
                    <textarea class="form-control form-control-sm" rows="16"
                              onclick="this.select();"><?php echo htmlspecialchars($erConfig, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="tab-pane fade" id="pane-ar" role="tabpanel">
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
