<?php
// public/pages/billing_invoice_new.php
//
// Robust variant som ikke blir "blank" ved DB-feil.
// Debug: /?page=billing_invoice_new&debug=1

use App\Database;

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$errors  = [];
$success = null;

// ---------------------------------------------------------
// Rolle-guard (user_roles + session perms/roles) + bakoverkompatibel admin-fallback
// - Krev: admin eller invoice (faktura-rolle) eller billing_write (legacy)
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';
$username = trim((string)$username);

if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til fakturagrunnlag.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Guards/helpers (avoid redeclare)
// ---------------------------------------------------------
if (!function_exists('normalize_list')) {
    function normalize_list($v): array {
        if (is_array($v)) return array_values(array_filter(array_map('strval', $v)));
        if (is_string($v) && trim($v) !== '') {
            $parts = preg_split('/[,\s;]+/', $v);
            return array_values(array_filter(array_map('strval', $parts)));
        }
        return [];
    }
}
if (!function_exists('has_any')) {
    function has_any(array $needles, array $haystack): bool {
        $haystack = array_map('strtolower', $haystack);
        foreach ($needles as $n) {
            if (in_array(strtolower($n), $haystack, true)) return true;
        }
        return false;
    }
}

/**
 * Normaliser session-username til typisk users.username:
 * - "DOMENE\bruker" -> "bruker"
 * - "bruker@domene" -> "bruker"
 */
if (!function_exists('normalizeUsername')) {
    function normalizeUsername(string $u): string {
        $u = trim($u);
        if ($u === '') return '';

        if (strpos($u, '\\') !== false) {
            $parts = explode('\\', $u);
            $u = end($parts) ?: $u;
        }
        if (strpos($u, '@') !== false) {
            $u = explode('@', $u)[0] ?: $u;
        }
        return trim($u);
    }
}

// ---------------------------------------------------------
// DB (vi trenger den uansett). Ikke gi blank side.
// ---------------------------------------------------------
$pdo = null;
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    $pdo = null;
    $errors[] = 'Klarte ikke koble til databasen.';
    if (!empty($_GET['debug'])) {
        $errors[] = 'DB error: ' . $e->getMessage();
    }
}

// Admin-fallback (legacy)
$isAdmin = (bool)($_SESSION['is_admin'] ?? false);

// Hent roller/perms fra session først (kan være tomt)
$roles = normalize_list($_SESSION['roles'] ?? null);
$perms = normalize_list($_SESSION['permissions'] ?? null);

