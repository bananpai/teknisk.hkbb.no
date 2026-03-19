<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\pages\inv_reports.php
//
// Varelager – Uttakrapport (OUT)
// - Summerer belastning basert på inv_movements.qty * (enhetspris)
// - Enhetspris hentes primært fra inv_movements.unit_price
// - Hvis inv_movements.unit_price = 0, men batch_id finnes: fall back til inv_batches.unit_price
//
// VIKTIG FIFO:
// - For 100% FIFO-korrekt økonomirapportering må OUT-linjer postes med batch_id + korrekt unit_price.
// - Hvis OUT-linje mangler batch_id og unit_price=0/NULL blir belastning 0 i rapporten (og det varsles i UI)
//
// NYTT (Salg/Faktura/Kunde/ERP-prosjekt):
// - Detekterer relevante kolonner/tabeller og viser disse i "Detaljert" + CSV.
// - Robust fallback: hvis tabeller/kolonner ikke finnes, vises tomme felt.
//
// NYTT (Prosjektdimensjoner):
// - Viser tydelig:
//   1) Fra-prosjekt (kilde / lagerprosjekt)  -> typisk batch.project_id eller egen source_project_id på movement
//   2) Belastes-prosjekt (uttaksprosjekt)    -> typisk m.project_id eller wo.project_id
// - Viser også prosjektnummer (projects.project_no hvis finnes) for begge.
//
// OPPDATERT (Arbeidsordre):
// - Viser arbeidsordrenummer (work_orders.work_order_no) i oversikt og detaljert når work_order_id finnes.
// - Faller tilbake til arbeidsordretekst utledet fra inv_movements.note når work_order_id er NULL.
// - Dette gjør at uttak fra Workforce-flyten (løsning A) også vises i rapporten.
//
// NOTE-format som støttes:
// - "... / WO HFA-1234"
// - "... / WO HFA-1234 – Beskrivelse"
// - "... / WO HFA-1234 - Beskrivelse"
// - Teksten klippes før " | " hvis notatdelen kommer etterpå.

use App\Database;

$pageTitle = 'Varelager – Uttakrapport';

$pdo = Database::getConnection();

// ---------------------------------------------------------
// Rolle-guard (user_roles) + bakoverkompatibel admin-fallback
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til varelager-rapporter.
    </div>
    <?php
    return;
}

$isAdmin = (bool)($_SESSION['is_admin'] ?? false);
if ($username === 'rsv') $isAdmin = true;

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
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til varelager-rapporter.
    </div>
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
    } catch (\Throwable $e) {
        $map = [];
    }
    return $map;
}

function detectFirstExistingColumn(array $colsMap, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (isset($colsMap[$c])) return $c;
    }
    return null;
}

function detectFirstExistingTable(PDO $pdo, array $candidates): ?string {
    foreach ($candidates as $t) {
        if (tableExists($pdo, $t)) return $t;
    }
    return null;
}

function fmtAmount(float $v): string {
    return number_format($v, 2, ',', ' ');
}
function fmtQtyNoDecimals($v): string {
    return number_format((float)$v, 0, ',', ' ');
}
function fmtDateTime(?string $dt): string {
    if (!$dt) return '';
    try {
        $d = new DateTime($dt);
        return $d->format('Y-m-d H:i');
    } catch (\Throwable $e) {
        return (string)$dt;
    }
}
function projectLabel(?string $no, ?string $name, ?int $id = null): string {
    $no   = trim((string)$no);
    $name = trim((string)$name);

    if ($no !== '' && $name !== '') return $no . ' – ' . $name;
    if ($name !== '') return $name;
    if ($no !== '') return $no;
    if ($id !== null && $id > 0) return '#' . $id;
    return '—';
}

// ---------------------------------------------------------
// Finn lokasjonskolonne i inv_movements
// ---------------------------------------------------------
$movementColsMap = getColumnsMap($pdo, 'inv_movements');

$physicalColCandidates = [
    'physical_location_id',
    'location_id',
    'inv_location_id',
    'inv_locations_id',
    'invloc_id',
    'stock_location_id',
    'physicalLocationId',
    'warehouse_id',
    'inv_warehouse_id',
];

$physicalMovementCol = detectFirstExistingColumn($movementColsMap, $physicalColCandidates);
$physicalColMissing  = ($physicalMovementCol === null);

// ---------------------------------------------------------
// Prosjektkolonner
// ---------------------------------------------------------
$projectsTableExists = tableExists($pdo, 'projects');
$projectsColsMap     = $projectsTableExists ? getColumnsMap($pdo, 'projects') : [];

$projectNameCol = $projectsTableExists ? detectFirstExistingColumn($projectsColsMap, [
    'name', 'project_name', 'title'
]) : null;

$projectNoCol = $projectsTableExists ? detectFirstExistingColumn($projectsColsMap, [
    'project_no', 'project_number', 'project_code', 'code', 'no', 'number'
]) : null;

// ---------------------------------------------------------
// Produkt
// ---------------------------------------------------------
$productTableExists = tableExists($pdo, 'inv_products');
$productColsMap     = $productTableExists ? getColumnsMap($pdo, 'inv_products') : [];

$productNameCol = $productTableExists ? detectFirstExistingColumn($productColsMap, [
    'name', 'product_name', 'title', 'description'
]) : null;

$productSkuCol = $productTableExists ? detectFirstExistingColumn($productColsMap, [
    'sku', 'item_no', 'code', 'product_code'
]) : null;

// ---------------------------------------------------------
// Batch
// ---------------------------------------------------------
$batchTableExists = tableExists($pdo, 'inv_batches');

// ---------------------------------------------------------
// Fra-/til-prosjekt
// ---------------------------------------------------------
$sourceProjectCol = detectFirstExistingColumn($movementColsMap, [
    'source_project_id',
    'from_project_id',
    'project_from_id',
    'project_source_id',
    'src_project_id',
    'warehouse_project_id',
    'lager_project_id',
]);

$batchColsMap = $batchTableExists ? getColumnsMap($pdo, 'inv_batches') : [];
$batchProjectCol = $batchTableExists ? detectFirstExistingColumn($batchColsMap, [
    'project_id', 'lager_project_id', 'warehouse_project_id'
]) : null;

// ---------------------------------------------------------
// Arbeidsordre
// ---------------------------------------------------------
$workOrdersTableExists = tableExists($pdo, 'work_orders');
$workOrderColsMap      = $workOrdersTableExists ? getColumnsMap($pdo, 'work_orders') : [];

$workOrderNoCol = $workOrdersTableExists ? detectFirstExistingColumn($workOrderColsMap, [
    'work_order_no', 'workorder_no', 'wo_no', 'order_no', 'number', 'no'
]) : null;

$workOrderTitleCol = $workOrdersTableExists ? detectFirstExistingColumn($workOrderColsMap, [
    'title', 'name', 'subject', 'description'
]) : null;

// ---------------------------------------------------------
// Salg / Faktura / Kunde / ERP
// ---------------------------------------------------------
$saleFlagCol = detectFirstExistingColumn($movementColsMap, [
    'is_sale', 'for_sale', 'sale', 'is_sales', 'sales', 'is_salg', 'for_salg'
]);

