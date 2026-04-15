<?php
// public/pages/billing_invoices.php
//
// Fakturaarkiv / oversikt over opprettede fakturagrunnlag (drafts)
// + mulighet for å sette status.
//
// Forutsetter minst:
// - billing_invoice_drafts
// - crm_accounts
//
// Roller (user_roles):
// - admin            => full tilgang
// - invoice          => tilgang (read + status-write)
// - billing_read     => kan se arkiv
// - billing_write    => kan endre status
//
// Fallback:
// - $_SESSION['is_admin'] eller username === 'rsv' => admin

use App\Database;

$pageTitle = 'Fakturaarkiv';

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function h(?string $s): string {
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

function columnExists(PDO $pdo, string $table, string $column): bool
{
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

function g_str(string $key, string $default = ''): string {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function sanitizeDate(string $d): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
    return '';
}

function post_str(string $key): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}
function post_int(string $key): int {
    return isset($_POST[$key]) ? (int)$_POST[$key] : 0;
}

// ---------------------------------------------------------
// Krev innlogging + DB
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

$errors = [];
$successMessage = null;

$pdo = null;
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    $pdo = null;
    $errors[] = 'Klarte ikke koble til databasen.';
    if (!empty($_GET['debug'])) $errors[] = $e->getMessage();
}

if (!$pdo) {
    ?>
    <div class="alert alert-danger mt-3">
        <div class="fw-semibold mb-1">DB-feil</div>
        <?= h(implode(' / ', $errors)) ?>
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Rolle-guard (user_roles) + fallback admin
// ---------------------------------------------------------
$isAdmin = (bool)($_SESSION['is_admin'] ?? false);

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
    if (!empty($_GET['debug'])) $errors[] = 'Rolleoppslag feilet: ' . $e->getMessage();
}

if (!$isAdmin && in_array('admin', $currentRoles, true)) {
    $isAdmin = true;
}

// ---------------------------------------------------------
// NEW: invoice-role gir tilgang
// - invoice => read + write status (som billing_write)
// ---------------------------------------------------------
$hasInvoiceRole = in_array('invoice', $currentRoles, true);

$canRead  = $isAdmin
    || $hasInvoiceRole
    || in_array('billing_read', $currentRoles, true)
    || in_array('billing_write', $currentRoles, true);

$canWrite = $isAdmin
    || $hasInvoiceRole
    || in_array('billing_write', $currentRoles, true);

if (!$canRead) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til fakturaarkiv.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Sjekk tabeller
// ---------------------------------------------------------
$tablesOk = tableExists($pdo, 'billing_invoice_drafts') && tableExists($pdo, 'crm_accounts');
if (!$tablesOk) {
    ?>
    <div class="alert alert-warning mt-3">
        <div class="fw-semibold mb-1">Modulen er ikke initialisert</div>
        Mangler en eller flere tabeller: <code>billing_invoice_drafts</code>, <code>crm_accounts</code>.
    </div>
    <?php
    return;
}

// Kolonner vi kan vise hvis de finnes
$draftCols = [
    'updated_at' => columnExists($pdo, 'billing_invoice_drafts', 'updated_at'),
    'status'     => columnExists($pdo, 'billing_invoice_drafts', 'status'),
    'title'      => columnExists($pdo, 'billing_invoice_drafts', 'title'),
    'currency'   => columnExists($pdo, 'billing_invoice_drafts', 'currency'),
    'issue_date' => columnExists($pdo, 'billing_invoice_drafts', 'issue_date'),
    'due_date'   => columnExists($pdo, 'billing_invoice_drafts', 'due_date'),
    'created_by' => columnExists($pdo, 'billing_invoice_drafts', 'created_by'),
    'created_at' => columnExists($pdo, 'billing_invoice_drafts', 'created_at'),
    'account_id' => columnExists($pdo, 'billing_invoice_drafts', 'account_id'),
];

// ---------------------------------------------------------
// Status-list (for filter + dropdown)
// ---------------------------------------------------------
$baseStatuses = [
    'draft'     => 'Utkast',
    'ready'     => 'Klar',
    'sent'      => 'Sendt',
    'paid'      => 'Betalt',
    'cancelled' => 'Kansellert',
    'archived'  => 'Arkivert',
];

