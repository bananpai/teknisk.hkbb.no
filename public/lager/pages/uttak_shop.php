<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\lager\pages\uttak_shop.php
// Uttak wizard steg 2/3 - plukk utstyr til varekorg fra valgt lokasjon
//
// Endret 2026-03-11:
// - Støtter både:
//   1) Uttak mot arbeidsordre
//   2) Flytting til annet lager / bil / forbrukslager
// - Leser action_mode fra wizard-session
// - Krever arbeidsordre kun ved action_mode=workorder
// - Krever target_location_id kun ved action_mode=transfer
// - Viser riktig kontekst øverst i UI
// - Lagrer lokal arbeidsordre kun ved workorder-modus
//
// Tidligere:
// - Lokal arbeidsordre lagres i target_work_order_local_id
// - target_work_order_id brukes ikke til inv_work_orders.id
// - Dette hindrer FK-feil mot work_orders ved checkout

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

$u   = require_lager_login();
$pdo = get_pdo();

if (!function_exists('h')) {
    function h(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('tableExists')) {
    function tableExists(PDO $pdo, string $table): bool
    {
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
}
if (!function_exists('columnExists')) {
    function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND column_name = :c
            ");
            $stmt->execute([':t' => $table, ':c' => $column]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
if (!function_exists('post_int')) {
    function post_int(string $key): int
    {
        $raw = trim((string)($_POST[$key] ?? '0'));
        $raw = str_replace(' ', '', $raw);
        if ($raw === '') {
            return 0;
        }
        $raw2 = str_replace(',', '.', $raw);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $raw2)) {
            return 0;
        }
        $f = (float)$raw2;
        if (abs($f - round($f)) > 1e-9) {
            return 0;
        }
        return (int)round($f);
    }
}

function ensureLocalWorkOrdersTable(PDO $pdo): bool
{
    if (tableExists($pdo, 'inv_work_orders')) {
        return true;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inv_work_orders (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                work_order_number VARCHAR(64) NOT NULL,
                work_order_name VARCHAR(255) DEFAULT NULL,
                source_system VARCHAR(64) NOT NULL DEFAULT 'workforce',
                external_project_id VARCHAR(64) DEFAULT NULL,
                external_statecode VARCHAR(32) DEFAULT NULL,
                created_by VARCHAR(191) DEFAULT NULL,
                updated_by VARCHAR(191) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_work_order_number (work_order_number),
                KEY idx_source_system (source_system),
                KEY idx_last_seen_at (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (\Throwable $e) {
        return false;
    }

    return tableExists($pdo, 'inv_work_orders');
}

function upsertLocalWorkOrder(PDO $pdo, array $wiz, string $username = ''): ?int
{
    $number    = trim((string)($wiz['target_work_order_text'] ?? ''));
    $name      = trim((string)($wiz['target_work_order_name'] ?? ''));
    $projectId = trim((string)($wiz['target_work_order_project_id'] ?? ''));
    $statecode = trim((string)($wiz['target_work_order_statecode'] ?? ''));

    if ($number === '') {
        return null;
    }

    if (!ensureLocalWorkOrdersTable($pdo)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO inv_work_orders
                (work_order_number, work_order_name, source_system, external_project_id, external_statecode, created_by, updated_by)
            VALUES
                (:num, :name, 'workforce', :project_id, :statecode, :created_by, :updated_by)
            ON DUPLICATE KEY UPDATE
                work_order_name      = VALUES(work_order_name),
                external_project_id  = VALUES(external_project_id),
                external_statecode   = VALUES(external_statecode),
                updated_by           = VALUES(updated_by),
                last_seen_at         = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':num'        => $number,
            ':name'       => ($name !== '' ? $name : null),
            ':project_id' => ($projectId !== '' ? $projectId : null),
            ':statecode'  => ($statecode !== '' ? $statecode : null),
            ':created_by' => ($username !== '' ? $username : null),
            ':updated_by' => ($username !== '' ? $username : null),
        ]);

        $stmt2 = $pdo->prepare("
            SELECT id
            FROM inv_work_orders
            WHERE work_order_number = :num
            LIMIT 1
        ");
        $stmt2->execute([':num' => $number]);
        $id = (int)$stmt2->fetchColumn();

        return $id > 0 ? $id : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function getLocationLabel(PDO $pdo, int $locationId): string
{
    if ($locationId <= 0) {
        return '—';
    }

    $locationLabel = '#' . $locationId;
    try {
        $stmt = $pdo->prepare("SELECT code, name FROM inv_locations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $locationId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($r) {
            $code = trim((string)($r['code'] ?? ''));
            $name = trim((string)($r['name'] ?? ''));
            $locationLabel = ($code !== '' ? ($code . ' – ' . $name) : ($name !== '' ? $name : $locationLabel));
        }
    } catch (\Throwable $e) {
    }

    return $locationLabel;
}

$errors      = [];
$success     = null;
$syncWarning = null;

if (!tableExists($pdo, 'inv_products')) {
    $errors[] = 'Mangler tabell inv_products.';
}
if (!tableExists($pdo, 'inv_movements')) {
    $errors[] = 'Mangler tabell inv_movements.';
}
if (!tableExists($pdo, 'inv_locations')) {
    $errors[] = 'Mangler tabell inv_locations.';
}

if (!isset($_SESSION['lager_uttak_wizard']) || !is_array($_SESSION['lager_uttak_wizard'])) {
    header('Location: /lager/?page=uttak');
    exit;
}
$wiz =& $_SESSION['lager_uttak_wizard'];

$actionMode            = (string)($wiz['action_mode'] ?? 'workorder');
$actionMode            = in_array($actionMode, ['workorder', 'transfer'], true) ? $actionMode : 'workorder';
$sourceLocationId      = (int)($wiz['source_location_id'] ?? 0);
$targetLocationId      = (int)($wiz['target_location_id'] ?? 0);
$targetWorkOrderId     = (int)($wiz['target_work_order_id'] ?? 0);
$targetWorkOrderLocalId= (int)($wiz['target_work_order_local_id'] ?? 0);
$targetWorkOrderNumber = trim((string)($wiz['target_work_order_text'] ?? ''));
$targetWorkOrderName   = trim((string)($wiz['target_work_order_name'] ?? ''));
$targetWorkOrderProjId = trim((string)($wiz['target_work_order_project_id'] ?? ''));
$targetWorkOrderState  = trim((string)($wiz['target_work_order_statecode'] ?? ''));

$currentUsername = (string)($u['username'] ?? $u['user'] ?? $u['email'] ?? '');

if ($sourceLocationId <= 0) {
    header('Location: /lager/?page=uttak');
    exit;
}
if ($actionMode === 'workorder' && $targetWorkOrderNumber === '') {
    header('Location: /lager/?page=uttak');
    exit;
}
if ($actionMode === 'transfer' && $targetLocationId <= 0) {
    header('Location: /lager/?page=uttak');
    exit;
}

if (!isset($wiz['cart']) || !is_array($wiz['cart'])) {
    $wiz['cart'] = [];
}

if (!$errors && $actionMode === 'workorder') {
    $localWorkOrderId = upsertLocalWorkOrder($pdo, $wiz, $currentUsername);
    if ($localWorkOrderId !== null) {
        $wiz['target_work_order_local_id'] = $localWorkOrderId;
        $targetWorkOrderLocalId = $localWorkOrderId;
    } elseif (!tableExists($pdo, 'inv_work_orders')) {
        $syncWarning = 'Kunne ikke lagre arbeidsordre lokalt fordi tabellen inv_work_orders ikke finnes eller ikke kunne opprettes.';
    }
}

if (isset($_GET['reset']) && (string)$_GET['reset'] === '1') {
    $_SESSION['lager_uttak_wizard']['cart'] = [];
    $_SESSION['lager_uttak_wizard']['note'] = '';
    header('Location: /lager/?page=uttak_shop');
    exit;
}

if (isset($_GET['back']) && (string)$_GET['back'] === '1') {
    header('Location: /lager/?page=uttak');
    exit;
}

$locationLabel = getLocationLabel($pdo, $sourceLocationId);
$targetLocationLabel = $actionMode === 'transfer' ? getLocationLabel($pdo, $targetLocationId) : '—';

$workOrderLabel = $targetWorkOrderNumber;
if ($targetWorkOrderName !== '') {
    $workOrderLabel .= ' – ' . $targetWorkOrderName;
}

$pageTitle = $actionMode === 'transfer' ? 'Flytt utstyr' : 'Plukk utstyr';
$pageSub   = $actionMode === 'transfer'
    ? 'Steg 2 av 3 — flytt fra ' . $locationLabel
    : 'Steg 2 av 3 — ' . $locationLabel;

$nextBtnText = $actionMode === 'transfer' ? 'Neste' : 'Neste';
$cartPillTitle = $actionMode === 'transfer' ? 'Gå til bekreft flytting' : 'Gå til bekreft uttak';

$unitCol = null;
if (!$errors) {
    foreach (['unit', 'unit_name', 'uom', 'uom_name', 'enhet', 'unit_code', 'uom_code'] as $c) {
        if (columnExists($pdo, 'inv_products', $c)) {
            $unitCol = $c;
            break;
        }
    }
}

$imgCol = null;
if (!$errors) {
    foreach ([
        'image_url', 'img_url', 'thumbnail_url', 'thumb_url',
        'image', 'img', 'photo', 'photo_url', 'picture', 'picture_url',
        'image_path', 'img_path', 'thumbnail', 'thumb'
    ] as $c) {
        if (columnExists($pdo, 'inv_products', $c)) {
            $imgCol = $c;
            break;
        }
    }
}

$productRows   = [];
$productsById  = [];
try {
    $selectUnit = $unitCol ? ", COALESCE(`{$unitCol}`,'') AS unit" : ", '' AS unit";
    $selectImg  = $imgCol ? ", COALESCE(`{$imgCol}`,'') AS img" : ", '' AS img";

    $stmt = $pdo->query("
        SELECT id, name, COALESCE(sku,'') AS sku
        {$selectUnit}
        {$selectImg}
        FROM inv_products
        WHERE is_active = 1
        ORDER BY name
        LIMIT 5000
    ");
    $productRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($productRows as $pr) {
        $pid = (int)($pr['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $productsById[$pid] = [
            'id'   => $pid,
            'name' => (string)($pr['name'] ?? ''),
            'sku'  => (string)($pr['sku'] ?? ''),
            'unit' => trim((string)($pr['unit'] ?? '')),
            'img'  => trim((string)($pr['img'] ?? '')),
        ];
    }
} catch (\Throwable $e) {
    $productRows  = [];
    $productsById = [];
}

$availableByProduct = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            m.product_id,
            SUM(
                CASE
                    WHEN m.type='IN'  THEN m.qty
                    WHEN m.type='OUT' THEN -m.qty
                    ELSE m.qty
                END
            ) AS avail_qty
        FROM inv_movements m
        WHERE m.physical_location_id = :lid
        GROUP BY m.product_id
    ");
    $stmt->execute([':lid' => $sourceLocationId]);

    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $pid = (int)($r['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $availableByProduct[$pid] = (float)($r['avail_qty'] ?? 0);
    }
} catch (\Throwable $e) {
}

$action = (string)($_POST['action'] ?? '');
$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['ajax'] ?? '') === '1');

if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajaxResponse = null;

    if ($action === 'add') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty       = post_int('qty');

        $localErrors = [];

        if ($productId <= 0) {
            $localErrors[] = 'Ugyldig produkt.';
        }
        if ($qty <= 0) {
            $localErrors[] = 'Antall må være et heltall > 0.';
        }

        $availInt = (int)floor(((float)($availableByProduct[$productId] ?? 0.0)) + 1e-9);
        $already  = (int)($wiz['cart'][$productId]['qty'] ?? 0);

        if ($availInt <= 0) {
            $localErrors[] = 'Varen finnes ikke på valgt lagerlokasjon.';
        }

        if ($qty + $already > $availInt) {
            $localErrors[] = "Ikke nok beholdning på valgt lager. Tilgjengelig: {$availInt}.";
        }

        if (!$localErrors) {
            $p = $productsById[$productId] ?? ['name' => '#' . $productId, 'sku' => '', 'unit' => '', 'img' => ''];
            if (!isset($wiz['cart'][$productId])) {
                $wiz['cart'][$productId] = [
                    'qty'  => 0,
                    'name' => (string)$p['name'],
                    'sku'  => (string)$p['sku'],
                ];
            }
            $wiz['cart'][$productId]['qty'] += $qty;
            $success = 'Lagt i kurv.';

            if ($isAjax) {
                $unit = trim((string)($productsById[$productId]['unit'] ?? ''));
                if ($unit === '') {
                    $unit = 'stk';
                }

                $ajaxResponse = [
                    'ok'         => true,
                    'message'    => 'Lagt i kurv.',
                    'cartCount'  => is_array($wiz['cart']) ? count($wiz['cart']) : 0,
                    'productId'  => $productId,
                    'inCartQty'  => (int)($wiz['cart'][$productId]['qty'] ?? 0),
                    'avail'      => $availInt,
                    'unit'       => $unit,
                ];
            }
        } else {
            if ($isAjax) {
                $ajaxResponse = [
                    'ok'     => false,
                    'errors' => $localErrors,
                ];
            } else {
                $errors = array_merge($errors, $localErrors);
            }
        }
    }

    if ($action === 'remove') {
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId > 0) {
            unset($wiz['cart'][$productId]);
            $success = 'Fjernet fra kurv.';
        }
    }

    if ($action === 'next') {
        if (empty($wiz['cart'])) {
            $errors[] = 'Kurven er tom.';
        } else {
            header('Location: /lager/?page=uttak_checkout');
            exit;
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ajaxResponse ?? ['ok' => false, 'errors' => ['Ukjent feil.']], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$cartCount       = is_array($wiz['cart']) ? count($wiz['cart']) : 0;
$q               = trim((string)($_GET['q'] ?? ''));
$locationLabelJs = json_encode($locationLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

?>
<script defer src="https://unpkg.com/@zxing/browser@0.1.5"></script>

<style>
    .wiz-head {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:.75rem;
        flex-wrap:wrap;
        margin-top:.25rem;
        margin-bottom:.5rem;
    }
    .wiz-title { margin:0; font-size:1.15rem; font-weight:700; line-height:1.2; }
    .wiz-sub { color:rgba(0,0,0,.55); font-size:.9rem; margin-top:.15rem; }
    .wiz-progress { height:8px; border-radius:999px; background:rgba(13,110,253,.10); overflow:hidden; }
    .wiz-progress > div { height:100%; width:66.666%; background:rgba(13,110,253,.55); }

    .wiz-info-card {
        border: 1px solid rgba(13,110,253,.10);
        background: rgba(13,110,253,.05);
        border-radius: 14px;
        padding: .8rem .95rem;
        margin-bottom: .65rem;
    }
    .wiz-info-grid {
        display:grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap:.6rem 1rem;
    }
    .wiz-info-k {
        font-size:.8rem;
        color:rgba(0,0,0,.55);
        margin-bottom:.1rem;
    }
    .wiz-info-v {
        font-weight:600;
        word-break:break-word;
    }

    .toolbar {
        position: sticky;
        top: 64px;
        z-index: 20;
        background: #fff;
        border: 1px solid rgba(0,0,0,.06);
        border-radius: 14px;
        padding: 10px;
        box-shadow: 0 10px 20px rgba(0,0,0,.04);
    }
    @media (max-width: 991.98px) { .toolbar { top: 62px; } }

    #productSearch { max-width: 420px; }

    .table td, .table th { vertical-align: middle; }
    .table > :not(caption) > * > * { padding-top: .45rem; padding-bottom: .45rem; }
    #scanVideo { width: 100%; border-radius: 10px; background: #000; }
    .scan-hint { font-size: .9rem; }

    .nextbar {
        position: sticky;
        bottom: 0;
        z-index: 15;
        background: rgba(255,255,255,.92);
        backdrop-filter: blur(6px);
        border-top: 1px solid rgba(0,0,0,.06);
        padding: .5rem 0;
        margin-top: .5rem;
    }

    .pill {
        display:inline-flex;
        align-items:center;
        gap:.35rem;
        padding:.25rem .55rem;
        border-radius:999px;
        border:1px solid rgba(0,0,0,.08);
        background:rgba(0,0,0,.02);
        font-size:.85rem;
        color:rgba(0,0,0,.75);
    }
    a.pill { text-decoration: none; }
    a.pill:hover { background: rgba(0,0,0,.04); }

    .qty-stepper .btn { width: 52px; }
    .qty-stepper input { text-align: right; }

    .prod-row {
        border: 1px solid rgba(0,0,0,.06);
        border-radius: 14px;
        background: #fff;
    }
    .prod-row td { background: transparent !important; }
    .prod-inner {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap: .75rem;
        flex-wrap: wrap;
    }
    .prod-left {
        display:flex;
        align-items:flex-start;
        gap: .7rem;
        min-width: 260px;
        flex: 1 1 420px;
    }
    .prod-thumb {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid rgba(0,0,0,.08);
        background: rgba(0,0,0,.03);
        display:flex;
        align-items:center;
        justify-content:center;
        flex: 0 0 auto;
    }
    .prod-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display:block;
    }
    .prod-name {
        font-weight: 700;
        line-height: 1.15;
        font-size: 1.05rem;
        word-break: break-word;
    }
    .prod-meta { margin-top: .2rem; }
    .prod-right {
        display:flex;
        align-items:flex-start;
        justify-content:flex-end;
        gap: .75rem;
        flex: 0 0 auto;
        min-width: 300px;
    }
    .prod-avail {
        min-width: 140px;
        text-align: right;
    }
    .prod-actions {
        min-width: 240px;
        display:flex;
        flex-direction: column;
        align-items: flex-end;
        gap: .35rem;
    }

    @media (max-width: 575.98px) {
        .prod-right { width: 100%; justify-content: space-between; }
        .prod-actions { min-width: 0; width: 100%; align-items: stretch; }
        .prod-avail { text-align: left; min-width: 0; }
    }

    .toast-area {
        position: fixed;
        top: 12px;
        right: 12px;
        z-index: 2000;
        display: flex;
        flex-direction: column;
        gap: 10px;
        pointer-events: none;
    }
    .mini-toast {
        pointer-events: none;
        min-width: 220px;
        max-width: 320px;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid rgba(0,0,0,.08);
        box-shadow: 0 10px 24px rgba(0,0,0,.08);
        color: #0b0f14;
        background: #fff;
        opacity: 0;
        transform: translateY(-8px);
        transition: opacity .18s ease, transform .18s ease;
        font-size: .95rem;
        line-height: 1.2;
    }
    .mini-toast.show {
        opacity: 1;
        transform: translateY(0);
    }
    .mini-toast.success { border-color: rgba(25,135,84,.25); background: rgba(25,135,84,.10); }
    .mini-toast.danger  { border-color: rgba(220,53,69,.25); background: rgba(220,53,69,.10); }
    .mini-toast.warning { border-color: rgba(255,193,7,.35); background: rgba(255,193,7,.15); }

    .toolbar .form-control,
    .prod-actions .form-control,
    .modal .form-control,
    .modal .btn,
    .toolbar .btn {
        font-size: .88rem;
        line-height: 1.15;
    }

    .toolbar .form-control {
        padding-top: .42rem;
        padding-bottom: .42rem;
    }
    .prod-actions .form-control {
        padding-top: .35rem;
        padding-bottom: .35rem;
    }

    #openScanBtn {
        font-size: .9rem;
        padding: .5rem 1.05rem;
        min-width: 240px !important;
    }

    .qty-stepper.input-group-lg > .form-control,
    .qty-stepper.input-group-lg > .btn,
    .qty-stepper.input-group-lg > .input-group-text {
        padding-top: .45rem;
        padding-bottom: .45rem;
        font-size: .9rem;
    }
</style>

<div class="toast-area" id="toastArea"></div>

<div class="wiz-head">
    <div>
        <div class="d-flex align-items-center gap-2">
            <i class="bi <?= $actionMode === 'transfer' ? 'bi-arrow-left-right' : 'bi-bag-check' ?>"></i>
            <h3 class="wiz-title"><?= h($pageTitle) ?></h3>
        </div>
        <div class="wiz-sub"><?= h($pageSub) ?></div>
        <div class="wiz-progress mt-2" aria-label="Fremdrift uttak"><div></div></div>
    </div>

    <div class="d-flex align-items-center gap-2">
        <a class="pill" title="<?= h($cartPillTitle) ?>" href="?page=uttak_checkout" id="cartPill">
            <i class="bi bi-cart"></i><strong id="cartCountEl"><?= (int)$cartCount ?></strong>
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="?page=uttak_shop&back=1"><i class="bi bi-chevron-left me-1"></i>Tilbake</a>
        <a class="btn btn-outline-danger btn-sm" href="?page=uttak_shop&reset=1"><i class="bi bi-trash3 me-1"></i>Tøm</a>
    </div>
</div>

<div class="wiz-info-card">
    <div class="wiz-info-grid">
        <div>
            <div class="wiz-info-k">Modus</div>
            <div class="wiz-info-v"><?= h($actionMode === 'transfer' ? 'Intern flytting' : 'Uttak mot arbeidsordre') ?></div>
        </div>

        <div>
            <div class="wiz-info-k"><?= h($actionMode === 'transfer' ? 'Fra-lager' : 'Uttakslokasjon') ?></div>
            <div class="wiz-info-v"><?= h($locationLabel) ?></div>
        </div>

        <?php if ($actionMode === 'transfer'): ?>
            <div>
                <div class="wiz-info-k">Til-lager / bil / forbrukslager</div>
                <div class="wiz-info-v"><?= h($targetLocationLabel) ?></div>
            </div>
        <?php else: ?>
            <div>
                <div class="wiz-info-k">Arbeidsordre</div>
                <div class="wiz-info-v"><?= h($targetWorkOrderNumber) ?></div>
            </div>
            <div>
                <div class="wiz-info-k">Beskrivelse</div>
                <div class="wiz-info-v"><?= h($targetWorkOrderName !== '' ? $targetWorkOrderName : '—') ?></div>
            </div>
            <?php if ($targetWorkOrderLocalId > 0): ?>
                <div>
                    <div class="wiz-info-k">Lokal WO-ID</div>
                    <div class="wiz-info-v">#<?= (int)$targetWorkOrderLocalId ?></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($syncWarning): ?>
    <div class="alert alert-warning py-2 mb-2" role="alert">
        <?= h($syncWarning) ?>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger py-2 mb-2" role="alert">
        <strong>Feil:</strong> <?= h(implode(' ', $errors)) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <script>
        window.__lager_initial_toast = <?= json_encode($success, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
<?php endif; ?>

<div class="toolbar">
    <div class="d-flex flex-column align-items-center gap-2">
        <div class="w-100 d-flex justify-content-center">
            <input
                type="text"
                class="form-control"
                id="productSearch"
                value="<?= h($q) ?>"
                placeholder="Søk vare… (navn / SKU)"
                autocomplete="off"
            >
        </div>

        <div class="w-100 d-flex justify-content-center">
            <button type="button" class="btn btn-primary px-4" id="openScanBtn" style="min-width: 240px;">
                <i class="bi bi-qr-code-scan me-2"></i>Scan
            </button>
        </div>

        <div class="text-muted small" id="searchCount"></div>
    </div>
</div>

<div class="table-responsive mt-2">
    <table class="table table-sm align-middle" id="productsTable">
        <tbody>
        <?php
        $rendered = 0;
        foreach ($productRows as $pr):
            $pid = (int)($pr['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }

            $name = (string)($pr['name'] ?? '');
            $sku  = (string)($pr['sku'] ?? '');
            $img  = trim((string)($pr['img'] ?? ''));

            $availInt = (int)floor(((float)($availableByProduct[$pid] ?? 0.0)) + 1e-9);
            if ($availInt <= 0) {
                continue;
            }

            $inCart = (int)($wiz['cart'][$pid]['qty'] ?? 0);

            $unit = trim((string)($productsById[$pid]['unit'] ?? ''));
            if ($unit === '') {
                $unit = 'stk';
            }

            $hay = mb_strtolower(trim($name . ' ' . $sku), 'UTF-8');

            $rendered++;
        ?>
            <tr
                class="product-row prod-row"
                data-product-id="<?= (int)$pid ?>"
                data-name="<?= h($name) ?>"
                data-sku="<?= h(mb_strtolower(trim($sku), 'UTF-8')) ?>"
                data-search="<?= h($hay) ?>"
                data-avail="<?= (int)$availInt ?>"
                data-unit="<?= h($unit) ?>"
                data-incart="<?= (int)$inCart ?>"
                data-img="<?= h($img) ?>"
            >
                <td colspan="3">
                    <div class="prod-inner">
                        <div class="prod-left">
                            <div class="prod-thumb" aria-hidden="true">
                                <?php if ($img !== ''): ?>
                                    <img src="<?= h($img) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"
                                         onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=&quot;bi bi-box-seam&quot;></i>';"/>
                                <?php else: ?>
                                    <i class="bi bi-box-seam"></i>
                                <?php endif; ?>
                            </div>

                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="prod-name"><?= h($name) ?></div>
                                <div class="prod-meta text-muted small">
                                    <?php if ($sku !== ''): ?>SKU: <?= h($sku) ?><?php endif; ?>
                                </div>

                                <div class="text-muted small mt-1" data-incart-label="<?= (int)$pid ?>" style="<?= $inCart > 0 ? '' : 'display:none;' ?>">
                                    i kurv: <span data-incart-value="<?= (int)$pid ?>"><?= (int)$inCart ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="prod-right">
                            <div class="prod-avail">
                                <div class="text-muted small">Tilgjengelig</div>
                                <div class="fw-semibold"><?= (int)$availInt ?> <?= h($unit) ?></div>
                            </div>

                            <div class="prod-actions">
                                <form method="post" class="d-flex justify-content-end gap-2 add-form">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= (int)$pid ?>">
                                    <input type="text" name="qty" class="form-control form-control-sm text-end" style="width:84px;" value="1" inputmode="numeric">
                                    <button class="btn btn-sm btn-primary">
                                        <i class="bi bi-plus-lg me-1"></i>Legg
                                    </button>
                                </form>

                                <?php if ($inCart > 0): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="product_id" value="<?= (int)$pid ?>">
                                        <button class="btn btn-sm btn-outline-danger w-100">
                                            <i class="bi bi-x-circle me-1"></i>Fjern fra kurv
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>

        <?php if ($rendered === 0): ?>
            <tr><td class="text-muted">Ingen varer med beholdning på valgt lokasjon.</td></tr>
        <?php endif; ?>

        <tr id="noMatchesRow" style="display:none;">
            <td class="text-muted">Ingen treff.</td>
        </tr>
        </tbody>
    </table>
</div>

<div class="nextbar">
    <form method="post" class="d-grid">
        <input type="hidden" name="action" value="next">
        <button class="btn btn-success btn-lg">
            <?= h($nextBtnText) ?> <i class="bi bi-chevron-right ms-1"></i>
        </button>
    </form>
</div>

<div class="modal fade" id="scanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="modal-title fw-semibold">Scan QR / strekkode</div>
                    <div class="text-muted scan-hint">
                        Scan etikett (p:&lt;id&gt;) eller strekkode/SKU.
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Lukk"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning d-none" id="scanWarn"></div>
                <video id="scanVideo" playsinline></video>

                <div class="d-flex gap-2 mt-3 flex-wrap">
                    <button type="button" class="btn btn-outline-secondary" id="scanStopBtn">Stopp</button>
                    <button type="button" class="btn btn-primary" id="scanStartBtn">Start</button>
                    <div class="text-muted small align-self-center" id="scanStatus"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="scanAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="scanAddForm">
                <div class="modal-header">
                    <div>
                        <div class="modal-title fw-semibold">Legg til i kurv</div>
                        <div class="text-muted small" id="scanAddSub"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Lukk"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="product_id" id="scanAddProductId" value="0">

                    <div class="fw-semibold d-flex align-items-center gap-2">
                        <span id="scanAddImgWrap" class="prod-thumb" style="width:40px;height:40px;border-radius:12px;">
                            <i class="bi bi-box-seam"></i>
                        </span>
                        <span id="scanAddName">Vare</span>
                    </div>
                    <div class="text-muted small" id="scanAddSku"></div>

                    <div class="mt-3">
                        <label class="form-label mb-1">Antall</label>

                        <div class="input-group input-group-lg qty-stepper">
                            <button type="button" class="btn btn-outline-secondary" id="scanQtyMinus" aria-label="Minus">
                                <i class="bi bi-dash-lg"></i>
                            </button>
                            <input
                                type="text"
                                class="form-control text-end"
                                name="qty"
                                id="scanAddQty"
                                value="1"
                                inputmode="numeric"
                                autocomplete="off"
                            >
                            <button type="button" class="btn btn-outline-secondary" id="scanQtyPlus" aria-label="Pluss">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>

                        <div class="text-muted small mt-1" id="scanAddHint"></div>
                    </div>

                    <div class="alert alert-warning d-none mt-3" id="scanAddWarn"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="submit" class="btn btn-primary" id="scanAddSubmit">
                        <i class="bi bi-plus-lg me-1"></i>Legg i kurv
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const locationLabel = <?= $locationLabelJs ?>;

    const toastArea = document.getElementById('toastArea');

    function escapeHtml(s) {
        return (s || '').toString()
            .replaceAll('&','&amp;')
            .replaceAll('<','&lt;')
            .replaceAll('>','&gt;')
            .replaceAll('"','&quot;')
            .replaceAll("'","&#039;");
    }

    function showToast(message, variant='success', ms=2200) {
        if (!toastArea || !message) return;

        const el = document.createElement('div');
        el.className = 'mini-toast ' + (variant || 'success');
        el.innerHTML = escapeHtml(message);

        toastArea.appendChild(el);

        requestAnimationFrame(() => el.classList.add('show'));

        setTimeout(() => {
            el.classList.remove('show');
            setTimeout(() => { try { el.remove(); } catch(e) {} }, 250);
        }, ms);
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (window.__lager_initial_toast) {
            showToast(window.__lager_initial_toast, 'success', 2000);
            try { delete window.__lager_initial_toast; } catch(e) {}
        }
    });

    const input = document.getElementById('productSearch');
    const rows  = Array.from(document.querySelectorAll('#productsTable tbody tr.product-row'));
    const noRow = document.getElementById('noMatchesRow');
    const cntEl = document.getElementById('searchCount');

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

    if (input) {
        input.addEventListener('input', applyFilter);
        applyFilter();
    }

    const addModalEl = document.getElementById('scanAddModal');
    const addForm    = document.getElementById('scanAddForm');
    const addPidEl   = document.getElementById('scanAddProductId');
    const addQtyEl   = document.getElementById('scanAddQty');
    const addNameEl  = document.getElementById('scanAddName');
    const addSkuEl   = document.getElementById('scanAddSku');
    const addSubEl   = document.getElementById('scanAddSub');
    const addHintEl  = document.getElementById('scanAddHint');
    const addWarnEl  = document.getElementById('scanAddWarn');
    const addBtnEl   = document.getElementById('scanAddSubmit');
    const addImgWrap = document.getElementById('scanAddImgWrap');

    const minusBtn   = document.getElementById('scanQtyMinus');
    const plusBtn    = document.getElementById('scanQtyPlus');

    let bsAddModal = null;
    function ensureAddModal() {
        if (!addModalEl) return null;
        if (!bsAddModal && window.bootstrap && bootstrap.Modal) bsAddModal = new bootstrap.Modal(addModalEl);
        return bsAddModal;
    }

    function parseIntSafe(v, def=0) {
        const n = parseInt((v ?? '').toString(), 10);
        return Number.isFinite(n) ? n : def;
    }

    let reopenScanAfterAddModal = false;

    function setAddThumb(imgUrl) {
        if (!addImgWrap) return;
        if (!imgUrl) {
            addImgWrap.innerHTML = '<i class="bi bi-box-seam"></i>';
            return;
        }
        const safe = escapeHtml(imgUrl);
        addImgWrap.innerHTML = `<img src="${safe}" alt="" loading="lazy" referrerpolicy="no-referrer"
            onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=&quot;bi bi-box-seam&quot;></i>';"/>`;
    }

    function openAddModalFromRow(tr) {
        if (!tr || !addPidEl || !addQtyEl) return;

        const pid    = parseIntSafe(tr.getAttribute('data-product-id'), 0);
        const name   = (tr.getAttribute('data-name') || '').trim();
        const sku    = (tr.getAttribute('data-sku') || '').trim();
        const avail  = parseIntSafe(tr.getAttribute('data-avail'), 0);
        const unit   = (tr.getAttribute('data-unit') || 'stk').trim();
        const inCart = parseIntSafe(tr.getAttribute('data-incart'), 0);
        const img    = (tr.getAttribute('data-img') || '').trim();

        const maxTake = Math.max(0, avail - inCart);

        addPidEl.value = String(pid);
        if (addNameEl) addNameEl.textContent = name || ('#' + pid);
        if (addSkuEl)  addSkuEl.textContent = sku ? ('SKU: ' + sku) : '';
        if (addSubEl)  addSubEl.textContent = 'Lager: ' + locationLabel;
        setAddThumb(img);

        if (addHintEl) {
            addHintEl.textContent = `Tilgjengelig: ${avail} ${unit}. I kurv: ${inCart}. Du kan legge til maks ${maxTake}.`;
        }

        if (addWarnEl) {
            addWarnEl.classList.add('d-none');
            addWarnEl.textContent = '';
        }

        addQtyEl.value = (maxTake >= 1) ? '1' : '0';
        addQtyEl.setAttribute('data-max', String(maxTake));

        const canAdd = (pid > 0 && maxTake > 0);
        addQtyEl.disabled = !canAdd;
        if (addBtnEl) addBtnEl.disabled = !canAdd;
        if (minusBtn) minusBtn.disabled = !canAdd;
        if (plusBtn)  plusBtn.disabled = !canAdd;

        if (!canAdd && addWarnEl) {
            addWarnEl.classList.remove('d-none');
            addWarnEl.textContent = 'Du har allerede lagt maks i kurv for denne varen på valgt lager.';
        }

        const m = ensureAddModal();
        if (m) m.show();

        setTimeout(() => {
            try { addQtyEl.focus(); addQtyEl.select(); } catch(e) {}
        }, 150);
    }

    function getMaxTake() {
        if (!addQtyEl) return 0;
        return parseIntSafe(addQtyEl.getAttribute('data-max'), 0);
    }
    function getQty() {
        return parseIntSafe((addQtyEl ? addQtyEl.value : '0'), 0);
    }
    function setQty(n) {
        const max = getMaxTake();
        const v = Math.max(0, Math.min(max, n));
        if (addQtyEl) addQtyEl.value = String(v);
        clampQtyInput();
    }
    function clampQtyInput() {
        if (!addQtyEl || !addWarnEl) return true;
        const max = getMaxTake();
        const n = getQty();

        if (!Number.isFinite(n) || n <= 0) {
            addWarnEl.classList.remove('d-none');
            addWarnEl.textContent = 'Antall må være et heltall større enn 0.';
            return false;
        }
        if (n > max) {
            addWarnEl.classList.remove('d-none');
            addWarnEl.textContent = 'Antall er høyere enn tilgjengelig (minus i kurv).';
            return false;
        }
        addWarnEl.classList.add('d-none');
        addWarnEl.textContent = '';
        return true;
    }

    if (addQtyEl) {
        addQtyEl.addEventListener('input', () => { clampQtyInput(); });
        addQtyEl.addEventListener('blur',  () => { clampQtyInput(); });
        addQtyEl.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowUp')   { e.preventDefault(); setQty(getQty() + 1); }
            if (e.key === 'ArrowDown') { e.preventDefault(); setQty(getQty() - 1); }
        });
    }
    if (minusBtn) minusBtn.addEventListener('click', () => setQty(getQty() - 1));
    if (plusBtn)  plusBtn.addEventListener('click',  () => setQty(getQty() + 1));

    const openBtn     = document.getElementById('openScanBtn');
    const scanModalEl = document.getElementById('scanModal');
    const warnEl      = document.getElementById('scanWarn');
    const videoEl     = document.getElementById('scanVideo');
    const startBtn    = document.getElementById('scanStartBtn');
    const stopBtn     = document.getElementById('scanStopBtn');
    const statusEl    = document.getElementById('scanStatus');

    let bsScanModal = null;
    let scanning = false;

    let codeReader = null;
    let zxingControls = null;

    let lastValue = '';
    let lastAt = 0;

    function setScanWarn(msg) {
        if (!warnEl) return;
        if (!msg) {
            warnEl.classList.add('d-none');
            warnEl.textContent = '';
            return;
        }
        warnEl.classList.remove('d-none');
        warnEl.textContent = msg;
    }

    function setScanStatus(msg) {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
    }

    function isSecureOk() {
        if (window.isSecureContext) return true;
        const host = (location.hostname || '').toLowerCase();
        return (host === 'localhost' || host === '127.0.0.1');
    }

    function ensureScanModal() {
        if (!scanModalEl) return null;
        if (!bsScanModal && window.bootstrap && bootstrap.Modal) bsScanModal = new bootstrap.Modal(scanModalEl);
        return bsScanModal;
    }

    function stopScan() {
        scanning = false;
        setScanStatus('');

        try {
            if (zxingControls && typeof zxingControls.stop === 'function') zxingControls.stop();
        } catch(e) {}
        zxingControls = null;

        try {
            if (codeReader && typeof codeReader.reset === 'function') codeReader.reset();
        } catch(e) {}
        codeReader = null;

        try {
            if (videoEl) { videoEl.pause(); videoEl.srcObject = null; }
        } catch(e) {}
    }

    async function startScan() {
        setScanWarn('');
        setScanStatus('');

        if (!isSecureOk()) {
            setScanWarn('Kamera krever HTTPS (eller localhost). Åpne lager-appen over https for scanning.');
            return;
        }
        if (!window.ZXingBrowser) {
            setScanWarn('Scanner-biblioteket (ZXing) er ikke lastet. Sjekk nettverk/CSP.');
            return;
        }

        try {
            codeReader = new ZXingBrowser.BrowserMultiFormatReader();

            const constraints = {
                video: { facingMode: { ideal: 'environment' } },
                audio: false
            };

            scanning = true;
            setScanStatus('Scanner…');

            zxingControls = await codeReader.decodeFromConstraints(
                constraints,
                videoEl,
                (result) => {
                    if (!scanning) return;
                    if (!result) return;

                    const txt = (typeof result.getText === 'function') ? result.getText() : (result.text || String(result));
                    handleScanResult(txt);
                }
            );

        } catch (e) {
            scanning = false;
            setScanWarn('Fikk ikke startet kamera/scanner. Sjekk tillatelser på mobilen.');
        }
    }

    function handleNotFound(scannedValue) {
        const label = locationLabel || '';
        setScanWarn(`Varen finnes ikke på valgt lager ${label}. (Scannet: ${scannedValue})`);

        stopScan();
        setTimeout(() => {
            const m = ensureScanModal();
            if (m) startScan();
        }, 900);
    }

    function handleFound(tr) {
        stopScan();
        reopenScanAfterAddModal = true;

        try { if (bsScanModal) bsScanModal.hide(); } catch(e) {}
        setTimeout(() => openAddModalFromRow(tr), 120);
    }

    function handleScanResult(raw) {
        const v = (raw || '').trim();
        if (!v) return;

        const now = Date.now();
        if (v === lastValue && (now - lastAt) < 1200) return;
        lastValue = v;
        lastAt = now;

        const m = v.match(/^p:(\d+)$/i);
        if (m) {
            const pid = parseIntSafe(m[1], 0);
            if (pid > 0) {
                const tr = document.querySelector('tr.product-row[data-product-id="'+pid+'"]');
                if (!tr) { handleNotFound(v); return; }
                handleFound(tr);
                return;
            }
        }

        const norm = v.toLowerCase();
        const hits = rows.filter(tr => (tr.getAttribute('data-sku') || '') === norm);

        if (hits.length === 1) {
            handleFound(hits[0]);
            return;
        }

        if (hits.length === 0) {
            handleNotFound(v);
            return;
        }

        if (input) {
            input.value = v;
            applyFilter();
        }
        setScanWarn(`Flere treff på scannet kode. Viser treff i listen for ${locationLabel}.`);
        stopScan();
        setTimeout(() => startScan(), 900);
    }

    function updateRowCartState(pid, inCartQty) {
        const tr = document.querySelector('tr.product-row[data-product-id="'+pid+'"]');
        if (!tr) return;

        tr.setAttribute('data-incart', String(inCartQty));

        const lbl = document.querySelector('[data-incart-label="'+pid+'"]');
        const val = document.querySelector('[data-incart-value="'+pid+'"]');
        if (val) val.textContent = String(inCartQty);
        if (lbl) lbl.style.display = (inCartQty > 0) ? '' : 'none';
    }

    function updateCartCount(cartCount) {
        const el = document.getElementById('cartCountEl');
        if (el) el.textContent = String(cartCount);
    }

    async function ajaxAddToCart(formEl) {
        const fd = new FormData(formEl);
        fd.set('ajax', '1');

        const res = await fetch(window.location.href, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });

        let data = null;
        try { data = await res.json(); } catch(e) {}

        if (!data || !data.ok) {
            const errs = (data && data.errors) ? data.errors : ['Kunne ikke legge i kurv.'];
            throw new Error(errs.join(' '));
        }

        updateCartCount(data.cartCount ?? 0);
        updateRowCartState(data.productId, data.inCartQty ?? 0);

        showToast('Lagt i kurv', 'success', 2000);
        return data;
    }

    if (addForm) {
        addForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            e.stopPropagation();

            if (!clampQtyInput()) return;

            if (addBtnEl) addBtnEl.disabled = true;
            if (addWarnEl) { addWarnEl.classList.add('d-none'); addWarnEl.textContent = ''; }

            try {
                await ajaxAddToCart(addForm);
                try { if (bsAddModal) bsAddModal.hide(); } catch(e) {}
            } catch (err) {
                if (addWarnEl) {
                    addWarnEl.classList.remove('d-none');
                    addWarnEl.textContent = (err && err.message) ? err.message : 'Kunne ikke legge i kurv.';
                }
            } finally {
                if (addBtnEl) addBtnEl.disabled = false;
            }
        });
    }

    if (openBtn && scanModalEl) {
        openBtn.addEventListener('click', function () {
            setScanWarn('');
            setScanStatus('');
            const m = ensureScanModal();
            if (m) m.show();
        });

        scanModalEl.addEventListener('shown.bs.modal', function () {
            startScan();
        });

        scanModalEl.addEventListener('hidden.bs.modal', function () {
            stopScan();
        });
    }

    if (addModalEl) {
        addModalEl.addEventListener('hidden.bs.modal', function () {
            if (!reopenScanAfterAddModal) return;
            reopenScanAfterAddModal = false;

            setTimeout(() => {
                setScanWarn('');
                const m = ensureScanModal();
                if (m) m.show();
            }, 120);
        });
    }

    if (startBtn) startBtn.addEventListener('click', startScan);
    if (stopBtn)  stopBtn.addEventListener('click',  stopScan);
})();
</script>