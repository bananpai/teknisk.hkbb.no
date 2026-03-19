<?php
// public/pages/inv_out_shop.php
//
// "Butikk" for uttak/flytting fra lager (FIFO/batch):
//
// OPPDATERT:
// - Beholdning fra inv_batches.qty_remaining (filtrert på project_id hvis finnes)
// - Checkout allokerer FIFO mot batcher og skriver inv_movements med batch_id + unit_price
// - Trekker ned inv_batches.qty_remaining
// - Transfer: OUT fra kilde-batch + opprett ny batch på dest + IN på dest med ny batch
// - Antall er heltall (uten desimaler) + enheter vises
//
// NYTT:
// - Produktbilde thumbnail (inv_products.image_path) i liste + handlekurv (likt som logistikk_products.php)

use App\Database;

$pdo = Database::getConnection();

// ---------------------------------------------------------
// Rolle-guard (warehouse_write) + admin-fallback
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

$isAdmin = (bool)($_SESSION['is_admin'] ?? false);
if ($username === 'rsv') {
    $isAdmin = true;
}

$currentUserId = 0;
$currentRoles  = [];

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute(['u' => $username]);
    $currentUserId = (int)($stmt->fetchColumn() ?: 0);

    if ($currentUserId > 0) {
        $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $stmt->execute(['uid' => $currentUserId]);
        $currentRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
} catch (\Throwable $e) {
    $currentRoles = [];
}

if (!$isAdmin && in_array('admin', $currentRoles, true)) {
    $isAdmin = true;
}

$canWarehouseWrite = $isAdmin || in_array('warehouse_write', $currentRoles, true);
if (!$canWarehouseWrite) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til å ta ut/flytte varer.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :t
        ");
        $stmt->execute(['t' => $table]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :t
              AND column_name = :c
        ");
        $stmt->execute(['t' => $table, 'c' => $column]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

function ensureProductImageColumn(PDO $pdo): bool
{
    try {
        if (!columnExists($pdo, 'inv_products', 'image_path')) {
            $pdo->exec("ALTER TABLE inv_products ADD COLUMN image_path VARCHAR(255) NULL");
        }
        return true;
    } catch (\Throwable $e) {
        return columnExists($pdo, 'inv_products', 'image_path');
    }
}

function post_qty_int(string $key): int {
    $v = trim((string)($_POST[$key] ?? '0'));
    $v = str_replace([' ', ','], ['', ''], $v);
    $v = preg_replace('/[^0-9\-]/', '', $v);
    return (int)$v;
}

function fmt_int($v): string {
    return number_format((float)$v, 0, ',', ' ');
}

function safe_unit(?string $u): string {
    $u = trim((string)$u);
    return ($u !== '') ? $u : 'stk';
}

// ---------------------------------------------------------
// Sanity checks
// ---------------------------------------------------------
$errors  = [];
$success = null;

if (!tableExists($pdo, 'inv_products'))   $errors[] = "Mangler tabell inv_products.";
if (!tableExists($pdo, 'inv_movements')) $errors[] = "Mangler tabell inv_movements.";
if (!tableExists($pdo, 'inv_locations')) $errors[] = "Mangler tabell inv_locations.";
if (!tableExists($pdo, 'projects'))      $errors[] = "Mangler tabell projects.";
if (!tableExists($pdo, 'inv_batches'))   $errors[] = "Mangler tabell inv_batches (kreves for FIFO/batch).";

$hasProjectType = !$errors && columnExists($pdo, 'projects', 'project_type');
$hasWorkOrders  = !$errors && tableExists($pdo, 'work_orders');

// inv_movements kolonner
$hasMoveWorkOrderId = !$errors && columnExists($pdo, 'inv_movements', 'work_order_id');
$hasMoveBatchId     = !$errors && columnExists($pdo, 'inv_movements', 'batch_id');
$hasMoveUnitPrice   = !$errors && columnExists($pdo, 'inv_movements', 'unit_price');
$hasMovePhys        = !$errors && columnExists($pdo, 'inv_movements', 'physical_location_id');
$hasMoveProjectId   = !$errors && columnExists($pdo, 'inv_movements', 'project_id');

// inv_batches kolonner
$hasBatchProduct = !$errors && columnExists($pdo, 'inv_batches', 'product_id');
$hasBatchPhys    = !$errors && columnExists($pdo, 'inv_batches', 'physical_location_id');
$hasBatchProj    = !$errors && columnExists($pdo, 'inv_batches', 'project_id');
$hasBatchRemain  = !$errors && columnExists($pdo, 'inv_batches', 'qty_remaining');
$hasBatchRecvAt  = !$errors && columnExists($pdo, 'inv_batches', 'received_at');
$hasBatchPrice   = !$errors && columnExists($pdo, 'inv_batches', 'unit_price');
$hasBatchQtyRec  = !$errors && columnExists($pdo, 'inv_batches', 'qty_received');

$batchOutSupported =
    !$errors &&
    $hasMoveBatchId &&
    $hasMoveUnitPrice &&
    $hasMovePhys &&
    $hasMoveProjectId &&
    $hasBatchProduct &&
    $hasBatchPhys &&
    $hasBatchRemain &&
    $hasBatchRecvAt &&
    $hasBatchPrice &&
    $hasBatchQtyRec;

// Produktbilde-kolonne
$hasImageColumn = !$errors && ensureProductImageColumn($pdo);

// ---------------------------------------------------------
// Session: husk valg
// ---------------------------------------------------------
if (!isset($_SESSION['inv_shop_last_logical_project_id'])) {
    $_SESSION['inv_shop_last_logical_project_id'] = 0;
}
if (!isset($_SESSION['inv_shop_last_source_location_id'])) {
    $_SESSION['inv_shop_last_source_location_id'] = 0;
}

// ---------------------------------------------------------
// Session-cart
// ---------------------------------------------------------
if (!isset($_SESSION['inv_shop_cart']) || !is_array($_SESSION['inv_shop_cart'])) {
    $_SESSION['inv_shop_cart'] = [
        'logical_project_id' => 0,
        'source_location_id' => 0,
        'items' => [], // product_id => ['qty'=>int,'name'=>string,'sku'=>string,'unit'=>string]
    ];
}
$cart =& $_SESSION['inv_shop_cart'];

// ---------------------------------------------------------
// Reset (Nullstill)
// ---------------------------------------------------------
if (isset($_GET['reset']) && (string)$_GET['reset'] === '1') {
    $_SESSION['inv_shop_last_logical_project_id'] = 0;
    $_SESSION['inv_shop_last_source_location_id'] = 0;

    $cart['logical_project_id'] = 0;
    $cart['source_location_id'] = 0;
    $cart['items'] = [];

    header('Location: /?page=inv_out_shop');
    exit;
}

// ---------------------------------------------------------
// Input
// ---------------------------------------------------------
$logicalProjectIdReq = isset($_REQUEST['logical_project_id']) ? (int)$_REQUEST['logical_project_id'] : 0;
$sourceLocationReq   = isset($_REQUEST['source_location_id']) ? (int)$_REQUEST['source_location_id'] : 0;

$showZero = isset($_GET['show_zero']) && (string)$_GET['show_zero'] === '1';
$q = trim((string)($_GET['q'] ?? ''));

$prefillProductId = (int)($_GET['product_id'] ?? 0);
if ($prefillProductId > 0 && !isset($_GET['show_zero'])) {
    $showZero = true;
}

if (array_key_exists('logical_project_id', $_REQUEST)) {
    $_SESSION['inv_shop_last_logical_project_id'] = $logicalProjectIdReq;
    if ($logicalProjectIdReq <= 0) $_SESSION['inv_shop_last_source_location_id'] = 0;
}
if (array_key_exists('source_location_id', $_REQUEST)) {
    $_SESSION['inv_shop_last_source_location_id'] = $sourceLocationReq;
}

$logicalProjectId = array_key_exists('logical_project_id', $_REQUEST)
    ? $logicalProjectIdReq
    : (int)($_SESSION['inv_shop_last_logical_project_id'] ?? 0);

$sourceLocationId = ($logicalProjectId > 0)
    ? (array_key_exists('source_location_id', $_REQUEST)
        ? $sourceLocationReq
        : (int)($_SESSION['inv_shop_last_source_location_id'] ?? 0))
    : 0;

// ---------------------------------------------------------
// Dropdown-data (locations, projects, work orders)
// ---------------------------------------------------------
$locations = [];
$locationLabelById = [];

try {
    $stmt = $pdo->query("SELECT id, code, name FROM inv_locations WHERE is_active=1 ORDER BY name, code");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $label = trim((string)$r['code']) !== '' ? ($r['code'].' – '.$r['name']) : (string)$r['name'];
        $locations[] = ['id'=>$id, 'label'=>$label];
        $locationLabelById[$id] = $label;
    }
} catch (\Throwable $e) {}

$lagerProjects = [];
$arbeidProjects = [];
$projectNameById = [];
$projectTypeById = [];

try {
    if ($hasProjectType) {
        $stmt = $pdo->query("SELECT id, project_no, name, project_type FROM projects WHERE is_active=1 ORDER BY name");
    } else {
        $stmt = $pdo->query("SELECT id, project_no, name FROM projects WHERE is_active=1 ORDER BY name");
    }
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($all as $p) {
        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) continue;

        $ptype = $hasProjectType ? (string)($p['project_type'] ?? 'arbeid') : 'arbeid';
        if ($ptype !== 'lager' && $ptype !== 'arbeid') $ptype = 'arbeid';

        $row = [
            'id' => $id,
            'project_no' => (string)($p['project_no'] ?? ''),
            'name' => (string)($p['name'] ?? ''),
            'project_type' => $ptype,
        ];

        $projectNameById[$id] = $row['name'];
        $projectTypeById[$id] = $ptype;

        if (!$hasProjectType) {
            $lagerProjects[]  = $row;
            $arbeidProjects[] = $row;
        } else {
            if ($ptype === 'lager')  $lagerProjects[]  = $row;
            if ($ptype === 'arbeid') $arbeidProjects[] = $row;
        }
    }
} catch (\Throwable $e) {}