$existingStatuses = [];
try {
    if ($draftCols['status']) {
        $rows = $pdo->query("
            SELECT DISTINCT status
            FROM billing_invoice_drafts
            WHERE status IS NOT NULL AND status <> ''
            ORDER BY status
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $s) {
            $s = (string)$s;
            if ($s !== '') $existingStatuses[$s] = $s;
        }
    }
} catch (\Throwable $e) {
    if (!empty($_GET['debug'])) $errors[] = 'Kunne ikke hente statusliste: ' . $e->getMessage();
}

$statusOptions = $baseStatuses;
foreach ($existingStatuses as $k => $v) {
    if (!isset($statusOptions[$k])) $statusOptions[$k] = $v;
}

// ---------------------------------------------------------
// POST: oppdater status
// ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = post_str('action');

    try {
        if ($action === 'update_status') {
            if (!$canWrite) {
                throw new RuntimeException('Du har ikke tilgang til å endre status.');
            }

            $id     = post_int('id');
            $status = post_str('status');

            if ($id <= 0) throw new RuntimeException('Ugyldig ID.');
            if ($status === '') throw new RuntimeException('Velg status.');

            // Tillat kun status som finnes i vår liste (base + eksisterende)
            if (!array_key_exists($status, $statusOptions)) {
                throw new RuntimeException('Ugyldig status: ' . $status);
            }

            // Oppdater
            $set = "status = :s";
            if ($draftCols['updated_at']) {
                $set .= ", updated_at = NOW()";
            }

            $stmt = $pdo->prepare("UPDATE billing_invoice_drafts SET $set WHERE id = :id LIMIT 1");
            $stmt->execute([':s' => $status, ':id' => $id]);

            $successMessage = 'Status oppdatert.';

        } elseif ($action === 'delete') {
            if (!$isAdmin) {
                throw new RuntimeException('Kun administratorer kan slette fakturagrunnlag.');
            }

            $id = post_int('id');
            if ($id <= 0) throw new RuntimeException('Ugyldig ID.');

            // Slett tilknyttede linjer hvis tabellen finnes
            if (tableExists($pdo, 'billing_invoice_lines')) {
                $pdo->prepare("DELETE FROM billing_invoice_lines WHERE invoice_id = :id")
                    ->execute([':id' => $id]);
            }

            $stmt = $pdo->prepare("DELETE FROM billing_invoice_drafts WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);

            $successMessage = 'Fakturagrunnlag #' . $id . ' ble slettet.';
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// ---------------------------------------------------------
// Filtre (GET)
// ---------------------------------------------------------
$q        = g_str('q', '');
$statusF  = g_str('status', '');
$dateFrom = sanitizeDate(g_str('from', ''));
$dateTo   = sanitizeDate(g_str('to', ''));

if ($dateFrom === '' && $dateTo === '' && $q === '' && $statusF === '') {
    // Default: siste 90 dager
    $dateFrom = date('Y-m-d', strtotime('-90 days'));
    $dateTo   = date('Y-m-d');
}

// ---------------------------------------------------------
// Fetch liste
// ---------------------------------------------------------
$where = [];
$params = [];

if ($q !== '') {
    // Søk på tittel, kunde, id
    $where[] = "(d.title LIKE :q OR a.name LIKE :q OR CAST(d.id AS CHAR) LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

if ($statusF !== '') {
    $where[] = "d.status = :st";
    $params[':st'] = $statusF;
}

if ($dateFrom !== '' && $draftCols['created_at']) {
    $where[] = "d.created_at >= :df";
    $params[':df'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && $draftCols['created_at']) {
    $where[] = "d.created_at <= :dt";
    $params[':dt'] = $dateTo . ' 23:59:59';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
try {
    $select = "
        d.id,
        " . ($draftCols['status'] ? "d.status," : "'' AS status,") . "
        " . ($draftCols['title'] ? "d.title," : "'' AS title,") . "
        " . ($draftCols['currency'] ? "d.currency," : "'' AS currency,") . "
        " . ($draftCols['issue_date'] ? "d.issue_date," : "NULL AS issue_date,") . "
        " . ($draftCols['due_date'] ? "d.due_date," : "NULL AS due_date,") . "
        " . ($draftCols['created_by'] ? "d.created_by," : "'' AS created_by,") . "
        " . ($draftCols['created_at'] ? "d.created_at," : "NULL AS created_at,") . "
        " . ($draftCols['updated_at'] ? "d.updated_at," : "NULL AS updated_at,") . "
        a.id AS account_id,
        a.name AS account_name,
        a.type AS account_type,
        a.is_active AS account_active
    ";

    $sql = "
        SELECT $select
        FROM billing_invoice_drafts d
        LEFT JOIN crm_accounts a ON a.id = d.account_id
        $whereSql
        ORDER BY
            " . ($draftCols['created_at'] ? "d.created_at" : "d.id") . " DESC,
            d.id DESC
        LIMIT 1000
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $errors[] = 'Databasefeil ved henting av liste.';
    if (!empty($_GET['debug'])) $errors[] = $e->getMessage();
    $rows = [];
}

// KPI
$totalShown = count($rows);
$byStatus = [];
foreach ($rows as $r) {
    $s = (string)($r['status'] ?? '');
    if ($s === '') $s = '—';
    $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
}

// Badge helper
function statusBadge(string $s): array {
    $key = strtolower(trim($s));
    if ($key === 'draft') return ['bg-secondary', 'Utkast'];
    if ($key === 'ready') return ['bg-info', 'Klar'];
    if ($key === 'sent') return ['bg-primary', 'Sendt'];
    if ($key === 'paid') return ['bg-success', 'Betalt'];
    if ($key === 'cancelled') return ['bg-danger', 'Kansellert'];
    if ($key === 'archived') return ['bg-dark', 'Arkivert'];
    return ['bg-light text-dark border', $s !== '' ? $s : '—'];
}

?>
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h3 class="mb-0">Fakturaarkiv</h3>
            <div class="text-muted">Oversikt over opprettede fakturagrunnlag og status.</div>
            <?php if (!$isAdmin): ?>
                <div class="text-muted small mt-1">
                    Tilgang: <?= $canWrite ? 'invoice/billing_write' : 'invoice/billing_read' ?>.
                </div>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-primary" href="/?page=billing_invoice_new">
                <i class="bi bi-plus-lg me-1"></i> Nytt fakturagrunnlag
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
        <div class="alert alert-success">
            <?= h($successMessage) ?>
        </div>
    <?php endif; ?>

    <!-- KPI -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">Vist i liste</div>
                    <div class="fs-3 fw-semibold"><?= (int)$totalShown ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small mb-2">Fordeling per status (basert på filtrert liste)</div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if (!$byStatus): ?>
                            <span class="text-muted">—</span>
                        <?php else: ?>
                            <?php foreach ($byStatus as $st => $cnt): ?>
                                <?php [$cls, $lbl] = statusBadge($st); ?>
                                <span class="badge <?= h($cls) ?>">
                                    <?= h($lbl) ?>: <?= (int)$cnt ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtre -->
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="fw-semibold">Filtre</span>
            <a class="btn btn-sm btn-outline-secondary" href="/?page=billing_invoices">Nullstill</a>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2">
                <input type="hidden" name="page" value="billing_invoices">

                <div class="col-12 col-md-5">
                    <label class="form-label">Søk</label>
                    <input class="form-control" name="q" placeholder="ID, tittel eller kunde"
                           value="<?= h($q) ?>">
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="" <?= $statusF === '' ? 'selected' : '' ?>>Alle</option>
                        <?php foreach ($statusOptions as $k => $label): ?>
                            <option value="<?= h($k) ?>" <?= $statusF === $k ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label">Fra dato</label>
                    <input class="form-control" type="date" name="from" value="<?= h($dateFrom) ?>">
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label">Til dato</label>
                    <input class="form-control" type="date" name="to" value="<?= h($dateTo) ?>">
                </div>

                <div class="col-12 mt-2 d-flex gap-2">
                    <button class="btn btn-outline-primary">
                        <i class="bi bi-search me-1"></i> Filtrer
                    </button>
                    <a class="btn btn-outline-secondary" href="/?page=billing_invoices">Nullstill</a>
                </div>
            </form>
        </div>
        <div class="card-footer small text-muted">
            Viser maks 1000 rader per søk.
        </div>
    </div>

    <!-- Liste -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="fw-semibold">Fakturagrunnlag</span>
            <span class="text-muted small"><?= (int)$totalShown ?> vist</span>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Kunde/partner</th>
                            <th>Tittel</th>
                            <th>Status</th>
                            <th>Dato</th>
                            <th class="text-muted">Opprettet</th>
                            <th class="text-muted">Av</th>
                            <th class="text-end">Handling</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $id      = (int)$r['id'];
                            $status  = (string)($r['status'] ?? '');
                            $title   = (string)($r['title'] ?? '');
                            $accName = (string)($r['account_name'] ?? '—');
                            $accType = (string)($r['account_type'] ?? '');
                            $created = (string)($r['created_at'] ?? '');
                            $by      = (string)($r['created_by'] ?? '');
                            $issue   = (string)($r['issue_date'] ?? '');
                            $due     = (string)($r['due_date'] ?? '');

                            [$badgeCls, $badgeLbl] = statusBadge($status);
                            ?>
                            <tr>
                                <td>
                                    <a href="/?page=billing_invoice_edit&id=<?= $id ?>">
                                        #<?= $id ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="fw-semibold">
                                        <?= h($accName) ?>
                                        <?php if ($accType === 'partner'): ?>
                                            <span class="badge bg-info ms-1">Partner</span>
                                        <?php elseif ($accType === 'customer'): ?>
                                            <span class="badge bg-secondary ms-1">Kunde</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ((int)($r['account_active'] ?? 1) === 0): ?>
                                        <div class="text-muted small">Konto inaktiv</div>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($title !== '' ? $title : '—') ?></td>
                                <td>
                                    <span class="badge <?= h($badgeCls) ?>"><?= h($badgeLbl) ?></span>
                                    <?php if ($canWrite): ?>
                                        <div class="mt-2">
                                            <form method="post" class="d-flex gap-2 align-items-center">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <select class="form-select form-select-sm" name="status" style="max-width: 160px;">
                                                    <?php foreach ($statusOptions as $k => $label): ?>
                                                        <option value="<?= h($k) ?>" <?= ($k === $status) ? 'selected' : '' ?>>
                                                            <?= h($label) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn btn-sm btn-outline-primary" title="Lagre status">
                                                    <i class="bi bi-check2"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php if ($issue !== '' || $due !== ''): ?>
                                        <div>Utsted: <span class="text-muted"><?= h($issue !== '' ? $issue : '—') ?></span></div>
                                        <div>Forfall: <span class="text-muted"><?= h($due !== '' ? $due : '—') ?></span></div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= h($created !== '' ? $created : '—') ?></td>
                                <td class="small text-muted"><?= h($by !== '' ? $by : '—') ?></td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Handling">
                                        <a class="btn btn-outline-secondary"
                                           href="/?page=billing_invoice_edit&id=<?= $id ?>">
                                            <i class="bi bi-pencil"></i> Åpne
                                        </a>

                                        <a class="btn btn-outline-secondary"
                                           href="/?page=billing_invoice_print&id=<?= $id ?>"
                                           target="_blank"
                                           title="Åpne utskrift">
                                            <i class="bi bi-printer"></i> Print
                                        </a>

                                        <?php if ($isAdmin): ?>
                                        <button type="button"
                                                class="btn btn-outline-danger"
                                                title="Slett fakturagrunnlag"
                                                onclick="deleteDraft(<?= $id ?>, <?= h(json_encode($title !== '' ? $title : '#' . $id)) ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="8" class="text-muted p-3">Ingen treff.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer small text-muted">
            <?php if (!$canWrite): ?>
                Du har lesetilgang. For å endre status, gi brukeren rollen <code>invoice</code> eller <code>billing_write</code>.
            <?php else: ?>
                Statusendring lagres direkte på fakturagrunnlaget.
            <?php endif; ?>
            <?php if (!empty($_GET['debug'])): ?>
                <span class="ms-2">Debug: roles=<?= h(implode(',', $currentRoles)) ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- Skjult slett-skjema (brukes av deleteDraft()) -->
<form id="deleteForm" method="post" class="d-none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId" value="">
</form>

<script>
function deleteDraft(id, label) {
    if (!confirm('Slett fakturagrunnlag ' + label + '?\n\nDenne handlingen kan ikke angres.')) return;
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteForm').submit();
}
</script>
<?php endif; ?>