$salesRefCol = detectFirstExistingColumn($movementColsMap, [
    'sales_order_id', 'sales_order_no', 'salesorder_id', 'salesorder_no',
    'so_id', 'so_no', 'ordre_id', 'ordre_no', 'order_id', 'order_no'
]);

$invoiceIdCol = detectFirstExistingColumn($movementColsMap, [
    'invoice_id', 'faktura_id', 'invoiceId'
]);
$invoiceNoCol = detectFirstExistingColumn($movementColsMap, [
    'invoice_no', 'invoice_number', 'faktura_no', 'fakturanr', 'faktura_nr', 'invoiceNo'
]);

$customerIdCol = detectFirstExistingColumn($movementColsMap, [
    'customer_id', 'kunde_id', 'client_id', 'customerId', 'kundeId'
]);
$customerNameOnMovementCol = detectFirstExistingColumn($movementColsMap, [
    'customer_name', 'kunde_navn', 'kunde', 'customer'
]);

$erpProjectIdCol = detectFirstExistingColumn($movementColsMap, [
    'erp_project_id', 'external_project_id', 'erpProjectId'
]);
$erpProjectNoCol = detectFirstExistingColumn($movementColsMap, [
    'erp_project_no', 'erp_project_number', 'external_project_no', 'erpProjectNo'
]);
$erpProjectNameOnMovementCol = detectFirstExistingColumn($movementColsMap, [
    'erp_project_name', 'external_project_name', 'erpProjectName'
]);

$invoiceTable = detectFirstExistingTable($pdo, [
    'invoices', 'erp_invoices', 'sales_invoices', 'accounting_invoices'
]);
$invoiceColsMap = $invoiceTable ? getColumnsMap($pdo, $invoiceTable) : [];
$invoiceNumberCol = $invoiceTable ? detectFirstExistingColumn($invoiceColsMap, [
    'invoice_no', 'invoice_number', 'number', 'faktura_no', 'fakturanr'
]) : null;
$invoiceCustomerIdCol = $invoiceTable ? detectFirstExistingColumn($invoiceColsMap, [
    'customer_id', 'kunde_id', 'client_id'
]) : null;
$invoiceErpProjectIdCol = $invoiceTable ? detectFirstExistingColumn($invoiceColsMap, [
    'erp_project_id', 'external_project_id', 'project_id'
]) : null;

$customerTable = detectFirstExistingTable($pdo, [
    'customers', 'erp_customers', 'clients', 'crm_customers'
]);
$customerColsMap = $customerTable ? getColumnsMap($pdo, $customerTable) : [];
$customerNameCol = $customerTable ? detectFirstExistingColumn($customerColsMap, [
    'name', 'customer_name', 'company', 'kunde_navn', 'title'
]) : null;
$customerNoCol = $customerTable ? detectFirstExistingColumn($customerColsMap, [
    'customer_no', 'customer_number', 'kunde_no', 'kundenr', 'account_no'
]) : null;

$erpProjectTable = detectFirstExistingTable($pdo, [
    'erp_projects', 'external_projects', 'projects_erp'
]);
$erpProjectColsMap = $erpProjectTable ? getColumnsMap($pdo, $erpProjectTable) : [];
$erpProjectTableNoCol = $erpProjectTable ? detectFirstExistingColumn($erpProjectColsMap, [
    'project_no', 'project_number', 'code', 'erp_project_no'
]) : null;
$erpProjectTableNameCol = $erpProjectTable ? detectFirstExistingColumn($erpProjectColsMap, [
    'name', 'project_name', 'title'
]) : null;

// ---------------------------------------------------------
// Input
// ---------------------------------------------------------
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

$mode = $_GET['mode'] ?? 'project';

$projectFilterId     = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : 0;
$fromProjectFilterId = isset($_GET['from_project_id']) && $_GET['from_project_id'] !== '' ? (int)$_GET['from_project_id'] : 0;
$locationFilterId    = isset($_GET['location_id']) && $_GET['location_id'] !== '' ? (int)$_GET['location_id'] : 0;
$productFilterId     = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int)$_GET['product_id'] : 0;
$workOrderFilterId   = isset($_GET['work_order_id']) && $_GET['work_order_id'] !== '' ? (int)$_GET['work_order_id'] : 0;

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5000;
if ($limit < 100) $limit = 100;
if ($limit > 20000) $limit = 20000;

$export = (string)($_GET['export'] ?? '');

