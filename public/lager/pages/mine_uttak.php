<?php
// public/lager/pages/mine_uttak.php

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

$u   = require_lager_login();
$pdo = get_pdo();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Logg exceptions pent
set_exception_handler(function($e){
    error_log("mine_uttak.php EXCEPTION: ".$e->getMessage()."\n".$e->getTraceAsString());
    http_response_code(500);
    echo "<div class='alert alert-danger mt-3'>Serverfeil (se PHP error_log).</div>";
    exit;
});

// ---------------------------------------------------------
// Polyfills / helpers (guard mot redeclare)
// ---------------------------------------------------------
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
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

if (!function_exists('table_columns')) {
    function table_columns(PDO $pdo, string $table): array {
        try {
            $cols = [];
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($r['Field'])) $cols[] = (string)$r['Field'];
            }
            return $cols;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('table_columns_meta')) {
    function table_columns_meta(PDO $pdo, string $table): array {
        try {
            $meta = [];
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($r['Field'])) $meta[(string)$r['Field']] = $r;
            }
            return $meta;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('pick_first')) {
    function pick_first(array $candidates, array $available): ?string {
        foreach ($candidates as $c) {
            if (in_array($c, $available, true)) return (string)$c;
        }
        return null;
    }
}

if (!function_exists('sanitize_date')) {
    function sanitize_date(string $d): string {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
    }
}

if (!function_exists('g_str')) {
    function g_str(string $key, string $default = ''): string {
        return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }
}

if (!function_exists('is_text_type')) {
    function is_text_type(string $mysqlType): bool {
        $t = strtolower($mysqlType);
        return (str_contains($t, 'char') || str_contains($t, 'text') || str_contains($t, 'blob'));
    }
}

// ---------------------------------------------------------
// Sjekk tabeller
// ---------------------------------------------------------
$hasHistoryTables = table_exists($pdo, 'lager_withdrawals') && table_exists($pdo, 'lager_withdrawal_lines');
$hasMovements     = table_exists($pdo, 'inv_movements');

$projects  = [];
$locations = [];

// products: label + unit
$products = [];             // product_id => "Navn (SKU)"
$productUnits = [];         // product_id => "stk/m/kg..."
$defaultUnit = 'stk';

$items             = []; // withdrawals
$linesByWithdrawal = [];
$moveLines         = []; // fallback: inv_movements-linjer

$errors = [];

// ---------------------------------------------------------
// Current user (robust)
// ---------------------------------------------------------
$currentUserId = 0;
foreach (['id', 'user_id', 'lager_user_id'] as $k) {
    if (isset($u[$k]) && (int)$u[$k] > 0) { $currentUserId = (int)$u[$k]; break; }
}

$currentUsername = '';
foreach (['username', 'user', 'email', 'name'] as $k) {
    if (!empty($u[$k])) { $currentUsername = (string)$u[$k]; break; }
}

// Candidate strings: epost + evt localpart
$candidateStrings = [];
if ($currentUsername !== '') {
    $candidateStrings[] = $currentUsername;
    if (str_contains($currentUsername, '@')) {
        $candidateStrings[] = strtolower(trim(strtok($currentUsername, '@')));
    }
}

// ---------------------------------------------------------
// Finn kandidat-IDer basert på epost/brukernavn (users / lager_users)
// ---------------------------------------------------------
$candidateUserIds = [];
if ($currentUserId > 0) $candidateUserIds[] = $currentUserId;

function lookup_user_id(PDO $pdo, string $table, string $value): array {
    if ($value === '') return [];
    if (!table_exists($pdo, $table)) return [];

    $cols = table_columns($pdo, $table);
    if (!$cols) return [];

    $idCol = pick_first(['id','user_id','lager_user_id'], $cols);
    if (!$idCol) return [];

    $emailCol = pick_first(['email','mail','e_mail'], $cols);
    $userCol  = pick_first(['username','user','login','name'], $cols);

    $where = [];
    $params = [];

    if ($emailCol) { $where[] = "`$emailCol` = :v";  $params[':v']  = $value; }
    if ($userCol)  { $where[] = "`$userCol`  = :v2"; $params[':v2'] = $value; }

    if (!$where) return [];

    $sql = "SELECT `$idCol` AS id FROM `$table` WHERE (" . implode(' OR ', $where) . ") LIMIT 5";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->execute();

    $out = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) $out[] = $id;
    }
    return $out;
}

