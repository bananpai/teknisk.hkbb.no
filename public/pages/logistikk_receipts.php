<?php
// public/pages/logistikk_receipts.php
// Registrer inn ny varelevering (batch) mot en varetype + vis beholdning og lagerverdi
// Støtter lagerdimensjoner: fysisk (inv_locations) + logisk/prosjekt (projects)
// Støtter redigering av batch + flagg for "faktura mottatt"

use App\Database;

$pageTitle = 'Logistikk: Vareleveringer';

// Krev innlogging
$username = $_SESSION['username'] ?? '';
if (!$username) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

$pdo = Database::getConnection();

// ---------------------------------------------------------
// Rolle-sjekk (user_roles) + bakoverkompatibel admin-fallback
// ---------------------------------------------------------
$isAdmin = (bool)($_SESSION['is_admin'] ?? false);
if ($username === 'rsv') $isAdmin = true;

// Hent current user_id + roller
$currentUserId = 0;
$currentRoles  = [];

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $currentUserId = (int)($stmt->fetchColumn() ?: 0);

    if ($currentUserId > 0) {
        $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $stmt->execute([':uid' => $currentUserId]);
        $currentRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
} catch (\Throwable $e) {
    $currentRoles = [];
}

if (!$isAdmin && in_array('admin', $currentRoles, true)) $isAdmin = true;

$canWarehouseRead  = $isAdmin || in_array('warehouse_read', $currentRoles, true) || in_array('warehouse_write', $currentRoles, true);
$canWarehouseWrite = $isAdmin || in_array('warehouse_write', $currentRoles, true);

// Krev minst lesetilgang
if (!$canWarehouseRead) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til vareleveringer.
    </div>
    <?php
    return;
}

$errors = [];
$successMessage = null;

// -----------------------------
// Helpers
// -----------------------------
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function post_str(string $key): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}
function post_int(string $key): int {
    return isset($_POST[$key]) ? (int)$_POST[$key] : 0;
}
function post_bool(string $key): int {
    return isset($_POST[$key]) ? 1 : 0;
}
function post_qty_int(string $key): int {
    $v = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '0';
    $v = str_replace([' ', ','], ['', ''], $v);
    $v = preg_replace('/[^0-9\-]/', '', $v);
    return (int)$v;
}
function post_money(string $key): float {
    if (!isset($_POST[$key])) return 0.0;
    $v = str_replace(' ', '', (string)$_POST[$key]);
    $v = str_replace(',', '.', $v);
    $v = preg_replace('/[^0-9\.\-]/', '', $v);
    return (float)$v;
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :t
    ");
    $stmt->execute([':t' => $table]);
    return ((int)$stmt->fetchColumn()) > 0;
}
function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = :t
          AND column_name = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}
function columnsMap(PDO $pdo, string $table): array {
    $map = [];
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($cols as $c) $map[$c['Field']] = true;
    } catch (\Throwable $e) {
        $map = [];
    }
    return $map;
}

function fmt_qty($v): string {
    return (string)((int)round((float)$v, 0));
}
function fmt_price($v): string {
    return number_format((float)$v, 2, '.', '');
}

/**
 * Bygg dynamisk INSERT basert på felt som finnes.
 * $data = ['col' => value, ...] (inkluderer kun kolonner som faktisk finnes i tabellen)
 */
function db_insert(PDO $pdo, string $table, array $data): void {
    if (!$data) throw new RuntimeException("INSERT: ingen data for $table");
    $cols = array_keys($data);
    $ph   = array_map(fn($c) => ':' . $c, $cols);

    $sql = "INSERT INTO `$table` (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ")
            VALUES (" . implode(',', $ph) . ")";
    $stmt = $pdo->prepare($sql);

    $params = [];
    foreach ($data as $k => $v) $params[':' . $k] = $v;
    $stmt->execute($params);
}

/**
 * Dynamisk UPDATE basert på felt som finnes.
 */