$allowedModes = ['project', 'workorder', 'day', 'product', 'location', 'detailed'];
if (!in_array($mode, $allowedModes, true)) $mode = 'project';
if ($mode === 'location' && $physicalColMissing) $mode = 'project';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$fromDt = $from . ' 00:00:00';
$toPlus = (new DateTime($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

// ---------------------------------------------------------
// Dropdown: prosjekter
// ---------------------------------------------------------
$projects = [];
try {
    if ($projectsTableExists) {
        if ($projectNoCol) {
            $stmt = $pdo->query("SELECT id, `$projectNoCol` AS project_no, name FROM projects ORDER BY `$projectNoCol`, name");
        } else {
            $stmt = $pdo->query("SELECT id, NULL AS project_no, name FROM projects ORDER BY name");
        }
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (\Throwable $e) {
    $projects = [];
}

// ---------------------------------------------------------
// Dropdown: lokasjoner
// ---------------------------------------------------------
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
    } catch (\Throwable $e) {
        $locations = [];
    }
}

if (!$locations && !$physicalColMissing) {
    try {
        $col = $physicalMovementCol;
        $stmt = $pdo->query("
            SELECT DISTINCT `$col` AS id
            FROM inv_movements
            WHERE `$col` IS NOT NULL
            ORDER BY `$col`
        ");
        $tmp = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($tmp as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id > 0) $locations[] = ['id' => $id, 'name' => (string)$id];
        }
    } catch (\Throwable $e) {
        $locations = [];
    }
}

// ---------------------------------------------------------
// Query bygging
// ---------------------------------------------------------
$params = [
    ':fromDt' => $fromDt,
    ':toPlus' => $toPlus,
];

$batchJoinSql = $batchTableExists ? " LEFT JOIN inv_batches b ON b.id = m.batch_id " : "";

$toProjectExpr = "COALESCE(m.project_id, wo.project_id)";

$fromProjectExpr = "NULL";
if ($sourceProjectCol) {
    $fromProjectExpr = "m.`$sourceProjectCol`";
} elseif ($batchTableExists && $batchProjectCol) {
    $fromProjectExpr = "b.`$batchProjectCol`";
}

// ---------------------------------------------------------
// Arbeidsordre-tekst fallback fra note
// ---------------------------------------------------------
// 1) finn delen etter '/ WO '
// 2) klipp før ' | ' hvis notat kommer etterpå
$workOrderNoteTailExpr = "
    CASE
        WHEN m.note IS NOT NULL AND INSTR(m.note, '/ WO ') > 0
            THEN TRIM(SUBSTRING(m.note, INSTR(m.note, '/ WO ') + 5))
        ELSE NULL
    END
";

$workOrderNoteLabelExpr = "
    CASE
        WHEN ($workOrderNoteTailExpr) IS NULL OR ($workOrderNoteTailExpr) = ''
            THEN NULL
        WHEN INSTR(($workOrderNoteTailExpr), ' | ') > 0
            THEN TRIM(SUBSTRING_INDEX(($workOrderNoteTailExpr), ' | ', 1))
        ELSE TRIM(($workOrderNoteTailExpr))
    END
";

$woNoSelect = $workOrderNoCol ? "wo.`$workOrderNoCol` AS work_order_no," : "NULL AS work_order_no,";
$woTitleSelect = $workOrderTitleCol ? "wo.`$workOrderTitleCol` AS work_order_title," : "NULL AS work_order_title,";
$woFallbackSelect = "$workOrderNoteLabelExpr AS work_order_fallback_label,";

$workOrderDisplayExpr = "
    CASE
        WHEN wo.id IS NOT NULL AND NULLIF(TRIM(COALESCE(" . ($workOrderNoCol ? "wo.`$workOrderNoCol`" : "''") . ", '')), '') IS NOT NULL
            THEN CONCAT(
                TRIM(COALESCE(" . ($workOrderNoCol ? "wo.`$workOrderNoCol`" : "''") . ", '')),
                CASE
                    WHEN NULLIF(TRIM(COALESCE(" . ($workOrderTitleCol ? "wo.`$workOrderTitleCol`" : "''") . ", '')), '') IS NOT NULL
                        THEN CONCAT(' – ', TRIM(COALESCE(" . ($workOrderTitleCol ? "wo.`$workOrderTitleCol`" : "''") . ", '')))
                    ELSE ''
                END
            )
        WHEN wo.id IS NOT NULL
            THEN CONCAT(
                '#', wo.id,
                CASE
                    WHEN NULLIF(TRIM(COALESCE(" . ($workOrderTitleCol ? "wo.`$workOrderTitleCol`" : "''") . ", '')), '') IS NOT NULL
                        THEN CONCAT(' – ', TRIM(COALESCE(" . ($workOrderTitleCol ? "wo.`$workOrderTitleCol`" : "''") . ", '')))
                    ELSE ''
                END
            )
        ELSE $workOrderNoteLabelExpr
    END
";

$commonJoinSql = "
    $batchJoinSql
    LEFT JOIN work_orders wo ON wo.id = m.work_order_id
    LEFT JOIN projects p_to   ON p_to.id = $toProjectExpr
    LEFT JOIN projects p_from ON p_from.id = $fromProjectExpr
";

$locationJoinSql = '';
if (!$physicalColMissing && tableExists($pdo, 'inv_locations')) {
    $col = $physicalMovementCol;
    $locationJoinSql = " LEFT JOIN inv_locations l ON l.id = m.`$col` ";
}

$effectiveUnitPriceExpr = $batchTableExists
    ? "COALESCE(NULLIF(m.unit_price, 0), b.unit_price, 0)"
    : "COALESCE(m.unit_price, 0)";

$baseWhere = "
    WHERE m.type = 'OUT'
      AND m.occurred_at >= :fromDt
      AND m.occurred_at <  :toPlus
";

if (!$physicalColMissing && $locationFilterId > 0) {
    $col = $physicalMovementCol;
    $baseWhere .= " AND m.`$col` = :locationId ";
    $params[':locationId'] = $locationFilterId;
}

if ($projectFilterId > 0) {
    $baseWhere .= " AND $toProjectExpr = :toProjectId ";
    $params[':toProjectId'] = $projectFilterId;
}

if ($fromProjectFilterId > 0) {
    $baseWhere .= " AND $fromProjectExpr = :fromProjectId ";
    $params[':fromProjectId'] = $fromProjectFilterId;
}

if ($productFilterId > 0) {
    $baseWhere .= " AND m.product_id = :productId ";
    $params[':productId'] = $productFilterId;
}

if ($workOrderFilterId > 0) {
    $baseWhere .= " AND m.work_order_id = :woId ";
    $params[':woId'] = $workOrderFilterId;
}

$toProjectNoSelect   = $projectNoCol ? "p_to.`$projectNoCol` AS to_project_no," : "NULL AS to_project_no,";
$fromProjectNoSelect = $projectNoCol ? "p_from.`$projectNoCol` AS from_project_no," : "NULL AS from_project_no,";

$sql = "";

if ($mode === 'project') {
    $sql = "
        SELECT
            p_from.id   AS from_project_id,
            $fromProjectNoSelect
            p_from.name AS from_project_name,

            p_to.id   AS to_project_id,
            $toProjectNoSelect
            p_to.name AS to_project_name,

            SUM(m.qty * $effectiveUnitPriceExpr) AS total_amount,
            SUM(m.qty)                           AS total_qty,
            COUNT(*)                             AS movements
        FROM inv_movements m
        $commonJoinSql
        $baseWhere
        GROUP BY
            p_from.id, from_project_no, p_from.name,
            p_to.id,   to_project_no,   p_to.name
        ORDER BY total_amount DESC
    ";
}

if ($mode === 'workorder') {
    $sql = "
        SELECT
            p_from.id   AS from_project_id,
            $fromProjectNoSelect
            p_from.name AS from_project_name,

            p_to.id   AS to_project_id,
            $toProjectNoSelect
            p_to.name AS to_project_name,

            m.work_order_id AS work_order_id,
            $woNoSelect
            $woTitleSelect
            $woFallbackSelect
            $workOrderDisplayExpr AS work_order_display,

            SUM(m.qty * $effectiveUnitPriceExpr) AS total_amount,
            SUM(m.qty)                           AS total_qty,
            COUNT(*)                             AS movements
        FROM inv_movements m
        $commonJoinSql
        $baseWhere
          AND ($workOrderDisplayExpr IS NOT NULL AND TRIM($workOrderDisplayExpr) <> '')
        GROUP BY
            p_from.id, from_project_no, p_from.name,
            p_to.id,   to_project_no,   p_to.name,
            m.work_order_id, work_order_no, work_order_title, work_order_fallback_label, work_order_display
        ORDER BY to_project_name, work_order_display, total_amount DESC
    ";
}

if ($mode === 'day') {
    $sql = "
        SELECT
            DATE(m.occurred_at) AS day,

            p_from.id   AS from_project_id,
            $fromProjectNoSelect
            p_from.name AS from_project_name,

            p_to.id   AS to_project_id,
            $toProjectNoSelect
            p_to.name AS to_project_name,

            SUM(m.qty * $effectiveUnitPriceExpr) AS total_amount,
            SUM(m.qty)                           AS total_qty,
            COUNT(*)                             AS movements
        FROM inv_movements m
        $commonJoinSql
        $baseWhere
        GROUP BY
            DATE(m.occurred_at),
            p_from.id, from_project_no, p_from.name,
            p_to.id,   to_project_no,   p_to.name
        ORDER BY day DESC, total_amount DESC
    ";
}

if ($mode === 'product') {
    $prodJoin   = '';
    $prodSelect = " m.product_id AS product_id ";
    $prodGroup  = " m.product_id ";

    if ($productTableExists) {
        $prodJoin = " LEFT JOIN inv_products pr ON pr.id = m.product_id ";
        $nameSel = $productNameCol ? "pr.`$productNameCol`" : "NULL";
        $skuSel  = $productSkuCol ? "pr.`$productSkuCol`" : "NULL";
        $prodSelect = "
            m.product_id AS product_id,
            $nameSel AS product_name,
            $skuSel  AS product_sku
        ";
        $prodGroup = " m.product_id, product_name, product_sku ";
    }

    $sql = "
        SELECT
            p_from.id   AS from_project_id,
            $fromProjectNoSelect
            p_from.name AS from_project_name,

            p_to.id   AS to_project_id,
            $toProjectNoSelect
            p_to.name AS to_project_name,

            $prodSelect,
            SUM(m.qty * $effectiveUnitPriceExpr) AS total_amount,
            SUM(m.qty)                           AS total_qty,
            COUNT(*)                             AS movements
        FROM inv_movements m
        $commonJoinSql
        $prodJoin
        $baseWhere
        GROUP BY
            p_from.id, from_project_no, p_from.name,
            p_to.id,   to_project_no,   p_to.name,
            $prodGroup
        ORDER BY to_project_name, total_amount DESC
    ";
}

if ($mode === 'location' && !$physicalColMissing) {
    $locationLabelSel = '';
    $locationLabelGrp = '';

    if (tableExists($pdo, 'inv_locations')) {
        $locationLabelSel = " l.name AS location_name, l.code AS location_code, ";
        $locationLabelGrp = " l.name, l.code, ";
    }

    $col = $physicalMovementCol;

    $sql = "
        SELECT
            p_from.id   AS from_project_id,
            $fromProjectNoSelect
            p_from.name AS from_project_name,

            p_to.id   AS to_project_id,
            $toProjectNoSelect
            p_to.name AS to_project_name,

            $locationLabelSel
            m.`$col` AS location_id,

            SUM(m.qty * $effectiveUnitPriceExpr) AS total_amount,
            SUM(m.qty)                           AS total_qty,
            COUNT(*)                             AS movements
        FROM inv_movements m
        $commonJoinSql
        $locationJoinSql
        $baseWhere
        GROUP BY
            p_from.id, from_project_no, p_from.name,
            p_to.id,   to_project_no,   p_to.name,
            $locationLabelGrp m.`$col`
        ORDER BY to_project_name, total_amount DESC
    ";
}

if ($mode === 'detailed') {
    $prodJoin   = '';
    $prodSelect = " m.product_id AS product_id ";
    if ($productTableExists) {
        $prodJoin = " LEFT JOIN inv_products pr ON pr.id = m.product_id ";
        $nameSel = $productNameCol ? "pr.`$productNameCol`" : "NULL";
        $skuSel  = $productSkuCol ? "pr.`$productSkuCol`" : "NULL";
        $prodSelect = "
            m.product_id AS product_id,
            $nameSel AS product_name,
            $skuSel  AS product_sku
        ";
    }

    $locSelect = "";
    if (!$physicalColMissing) {
        $col = $physicalMovementCol;
        if (tableExists($pdo, 'inv_locations')) {
            $locSelect = "
                l.name AS location_name,
                l.code AS location_code,
                m.`$col` AS location_id,
            ";
        } else {
            $locSelect = " m.`$col` AS location_id, ";
        }
    }

    $batchSelect = "";
    if ($batchTableExists) {
        $batchSelect = "
            m.batch_id,
            b.received_at AS batch_received_at,
            b.unit_price  AS batch_unit_price,
        ";
    } else {
        $batchSelect = " m.batch_id, ";
    }

    $invoiceJoinSql = '';
    if ($invoiceTable && $invoiceIdCol) {
        $invoiceJoinSql = " LEFT JOIN `$invoiceTable` i ON i.id = m.`$invoiceIdCol` ";
    }

    $customerJoinSql = '';
    $customerIdExprForJoin = null;
    if ($customerIdCol) {
        $customerIdExprForJoin = "m.`$customerIdCol`";
    } elseif ($invoiceTable && $invoiceCustomerIdCol) {
        $customerIdExprForJoin = "i.`$invoiceCustomerIdCol`";
    }
    if ($customerTable && $customerIdExprForJoin) {
        $customerJoinSql = " LEFT JOIN `$customerTable` c ON c.id = $customerIdExprForJoin ";
    }

    $erpProjectJoinSql = '';
    $erpProjectIdExprForJoin = null;
    if ($erpProjectIdCol) {
        $erpProjectIdExprForJoin = "m.`$erpProjectIdCol`";
    } elseif ($invoiceTable && $invoiceErpProjectIdCol) {
        $erpProjectIdExprForJoin = "i.`$invoiceErpProjectIdCol`";
    }
    if ($erpProjectTable && $erpProjectIdExprForJoin) {
        $erpProjectJoinSql = " LEFT JOIN `$erpProjectTable` ep ON ep.id = $erpProjectIdExprForJoin ";
    }

    $saleSelect = $saleFlagCol ? "m.`$saleFlagCol` AS is_sale," : "NULL AS is_sale,";
    $salesRefSelect = $salesRefCol ? "m.`$salesRefCol` AS sales_ref," : "NULL AS sales_ref,";

    $invoiceLabelExprParts = [];
    if ($invoiceNoCol) $invoiceLabelExprParts[] = "NULLIF(m.`$invoiceNoCol`, '')";
    if ($invoiceTable && $invoiceNumberCol) $invoiceLabelExprParts[] = "NULLIF(i.`$invoiceNumberCol`, '')";
    if ($invoiceIdCol) $invoiceLabelExprParts[] = "CAST(m.`$invoiceIdCol` AS CHAR)";
    if ($invoiceTable) $invoiceLabelExprParts[] = "CAST(i.id AS CHAR)";
    $invoiceLabelExpr = $invoiceLabelExprParts ? ("COALESCE(" . implode(", ", $invoiceLabelExprParts) . ", '')") : "''";

    $customerLabelExprParts = [];
    if ($customerNameOnMovementCol) $customerLabelExprParts[] = "NULLIF(m.`$customerNameOnMovementCol`, '')";
    if ($customerTable && $customerNameCol) {
        if ($customerNoCol) {
            $customerLabelExprParts[] = "NULLIF(CONCAT(TRIM(COALESCE(c.`$customerNoCol`, '')), CASE WHEN TRIM(COALESCE(c.`$customerNoCol`, ''))<>'' THEN ' – ' ELSE '' END, TRIM(COALESCE(c.`$customerNameCol`, ''))), '')";
        } else {
            $customerLabelExprParts[] = "NULLIF(TRIM(COALESCE(c.`$customerNameCol`, '')), '')";
        }
    }
    if ($customerIdCol) $customerLabelExprParts[] = "CAST(m.`$customerIdCol` AS CHAR)";
    if ($invoiceTable && $invoiceCustomerIdCol) $customerLabelExprParts[] = "CAST(i.`$invoiceCustomerIdCol` AS CHAR)";
    $customerLabelExpr = $customerLabelExprParts ? ("COALESCE(" . implode(", ", $customerLabelExprParts) . ", '')") : "''";

    $erpProjectLabelExprParts = [];
    if ($erpProjectNoCol && $erpProjectNameOnMovementCol) {
        $erpProjectLabelExprParts[] = "NULLIF(CONCAT(TRIM(COALESCE(m.`$erpProjectNoCol`, '')), CASE WHEN TRIM(COALESCE(m.`$erpProjectNoCol`, ''))<>'' THEN ' – ' ELSE '' END, TRIM(COALESCE(m.`$erpProjectNameOnMovementCol`, ''))), '')";
    } else {
        if ($erpProjectNoCol) $erpProjectLabelExprParts[] = "NULLIF(TRIM(COALESCE(m.`$erpProjectNoCol`, '')), '')";
        if ($erpProjectNameOnMovementCol) $erpProjectLabelExprParts[] = "NULLIF(TRIM(COALESCE(m.`$erpProjectNameOnMovementCol`, '')), '')";
    }
    if ($erpProjectTable) {
        if ($erpProjectTableNoCol && $erpProjectTableNameCol) {
            $erpProjectLabelExprParts[] = "NULLIF(CONCAT(TRIM(COALESCE(ep.`$erpProjectTableNoCol`, '')), CASE WHEN TRIM(COALESCE(ep.`$erpProjectTableNoCol`, ''))<>'' THEN ' – ' ELSE '' END, TRIM(COALESCE(ep.`$erpProjectTableNameCol`, ''))), '')";
        } else {
            if ($erpProjectTableNoCol) $erpProjectLabelExprParts[] = "NULLIF(TRIM(COALESCE(ep.`$erpProjectTableNoCol`, '')), '')";
            if ($erpProjectTableNameCol) $erpProjectLabelExprParts[] = "NULLIF(TRIM(COALESCE(ep.`$erpProjectTableNameCol`, '')), '')";
        }
    }
    if ($erpProjectIdCol) $erpProjectLabelExprParts[] = "CAST(m.`$erpProjectIdCol` AS CHAR)";
    if ($invoiceTable && $invoiceErpProjectIdCol) $erpProjectLabelExprParts[] = "CAST(i.`$invoiceErpProjectIdCol` AS CHAR)";
    $erpProjectLabelExpr = $erpProjectLabelExprParts ? ("COALESCE(" . implode(", ", $erpProjectLabelExprParts) . ", '')") : "''";

    $sql = "
        SELECT
            m.id,
            m.occurred_at,

            p_from.id   AS from_project_id,
            $fromProjectNoSelect
            p_from.name AS from_project_name,

            p_to.id   AS to_project_id,
            $toProjectNoSelect
            p_to.name AS to_project_name,

            m.work_order_id,
            $woNoSelect
            $woTitleSelect
            $woFallbackSelect
            $workOrderDisplayExpr AS work_order_display,

            $saleSelect
            $salesRefSelect
            ($invoiceLabelExpr)    AS invoice_ref,
            ($customerLabelExpr)   AS customer_ref,
            ($erpProjectLabelExpr) AS erp_project_ref,

            $prodSelect,
            $locSelect
            $batchSelect
            m.qty,
            m.unit_price AS movement_unit_price,
            ($effectiveUnitPriceExpr) AS effective_unit_price,
            (m.qty * ($effectiveUnitPriceExpr)) AS line_amount
        FROM inv_movements m
        $commonJoinSql
        $invoiceJoinSql
        $customerJoinSql
        $erpProjectJoinSql
        $prodJoin
        $locationJoinSql
        $baseWhere
        ORDER BY m.occurred_at DESC, m.id DESC
        LIMIT $limit
    ";
}

// ---------------------------------------------------------
// Kjør rapport
// ---------------------------------------------------------
$rows = [];
$errors = [];

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $errors[] = $e->getMessage();
    $rows = [];
}

$missingCostCount = 0;
try {
    $missParams = $params;

    $missSql = "
        SELECT COUNT(*)
        FROM inv_movements m
        LEFT JOIN work_orders wo ON wo.id = m.work_order_id
        WHERE m.type = 'OUT'
          AND m.occurred_at >= :fromDt
          AND m.occurred_at <  :toPlus
          AND m.batch_id IS NULL
          AND (m.unit_price IS NULL OR m.unit_price = 0)
    ";

    if (!$physicalColMissing && $locationFilterId > 0) {
        $col = $physicalMovementCol;
        $missSql .= " AND m.`$col` = :locationId ";
    }
    if ($projectFilterId > 0) {
        $missSql .= " AND $toProjectExpr = :toProjectId ";
    }
    if ($productFilterId > 0) {
        $missSql .= " AND m.product_id = :productId ";
    }
    if ($workOrderFilterId > 0) {
        $missSql .= " AND m.work_order_id = :woId ";
    }

    $stmt = $pdo->prepare($missSql);
    $stmt->execute($missParams);
    $missingCostCount = (int)($stmt->fetchColumn() ?: 0);
} catch (\Throwable $e) {
    $missingCostCount = 0;
}

$grandAmount = 0.0;
$grandQty    = 0.0;
$grandMoves  = 0;

foreach ($rows as $r) {
    if ($mode === 'detailed') {
        $grandAmount += (float)($r['line_amount'] ?? 0);
        $grandQty    += (float)($r['qty'] ?? 0);
        $grandMoves  += 1;
    } else {
        $grandAmount += (float)($r['total_amount'] ?? 0);
        $grandQty    += (float)($r['total_qty'] ?? 0);
        $grandMoves  += (int)($r['movements'] ?? 0);
    }
}

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uttakrapport_' . $from . '_' . $to . '_' . $mode . '.csv"');

    $out = fopen('php://output', 'w');

    if ($mode === 'detailed') {
        fputcsv($out, [
            'occurred_at',
            'from_project_id',
            'from_project_no',
            'from_project_name',
            'to_project_id',
            'to_project_no',
            'to_project_name',
            'work_order_id',
            'work_order_no',
            'work_order_title',
            'work_order_fallback_label',
            'work_order_display',
            'is_sale',
            'sales_ref',
            'invoice_ref',
            'customer_ref',
            'erp_project_ref',
            'product_id',
            'product_sku',
            'product_name',
            'location_id',
            'location_code',
            'location_name',
            'batch_id',
            'batch_received_at',
            'qty',
            'movement_unit_price',
            'effective_unit_price',
            'line_amount',
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                (string)($r['occurred_at'] ?? ''),
                (string)($r['from_project_id'] ?? ''),
                (string)($r['from_project_no'] ?? ''),
                (string)($r['from_project_name'] ?? ''),
                (string)($r['to_project_id'] ?? ''),
                (string)($r['to_project_no'] ?? ''),
                (string)($r['to_project_name'] ?? ''),
                (string)($r['work_order_id'] ?? ''),
                (string)($r['work_order_no'] ?? ''),
                (string)($r['work_order_title'] ?? ''),
                (string)($r['work_order_fallback_label'] ?? ''),
                (string)($r['work_order_display'] ?? ''),
                (string)($r['is_sale'] ?? ''),
                (string)($r['sales_ref'] ?? ''),
                (string)($r['invoice_ref'] ?? ''),
                (string)($r['customer_ref'] ?? ''),
                (string)($r['erp_project_ref'] ?? ''),
                (string)($r['product_id'] ?? ''),
                (string)($r['product_sku'] ?? ''),
                (string)($r['product_name'] ?? ''),
                (string)($r['location_id'] ?? ''),
                (string)($r['location_code'] ?? ''),
                (string)($r['location_name'] ?? ''),
                (string)($r['batch_id'] ?? ''),
                (string)($r['batch_received_at'] ?? ''),
                (string)($r['qty'] ?? ''),
                (string)($r['movement_unit_price'] ?? ''),
                (string)($r['effective_unit_price'] ?? ''),
                (string)($r['line_amount'] ?? ''),
            ]);
        }
    } else {
        fputcsv($out, array_keys($rows[0] ?? ['empty' => '']));
        foreach ($rows as $r) fputcsv($out, array_values($r));
    }

    fclose($out);
    exit;
}