if ($currentUsername !== '') {
    foreach (['users', 'lager_users'] as $t) {
        try {
            $ids = lookup_user_id($pdo, $t, $currentUsername);
            foreach ($ids as $id) $candidateUserIds[] = $id;
        } catch (\Throwable $e) { /* ignore */ }
    }
}

$candidateUserIds = array_values(array_unique(array_values(array_filter(
    $candidateUserIds,
    static fn($x) => (int)$x > 0
))));

// ---------------------------------------------------------
// Oppslagstabeller (labels + enheter)
// ---------------------------------------------------------
try {
    if (table_exists($pdo, 'inv_products')) {
        $prCols = table_columns($pdo, 'inv_products');
        $prId   = pick_first(['id'], $prCols) ?? 'id';
        $prName = pick_first(['name','product_name','title'], $prCols) ?? 'name';
        $prSku  = pick_first(['sku','SKU','item_no','varenr'], $prCols);

        // Finn mulig enhetskolonne
        $prUnit = pick_first(
            ['unit','unit_name','unit_code','uom','uom_name','uom_code','enhet','measure_unit','qty_unit'],
            $prCols
        );

        $sql = "SELECT `$prId` AS id, `$prName` AS name"
             . ($prSku  ? ", `$prSku` AS sku"   : "")
             . ($prUnit ? ", `$prUnit` AS unit" : "")
             . " FROM inv_products";

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) continue;

            $label = (string)($r['name'] ?? '');
            $sku   = (string)($r['sku'] ?? '');
            if ($sku !== '') $label .= ' (' . $sku . ')';
            $products[$id] = $label;

            $unit = trim((string)($r['unit'] ?? ''));
            $productUnits[$id] = ($unit !== '' ? $unit : $defaultUnit);
        }
    }
} catch (\Throwable $e) {}

// ---------------------------------------------------------
// Filtre
// ---------------------------------------------------------
$filters = [
    'q'         => g_str('q', ''),
    'date_from' => sanitize_date(g_str('date_from', '')),
    'date_to'   => sanitize_date(g_str('date_to', '')),
];

// ---------------------------------------------------------
// Debug info
// ---------------------------------------------------------
$userFilterDebug = [
    'uid' => $currentUserId,
    'uname' => $currentUsername,
    'candidate_ids' => $candidateUserIds,
    'candidate_strings' => $candidateStrings,
    'matched_cols' => [],
    'user_col_type' => '',
    'source' => '',
];

// ---------------------------------------------------------
// 1) Withdrawals (hvis i bruk)
// ---------------------------------------------------------
$withdrawalsCount = 0;
if ($hasHistoryTables) {
    try {
        $withdrawalsCount = (int)$pdo->query("SELECT COUNT(*) FROM lager_withdrawals")->fetchColumn();
    } catch (\Throwable $e) {
        $withdrawalsCount = 0;
    }
}