function db_update(PDO $pdo, string $table, array $data, string $whereSql, array $whereParams): void {
    if (!$data) return;
    $sets = [];
    $params = [];

    foreach ($data as $k => $v) {
        $sets[] = "`$k` = :set_$k";
        $params[":set_$k"] = $v;
    }
    foreach ($whereParams as $k => $v) $params[$k] = $v;

    $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE $whereSql";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

// -----------------------------
// Tabell/kolonne-sjekk
// -----------------------------
$tablesOk =
    tableExists($pdo, 'inv_products') &&
    tableExists($pdo, 'inv_batches') &&
    tableExists($pdo, 'inv_movements') &&
    tableExists($pdo, 'inv_locations') &&
    tableExists($pdo, 'projects');

if (!$tablesOk) {
    ?>
    <div class="alert alert-warning mt-3">
        <div class="fw-semibold mb-1">Mangler tabeller</div>
        Denne siden krever inv_products, inv_batches, inv_movements, inv_locations og projects.
    </div>
    <?php
    return;
}

$batchCols = columnsMap($pdo, 'inv_batches');
$moveCols  = columnsMap($pdo, 'inv_movements');

$hasBatchPhys   = isset($batchCols['physical_location_id']);
$hasBatchProj   = isset($batchCols['project_id']);
$hasBatchInvRec = isset($batchCols['invoice_received']);

$hasMovePhys = isset($moveCols['physical_location_id']);
$hasMoveProj = isset($moveCols['project_id']);

if (!$hasBatchPhys || !$hasBatchProj || !$hasMovePhys) {
    ?>
    <div class="alert alert-warning mt-3">
        <div class="fw-semibold mb-1">Mangler kolonner</div>
        Denne siden krever:
        <code>inv_batches.physical_location_id</code>,
        <code>inv_batches.project_id</code>,
        <code>inv_movements.physical_location_id</code>.
    </div>
    <?php
    return;
}

// -----------------------------
// Data til skjema
// -----------------------------
$products = $pdo->query("
    SELECT id, name, sku, unit, is_active
    FROM inv_products
    ORDER BY is_active DESC, name ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$physicalLocations = $pdo->query("
    SELECT id, code, name
    FROM inv_locations
    WHERE is_active = 1
    ORDER BY name ASC, code ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$projects = $pdo->query("
    SELECT id, project_no, name, owner
    FROM projects
    WHERE is_active = 1
    ORDER BY project_no ASC, name ASC
    LIMIT 2000
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$productIdDefault = (int)($_GET['product_id'] ?? 0);
$editBatchId      = (int)($_GET['edit_batch_id'] ?? 0);

$editBatch = null;
if ($editBatchId > 0) {
    $st = $pdo->prepare("
        SELECT
            b.*,
            p.name AS product_name,
            p.sku  AS product_sku,
            p.unit AS product_unit
        FROM inv_batches b
        JOIN inv_products p ON p.id = b.product_id
        WHERE b.id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $editBatchId]);
    $editBatch = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editBatch) {
        $errors[] = 'Fant ikke batchen du prøver å redigere.';
        $editBatchId = 0;
    }
}

// -----------------------------
// POST: registrer/oppdater leveranse
// -----------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$canWarehouseWrite) {
        $errors[] = 'Du har ikke rettighet til å registrere/endre vareleveringer (krever warehouse_write eller admin).';
    } else {
        $action = post_str('action');

        try {
            // Normaliser dato (datetime-local)
            $received_at_raw = post_str('received_at'); // YYYY-MM-DDTHH:MM
            $occurredAt = null;
            if ($received_at_raw !== '') {
                $occurredAt = str_replace('T', ' ', $received_at_raw) . ':00';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $occurredAt)) {
                    $occurredAt = null;
                }
            }
            if ($occurredAt === null) $occurredAt = date('Y-m-d H:i:s');

            if ($action === 'receive') {
                $product_id = post_int('product_id');
                $qty        = post_qty_int('qty');          // heltall (mottatt)
                $unit_price = post_money('unit_price');     // 2 des
                $supplier   = post_str('supplier');
                $note       = post_str('note');
                $ref_no     = post_str('reference_no');

                $phys_id    = post_int('physical_location_id');
                $proj_id    = post_int('project_id'); // 0 => NULL
                $inv_recv   = $hasBatchInvRec ? post_bool('invoice_received') : 0;

                if ($product_id <= 0) throw new RuntimeException('Velg varetype.');
                if ($qty <= 0)        throw new RuntimeException('Antall må være > 0.');
                if ($unit_price < 0)  throw new RuntimeException('Pris kan ikke være negativ.');
                if ($phys_id <= 0)    throw new RuntimeException('Velg fysisk lager.');

                // produkt finnes
                $st = $pdo->prepare("SELECT id FROM inv_products WHERE id = :id LIMIT 1");
                $st->execute([':id' => $product_id]);
                if (!(int)$st->fetchColumn()) throw new RuntimeException('Varetypen finnes ikke.');

                // fysisk finnes
                $st = $pdo->prepare("SELECT id FROM inv_locations WHERE id = :id AND is_active = 1 LIMIT 1");
                $st->execute([':id' => $phys_id]);
                if (!(int)$st->fetchColumn()) throw new RuntimeException('Ugyldig fysisk lager.');

                // prosjekt valgfritt
                $projDb = null;
                if ($proj_id > 0) {
                    $st = $pdo->prepare("SELECT id FROM projects WHERE id = :id AND is_active = 1 LIMIT 1");
                    $st->execute([':id' => $proj_id]);
                    if (!(int)$st->fetchColumn()) throw new RuntimeException('Ugyldig prosjekt.');
                    $projDb = $proj_id;
                }

                $pdo->beginTransaction();

                // INSERT batch (kun kolonner som finnes)
                $batchData = [
                    'product_id'           => $product_id,
                    'physical_location_id' => $phys_id,
                    'project_id'           => $projDb,
                    'received_at'          => $occurredAt,
                    'unit_price'           => $unit_price,
                    'qty_received'         => (float)$qty,
                    'qty_remaining'        => (float)$qty,
                ];
                if (isset($batchCols['supplier'])) $batchData['supplier'] = ($supplier !== '' ? $supplier : null);
                if (isset($batchCols['note']))     $batchData['note']     = ($note !== '' ? $note : null);
                if ($hasBatchInvRec)               $batchData['invoice_received'] = (int)$inv_recv;

                // fjern kolonner som ikke finnes
                $batchData = array_filter(
                    $batchData,
                    fn($v, $k) => isset($batchCols[$k]),
                    ARRAY_FILTER_USE_BOTH
                );

                db_insert($pdo, 'inv_batches', $batchData);
                $batch_id = (int)$pdo->lastInsertId();

                // INSERT movement IN (dynamisk – støtter både "reference_*" og "source_*")
                $moveData = [
                    'occurred_at'          => $occurredAt,
                    'type'                 => 'IN',
                    'product_id'           => $product_id,
                    'batch_id'             => $batch_id,
                    'physical_location_id' => $phys_id,
                    'qty'                  => (float)$qty,
                    'unit_price'           => $unit_price,
                ];

                if ($hasMoveProj) $moveData['project_id'] = $projDb;
                if (isset($moveCols['note'])) $moveData['note'] = ($note !== '' ? $note : null);
                if (isset($moveCols['created_by'])) $moveData['created_by'] = $username ?: null;

                // noen DB-er har source_table/source_id
                if (isset($moveCols['source_table'])) $moveData['source_table'] = 'inv_batches';
                if (isset($moveCols['source_id']))    $moveData['source_id']    = $batch_id;

                // noen DB-er har reference_type/reference_no
                if (isset($moveCols['reference_type'])) $moveData['reference_type'] = 'varelevering';
                if (isset($moveCols['reference_no']))   $moveData['reference_no']   = ($ref_no !== '' ? $ref_no : null);

                // fjern kolonner som ikke finnes
                $moveData = array_filter(
                    $moveData,
                    fn($v, $k) => isset($moveCols[$k]),
                    ARRAY_FILTER_USE_BOTH
                );

                db_insert($pdo, 'inv_movements', $moveData);

                $pdo->commit();

                header('Location: /?page=logistikk_receipts&edit_batch_id=' . $batch_id . '&msg=received');
                exit;
            }

            if ($action === 'update_batch') {
                $batch_id  = post_int('batch_id');
                if ($batch_id <= 0) throw new RuntimeException('Ugyldig batch.');

                $phys_id    = post_int('physical_location_id');
                $proj_id    = post_int('project_id');
                $inv_recv   = $hasBatchInvRec ? post_bool('invoice_received') : 0;

                $supplier   = post_str('supplier');
                $note       = post_str('note');
                $unit_price = post_money('unit_price');

                // qty-feltet representerer "igjen" (qty_remaining)
                $newRemaining = post_qty_int('qty');

                if ($newRemaining < 0) throw new RuntimeException('Antall kan ikke være negativt.');
                if ($unit_price < 0)   throw new RuntimeException('Pris kan ikke være negativ.');
                if ($phys_id <= 0)     throw new RuntimeException('Velg fysisk lager.');

                // last batch
                $st = $pdo->prepare("SELECT * FROM inv_batches WHERE id = :id LIMIT 1");
                $st->execute([':id' => $batch_id]);
                $b = $st->fetch(PDO::FETCH_ASSOC);
                if (!$b) throw new RuntimeException('Batch finnes ikke.');

                $qtyReceivedOld  = (float)$b['qty_received'];
                $qtyRemainingOld = (float)$b['qty_remaining'];
                $used = $qtyReceivedOld - $qtyRemainingOld;
                if ($used < 0) $used = 0;

                // Ny qty_received = brukt + ny remaining
                $newReceived = $used + (float)$newRemaining;

                // valider fysisk
                $st = $pdo->prepare("SELECT id FROM inv_locations WHERE id = :id AND is_active = 1 LIMIT 1");
                $st->execute([':id' => $phys_id]);
                if (!(int)$st->fetchColumn()) throw new RuntimeException('Ugyldig fysisk lager.');

                // prosjekt valgfritt
                $projDb = null;
                if ($proj_id > 0) {
                    $st = $pdo->prepare("SELECT id FROM projects WHERE id = :id AND is_active = 1 LIMIT 1");
                    $st->execute([':id' => $proj_id]);
                    if (!(int)$st->fetchColumn()) throw new RuntimeException('Ugyldig prosjekt.');
                    $projDb = $proj_id;
                }

                $pdo->beginTransaction();

                // UPDATE batch (kun kolonner som finnes)
                $batchUpd = [
                    'physical_location_id' => $phys_id,
                    'project_id'           => $projDb,
                    'received_at'          => $occurredAt,
                    'unit_price'           => $unit_price,
                    'qty_received'         => $newReceived,
                    'qty_remaining'        => (float)$newRemaining,
                ];
                if (isset($batchCols['supplier'])) $batchUpd['supplier'] = ($supplier !== '' ? $supplier : null);
                if (isset($batchCols['note']))     $batchUpd['note']     = ($note !== '' ? $note : null);
                if ($hasBatchInvRec)               $batchUpd['invoice_received'] = (int)$inv_recv;

                $batchUpd = array_filter(
                    $batchUpd,
                    fn($v, $k) => isset($batchCols[$k]),
                    ARRAY_FILTER_USE_BOTH
                );

                db_update($pdo, 'inv_batches', $batchUpd, "id = :id LIMIT 1", [':id' => $batch_id]);

                // oppdater IN-movement for batch (ikke rør OUT)
                $st = $pdo->prepare("
                    SELECT id
                    FROM inv_movements
                    WHERE batch_id = :bid AND type = 'IN'
                    ORDER BY occurred_at ASC, id ASC
                    LIMIT 1
                ");
                $st->execute([':bid' => $batch_id]);
                $moveId = (int)($st->fetchColumn() ?: 0);

                if ($moveId > 0) {
                    $moveUpd = [
                        'occurred_at'          => $occurredAt,
                        'physical_location_id' => $phys_id,
                        'qty'                  => $newReceived,
                        'unit_price'           => $unit_price,
                    ];
                    if ($hasMoveProj) $moveUpd['project_id'] = $projDb;
                    if (isset($moveCols['note'])) $moveUpd['note'] = ($note !== '' ? $note : null);
                    if (isset($moveCols['created_by'])) $moveUpd['created_by'] = $username ?: null;

                    // hold source_table/source_id hvis de finnes (og sett hvis tom)
                    if (isset($moveCols['source_table'])) $moveUpd['source_table'] = 'inv_batches';
                    if (isset($moveCols['source_id']))    $moveUpd['source_id']    = $batch_id;

                    $moveUpd = array_filter(
                        $moveUpd,
                        fn($v, $k) => isset($moveCols[$k]),
                        ARRAY_FILTER_USE_BOTH
                    );

                    db_update($pdo, 'inv_movements', $moveUpd, "id = :id LIMIT 1", [':id' => $moveId]);
                }

                $pdo->commit();

                header('Location: /?page=logistikk_receipts&edit_batch_id=' . $batch_id . '&msg=updated');
                exit;
            }

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

// msg feedback
if (isset($_GET['msg']) && $_GET['msg'] === 'received') $successMessage = 'Varelevering registrert.';
if (isset($_GET['msg']) && $_GET['msg'] === 'updated')  $successMessage = 'Varelevering oppdatert.';

// -----------------------------
// Lagerstatus (beholdning + verdi) + produktbilde hvis finnes
// -----------------------------
$prodCols = columnsMap($pdo, 'inv_products');
$hasProductImage = isset($prodCols['image_path']);
$imgSelect = $hasProductImage ? "p.image_path," : "NULL AS image_path,";

$stockRows = $pdo->query("
    SELECT
        p.id,
        p.name,
        p.sku,
        p.unit,
        p.is_active,
        $imgSelect
        COALESCE(SUM(b.qty_remaining), 0) AS qty_on_hand,
        COALESCE(SUM(b.qty_remaining * b.unit_price), 0) AS value_on_hand
    FROM inv_products p
    LEFT JOIN inv_batches b ON b.product_id = p.id
    GROUP BY p.id, p.name, p.sku, p.unit, p.is_active" . ($hasProductImage ? ", p.image_path" : "") . "
    ORDER BY p.is_active DESC, p.name ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalValue = 0.0;
$totalQtyItems = 0.0;
foreach ($stockRows as $r) {
    $totalValue += (float)$r['value_on_hand'];
    $totalQtyItems += (float)$r['qty_on_hand'];
}

// Siste vareleveringer (batcher)
$latestBatches = $pdo->query("
    SELECT
        b.id,
        b.received_at,
        b.product_id,
        p.name AS product_name,
        p.sku AS product_sku,
        p.unit AS product_unit,
        b.qty_received,
        b.qty_remaining,
        b.unit_price,
        b.supplier,
        b.note,
        b.invoice_received,
        l.code AS physical_code,
        l.name AS physical_name,
        pr.project_no,
        pr.name AS project_name,
        pr.owner AS project_owner
    FROM inv_batches b
    JOIN inv_products p ON p.id = b.product_id
    LEFT JOIN inv_locations l ON l.id = b.physical_location_id
    LEFT JOIN projects pr ON pr.id = b.project_id
    ORDER BY b.received_at DESC, b.id DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<style>
/* Thumbnail med "zoomet ut" (contain) og sentrert */
.product-thumb {
    width: 38px;
    height: 38px;
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

<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h3 class="mb-0">Vareleveringer</h3>
            <div class="text-muted">Registrer nye vareleveringer (batcher) og få oversikt over beholdning og lagerverdi. (Fysisk + prosjekt)</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk">
                <i class="bi bi-arrow-left"></i> Til oversikt
            </a>
            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_movements">
                <i class="bi bi-clock-history"></i> Bevegelser
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1">Feil</div>
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?= h($successMessage) ?></div>
    <?php endif; ?>

    <?php if (!$canWarehouseWrite): ?>
        <div class="alert alert-info">
            Du har lesetilgang til vareleveringer, men ikke skrivetilgang.
            Registrering/endring krever rollen <code>warehouse_write</code> (eller <code>admin</code>).
        </div>
    <?php endif; ?>

    <!-- KPI -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Total lagerverdi (på hånd)</div>
                    <div class="fs-3 fw-semibold"><?= h(fmt_price($totalValue)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Total beholdning (sum)</div>
                    <div class="fs-3 fw-semibold"><?= h(fmt_qty($totalQtyItems)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Antall varetyper</div>
                    <div class="fs-3 fw-semibold"><?= (int)count($stockRows) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Registrer / Rediger varelevering -->
        <div class="col-12 col-xxl-4">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-semibold">
                        <i class="bi bi-box-arrow-in-down me-1"></i>
                        <?= $editBatch ? 'Rediger varelevering' : 'Registrer varelevering' ?>
                    </span>
                    <?php if ($editBatch): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_receipts">
                            Avbryt
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$canWarehouseWrite): ?>
                        <div class="alert alert-secondary mb-0">
                            Skjema er deaktivert (krever <code>warehouse_write</code> eller <code>admin</code>).
                        </div>
                    <?php else: ?>
                        <form method="post">
                            <?php if ($editBatch): ?>
                                <input type="hidden" name="action" value="update_batch">
                                <input type="hidden" name="batch_id" value="<?= (int)$editBatch['id'] ?>">
                            <?php else: ?>
                                <input type="hidden" name="action" value="receive">
                            <?php endif; ?>

                            <div class="mb-2">
                                <label class="form-label">Varetype</label>
                                <?php if ($editBatch): ?>
                                    <div class="form-control-plaintext">
                                        <div class="fw-semibold">
                                            <?= h($editBatch['product_name'] ?? '') ?>
                                            <?php if (!empty($editBatch['product_sku'])): ?>
                                                <span class="text-muted">(<?= h($editBatch['product_sku']) ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <input type="hidden" name="product_id" value="<?= (int)$editBatch['product_id'] ?>">
                                <?php else: ?>
                                    <input id="productSearch" type="text" class="form-control mb-2"
                                           placeholder="Søk varetype (navn eller produktnummer/SKU)">

                                    <select class="form-select" name="product_id" required id="productSelect">
                                        <option value="">— Velg varetype</option>
                                        <?php foreach ($products as $p): ?>
                                            <option value="<?= (int)$p['id'] ?>"
                                                    data-name="<?= h(mb_strtolower((string)$p['name'], 'UTF-8')) ?>"
                                                    data-sku="<?= h(mb_strtolower((string)($p['sku'] ?? ''), 'UTF-8')) ?>"
                                                    <?= ($productIdDefault === (int)$p['id']) ? 'selected' : '' ?>>
                                                <?php
                                                $sku = trim((string)($p['sku'] ?? ''));
                                                $label = $p['name'] . ($sku !== '' ? ' (' . $sku . ')' : '');
                                                echo h($label);
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Tips: skriv deler av navn eller SKU, så filtreres listen.</div>
                                <?php endif; ?>
                            </div>

                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label"><?= $editBatch ? 'Antall (igjen)' : 'Antall mottatt' ?></label>
                                    <input class="form-control" name="qty" inputmode="numeric" required
                                           value="<?php
                                           if ($editBatch) echo h((string)(int)round((float)$editBatch['qty_remaining'], 0));
                                           ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Innkjøpspris pr enhet</label>
                                    <input class="form-control" name="unit_price" inputmode="decimal" required
                                           value="<?php
                                           if ($editBatch) echo h(fmt_price((float)$editBatch['unit_price']));
                                           ?>">
                                </div>
                            </div>

                            <div class="mt-2">
                                <label class="form-label">Mottatt tidspunkt</label>
                                <?php
                                $dtVal = '';
                                if ($editBatch && !empty($editBatch['received_at'])) {
                                    $dtVal = substr((string)$editBatch['received_at'], 0, 16);
                                    $dtVal = str_replace(' ', 'T', $dtVal);
                                }
                                ?>
                                <input class="form-control" type="datetime-local" name="received_at" value="<?= h($dtVal) ?>">
                            </div>

                            <div class="row g-2 mt-2">
                                <div class="col-6">
                                    <label class="form-label">Fysisk lager</label>
                                    <select class="form-select" name="physical_location_id" required>
                                        <option value="0">— Velg fysisk lager</option>
                                        <?php
                                        $selPhys = (int)($editBatch['physical_location_id'] ?? 0);
                                        foreach ($physicalLocations as $l):
                                            $label = ($l['code'] ? $l['code'] . ' · ' : '') . $l['name'];
                                        ?>
                                            <option value="<?= (int)$l['id'] ?>" <?= ($selPhys === (int)$l['id']) ? 'selected' : '' ?>>
                                                <?= h($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Prosjekt (logisk lager)</label>
                                    <select class="form-select" name="project_id">
                                        <?php $selProj = (int)($editBatch['project_id'] ?? 0); ?>
                                        <option value="0" <?= ($selProj === 0) ? 'selected' : '' ?>>— Uten prosjekt —</option>
                                        <?php foreach ($projects as $pr): ?>
                                            <?php
                                            $pid = (int)$pr['id'];
                                            $label = trim(($pr['project_no'] ?? '') . ' - ' . ($pr['name'] ?? ''));
                                            if (!empty($pr['owner'])) $label .= ' (' . $pr['owner'] . ')';
                                            ?>
                                            <option value="<?= $pid ?>" <?= ($selProj === $pid) ? 'selected' : '' ?>>
                                                <?= h($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-2 mt-2">
                                <div class="col-6">
                                    <label class="form-label">Leverandør (valgfritt)</label>
                                    <input class="form-control" name="supplier" value="<?= h($editBatch['supplier'] ?? '') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Ref / faktura (valgfritt)</label>
                                    <input class="form-control" name="reference_no" value="">
                                    <div class="form-text">Brukes kun ved registrering (IN-movement). Edit endrer ikke ref.</div>
                                </div>
                            </div>

                            <div class="mt-2">
                                <label class="form-label">Notat (valgfritt)</label>
                                <textarea class="form-control" name="note" rows="2"><?= h($editBatch['note'] ?? '') ?></textarea>
                            </div>

                            <?php if ($hasBatchInvRec): ?>
                                <div class="form-check mt-2">
                                    <?php $chk = $editBatch ? ((int)$editBatch['invoice_received'] === 1) : false; ?>
                                    <input class="form-check-input" type="checkbox" name="invoice_received" id="invrecv" <?= $chk ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="invrecv">Faktura mottatt</label>
                                </div>
                            <?php endif; ?>

                            <div class="mt-3">
                                <button class="btn <?= $editBatch ? 'btn-success' : 'btn-primary' ?>">
                                    <i class="bi bi-check2-circle me-1"></i>
                                    <?= $editBatch ? 'Oppdater' : 'Registrer inn' ?>
                                </button>
                            </div>
                        </form>

                        <?php if (!$editBatch): ?>
                            <script>
                                (function () {
                                    const input = document.getElementById('productSearch');
                                    const select = document.getElementById('productSelect');
                                    if (!input || !select) return;

                                    const options = Array.from(select.options);

                                    function applyFilter() {
                                        const q = (input.value || '').trim().toLowerCase();
                                        let anyVisible = false;

                                        options.forEach(opt => {
                                            if (!opt.value) return;
                                            const name = (opt.dataset.name || '');
                                            const sku  = (opt.dataset.sku || '');
                                            const hit = q === '' || name.includes(q) || sku.includes(q);
                                            opt.hidden = !hit;
                                            if (hit) anyVisible = true;
                                        });

                                        const sel = select.selectedOptions && select.selectedOptions[0];
                                        if (sel && sel.hidden) select.value = '';

                                        if (q !== '' && !anyVisible) select.title = 'Ingen treff – prøv et annet søk.';
                                        else select.title = '';
                                    }

                                    input.addEventListener('input', applyFilter);
                                    applyFilter();
                                })();
                            </script>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="card-footer small text-muted">
                    Beholdning/verdi beregnes fra batchene. Uttak registreres separat og endres ikke når du editerer en batch.
                </div>
            </div>
        </div>

        <!-- Beholdning og verdi per varetype -->
        <div class="col-12 col-xxl-8">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-semibold"><i class="bi bi-clipboard-data me-1"></i> Beholdning og lagerverdi</span>
                    <span class="text-muted small"><?= (int)count($stockRows) ?> varetyper</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th style="width:56px;"></th>
                                    <th>Varetype</th>
                                    <th>Produktnummer / SKU</th>
                                    <th class="text-end">Beholdning</th>
                                    <th class="text-end">Lagerverdi</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stockRows as $r): ?>
                                    <?php $img = trim((string)($r['image_path'] ?? '')); ?>
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
                                                    <i class="bi bi-box-seam text-muted" style="font-size:1.1rem;"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold">
                                                <?= h($r['name']) ?>
                                                <?php if (!(int)$r['is_active']): ?>
                                                    <span class="badge bg-secondary ms-1">Inaktiv</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small"><?= h($r['unit'] ?? '') ?></div>
                                        </td>
                                        <td class="text-muted small"><?= h($r['sku'] ?? '') ?></td>
                                        <td class="text-end">
                                            <?= h(fmt_qty($r['qty_on_hand'])) ?>
                                            <span class="text-muted"><?= h($r['unit'] ?? '') ?></span>
                                        </td>
                                        <td class="text-end"><?= h(fmt_price($r['value_on_hand'])) ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-secondary"
                                               href="/?page=logistikk_movements&product_id=<?= (int)$r['id'] ?>">
                                                <i class="bi bi-clock-history"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($stockRows)): ?>
                                    <tr><td colspan="6" class="text-muted p-3">Ingen varer funnet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer small text-muted">
                    Lagerverdi = SUM(qty_remaining × unit_price) per varetype. Klikk på bilde-thumbnail for å åpne bilde.
                </div>
            </div>

            <!-- Siste vareleveringer -->
            <div class="card mt-3">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-semibold"><i class="bi bi-truck me-1"></i> Siste vareleveringer</span>
                    <span class="text-muted small">Viser 50 siste batcher</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Tid</th>
                                    <th>Varetype</th>
                                    <th>Fysisk</th>
                                    <th>Prosjekt</th>
                                    <th class="text-end">Antall (igjen)</th>
                                    <th class="text-end">Pris</th>
                                    <th class="text-end">Verdi (igjen)</th>
                                    <th>Leverandør</th>
                                    <th>Faktura</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($latestBatches as $b): ?>
                                    <?php
                                    $qtyRem = (float)$b['qty_remaining'];
                                    $uPrice = (float)$b['unit_price'];
                                    $valRem = $qtyRem * $uPrice;

                                    $physLabel = '—';
                                    if (!empty($b['physical_name'])) {
                                        $physLabel = ($b['physical_code'] ? $b['physical_code'] . ' · ' : '') . $b['physical_name'];
                                    }

                                    $projLabel = '— Uten prosjekt —';
                                    if (!empty($b['project_no']) || !empty($b['project_name'])) {
                                        $projLabel = trim(($b['project_no'] ?? '') . ' - ' . ($b['project_name'] ?? ''));
                                        if (!empty($b['project_owner'])) $projLabel .= ' (' . $b['project_owner'] . ')';
                                    }
                                    ?>
                                    <tr class="<?= ((int)$editBatchId === (int)$b['id']) ? 'table-info' : '' ?>">
                                        <td class="small text-muted"><?= h($b['received_at'] ?? '—') ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= h($b['product_name']) ?></div>
                                            <div class="text-muted small"><?= h($b['product_sku'] ?? '') ?></div>
                                        </td>
                                        <td class="small"><?= h($physLabel) ?></td>
                                        <td class="small"><?= h($projLabel) ?></td>
                                        <td class="text-end">
                                            <?= h(fmt_qty($qtyRem)) ?>
                                            <span class="text-muted"><?= h($b['product_unit'] ?? '') ?></span>
                                        </td>
                                        <td class="text-end"><?= h(fmt_price($uPrice)) ?></td>
                                        <td class="text-end"><?= h(fmt_price($valRem)) ?></td>
                                        <td class="small"><?= h($b['supplier'] ?? '—') ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($b['invoice_received'])): ?>
                                                <span class="badge bg-success">Ja</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Nei</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="/?page=logistikk_receipts&edit_batch_id=<?= (int)$b['id'] ?>">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($latestBatches)): ?>
                                    <tr><td colspan="10" class="text-muted p-3">Ingen vareleveringer er registrert enda.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer small text-muted">
                    Redigerer du en batch, endrer vi kun batch + IN-movement. OUT-movements forblir uendret.
                </div>
            </div>
        </div>
    </div>
</div>