$modeLabel = [
    'project'  => 'Per prosjekt (Fra → Belastes)',
    'workorder'=> 'Per arbeidsordre (Fra → Belastes)',
    'day'      => 'Per dag (Fra → Belastes)',
    'product'  => 'Per produkt (Fra → Belastes)',
    'location' => 'Per lokasjon (Fra → Belastes)',
    'detailed' => 'Detaljert (varelinjer)',
][$mode] ?? 'Per prosjekt';

$detailSummary = [];
if ($mode === 'detailed' && $rows) {
    foreach ($rows as $r) {
        $key = projectLabel($r['to_project_no'] ?? null, $r['to_project_name'] ?? null, (int)($r['to_project_id'] ?? 0));
        if ($key === '') $key = '(ukjent prosjekt)';
        if (!isset($detailSummary[$key])) {
            $detailSummary[$key] = ['qty' => 0.0, 'amount' => 0.0, 'lines' => 0];
        }
        $detailSummary[$key]['qty']    += (float)($r['qty'] ?? 0);
        $detailSummary[$key]['amount'] += (float)($r['line_amount'] ?? 0);
        $detailSummary[$key]['lines']  += 1;
    }
    uasort($detailSummary, fn($a, $b) => ($b['amount'] <=> $a['amount']));
}

