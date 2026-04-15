<?php
// public/pages/inv_stocktake.php
//
// "Varetelling":
// - Ta ut varetellingsliste for logisk lager (prosjekt) og/eller fysisk lager (lokasjon)
// - Velg "as-of" dato (tilbake i tid) eller "nå"
// - Liste kan skrives ut eller lastes ned (CSV)
// - Registrer gjennomført varetelling og last opp PDF/bilde som vedlegg
//
// NB: Produkt-ID skal aldri vises i UI/CSV (kun internt for grouping).
//
// Forutsetter tabeller (eksisterende):
// - inv_movements
// - inv_products (valgfritt, men anbefalt for navn/SKU)
// - inv_locations (valgfritt for navn på lokasjon)
// - projects
// - users (+ user_roles)
//
// Ny tabell (autoforsøk): inv_stocktakes
//
// Sikkerhet:
// - Krever warehouse_read/warehouse_write eller admin for å se
// - Krever warehouse_write eller admin for å registrere/opplaste vedlegg

use App\Database;

$pdo = Database::getConnection();

// ---------------------------------------------------------
// Guard: warehouse_read / warehouse_write / admin
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

// Finn user_id + roller
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

if (!$canWarehouseRead) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang til varetelling.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :t
        ");
        $stmt->execute([':t' => $table]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (\Throwable $e) {
        return false;
    }
}
function getColumnsMap(PDO $pdo, string $table): array {
    $map = [];
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($cols as $c) $map[$c['Field']] = true;
    } catch (\Throwable $e) {}
    return $map;
}
function detectFirstExistingColumn(array $colsMap, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (isset($colsMap[$c])) return $c;
    }
    return null;
}
function fmtQtyNoDecimals($v): string {
    return number_format((float)$v, 0, ',', ' ');
}
function fmtDt(?string $dt): string {
    if (!$dt) return '';
    try { return (new DateTime($dt))->format('Y-m-d H:i'); } catch (\Throwable $e) { return (string)$dt; }
}

// ---------------------------------------------------------
// Detect location column in inv_movements
// ---------------------------------------------------------
$movementColsMap = getColumnsMap($pdo, 'inv_movements');

$physicalColCandidates = [
    'location_id',
    'inv_location_id',
    'inv_locations_id',
    'invloc_id',
    'stock_location_id',
    'physical_location_id',
    'warehouse_id',
    'inv_warehouse_id',
];
$physicalMovementCol = detectFirstExistingColumn($movementColsMap, $physicalColCandidates);
$physicalColMissing  = ($physicalMovementCol === null);

// Detect product_id/project_id/work_order_id columns (for safety)
$productIdCol = detectFirstExistingColumn($movementColsMap, ['product_id', 'inv_product_id', 'item_id']);
$projectIdCol = detectFirstExistingColumn($movementColsMap, ['project_id']);
$occurredCol  = detectFirstExistingColumn($movementColsMap, ['occurred_at', 'created_at', 'date', 'moved_at']);
$typeCol      = detectFirstExistingColumn($movementColsMap, ['type', 'movement_type']);
$qtyCol       = detectFirstExistingColumn($movementColsMap, ['qty', 'quantity']);
$woIdCol      = detectFirstExistingColumn($movementColsMap, ['work_order_id']);

// Minimum required columns
$schemaErrors = [];
if (!$productIdCol) $schemaErrors[] = 'Fant ikke product_id-kolonne i inv_movements.';
if (!$qtyCol)       $schemaErrors[] = 'Fant ikke qty/quantity-kolonne i inv_movements.';
if (!$occurredCol)  $schemaErrors[] = 'Fant ikke occurred_at/created_at-kolonne i inv_movements.';
if (!$typeCol)      $schemaErrors[] = 'Fant ikke type-kolonne i inv_movements.';