if ($hasHistoryTables && $withdrawalsCount > 0) {
    $userFilterDebug['source'] = 'lager_withdrawals';

    try {
        $wCols = table_columns($pdo, 'lager_withdrawals');
        $wMeta = table_columns_meta($pdo, 'lager_withdrawals');

        $wId        = pick_first(['id','withdrawal_id'], $wCols) ?? 'id';

        $wUserNum   = pick_first(['lager_user_id','user_id','created_by_user_id','created_by_id'], $wCols);
        $wUserStr   = pick_first(['created_by','created_by_username','username','user','email','created_by_email'], $wCols);

        $wFromProj  = pick_first(['from_project_id','source_project_id','source_logical_project_id'], $wCols);
        $wFromLoc   = pick_first(['from_location_id','source_location_id','source_physical_location_id'], $wCols);
        $wToProj    = pick_first(['to_project_id','target_project_id','dest_project_id'], $wCols);
        $wWorkOrder = pick_first(['work_order_id','target_work_order_id','wo_id'], $wCols);
        $wNote      = pick_first(['note','comment','remarks'], $wCols);
        $wCreated   = pick_first(['created_at','created','created_on','occurred_at'], $wCols) ?? 'created_at';

        $sel = [];
        $sel[] = "`$wId` AS id";
        $sel[] = ($wFromProj ? "`$wFromProj` AS from_project_id" : "NULL AS from_project_id");
        $sel[] = ($wFromLoc  ? "`$wFromLoc`  AS from_location_id" : "NULL AS from_location_id");
        $sel[] = ($wToProj   ? "`$wToProj`   AS to_project_id" : "NULL AS to_project_id");
        $sel[] = ($wWorkOrder? "`$wWorkOrder` AS work_order_id" : "NULL AS work_order_id");
        $sel[] = ($wNote     ? "`$wNote`     AS note" : "NULL AS note");
        $sel[] = "`$wCreated` AS created_at";

        $userConds = [];
        $params = [];

        if ($wUserNum) {
            $type = (string)($wMeta[$wUserNum]['Type'] ?? '');
            $userFilterDebug['user_col_type'] = $type;
            $userFilterDebug['matched_cols'][] = $wUserNum;

            if (is_text_type($type)) {
                if ($currentUsername !== '') {
                    $userConds[] = "`$wUserNum` = :uname";
                    $params[':uname'] = $currentUsername;
                }
                if ($currentUserId > 0) {
                    $userConds[] = "`$wUserNum` = :uid_str";
                    $params[':uid_str'] = (string)$currentUserId;
                }
            } else {
                if ($candidateUserIds) {
                    $in = [];
                    foreach ($candidateUserIds as $i => $id) {
                        $ph = ":uid$i";
                        $in[] = $ph;
                        $params[$ph] = (int)$id;
                    }
                    $userConds[] = "`$wUserNum` IN (" . implode(',', $in) . ")";
                } elseif ($currentUserId > 0) {
                    $userConds[] = "`$wUserNum` = :uid";
                    $params[':uid'] = $currentUserId;
                }
            }
        }

        if ($wUserStr && $currentUsername !== '') {
            $userFilterDebug['matched_cols'][] = $wUserStr;
            $userConds[] = "`$wUserStr` = :uname2";
            $params[':uname2'] = $currentUsername;
        }

        if (!$userConds) {
            $errors[] = "Fant ingen brukerkolonne i lager_withdrawals som kan matches mot innlogget bruker.";
            $items = [];
        } else {
            $whereSql = '( ' . implode(' OR ', $userConds) . ' )';

            if ($filters['q'] !== '' && $wNote) {
                $whereSql .= " AND `$wNote` LIKE :q";
                $params[':q'] = '%' . $filters['q'] . '%';
            }

            if ($filters['date_from'] !== '') {
                $whereSql .= " AND `$wCreated` >= :df";
                $params[':df'] = $filters['date_from'] . ' 00:00:00';
            }
            if ($filters['date_to'] !== '') {
                $whereSql .= " AND `$wCreated` <= :dt";
                $params[':dt'] = $filters['date_to'] . ' 23:59:59';
            }

            $sql = "
                SELECT " . implode(",\n                       ", $sel) . "
                  FROM lager_withdrawals
                 WHERE $whereSql
                 ORDER BY `$wCreated` DESC, `$wId` DESC
                 LIMIT 200
            ";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                if (is_int($v)) $stmt->bindValue($k, $v, PDO::PARAM_INT);
                else $stmt->bindValue($k, (string)$v, PDO::PARAM_STR);
            }
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Linjer
        if ($items) {
            $lineCols = table_columns($pdo, 'lager_withdrawal_lines');

            $lWid = pick_first(['withdrawal_id','lager_withdrawal_id'], $lineCols) ?? 'withdrawal_id';
            $lPid = pick_first(['product_id','inv_product_id'], $lineCols) ?? 'product_id';
            $lQty = pick_first(['qty','quantity','antall'], $lineCols) ?? 'qty';

            $ids = array_values(array_map(static fn($r) => (int)($r['id'] ?? 0), $items));
            $ids = array_values(array_filter($ids, static fn($x) => $x > 0));

            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare("
                    SELECT `$lWid` AS withdrawal_id, `$lPid` AS product_id, `$lQty` AS qty
                      FROM lager_withdrawal_lines
                     WHERE `$lWid` IN ($in)
                ");
                $st->execute($ids);

                foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
                    $wid = (int)($r['withdrawal_id'] ?? 0);
                    if ($wid <= 0) continue;
                    if (!isset($linesByWithdrawal[$wid])) $linesByWithdrawal[$wid] = [];
                    $linesByWithdrawal[$wid][] = [
                        'product_id' => (int)($r['product_id'] ?? 0),
                        'qty'        => (int)($r['qty'] ?? 0),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
        $items = [];
        $linesByWithdrawal = [];
    }
}

// ---------------------------------------------------------
// 2) Fallback: inv_movements (når withdrawals er tom / ikke finnes)
// ---------------------------------------------------------
if ((!$hasHistoryTables || $withdrawalsCount === 0) && $hasMovements) {
    $userFilterDebug['source'] = 'inv_movements';

    try {
        $mCols = table_columns($pdo, 'inv_movements');

        $mId      = pick_first(['id','movement_id'], $mCols) ?? 'id';
        $mType    = pick_first(['movement_type','type','direction'], $mCols);
        $mQty     = pick_first(['qty','quantity','antall'], $mCols) ?? 'qty';
        $mPid     = pick_first(['product_id','inv_product_id'], $mCols);
        $mNote    = pick_first(['note','comment','remarks','description'], $mCols);
        $mCreated = pick_first(['created_at','created','occurred_at','movement_date'], $mCols) ?? 'created_at';

        $mUserNum = pick_first(['created_by_user_id','user_id','lager_user_id'], $mCols);
        $mUserStr = pick_first(['created_by','username','user','email'], $mCols);

        $sel = [];
        $sel[] = "`$mId` AS id";
        $sel[] = ($mPid ? "`$mPid` AS product_id" : "NULL AS product_id");
        $sel[] = "`$mQty` AS qty";
        $sel[] = ($mNote  ? "`$mNote` AS note" : "NULL AS note");
        $sel[] = "`$mCreated` AS created_at";

        $where = [];
        $params = [];

        // Bare uttak hvis typekolonne finnes
        if ($mType) {
            $where[] = "LOWER(`$mType`) IN ('ut','out','uttak','withdrawal')";
        }

        // Brukerfilter
        $userParts = [];
        if ($mUserNum && $candidateUserIds) {
            $in = [];
            foreach ($candidateUserIds as $i => $id) {
                $ph = ":uid$i";
                $in[] = $ph;
                $params[$ph] = (int)$id;
            }
            $userParts[] = "`$mUserNum` IN (" . implode(',', $in) . ")";
            $userFilterDebug['matched_cols'][] = $mUserNum;
        }
        if ($mUserStr && $candidateStrings) {
            $in = [];
            foreach ($candidateStrings as $i => $s) {
                $ph = ":us$i";
                $in[] = $ph;
                $params[$ph] = (string)$s;
            }
            $userParts[] = "`$mUserStr` IN (" . implode(',', $in) . ")";
            $userFilterDebug['matched_cols'][] = $mUserStr;
        }

        if ($userParts) $where[] = '(' . implode(' OR ', $userParts) . ')';

        if ($filters['q'] !== '' && $mNote) {
            $where[] = "`$mNote` LIKE :q";
            $params[':q'] = '%' . $filters['q'] . '%';
        }

        if ($filters['date_from'] !== '') {
            $where[] = "`$mCreated` >= :df";
            $params[':df'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $where[] = "`$mCreated` <= :dt";
            $params[':dt'] = $filters['date_to'] . ' 23:59:59';
        }

        if (!$where) $where[] = "1=0";

        $sql = "
            SELECT " . implode(",\n                   ", $sel) . "
              FROM inv_movements
             WHERE " . implode(" AND ", $where) . "
             ORDER BY `$mCreated` DESC, `$mId` DESC
             LIMIT 200
        ";

        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            if (is_int($v)) $st->bindValue($k, $v, PDO::PARAM_INT);
            else $st->bindValue($k, (string)$v, PDO::PARAM_STR);
        }
        $st->execute();
        $moveLines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
        $moveLines = [];
    }
}
?>

<style>
.mine-uttak-note{
    max-width: 700px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>

<div class="d-flex align-items-start justify-content-between mt-3 flex-wrap gap-2">
    <div>
        <h3 class="mb-1">Mine uttak</h3>
        <div class="text-muted">
            <?php if ($userFilterDebug['source'] === 'lager_withdrawals'): ?>
                Historikk over uttak du har registrert (gruppert).
            <?php else: ?>
                Historikk basert på bevegelser.
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="/lager/">Tilbake til meny</a>
        <a class="btn btn-primary" href="/lager/uttak">Nytt uttak</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger mt-3">
        <strong>Feil:</strong><br>
        <?= nl2br(h(implode("\n", $errors))) ?>
    </div>
<?php endif; ?>

<!-- Filtre -->
<div class="card mt-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold">Filtre</span>
        <a class="btn btn-sm btn-outline-secondary" href="/lager/mine_uttak">Nullstill</a>
    </div>
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-12 col-md-4">
                <label class="form-label">Søk i kommentar</label>
                <input class="form-control" name="q" placeholder="tekst i kommentar" value="<?= h($filters['q']) ?>">
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label">Fra dato</label>
                <input class="form-control" type="date" name="date_from" value="<?= h($filters['date_from']) ?>">
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label">Til dato</label>
                <input class="form-control" type="date" name="date_to" value="<?= h($filters['date_to']) ?>">
            </div>

            <div class="col-12 col-md-2 d-flex align-items-end">
                <button class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i> Filtrer
                </button>
            </div>
        </form>
    </div>
    <div class="card-footer small text-muted">
        Viser maks 200 uttak.
        <?php if (isset($_GET['debug'])): ?>
            <span class="ms-2">
                DEBUG:
                uid=<?= (int)$userFilterDebug['uid'] ?>,
                uname=<?= h($userFilterDebug['uname']) ?>,
                candidate_ids=<?= h(implode(',', $userFilterDebug['candidate_ids'])) ?>,
                candidate_strings=<?= h(implode(' | ', $userFilterDebug['candidate_strings'])) ?>,
                cols=<?= h(implode(',', $userFilterDebug['matched_cols'])) ?>,
                type=<?= h($userFilterDebug['user_col_type']) ?>,
                source=<?= h($userFilterDebug['source']) ?>
            </span>
        <?php endif; ?>
    </div>
</div>

<?php
// ---------------------------------------------------------
// Render
// ---------------------------------------------------------
if ($userFilterDebug['source'] === 'lager_withdrawals'):

    if (!$items): ?>
        <div class="card mt-3">
            <div class="card-body">
                <div class="fw-bold">Ingen uttak registrert av deg i valgt filtrering.</div>
                <div class="text-muted small mt-1">
                    Legg til <code>?debug=1</code> for å se hvilke bruker-id’er som forsøkes matchet.
                </div>
            </div>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <div class="mt-3 d-flex flex-column gap-2">
    <?php foreach ($items as $it): ?>
        <?php
            $wid     = (int)($it['id'] ?? 0);
            $fromP   = (int)($it['from_project_id'] ?? 0);
            $toP     = (int)($it['to_project_id'] ?? 0);
            $fromL   = (int)($it['from_location_id'] ?? 0);
            $note    = trim((string)($it['note'] ?? ''));
            $created = (string)($it['created_at'] ?? '');
            $woId    = (int)($it['work_order_id'] ?? 0);

            $lines = $linesByWithdrawal[$wid] ?? [];
        ?>

        <div class="card">
            <div class="card-body">
                <details>
                    <summary class="fw-semibold">
                        Uttak #<?= (int)$wid ?>
                        <?php if ($created !== ''): ?>
                            <span class="text-muted small"> · <?= h($created) ?></span>
                        <?php endif; ?>
                    </summary>

                    <div class="mt-3 d-flex flex-wrap gap-2">
                        <span class="badge text-bg-light">
                            Fra prosjekt: <?= h($projects[$fromP] ?? ($fromP > 0 ? ('#'.$fromP) : '-')) ?>
                        </span>
                        <span class="badge text-bg-light">
                            Fra lokasjon: <?= h($locations[$fromL] ?? ($fromL > 0 ? ('#'.$fromL) : '-')) ?>
                        </span>
                        <span class="badge text-bg-light">
                            Til prosjekt: <?= h($projects[$toP] ?? ($toP > 0 ? ('#'.$toP) : '-')) ?>
                        </span>
                        <?php if ($woId > 0): ?>
                            <span class="badge text-bg-light">Arbeidsordre: #<?= (int)$woId ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($note !== ''): ?>
                        <div class="text-muted small mt-2"><strong>Kommentar:</strong> <?= h($note) ?></div>
                    <?php endif; ?>

                    <div class="mt-3 fw-semibold">Linjer</div>
                    <?php if (!$lines): ?>
                        <div class="text-muted small">Ingen linjer funnet.</div>
                    <?php else: ?>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($lines as $ln): ?>
                                <?php
                                    $pid = (int)($ln['product_id'] ?? 0);
                                    $qty = (int)($ln['qty'] ?? 0);
                                    $unit = $productUnits[$pid] ?? $defaultUnit;
                                ?>
                                <li>
                                    <?= h($products[$pid] ?? ($pid > 0 ? ('Produkt #'.$pid) : 'Ukjent produkt')) ?>
                                    — <strong><?= (int)$qty ?></strong> <?= h($unit) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </details>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

<?php else: ?>

    <?php if (!$moveLines): ?>
        <div class="card mt-3">
            <div class="card-body">
                <div class="fw-bold">Ingen uttak funnet for deg i valgt filtrering.</div>
                <div class="text-muted small mt-1">
                    Legg til <code>?debug=1</code> for å se hvilke bruker-id’er/strenger som forsøkes matchet.
                </div>
            </div>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="fw-semibold">Uttak</span>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-nowrap">Tid</th>
                        <th>Vare</th>
                        <th class="text-end text-nowrap">Antall</th>
                        <th>Kommentar</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($moveLines as $r): ?>
                    <?php
                        $created = (string)($r['created_at'] ?? '');
                        $pid = (int)($r['product_id'] ?? 0);
                        $qty = (int)($r['qty'] ?? 0);
                        $note  = trim((string)($r['note'] ?? ''));
                        $prodLabel = $products[$pid] ?? ($pid > 0 ? ('Produkt #'.$pid) : 'Ukjent produkt');
                        $unit = $productUnits[$pid] ?? $defaultUnit;
                    ?>
                    <tr>
                        <td class="text-nowrap">
                            <div class="small"><?= h($created) ?></div>
                        </td>

                        <td>
                            <div class="fw-semibold"><?= h($prodLabel) ?></div>
                        </td>

                        <td class="text-end text-nowrap">
                            <span class="fw-bold"><?= (int)$qty ?></span> <?= h($unit) ?>
                        </td>

                        <td class="small text-muted">
                            <?php if ($note === ''): ?>
                                <span class="text-muted">—</span>
                            <?php else: ?>
                                <div class="mine-uttak-note" title="<?= h($note) ?>"><?= h($note) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>