$detailQs = $_GET;
$detailQs['page'] = 'inv_reports';
$detailQs['mode'] = 'detailed';
if (!isset($detailQs['limit'])) $detailQs['limit'] = 20000;
$detailUrl = '/?' . http_build_query($detailQs);

?>
<div class="d-flex align-items-start justify-content-between mt-3">
    <div>
        <h3 class="mb-1">Varelager – Uttakrapport</h3>
        <div class="text-muted">
            Viser uttak (OUT) fra <strong><?= h($from) ?></strong> til <strong><?= h($to) ?></strong>
            <span class="ms-2">•</span>
            <strong><?= h($modeLabel) ?></strong>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-2">
            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_movements">
                <i class="bi bi-clock-history me-1"></i> Bevegelser
            </a>

            <a class="btn btn-sm btn-outline-secondary" href="<?= h($detailUrl) ?>">
                <i class="bi bi-list-ul me-1"></i> Vis varelinjer
            </a>

            <?php
            $stocktakeQs = [
                'page'        => 'inv_stocktake',
                'project_id'  => ($projectFilterId > 0 ? $projectFilterId : null),
                'location_id' => ($locationFilterId > 0 ? $locationFilterId : null),
                'asof_mode'   => 'now',
            ];
            $stocktakeQs = array_filter($stocktakeQs, fn($v) => $v !== null && $v !== '');
            $stocktakeUrl = '/?' . http_build_query($stocktakeQs);

            $csvQs = $_GET;
            $csvQs['page'] = 'inv_reports';
            $csvQs['export'] = 'csv';
            $csvUrl = '/?' . http_build_query($csvQs);
            ?>

            <a class="btn btn-sm btn-outline-secondary" href="<?= h($stocktakeUrl) ?>">
                <i class="bi bi-clipboard-check me-1"></i> Varetelling
            </a>

            <a class="btn btn-sm btn-outline-secondary" href="<?= h($csvUrl) ?>">
                <i class="bi bi-download me-1"></i> Last ned CSV
            </a>
        </div>

        <?php if ($physicalColMissing): ?>
            <div class="alert alert-warning mt-2 mb-0">
                Fysisk lager-filter er ikke aktivt: fant ingen lokasjonskolonne i <code>inv_movements</code>.
                Forventet f.eks. <code>physical_location_id</code>.
            </div>
        <?php endif; ?>

        <?php if ($missingCostCount > 0): ?>
            <div class="alert alert-warning mt-2 mb-0">
                <?= (int)$missingCostCount ?> uttakslinjer i perioden mangler FIFO-kost
                (<code>batch_id</code> er NULL og <code>unit_price</code> er 0/NULL).
                Rapportert "Sum belastet" blir da lav/0 for disse linjene.
            </div>
        <?php endif; ?>

        <?php if (!$canWarehouseWrite): ?>
            <div class="text-muted small mt-1">
                Du har lesetilgang til rapporter, men ikke skrivetilgang til varelager.
            </div>
        <?php endif; ?>
    </div>

    <div class="text-end">
        <div class="text-muted">Sum belastet</div>
        <div style="font-size: 1.25rem;">
            <strong><?= fmtAmount($grandAmount) ?></strong>
        </div>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger mt-3">
        <strong>Feil:</strong><br>
        <?= nl2br(h(implode("\n", $errors))) ?>
    </div>
