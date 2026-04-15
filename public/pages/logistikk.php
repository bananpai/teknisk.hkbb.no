<?php
// public/pages/logistikk.php

use App\Database;

$pageTitle = 'Logistikk & varelager';

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

// Admin via rolle
if (!$isAdmin && in_array('admin', $currentRoles, true)) {
    $isAdmin = true;
}

$canWarehouseRead  = $isAdmin || in_array('warehouse_read', $currentRoles, true) || in_array('warehouse_write', $currentRoles, true);
$canWarehouseWrite = $isAdmin || in_array('warehouse_write', $currentRoles, true);

// Krev minst lesetilgang
if (!$canWarehouseRead) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til logistikk &amp; varelager.
    </div>
    <?php
    return;
}

// -----------------------------
// Helpers
// -----------------------------
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :t
    ");
    $stmt->execute([':t' => $table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function tableColumns(PDO $pdo, string $table): array
{
    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = $r['Field'];
    }
    return $cols;
}

function pickFirst(array $candidates, array $available): ?string
{
    foreach ($candidates as $c) {
        if (in_array($c, $available, true)) return $c;
    }
    return null;
}

function fetchProducts(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            p.id,
            p.sku,
            p.name,
            p.unit,
            p.min_stock,
            p.is_active,
            c.name AS category_name
        FROM inv_products p
        LEFT JOIN inv_categories c ON c.id = p.category_id
        ORDER BY p.is_active DESC, p.name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Beholdning (kun ledger):
 * - qty_on_hand: beregnes fra inv_movements: IN = +ABS(qty), OUT = -ABS(qty)
 * + image_path hvis kolonnen finnes (ellers NULL)
 */
function fetchStock(PDO $pdo): array
{
    $pCols = tableColumns($pdo, 'inv_products');
    $hasImage = in_array('image_path', $pCols, true);

    $imgSelect = $hasImage ? "p.image_path," : "NULL AS image_path,";

    $stmt = $pdo->query("
        SELECT
            p.id AS product_id,
            p.sku,
            p.name,
            $imgSelect
            p.unit,
            p.min_stock,
            c.name AS category_name,
            COALESCE(mv.qty_on_hand, 0) AS qty_on_hand
        FROM inv_products p
        LEFT JOIN inv_categories c ON c.id = p.category_id
        LEFT JOIN (
            SELECT
                product_id,
                SUM(
                    CASE
                        WHEN type = 'IN'  THEN ABS(qty)
                        WHEN type = 'OUT' THEN -ABS(qty)
                        ELSE 0
                    END
                ) AS qty_on_hand
            FROM inv_movements
            GROUP BY product_id
        ) mv ON mv.product_id = p.id
        WHERE p.is_active = 1
        ORDER BY p.name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Siste bevegelser – med batch hvis mulig.
 */
function fetchRecentMovements(PDO $pdo, int $limit = 25): array
{
    $mvCols = tableColumns($pdo, 'inv_movements');

    $mvBatchIdCol   = pickFirst(['batch_id'], $mvCols);
    $mvBatchTextCol = pickFirst(['batch_no','batch_code','batch_ref','batch'], $mvCols);

    $selectBatch = "NULL AS batch_label";
    $joinBatch   = "";

    if ($mvBatchIdCol && tableExists($pdo, 'inv_batches')) {
        $btCols = tableColumns($pdo, 'inv_batches');
        $btLabelCol = pickFirst(['batch_no','batch_code','code','name','reference','ref'], $btCols);

        if ($btLabelCol) {
            $selectBatch = "b.`$btLabelCol` AS batch_label";
            $joinBatch   = "LEFT JOIN inv_batches b ON b.id = m.`$mvBatchIdCol`";
        }
    } elseif ($mvBatchTextCol) {
        $selectBatch = "m.`$mvBatchTextCol` AS batch_label";
    }

    $sql = "
        SELECT
            m.id,
            m.occurred_at,
            m.type,
            m.qty,
            m.unit_price,
            m.reference_type,
            m.reference_no,
            m.issued_to,
            m.note,
            m.created_by,
            p.name AS product_name,
            p.sku  AS product_sku,
            p.unit AS product_unit,
            {$selectBatch}
        FROM inv_movements m
        LEFT JOIN inv_products p ON p.id = m.product_id
        {$joinBatch}
        ORDER BY m.occurred_at DESC, m.id DESC
        LIMIT :lim
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// -----------------------------
// Sjekk tabeller finnes
// -----------------------------
$tablesOk =
    tableExists($pdo, 'inv_categories') &&
    tableExists($pdo, 'inv_products') &&
    tableExists($pdo, 'inv_movements');

if (!$tablesOk) {
    ?>
    <div class="alert alert-warning mt-3">
        <div class="fw-semibold mb-1">Modulen er ikke initialisert</div>
        Mangler en eller flere tabeller (inv_categories / inv_products / inv_movements).
    </div>
    <?php
    return;
}

// -----------------------------
// Data til visning
// -----------------------------
$products = fetchProducts($pdo);
$stock    = fetchStock($pdo);
$recent   = fetchRecentMovements($pdo, 25);

// KPI: lav lager teller (kun når min_stock > 0 og qty_on_hand < min_stock)
$lowCount = 0;
foreach ($stock as $s) {
    $onHand   = (float)$s['qty_on_hand'];
    $minStock = (float)$s['min_stock'];
    if ($minStock > 0 && $onHand < $minStock) $lowCount++;
}

// KPI: aktive varer
$activeCount = 0;
foreach ($products as $p) {
    if ((int)$p['is_active'] === 1) $activeCount++;
}
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
            <h3 class="mb-0">Logistikk &amp; varelager</h3>
            <div class="text-muted">Oversikt over beholdning og siste bevegelser.</div>
        </div>

        <div class="d-flex gap-2 flex-wrap justify-content-end">
            <a class="btn btn-sm btn-primary" href="/?page=logistikk_receipts">
                <i class="bi bi-truck me-1"></i> Varemottak
            </a>
            <a class="btn btn-sm btn-danger" href="/?page=inv_out_shop">
                <i class="bi bi-box-arrow-up me-1"></i> Uttak
            </a>

            <span class="vr d-none d-md-inline"></span>

            <a class="btn btn-sm btn-outline-primary" href="/?page=logistikk_products">
                <i class="bi bi-box-seam me-1"></i> Varer
            </a>
            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_categories">
                <i class="bi bi-tags me-1"></i> Kategorier
            </a>
            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_movements">
                <i class="bi bi-clock-history me-1"></i> Bevegelser
            </a>
        </div>
    </div>

    <?php if (!$canWarehouseWrite): ?>
        <div class="alert alert-info">
            Du har lesetilgang til lager. For å registrere varemottak/uttak trenger du <code>warehouse_write</code> (eller <code>admin</code>).
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- KPI -->
        <div class="col-12">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <div class="text-muted small">Aktive varer</div>
                            <div class="fs-3 fw-semibold"><?php echo (int)$activeCount; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <div class="text-muted small">Bevegelser (siste 25)</div>
                            <div class="fs-3 fw-semibold"><?php echo count($recent); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card <?php echo $lowCount > 0 ? 'border-warning' : ''; ?>">
                        <div class="card-body">
                            <div class="text-muted small">Under minimumslager</div>
                            <div class="fs-3 fw-semibold">
                                <?php echo (int)$lowCount; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Beholdning -->
        <div class="col-12 col-xxl-7">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-semibold">Beholdning</span>
                    <span class="text-muted small">På lager (fra inn/ut-logg)</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th style="width:56px;"></th>
                                    <th>Vare</th>
                                    <th>Kategori</th>
                                    <th class="text-end">På lager</th>
                                    <th class="text-end">Min.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stock as $s): ?>
                                    <?php
                                    $onHand   = (float)$s['qty_on_hand'];
                                    $minStock = (float)$s['min_stock'];

                                    $isLow = ($minStock > 0 && $onHand < $minStock);

                                    $onHandDisp   = (int)round($onHand, 0);
                                    $minStockDisp = (int)round($minStock, 0);

                                    $img = trim((string)($s['image_path'] ?? ''));
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($img !== ''): ?>
                                                <a class="product-thumb-link" href="<?php echo h($img); ?>" target="_blank" rel="noopener" title="Åpne bilde">
                                                    <span class="product-thumb">
                                                        <img src="<?php echo h($img); ?>" alt="">
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
                                                <?php echo h($s['name']); ?>
                                                <?php if ($isLow): ?>
                                                    <i class="bi bi-flag-fill text-danger ms-2"
                                                       title="<?php echo h('Under minimumslager (min ' . $minStockDisp . ')'); ?>"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small"><?php echo h($s['sku'] ?? ''); ?></div>
                                        </td>

                                        <td><?php echo h($s['category_name'] ?? '—'); ?></td>

                                        <td class="text-end">
                                            <?php echo number_format($onHandDisp, 0, '.', ''); ?>
                                            <span class="text-muted"><?php echo h($s['unit'] ?? 'stk'); ?></span>
                                        </td>

                                        <td class="text-end">
                                            <?php echo number_format($minStockDisp, 0, '.', ''); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($stock)): ?>
                                    <tr><td colspan="5" class="text-muted p-3">Ingen varer opprettet enda.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer small text-muted">
                    <i class="bi bi-flag-fill text-danger me-1"></i> = under minimumslager (kun når <code>Min &gt; 0</code>).
                    Klikk på bilde-thumbnail for å åpne bildet i ny fane.
                </div>
            </div>
        </div>

        <!-- Siste bevegelser -->
        <div class="col-12 col-xxl-5">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div class="fw-semibold">Siste bevegelser</div>
                    <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_movements">
                        Detaljer
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Tid</th>
                                    <th>Vare</th>
                                    <th>Type</th>
                                    <th>Batch</th>
                                    <th class="text-end">Antall</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent as $m): ?>
                                    <?php
                                    $qtyDisp = (int)round((float)$m['qty'], 0);
                                    $batchLabel = trim((string)($m['batch_label'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="small text-muted"><?php echo h($m['occurred_at']); ?></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo h($m['product_name'] ?? '—'); ?></div>
                                            <div class="text-muted small">
                                                <?php echo h($m['product_sku'] ?? ''); ?>
                                                <?php if (!empty($m['reference_type']) || !empty($m['reference_no'])): ?>
                                                    · <?php echo h(trim(($m['reference_type'] ?? '') . ' ' . ($m['reference_no'] ?? ''))); ?>
                                                <?php endif; ?>
                                                <?php if (!empty($m['issued_to'])): ?>
                                                    · <?php echo h($m['issued_to']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($m['type'] === 'IN'): ?>
                                                <span class="badge bg-success">INN</span>
                                            <?php elseif ($m['type'] === 'OUT'): ?>
                                                <span class="badge bg-danger">UT</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?php echo h($m['type']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <?php echo $batchLabel !== '' ? h($batchLabel) : '<span class="text-muted">—</span>'; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php echo number_format($qtyDisp, 0, '.', ''); ?>
                                            <span class="text-muted"><?php echo h($m['product_unit'] ?? ''); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recent)): ?>
                                    <tr><td colspan="5" class="text-muted p-3">Ingen bevegelser enda.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer small text-muted">
                    Uttak/innlevering logges i <code>inv_movements</code>.
                </div>
            </div>
        </div>

        <!-- Handlinger (kun navigasjon) -->
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-semibold">Handlinger</span>
                    <span class="text-muted small">Registrering gjøres på dedikerte sider</span>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-primary" href="/?page=logistikk_receipts">
                            <i class="bi bi-truck me-1"></i> Gå til Varemottak
                        </a>
                        <a class="btn btn-danger" href="/?page=inv_out_shop">
                            <i class="bi bi-box-arrow-up me-1"></i> Gå til Uttak
                        </a>
                    </div>

                    <?php if (!$canWarehouseWrite): ?>
                        <div class="text-muted small mt-2">
                            Du kan åpne sidene, men registrering krever <code>warehouse_write</code> (eller <code>admin</code>).
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>