// ---------------------------------------------------------
// Products meta (optional)
// ---------------------------------------------------------
$productTableExists = tableExists($pdo, 'inv_products');
$productColsMap     = $productTableExists ? getColumnsMap($pdo, 'inv_products') : [];
$productNameCol     = $productTableExists ? detectFirstExistingColumn($productColsMap, ['name', 'product_name', 'title', 'description']) : null;
$productSkuCol      = $productTableExists ? detectFirstExistingColumn($productColsMap, ['sku', 'item_no', 'code', 'product_code']) : null;

// ---------------------------------------------------------
// Ensure inv_stocktakes table (best effort)
// ---------------------------------------------------------
$stocktakeTableExists = tableExists($pdo, 'inv_stocktakes');
$stocktakeTableError  = null;

if (!$stocktakeTableExists) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `inv_stocktakes` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `performed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `as_of_at` datetime NULL DEFAULT NULL,
              `project_id` int unsigned NULL DEFAULT NULL,
              `location_id` int unsigned NULL DEFAULT NULL,
              `include_zero` tinyint(1) NOT NULL DEFAULT 0,
              `note` text NULL,
              `attachment_path` varchar(255) NULL,
              `attachment_original_name` varchar(255) NULL,
              `attachment_mime` varchar(128) NULL,
              `attachment_size` int unsigned NULL,
              `created_by_user_id` int unsigned NULL,
              PRIMARY KEY (`id`),
              KEY `idx_asof` (`as_of_at`),
              KEY `idx_project` (`project_id`),
              KEY `idx_location` (`location_id`),
              KEY `idx_created_by` (`created_by_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ");
        $stocktakeTableExists = tableExists($pdo, 'inv_stocktakes');
    } catch (\Throwable $e) {
        $stocktakeTableError = $e->getMessage();
        $stocktakeTableExists = false;
    }
}

// ---------------------------------------------------------
// Load projects + locations for filters
// ---------------------------------------------------------
$projects = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {}

$locations = [];
if (tableExists($pdo, 'inv_locations')) {
    try {
        $stmt = $pdo->query("
            SELECT id, code, name
            FROM inv_locations
            WHERE is_active = 1
            ORDER BY name, code
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $id   = (int)($r['id'] ?? 0);
            $code = (string)($r['code'] ?? '');
            $name = (string)($r['name'] ?? '');
            if ($id > 0) {
                $label = trim($code) !== '' ? ($code . ' – ' . $name) : $name;
                $locations[] = ['id' => $id, 'name' => $label];
            }
        }
    } catch (\Throwable $e) {}
}

// ---------------------------------------------------------
// Input: filters / report params
// ---------------------------------------------------------
$projectFilterId  = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : 0;
$locationFilterId = isset($_GET['location_id']) && $_GET['location_id'] !== '' ? (int)$_GET['location_id'] : 0;

$asOfMode = $_GET['asof_mode'] ?? 'now'; // now | date
$asOfDate = $_GET['asof_date'] ?? date('Y-m-d');
$includeZero = isset($_GET['include_zero']) && $_GET['include_zero'] === '1';

if ($asOfMode !== 'now' && $asOfMode !== 'date') $asOfMode = 'now';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) $asOfDate = date('Y-m-d');

// as-of timestamp
if ($asOfMode === 'now') {
    $asOfAt = (new DateTime())->format('Y-m-d H:i:s');
    $asOfLabel = 'Nå (' . (new DateTime())->format('Y-m-d H:i') . ')';
} else {
    // end of day
    $asOfAt = $asOfDate . ' 23:59:59';
    $asOfLabel = 'As-of ' . $asOfDate . ' 23:59';
}

$export = $_GET['export'] ?? ''; // csv

// ---------------------------------------------------------
// Handle POST: Register completed stocktake + upload attachment
// ---------------------------------------------------------
$errors = [];
$success = null;