<?php endif; ?>

<form class="row g-2 mt-3 mb-3" method="get">
    <input type="hidden" name="page" value="inv_reports">

    <div class="col-auto">
        <label class="form-label">Fra dato</label>
        <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
    </div>

    <div class="col-auto">
        <label class="form-label">Til dato</label>
        <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
    </div>

    <div class="col-auto">
        <label class="form-label">Visning</label>
        <select name="mode" class="form-select">
            <option value="project"   <?= $mode === 'project' ? 'selected' : '' ?>>Per prosjekt (Fra → Belastes)</option>
            <option value="workorder" <?= $mode === 'workorder' ? 'selected' : '' ?>>Per arbeidsordre</option>
            <option value="day"       <?= $mode === 'day' ? 'selected' : '' ?>>Per dag</option>
            <option value="product"   <?= $mode === 'product' ? 'selected' : '' ?>>Per produkt</option>
            <option value="location"  <?= ($mode === 'location' ? 'selected' : '') ?> <?= $physicalColMissing ? 'disabled' : '' ?>>
                Per lokasjon
            </option>
            <option value="detailed"  <?= $mode === 'detailed' ? 'selected' : '' ?>>Varelinjer (detaljert)</option>
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
        <label class="form-label">Belastes prosjekt (uttak)</label>
        <select name="project_id" class="form-select">
            <option value="">Alle</option>
            <?php foreach ($projects as $p): ?>
                <?php
                $pid = (int)($p['id'] ?? 0);
                $pno = (string)($p['project_no'] ?? '');
                $pnm = (string)($p['name'] ?? '');
                $lbl = projectLabel($pno, $pnm, $pid);
                ?>
                <option value="<?= $pid ?>" <?= $projectFilterId === $pid ? 'selected' : '' ?>>
                    <?= h($lbl) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-auto">
        <label class="form-label">Fra-prosjekt (kilde/lager)</label>
        <select name="from_project_id" class="form-select">
            <option value="">Alle</option>
            <?php foreach ($projects as $p): ?>
                <?php
                $pid = (int)($p['id'] ?? 0);
                $pno = (string)($p['project_no'] ?? '');
                $pnm = (string)($p['name'] ?? '');
                $lbl = projectLabel($pno, $pnm, $pid);
                ?>
                <option value="<?= $pid ?>" <?= $fromProjectFilterId === $pid ? 'selected' : '' ?>>
                    <?= h($lbl) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-auto <?= $mode === 'detailed' ? '' : 'd-none' ?>">
        <label class="form-label">Limit (detaljert)</label>
        <input type="number" name="limit" min="100" max="20000" class="form-control" value="<?= (int)$limit ?>">
    </div>

    <div class="col-auto align-self-end">
        <button class="btn btn-primary">Kjør rapport</button>
        <a class="btn btn-outline-secondary" href="?page=inv_reports">Nullstill</a>
    </div>

    <div class="col-auto align-self-end ms-auto text-muted">
        <?= (int)$grandMoves ?> bevegelser • <?= fmtQtyNoDecimals($grandQty) ?> qty
        <?php if ($mode === 'detailed'): ?>
            <span class="ms-2 text-muted">(viser maks <?= (int)$limit ?> linjer)</span>
        <?php endif; ?>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
        <thead>
        <tr>
            <?php if ($mode === 'workorder'): ?>
                <th>Fra-prosjekt</th>
                <th>Belastes prosjekt</th>
                <th>Arbeidsordre</th>
                <th class="text-end">Bevegelser</th>
                <th class="text-end">Sum qty</th>
                <th class="text-end">Sum belastet</th>
                <th class="text-end"></th>
            <?php elseif ($mode === 'day'): ?>
                <th>Dato</th>
                <th>Fra-prosjekt</th>
                <th>Belastes prosjekt</th>
                <th class="text-end">Bevegelser</th>
                <th class="text-end">Sum qty</th>
                <th class="text-end">Sum belastet</th>
                <th class="text-end"></th>
            <?php elseif ($mode === 'product'): ?>
                <th>Fra-prosjekt</th>
                <th>Belastes prosjekt</th>
                <th>Produkt</th>
                <th class="text-end">Bevegelser</th>
                <th class="text-end">Sum qty</th>
                <th class="text-end">Sum belastet</th>
                <th class="text-end"></th>
            <?php elseif ($mode === 'location'): ?>
                <th>Fra-prosjekt</th>
                <th>Belastes prosjekt</th>
                <th>Lokasjon</th>
                <th class="text-end">Bevegelser</th>
                <th class="text-end">Sum qty</th>
                <th class="text-end">Sum belastet</th>
                <th class="text-end"></th>
            <?php elseif ($mode === 'detailed'): ?>
                <th>Tid</th>
                <th>Fra-prosjekt</th>
                <th>Belastes prosjekt</th>
                <th>Arbeidsordre</th>
                <th>Salg</th>
                <th>Faktura</th>
                <th>Kunde</th>
                <th>ERP-prosjekt</th>
                <th>Produkt</th>
                <th>Lokasjon</th>
                <th>Batch</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Á-pris</th>
                <th class="text-end">Beløp</th>
            <?php else: ?>
                <th>Fra-prosjekt</th>
                <th>Belastes prosjekt</th>
                <th class="text-end">Bevegelser</th>
                <th class="text-end">Sum qty</th>
                <th class="text-end">Sum belastet</th>
                <th class="text-end"></th>
            <?php endif; ?>
        </tr>
        </thead>

        <tbody>
        <?php
        $emptyColspan = 6;
        if ($mode === 'workorder' || $mode === 'day' || $mode === 'product' || $mode === 'location') $emptyColspan = 7;
        if ($mode === 'detailed') $emptyColspan = 14;
        ?>
        <?php if (!$rows): ?>
            <tr>
                <td colspan="<?= (int)$emptyColspan ?>" class="text-muted">
                    Ingen uttak i valgt periode.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>

                <?php if ($mode === 'detailed'): ?>
                    <?php
                        $fromProjLbl = projectLabel($r['from_project_no'] ?? null, $r['from_project_name'] ?? null, (int)($r['from_project_id'] ?? 0));
                        $toProjLbl   = projectLabel($r['to_project_no'] ?? null,   $r['to_project_name'] ?? null,   (int)($r['to_project_id'] ?? 0));

                        $locLabel = '';
                        if (!$physicalColMissing) {
                            $locCode = trim((string)($r['location_code'] ?? ''));
                            $locName = trim((string)($r['location_name'] ?? ''));
                            $locId   = (int)($r['location_id'] ?? 0);
                            if ($locName !== '' || $locCode !== '') {
                                $locLabel = trim($locCode) !== '' ? ($locCode . ' – ' . $locName) : $locName;
                            } elseif ($locId > 0) {
                                $locLabel = (string)$locId;
                            }
                        }

                        $prodLabel = '';
                        $pname = trim((string)($r['product_name'] ?? ''));
                        $psku  = trim((string)($r['product_sku'] ?? ''));
                        $pid   = (int)($r['product_id'] ?? 0);
                        if ($pname !== '' || $psku !== '') {
                            $prodLabel = ($psku !== '' ? ($psku . ' – ') : '') . $pname;
                        } elseif ($pid > 0) {
                            $prodLabel = '#' . $pid;
                        }

                        $woLabel = trim((string)($r['work_order_display'] ?? ''));
                        if ($woLabel === '') {
                            $woLabel = '—';
                        }

                        $batchLabel = '';
                        $bid = (int)($r['batch_id'] ?? 0);
                        if ($bid > 0) {
                            $br = (string)($r['batch_received_at'] ?? '');
                            $brShort = '';
                            if ($br !== '') {
                                try { $d = new DateTime($br); $brShort = $d->format('Y-m-d'); } catch (\Throwable $e) {}
                            }
                            $batchLabel = '#' . $bid . ($brShort !== '' ? (' (' . $brShort . ')') : '');
                        } else {
                            $batchLabel = '—';
                        }

                        $effectiveUnitPrice = (float)($r['effective_unit_price'] ?? 0);
                        $isMissingCost = ($bid <= 0 && $effectiveUnitPrice <= 0);

                        $isSaleRaw = $r['is_sale'] ?? null;
                        $isSale = null;
                        if ($isSaleRaw !== null && $isSaleRaw !== '') $isSale = ((int)$isSaleRaw) ? 'Ja' : 'Nei';

                        $salesRef = trim((string)($r['sales_ref'] ?? ''));
                        $invoiceRef = trim((string)($r['invoice_ref'] ?? ''));
                        $customerRef = trim((string)($r['customer_ref'] ?? ''));
                        $erpProjectRef = trim((string)($r['erp_project_ref'] ?? ''));

                        $saleLabel = $isSale ?? '—';
                        if ($saleLabel === 'Nei' && $salesRef !== '') $saleLabel = 'Nei (' . $salesRef . ')';
                        if ($saleLabel === 'Ja'  && $salesRef !== '') $saleLabel = 'Ja (' . $salesRef . ')';
                        if ($saleLabel === '—'   && $salesRef !== '') $saleLabel = $salesRef;
                    ?>
                    <tr>
                        <td><?= h(fmtDateTime($r['occurred_at'] ?? null)) ?></td>
                        <td><?= h($fromProjLbl) ?></td>
                        <td><?= h($toProjLbl) ?></td>
                        <td><?= h($woLabel) ?></td>

                        <td><?= h($saleLabel) ?></td>
                        <td><?= h($invoiceRef !== '' ? $invoiceRef : '—') ?></td>
                        <td><?= h($customerRef !== '' ? $customerRef : '—') ?></td>
                        <td><?= h($erpProjectRef !== '' ? $erpProjectRef : '—') ?></td>

                        <td><?= h($prodLabel) ?></td>
                        <td><?= h($locLabel) ?></td>
                        <td class="<?= $isMissingCost ? 'text-warning' : '' ?>"><?= h($batchLabel) ?></td>
                        <td class="text-end"><strong><?= fmtQtyNoDecimals($r['qty'] ?? 0) ?></strong></td>
                        <td class="text-end <?= $isMissingCost ? 'text-warning' : '' ?>"><?= fmtAmount($effectiveUnitPrice) ?></td>
                        <td class="text-end"><strong><?= fmtAmount((float)($r['line_amount'] ?? 0)) ?></strong></td>
                    </tr>

                <?php else: ?>
                    <?php
                        $fromProjLbl = projectLabel($r['from_project_no'] ?? null, $r['from_project_name'] ?? null, (int)($r['from_project_id'] ?? 0));
                        $toProjLbl   = projectLabel($r['to_project_no'] ?? null,   $r['to_project_name'] ?? null,   (int)($r['to_project_id'] ?? 0));

                        $qs = $_GET;
                        $qs['page']  = 'inv_reports';
                        $qs['mode']  = 'detailed';
                        $qs['limit'] = $qs['limit'] ?? 20000;

                        $qs['project_id'] = (int)($r['to_project_id'] ?? 0);
                        $qs['from_project_id'] = (int)($r['from_project_id'] ?? 0);

                        if ($mode === 'workorder') {
                            $woIdForDrilldown = (int)($r['work_order_id'] ?? 0);
                            if ($woIdForDrilldown > 0) {
                                $qs['work_order_id'] = $woIdForDrilldown;
                            } else {
                                unset($qs['work_order_id']);
                            }
                        } elseif ($mode === 'product') {
                            $qs['product_id'] = (int)($r['product_id'] ?? 0);
                        } elseif ($mode === 'location') {
                            $qs['location_id'] = (int)($r['location_id'] ?? 0);
                        }

                        $rowDetailUrl = '/?' . http_build_query($qs);
                    ?>

                    <?php if ($mode === 'day'): ?>
                        <tr>
                            <td><?= h((string)($r['day'] ?? '')) ?></td>
                            <td><?= h($fromProjLbl) ?></td>
                            <td><?= h($toProjLbl) ?></td>
                            <td class="text-end"><?= (int)($r['movements'] ?? 0) ?></td>
                            <td class="text-end"><?= fmtQtyNoDecimals($r['total_qty'] ?? 0) ?></td>
                            <td class="text-end"><strong><?= fmtAmount((float)($r['total_amount'] ?? 0)) ?></strong></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= h($rowDetailUrl) ?>">Varelinjer</a>
                            </td>
                        </tr>

                    <?php elseif ($mode === 'product'): ?>
                        <?php
                            $prodLabel = '';
                            $pname = trim((string)($r['product_name'] ?? ''));
                            $psku  = trim((string)($r['product_sku'] ?? ''));
                            $pid   = (int)($r['product_id'] ?? 0);
                            if ($pname !== '' || $psku !== '') $prodLabel = ($psku !== '' ? ($psku . ' – ') : '') . $pname;
                            elseif ($pid > 0) $prodLabel = '#' . $pid;
                        ?>
                        <tr>
                            <td><?= h($fromProjLbl) ?></td>
                            <td><?= h($toProjLbl) ?></td>
                            <td><?= h($prodLabel) ?></td>
                            <td class="text-end"><?= (int)($r['movements'] ?? 0) ?></td>
                            <td class="text-end"><?= fmtQtyNoDecimals($r['total_qty'] ?? 0) ?></td>
                            <td class="text-end"><strong><?= fmtAmount((float)($r['total_amount'] ?? 0)) ?></strong></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= h($rowDetailUrl) ?>">Varelinjer</a>
                            </td>
                        </tr>

                    <?php elseif ($mode === 'location'): ?>
                        <?php
                            $locLabel = '';
                            $locCode = trim((string)($r['location_code'] ?? ''));
                            $locName = trim((string)($r['location_name'] ?? ''));
                            $locId   = (int)($r['location_id'] ?? 0);
                            if ($locName !== '' || $locCode !== '') $locLabel = trim($locCode) !== '' ? ($locCode . ' – ' . $locName) : $locName;
                            elseif ($locId > 0) $locLabel = (string)$locId;
                        ?>
                        <tr>
                            <td><?= h($fromProjLbl) ?></td>
                            <td><?= h($toProjLbl) ?></td>
                            <td><?= h($locLabel) ?></td>
                            <td class="text-end"><?= (int)($r['movements'] ?? 0) ?></td>
                            <td class="text-end"><?= fmtQtyNoDecimals($r['total_qty'] ?? 0) ?></td>
                            <td class="text-end"><strong><?= fmtAmount((float)($r['total_amount'] ?? 0)) ?></strong></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= h($rowDetailUrl) ?>">Varelinjer</a>
                            </td>
                        </tr>

                    <?php elseif ($mode === 'workorder'): ?>
                        <?php
                            $woLabel = trim((string)($r['work_order_display'] ?? ''));
                            if ($woLabel === '') $woLabel = '—';
                        ?>
                        <tr>
                            <td><?= h($fromProjLbl) ?></td>
                            <td><?= h($toProjLbl) ?></td>
                            <td><?= h($woLabel) ?></td>
                            <td class="text-end"><?= (int)($r['movements'] ?? 0) ?></td>
                            <td class="text-end"><?= fmtQtyNoDecimals($r['total_qty'] ?? 0) ?></td>
                            <td class="text-end"><strong><?= fmtAmount((float)($r['total_amount'] ?? 0)) ?></strong></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= h($rowDetailUrl) ?>">Varelinjer</a>
                            </td>
                        </tr>

                    <?php else: ?>
                        <tr>
                            <td><?= h($fromProjLbl) ?></td>
                            <td><?= h($toProjLbl) ?></td>
                            <td class="text-end"><?= (int)($r['movements'] ?? 0) ?></td>
                            <td class="text-end"><?= fmtQtyNoDecimals($r['total_qty'] ?? 0) ?></td>
                            <td class="text-end"><strong><?= fmtAmount((float)($r['total_amount'] ?? 0)) ?></strong></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= h($rowDetailUrl) ?>">Varelinjer</a>
                            </td>
                        </tr>
                    <?php endif; ?>

                <?php endif; ?>

            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($mode === 'detailed' && $detailSummary): ?>
    <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <strong>Oppsummering per belastes-prosjekt (for viste linjer)</strong>
            <span class="text-muted small">Basert på <?= (int)$grandMoves ?> linjer</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                <tr>
                    <th>Belastes-prosjekt</th>
                    <th class="text-end">Linjer</th>
                    <th class="text-end">Sum qty</th>
                    <th class="text-end">Sum belastet</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($detailSummary as $pname => $s): ?>
                    <tr>
                        <td><?= h($pname) ?></td>
                        <td class="text-end"><?= (int)$s['lines'] ?></td>
                        <td class="text-end"><?= fmtQtyNoDecimals((float)$s['qty']) ?></td>
                        <td class="text-end"><strong><?= fmtAmount((float)$s['amount']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <th>Totalt</th>
                    <th class="text-end"><?= (int)$grandMoves ?></th>
                    <th class="text-end"><?= fmtQtyNoDecimals((float)$grandQty) ?></th>
                    <th class="text-end"><strong><?= fmtAmount((float)$grandAmount) ?></strong></th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>