if (!$errors && $hasProjectType && $logicalProjectId > 0) {
    $lt = $projectTypeById[$logicalProjectId] ?? null;
    if ($lt !== null && $lt !== 'lager') {
        $logicalProjectId = 0;
        $_SESSION['inv_shop_last_logical_project_id'] = 0;
        $_SESSION['inv_shop_last_source_location_id'] = 0;
        $cart['logical_project_id'] = 0;
        $cart['source_location_id'] = 0;
        $cart['items'] = [];
        $sourceLocationId = 0;
    }
}

$workOrdersAll = [];
if ($hasWorkOrders) {
    try {
        $stmt = $pdo->query("SELECT id, project_id, title FROM work_orders ORDER BY id DESC");
        $workOrdersAll = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}
}

// ---------------------------------------------------------
// Reset cart hvis kontekst endres
// ---------------------------------------------------------
if ((int)($cart['logical_project_id'] ?? 0) !== (int)$logicalProjectId
 || (int)($cart['source_location_id'] ?? 0) !== (int)$sourceLocationId) {
    $cart['logical_project_id'] = (int)$logicalProjectId;
    $cart['source_location_id'] = (int)$sourceLocationId;
    $cart['items'] = [];
}

// ---------------------------------------------------------
// Unit-kolonne i inv_products
// ---------------------------------------------------------
$unitCol = null;
if (!$errors) {
    foreach (['unit','unit_name','uom','uom_name','enhet','unit_code','uom_code'] as $c) {
        if (columnExists($pdo, 'inv_products', $c)) { $unitCol = $c; break; }
    }
}

