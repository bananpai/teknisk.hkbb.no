<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\pages\logistikk_products.php
// public/pages/logistikk_products.php
//
// Varer (varetyper):
//  - Opprett/rediger/deaktiver varetyper i inv_products
//  - Innregistrering av varer (batcher) gjøres i /?page=logistikk_receipts
//
// QR/etikett:
//  - Generer/skriv ut QR-etikett per varetype (produkt)
//  - QR payload: "p:<product_id>" (stabil intern kode for scanning i uttak-appen)
//  - QR rendres som <img> (ikke JS), for å unngå at QR blir borte pga. CSP/blocked script
//
// BULK QR:
//  - Skriv ut QR for filtrerte varer / alle varer / valgte varer (checkbox i liste)
//  - GET: bulk_label=1&mode=filtered|all|selected
//
// OPPDATERT 2026-03-16:
//  - Lagt til støtte for produsent på varetype (inv_products.manufacturer)
//  - Oppretter DB-kolonnen automatisk hvis den mangler
//  - Produsent kan søkes på, redigeres og vises i vareliste
//  - Lagt til sortering på alle relevante kolonner i varelisten
//  - Klikkbare kolonneoverskrifter med stigende/synkende sortering
//  - Beholder støtte for bilde, QR, sletting/skjuling og etikettutskrift
//
// Bruk: /?page=logistikk_products

declare(strict_types=1);

use App\Database;

$pageTitle = 'Logistikk: Varer';

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

if (!$isAdmin && in_array('admin', $currentRoles, true)) {
    $isAdmin = true;
}

$canWarehouseRead  = $isAdmin || in_array('warehouse_read', $currentRoles, true) || in_array('warehouse_write', $currentRoles, true);
$canWarehouseWrite = $isAdmin || in_array('warehouse_write', $currentRoles, true);

if (!$canWarehouseRead) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til varelager.
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

function post_int(string $key): int {
    return isset($_POST[$key]) ? (int)$_POST[$key] : 0;
}
function post_str(string $key): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}
function post_float(string $key): float {
    $v = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '0';
    $v = str_replace(' ', '', $v);
    $v = str_replace(',', '.', $v);
    $v = preg_replace('/[^0-9\.\-]/', '', $v);
    return (float)$v;
}
function get_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}
function get_str(string $key, string $default = ''): string {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
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

function fmt_qty(float $v): string {
    return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
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

function ensureProductManufacturerColumn(PDO $pdo): bool
{
    try {
        if (!columnExists($pdo, 'inv_products', 'manufacturer')) {
            $pdo->exec("ALTER TABLE inv_products ADD COLUMN manufacturer VARCHAR(128) NULL AFTER name");
        }
        return true;
    } catch (\Throwable $e) {
        return columnExists($pdo, 'inv_products', 'manufacturer');
    }
}

function handleProductImageUpload(int $productId, array $file, string $uploadFsBase, string $uploadRelBase): ?string
{
    if (empty($file) || !isset($file['error'])) return null;
    if ((int)$file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Feil ved opplasting av bilde (kode ' . (int)$file['error'] . ').');
    }

    $maxBytes = 3 * 1024 * 1024; // 3 MB
    if (!isset($file['size']) || (int)$file['size'] <= 0) {
        throw new RuntimeException('Bilde mangler størrelse.');
    }
    if ((int)$file['size'] > $maxBytes) {
        throw new RuntimeException('Bildet er for stort (maks 3 MB).');
    }

    $tmp = (string)$file['tmp_name'];
    $imgInfo = @getimagesize($tmp);
    if (!$imgInfo) {
        throw new RuntimeException('Ugyldig bildefil.');
    }

    $mime = $imgInfo['mime'] ?? '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Ugyldig bildeformat. Tillatt: JPG, PNG, WEBP, GIF.');
    }

    if (!is_dir($uploadFsBase)) {
        @mkdir($uploadFsBase, 0755, true);
    }
    if (!is_dir($uploadFsBase) || !is_writable($uploadFsBase)) {
        throw new RuntimeException('Kan ikke lagre bilde. Sjekk rettigheter på opplastingsmappen.');
    }

    $ext = $allowed[$mime];
    $rand = bin2hex(random_bytes(8));
    $filename = 'p' . $productId . '_' . $rand . '.' . $ext;

    $destFs = rtrim($uploadFsBase, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmp, $destFs)) {
        throw new RuntimeException('Kunne ikke lagre opplastet bilde.');
    }

    return rtrim($uploadRelBase, '/') . '/' . $filename;
}

function tryDeleteFileByWebPath(string $webPath): void
{
    if ($webPath === '') return;
    if (strpos($webPath, '/uploads/') !== 0) return; // guard

    $publicDir = dirname(__DIR__); // public/
    $fs = $publicDir . $webPath;
    if (is_file($fs)) {
        @unlink($fs);
    }
}

/**
 * Hent valgte IDs fra query.
 * Støtter:
 *  - ids[]=1&ids[]=2
 *  - ids=1,2,3
 *
 * VIKTIG: Må aldri gjøre (string)$_GET['ids'] hvis den er array (gir "Array to string conversion").
 */
function parse_ids_from_request(): array
{
    $ids = [];
    $raw = $_GET['ids'] ?? null;

    if (is_array($raw)) {
        foreach ($raw as $v) {
            $n = (int)$v;
            if ($n > 0) $ids[] = $n;
        }
    }

    if (is_string($raw)) {
        $csv = trim($raw);
        if ($csv !== '') {
            foreach (preg_split('/[,\s]+/', $csv) as $p) {
                $n = (int)$p;
                if ($n > 0) $ids[] = $n;
            }
        }
    }

    $ids = array_values(array_unique($ids));
    return $ids;
}

function getProductUsageInfo(PDO $pdo, int $productId): array
{
    $movementCount = 0;
    $batchCount = 0;
    $qtyOnHand = 0.0;

    if ($productId <= 0) {
        return [
            'movement_count' => 0,
            'batch_count' => 0,
            'qty_on_hand' => 0.0,
            'has_history' => false,
            'can_delete' => false,
        ];
    }

    if (tableExists($pdo, 'inv_movements') && columnExists($pdo, 'inv_movements', 'product_id')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_movements WHERE product_id = :id");
        $stmt->execute([':id' => $productId]);
        $movementCount = (int)($stmt->fetchColumn() ?: 0);
    }

    if (tableExists($pdo, 'inv_batches') && columnExists($pdo, 'inv_batches', 'product_id')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_batches WHERE product_id = :id");
        $stmt->execute([':id' => $productId]);
        $batchCount = (int)($stmt->fetchColumn() ?: 0);

        if (columnExists($pdo, 'inv_batches', 'qty_remaining')) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(qty_remaining), 0) FROM inv_batches WHERE product_id = :id");
            $stmt->execute([':id' => $productId]);
            $qtyOnHand = (float)($stmt->fetchColumn() ?: 0);
        }
    }

    $hasHistory = ($movementCount > 0 || $batchCount > 0);
    $canDelete = ($movementCount === 0 && $batchCount === 0 && abs($qtyOnHand) < 0.000001);

    return [
        'movement_count' => $movementCount,
        'batch_count' => $batchCount,
        'qty_on_hand' => $qtyOnHand,
        'has_history' => $hasHistory,
        'can_delete' => $canDelete,
    ];
}

