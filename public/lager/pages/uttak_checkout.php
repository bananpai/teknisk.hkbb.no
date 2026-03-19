<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\lager\pages\uttak_checkout.php
// Uttak wizard steg 3/3 - kontroll og checkout
//
// Endret 2026-03-11:
// - Støtter både:
//   1) Uttak mot arbeidsordre
//   2) Intern flytting mellom lager / bil / forbrukslager
// - action_mode=workorder:
//   - Oppretter OUT fra kildelager
//   - work_order_id settes bare hvis det finnes en ekte rad i work_orders
// - action_mode=transfer:
//   - Oppretter OUT fra kildelager
//   - Oppretter ny batch på mållager (best effort, basert på tilgjengelige kolonner)
//   - Oppretter IN til mållager
// - Viser riktig kontekst i UI
//
// Viktig:
// - For batch-styrt lager forsøker denne filen å opprette ny batch på mållager ved flytting.
// - Hvis databasen har påkrevde batch-kolonner uten default-verdi som ikke kan oppdages her,
//   vil checkout stoppe med feil slik at ingenting blir halvveis lagret.

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
            return ((int)$stmt->fetchColumn() > 0);
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
            return ((int)$stmt->fetchColumn() > 0);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('uuid_v4')) {
    function uuid_v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

function getLocationLabel(PDO $pdo, int $locationId): string
{
    if ($locationId <= 0) {
        return '—';
    }

    $locationLabel = '#' . $locationId;
    try {
        $stmt = $pdo->prepare("SELECT code, name FROM inv_locations WHERE id=:id LIMIT 1");
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

function fifo_fetch_batches(PDO $pdo, int $productId, int $physId, bool $hasBatchProjCol, int $sourceProjectId): array
{
    $params = [':pid' => $productId, ':phys' => $physId];

    $projSql = '';
    if ($sourceProjectId > 0 && $hasBatchProjCol) {
        $projSql = " AND b.project_id = :spid ";
        $params[':spid'] = $sourceProjectId;
    }

    $sql = "
        SELECT b.*
        FROM inv_batches b
        WHERE b.product_id = :pid
          AND b.physical_location_id = :phys
          AND b.qty_remaining > 0
          $projSql
        ORDER BY b.received_at ASC, b.id ASC
        FOR UPDATE
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function insertInvMovement(
    PDO $pdo,
    array $schema,
    array $data
): void {
    $cols = ['occurred_at', 'type', 'product_id', 'physical_location_id', 'qty', 'note', 'created_by'];
    $params = [
        ':occurred_at'          => $data['occurred_at'],
        ':type'                 => $data['type'],
        ':product_id'           => $data['product_id'],
        ':physical_location_id' => $data['physical_location_id'],
        ':qty'                  => $data['qty'],
        ':note'                 => $data['note'],
        ':created_by'           => $data['created_by'],
    ];

    if (!empty($schema['hasMoveBatchIdCol'])) {
        $cols[] = 'batch_id';
        $params[':batch_id'] = $data['batch_id'] ?? null;
    }

    if (!empty($schema['hasMoveUnitPrice'])) {
        $cols[] = 'unit_price';
        $params[':unit_price'] = $data['unit_price'] ?? 0;
    }

    if (!empty($schema['hasProjectIdCol'])) {
        $cols[] = 'project_id';
        $params[':project_id'] = $data['project_id'] ?? null;
    }

    if (!empty($schema['hasWorkOrderIdCol'])) {
        $cols[] = 'work_order_id';
        $params[':work_order_id'] = $data['work_order_id'] ?? null;
    }

    if (!empty($schema['hasMoveSourceTable'])) {
        $cols[] = 'source_table';
        $params[':source_table'] = $data['source_table'] ?? null;
    }

    if (!empty($schema['hasMoveSourceId'])) {
        $cols[] = 'source_id';
        $params[':source_id'] = $data['source_id'] ?? null;
    }

    $sql = "
        INSERT INTO inv_movements (" . implode(', ', $cols) . ")
        VALUES (" . implode(', ', array_keys($params)) . ")
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function createTransferBatchFromSource(
    PDO $pdo,
    int $productId,
    int $targetLocationId,
    int $qty,
    float $unitPrice,
    string $now,
    string $createdBy,
    ?int $targetProjectId,
    string $note,
    ?int $sourceBatchId = null
): int {
    $columns = [];
    $values = [];
    $params = [];

    $add = static function (string $col, string $param, $value) use (&$columns, &$values, &$params): void {
        $columns[] = $col;
        $values[] = $param;
        $params[$param] = $value;
    };

    $add('product_id', ':product_id', $productId);
    $add('physical_location_id', ':physical_location_id', $targetLocationId);

    if (columnExists($pdo, 'inv_batches', 'qty_received')) {
        $add('qty_received', ':qty_received', $qty);
    } elseif (columnExists($pdo, 'inv_batches', 'qty')) {
        $add('qty', ':qty_total', $qty);
    }

    if (columnExists($pdo, 'inv_batches', 'qty_remaining')) {
        $add('qty_remaining', ':qty_remaining', $qty);
    }

    if (columnExists($pdo, 'inv_batches', 'unit_price')) {
        $add('unit_price', ':unit_price', $unitPrice);
    }

    if (columnExists($pdo, 'inv_batches', 'received_at')) {
        $add('received_at', ':received_at', $now);
    }

    if (columnExists($pdo, 'inv_batches', 'created_at')) {
        $add('created_at', ':created_at', $now);
    }

    if (columnExists($pdo, 'inv_batches', 'created_by')) {
        $add('created_by', ':created_by', $createdBy !== '' ? $createdBy : null);
    }

    if (columnExists($pdo, 'inv_batches', 'note')) {
        $add('note', ':note', $note);
    }

    if (columnExists($pdo, 'inv_batches', 'project_id')) {
        $add('project_id', ':project_id', ($targetProjectId && $targetProjectId > 0) ? $targetProjectId : null);
    }

    if ($sourceBatchId !== null && $sourceBatchId > 0) {
        foreach (['source_batch_id', 'parent_batch_id', 'origin_batch_id'] as $candidate) {
            if (columnExists($pdo, 'inv_batches', $candidate)) {
                $add($candidate, ':src_batch_id', $sourceBatchId);
                break;
            }
        }
    }

    $sql = "
        INSERT INTO inv_batches (" . implode(', ', $columns) . ")
        VALUES (" . implode(', ', $values) . ")
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $newId = (int)$pdo->lastInsertId();
    if ($newId <= 0) {
        throw new RuntimeException('Kunne ikke opprette batch på mållager.');
    }

    return $newId;
}

$errors = [];
$successMessage = null;
$successType    = '';

if (!tableExists($pdo, 'inv_movements')) {
    $errors[] = 'Mangler tabell inv_movements.';
}
if (!tableExists($pdo, 'inv_locations')) {
    $errors[] = 'Mangler tabell inv_locations.';
}
if (!tableExists($pdo, 'inv_products')) {
    $errors[] = 'Mangler tabell inv_products.';
}

$hasProjectsTable = tableExists($pdo, 'projects');
$hasBatchesTable  = !$errors && tableExists($pdo, 'inv_batches');

$hasWorkOrderIdCol = !$errors && columnExists($pdo, 'inv_movements', 'work_order_id');
$hasProjectIdCol   = !$errors && columnExists($pdo, 'inv_movements', 'project_id');

$hasMoveBatchIdCol = !$errors && columnExists($pdo, 'inv_movements', 'batch_id');
$hasMoveUnitPrice  = !$errors && columnExists($pdo, 'inv_movements', 'unit_price');

$hasBatchProductCol = $hasBatchesTable && columnExists($pdo, 'inv_batches', 'product_id');
$hasBatchPhysCol    = $hasBatchesTable && columnExists($pdo, 'inv_batches', 'physical_location_id');
$hasBatchRemainCol  = $hasBatchesTable && columnExists($pdo, 'inv_batches', 'qty_remaining');
$hasBatchPriceCol   = $hasBatchesTable && columnExists($pdo, 'inv_batches', 'unit_price');
$hasBatchRecvCol    = $hasBatchesTable && columnExists($pdo, 'inv_batches', 'received_at');
$hasBatchProjCol    = $hasBatchesTable && columnExists($pdo, 'inv_batches', 'project_id');

$batchOutSupported =
    $hasBatchesTable &&
    $hasMoveBatchIdCol &&
    $hasMoveUnitPrice &&
    $hasBatchProductCol &&
    $hasBatchPhysCol &&
    $hasBatchRemainCol &&
    $hasBatchPriceCol &&
    $hasBatchRecvCol;

$hasMoveSourceTable = !$errors && columnExists($pdo, 'inv_movements', 'source_table');
$hasMoveSourceId    = !$errors && columnExists($pdo, 'inv_movements', 'source_id');

$schemaFlags = [
    'hasMoveBatchIdCol' => $hasMoveBatchIdCol,
    'hasMoveUnitPrice'  => $hasMoveUnitPrice,
    'hasProjectIdCol'   => $hasProjectIdCol,
    'hasWorkOrderIdCol' => $hasWorkOrderIdCol,
    'hasMoveSourceTable'=> $hasMoveSourceTable,
    'hasMoveSourceId'   => $hasMoveSourceId,
];

if (!isset($_SESSION['lager_uttak_wizard']) || !is_array($_SESSION['lager_uttak_wizard'])) {
    header('Location: /lager/?page=uttak');
    exit;
}
$wiz =& $_SESSION['lager_uttak_wizard'];

$actionMode           = (string)($wiz['action_mode'] ?? 'workorder');
$actionMode           = in_array($actionMode, ['workorder', 'transfer'], true) ? $actionMode : 'workorder';
$sourceLocationId     = (int)($wiz['source_location_id'] ?? 0);
$targetLocationId     = (int)($wiz['target_location_id'] ?? 0);
$targetProjectId      = (int)($wiz['target_project_id'] ?? 0);
$targetWorkOrderId    = (int)($wiz['target_work_order_id'] ?? 0);
$woText               = trim((string)($wiz['target_work_order_text'] ?? ''));
$woName               = trim((string)($wiz['target_work_order_name'] ?? ''));
$sourceProjectId      = (int)($wiz['source_project_id'] ?? 0);

if ($sourceLocationId <= 0) {
    header('Location: /lager/?page=uttak');
    exit;
}
if ($actionMode === 'workorder' && ($targetWorkOrderId <= 0 && $woText === '')) {
    header('Location: /lager/?page=uttak');
    exit;
}
if ($actionMode === 'transfer' && $targetLocationId <= 0) {
    header('Location: /lager/?page=uttak');
    exit;
}

if (!isset($wiz['cart']) || !is_array($wiz['cart']) || empty($wiz['cart'])) {
    header('Location: /lager/?page=uttak_shop');
    exit;
}

if (isset($_GET['back']) && (string)$_GET['back'] === '1') {
    header('Location: /lager/?page=uttak_shop');
    exit;
}

$locationLabel       = getLocationLabel($pdo, $sourceLocationId);
$targetLocationLabel = $actionMode === 'transfer' ? getLocationLabel($pdo, $targetLocationId) : '';

$projectLabel = '';
if ($targetProjectId > 0 && $hasProjectsTable) {
    $projectLabel = '#' . $targetProjectId;
    try {
        $stmt = $pdo->prepare("SELECT name, project_no FROM projects WHERE id=:id LIMIT 1");
        $stmt->execute([':id' => $targetProjectId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($r) {
            $nm = trim((string)($r['name'] ?? ''));
            $no = trim((string)($r['project_no'] ?? ''));
            $projectLabel = $nm !== '' ? $nm : $projectLabel;
            if ($no !== '') {
                $projectLabel .= " ({$no})";
            }
        }
    } catch (\Throwable $e) {
    }
}

$woLabel = trim((string)($_SESSION['lager_uttak_wizard_wo_label'] ?? ''));
if ($woLabel === '' && $woText !== '') {
    $woLabel = $woText;
    if ($woName !== '') {
        $woLabel .= ' – ' . $woName;
    }
}

$resolvedRealWorkOrderId = 0;
if ($targetWorkOrderId > 0 && tableExists($pdo, 'work_orders')) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM work_orders WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $targetWorkOrderId]);
        $resolvedRealWorkOrderId = (int)($stmt->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        $resolvedRealWorkOrderId = 0;
    }
}

$unitCol = null;
if (!$errors) {
    foreach (['unit', 'unit_name', 'uom', 'uom_name', 'enhet', 'unit_code', 'uom_code'] as $c) {
        if (columnExists($pdo, 'inv_products', $c)) {
            $unitCol = $c;
            break;
        }
    }
}

$unitsByProduct = [];
try {
    $ids = array_map('intval', array_keys($wiz['cart']));
    $ids = array_values(array_filter($ids, static fn($x) => $x > 0));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($unitCol) {
            $sql = "SELECT id, COALESCE(`{$unitCol}`,'') AS unit FROM inv_products WHERE id IN ($placeholders)";
        } else {
            $sql = "SELECT id, '' AS unit FROM inv_products WHERE id IN ($placeholders)";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);

        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $pid = (int)($r['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $unitsByProduct[$pid] = trim((string)($r['unit'] ?? ''));
        }
    }
} catch (\Throwable $e) {
}

$availableByProduct = [];
try {
    if ($batchOutSupported) {
        $whereProj = '';
        $params = [':lid' => $sourceLocationId];

        if ($sourceProjectId > 0 && $hasBatchProjCol) {
            $whereProj = " AND b.project_id = :spid ";
            $params[':spid'] = $sourceProjectId;
        }

        $stmt = $pdo->prepare("
            SELECT b.product_id, COALESCE(SUM(b.qty_remaining),0) AS avail_qty
            FROM inv_batches b
            WHERE b.physical_location_id = :lid
              AND b.qty_remaining > 0
              $whereProj
            GROUP BY b.product_id
        ");
        $stmt->execute($params);

        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $pid = (int)($r['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $availableByProduct[$pid] = (float)($r['avail_qty'] ?? 0);
        }
    } else {
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
    }
} catch (\Throwable $e) {
}

$errors = array_values($errors);

$action = (string)($_POST['action'] ?? '');
if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'update') {
        foreach ((array)($_POST['cart_qty'] ?? []) as $pidStr => $qtyStr) {
            $pid = (int)$pidStr;
            if ($pid <= 0) {
                continue;
            }

            $raw = trim((string)$qtyStr);
            $raw = str_replace(' ', '', $raw);
            $raw = str_replace(',', '.', $raw);
            $f = (float)$raw;
            if (abs($f - round($f)) > 1e-9) {
                $errors[] = "Antall for #{$pid} må være heltall.";
                continue;
            }
            $qty = (int)round($f);

            if ($qty <= 0) {
                unset($wiz['cart'][$pid]);
                continue;
            }

            $availInt = (int)floor(((float)($availableByProduct[$pid] ?? 0.0)) + 1e-9);
            if ($qty > $availInt) {
                $errors[] = "For mye på #{$pid}. Tilgjengelig: {$availInt}.";
                continue;
            }

            if (isset($wiz['cart'][$pid])) {
                $wiz['cart'][$pid]['qty'] = $qty;
            }
        }

        $wiz['note'] = trim((string)($_POST['note'] ?? ''));

        if (!$errors) {
            if (empty($wiz['cart'])) {
                $errors[] = 'Kurven er tom.';
            } else {
                $successMessage = 'Handlekurv oppdatert.';
                $successType = 'update';
            }
        }
    }

    if ($action === 'checkout') {
        $note = trim((string)($_POST['note'] ?? ''));
        $wiz['note'] = $note;

        if (!$batchOutSupported) {
            $errors[] =
                'Checkout kan ikke fullføres fordi batch-ut ikke er støttet i databasen din. ' .
                'Krever inv_batches(received_at, qty_remaining, unit_price) + inv_movements(batch_id, unit_price).';
        }

        foreach ($wiz['cart'] as $pid => $it) {
            $pid = (int)$pid;
            $need = (int)($it['qty'] ?? 0);
            $availInt = (int)floor(((float)($availableByProduct[$pid] ?? 0.0)) + 1e-9);
            if ($need <= 0) {
                $errors[] = "Ugyldig antall for #{$pid}.";
                continue;
            }
            if ($need > $availInt) {
                $errors[] = "Ikke nok beholdning for #{$pid}. Tilgjengelig: {$availInt}.";
            }
        }

        if ($actionMode === 'transfer' && $targetLocationId <= 0) {
            $errors[] = 'Mållokasjon mangler for flytting.';
        }

        if (!$errors) {
            $now = (new DateTime())->format('Y-m-d H:i:s');

            $createdBy   = (string)($u['username'] ?? $u['user'] ?? $u['email'] ?? '');
            $lagerUserId = (int)($u['id'] ?? $u['lager_user_id'] ?? 0);
            $transferRef = uuid_v4();

            if ($actionMode === 'transfer') {
                $noteBase = "Flytting (app) {$locationLabel} → {$targetLocationLabel}";
                if ($note !== '') {
                    $noteBase .= " | {$note}";
                }
                $noteBase .= " | Ref {$transferRef}";
            } else {
                $noteBase = "Uttak (app) {$locationLabel}";
                if ($projectLabel !== '') {
                    $noteBase .= " → {$projectLabel}";
                }
                if ($woLabel !== '') {
                    $noteBase .= " / WO {$woLabel}";
                }
                if ($note !== '') {
                    $noteBase .= " | {$note}";
                }
            }

            if (mb_strlen($noteBase) > 250) {
                $noteBase = mb_substr($noteBase, 0, 250);
            }

            $hasWithdrawals     = tableExists($pdo, 'lager_withdrawals');
            $hasWithdrawalLines = tableExists($pdo, 'lager_withdrawal_lines');

            try {
                $pdo->beginTransaction();

                $createdMovements = 0;
                $withdrawalId = null;

                if (
                    $actionMode === 'workorder' &&
                    $hasWithdrawals &&
                    $hasWithdrawalLines &&
                    $lagerUserId > 0 &&
                    $sourceProjectId > 0 &&
                    $targetProjectId > 0
                ) {
                    $clientReq = uuid_v4();

                    $stmtW = $pdo->prepare("
                        INSERT INTO lager_withdrawals
                        (lager_user_id, from_project_id, from_location_id, to_project_id, work_order_id, note, created_at, source, client_request_id, status, posted_at, posted_by)
                        VALUES
                        (:uid, :from_pid, :from_lid, :to_pid, :woid, :note, :created_at, 'web', :client_req, 'posted', :posted_at, :posted_by)
                    ");
                    $stmtW->execute([
                        ':uid'        => $lagerUserId,
                        ':from_pid'   => $sourceProjectId,
                        ':from_lid'   => $sourceLocationId,
                        ':to_pid'     => $targetProjectId,
                        ':woid'       => ($resolvedRealWorkOrderId > 0 ? $resolvedRealWorkOrderId : null),
                        ':note'       => $noteBase,
                        ':created_at' => $now,
                        ':client_req' => $clientReq,
                        ':posted_at'  => $now,
                        ':posted_by'  => $lagerUserId,
                    ]);
                    $withdrawalId = (int)$pdo->lastInsertId();

                    $stmtWL = $pdo->prepare("
                        INSERT INTO lager_withdrawal_lines (withdrawal_id, product_id, qty)
                        VALUES (:wid, :pid, :qty)
                    ");
                    foreach ($wiz['cart'] as $pid => $it) {
                        $pid = (int)$pid;
                        $qtyNeed = (int)($it['qty'] ?? 0);
                        if ($pid <= 0 || $qtyNeed <= 0) {
                            continue;
                        }

                        $stmtWL->execute([
                            ':wid' => $withdrawalId,
                            ':pid' => $pid,
                            ':qty' => $qtyNeed,
                        ]);
                    }
                }

                $sqlUpdateBatch = "
                    UPDATE inv_batches
                    SET qty_remaining = qty_remaining - :take1
                    WHERE id = :bid
                      AND qty_remaining >= :take2
                    LIMIT 1
                ";
                $stmtUpdateBatch = $pdo->prepare($sqlUpdateBatch);

                foreach ($wiz['cart'] as $pid => $it) {
                    $pid = (int)$pid;
                    $qtyNeed = (int)($it['qty'] ?? 0);
                    if ($pid <= 0 || $qtyNeed <= 0) {
                        continue;
                    }

                    $batches = fifo_fetch_batches($pdo, $pid, $sourceLocationId, $hasBatchProjCol, $sourceProjectId);
                    $remainingToTake = $qtyNeed;

                    foreach ($batches as $b) {
                        if ($remainingToTake <= 0) {
                            break;
                        }

                        $bid    = (int)($b['id'] ?? 0);
                        $rem    = (float)($b['qty_remaining'] ?? 0);
                        $uprice = (float)($b['unit_price'] ?? 0);

                        if ($bid <= 0 || $rem <= 0) {
                            continue;
                        }

                        $remInt = (int)floor($rem + 1e-9);
                        if ($remInt <= 0) {
                            continue;
                        }

                        $take = min($remainingToTake, $remInt);
                        if ($take <= 0) {
                            continue;
                        }

                        $stmtUpdateBatch->execute([
                            ':take1' => $take,
                            ':take2' => $take,
                            ':bid'   => $bid,
                        ]);
                        if ($stmtUpdateBatch->rowCount() !== 1) {
                            throw new RuntimeException("Batch {$bid} kunne ikke trekkes ned (mulig samtidig uttak/flytting). Prøv igjen.");
                        }

                        if ($actionMode === 'transfer') {
                            $targetBatchId = createTransferBatchFromSource(
                                $pdo,
                                $pid,
                                $targetLocationId,
                                $take,
                                $uprice,
                                $now,
                                $createdBy,
                                null,
                                $noteBase,
                                $bid
                            );

                            insertInvMovement($pdo, $schemaFlags, [
                                'occurred_at'          => $now,
                                'type'                 => 'OUT',
                                'product_id'           => $pid,
                                'batch_id'             => $bid,
                                'physical_location_id' => $sourceLocationId,
                                'qty'                  => $take,
                                'unit_price'           => $uprice,
                                'project_id'           => null,
                                'work_order_id'        => null,
                                'source_table'         => null,
                                'source_id'            => null,
                                'note'                 => $noteBase,
                                'created_by'           => $createdBy,
                            ]);
                            $createdMovements++;

                            insertInvMovement($pdo, $schemaFlags, [
                                'occurred_at'          => $now,
                                'type'                 => 'IN',
                                'product_id'           => $pid,
                                'batch_id'             => $targetBatchId,
                                'physical_location_id' => $targetLocationId,
                                'qty'                  => $take,
                                'unit_price'           => $uprice,
                                'project_id'           => null,
                                'work_order_id'        => null,
                                'source_table'         => null,
                                'source_id'            => null,
                                'note'                 => $noteBase,
                                'created_by'           => $createdBy,
                            ]);
                            $createdMovements++;
                        } else {
                            insertInvMovement($pdo, $schemaFlags, [
                                'occurred_at'          => $now,
                                'type'                 => 'OUT',
                                'product_id'           => $pid,
                                'batch_id'             => $bid,
                                'physical_location_id' => $sourceLocationId,
                                'qty'                  => $take,
                                'unit_price'           => $uprice,
                                'project_id'           => ($targetProjectId > 0 ? $targetProjectId : null),
                                'work_order_id'        => ($resolvedRealWorkOrderId > 0 ? $resolvedRealWorkOrderId : null),
                                'source_table'         => ($withdrawalId ? 'lager_withdrawals' : null),
                                'source_id'            => ($withdrawalId ?: null),
                                'note'                 => $noteBase,
                                'created_by'           => $createdBy,
                            ]);
                            $createdMovements++;
                        }

                        $remainingToTake -= $take;
                    }

                    if ($remainingToTake > 0) {
                        throw new RuntimeException("Ikke nok batch-beholdning for produkt #{$pid}. Mangler {$remainingToTake} stk.");
                    }
                }

                $pdo->commit();

                $wiz['cart'] = [];
                $wiz['note'] = '';

                if ($actionMode === 'transfer') {
                    $successMessage = "Flytting registrert. Opprettet {$createdMovements} linje(r).";
                } else {
                    $successMessage = "Uttak registrert. Opprettet {$createdMovements} linje(r).";
                    if ($withdrawalId) {
                        $successMessage .= " (Logget som uttak #{$withdrawalId}.)";
                    }
                }
                $successType = 'checkout';
            } catch (\Throwable $e) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable $e2) {
                }
                $errors[] = $e->getMessage();
            }
        }
    }
}

$noteUi = (string)($wiz['note'] ?? '');
$cartCount = count($wiz['cart']);

$pageTitle = $actionMode === 'transfer' ? 'Kontroll av flytting' : 'Kontroll av uttak';
$newActionText = $actionMode === 'transfer' ? 'Ny flytting' : 'Nytt uttak';
$checkoutBtnText = $actionMode === 'transfer'
    ? 'Checkout – Registrer flytting'
    : 'Checkout – Registrer ut utstyr';

?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
    .qty-wrap { display:flex; align-items:center; justify-content:flex-end; gap:.35rem; }
    .qty-unit { min-width: 2.2rem; text-align:left; color:#6c757d; font-size:.9rem; }
    .summary-card {
        border: 1px solid rgba(13,110,253,.10);
        background: rgba(13,110,253,.05);
        border-radius: 14px;
        padding: .85rem 1rem;
    }
</style>

<div class="container py-3">

    <div class="d-flex align-items-start justify-content-between mt-2">
        <div>
            <h3 class="mb-1"><?= h($pageTitle) ?></h3>
            <div class="text-muted">Steg 3/3</div>
        </div>
        <div class="text-end">
            <div class="text-muted">Linjer</div>
            <div style="font-size:1.25rem;"><strong><?= (int)$cartCount ?></strong></div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3">
        <a class="btn btn-outline-secondary" href="?page=uttak_checkout&back=1">Tilbake</a>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger mt-3">
            <strong>Feil:</strong><br><?= nl2br(h(implode("\n", $errors))) ?>
        </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="alert alert-success mt-3">
            <?= h($successMessage) ?>

            <?php if ($successType === 'checkout'): ?>
                <div class="mt-2 d-grid">
                    <a class="btn btn-primary btn-lg" href="/lager/?page=uttak"><?= h($newActionText) ?></a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="summary-card mt-3">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="text-muted small">Modus</div>
                <div><strong><?= h($actionMode === 'transfer' ? 'Intern flytting' : 'Uttak mot arbeidsordre') ?></strong></div>
            </div>

            <div class="col-md-3">
                <div class="text-muted small"><?= h($actionMode === 'transfer' ? 'Fra-lager' : 'Lokasjon') ?></div>
                <div><strong><?= h($locationLabel) ?></strong></div>
            </div>

            <?php if ($actionMode === 'transfer'): ?>
                <div class="col-md-3">
                    <div class="text-muted small">Til-lager</div>
                    <div><strong><?= h($targetLocationLabel !== '' ? $targetLocationLabel : '—') ?></strong></div>
                </div>
            <?php else: ?>
                <?php if ($projectLabel !== ''): ?>
                    <div class="col-md-3">
                        <div class="text-muted small">Prosjekt</div>
                        <div><strong><?= h($projectLabel) ?></strong></div>
                    </div>
                <?php endif; ?>

                <div class="col-md-3">
                    <div class="text-muted small">Arbeidsordre</div>
                    <div><strong><?= h($woLabel !== '' ? $woLabel : '—') ?></strong></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-body">

            <form method="post" id="updateForm">
                <input type="hidden" name="action" value="update">

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                        <tr>
                            <th>Vare</th>
                            <th class="text-end" style="width:190px;">Antall</th>
                            <th class="text-end" style="width:170px;">Tilg.</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($wiz['cart'] as $pid => $it): ?>
                            <?php
                                $pid = (int)$pid;
                                $nm  = (string)($it['name'] ?? ('#' . $pid));
                                $sku = (string)($it['sku'] ?? '');
                                $qty = (int)($it['qty'] ?? 0);
                                $availInt = (int)floor(((float)($availableByProduct[$pid] ?? 0.0)) + 1e-9);

                                $unit = trim((string)($unitsByProduct[$pid] ?? ''));
                                if ($unit === '') {
                                    $unit = 'stk';
                                }
                            ?>
                            <tr>
                                <td>
                                    <div><strong><?= h($nm) ?></strong></div>
                                    <?php if ($sku !== ''): ?><div class="text-muted small"><?= h($sku) ?></div><?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="qty-wrap">
                                        <input class="form-control form-control-sm text-end cart-qty"
                                               type="text"
                                               name="cart_qty[<?= (int)$pid ?>]"
                                               value="<?= h((string)$qty) ?>"
                                               style="width:90px;">
                                        <span class="qty-unit"><?= h($unit) ?></span>
                                    </div>
                                </td>
                                <td class="text-end text-muted">
                                    <?= (int)$availInt ?> <?= h($unit) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mb-3">
                    <label class="form-label">Notat</label>
                    <input
                        type="text"
                        class="form-control form-control-lg"
                        name="note"
                        id="noteInput"
                        value="<?= h($noteUi) ?>"
                        placeholder="<?= h($actionMode === 'transfer' ? 'Eks. Flytting til servicebil.' : 'Eks. Uttak til node Brakahaug.') ?>"
                    >
                </div>

                <div class="d-grid gap-2">
                    <button class="btn btn-outline-primary btn-lg" type="submit" id="updateBtn">Oppdater handlekurv</button>
                </div>
            </form>

            <form method="post" class="mt-3" id="checkoutForm">
                <input type="hidden" name="action" value="checkout">
                <input type="hidden" name="note" id="noteHidden" value="<?= h($noteUi) ?>">

                <div class="d-grid">
                    <button class="btn btn-success btn-lg" id="checkoutBtn"><?= h($checkoutBtnText) ?></button>
                </div>

                <div class="text-muted small mt-2" id="dirtyHint" style="display:none;">
                    Du har endringer – trykk <strong>Oppdater handlekurv</strong> før du kan sjekke ut.
                </div>
            </form>

        </div>
    </div>
</div>

<script>
(function(){
    const qtyInputs = Array.from(document.querySelectorAll('.cart-qty'));
    const noteInput = document.getElementById('noteInput');
    const noteHidden = document.getElementById('noteHidden');

    const checkoutBtn = document.getElementById('checkoutBtn');
    const dirtyHint = document.getElementById('dirtyHint');

    const updateForm = document.getElementById('updateForm');

    if (!checkoutBtn) return;

    const initial = {
        qty: qtyInputs.map(i => i.value),
        note: noteInput ? noteInput.value : ''
    };

    function isDirty() {
        for (let i = 0; i < qtyInputs.length; i++) {
            if ((qtyInputs[i].value || '') !== (initial.qty[i] || '')) return true;
        }
        if (noteInput && (noteInput.value || '') !== (initial.note || '')) return true;
        return false;
    }

    function setCheckoutEnabled(enabled) {
        checkoutBtn.disabled = !enabled;
        if (dirtyHint) dirtyHint.style.display = enabled ? 'none' : '';
    }

    function syncNoteHidden() {
        if (noteHidden && noteInput) noteHidden.value = noteInput.value || '';
    }

    function onChange() {
        syncNoteHidden();
        setCheckoutEnabled(!isDirty());
    }

    qtyInputs.forEach(i => i.addEventListener('input', onChange));
    if (noteInput) noteInput.addEventListener('input', onChange);

    if (updateForm) {
        updateForm.addEventListener('submit', function(){
            syncNoteHidden();
        });
    }

    syncNoteHidden();
    setCheckoutEnabled(true);
})();
</script>