// Hent user_roles fra DB dersom vi kan
$currentUserId = 0;
if ($pdo) {
    try {
        $raw  = $username;
        $norm = normalizeUsername($raw);

        $st = $pdo->prepare("
            SELECT u.id
              FROM users u
             WHERE LOWER(u.username) = LOWER(:u)
             LIMIT 1
        ");

        $st->execute([':u' => $raw]);
        $currentUserId = (int)($st->fetchColumn() ?: 0);

        if ($currentUserId <= 0 && $norm !== '' && $norm !== $raw) {
            $st->execute([':u' => $norm]);
            $currentUserId = (int)($st->fetchColumn() ?: 0);
        }

        if ($currentUserId > 0) {
            $st2 = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
            $st2->execute([':uid' => $currentUserId]);
            $dbRoles = $st2->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $roles = array_merge($roles, normalize_list($dbRoles));
        }
    } catch (\Throwable $e) {
        if (!empty($_GET['debug'])) {
            $errors[] = 'Rolleoppslag feilet: ' . $e->getMessage();
        }
    }
}

// Normaliser roller/perms
$roles = array_values(array_unique(array_map('strtolower', $roles)));
$perms = array_values(array_unique(array_map('strtolower', $perms)));

// Admin via rolle også
if (!$isAdmin && has_any(['admin'], $roles)) {
    $isAdmin = true;
}

// ✅ Krav: admin eller faktura-tilgang
// - Ny standard: invoice
// - Legacy: billing_write / billing / faktura
$canInvoiceWrite = $isAdmin
    || has_any(['invoice','billing_write','billing','faktura','support'], $roles)
    || has_any(['invoice','billing_write','billing','faktura','support'], $perms);

if (!$canInvoiceWrite) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til fakturagrunnlag.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Handle POST: create draft
// ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$pdo) {
        $errors[] = 'Kan ikke opprette fakturagrunnlag uten DB-tilkobling.';
    } else {
        $accountId = (int)($_POST['account_id'] ?? 0);

        if ($accountId <= 0) {
            $errors[] = 'Velg kunde/partner.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, name, payment_terms_days FROM crm_accounts WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $accountId]);
                $acc = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$acc) {
                    $errors[] = 'Fant ikke kunde/partner.';
                } else {
                    $terms = (int)($acc['payment_terms_days'] ?? 14);
                    if ($terms < 0 || $terms > 365) $terms = 14;

                    $issue = (new DateTime())->format('Y-m-d');
                    $due   = (new DateTime())->modify('+' . $terms . ' day')->format('Y-m-d');

                    $ins = $pdo->prepare("
                        INSERT INTO billing_invoice_drafts
                            (account_id, status, title, currency, issue_date, due_date, created_by, created_at)
                        VALUES
                            (:account_id, 'draft', 'Fakturagrunnlag', 'NOK', :issue_date, :due_date, :created_by, NOW())
                    ");
                    $ins->execute([
                        ':account_id' => $accountId,
                        ':issue_date' => $issue,
                        ':due_date'   => $due,
                        ':created_by' => $username ?: null,
                    ]);

                    $newId = (int)$pdo->lastInsertId();
                    header('Location: /?page=billing_invoice_edit&id=' . $newId);
                    exit;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Databasefeil ved oppretting.';
                if (!empty($_GET['debug'])) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

// ---------------------------------------------------------
// Fetch accounts for dropdown
// ---------------------------------------------------------
$accounts = [];
if ($pdo) {
    try {
        $accounts = $pdo->query("
            SELECT id, name, type, is_active
            FROM crm_accounts
            ORDER BY is_active DESC, name ASC
            LIMIT 2000
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $errors[] = 'Databasefeil ved henting av kunder/partnere.';
        if (!empty($_GET['debug'])) {
            $errors[] = $e->getMessage();
        }
        $accounts = [];
    }
}

$preselect = (int)($_GET['account_id'] ?? 0);
?>

<div class="d-flex align-items-start justify-content-between mt-3">
    <div>
        <h3 class="mb-1">Nytt fakturagrunnlag</h3>
        <div class="text-muted">Velg kunde/partner og opprett et nytt draft.</div>
        <?php if (!$isAdmin): ?>
            <div class="text-muted small mt-1">Tilgang via rolle: <code>invoice</code> (evt. legacy <code>billing_write</code>).</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success mt-3"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger mt-3">
        <strong>Feil:</strong><br>
        <?= nl2br(h(implode("\n", $errors))) ?>
        <?php if (empty($_GET['debug'])): ?>
            <div class="mt-2 small">
                Tips: åpne med <code>&amp;debug=1</code> for mer detaljer.
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card mt-3">
    <div class="card-header">
        <strong>Opprett</strong>
    </div>

    <div class="card-body">
        <?php if (!$pdo): ?>
            <div class="text-muted">
                DB-tilkobling feilet – kan ikke opprette fakturagrunnlag.
            </div>
        <?php else: ?>
            <form method="post" class="row g-2">
                <div class="col-md-8">
                    <label class="form-label">Kunde/partner</label>
                    <select class="form-select" name="account_id" required>
                        <option value="">Velg...</option>
                        <?php foreach ($accounts as $a): ?>
                            <option
                                value="<?= (int)$a['id'] ?>"
                                <?= ($preselect > 0 && (int)$a['id'] === $preselect) ? 'selected' : '' ?>
                            >
                                <?= h($a['name']) ?>
                                <?= ($a['type'] ?? '') === 'partner' ? '(Partner)' : '(Kunde)' ?>
                                <?= ((int)($a['is_active'] ?? 1) === 0) ? ' [inaktiv]' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-muted small mt-1">
                        Forfallsdato settes automatisk basert på betalingsbetingelser fra kunderegisteret.
                    </div>
                </div>

                <div class="col-md-4 align-self-end">
                    <button class="btn btn-primary">
                        <i class="bi bi-plus-lg"></i> Opprett fakturagrunnlag
                    </button>
                    <a class="btn btn-outline-secondary" href="/?page=crm_accounts">
                        Avbryt
                    </a>
                </div>
            </form>

            <?php if (!empty($_GET['debug'])): ?>
                <hr>
                <div class="small text-muted">
                    Debug: kontoer lastet: <strong><?= (int)count($accounts) ?></strong>
                </div>
                <div class="small text-muted">
                    Debug: roller: <code><?= h(implode(', ', $roles)) ?></code>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