function normalizeSort(string $sort): string
{
    $allowed = [
        'image',
        'name',
        'manufacturer',
        'sku',
        'category',
        'qty_on_hand',
        'min_stock',
        'status',
    ];
    return in_array($sort, $allowed, true) ? $sort : 'name';
}

function normalizeSortDir(string $dir): string
{
    return strtolower($dir) === 'desc' ? 'desc' : 'asc';
}

function buildSortUrl(string $column, array $filters, string $currentSort, string $currentDir): string
{
    $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';

    return '/?page=logistikk_products'
        . '&q=' . rawurlencode((string)$filters['q'])
        . '&active=' . rawurlencode((string)$filters['active'])
        . '&category_id=' . (int)$filters['category_id']
        . '&sort=' . rawurlencode($column)
        . '&dir=' . rawurlencode($nextDir);
}

function sortIndicator(string $column, string $currentSort, string $currentDir): string
{
    if ($currentSort !== $column) {
        return '<i class="bi bi-arrow-down-up text-muted ms-1"></i>';
    }
    if ($currentDir === 'asc') {
        return '<i class="bi bi-sort-down-alt ms-1"></i>';
    }
    return '<i class="bi bi-sort-up ms-1"></i>';
}

// -----------------------------
// Tabell-sjekk
// -----------------------------
$tablesOk =
    tableExists($pdo, 'inv_products') &&
    tableExists($pdo, 'inv_categories');

if (!$tablesOk) {
    ?>
    <div class="alert alert-warning mt-3">
        <div class="fw-semibold mb-1">Mangler tabeller</div>
        Denne siden krever <code>inv_products</code> og <code>inv_categories</code>.
    </div>
    <?php
    return;
}

// Beholdning: vi viser total fra inv_batches hvis tilgjengelig
$hasBatches =
    tableExists($pdo, 'inv_batches') &&
    columnExists($pdo, 'inv_batches', 'product_id') &&
    columnExists($pdo, 'inv_batches', 'qty_remaining');

$hasImageColumn = ensureProductImageColumn($pdo);
$hasManufacturerColumn = ensureProductManufacturerColumn($pdo);

// Upload paths
$uploadRelBase = '/uploads/products';
$uploadFsBase  = dirname(__DIR__) . $uploadRelBase; // public/uploads/products

// -----------------------------
// Data-hentere
// -----------------------------
function fetchCategoryOptions(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT id, parent_id, name
        FROM inv_categories
        ORDER BY parent_id ASC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $byId = [];
    foreach ($rows as $r) {
        $r['children'] = [];
        $byId[(int)$r['id']] = $r;
    }

    $root = [];
    foreach ($byId as $id => &$node) {
        $pid = $node['parent_id'] !== null ? (int)$node['parent_id'] : null;
        if ($pid && isset($byId[$pid])) {
            $byId[$pid]['children'][] = &$node;
        } else {
            $root[] = &$node;
        }
    }
    unset($node);

    $flatten = function (array $tree, int $depth = 0) use (&$flatten): array {
        $out = [];
        foreach ($tree as $n) {
            $out[] = [
                'id' => (int)$n['id'],
                'label' => str_repeat('— ', $depth) . $n['name'],
            ];
            if (!empty($n['children'])) {
                $out = array_merge($out, $flatten($n['children'], $depth + 1));
            }
        }
        return $out;
    };

    return $flatten($root);
}

function fetchProductsList(PDO $pdo, array $filters, bool $hasBatches, bool $hasImageColumn, bool $hasManufacturerColumn, string $sort, string $dir): array {
    $where = [];
    $params = [];

    if (!empty($filters['q'])) {
        if ($hasManufacturerColumn) {
            $where[] = "(p.name LIKE :q1 OR p.sku LIKE :q2 OR p.manufacturer LIKE :q3)";
            $params[':q1'] = '%' . $filters['q'] . '%';
            $params[':q2'] = '%' . $filters['q'] . '%';
            $params[':q3'] = '%' . $filters['q'] . '%';
        } else {
            $where[] = "(p.name LIKE :q1 OR p.sku LIKE :q2)";
            $params[':q1'] = '%' . $filters['q'] . '%';
            $params[':q2'] = '%' . $filters['q'] . '%';
        }
    }

    if (isset($filters['active']) && $filters['active'] !== '') {
        $where[] = "p.is_active = :active";
        $params[':active'] = (int)$filters['active'];
    }

    if (!empty($filters['category_id'])) {
        $where[] = "p.category_id = :cid";
        $params[':cid'] = (int)$filters['category_id'];
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $imgSelect = $hasImageColumn
        ? "p.image_path, CASE WHEN p.image_path IS NULL OR p.image_path = '' THEN 0 ELSE 1 END AS has_image,"
        : "NULL AS image_path, 0 AS has_image,";
    $manufacturerSelect = $hasManufacturerColumn ? "p.manufacturer," : "NULL AS manufacturer,";

    $sortMap = [
        'image'        => 'has_image',
        'name'         => 'p.name',
        'manufacturer' => $hasManufacturerColumn ? 'p.manufacturer' : 'p.name',
        'sku'          => 'p.sku',
        'category'     => 'c.name',
        'qty_on_hand'  => 'qty_on_hand',
        'min_stock'    => 'p.min_stock',
        'status'       => 'p.is_active',
    ];

    $sortExpr = $sortMap[$sort] ?? 'p.name';
    $sortDirSql = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

    if ($hasBatches) {
        $sql = "
            SELECT
                p.id,
                p.category_id,
                p.sku,
                p.name,
                $manufacturerSelect
                p.unit,
                p.min_stock,
                p.is_active,
                p.created_at,
                $imgSelect
                c.name AS category_name,
                COALESCE(SUM(b.qty_remaining), 0) AS qty_on_hand
            FROM inv_products p
            LEFT JOIN inv_categories c ON c.id = p.category_id
            LEFT JOIN inv_batches b ON b.product_id = p.id
            $whereSql
            GROUP BY
                p.id,
                p.category_id,
                p.sku,
                p.name," . ($hasManufacturerColumn ? "
                p.manufacturer," : "") . "
                p.unit,
                p.min_stock,
                p.is_active,
                p.created_at,
                c.name" . ($hasImageColumn ? ", p.image_path" : "") . "
            ORDER BY $sortExpr $sortDirSql, p.name ASC
        ";
    } else {
        $sql = "
            SELECT
                p.id,
                p.category_id,
                p.sku,
                p.name,
                $manufacturerSelect
                p.unit,
                p.min_stock,
                p.is_active,
                p.created_at,
                $imgSelect
                c.name AS category_name,
                0 AS qty_on_hand
            FROM inv_products p
            LEFT JOIN inv_categories c ON c.id = p.category_id
            $whereSql
            ORDER BY $sortExpr $sortDirSql, p.name ASC
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $usage = getProductUsageInfo($pdo, (int)$row['id']);
        $row['movement_count'] = $usage['movement_count'];
        $row['batch_count'] = $usage['batch_count'];
        $row['has_history'] = $usage['has_history'] ? 1 : 0;
        $row['can_delete'] = $usage['can_delete'] ? 1 : 0;
    }
    unset($row);

    return $rows;
}

function fetchProductsByIds(PDO $pdo, array $ids, bool $hasImageColumn, bool $hasManufacturerColumn): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($n) => $n > 0)));
    if (empty($ids)) return [];

    if (count($ids) > 500) {
        $ids = array_slice($ids, 0, 500);
    }

    $placeholders = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $ph = ':id' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $id;
    }

    $imgSelect = $hasImageColumn ? "p.image_path," : "NULL AS image_path,";
    $manufacturerSelect = $hasManufacturerColumn ? "p.manufacturer," : "NULL AS manufacturer,";

    $sql = "
        SELECT
            p.id,
            p.category_id,
            p.sku,
            p.name,
            $manufacturerSelect
            p.unit,
            p.min_stock,
            p.is_active,
            $imgSelect
            c.name AS category_name
        FROM inv_products p
        LEFT JOIN inv_categories c ON c.id = p.category_id
        WHERE p.id IN (" . implode(',', $placeholders) . ")
        ORDER BY p.name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchProductById(PDO $pdo, int $id, bool $hasImageColumn, bool $hasManufacturerColumn): ?array {
    $imgSelect = $hasImageColumn ? "p.image_path," : "NULL AS image_path,";
    $manufacturerSelect = $hasManufacturerColumn ? "p.manufacturer," : "NULL AS manufacturer,";
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.category_id,
            p.sku,
            p.name,
            $manufacturerSelect
            p.unit,
            p.min_stock,
            p.is_active,
            $imgSelect
            c.name AS category_name
        FROM inv_products p
        LEFT JOIN inv_categories c ON c.id = p.category_id
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $usage = getProductUsageInfo($pdo, $id);
    $row['movement_count'] = $usage['movement_count'];
    $row['batch_count'] = $usage['batch_count'];
    $row['qty_on_hand'] = $usage['qty_on_hand'];
    $row['has_history'] = $usage['has_history'] ? 1 : 0;
    $row['can_delete'] = $usage['can_delete'] ? 1 : 0;

    return $row;
}