$uploadDirPublic = '/uploads/stocktakes';
$publicRoot = realpath(__DIR__ . '/..'); // public/
$uploadDirFs = ($publicRoot ? $publicRoot : (__DIR__ . '/..')) . $uploadDirPublic; // public/uploads/stocktakes

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWarehouseWrite) {
        $errors[] = 'Du har ikke tilgang til å registrere varetelling.';
    } elseif (!$stocktakeTableExists) {
        $errors[] = 'Tabellen inv_stocktakes finnes ikke og kunne ikke opprettes.';
    } else {
        $postProjectId   = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : 0;
        $postLocationId  = isset($_POST['location_id']) && $_POST['location_id'] !== '' ? (int)$_POST['location_id'] : 0;
        $postIncludeZero = isset($_POST['include_zero']) && $_POST['include_zero'] === '1';
        $postAsOfMode    = $_POST['asof_mode'] ?? 'now';
        $postAsOfDate    = $_POST['asof_date'] ?? date('Y-m-d');
        $postNote        = trim((string)($_POST['note'] ?? ''));

        if ($postAsOfMode !== 'now' && $postAsOfMode !== 'date') $postAsOfMode = 'now';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postAsOfDate)) $postAsOfDate = date('Y-m-d');

        $postAsOfAt = ($postAsOfMode === 'now')
            ? (new DateTime())->format('Y-m-d H:i:s')
            : ($postAsOfDate . ' 23:59:59');

        // Upload validate
        $attPath = null;
        $attOrig = null;
        $attMime = null;
        $attSize = null;

        if (!empty($_FILES['attachment']['name'] ?? '')) {
            $fileErr = (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($fileErr !== UPLOAD_ERR_OK) {
                $errors[] = 'Kunne ikke laste opp vedlegg (feilkode ' . $fileErr . ').';
            } else {
                $tmpName = (string)($_FILES['attachment']['tmp_name'] ?? '');
                $orig    = (string)($_FILES['attachment']['name'] ?? '');
                $size    = (int)($_FILES['attachment']['size'] ?? 0);

                if ($size <= 0) {
                    $errors[] = 'Vedlegg er tomt.';
                } elseif ($size > 15 * 1024 * 1024) {
                    $errors[] = 'Vedlegg er for stort (maks 15 MB).';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file($tmpName) ?: 'application/octet-stream';

                    $allowed = [
                        'application/pdf' => 'pdf',
                        'image/jpeg'      => 'jpg',
                        'image/png'       => 'png',
                        'image/webp'      => 'webp',
                    ];

                    if (!isset($allowed[$mime])) {
                        $errors[] = 'Ugyldig filtype. Tillatt: PDF, JPG, PNG, WEBP.';
                    } else {
                        if (!is_dir($uploadDirFs)) {
                            @mkdir($uploadDirFs, 0775, true);
                        }
                        if (!is_dir($uploadDirFs) || !is_writable($uploadDirFs)) {
                            $errors[] = 'Opplastingsmappe er ikke tilgjengelig: ' . h($uploadDirFs);
                        } else {
                            $ext = $allowed[$mime];
                            $safeBase = bin2hex(random_bytes(16));
                            $filename = 'stocktake_' . date('Ymd_His') . '_' . $safeBase . '.' . $ext;
                            $destFs   = rtrim($uploadDirFs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

                            if (!@move_uploaded_file($tmpName, $destFs)) {
                                $errors[] = 'Kunne ikke flytte opplastet fil.';
                            } else {
                                $attPath = rtrim($uploadDirPublic, '/') . '/' . $filename; // public path
                                $attOrig = $orig;
                                $attMime = $mime;
                                $attSize = $size;
                            }
                        }
                    }
                }
            }
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO inv_stocktakes
                        (performed_at, as_of_at, project_id, location_id, include_zero, note,
                         attachment_path, attachment_original_name, attachment_mime, attachment_size,
                         created_by_user_id)
                    VALUES
                        (NOW(), :asof, :pid, :lid, :iz, :note,
                         :apath, :aorig, :amime, :asize,
                         :uid)
                ");
                $stmt->execute([
                    ':asof'  => $postAsOfAt,
                    ':pid'   => ($postProjectId > 0 ? $postProjectId : null),
                    ':lid'   => ($postLocationId > 0 ? $postLocationId : null),
                    ':iz'    => $postIncludeZero ? 1 : 0,
                    ':note'  => ($postNote !== '' ? $postNote : null),
                    ':apath' => $attPath,
                    ':aorig' => $attOrig,
                    ':amime' => $attMime,
                    ':asize' => $attSize,
                    ':uid'   => ($currentUserId > 0 ? $currentUserId : null),
                ]);

                $success = 'Varetelling er registrert.' . ($attPath ? ' Vedlegg er lastet opp.' : '');
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------
// Build stock list query (as-of)
// ---------------------------------------------------------
$listRows = [];
$listSqlError = null;

// Denne brukes både i UI og CSV (og skal IKKE vise produkt_id)
$showLocationColumn = (!$physicalColMissing && $locationFilterId <= 0);

if (!$schemaErrors) {
    // Sign logic: IN adds, OUT subtracts, else uses qty as-is (best effort)
    $qtyExpr = "
        SUM(
            CASE
                WHEN m.`$typeCol` = 'OUT' THEN -m.`$qtyCol`
                WHEN m.`$typeCol` = 'IN'  THEN  m.`$qtyCol`
                ELSE m.`$qtyCol`
            END
        )
    ";

    // Product select/join (product_id brukes kun internt for grouping)
    $prodJoin   = '';
    $prodSelect = "m.`$productIdCol` AS product_id";
    $prodGroup  = "m.`$productIdCol`";

    if ($productTableExists) {
        $prodJoin = "LEFT JOIN inv_products pr ON pr.id = m.`$productIdCol`";
        $nameSel = $productNameCol ? "pr.`$productNameCol`" : "NULL";
        $skuSel  = $productSkuCol ? "pr.`$productSkuCol`" : "NULL";
        $prodSelect = "
            m.`$productIdCol` AS product_id,
            $skuSel  AS product_sku,
            $nameSel AS product_name
        ";
        $prodGroup = "m.`$productIdCol`, product_sku, product_name";
    }

    // Location select/join
    $locSelect = "";
    $locJoin   = "";
    $locGroup  = "";

    if (!$physicalColMissing) {
        $col = $physicalMovementCol;

        if ($locationFilterId > 0) {
            // spesifikk lokasjon: ikke vis lokasjonskolonne i liste
            $showLocationColumn = false;
        } else {
            // alle lokasjoner: group per lokasjon
            $showLocationColumn = true;
            if (tableExists($pdo, 'inv_locations')) {
                $locJoin = "LEFT JOIN inv_locations l ON l.id = m.`$col`";
                $locSelect = "
                    m.`$col` AS location_id,
                    l.code AS location_code,
                    l.name AS location_name
                ";
                $locGroup = "m.`$col`, location_code, location_name";
            } else {
                $locSelect = "m.`$col` AS location_id";
                $locGroup  = "m.`$col`";
            }
        }
    }

    // WHERE
    $params = [':asof' => $asOfAt];
    $where = "WHERE m.`$occurredCol` <= :asof";

    // Optional: project filter (logisk lager)
    if ($projectFilterId > 0 && $projectIdCol) {
        $where .= " AND m.`$projectIdCol` = :pid";
        $params[':pid'] = $projectFilterId;
    }

    // Optional: location filter
    if ($locationFilterId > 0 && !$physicalColMissing) {
        $col = $physicalMovementCol;
        $where .= " AND m.`$col` = :lid";
        $params[':lid'] = $locationFilterId;
    }

    // Group by
    $groupParts = [];
    $groupParts[] = $prodGroup;
    if ($showLocationColumn && $locGroup) $groupParts[] = $locGroup;

    $groupBy = "GROUP BY " . implode(", ", $groupParts);

    // Having include_zero
    $having = $includeZero ? "" : "HAVING stock_qty <> 0";

    // Order by (bruk navn/sku hvis mulig)
    $orderBy = "ORDER BY " . ($productTableExists ? "product_name, product_sku" : "product_id");
    if ($showLocationColumn) $orderBy .= ", location_id";

    $selectCols = "
        $prodSelect,
        $qtyExpr AS stock_qty
    ";
    if ($showLocationColumn && $locSelect) {
        $selectCols .= ", $locSelect";
    }

    $sqlList = "
        SELECT
            $selectCols
        FROM inv_movements m
        $prodJoin
        $locJoin
        $where
        $groupBy
        $having
        $orderBy
    ";

    try {
        $stmt = $pdo->prepare($sqlList);
        $stmt->execute($params);
        $listRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $listSqlError = $e->getMessage();
        $listRows = [];
    }

    // CSV export (uten produkt_id)
    if ($export === 'csv') {
        // Viktig: sørg for at ingen HTML/layout havner i CSV-responsen
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @ini_set('display_errors', '0');

        $fnParts = ['varetelling'];
        if ($projectFilterId > 0)  $fnParts[] = 'prosjekt_' . $projectFilterId;
        if ($locationFilterId > 0) $fnParts[] = 'lokasjon_' . $locationFilterId;
        $fnParts[] = ($asOfMode === 'now' ? 'now' : $asOfDate);
        $filename = implode('_', $fnParts) . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        echo "\xEF\xBB\xBF"; // BOM for Excel (UTF-8)
        $out = fopen('php://output', 'w');

        $headers = [];
        if ($showLocationColumn) $headers[] = 'Lokasjon';
        $headers[] = 'Produkt';
        $headers[] = 'Systembeholdning';
        $headers[] = 'Telt';
        $headers[] = 'Kommentar';

        fputcsv($out, $headers, ';');

        foreach ($listRows as $r) {
            $row = [];

            if ($showLocationColumn) {
                $locCode = trim((string)($r['location_code'] ?? ''));
                $locName = trim((string)($r['location_name'] ?? ''));
                $locId   = (int)($r['location_id'] ?? 0);
                if ($locName !== '' || $locCode !== '') {
                    $row[] = trim($locCode) !== '' ? ($locCode . ' – ' . $locName) : $locName;
                } else {
                    $row[] = $locId > 0 ? (string)$locId : '';
                }
            }

            // Produktlabel (ingen ID)
            $sku  = trim((string)($r['product_sku'] ?? ''));
            $name = trim((string)($r['product_name'] ?? ''));
            if ($sku !== '' && $name !== '') {
                $row[] = $sku . ' – ' . $name;
            } elseif ($name !== '') {
                $row[] = $name;
            } elseif ($sku !== '') {
                $row[] = $sku;
            } else {
                $row[] = 'Ukjent produkt';
            }

            $row[] = (string)fmtQtyNoDecimals($r['stock_qty'] ?? 0);
            $row[] = '';
            $row[] = '';

            fputcsv($out, $row, ';');
        }

        fclose($out);
        exit;
    }
}

// ---------------------------------------------------------
// Load recent stocktakes (for list)
// ---------------------------------------------------------
$recentStocktakes = [];
if ($stocktakeTableExists) {
    try {
        $stmt = $pdo->query("
            SELECT
                s.*,
                p.name AS project_name,
                l.code AS location_code,
                l.name AS location_name,
                u.username AS created_by
            FROM inv_stocktakes s
            LEFT JOIN projects p ON p.id = s.project_id
            LEFT JOIN inv_locations l ON l.id = s.location_id
            LEFT JOIN users u ON u.id = s.created_by_user_id
            ORDER BY s.id DESC
            LIMIT 50
        ");
        $recentStocktakes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $recentStocktakes = [];
    }
}

$totalLines = count($listRows);

?>
<div class="d-flex align-items-start justify-content-between mt-3">
    <div>
        <h3 class="mb-1">Varetelling</h3>
        <div class="text-muted">
            Ta ut varetellingslister, skriv ut / last ned, og lagre dokumentasjon.
        </div>

        <?php if ($physicalColMissing): ?>
            <div class="alert alert-warning mt-2 mb-0">
                Fysisk lager-filter/listing per lokasjon er begrenset:
                fant ingen lokasjonskolonne i <code>inv_movements</code>.
            </div>
        <?php endif; ?>

        <?php if ($schemaErrors): ?>
            <div class="alert alert-danger mt-2 mb-0">
                <strong>Kan ikke generere liste:</strong><br>
                <?= nl2br(h(implode("\n", $schemaErrors))) ?>
            </div>
        <?php endif; ?>

        <?php if (!$stocktakeTableExists): ?>
            <div class="alert alert-warning mt-2 mb-0">
                <strong>Merk:</strong> Klarte ikke å opprette/finne tabellen <code>inv_stocktakes</code>.
                Registrering av gjennomført varetelling/vedlegg vil ikke fungere.
                <?php if ($stocktakeTableError): ?>
                    <div class="small text-muted mt-1"><?= h($stocktakeTableError) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$canWarehouseWrite): ?>
            <div class="text-muted small mt-1">
                Du har lesetilgang. Registrering/opplasting krever skrivetilgang til varelager.
            </div>
        <?php endif; ?>
    </div>

    <div class="text-end">
        <div class="text-muted">As-of</div>
        <div style="font-size: 1.05rem;">
            <strong><?= h($asOfLabel) ?></strong>
        </div>
        <div class="text-muted small">
            <?= (int)$totalLines ?> linjer
        </div>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success mt-3"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger mt-3">
        <strong>Feil:</strong><br>
        <?= nl2br(h(implode("\n", $errors))) ?>
    </div>
<?php endif; ?>

<!-- Filters / List generation -->
<form class="row g-2 mt-3 mb-3" method="get">
    <input type="hidden" name="page" value="inv_stocktake">

    <div class="col-auto">
        <label class="form-label">Logisk lager (Prosjekt)</label>
        <select name="project_id" class="form-select">
            <option value="">Alle prosjekter</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $projectFilterId === (int)$p['id'] ? 'selected' : '' ?>>
                    <?= h($p['name'] ?? '') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-auto">
        <label class="form-label">Fysisk lager (Lokasjon)</label>
        <select name="location_id" class="form-select" <?= $physicalColMissing ? 'disabled' : '' ?>>
            <option value="">Alle lokasjoner</option>
            <?php foreach ($locations as $l): ?>
                <option value="<?= (int)$l['id'] ?>" <?= $locationFilterId === (int)$l['id'] ? 'selected' : '' ?>>
                    <?= h($l['name'] ?? '') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-auto">
        <label class="form-label">Tidspunkt</label>
        <div class="input-group">
            <select name="asof_mode" class="form-select" style="max-width: 130px;">
                <option value="now"  <?= $asOfMode === 'now' ? 'selected' : '' ?>>Nå</option>
                <option value="date" <?= $asOfMode === 'date' ? 'selected' : '' ?>>Dato</option>
            </select>
            <input type="date" name="asof_date" class="form-control" value="<?= h($asOfDate) ?>" <?= $asOfMode === 'date' ? '' : 'disabled' ?>>
        </div>
        <div class="form-text">Ved dato brukes slutt av dagen (23:59).</div>
    </div>

    <div class="col-auto align-self-end">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="includeZero" name="include_zero" <?= $includeZero ? 'checked' : '' ?>>
            <label class="form-check-label" for="includeZero">Ta med 0-beholdning</label>
        </div>
    </div>

    <div class="col-auto align-self-end">
        <button class="btn btn-primary" <?= $schemaErrors ? 'disabled' : '' ?>>
            <i class="bi bi-list-check me-1"></i> Generer liste
        </button>

        <?php
        $qs = $_GET;
        $qs['page'] = 'inv_stocktake';
        $qs['export'] = 'csv';
        $csvUrl = '/?' . http_build_query($qs);
        ?>

        <a class="btn btn-outline-secondary <?= ($schemaErrors ? 'disabled' : '') ?>"
           href="<?= $schemaErrors ? '#' : h($csvUrl) ?>">
            <i class="bi bi-download me-1"></i> Last ned CSV
        </a>

        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Skriv ut
        </button>

        <a class="btn btn-outline-secondary" href="/?page=inv_stocktake">
            Nullstill
        </a>
    </div>
</form>

<script>
    document.addEventListener('change', function(e) {
        if (e.target && e.target.name === 'asof_mode') {
            const dateInput = document.querySelector('input[name="asof_date"]');
            if (!dateInput) return;
            dateInput.disabled = (e.target.value !== 'date');
        }
    });
</script>

<?php if ($listSqlError): ?>
    <div class="alert alert-danger">
        <strong>SQL-feil:</strong><br>
        <?= h($listSqlError) ?>
    </div>
<?php endif; ?>

<!-- Stock list -->
<div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
        <thead>
        <tr>
            <?php if ($showLocationColumn): ?>
                <th>Lokasjon</th>
            <?php endif; ?>
            <th>Produkt</th>
            <th class="text-end" style="white-space:nowrap;">Systembeholdning</th>
            <th class="text-end" style="white-space:nowrap;">Telt</th>
            <th>Kommentar</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$listRows && !$schemaErrors): ?>
            <tr>
                <td colspan="<?= $showLocationColumn ? 5 : 4 ?>" class="text-muted">
                    Ingen linjer (tomt eller filtrert bort).
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($listRows as $r): ?>
                <?php
                $locLabel = '';
                if ($showLocationColumn) {
                    $locCode = trim((string)($r['location_code'] ?? ''));
                    $locName = trim((string)($r['location_name'] ?? ''));
                    $locId   = (int)($r['location_id'] ?? 0);
                    if ($locName !== '' || $locCode !== '') {
                        $locLabel = trim($locCode) !== '' ? ($locCode . ' – ' . $locName) : $locName;
                    } else {
                        $locLabel = $locId > 0 ? (string)$locId : '';
                    }
                }

                // Produktlabel (ingen ID)
                $sku  = trim((string)($r['product_sku'] ?? ''));
                $name = trim((string)($r['product_name'] ?? ''));
                if ($sku !== '' && $name !== '') {
                    $productLabel = $sku . ' – ' . $name;
                } elseif ($name !== '') {
                    $productLabel = $name;
                } elseif ($sku !== '') {
                    $productLabel = $sku;
                } else {
                    $productLabel = 'Ukjent produkt';
                }
                ?>
                <tr>
                    <?php if ($showLocationColumn): ?>
                        <td><?= h($locLabel) ?></td>
                    <?php endif; ?>

                    <td><?= h($productLabel) ?></td>

                    <td class="text-end"><strong><?= fmtQtyNoDecimals($r['stock_qty'] ?? 0) ?></strong></td>
                    <td class="text-end" style="color:#999;">&nbsp;</td>
                    <td style="color:#999;">&nbsp;</td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<hr class="my-4">

<!-- Register completed stocktake -->
<div class="d-flex align-items-start justify-content-between">
    <div>
        <h4 class="mb-1">Registrer gjennomført varetelling</h4>
        <div class="text-muted">Last opp PDF/bilde som dokumentasjon og lagre referanse.</div>
    </div>
</div>

<form class="row g-2 mt-2" method="post" enctype="multipart/form-data">
    <div class="col-auto">
        <label class="form-label">Logisk lager (Prosjekt)</label>
        <select name="project_id" class="form-select" <?= !$canWarehouseWrite ? 'disabled' : '' ?>>
            <option value="">Alle prosjekter</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $projectFilterId === (int)$p['id'] ? 'selected' : '' ?>>
                    <?= h($p['name'] ?? '') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-auto">
        <label class="form-label">Fysisk lager (Lokasjon)</label>
        <select name="location_id" class="form-select" <?= ($physicalColMissing || !$canWarehouseWrite) ? 'disabled' : '' ?>>
            <option value="">Alle lokasjoner</option>
            <?php foreach ($locations as $l): ?>
                <option value="<?= (int)$l['id'] ?>" <?= $locationFilterId === (int)$l['id'] ? 'selected' : '' ?>>
                    <?= h($l['name'] ?? '') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-auto">
        <label class="form-label">Tidspunkt (as-of)</label>
        <div class="input-group">
            <select name="asof_mode" class="form-select" style="max-width: 130px;" <?= !$canWarehouseWrite ? 'disabled' : '' ?>>
                <option value="now"  <?= $asOfMode === 'now' ? 'selected' : '' ?>>Nå</option>
                <option value="date" <?= $asOfMode === 'date' ? 'selected' : '' ?>>Dato</option>
            </select>
            <input type="date" name="asof_date" class="form-control" value="<?= h($asOfDate) ?>" <?= (!$canWarehouseWrite || $asOfMode !== 'date') ? 'disabled' : '' ?>>
        </div>
    </div>

    <div class="col-auto align-self-end">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="includeZero2" name="include_zero" <?= $includeZero ? 'checked' : '' ?> <?= !$canWarehouseWrite ? 'disabled' : '' ?>>
            <label class="form-check-label" for="includeZero2">Ta med 0-beholdning</label>
        </div>
    </div>

    <div class="col-12">
        <label class="form-label">Notat</label>
        <textarea class="form-control" name="note" rows="2" placeholder="F.eks. hvem telte, avvik, kommentar..." <?= !$canWarehouseWrite ? 'disabled' : '' ?>></textarea>
    </div>

    <div class="col-12">
        <label class="form-label">Vedlegg (PDF eller bilde)</label>
        <input type="file" class="form-control" name="attachment" accept="application/pdf,image/jpeg,image/png,image/webp" <?= !$canWarehouseWrite ? 'disabled' : '' ?>>
        <div class="form-text">Tillatt: PDF, JPG, PNG, WEBP. Maks 15 MB.</div>
    </div>

    <div class="col-12">
        <button class="btn btn-success" <?= (!$canWarehouseWrite || !$stocktakeTableExists) ? 'disabled' : '' ?>>
            <i class="bi bi-check2-circle me-1"></i> Lagre varetelling
        </button>
    </div>
</form>

<?php if ($recentStocktakes): ?>
    <hr class="my-4">
    <h5 class="mb-2">Siste varetellinger</h5>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
            <tr>
                <th>ID</th>
                <th>Utført</th>
                <th>As-of</th>
                <th>Prosjekt</th>
                <th>Lokasjon</th>
                <th>0-linjer</th>
                <th>Vedlegg</th>
                <th>Opprettet av</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recentStocktakes as $s): ?>
                <?php
                $locCode = trim((string)($s['location_code'] ?? ''));
                $locName = trim((string)($s['location_name'] ?? ''));
                $locLabel = '';
                if ($locName !== '' || $locCode !== '') {
                    $locLabel = trim($locCode) !== '' ? ($locCode . ' – ' . $locName) : $locName;
                } elseif (!empty($s['location_id'])) {
                    $locLabel = (string)((int)$s['location_id']);
                }
                $attPath = (string)($s['attachment_path'] ?? '');
                $attName = (string)($s['attachment_original_name'] ?? '');
                ?>
                <tr>
                    <td>#<?= (int)$s['id'] ?></td>
                    <td><?= h(fmtDt($s['performed_at'] ?? null)) ?></td>
                    <td><?= h(fmtDt($s['as_of_at'] ?? null)) ?></td>
                    <td><?= h((string)($s['project_name'] ?? '')) ?></td>
                    <td><?= h($locLabel) ?></td>
                    <td><?= (int)($s['include_zero'] ?? 0) === 1 ? 'Ja' : 'Nei' ?></td>
                    <td>
                        <?php if ($attPath): ?>
                            <a href="<?= h($attPath) ?>" target="_blank" rel="noopener">
                                <i class="bi bi-paperclip me-1"></i><?= h($attName !== '' ? $attName : basename($attPath)) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">–</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h((string)($s['created_by'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<style>
@media print {
    .btn, form, .alert, hr { display: none !important; }
    .table { font-size: 12px; }
    h3, h4, h5 { margin-top: 0; }
}
</style>