// ---------------------------------------------------------
// Produkter (aktive) + enhet + bilde
// ---------------------------------------------------------
$products = [];
$productRows = [];
try {
    $unitSel = $unitCol ? "COALESCE(`{$unitCol}`,'') AS unit" : "'' AS unit";
    $imgSel  = ($hasImageColumn ? "COALESCE(image_path,'') AS image_path" : "'' AS image_path");

    $stmt = $pdo->query("
        SELECT id, name, COALESCE(sku,'') AS sku, {$unitSel}, {$imgSel}
        FROM inv_products
        WHERE is_active = 1
        ORDER BY name
        LIMIT 5000
    ");
    $productRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($productRows as $pr) {
        $pid = (int)($pr['id'] ?? 0);
        if ($pid <= 0) continue;
        $products[$pid] = [
            'id' => $pid,
            'name' => (string)($pr['name'] ?? ''),
            'sku' => (string)($pr['sku'] ?? ''),
            'unit' => (string)($pr['unit'] ?? ''),
            'image_path' => (string)($pr['image_path'] ?? ''),
        ];
    }
} catch (\Throwable $e) {}

if ($prefillProductId > 0 && $q === '' && isset($products[$prefillProductId])) {
    $psku  = trim((string)($products[$prefillProductId]['sku'] ?? ''));
    $pname = trim((string)($products[$prefillProductId]['name'] ?? ''));
    $q = trim($psku . ' ' . $pname);
}

// ---------------------------------------------------------
// Beholdning fra batch (SUM qty_remaining), filtrert på project_id hvis finnes
// ---------------------------------------------------------
$availByLocProduct = [];
$availableByProduct = [];

if (!$errors && $logicalProjectId > 0) {
    try {
        $projFilterSql = '';
        $params = [];

        if ($hasBatchProj) {
            $projFilterSql = " AND b.project_id = :pid ";
            $params['pid'] = $logicalProjectId;
        }

        $sql = "
            SELECT
                b.physical_location_id AS location_id,
                b.product_id AS product_id,
                COALESCE(SUM(b.qty_remaining), 0) AS avail_qty
            FROM inv_batches b
            WHERE b.physical_location_id IS NOT NULL
              AND b.qty_remaining IS NOT NULL
              {$projFilterSql}
            GROUP BY b.physical_location_id, b.product_id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $r) {
            $lid = (int)($r['location_id'] ?? 0);
            $pid = (int)($r['product_id'] ?? 0);
            $aq  = (float)($r['avail_qty'] ?? 0);
            if ($lid <= 0 || $pid <= 0) continue;

            if (!isset($availByLocProduct[$lid])) $availByLocProduct[$lid] = [];
            $availByLocProduct[$lid][$pid] = $aq;

            if ($sourceLocationId > 0 && $lid === $sourceLocationId) {
                $availableByProduct[$pid] = $aq;
            }
        }
    } catch (\Throwable $e) {
        $availByLocProduct = [];
        $availableByProduct = [];
    }
}