// ---------------------------------------------------------
// BULK QR / etikett utskriftsvisning (GET: bulk_label=1&mode=...)
// ---------------------------------------------------------
$isBulkLabelView = (get_int('bulk_label', 0) === 1);
if ($isBulkLabelView) {
    $mode = get_str('mode', 'filtered');
    if (!in_array($mode, ['filtered','all','selected'], true)) $mode = 'filtered';

    $perQty  = max(1, min(50, get_int('per_qty', 1)));
    $size = get_str('size', '70x37');
    $allowedSizes = ['50x30','60x40','70x50','70x37'];
    if (!in_array($size, $allowedSizes, true)) $size = '70x37';

    $qrPx = 240;
    if ($size === '60x40') $qrPx = 320;
    if ($size === '70x50') $qrPx = 420;
    if ($size === '70x37') $qrPx = 320;

    $productsToPrint = [];

    if ($mode === 'selected') {
        $ids = parse_ids_from_request();
        if (empty($ids)) {
            ?>
            <div class="alert alert-warning mt-3">
                Ingen varer valgt. Gå tilbake og huk av varene du vil skrive ut QR for.
                <div class="mt-2">
                    <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_products">
                        <i class="bi bi-arrow-left"></i> Tilbake
                    </a>
                </div>
            </div>
            <?php
            return;
        }
        $productsToPrint = fetchProductsByIds($pdo, $ids, $hasImageColumn, $hasManufacturerColumn);
    } elseif ($mode === 'all') {
        $productsToPrint = fetchProductsList($pdo, ['q'=>'', 'active'=>'', 'category_id'=>0], $hasBatches, $hasImageColumn, $hasManufacturerColumn, 'name', 'asc');
    } else {
        $filtersBulk = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'active' => (string)($_GET['active'] ?? '1'),
            'category_id' => (int)($_GET['category_id'] ?? 0),
        ];
        $productsToPrint = fetchProductsList($pdo, $filtersBulk, $hasBatches, $hasImageColumn, $hasManufacturerColumn, 'name', 'asc');
    }

    $countProducts = count($productsToPrint);
    $gridClass = 'grid-' . $size;
    ?>
    <style>
        @page {
            size: A4;
            margin: 0;
        }

        html, body {
            margin: 0;
            padding: 0;
        }

        @media print {
            body * { visibility: hidden !important; }
            #bulk-label-print-area, #bulk-label-print-area * { visibility: visible !important; }
            #bulk-label-print-area { position: absolute; left: 0; top: 0; width: 210mm; height: 297mm; }
        }

        #bulk-label-print-area { padding: 10px; }
        .label-toolbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .label-grid { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-start; }

        .label-grid.grid-70x37 {
            display: grid;
            grid-template-columns: repeat(3, 70mm);
            grid-auto-rows: 37mm;
            width: 210mm;
        }

        .lbl {
            border: 1px dashed rgba(0,0,0,.25);
            border-radius: 8px;
            display: flex;
            align-items: flex-start;
            gap: 2.5mm;
            padding: 2mm 2.5mm;
            background: #fff;
            overflow: hidden;
            box-sizing: border-box;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .lbl.size-50x30 { width: 50mm; height: 30mm; }
        .lbl.size-60x40 { width: 60mm; height: 40mm; }
        .lbl.size-70x50 { width: 70mm; height: 50mm; }
        .lbl.size-70x37 { width: 70mm; height: 37mm; }

        .lbl .qr {
            width: 22mm;
            height: 22mm;
            flex: 0 0 auto;
            display: grid;
            place-items: center;
        }
        .lbl.size-60x40 .qr { width: 28mm; height: 28mm; }
        .lbl.size-70x50 .qr { width: 34mm; height: 34mm; }
        .lbl.size-70x37 .qr { width: 23mm; height: 23mm; }

        .qr img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            image-rendering: pixelated;
        }

        .lbl .txt {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            line-height: 1.05;
        }

        .lbl .txt .name {
            font-weight: 700;
            font-size: 11px;
            white-space: normal;
            word-break: break-word;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            max-height: 2.2em;
            overflow: hidden;
        }

        .lbl .txt .sku {
            font-size: 10.5px;
            opacity: .9;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .lbl .txt .code {
            font-size: 10px;
            opacity: .75;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media print {
            .label-toolbar { display: none !important; }

            #bulk-label-print-area {
                padding: 0 !important;
            }

            .label-grid { gap: 0 !important; }

            .lbl {
                border: none !important;
                border-radius: 0 !important;
                padding: 2mm 2.5mm !important;
            }

            .label-grid.grid-70x37 {
                gap: 0 !important;
                margin: 0 !important;
            }

            .lbl.size-70x37 .txt .name { font-size: 10.5px; max-height: 2.1em; }
            .lbl.size-70x37 .txt .sku  { font-size: 10.0px; }
            .lbl.size-70x37 .txt .code { font-size: 9.5px; }
        }
    </style>

    <div id="bulk-label-print-area">
        <div class="label-toolbar">
            <div>
                <div class="fw-semibold">Utskrift av QR-etiketter (flere varer)</div>
                <div class="text-muted small">
                    Modus:
                    <strong>
                        <?php
                        if ($mode === 'all') echo 'Alle varer';
                        elseif ($mode === 'selected') echo 'Valgte varer';
                        else echo 'Filtrerte varer';
                        ?>
                    </strong>
                    · Antall varer: <strong><?= (int)$countProducts ?></strong>
                    · Etiketter per vare: <strong><?= (int)$perQty ?></strong>
                    · Størrelse: <strong><?= h($size) ?></strong>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <form class="d-flex gap-2 flex-wrap" method="get">
                    <input type="hidden" name="page" value="logistikk_products">
                    <input type="hidden" name="bulk_label" value="1">
                    <input type="hidden" name="mode" value="<?= h($mode) ?>">

                    <?php if ($mode === 'filtered'): ?>
                        <input type="hidden" name="q" value="<?= h((string)($_GET['q'] ?? '')) ?>">
                        <input type="hidden" name="active" value="<?= h((string)($_GET['active'] ?? '1')) ?>">
                        <input type="hidden" name="category_id" value="<?= (int)($_GET['category_id'] ?? 0) ?>">
                    <?php elseif ($mode === 'selected'): ?>
                        <?php
                        $idsKeep = parse_ids_from_request();
                        foreach ($idsKeep as $idKeep) {
                            echo '<input type="hidden" name="ids[]" value="' . (int)$idKeep . '">' . PHP_EOL;
                        }
                        ?>
                    <?php endif; ?>

                    <div class="input-group input-group-sm" style="width: 170px;">
                        <span class="input-group-text">Per vare</span>
                        <input class="form-control" type="number" min="1" max="50" name="per_qty" value="<?= (int)$perQty ?>">
                    </div>

                    <div class="input-group input-group-sm" style="width: 190px;">
                        <span class="input-group-text">Størrelse</span>
                        <select class="form-select" name="size">
                            <option value="70x37" <?= $size==='70x37'?'selected':'' ?>>70×37 mm (24/A4)</option>
                            <option value="50x30" <?= $size==='50x30'?'selected':'' ?>>50×30 mm</option>
                            <option value="60x40" <?= $size==='60x40'?'selected':'' ?>>60×40 mm</option>
                            <option value="70x50" <?= $size==='70x50'?'selected':'' ?>>70×50 mm</option>
                        </select>
                    </div>

                    <button class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-repeat"></i> Oppdater
                    </button>
                </form>

                <button class="btn btn-sm btn-primary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Skriv ut
                </button>

                <a class="btn btn-sm btn-outline-secondary"
                   href="/?page=logistikk_products<?= $mode==='filtered'
                       ? ('&q=' . rawurlencode((string)($_GET['q'] ?? '')) . '&active=' . rawurlencode((string)($_GET['active'] ?? '1')) . '&category_id=' . (int)($_GET['category_id'] ?? 0))
                       : '' ?>">
                    <i class="bi bi-arrow-left"></i> Tilbake
                </a>
            </div>
        </div>

        <?php if ($countProducts === 0): ?>
            <div class="alert alert-warning">
                Ingen varer å skrive ut for i valgt modus/filtrering.
            </div>
        <?php else: ?>
            <div class="label-grid <?= h($gridClass) ?>">
                <?php foreach ($productsToPrint as $p): ?>
                    <?php
                    $pid = (int)$p['id'];
                    $qrPayload = 'p:' . $pid;
                    $qrImgSrc = 'https://api.qrserver.com/v1/create-qr-code/?size='
                        . $qrPx . 'x' . $qrPx
                        . '&data=' . rawurlencode($qrPayload);
                    ?>
                    <?php for ($i = 0; $i < $perQty; $i++): ?>
                        <div class="lbl size-<?= h($size) ?>">
                            <div class="qr">
                                <img src="<?= h($qrImgSrc) ?>" alt="QR">
                            </div>
                            <div class="txt">
                                <div class="name"><?= h((string)$p['name']) ?></div>
                                <div class="sku">SKU: <?= h((string)($p['sku'] ?? '')) ?></div>
                                <div class="code"><?= h($qrPayload) ?></div>
                            </div>
                        </div>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// QR / etikett utskriftsvisning (GET: label=1&product_id=..)
// ---------------------------------------------------------
$isLabelView = (get_int('label', 0) === 1);
$labelProductId = get_int('product_id', 0);

if ($isLabelView) {
    if ($labelProductId <= 0) {
        ?>
        <div class="alert alert-danger mt-3">Ugyldig vare (product_id).</div>
        <?php
        return;
    }

    $product = fetchProductById($pdo, $labelProductId, $hasImageColumn, $hasManufacturerColumn);
    if (!$product) {
        ?>
        <div class="alert alert-danger mt-3">Fant ikke varen.</div>
        <?php
        return;
    }

    $qty  = max(1, min(500, get_int('qty', 24)));
    $size = get_str('size', '70x37');
    $allowedSizes = ['50x30','60x40','70x50','70x37'];
    if (!in_array($size, $allowedSizes, true)) $size = '70x37';

    $qrPayload = 'p:' . (int)$product['id'];

    $qrPx = 240;
    if ($size === '60x40') $qrPx = 320;
    if ($size === '70x50') $qrPx = 420;
    if ($size === '70x37') $qrPx = 320;

    $qrImgSrc = 'https://api.qrserver.com/v1/create-qr-code/?size='
        . $qrPx . 'x' . $qrPx
        . '&data=' . rawurlencode($qrPayload);

    $gridClass = 'grid-' . $size;
    ?>
    <style>
        @page {
            size: A4;
            margin: 0;
        }
        html, body { margin: 0; padding: 0; }

        @media print {
            body * { visibility: hidden !important; }
            #label-print-area, #label-print-area * { visibility: visible !important; }
            #label-print-area { position: absolute; left: 0; top: 0; width: 210mm; height: 297mm; }
        }

        #label-print-area { padding: 10px; }
        .label-toolbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .label-grid { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-start; }
        .label-grid.grid-70x37 {
            display: grid;
            grid-template-columns: repeat(3, 70mm);
            grid-auto-rows: 37mm;
            width: 210mm;
        }

        .lbl {
            border: 1px dashed rgba(0,0,0,.25);
            border-radius: 8px;
            display: flex;
            align-items: flex-start;
            gap: 2.5mm;
            padding: 2mm 2.5mm;
            background: #fff;
            overflow: hidden;
            box-sizing: border-box;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .lbl.size-50x30 { width: 50mm; height: 30mm; }
        .lbl.size-60x40 { width: 60mm; height: 40mm; }
        .lbl.size-70x50 { width: 70mm; height: 50mm; }
        .lbl.size-70x37 { width: 70mm; height: 37mm; }

        .lbl .qr {
            width: 22mm;
            height: 22mm;
            flex: 0 0 auto;
            display: grid;
            place-items: center;
        }
        .lbl.size-60x40 .qr { width: 28mm; height: 28mm; }
        .lbl.size-70x50 .qr { width: 34mm; height: 34mm; }
        .lbl.size-70x37 .qr { width: 23mm; height: 23mm; }

        .qr img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            image-rendering: pixelated;
        }

        .lbl .txt {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            line-height: 1.05;
        }

        .lbl .txt .name {
            font-weight: 700;
            font-size: 11px;
            white-space: normal;
            word-break: break-word;

            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;

            max-height: 2.2em;
            overflow: hidden;
        }

        .lbl .txt .sku {
            font-size: 10.5px;
            opacity: .9;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .lbl .txt .code {
            font-size: 10px;
            opacity: .75;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media print {
            .label-toolbar { display: none !important; }

            #label-print-area { padding: 0 !important; }

            .label-grid { gap: 0 !important; }
            .lbl {
                border: none !important;
                border-radius: 0 !important;
                padding: 2mm 2.5mm !important;
            }

            .lbl.size-70x37 .txt .name { font-size: 10.5px; max-height: 2.1em; }
            .lbl.size-70x37 .txt .sku  { font-size: 10.0px; }
            .lbl.size-70x37 .txt .code { font-size: 9.5px; }
        }
    </style>

    <div id="label-print-area">
        <div class="label-toolbar">
            <div>
                <div class="fw-semibold">Utskrift av QR-etiketter</div>
                <div class="text-muted small">
                    Vare: <strong><?= h((string)$product['name']) ?></strong>
                    · SKU: <strong><?= h((string)($product['sku'] ?? '')) ?></strong>
                    · QR: <code><?= h($qrPayload) ?></code>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <form class="d-flex gap-2 flex-wrap" method="get">
                    <input type="hidden" name="page" value="logistikk_products">
                    <input type="hidden" name="label" value="1">
                    <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">

                    <div class="input-group input-group-sm" style="width: 150px;">
                        <span class="input-group-text">Antall</span>
                        <input class="form-control" type="number" min="1" max="500" name="qty" value="<?= (int)$qty ?>">
                    </div>

                    <div class="input-group input-group-sm" style="width: 190px;">
                        <span class="input-group-text">Størrelse</span>
                        <select class="form-select" name="size">
                            <option value="70x37" <?= $size==='70x37'?'selected':'' ?>>70×37 mm (24/A4)</option>
                            <option value="50x30" <?= $size==='50x30'?'selected':'' ?>>50×30 mm</option>
                            <option value="60x40" <?= $size==='60x40'?'selected':'' ?>>60×40 mm</option>
                            <option value="70x50" <?= $size==='70x50'?'selected':'' ?>>70×50 mm</option>
                        </select>
                    </div>

                    <button class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-repeat"></i> Oppdater
                    </button>
                </form>

                <button class="btn btn-sm btn-primary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Skriv ut
                </button>

                <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_products&edit_id=<?= (int)$product['id'] ?>">
                    <i class="bi bi-arrow-left"></i> Tilbake
                </a>
            </div>
        </div>

        <div class="label-grid <?= h($gridClass) ?>">
            <?php for ($i = 0; $i < $qty; $i++): ?>
                <div class="lbl size-<?= h($size) ?>">
                    <div class="qr">
                        <img src="<?= h($qrImgSrc) ?>" alt="QR">
                    </div>
                    <div class="txt">
                        <div class="name"><?= h((string)$product['name']) ?></div>
                        <div class="sku">SKU: <?= h((string)($product['sku'] ?? '')) ?></div>
                        <div class="code"><?= h($qrPayload) ?></div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <?php
    return;
}

// -----------------------------
// POST-handling (kun varetyper + bilde)
// -----------------------------
$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$canWarehouseWrite) {
        $errors[] = 'Du har ikke rettighet til å endre varer (krever warehouse_write eller admin).';
    } else {
        $action = post_str('action');

        try {
            if ($action === 'create_product') {
                $name = post_str('product_name');
                $manufacturer = $GLOBALS['hasManufacturerColumn'] ? post_str('manufacturer') : '';
                $sku  = post_str('sku');
                $unit = post_str('unit') ?: 'stk';
                $categoryId = post_int('category_id');
                $minStock = post_float('min_stock');

                if ($name === '') throw new RuntimeException('Varenavn mangler.');
                if ($sku === '')  throw new RuntimeException('Produktnummer / SKU mangler.');

                $stmt = $pdo->prepare("
                    INSERT INTO inv_products (
                        category_id,
                        sku,
                        name" . ($GLOBALS['hasManufacturerColumn'] ? ", manufacturer" : "") . ",
                        unit,
                        min_stock,
                        is_active" . ($GLOBALS['hasImageColumn'] ? ", image_path" : "") . "
                    )
                    VALUES (
                        :cid,
                        :sku,
                        :name" . ($GLOBALS['hasManufacturerColumn'] ? ", :manufacturer" : "") . ",
                        :unit,
                        :min_stock,
                        1" . ($GLOBALS['hasImageColumn'] ? ", NULL" : "") . "
                    )
                ");
                $params = [
                    ':cid' => $categoryId > 0 ? $categoryId : null,
                    ':sku' => $sku,
                    ':name' => $name,
                    ':unit' => $unit,
                    ':min_stock' => $minStock,
                ];
                if ($GLOBALS['hasManufacturerColumn']) {
                    $params[':manufacturer'] = ($manufacturer !== '') ? $manufacturer : null;
                }
                $stmt->execute($params);

                $newId = (int)$pdo->lastInsertId();

                if ($GLOBALS['hasImageColumn'] && isset($_FILES['product_image'])) {
                    $newPath = handleProductImageUpload($newId, $_FILES['product_image'], $GLOBALS['uploadFsBase'], $GLOBALS['uploadRelBase']);
                    if ($newPath) {
                        $stmt = $pdo->prepare("UPDATE inv_products SET image_path = :p WHERE id = :id LIMIT 1");
                        $stmt->execute([':p' => $newPath, ':id' => $newId]);
                    }
                }

                $successMessage = 'Varetype opprettet.';
                $editId = $newId;
            }

            if ($action === 'update_product') {
                $id = post_int('id');
                if ($id <= 0) throw new RuntimeException('Ugyldig vare.');

                $name = post_str('product_name');
                $manufacturer = $GLOBALS['hasManufacturerColumn'] ? post_str('manufacturer') : '';
                $sku  = post_str('sku');
                $unit = post_str('unit') ?: 'stk';
                $categoryId = post_int('category_id');
                $minStock = post_float('min_stock');
                $isActive = post_int('is_active') ? 1 : 0;

                if ($name === '') throw new RuntimeException('Varenavn mangler.');
                if ($sku === '')  throw new RuntimeException('Produktnummer / SKU mangler.');

                $oldImagePath = '';
                if ($GLOBALS['hasImageColumn']) {
                    $stmt = $pdo->prepare("SELECT image_path FROM inv_products WHERE id = :id LIMIT 1");
                    $stmt->execute([':id' => $id]);
                    $oldImagePath = (string)($stmt->fetchColumn() ?: '');
                }

                $sql = "
                    UPDATE inv_products
                    SET
                        category_id = :cid,
                        sku = :sku,
                        name = :name,
                        unit = :unit,
                        min_stock = :min_stock,
                        is_active = :active" . ($GLOBALS['hasManufacturerColumn'] ? ",
                        manufacturer = :manufacturer" : "") . "
                    WHERE id = :id
                    LIMIT 1
                ";
                $params = [
                    ':cid' => $categoryId > 0 ? $categoryId : null,
                    ':sku' => $sku,
                    ':name' => $name,
                    ':unit' => $unit,
                    ':min_stock' => $minStock,
                    ':active' => $isActive,
                    ':id' => $id,
                ];
                if ($GLOBALS['hasManufacturerColumn']) {
                    $params[':manufacturer'] = ($manufacturer !== '') ? $manufacturer : null;
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $removeImage = post_int('remove_image') === 1;

                if ($GLOBALS['hasImageColumn']) {
                    if ($removeImage && $oldImagePath) {
                        $stmt = $pdo->prepare("UPDATE inv_products SET image_path = NULL WHERE id = :id LIMIT 1");
                        $stmt->execute([':id' => $id]);
                        tryDeleteFileByWebPath($oldImagePath);
                        $oldImagePath = '';
                    }

                    if (isset($_FILES['product_image'])) {
                        $newPath = handleProductImageUpload($id, $_FILES['product_image'], $GLOBALS['uploadFsBase'], $GLOBALS['uploadRelBase']);
                        if ($newPath) {
                            $stmt = $pdo->prepare("UPDATE inv_products SET image_path = :p WHERE id = :id LIMIT 1");
                            $stmt->execute([':p' => $newPath, ':id' => $id]);

                            if ($oldImagePath) {
                                tryDeleteFileByWebPath($oldImagePath);
                            }
                        }
                    }
                }

                $successMessage = 'Varetype oppdatert.';
                $editId = $id;
            }

            if ($action === 'archive_product') {
                $id = post_int('id');
                if ($id <= 0) throw new RuntimeException('Ugyldig vare.');

                $stmt = $pdo->prepare("UPDATE inv_products SET is_active = 0 WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $id]);

                $successMessage = 'Varen er skjult (satt som inaktiv).';
                $editId = $id;
            }

            if ($action === 'activate_product') {
                $id = post_int('id');
                if ($id <= 0) throw new RuntimeException('Ugyldig vare.');

                $stmt = $pdo->prepare("UPDATE inv_products SET is_active = 1 WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $id]);

                $successMessage = 'Varen er gjort synlig igjen.';
                $editId = $id;
            }

            if ($action === 'delete_product') {
                $id = post_int('id');
                if ($id <= 0) throw new RuntimeException('Ugyldig vare.');

                $usage = getProductUsageInfo($pdo, $id);
                if (!$usage['can_delete']) {
                    throw new RuntimeException('Kan ikke slette varetype som har historikk eller batcher. Skjul den i stedet.');
                }

                if ($GLOBALS['hasImageColumn']) {
                    $stmt = $pdo->prepare("SELECT image_path FROM inv_products WHERE id = :id LIMIT 1");
                    $stmt->execute([':id' => $id]);
                    $img = (string)($stmt->fetchColumn() ?: '');
                    if ($img) tryDeleteFileByWebPath($img);
                }

                $stmt = $pdo->prepare("DELETE FROM inv_products WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $id]);

                $successMessage = 'Varetype slettet.';
                $editId = 0;
            }

        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// -----------------------------
// Filters / fetch
// -----------------------------
$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'active' => (string)($_GET['active'] ?? '1'),
    'category_id' => (int)($_GET['category_id'] ?? 0),
];

$sort = normalizeSort((string)($_GET['sort'] ?? 'name'));
$dir  = normalizeSortDir((string)($_GET['dir'] ?? 'asc'));

$categoryOptions = fetchCategoryOptions($pdo);
$products = fetchProductsList($pdo, $filters, $hasBatches, $hasImageColumn, $hasManufacturerColumn, $sort, $dir);

$editProduct = null;
if ($editId > 0) {
    $editProduct = fetchProductById($pdo, $editId, $hasImageColumn, $hasManufacturerColumn);
    if (!$editProduct) {
        $errors[] = 'Fant ikke varen du prøver å redigere.';
        $editId = 0;
    }
}

?>
<style>
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
.product-thumb-lg {
    width: 56px;
    height: 56px;
}

.btn-icon {
    width: 36px;
    height: 34px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
}

.qr-check-col { width: 42px; }

.inline-form {
    display: inline;
    margin: 0;
}

.badge-soft {
    background: #f1f3f5;
    color: #495057;
}

.products-table {
    --bs-table-bg: transparent;
}

.products-table thead th {
    white-space: nowrap;
    vertical-align: middle;
}

.products-table tbody td {
    vertical-align: middle;
}

.products-table .col-actions {
    width: 260px;
    min-width: 260px;
    text-align: center !important;
}

.product-actions-cell {
    text-align: center;
    vertical-align: middle !important;
}

.product-actions {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    flex-wrap: wrap;
    padding: 6px 8px;
    border-radius: 12px;
    background: transparent;
}

.product-actions .btn,
.product-actions .inline-form button {
    box-shadow: none;
}

.product-actions .btn-outline-primary {
    min-width: 88px;
}

.products-table tbody tr:hover .product-actions {
    background: rgba(0, 0, 0, 0.025);
}

.products-table tbody tr td {
    background-clip: padding-box;
}

.sort-link {
    color: inherit;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
}

.sort-link:hover {
    text-decoration: underline;
}

.sort-link i {
    font-size: .9em;
}

@media (max-width: 1199.98px) {
    .products-table .col-actions {
        min-width: 220px;
        width: 220px;
    }

    .product-actions .btn-outline-primary {
        min-width: 74px;
    }
}
</style>

<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h3 class="mb-0">Varer</h3>
            <div class="text-muted">Opprett, rediger, slett eller skjul varetyper. Innregistrering av varer gjøres i Varemottak.</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk">
                <i class="bi bi-arrow-left"></i> Til oversikt
            </a>
            <a class="btn btn-sm btn-outline-primary" href="/?page=logistikk_receipts">
                <i class="bi bi-box-arrow-in-down"></i> Varemottak
            </a>
            <a class="btn btn-sm btn-outline-warning" href="/?page=inv_out_shop" title="Uttak">
                <i class="bi bi-box-arrow-up-right"></i> Uttak
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
            Du har lesetilgang til varelager, men ikke skrivetilgang.
            Endringer krever rollen <code>warehouse_write</code> (eller <code>admin</code>).
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-xxl-4">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-semibold">
                        <?= $editProduct ? 'Rediger varetype' : 'Ny varetype' ?>
                    </span>
                    <?php if ($editProduct): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_products">Avbryt</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$canWarehouseWrite): ?>
                        <div class="alert alert-secondary mb-0">
                            Opprett/rediger er deaktivert (krever <code>warehouse_write</code> eller <code>admin</code>).
                        </div>
                    <?php else: ?>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="<?= $editProduct ? 'update_product' : 'create_product' ?>">
                            <?php if ($editProduct): ?>
                                <input type="hidden" name="id" value="<?= (int)$editProduct['id'] ?>">
                            <?php endif; ?>

                            <div class="mb-2">
                                <label class="form-label">Varenavn</label>
                                <input class="form-control" name="product_name" required value="<?= h($editProduct['name'] ?? '') ?>">
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Produsent</label>
                                <input class="form-control" name="manufacturer" value="<?= h($editProduct['manufacturer'] ?? '') ?>" placeholder="F.eks. Ubiquiti, Nexans, Cisco">
                            </div>

                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label">Produktnummer / SKU</label>
                                    <input class="form-control" name="sku" required value="<?= h($editProduct['sku'] ?? '') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Enhet</label>
                                    <input class="form-control" name="unit" value="<?= h($editProduct['unit'] ?? 'stk') ?>">
                                </div>
                            </div>

                            <div class="row g-2 mt-1">
                                <div class="col-8">
                                    <label class="form-label">Kategori</label>
                                    <select class="form-select" name="category_id">
                                        <option value="0">— Ingen</option>
                                        <?php foreach ($categoryOptions as $c): ?>
                                            <?php $selected = $editProduct && (int)$editProduct['category_id'] === (int)$c['id']; ?>
                                            <option value="<?= (int)$c['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                                                <?= h($c['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label class="form-label">Min. lager</label>
                                    <input class="form-control" name="min_stock" inputmode="decimal"
                                           value="<?= h((string)($editProduct['min_stock'] ?? '0')) ?>">
                                </div>
                            </div>

                            <?php if ($editProduct): ?>
                                <div class="mt-2">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="is_active">
                                        <option value="1" <?= ((int)$editProduct['is_active'] === 1) ? 'selected' : '' ?>>Aktiv</option>
                                        <option value="0" <?= ((int)$editProduct['is_active'] === 0) ? 'selected' : '' ?>>Inaktiv / skjult</option>
                                    </select>
                                </div>

                                <div class="alert alert-light border mt-3 mb-0">
                                    <div class="fw-semibold mb-1">Bruk / historikk</div>
                                    <div class="small">
                                        Bevegelser: <strong><?= (int)($editProduct['movement_count'] ?? 0) ?></strong><br>
                                        Batcher: <strong><?= (int)($editProduct['batch_count'] ?? 0) ?></strong><br>
                                        På lager nå: <strong><?= h(fmt_qty((float)($editProduct['qty_on_hand'] ?? 0))) ?></strong> <?= h((string)($editProduct['unit'] ?? 'stk')) ?>
                                    </div>
                                    <div class="small text-muted mt-2">
                                        Varer uten historikk kan slettes. Varer som har vært brukt bør skjules ved å sette dem som inaktive.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="mt-3">
                                <label class="form-label">Bilde</label>

                                <?php if (!$hasImageColumn): ?>
                                    <div class="alert alert-warning small mb-2">
                                        Bilde er ikke aktivert (mangler DB-kolonne <code>inv_products.image_path</code> eller mangler rettigheter til å opprette den).
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <?php $img = trim((string)($editProduct['image_path'] ?? '')); ?>
                                        <?php if ($img !== ''): ?>
                                            <a class="product-thumb-link" href="<?= h($img) ?>" target="_blank" rel="noopener">
                                                <span class="product-thumb product-thumb-lg" title="Klikk for å åpne bilde">
                                                    <img src="<?= h($img) ?>" alt="Bilde">
                                                </span>
                                            </a>
                                            <div class="small text-muted">Klikk for å åpne</div>
                                        <?php else: ?>
                                            <span class="product-thumb product-thumb-lg" title="Ingen bilde">
                                                <i class="bi bi-box-seam text-muted" style="font-size:1.4rem;"></i>
                                            </span>
                                            <div class="small text-muted">Ingen bilde</div>
                                        <?php endif; ?>
                                    </div>

                                    <input class="form-control" type="file" name="product_image" accept="image/*">
                                    <div class="form-text">Tillatt: JPG/PNG/WEBP/GIF. Maks 3 MB.</div>

                                    <?php if (!empty($img)): ?>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" value="1" id="remove_image" name="remove_image">
                                            <label class="form-check-label" for="remove_image">Fjern bilde</label>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <div class="mt-3 d-flex gap-2 flex-wrap">
                                <button class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i> <?= $editProduct ? 'Lagre' : 'Opprett' ?>
                                </button>

                                <?php if ($editProduct): ?>
                                    <a class="btn btn-outline-success"
                                       href="/?page=logistikk_receipts&product_id=<?= (int)$editProduct['id'] ?>">
                                        <i class="bi bi-box-arrow-in-down me-1"></i> Registrer varemottak
                                    </a>

                                    <a class="btn btn-outline-warning"
                                       href="/?page=inv_out_shop&product_id=<?= (int)$editProduct['id'] ?>"
                                       title="<?= h('Uttak av ' . (string)$editProduct['name']) ?>">
                                        <i class="bi bi-box-arrow-up-right me-1"></i> Uttak
                                    </a>

                                    <a class="btn btn-outline-dark"
                                       href="/?page=logistikk_products&label=1&product_id=<?= (int)$editProduct['id'] ?>&qty=24&size=70x37"
                                       title="Generer og skriv ut QR-etiketter (70×37 standard)">
                                        <i class="bi bi-qr-code me-1"></i> QR-etikett
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <?php if ($editProduct): ?>
                            <hr>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php if ((int)$editProduct['can_delete'] === 1): ?>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Slette varetypen permanent? Dette kan ikke angres.');">
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="id" value="<?= (int)$editProduct['id'] ?>">
                                        <button class="btn btn-outline-danger">
                                            <i class="bi bi-trash me-1"></i> Slett permanent
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <?php if ((int)$editProduct['is_active'] === 1): ?>
                                        <form method="post" class="inline-form" onsubmit="return confirm('Skjule varen? Den blir satt som inaktiv, men historikken beholdes.');">
                                            <input type="hidden" name="action" value="archive_product">
                                            <input type="hidden" name="id" value="<?= (int)$editProduct['id'] ?>">
                                            <button class="btn btn-outline-secondary">
                                                <i class="bi bi-eye-slash me-1"></i> Skjul vare
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="action" value="activate_product">
                                            <input type="hidden" name="id" value="<?= (int)$editProduct['id'] ?>">
                                            <button class="btn btn-outline-success">
                                                <i class="bi bi-eye me-1"></i> Vis vare igjen
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-xxl-8">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-semibold">Vareliste</span>
                    <span class="text-muted small">Søk / filtrer</span>
                </div>

                <div class="card-body">
                    <form class="row g-2" method="get">
                        <input type="hidden" name="page" value="logistikk_products">
                        <input type="hidden" name="sort" value="<?= h($sort) ?>">
                        <input type="hidden" name="dir" value="<?= h($dir) ?>">

                        <div class="col-12 col-md-5">
                            <label class="form-label">Søk</label>
                            <input class="form-control" name="q" placeholder="Navn, produsent eller produktnummer/SKU" value="<?= h($filters['q']) ?>">
                        </div>

                        <div class="col-6 col-md-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="active">
                                <option value="" <?= ($filters['active'] === '') ? 'selected' : '' ?>>Alle</option>
                                <option value="1" <?= ($filters['active'] === '1') ? 'selected' : '' ?>>Aktive</option>
                                <option value="0" <?= ($filters['active'] === '0') ? 'selected' : '' ?>>Inaktive / skjulte</option>
                            </select>
                        </div>

                        <div class="col-6 col-md-4">
                            <label class="form-label">Kategori</label>
                            <select class="form-select" name="category_id">
                                <option value="0">Alle</option>
                                <?php foreach ($categoryOptions as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= ($filters['category_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                                        <?= h($c['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 d-flex gap-2 mt-2 flex-wrap">
                            <button class="btn btn-outline-primary">
                                <i class="bi bi-search me-1"></i> Filtrer
                            </button>
                            <a class="btn btn-outline-secondary" href="/?page=logistikk_products">Nullstill</a>

                            <a class="btn btn-outline-dark"
                               href="/?page=logistikk_products&bulk_label=1&mode=filtered&per_qty=1&size=70x37&q=<?= rawurlencode($filters['q']) ?>&active=<?= rawurlencode($filters['active']) ?>&category_id=<?= (int)$filters['category_id'] ?>"
                               title="Skriv ut QR for filtrerte varer (70×37 standard)">
                                <i class="bi bi-qr-code me-1"></i> QR for filtrerte
                            </a>

                            <a class="btn btn-outline-dark"
                               href="/?page=logistikk_products&bulk_label=1&mode=all&per_qty=1&size=70x37"
                               title="Skriv ut QR for alle varer (70×37 standard)">
                                <i class="bi bi-qr-code-scan me-1"></i> QR for alle
                            </a>
                        </div>
                    </form>
                </div>

                <div class="card-body pt-0">
                    <form class="d-flex gap-2 flex-wrap align-items-end" method="get">
                        <input type="hidden" name="page" value="logistikk_products">
                        <input type="hidden" name="bulk_label" value="1">
                        <input type="hidden" name="mode" value="selected">

                        <div class="input-group input-group-sm" style="width: 170px;">
                            <span class="input-group-text">Per vare</span>
                            <input class="form-control" type="number" min="1" max="50" name="per_qty" value="1">
                        </div>

                        <div class="input-group input-group-sm" style="width: 190px;">
                            <span class="input-group-text">Størrelse</span>
                            <select class="form-select" name="size">
                                <option value="70x37" selected>70×37 mm (24/A4)</option>
                                <option value="50x30">50×30 mm</option>
                                <option value="60x40">60×40 mm</option>
                                <option value="70x50">70×50 mm</option>
                            </select>
                        </div>

                        <button class="btn btn-sm btn-outline-dark">
                            <i class="bi bi-printer me-1"></i> QR for valgte
                        </button>

                        <div class="text-muted small ms-2">
                            Huk av varer i listen under og trykk «QR for valgte».
                        </div>

                        <div class="w-100"></div>

                        <div class="table-responsive w-100 mt-2">
                            <table class="table table-sm table-striped mb-0 align-middle products-table">
                                <thead>
                                <tr>
                                    <th class="qr-check-col">
                                        <input type="checkbox" id="selectAllProducts" title="Velg alle på siden">
                                    </th>
                                    <th style="width:64px;">
                                        <a class="sort-link" href="<?= h(buildSortUrl('image', $filters, $sort, $dir)) ?>">
                                            Bilde<?= sortIndicator('image', $sort, $dir) ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="sort-link" href="<?= h(buildSortUrl('name', $filters, $sort, $dir)) ?>">
                                            Vare<?= sortIndicator('name', $sort, $dir) ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="sort-link" href="<?= h(buildSortUrl('manufacturer', $filters, $sort, $dir)) ?>">
                                            Produsent<?= sortIndicator('manufacturer', $sort, $dir) ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="sort-link" href="<?= h(buildSortUrl('sku', $filters, $sort, $dir)) ?>">
                                            Produktnummer / SKU<?= sortIndicator('sku', $sort, $dir) ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="sort-link" href="<?= h(buildSortUrl('category', $filters, $sort, $dir)) ?>">
                                            Kategori<?= sortIndicator('category', $sort, $dir) ?>
                                        </a>
                                    </th>
                                    <th class="text-end">
                                        <a class="sort-link" href="<?= h(buildSortUrl('qty_on_hand', $filters, $sort, $dir)) ?>">
                                            På lager (total)<?= sortIndicator('qty_on_hand', $sort, $dir) ?>
                                        </a>
                                    </th>
                                    <th class="text-end">
                                        <a class="sort-link" href="<?= h(buildSortUrl('min_stock', $filters, $sort, $dir)) ?>">
                                            Min.<?= sortIndicator('min_stock', $sort, $dir) ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="sort-link" href="<?= h(buildSortUrl('status', $filters, $sort, $dir)) ?>">
                                            Status<?= sortIndicator('status', $sort, $dir) ?>
                                        </a>
                                    </th>
                                    <th class="col-actions">Handling</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($products as $p): ?>
                                    <?php
                                    $minStock = (float)$p['min_stock'];
                                    $onHand = (float)($p['qty_on_hand'] ?? 0);
                                    $img = trim((string)($p['image_path'] ?? ''));
                                    $hasHistory = ((int)($p['has_history'] ?? 0) === 1);
                                    ?>
                                    <tr>
                                        <td>
                                            <input class="form-check-input product-check" type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>">
                                        </td>

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

                                        <td class="fw-semibold">
                                            <?= h($p['name']) ?>
                                            <?php if ($hasHistory): ?>
                                                <span class="badge badge-soft ms-1">Historikk</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($p['manufacturer'] ?? '—') ?></td>
                                        <td><?= h($p['sku'] ?? '') ?></td>
                                        <td><?= h($p['category_name'] ?? '—') ?></td>
                                        <td class="text-end">
                                            <?= h(fmt_qty($onHand)) ?>
                                            <span class="text-muted"><?= h($p['unit'] ?? 'stk') ?></span>
                                        </td>
                                        <td class="text-end"><?= h(fmt_qty($minStock)) ?></td>
                                        <td>
                                            <?php if ((int)$p['is_active'] === 1): ?>
                                                <span class="badge bg-success">Aktiv</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Skjult</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="product-actions-cell">
                                            <div class="product-actions">
                                                <a class="btn btn-sm btn-outline-primary"
                                                   href="/?page=logistikk_products&edit_id=<?= (int)$p['id'] ?>">
                                                    <i class="bi bi-pencil"></i> Åpne
                                                </a>

                                                <a class="btn btn-sm btn-outline-success btn-icon"
                                                   href="/?page=logistikk_receipts&product_id=<?= (int)$p['id'] ?>"
                                                   title="<?= h('Registrer varemottak for ' . (string)$p['name']) ?>">
                                                    <i class="bi bi-box-arrow-in-down"></i>
                                                </a>

                                                <a class="btn btn-sm btn-outline-dark btn-icon"
                                                   href="/?page=logistikk_products&label=1&product_id=<?= (int)$p['id'] ?>&qty=24&size=70x37"
                                                   title="Skriv ut QR-etikett (70×37 standard)">
                                                    <i class="bi bi-qr-code"></i>
                                                </a>

                                                <?php if ((int)$p['is_active'] === 1): ?>
                                                    <form method="post" class="inline-form" onsubmit="return confirm('Skjule varen? Historikk beholdes.');">
                                                        <input type="hidden" name="action" value="archive_product">
                                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                                        <button class="btn btn-sm btn-outline-secondary btn-icon" title="Skjul vare">
                                                            <i class="bi bi-eye-slash"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post" class="inline-form">
                                                        <input type="hidden" name="action" value="activate_product">
                                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                                        <button class="btn btn-sm btn-outline-secondary btn-icon" title="Vis vare igjen">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($products)): ?>
                                    <tr><td colspan="10" class="text-muted p-3">Ingen treff.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>

                <div class="card-footer small text-muted">
                    Standard etikett: <strong>70×37 mm</strong> (24 per A4, 3×8), marginless print (@page margin 0).
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var selectAll = document.getElementById('selectAllProducts');
    if (!selectAll) return;

    selectAll.addEventListener('change', function () {
        var checks = document.querySelectorAll('.product-check');
        for (var i = 0; i < checks.length; i++) {
            checks[i].checked = selectAll.checked;
        }
    });
})();
</script>