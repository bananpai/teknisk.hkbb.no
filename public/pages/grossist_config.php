<?php
// public/pages/grossist_config.php

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
        Du har ikke tilgang til grossist-konfigurasjon.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Hent access_id
// ---------------------------------------------------------
$accessId = isset($_GET['access_id']) ? (int)$_GET['access_id'] : 0;
if ($accessId <= 0) {
    ?>
    <div class="alert alert-warning mt-3">
        Ugyldig eller manglende access_id.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Hent data for denne grossist-aksessen
// ---------------------------------------------------------
$pdo = Database::getConnection();

/*
 * Matcher ditt schema:
 *
 *  - grossist_access
 *  - grossist_vendors
 *  - grossist_service_routers
 *  - edge_routers
 *  - access_routers
 */
$sql = "
SELECT
    ga.*,
    gv.name            AS vendor_name,

    sr.sr_name         AS sr_name,
    sr.sr_ip           AS sr_ip,
    sr.location_name   AS sr_location,

    er.name            AS er_name,
    er.mgmt_ip         AS er_mgmt_ip,
    er.edge_type       AS er_type,

    ar.name            AS ar_name,
    ar.mgmt_ip         AS ar_mgmt_ip,
    ar.node_type       AS ar_node_type

FROM grossist_access ga
JOIN grossist_vendors         gv ON gv.id = ga.vendor_id
JOIN grossist_service_routers sr ON sr.id = ga.sr_id
JOIN edge_routers             er ON er.id = ga.er_id
JOIN access_routers           ar ON ar.id = ga.ar_id
WHERE ga.id = :id
LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $accessId]);
$access = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$access) {
    ?>
    <div class="alert alert-danger mt-3">
        Fant ikke grossist-aksess med ID <?= htmlspecialchars($accessId) ?>.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Felles variabler
// ---------------------------------------------------------
$cust      = $access['customer_name'];
$circuit   = $access['circuit_ref'];
$vendor    = $access['vendor_name'];

$srName    = $access['sr_name'];
$erName    = $access['er_name'];
$arName    = $access['ar_name'];

$srAcIf    = $access['sr_ac_if'];      // NNI-subif på SR
$erToArIf  = $access['er_to_ar_if'];   // Lag2 mot AR
$arCpeIf   = $access['ar_cpe_if'];     // Port mot CPE

$sVlan     = $access['s_vlan'] !== null ? (int)$access['s_vlan'] : null;
$cVlan     = $access['c_vlan'] !== null ? (int)$access['c_vlan'] : null;
$pwId      = (int)$access['pw_id'];
$mtu       = (int)($access['mtu'] ?: 9100);

$srLoop    = $access['sr_loopback_ip']; // fra grossist_access
$erLoop    = $access['er_loopback_ip']; // fra grossist_access

$svcName   = 'L2VPN-' . preg_replace('/\s+/', '_', $circuit);
$xcGroup   = 'GRS-' . $accessId;

// Håndter null-VLAN med "TODO" i kommentaren
$srVlanStr = $sVlan !== null ? (string)$sVlan : '<SETT_S_VLAN>';
$erVlanStr = $cVlan !== null ? (string)$cVlan : '<SETT_C_VLAN>';
$arVlanStr = $erVlanStr;

// ---------------------------------------------------------
// Bygg konfig for SR (Cisco IOS XR)
// ---------------------------------------------------------
$srConfig = <<<CFG
! ============================
! Service-Router (SR)
! Boks: {$srName}
! Kunde: {$cust}
! Circuit: {$circuit}
! Grossist: {$vendor}
! ============================

interface {$srAcIf}
 description {$cust} / {$vendor} NNI {$circuit}
 encapsulation dot1q {$srVlanStr}
 rewrite ingress tag pop 1 symmetric
 l2transport
 mtu {$mtu}
!

l2vpn
 xconnect group {$xcGroup}
  p2p {$svcName}
   interface {$srAcIf}
   neighbor ipv4 {$erLoop} pw-id {$pwId}
    encapsulation mpls
    mtu {$mtu}
  !
 !
!
CFG;

// ---------------------------------------------------------
// Bygg konfig for ER (Cisco IOS XR)
// ---------------------------------------------------------
$erSubif = $erToArIf . '.' . $erVlanStr;

$erConfig = <<<CFG
! ============================
! Edge-Router (ER)
! Boks: {$erName}
! Kunde: {$cust}
! Circuit: {$circuit}
! ============================

interface {$erSubif}
 description {$cust} / AR-link / C-VLAN {$erVlanStr}
 encapsulation dot1q {$erVlanStr}
 l2transport
 mtu {$mtu}
!

l2vpn
 xconnect group {$xcGroup}
  p2p {$svcName}
   interface {$erSubif}
   neighbor ipv4 {$srLoop} pw-id {$pwId}
    encapsulation mpls
    mtu {$mtu}
  !
 !
!
CFG;

// ---------------------------------------------------------
// Bygg konfig for AR
// For nå antar vi Cisco L2 (4500/9400).
// Om node_type tilsier Huawei/MA5800, kan vi lage egen mal senere.
// ---------------------------------------------------------
$arNodeType = strtolower($access['ar_node_type'] ?? '');

if (strpos($arNodeType, 'huawei') !== false || strpos($arNodeType, 'ma5800') !== false) {
    // Placeholder Huawei-mal – fyll ut når du vil ta den i bruk
    $arConfig = <<<CFG
! ============================
! Access-Router (AR) - Huawei (TODO)
! Boks: {$arName}
! Kunde: {$cust}
! Circuit: {$circuit}
! ============================
! Denne aksessen er merket som Huawei/MA5800 (node_type={$access['ar_node_type']}).
! Legg inn Huawei-serviceport-konfig her når du er klar til å automatisere den.
!
! Eksempel:
! vlan {$arVlanStr} smart
!  description {$cust} {$circuit}
!  quit
!
! service-port <ID> vlan {$arVlanStr} gpon X/X/X ont Y gemport 1 multi-service user-vlan {$arVlanStr} tag-transform transparent
!
CFG;
} else {
    // Cisco 4500/9400 – ren L2 access mot CPE
    $arConfig = <<<CFG
! ============================
! Access-Router (AR) - Cisco
! Boks: {$arName}
! Kunde: {$cust}
! Circuit: {$circuit}
! ============================

interface {$arCpeIf}
 description {$cust} CPE / L2 access VLAN {$arVlanStr}
 switchport
 switchport mode access
 switchport access vlan {$arVlanStr}
 spanning-tree portfast
!
CFG;
}

?>
<div class="container mt-3">
    <h1>Grossist-aksess konfig</h1>

    <p class="text-muted">
        Kunde: <strong><?= htmlspecialchars($cust) ?></strong> |
        Circuit: <strong><?= htmlspecialchars($circuit) ?></strong> |
        Grossist: <strong><?= htmlspecialchars($vendor) ?></strong>
    </p>

    <ul class="nav nav-tabs" id="configTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="sr-tab" data-bs-toggle="tab" data-bs-target="#sr" type="button" role="tab">
                SR (<?= htmlspecialchars($srName) ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="er-tab" data-bs-toggle="tab" data-bs-target="#er" type="button" role="tab">
                ER (<?= htmlspecialchars($erName) ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="ar-tab" data-bs-toggle="tab" data-bs-target="#ar" type="button" role="tab">
                AR (<?= htmlspecialchars($arName) ?>)
            </button>
        </li>
    </ul>

    <div class="tab-content border border-top-0 p-3" id="configTabsContent">
        <div class="tab-pane fade show active" id="sr" role="tabpanel" aria-labelledby="sr-tab">
            <h5>Service-Router (IOS XR)</h5>
            <pre class="mb-0"><code><?= htmlspecialchars($srConfig) ?></code></pre>
        </div>

        <div class="tab-pane fade" id="er" role="tabpanel" aria-labelledby="er-tab">
            <h5>Edge-Router (IOS XR)</h5>
            <pre class="mb-0"><code><?= htmlspecialchars($erConfig) ?></code></pre>
        </div>

        <div class="tab-pane fade" id="ar" role="tabpanel" aria-labelledby="ar-tab">
            <h5>Access-Router (Cisco / Huawei)</h5>
            <pre class="mb-0"><code><?= htmlspecialchars($arConfig) ?></code></pre>
        </div>
    </div>
</div>