// ---------------------------------------------------------
// FIFO: hent batcher FOR UPDATE
// ---------------------------------------------------------
function fifo_fetch_batches(PDO $pdo, int $productId, int $physId, int $logicalProjectId, bool $hasBatchProj): array {
    $params = [
        'pid'  => $productId,
        'phys' => $physId,
    ];
    $projSql = '';
    if ($hasBatchProj) {
        $projSql = " AND b.project_id = :proj ";
        $params['proj'] = $logicalProjectId;
    }

    $sql = "
        SELECT
            b.id,
            b.received_at,
            b.qty_remaining,
            b.unit_price
        FROM inv_batches b
        WHERE b.product_id = :pid
          AND b.physical_location_id = :phys
          AND b.qty_remaining > 0
          {$projSql}
        ORDER BY b.received_at ASC, b.id ASC
        FOR UPDATE
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ---------------------------------------------------------
// Actions
// ---------------------------------------------------------
$action = $_POST['action'] ?? '';

if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($action === 'add_to_cart') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty       = post_qty_int('qty');

        $name = trim((string)($_POST['product_name'] ?? ''));
        $sku  = trim((string)($_POST['product_sku'] ?? ''));
        $unit = trim((string)($_POST['product_unit'] ?? ''));

        if ($logicalProjectId <= 0) $errors[] = 'Velg logisk lager (lagerprosjekt) først.';
        if ($sourceLocationId <= 0) $errors[] = 'Velg fysisk lager (lokasjon) øverst først.';
        if ($hasProjectType && $logicalProjectId > 0 && (($projectTypeById[$logicalProjectId] ?? '') !== 'lager')) {
            $errors[] = 'Valgt logisk lager må være et lagerprosjekt.';
        }
        if ($productId <= 0) $errors[] = 'Ugyldig produkt.';
        if ($qty <= 0) $errors[] = 'Antall må være > 0.';
        if (!$batchOutSupported) {
            $errors[] = 'Batch/FIFO er ikke korrekt konfigurert i databasen (krever inv_batches + batch_id/unit_price i inv_movements).';
        }

        $avail = (float)($availableByProduct[$productId] ?? 0.0);
        $already = (int)($cart['items'][$productId]['qty'] ?? 0);

        $availInt = (int)floor($avail + 1e-9);
        if ($qty + $already > $availInt) {
            $errors[] = "Ikke nok beholdning på valgt lokasjon. Tilgjengelig: {$availInt}.";
        }

        if (!$errors) {
            if (!isset($cart['items'][$productId])) {
                $cart['items'][$productId] = ['qty'=>0, 'name'=>$name, 'sku'=>$sku, 'unit'=>$unit];
            }
            $cart['items'][$productId]['qty'] += $qty;
            if ($name !== '') $cart['items'][$productId]['name'] = $name;
            if ($sku !== '')  $cart['items'][$productId]['sku']  = $sku;
            if ($unit !== '') $cart['items'][$productId]['unit'] = $unit;

            $success = "Lagt i handlekurv.";
        }
    }

    if ($action === 'update_cart') {
        $removeId = (int)($_POST['remove_product_id'] ?? 0);
        if ($removeId > 0) {
            unset($cart['items'][$removeId]);
            $success = "Fjernet fra handlekurv.";
        }

        foreach ((array)($_POST['cart_qty'] ?? []) as $productIdStr => $qtyStr) {
            $productId = (int)$productIdStr;
            $qtyRaw = (string)$qtyStr;
            $qtyRaw = str_replace([' ', ','], ['', ''], $qtyRaw);
            $qtyRaw = preg_replace('/[^0-9\-]/', '', $qtyRaw);
            $qty = (int)$qtyRaw;

            if ($productId <= 0) continue;

            if ($qty <= 0) {
                unset($cart['items'][$productId]);
                continue;
            }

            $avail = (float)($availableByProduct[$productId] ?? 0.0);
            $availInt = (int)floor($avail + 1e-9);

            if ($qty > $availInt) {
                $errors[] = "For mye på produkt #$productId. Tilgjengelig: {$availInt}.";
                continue;
            }

            if (isset($cart['items'][$productId])) {
                $cart['items'][$productId]['qty'] = $qty;
            }
        }

        if (!$errors && !$success) $success = "Handlekurv oppdatert.";
    }

    if ($action === 'checkout') {
        if ($logicalProjectId <= 0) $errors[] = 'Velg logisk lager (lagerprosjekt) først.';
        if ($sourceLocationId <= 0) $errors[] = 'Velg fysisk lager (lokasjon) øverst først.';
        if ($hasProjectType && $logicalProjectId > 0 && (($projectTypeById[$logicalProjectId] ?? '') !== 'lager')) {
            $errors[] = 'Valgt logisk lager må være et lagerprosjekt.';
        }
        if (empty($cart['items'])) $errors[] = 'Handlekurven er tom.';
        if (!$batchOutSupported) {
            $errors[] = 'Checkout stoppet: batch/FIFO er ikke støttet i databasen (krever inv_batches + inv_movements.batch_id + inv_movements.unit_price).';
        }

        $checkoutType      = (string)($_POST['checkout_type'] ?? 'out_project'); // out_project | transfer
        $targetProjectId   = (int)($_POST['target_project_id'] ?? 0);
        $targetWorkOrderId = (int)($_POST['target_work_order_id'] ?? 0);
        $destLocationId    = (int)($_POST['dest_location_id'] ?? 0);

        if ($checkoutType === 'out_project') {
            if ($targetProjectId <= 0) $errors[] = 'Velg arbeidsprosjekt for uttak.';
            if ($hasProjectType && $targetProjectId > 0 && (($projectTypeById[$targetProjectId] ?? '') !== 'arbeid')) {
                $errors[] = 'Mottakerprosjekt må være et arbeidsprosjekt.';
            }

            if (!$errors && $targetWorkOrderId > 0 && $hasWorkOrders) {
                try {
                    $stmt = $pdo->prepare("SELECT project_id FROM work_orders WHERE id = :id LIMIT 1");
                    $stmt->execute(['id' => $targetWorkOrderId]);
                    $woPid = (int)($stmt->fetchColumn() ?: 0);
                    if ($woPid !== $targetProjectId) {
                        $errors[] = 'Valgt arbeidsordre tilhører ikke valgt arbeidsprosjekt.';
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Kunne ikke validere arbeidsordre.';
                }
            }
        } elseif ($checkoutType === 'transfer') {
            if ($destLocationId <= 0) $errors[] = 'Velg mottakende lager (lokasjon).';
            if ($destLocationId === $sourceLocationId) $errors[] = 'Mottakende lager kan ikke være samme som kilde.';
        } else {
            $errors[] = 'Ugyldig checkout-type.';
        }

        foreach ($cart['items'] as $pid => $it) {
            $pid  = (int)$pid;
            $need = (int)($it['qty'] ?? 0);
            $avail = (float)($availableByProduct[$pid] ?? 0.0);
            $availInt = (int)floor($avail + 1e-9);

            if ($need <= 0) { $errors[] = "Ugyldig antall for produkt #$pid."; continue; }
            if ($need > $availInt) {
                $errors[] = "Ikke nok beholdning for produkt #$pid. Tilgjengelig: {$availInt}.";
            }
        }

        if (!$errors) {
            $now = (new DateTime())->format('Y-m-d H:i:s');

            $sourceProjectName = $projectNameById[$logicalProjectId] ?? ("#".$logicalProjectId);
            $targetProjectName = $projectNameById[$targetProjectId] ?? ("#".$targetProjectId);
            $sourceLocLabel    = $locationLabelById[$sourceLocationId] ?? ("#".$sourceLocationId);

            try {
                $pdo->beginTransaction();

                // ✅ HY093-fiks: bruk positional placeholders (samme verdi kan brukes flere ganger)
                $stmtDecBatch = $pdo->prepare("
                    UPDATE inv_batches
                    SET qty_remaining = qty_remaining - ?
                    WHERE id = ?
                      AND qty_remaining >= ?
                    LIMIT 1
                ");

                // insert movement (named)
                $insCols = [
                    'occurred_at', 'type', 'product_id', 'batch_id', 'physical_location_id',
                    'qty', 'unit_price', 'project_id', 'note', 'created_by'
                ];
                if ($hasMoveWorkOrderId) $insCols[] = 'work_order_id';

                $colList = implode(',', $insCols);
                $placeholders = implode(',', array_map(fn($c)=>':'.$c, $insCols));

                $stmtInsMove = $pdo->prepare("
                    INSERT INTO inv_movements ($colList)
                    VALUES ($placeholders)
                ");

                // insert ny batch (transfer)
                $stmtInsBatch = $pdo->prepare("
                    INSERT INTO inv_batches
                        (product_id, physical_location_id, project_id, received_at, supplier, note, unit_price, qty_received, qty_remaining)
                    VALUES
                        (:pid, :phys, :proj, :received_at, :supplier, :note, :unit_price, :qty_received, :qty_remaining)
                ");

                $created = 0;

                foreach ($cart['items'] as $pid => $it) {
                    $pid = (int)$pid;
                    $need = (int)($it['qty'] ?? 0);
                    if ($pid <= 0 || $need <= 0) continue;

                    $batches = fifo_fetch_batches($pdo, $pid, $sourceLocationId, $logicalProjectId, $hasBatchProj);
                    $remaining = $need;

                    foreach ($batches as $b) {
                        if ($remaining <= 0) break;

                        $bid = (int)($b['id'] ?? 0);
                        $rem = (float)($b['qty_remaining'] ?? 0.0);
                        $uprice = (float)($b['unit_price'] ?? 0.0);
                        $recvAt = (string)($b['received_at'] ?? $now);

                        if ($bid <= 0) continue;

                        $remInt = (int)floor($rem + 1e-9);
                        if ($remInt <= 0) continue;

                        $take = min($remaining, $remInt);
                        if ($take <= 0) continue;

                        // trekk batch
                        $stmtDecBatch->execute([$take, $bid, $take]);
                        if ($stmtDecBatch->rowCount() !== 1) {
                            throw new RuntimeException("Batch {$bid} kunne ikke trekkes (mulig samtidig uttak). Prøv igjen.");
                        }

                        if ($checkoutType === 'out_project') {
                            $noteOut = "Uttak (butikk) fra {$sourceProjectName} ({$sourceLocLabel}) → {$targetProjectName}"
                                     . ($targetWorkOrderId > 0 ? " / WO #{$targetWorkOrderId}" : "");

                            $paramsMove = [
                                'occurred_at' => $now,
                                'type' => 'OUT',
                                'product_id' => $pid,
                                'batch_id' => $bid,
                                'physical_location_id' => $sourceLocationId,
                                'qty' => $take,
                                'unit_price' => $uprice,
                                'project_id' => $targetProjectId,
                                'note' => $noteOut,
                                'created_by' => $username,
                            ];
                            if ($hasMoveWorkOrderId) {
                                $paramsMove['work_order_id'] = ($targetWorkOrderId > 0 ? $targetWorkOrderId : null);
                            }
                            $stmtInsMove->execute($paramsMove);
                            $created++;
                        } else {
                            $destLabel = $locationLabelById[$destLocationId] ?? ("#".$destLocationId);

                            // OUT fra kilde med eksisterende batch_id
                            $noteOut = "Flytt (butikk) {$sourceProjectName}: {$sourceLocLabel} → {$destLabel}";
                            $paramsOut = [
                                'occurred_at' => $now,
                                'type' => 'OUT',
                                'product_id' => $pid,
                                'batch_id' => $bid,
                                'physical_location_id' => $sourceLocationId,
                                'qty' => $take,
                                'unit_price' => $uprice,
                                'project_id' => $logicalProjectId,
                                'note' => $noteOut,
                                'created_by' => $username,
                            ];
                            if ($hasMoveWorkOrderId) $paramsOut['work_order_id'] = null;

                            $stmtInsMove->execute($paramsOut);
                            $created++;

                            // ny batch på dest (beholder opprinnelig received_at for FIFO)
                            $noteBatch = "Flyttet fra {$sourceLocLabel} (batch #{$bid})";
                            $stmtInsBatch->execute([
                                'pid' => $pid,
                                'phys' => $destLocationId,
                                'proj' => $logicalProjectId,
                                'received_at' => $recvAt,
                                'supplier' => null,
                                'note' => $noteBatch,
                                'unit_price' => $uprice,
                                'qty_received' => $take,
                                'qty_remaining' => $take,
                            ]);
                            $newBatchId = (int)$pdo->lastInsertId();

                            // IN på dest med ny batch
                            $noteIn = "Flytt (butikk) {$sourceProjectName}: {$destLabel} ← {$sourceLocLabel}";
                            $paramsIn = [
                                'occurred_at' => $now,
                                'type' => 'IN',
                                'product_id' => $pid,
                                'batch_id' => $newBatchId,
                                'physical_location_id' => $destLocationId,
                                'qty' => $take,
                                'unit_price' => $uprice,
                                'project_id' => $logicalProjectId,
                                'note' => $noteIn,
                                'created_by' => $username,
                            ];
                            if ($hasMoveWorkOrderId) $paramsIn['work_order_id'] = null;

                            $stmtInsMove->execute($paramsIn);
                            $created++;
                        }

                        $remaining -= $take;
                    }

                    if ($remaining > 0) {
                        throw new RuntimeException("Ikke nok batch-beholdning for produkt #{$pid}. Mangler {$remaining} stk.");
                    }
                }

                $pdo->commit();
                $cart['items'] = [];
                $success = "Checkout fullført. Opprettet {$created} bevegelse(r).";
            } catch (\Throwable $e) {
                try { $pdo->rollBack(); } catch (\Throwable $e2) {}
                $errors[] = $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------
// UI: handlekurv count = antall varer (linjer)
// ---------------------------------------------------------
$cartItemCount = is_array($cart['items']) ? count($cart['items']) : 0;

// UI defaults
$checkoutTypeUi    = (string)($_POST['checkout_type'] ?? 'out_project');
$targetProjectUi   = (int)($_POST['target_project_id'] ?? 0);
$targetWorkOrderUi = (int)($_POST['target_work_order_id'] ?? 0);
$destLocationUi    = (int)($_POST['dest_location_id'] ?? 0);

if ($targetProjectUi <= 0 && count($arbeidProjects) === 1) {
    $targetProjectUi = (int)$arbeidProjects[0]['id'];
}

?>
<style>
/* Thumbnail med "zoomet ut" (contain) og sentrert */
.product-thumb {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    border: 1px solid #e5e5e5;
    background: #fff;
    overflow: hidden;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.product-thumb img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    object-position: center;
    display: block;
}
.product-thumb-link {
    display: inline-flex;
    text-decoration: none;
}
.product-thumb-link:hover .product-thumb {
    border-color: rgba(0,0,0,.25);
}
</style>

<div class="d-flex align-items-start justify-content-between mt-3">
    <div>
        <h3 class="mb-1">Varelager – Uttak / Flytt (Butikk)</h3>
        <div class="text-muted">
            Velg <strong>logisk lager</strong>. Velg <strong>fysisk lager</strong> for å legge i kurv. Uten valgt fysisk lager vises alle varelinjer (vare + lokasjon).
        </div>
        <?php if (!$batchOutSupported && $logicalProjectId > 0): ?>
            <div class="alert alert-warning mt-2 mb-0">
                <strong>Batch/FIFO mangler i DB:</strong> Checkout er deaktivert.
                Krever <code>inv_batches</code> + <code>inv_movements.batch_id</code> + <code>inv_movements.unit_price</code>.
            </div>
        <?php endif; ?>
    </div>
    <div class="text-end">
        <div class="text-muted">Handlekurv</div>
        <div style="font-size:1.25rem;"><strong><?= (int)$cartItemCount ?></strong> varer</div>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger mt-3">
        <strong>Feil:</strong><br>
        <?= nl2br(h(implode("\n", $errors))) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success mt-3">
        <?= h($success) ?>
    </div>
<?php endif; ?>

<form class="row g-2 mt-3" method="get">
    <input type="hidden" name="page" value="inv_out_shop">
    <?php if ($prefillProductId > 0): ?>
        <input type="hidden" name="product_id" value="<?= (int)$prefillProductId ?>">
    <?php endif; ?>

    <div class="col-12 col-lg-5">
        <label class="form-label">Logisk lager (Lagerprosjekt)</label>
        <select name="logical_project_id" class="form-select" onchange="this.form.submit()">
            <option value="0">Alle lagerprosjekter…</option>
            <?php foreach ($lagerProjects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $logicalProjectId === (int)$p['id'] ? 'selected' : '' ?>>
                    <?= h($p['name']) ?><?php if (!empty($p['project_no'])): ?> (<?= h($p['project_no']) ?>)<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="text-muted small mt-1">Valget huskes automatisk.</div>
    </div>

    <div class="col-12 col-lg-5">
        <label class="form-label">Fysisk lager (Lokasjon)</label>
        <select name="source_location_id" class="form-select" <?= $logicalProjectId > 0 ? '' : 'disabled' ?> onchange="this.form.submit()">
            <option value="0">Alle lokasjoner…</option>
            <?php foreach ($locations as $l): ?>
                <option value="<?= (int)$l['id'] ?>" <?= $sourceLocationId === (int)$l['id'] ? 'selected' : '' ?>>
                    <?= h($l['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="text-muted small mt-1">Velg lokasjon for å kunne legge i handlekurv.</div>
    </div>

    <div class="col-12 col-lg-2">
        <label class="form-label">&nbsp;</label>
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="showZero" name="show_zero" value="1"
                   <?= $showZero ? 'checked' : '' ?>
                   onchange="this.form.submit()"
                   <?= $logicalProjectId > 0 ? '' : 'disabled' ?>>
            <label class="form-check-label" for="showZero">
                Vis 0-beholdning
            </label>
        </div>
    </div>

    <div class="col-12 col-lg-2">
        <a class="btn btn-outline-secondary w-100 mt-2 mt-lg-0" href="?page=inv_out_shop&reset=1">Nullstill</a>
    </div>
</form>

<hr class="my-4">

<?php if ($logicalProjectId <= 0): ?>
    <div class="alert alert-info">
        Velg først <strong>logisk lager</strong> for å starte.
    </div>
<?php else: ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h5 class="mb-0">
                <?php if ($sourceLocationId > 0): ?>
                    Varer på: <span class="text-muted"><?= h($locationLabelById[$sourceLocationId] ?? ('#'.$sourceLocationId)) ?></span>
                <?php else: ?>
                    Varer på alle lokasjoner
                <?php endif; ?>
            </h5>

            <div class="d-flex gap-2 align-items-center">
                <input type="text" class="form-control" id="productSearch"
                       value="<?= h($q) ?>" placeholder="Søk… (navn / SKU / lokasjon)"
                       style="min-width: 320px;" autocomplete="off">
                <span class="text-muted small" id="searchCount"></span>
            </div>
        </div>

        <div class="table-responsive mt-2">
            <table class="table table-sm table-striped align-middle" id="productsTable">
                <thead>
                <tr>
                    <th style="width:64px;"></th>
                    <th>Vare</th>
                    <?php if ($sourceLocationId <= 0): ?>
                        <th>Fysisk lager</th>
                        <th class="text-end" style="width:170px;">Beholdning</th>
                        <th class="text-end" style="width:210px;">Handling</th>
                    <?php else: ?>
                        <th class="text-end" style="width:170px;">Tilgjengelig</th>
                        <th class="text-end" style="width:280px;">Legg til</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php
                $rendered = 0;

                if ($sourceLocationId > 0) {
                    foreach ($productRows as $pr) {
                        $pid   = (int)($pr['id'] ?? 0);
                        $pname = (string)($pr['name'] ?? '');
                        $psku  = (string)($pr['sku'] ?? '');
                        $unit  = safe_unit((string)($pr['unit'] ?? ''));
                        $img   = trim((string)($pr['image_path'] ?? ''));
                        if ($pid <= 0) continue;

                        $avail = (float)($availableByProduct[$pid] ?? 0.0);
                        $availInt = (int)floor($avail + 1e-9);
                        if (!$showZero && $availInt <= 0) continue;

                        $inCart = (int)($cart['items'][$pid]['qty'] ?? 0);

                        $hay = mb_strtolower(trim($pname . ' ' . $psku . ' ' . ($locationLabelById[$sourceLocationId] ?? '')), 'UTF-8');
                        $disabled = ($availInt <= 0) || !$batchOutSupported;

                        $rendered++;
                        ?>
                        <tr class="product-row <?= ($prefillProductId > 0 && $prefillProductId === $pid) ? 'table-info' : '' ?>"
                            data-search="<?= h($hay) ?>" data-product-id="<?= (int)$pid ?>">

                            <td>
                                <?php if ($img !== ''): ?>
                                    <a class="product-thumb-link" href="<?= h($img) ?>" target="_blank" rel="noopener" title="Åpne bilde">
                                        <span class="product-thumb">
                                            <img src="<?= h($img) ?>" alt="">
                                        </span>
                                    </a>
                                <?php else: ?>
                                    <span class="product-thumb" title="Ingen bilde">
                                        <i class="bi bi-box-seam text-muted" style="font-size:1.2rem;"></i>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div><strong><?= h($pname) ?></strong></div>
                                <?php if ($psku !== ''): ?><div class="text-muted small"><?= h($psku) ?></div><?php endif; ?>
                                <div class="text-muted small"><?= h($unit) ?></div>
                                <?php if ($inCart > 0): ?><div class="text-muted small">i kurv: <?= fmt_int($inCart) ?> <?= h($unit) ?></div><?php endif; ?>
                            </td>
                            <td class="text-end">
                                <strong><?= fmt_int($availInt) ?></strong> <span class="text-muted"><?= h($unit) ?></span>
                            </td>
                            <td class="text-end">
                                <form method="post" class="d-flex justify-content-end gap-2">
                                    <input type="hidden" name="action" value="add_to_cart">
                                    <input type="hidden" name="logical_project_id" value="<?= (int)$logicalProjectId ?>">
                                    <input type="hidden" name="source_location_id" value="<?= (int)$sourceLocationId ?>">
                                    <input type="hidden" name="product_id" value="<?= (int)$pid ?>">
                                    <input type="hidden" name="product_name" value="<?= h($pname) ?>">
                                    <input type="hidden" name="product_sku" value="<?= h($psku) ?>">
                                    <input type="hidden" name="product_unit" value="<?= h($unit) ?>">
                                    <input type="text" name="qty" class="form-control form-control-sm text-end" style="width:90px;"
                                           value="1" <?= $disabled ? 'disabled' : '' ?>>
                                    <button class="btn btn-sm btn-primary" <?= $disabled ? 'disabled' : '' ?>>Legg i kurv</button>
                                </form>
                            </td>
                        </tr>
                        <?php
                    }
                } else {
                    foreach ($locations as $loc) {
                        $lid = (int)$loc['id'];
                        $lbl = (string)$loc['label'];

                        $map = $availByLocProduct[$lid] ?? [];
                        foreach ($productRows as $pr) {
                            $pid = (int)($pr['id'] ?? 0);
                            if ($pid <= 0) continue;

                            $pname = (string)($pr['name'] ?? '');
                            $psku  = (string)($pr['sku'] ?? '');
                            $unit  = safe_unit((string)($pr['unit'] ?? ''));
                            $img   = trim((string)($pr['image_path'] ?? ''));

                            $avail = (float)($map[$pid] ?? 0.0);
                            $availInt = (int)floor($avail + 1e-9);
                            if (!$showZero && $availInt <= 0) continue;

                            $hay = mb_strtolower(trim($pname.' '.$psku.' '.$lbl), 'UTF-8');

                            $rendered++;
                            ?>
                            <tr class="product-row <?= ($prefillProductId > 0 && $prefillProductId === $pid) ? 'table-info' : '' ?>"
                                data-search="<?= h($hay) ?>" data-product-id="<?= (int)$pid ?>">

                                <td>
                                    <?php if ($img !== ''): ?>
                                        <a class="product-thumb-link" href="<?= h($img) ?>" target="_blank" rel="noopener" title="Åpne bilde">
                                            <span class="product-thumb">
                                                <img src="<?= h($img) ?>" alt="">
                                            </span>
                                        </a>
                                    <?php else: ?>
                                        <span class="product-thumb" title="Ingen bilde">
                                            <i class="bi bi-box-seam text-muted" style="font-size:1.2rem;"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div><strong><?= h($pname) ?></strong></div>
                                    <?php if ($psku !== ''): ?><div class="text-muted small"><?= h($psku) ?></div><?php endif; ?>
                                    <div class="text-muted small"><?= h($unit) ?></div>
                                </td>
                                <td><?= h($lbl) ?></td>
                                <td class="text-end">
                                    <strong><?= fmt_int($availInt) ?></strong> <span class="text-muted"><?= h($unit) ?></span>
                                </td>
                                <td class="text-end text-muted small">Velg lokasjon øverst for å legge i kurv</td>
                            </tr>
                            <?php
                        }
                    }
                }

                if ($rendered === 0):
                ?>
                    <tr><td colspan="<?= $sourceLocationId <= 0 ? 5 : 4 ?>" class="text-muted">Ingen treff.</td></tr>
                <?php endif; ?>

                <tr id="noMatchesRow" style="display:none;">
                    <td colspan="<?= $sourceLocationId <= 0 ? 5 : 4 ?>" class="text-muted">Ingen treff.</td>
                </tr>

                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-5">
        <h5 class="mb-2">Handlekurv</h5>

        <?php if (empty($cart['items'])): ?>
            <div class="alert alert-secondary">Handlekurven er tom.</div>
        <?php else: ?>
            <form method="post" class="mb-3">
                <input type="hidden" name="action" value="update_cart">
                <input type="hidden" name="logical_project_id" value="<?= (int)$logicalProjectId ?>">
                <input type="hidden" name="source_location_id" value="<?= (int)$sourceLocationId ?>">

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                        <tr>
                            <th style="width:64px;"></th>
                            <th>Vare</th>
                            <th style="width:150px;" class="text-end">Antall</th>
                            <th style="width:90px;"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cart['items'] as $pid => $it): ?>
                            <?php
                                $pid = (int)$pid;
                                $nm  = (string)($it['name'] ?? ("#".$pid));
                                $sku = (string)($it['sku'] ?? '');
                                $qty = (int)($it['qty'] ?? 0);
                                $unit = safe_unit((string)($it['unit'] ?? ($products[$pid]['unit'] ?? '')));
                                $avail = (float)($availableByProduct[$pid] ?? 0);
                                $availInt = (int)floor($avail + 1e-9);
                                $img = trim((string)($products[$pid]['image_path'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <?php if ($img !== ''): ?>
                                        <a class="product-thumb-link" href="<?= h($img) ?>" target="_blank" rel="noopener" title="Åpne bilde">
                                            <span class="product-thumb">
                                                <img src="<?= h($img) ?>" alt="">
                                            </span>
                                        </a>
                                    <?php else: ?>
                                        <span class="product-thumb" title="Ingen bilde">
                                            <i class="bi bi-box-seam text-muted" style="font-size:1.2rem;"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div><strong><?= h($nm) ?></strong></div>
                                    <?php if ($sku !== ''): ?><div class="text-muted small"><?= h($sku) ?></div><?php endif; ?>
                                    <div class="text-muted small">
                                        fra: <?= h($locationLabelById[$sourceLocationId] ?? ('#'.$sourceLocationId)) ?>
                                        • tilgjengelig: <?= fmt_int($availInt) ?> <?= h($unit) ?>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end align-items-center gap-2">
                                        <input class="form-control form-control-sm text-end"
                                               type="text"
                                               name="cart_qty[<?= (int)$pid ?>]"
                                               style="width:90px;"
                                               value="<?= h((string)$qty) ?>">
                                        <span class="text-muted small"><?= h($unit) ?></span>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-danger"
                                            type="submit"
                                            name="remove_product_id"
                                            value="<?= (int)$pid ?>">
                                        Fjern
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-primary" type="submit">Oppdater</button>
                </div>
            </form>

            <div class="card">
                <div class="card-body">
                    <h6 class="card-title mb-3">Checkout</h6>

                    <form method="post" id="checkoutForm">
                        <input type="hidden" name="action" value="checkout">
                        <input type="hidden" name="logical_project_id" value="<?= (int)$logicalProjectId ?>">
                        <input type="hidden" name="source_location_id" value="<?= (int)$sourceLocationId ?>">

                        <div class="mb-2">
                            <label class="form-label">Hva vil du gjøre?</label>
                            <select class="form-select" name="checkout_type" id="checkout_type" onchange="toggleCheckout()">
                                <option value="out_project" <?= $checkoutTypeUi === 'out_project' ? 'selected' : '' ?>>Uttak til arbeidsprosjekt / arbeidsordre</option>
                                <option value="transfer" <?= $checkoutTypeUi === 'transfer' ? 'selected' : '' ?>>Flytt til annet fysisk lager</option>
                            </select>
                        </div>

                        <div id="checkout_out_project">
                            <div class="mb-2">
                                <label class="form-label">Arbeidsprosjekt (mottaker)</label>
                                <select class="form-select" name="target_project_id" id="target_project_id" onchange="filterWorkOrders()">
                                    <option value="0">Velg arbeidsprosjekt…</option>
                                    <?php foreach ($arbeidProjects as $p): ?>
                                        <?php $ppid = (int)$p['id']; ?>
                                        <option value="<?= $ppid ?>" <?= ($ppid === $targetProjectUi) ? 'selected' : '' ?>>
                                            <?= h($p['name']) ?><?php if (!empty($p['project_no'])): ?> (<?= h($p['project_no']) ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Arbeidsordre (valgfritt)</label>
                                <select class="form-select" name="target_work_order_id" id="target_work_order_id">
                                    <option value="0" data-project-id="0">Ingen</option>
                                    <?php foreach ($workOrdersAll as $wo): ?>
                                        <?php
                                            $woId = (int)($wo['id'] ?? 0);
                                            $woPid = (int)($wo['project_id'] ?? 0);
                                            $woTitle = (string)($wo['title'] ?? '');
                                        ?>
                                        <option value="<?= $woId ?>" data-project-id="<?= $woPid ?>" <?= ($woId === $targetWorkOrderUi) ? 'selected' : '' ?>>
                                            #<?= $woId ?> – <?= h($woTitle) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div id="checkout_transfer" style="display:none;">
                            <div class="mb-2">
                                <label class="form-label">Mottakende fysisk lager (lokasjon)</label>
                                <select class="form-select" name="dest_location_id">
                                    <option value="0">Velg lokasjon…</option>
                                    <?php foreach ($locations as $l): ?>
                                        <?php if ((int)$l['id'] === $sourceLocationId) continue; ?>
                                        <option value="<?= (int)$l['id'] ?>" <?= ((int)$l['id'] === $destLocationUi) ? 'selected' : '' ?>>
                                            <?= h($l['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <button class="btn btn-success w-100 mt-2" <?= !$batchOutSupported ? 'disabled' : '' ?>>Check ut</button>
                        <?php if (!$batchOutSupported): ?>
                            <div class="text-muted small mt-2">Checkout er deaktivert fordi batch/FIFO ikke er støttet i databasen.</div>
                        <?php endif; ?>
                    </form>

                    <script>
                        function toggleCheckout() {
                            const v = document.getElementById('checkout_type').value;
                            document.getElementById('checkout_out_project').style.display = (v === 'out_project') ? '' : 'none';
                            document.getElementById('checkout_transfer').style.display = (v === 'transfer') ? '' : 'none';
                        }

                        function filterWorkOrders() {
                            const projSel = document.getElementById('target_project_id');
                            const woSel   = document.getElementById('target_work_order_id');
                            if (!projSel || !woSel) return;

                            const pid = parseInt(projSel.value || '0', 10);

                            let keepSelected = false;
                            for (const opt of woSel.options) {
                                const opid = parseInt(opt.getAttribute('data-project-id') || '0', 10);
                                const shouldShow = (opt.value === '0') || (pid > 0 && opid === pid);
                                opt.hidden = !shouldShow;
                                if (shouldShow && opt.selected) keepSelected = true;
                            }
                            if (!keepSelected) woSel.value = '0';
                        }

                        toggleCheckout();
                        filterWorkOrders();
                    </script>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const input = document.getElementById('productSearch');
    const rows  = Array.from(document.querySelectorAll('#productsTable tbody tr.product-row'));
    const noRow = document.getElementById('noMatchesRow');
    const cntEl = document.getElementById('searchCount');
    const prePid = <?= (int)$prefillProductId ?>;

    if (!input || rows.length === 0) return;

    function applyFilter() {
        const q = (input.value || '').trim().toLowerCase();
        let shown = 0;

        rows.forEach(tr => {
            const hay = (tr.getAttribute('data-search') || '').toLowerCase();
            const match = (q === '') || hay.includes(q);
            tr.style.display = match ? '' : 'none';
            if (match) shown++;
        });

        if (noRow) noRow.style.display = (shown === 0) ? '' : 'none';
        if (cntEl) cntEl.textContent = (q === '') ? '' : (shown + ' treff');
    }

    input.addEventListener('input', applyFilter);
    applyFilter();

    if (prePid > 0) {
        const target = document.querySelector('#productsTable tbody tr.product-row[data-product-id="' + prePid + '"]');
        if (target && target.style.display !== 'none') {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
})();
</script>

<?php endif; ?>
