<?php
// public/pages/logistikk_movements.php

use App\Database;

$pageTitle = 'Logistikk: Bevegelser';

// Krev innlogging
$username = $_SESSION['username'] ?? '';
if (!$username) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

// Admin-sjekk (lese er OK for alle innloggede, men admin får ekstra)
$isAdmin = $_SESSION['is_admin'] ?? false;

$pdo = Database::getConnection();

$errors = [];
$successMessage = null;

// -----------------------------
// Helpers
// -----------------------------
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

function g_str(string $key, string $default = ''): string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function g_int(string $key, int $default = 0): int
{
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}

function sanitizeDate(string $d): string
{
    // godta YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
    return '';
}

$tablesOk =
    tableExists($pdo, 'inv_movements') &&
    tableExists($pdo, 'inv_products') &&
    tableExists($pdo, 'inv_batches');

if (!$tablesOk) {
    ?>
    <div class="alert alert-warning mt-3">
        <div class="fw-semibold mb-1">Mangler tabeller</div>
        Denne siden krever inv_movements, inv_products og inv_batches.
    </div>
    <?php
    return;
}

function fetchProductsForFilter(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, name, sku, is_active
        FROM inv_products
        ORDER BY is_active DESC, name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchMovements(PDO $pdo, array $f, int $limit = 500): array
{
    $where = [];
    $params = [];

    if (!empty($f['type'])) {
        $where[] = "m.type = :type";
        $params[':type'] = $f['type'];
    }

    if (!empty($f['product_id'])) {
        $where[] = "m.product_id = :pid";
        $params[':pid'] = (int)$f['product_id'];
    }

    if (!empty($f['reference_type'])) {
        $where[] = "m.reference_type = :rtype";
        $params[':rtype'] = $f['reference_type'];
    }

    if (!empty($f['reference_no'])) {
        $where[] = "m.reference_no LIKE :rno";
        $params[':rno'] = '%' . $f['reference_no'] . '%';
    }

    if (!empty($f['issued_to'])) {
        $where[] = "m.issued_to LIKE :to";
        $params[':to'] = '%' . $f['issued_to'] . '%';
    }

    if (!empty($f['created_by'])) {
        $where[] = "m.created_by LIKE :by";
        $params[':by'] = '%' . $f['created_by'] . '%';
    }

    if (!empty($f['q'])) {
        $where[] = "(p.name LIKE :q OR p.sku LIKE :q OR m.note LIKE :q)";
        $params[':q'] = '%' . $f['q'] . '%';
    }

    if (!empty($f['date_from'])) {
        $where[] = "m.occurred_at >= :df";
        $params[':df'] = $f['date_from'] . " 00:00:00";
    }

    if (!empty($f['date_to'])) {
        $where[] = "m.occurred_at <= :dt";
        $params[':dt'] = $f['date_to'] . " 23:59:59";
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

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
            p.id AS product_id,
            p.name AS product_name,
            p.sku  AS product_sku,
            p.unit AS product_unit,
            b.id AS batch_id
        FROM inv_movements m
        LEFT JOIN inv_products p ON p.id = m.product_id
        LEFT JOIN inv_batches b ON b.id = m.batch_id
        $whereSql
        ORDER BY m.occurred_at DESC, m.id DESC
        LIMIT :lim
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchSummary(PDO $pdo, array $f): array
{
    // Samme filter (uten limit) men gruppert
    $where = [];
    $params = [];

    if (!empty($f['product_id'])) {
        $where[] = "m.product_id = :pid";
        $params[':pid'] = (int)$f['product_id'];
    }
    if (!empty($f['type'])) {
        $where[] = "m.type = :type";
        $params[':type'] = $f['type'];
    }
    if (!empty($f['reference_type'])) {
        $where[] = "m.reference_type = :rtype";
        $params[':rtype'] = $f['reference_type'];
    }
    if (!empty($f['reference_no'])) {
        $where[] = "m.reference_no LIKE :rno";
        $params[':rno'] = '%' . $f['reference_no'] . '%';
    }
    if (!empty($f['issued_to'])) {
        $where[] = "m.issued_to LIKE :to";
        $params[':to'] = '%' . $f['issued_to'] . '%';
    }
    if (!empty($f['created_by'])) {
        $where[] = "m.created_by LIKE :by";
        $params[':by'] = '%' . $f['created_by'] . '%';
    }
    if (!empty($f['q'])) {
        $where[] = "(p.name LIKE :q OR p.sku LIKE :q OR m.note LIKE :q)";
        $params[':q'] = '%' . $f['q'] . '%';
    }
    if (!empty($f['date_from'])) {
        $where[] = "m.occurred_at >= :df";
        $params[':df'] = $f['date_from'] . " 00:00:00";
    }
    if (!empty($f['date_to'])) {
        $where[] = "m.occurred_at <= :dt";
        $params[':dt'] = $f['date_to'] . " 23:59:59";
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN m.type='IN'  THEN m.qty ELSE 0 END)  AS qty_in,
            SUM(CASE WHEN m.type='OUT' THEN m.qty ELSE 0 END)  AS qty_out,
            COUNT(*) AS rows_count
        FROM inv_movements m
        LEFT JOIN inv_products p ON p.id = m.product_id
        $whereSql
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['qty_in' => 0, 'qty_out' => 0, 'rows_count' => 0];

    return $row;
}

// -----------------------------
// Filters
// -----------------------------
$filters = [
    'q' => g_str('q', ''),
    'type' => g_str('type', ''),
    'product_id' => g_int('product_id', 0),
    'reference_type' => g_str('reference_type', ''),
    'reference_no' => g_str('reference_no', ''),
    'issued_to' => g_str('issued_to', ''),
    'created_by' => $isAdmin ? g_str('created_by', '') : '', // kun admin kan filtrere på bruker om du vil
    'date_from' => sanitizeDate(g_str('date_from', '')),
    'date_to' => sanitizeDate(g_str('date_to', '')),
];

// Default: siste 30 dager hvis ingen dato er valgt
if ($filters['date_from'] === '' && $filters['date_to'] === '' && $filters['q'] === '' && $filters['product_id'] === 0) {
    $filters['date_from'] = date('Y-m-d', strtotime('-30 days'));
    $filters['date_to']   = date('Y-m-d');
}

$products = fetchProductsForFilter($pdo);
$summary  = fetchSummary($pdo, $filters);
$rows     = fetchMovements($pdo, $filters, 500);

// -----------------------------
// Format helpers
// -----------------------------
function fmt_qty($v): string
{
    $f = (float)$v;
    return rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.');
}

function fmt_price($v): string
{
    $f = (float)$v;
    return rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
}

function buildQuery(array $filters, array $overrides = []): string
{
    $q = array_merge($filters, $overrides);
    // fjern tomme
    foreach ($q as $k => $v) {
        if ($v === '' || $v === 0 || $v === null) unset($q[$k]);
    }
    $q['page'] = 'logistikk_movements';
    return '/?' . http_build_query($q);
}
?>

<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h3 class="mb-0">Bevegelser</h3>
            <div class="text-muted">Historikk for inn/ut, filtrer på vare, arbeidsordre, prosjekt, montør osv.</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk">
                <i class="bi bi-arrow-left"></i> Til oversikt
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1">Feil</div>
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                    <li><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- Filtre -->
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="fw-semibold">Filtre</span>
            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_movements">Nullstill</a>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2">
                <input type="hidden" name="page" value="logistikk_movements">

                <div class="col-12 col-md-4">
                    <label class="form-label">Søk</label>
                    <input class="form-control" name="q" placeholder="Vare, SKU eller notat"
                           value="<?php echo htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label">Type</label>
                    <select class="form-select" name="type">
                        <option value="" <?php echo $filters['type'] === '' ? 'selected' : ''; ?>>Alle</option>
                        <option value="IN" <?php echo $filters['type'] === 'IN' ? 'selected' : ''; ?>>INN</option>
                        <option value="OUT" <?php echo $filters['type'] === 'OUT' ? 'selected' : ''; ?>>UT</option>
                        <option value="ADJUST" <?php echo $filters['type'] === 'ADJUST' ? 'selected' : ''; ?>>JUST</option>
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Vare</label>
                    <select class="form-select" name="product_id">
                        <option value="0">Alle</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)$filters['product_id'] === (int)$p['id']) ? 'selected' : ''; ?>>
                                <?php
                                $label = $p['name'] . (!empty($p['sku']) ? ' (' . $p['sku'] . ')' : '');
                                echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Ref-type</label>
                    <select class="form-select" name="reference_type">
                        <option value="" <?php echo $filters['reference_type'] === '' ? 'selected' : ''; ?>>Alle</option>
                        <option value="arbeidsordre" <?php echo $filters['reference_type'] === 'arbeidsordre' ? 'selected' : ''; ?>>Arbeidsordre</option>
                        <option value="prosjekt" <?php echo $filters['reference_type'] === 'prosjekt' ? 'selected' : ''; ?>>Prosjekt</option>
                        <option value="annet" <?php echo $filters['reference_type'] === 'annet' ? 'selected' : ''; ?>>Annet</option>
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Ref-nr</label>
                    <input class="form-control" name="reference_no" placeholder="AO/Prosjektnr"
                           value="<?php echo htmlspecialchars($filters['reference_no'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Utlevert til</label>
                    <input class="form-control" name="issued_to" placeholder="Montør/person"
                           value="<?php echo htmlspecialchars($filters['issued_to'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Fra dato</label>
                    <input class="form-control" type="date" name="date_from"
                           value="<?php echo htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Til dato</label>
                    <input class="form-control" type="date" name="date_to"
                           value="<?php echo htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <?php if ($isAdmin): ?>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Registrert av</label>
                        <input class="form-control" name="created_by" placeholder="brukernavn"
                               value="<?php echo htmlspecialchars($filters['created_by'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                <?php endif; ?>

                <div class="col-12 mt-2 d-flex gap-2">
                    <button class="btn btn-outline-primary">
                        <i class="bi bi-search me-1"></i> Filtrer
                    </button>
                    <a class="btn btn-outline-secondary" href="/?page=logistikk_movements">Nullstill</a>
                </div>
            </form>
        </div>
        <div class="card-footer small text-muted">
            Viser maks 500 rader per søk.
        </div>
    </div>

    <!-- Oppsummering -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Rader</div>
                    <div class="fs-3 fw-semibold"><?php echo (int)$summary['rows_count']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Sum inn</div>
                    <div class="fs-3 fw-semibold"><?php echo fmt_qty($summary['qty_in']); ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Sum ut</div>
                    <div class="fs-3 fw-semibold"><?php echo fmt_qty($summary['qty_out']); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Liste -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="fw-semibold">Resultater</span>
            <span class="text-muted small"><?php echo count($rows); ?> vist</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Tid</th>
                            <th>Vare</th>
                            <th>Type</th>
                            <th class="text-end">Antall</th>
                            <th class="text-end">Pris</th>
                            <th>Ref</th>
                            <th>Utlevert til</th>
                            <th>Notat</th>
                            <th class="text-muted">Av</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="small text-muted"><?php echo htmlspecialchars($r['occurred_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <div class="fw-semibold">
                                        <?php echo htmlspecialchars($r['product_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div class="text-muted small">
                                        <?php echo htmlspecialchars($r['product_sku'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($r['type'] === 'IN'): ?>
                                        <span class="badge bg-success">INN</span>
                                    <?php elseif ($r['type'] === 'OUT'): ?>
                                        <span class="badge bg-danger">UT</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($r['type'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php echo fmt_qty($r['qty']); ?>
                                    <span class="text-muted"><?php echo htmlspecialchars($r['product_unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td class="text-end"><?php echo fmt_price($r['unit_price']); ?></td>
                                <td class="small">
                                    <?php
                                    $ref = trim(($r['reference_type'] ?? '') . ' ' . ($r['reference_no'] ?? ''));
                                    echo htmlspecialchars($ref !== '' ? $ref : '—', ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($r['issued_to'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="small"><?php echo htmlspecialchars($r['note'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($r['created_by'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="9" class="text-muted p-3">Ingen treff.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer small text-muted">
            Tips: Bruk Ref-type + Ref-nr for å finne alt som er tatt ut på en arbeidsordre/prosjekt.
        </div>
    </div>
</div>
