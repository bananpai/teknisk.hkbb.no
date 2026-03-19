<?php
// public/pages/contracts_new.php
//
// Avtaler & kontrakter – Ny avtale (v4)
// - Lagrer kun metadata + lenke (ingen avtaletekst)
// - DATE-felter lagres som NULL hvis tom (ikke ''), for å unngå MySQL strict DATE-feil
// - Ansvarlig + stedfortreder kobles mot users-tabellen (dropdown med navn) + søkefelt (type-to-select)
// - Motpart kan velges fra crm_accounts (counterparty_account_id) eller fritekst fallback
// - Link til /?page=crm_accounts for å opprette/vedlikeholde motparter
// - "Varsle også" som drag & drop (to-liste picklist) + søk i begge lister
// - Lagres i contracts_notify_recipients hvis tabellen finnes (ellers ingen lagring av "varsle også")
//
// NB: Owner (ansvarlig) varsles alltid av varselmotoren (kommer senere). Her lagres kun ekstra mottakere + stedfortreder.

use App\Database;

// ---------------------------------------------------------
// Guard
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

$pdo = Database::getConnection();

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
if (!function_exists('contracts_table_exists')) {
    function contracts_table_exists(PDO $pdo, string $table): bool {
        try {
            $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
if (!function_exists('contracts_column_exists')) {
    function contracts_column_exists(PDO $pdo, string $table, string $col): bool {
        try {
            $stmt = $pdo->query("DESCRIBE `$table`");
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($rows as $r) {
                if ((string)($r['Field'] ?? '') === $col) return true;
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('val_str')) {
    function val_str($v): string { return trim((string)$v); }
}
if (!function_exists('val_nullable_str')) {
    function val_nullable_str($v): ?string {
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }
}
if (!function_exists('val_nullable_int')) {
    function val_nullable_int($v): ?int {
        if ($v === null) return null;
        if (is_string($v) && trim($v) === '') return null;
        $i = (int)$v;
        return $i > 0 ? $i : null;
    }
}
if (!function_exists('val_nullable_date')) {
    function val_nullable_date($v): ?string {
        $s = trim((string)$v);
        if ($s === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
        [$y,$m,$d] = array_map('intval', explode('-', $s));
        if (!checkdate($m, $d, $y)) return null;
        return $s;
    }
}
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }
}
if (!function_exists('csrf_check')) {
    function csrf_check(): bool {
        $t = (string)($_POST['csrf_token'] ?? '');
        return $t !== '' && !empty($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $t);
    }
}

if (!function_exists('render_user_options')) {
    function render_user_options(array $users, ?int $selectedId): void {
        echo '<option value="">— Velg —</option>';
        foreach ($users as $u) {
            $id = (int)($u['id'] ?? 0);
            if ($id <= 0) continue;
            $name  = (string)($u['display_name'] ?? ($u['username'] ?? ''));
            $uname = (string)($u['username'] ?? '');
            $label = $name;
            if ($uname !== '' && $uname !== $name) $label .= ' (' . $uname . ')';
            $sel = ($selectedId !== null && $id === $selectedId) ? 'selected' : '';
            echo '<option value="' . $id . '" ' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
    }
}

if (!function_exists('render_account_options')) {
    function render_account_options(array $accounts, ?int $selectedId): void {
        echo '<option value="">— Velg fra Kunder &amp; partnere —</option>';
        foreach ($accounts as $a) {
            $id = (int)($a['id'] ?? 0);
            if ($id <= 0) continue;

            $name = (string)($a['name'] ?? '');
            $type = (string)($a['type'] ?? '');
            $org  = (string)($a['org_no'] ?? '');
            $ref  = (string)($a['reference'] ?? '');

            $suffix = [];
            if ($type !== '') $suffix[] = $type;
            if ($org !== '')  $suffix[] = $org;
            elseif ($ref !== '') $suffix[] = $ref;

            $label = $name;
            if (!empty($suffix)) $label .= ' — ' . implode(' · ', $suffix);

            $sel = ($selectedId !== null && $id === $selectedId) ? 'selected' : '';
            echo '<option value="' . $id . '" ' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
    }
}

// ---------------------------------------------------------
// Tables / schema
// ---------------------------------------------------------
$hasContracts   = contracts_table_exists($pdo, 'contracts');
$hasUsers       = contracts_table_exists($pdo, 'users');
$hasCrmAccounts = contracts_table_exists($pdo, 'crm_accounts');

// Optional recipients table (for multiple notify users)
$hasRecipients  = contracts_table_exists($pdo, 'contracts_notify_recipients');

// Ensure new column exists in contracts (defensive)
$hasCounterpartyAccountCol = $hasContracts ? contracts_column_exists($pdo, 'contracts', 'counterparty_account_id') : false;

// ---------------------------------------------------------
// Load users + crm accounts for dropdowns
// ---------------------------------------------------------
$users = [];
$accounts = [];
$currentUserId = null;

if ($hasUsers) {
    try {
        $stmt = $pdo->query("
            SELECT id, username,
                   COALESCE(NULLIF(display_name,''), username) AS display_name,
                   is_active
              FROM users
             WHERE is_active = 1
             ORDER BY display_name
        ");
        $users = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $currentUserId = (int)($stmt->fetchColumn() ?: 0);
        if ($currentUserId === 0) $currentUserId = null;
    } catch (\Throwable $e) {
        $users = [];
        $currentUserId = null;
    }
}

if ($hasCrmAccounts) {
    try {
        $stmt = $pdo->query("
            SELECT id, type, name, org_no, reference, is_active
              FROM crm_accounts
             WHERE is_active = 1
             ORDER BY name
        ");
        $accounts = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (\Throwable $e) {
        $accounts = [];
    }
}

// ---------------------------------------------------------
// Form defaults
// ---------------------------------------------------------
$form = [
    'title'            => '',
    'contract_no'      => '',
    'status'           => 'Aktiv',
    'party_type'       => '',
    'contract_type'    => '',

    // Motpart: enten velg fra CRM, eller fritekst fallback
    'counterparty_account_id' => '',
    'counterparty'            => '',
    'counterparty_ref'        => '',

    'start_date'       => '',
    'end_date'         => '',
    'renewal_date'     => '',
    'kpi_adjust_date'  => '',
    'kpi_basis'        => '',
    'kpi_note'         => '',

    'link_url'         => '',
    'link_label'       => '',

    // owner/substitute linked to users.id
    'owner_user_id'       => $currentUserId ? (string)$currentUserId : '',
    'substitute_user_id'  => '',

    // multiple notify recipients (user ids)
    'notify_user_ids'     => [],

    'notes'            => '',
    'is_active'        => '1',
];

// ---------------------------------------------------------
// POST handler
// ---------------------------------------------------------
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$hasContracts) $errors[] = 'Tabellen contracts finnes ikke i databasen ennå.';
    if (!$hasUsers) $errors[] = 'Tabellen users finnes ikke (kan ikke velge ansvarlig/stedfortreder).';

    if (!csrf_check()) $errors[] = 'Ugyldig skjema-token. Last siden på nytt og prøv igjen.';

    // Hydrate
    foreach ($form as $k => $v) {
        if ($k === 'notify_user_ids') continue;
        if (isset($_POST[$k])) {
            $form[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : $v;
        }
    }

    $notifyIds = $_POST['notify_user_ids'] ?? [];
    if (!is_array($notifyIds)) $notifyIds = [];
    $notifyIds = array_values(array_unique(array_filter(array_map('intval', $notifyIds), fn($x) => $x > 0)));
    $form['notify_user_ids'] = $notifyIds;

    // Validate basics
    if (val_str($form['title']) === '') $errors[] = 'Tittel må fylles ut.';

    // Motpart: hvis CRM valgt -> ok. Ellers fritekst må fylles
    $cpAccountId = val_nullable_int($form['counterparty_account_id']);
    if ($cpAccountId === null) {
        if (val_str($form['counterparty']) === '') {
            $errors[] = 'Velg motpart fra Kunder & partnere, eller skriv inn motpart manuelt.';
        }
    } else {
        if (!$hasCrmAccounts) {
            $errors[] = 'crm_accounts finnes ikke – kan ikke velge motpart fra database.';
        }
        if (!$hasCounterpartyAccountCol) {
            $errors[] = 'Kolonnen contracts.counterparty_account_id finnes ikke. Kjør ALTER TABLE.';
        }
    }

    // URL validation (optional)
    if (val_str($form['link_url']) !== '' && !filter_var($form['link_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Lenke må være en gyldig URL (inkl. https://).';
    }

    // Validate dates
    $startDate = val_nullable_date($form['start_date']);
    $endDate   = val_nullable_date($form['end_date']);
    $renewDate = val_nullable_date($form['renewal_date']);
    $kpiDate   = val_nullable_date($form['kpi_adjust_date']);

    if ($form['start_date'] !== '' && $startDate === null) $errors[] = 'Startdato er ugyldig (bruk YYYY-MM-DD).';
    if ($form['end_date'] !== '' && $endDate === null) $errors[] = 'Sluttdato er ugyldig (bruk YYYY-MM-DD).';
    if ($form['renewal_date'] !== '' && $renewDate === null) $errors[] = 'Fornyelsesdato er ugyldig (bruk YYYY-MM-DD).';
    if ($form['kpi_adjust_date'] !== '' && $kpiDate === null) $errors[] = 'KPI-dato er ugyldig (bruk YYYY-MM-DD).';

    // Owner/Substitute
    $ownerId = (int)($form['owner_user_id'] ?? 0);
    $subId   = (int)($form['substitute_user_id'] ?? 0);

    if ($ownerId <= 0) $errors[] = 'Du må velge en ansvarlig.';
    if ($subId > 0 && $subId === $ownerId) $errors[] = 'Stedfortreder kan ikke være samme som ansvarlig.';

    // Validate chosen users exist
    if ($hasUsers && ($ownerId > 0 || $subId > 0 || !empty($notifyIds))) {
        try {
            $all = array_values(array_unique(array_filter(array_merge([$ownerId, $subId], $notifyIds), fn($x) => $x > 0)));
            if (!empty($all)) {
                $in = implode(',', array_fill(0, count($all), '?'));
                $stmt = $pdo->prepare("SELECT id FROM users WHERE is_active=1 AND id IN ($in)");
                $stmt->execute($all);
                $ok = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $ok = array_map('intval', $ok);
                foreach ($all as $id) {
                    if (!in_array((int)$id, $ok, true)) {
                        $errors[] = 'Valgt bruker (id ' . (int)$id . ') finnes ikke eller er ikke aktiv.';
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'Kunne ikke validere brukervalg: ' . $e->getMessage();
        }
    }

    // If CRM account selected, fetch account to snapshot name/ref
    $cpName = null;
    $cpRef  = null;
    if ($cpAccountId !== null && $hasCrmAccounts) {
        try {
            $stmt = $pdo->prepare("SELECT name, org_no, reference FROM crm_accounts WHERE id=:id AND is_active=1 LIMIT 1");
            $stmt->execute([':id' => $cpAccountId]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$acc) {
                $errors[] = 'Valgt motpart finnes ikke eller er ikke aktiv.';
            } else {
                $cpName = (string)($acc['name'] ?? '');
                $org = (string)($acc['org_no'] ?? '');
                $ref = (string)($acc['reference'] ?? '');
                $cpRef = $org !== '' ? $org : ($ref !== '' ? $ref : null);
            }
        } catch (\Throwable $e) {
            $errors[] = 'Kunne ikke hente motpart fra CRM: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lookup usernames from ids (contracts table uses owner_username currently)
            $lookupUsername = function(int $uid) use ($pdo): ?string {
                if ($uid <= 0) return null;
                $st = $pdo->prepare("SELECT username FROM users WHERE id=:id LIMIT 1");
                $st->execute([':id' => $uid]);
                $u = (string)($st->fetchColumn() ?: '');
                return $u !== '' ? $u : null;
            };

            $ownerUsername = $lookupUsername($ownerId);
            $subUsername   = $lookupUsername($subId);

            if (!$ownerUsername) {
                throw new \RuntimeException('Fant ikke username for ansvarlig.');
            }

            // Determine counterparty fields
            $finalCounterparty = $cpName !== null ? $cpName : val_str($form['counterparty']);
            $finalCounterRef   = $cpRef !== null ? $cpRef : val_nullable_str($form['counterparty_ref']);

            // Insert contract
            if ($hasCounterpartyAccountCol) {
                $stmt = $pdo->prepare("
                    INSERT INTO contracts
                        (title, contract_no, status, party_type, contract_type,
                         counterparty, counterparty_account_id, counterparty_ref,
                         start_date, end_date, renewal_date, kpi_adjust_date,
                         kpi_basis, kpi_note,
                         link_url, link_label,
                         owner_username, created_by_username, updated_by_username,
                         notes, is_active)
                    VALUES
                        (:title, :contract_no, :status, :party_type, :contract_type,
                         :counterparty, :counterparty_account_id, :counterparty_ref,
                         :start_date, :end_date, :renewal_date, :kpi_adjust_date,
                         :kpi_basis, :kpi_note,
                         :link_url, :link_label,
                         :owner_username, :created_by_username, :updated_by_username,
                         :notes, :is_active)
                ");
                $stmt->execute([
                    ':title'                    => val_str($form['title']),
                    ':contract_no'              => val_nullable_str($form['contract_no']),
                    ':status'                   => val_nullable_str($form['status']),
                    ':party_type'               => val_nullable_str($form['party_type']),
                    ':contract_type'            => val_nullable_str($form['contract_type']),
                    ':counterparty'             => $finalCounterparty,
                    ':counterparty_account_id'  => $cpAccountId,
                    ':counterparty_ref'         => $finalCounterRef,
                    ':start_date'               => $startDate,
                    ':end_date'                 => $endDate,
                    ':renewal_date'             => $renewDate,
                    ':kpi_adjust_date'          => $kpiDate,
                    ':kpi_basis'                => val_nullable_str($form['kpi_basis']),
                    ':kpi_note'                 => val_nullable_str($form['kpi_note']),
                    ':link_url'                 => val_nullable_str($form['link_url']),
                    ':link_label'               => val_nullable_str($form['link_label']),
                    ':owner_username'           => $ownerUsername,
                    ':created_by_username'      => $username,
                    ':updated_by_username'      => $username,
                    ':notes'                    => val_nullable_str($form['notes']),
                    ':is_active'                => (int)($form['is_active'] === '1' ? 1 : 0),
                ]);
            } else {
                // Fallback (no column): keep original schema
                $stmt = $pdo->prepare("
                    INSERT INTO contracts
                        (title, contract_no, status, party_type, contract_type,
                         counterparty, counterparty_ref,
                         start_date, end_date, renewal_date, kpi_adjust_date,
                         kpi_basis, kpi_note,
                         link_url, link_label,
                         owner_username, created_by_username, updated_by_username,
                         notes, is_active)
                    VALUES
                        (:title, :contract_no, :status, :party_type, :contract_type,
                         :counterparty, :counterparty_ref,
                         :start_date, :end_date, :renewal_date, :kpi_adjust_date,
                         :kpi_basis, :kpi_note,
                         :link_url, :link_label,
                         :owner_username, :created_by_username, :updated_by_username,
                         :notes, :is_active)
                ");
                $stmt->execute([
                    ':title'               => val_str($form['title']),
                    ':contract_no'         => val_nullable_str($form['contract_no']),
                    ':status'              => val_nullable_str($form['status']),
                    ':party_type'          => val_nullable_str($form['party_type']),
                    ':contract_type'       => val_nullable_str($form['contract_type']),
                    ':counterparty'        => $finalCounterparty,
                    ':counterparty_ref'    => $finalCounterRef,
                    ':start_date'          => $startDate,
                    ':end_date'            => $endDate,
                    ':renewal_date'        => $renewDate,
                    ':kpi_adjust_date'     => $kpiDate,
                    ':kpi_basis'           => val_nullable_str($form['kpi_basis']),
                    ':kpi_note'            => val_nullable_str($form['kpi_note']),
                    ':link_url'            => val_nullable_str($form['link_url']),
                    ':link_label'          => val_nullable_str($form['link_label']),
                    ':owner_username'      => $ownerUsername,
                    ':created_by_username' => $username,
                    ':updated_by_username' => $username,
                    ':notes'               => val_nullable_str($form['notes']),
                    ':is_active'           => (int)($form['is_active'] === '1' ? 1 : 0),
                ]);
            }

            $contractId = (int)$pdo->lastInsertId();

            // Save recipients (optional)
            if ($hasRecipients) {
                $insertRec = $pdo->prepare("
                    INSERT INTO contracts_notify_recipients
                        (contract_id, user_id, recipient_role, is_active, created_at)
                    VALUES
                        (:cid, :uid, :role, 1, NOW())
                    ON DUPLICATE KEY UPDATE
                        recipient_role = VALUES(recipient_role),
                        is_active = 1
                ");

                if ($subId > 0) {
                    $insertRec->execute([':cid' => $contractId, ':uid' => $subId, ':role' => 'substitute']);
                }
                foreach ($notifyIds as $uid) {
                    if ($uid === $ownerId || $uid === $subId) continue;
                    $insertRec->execute([':cid' => $contractId, ':uid' => (int)$uid, ':role' => 'notify']);
                }
            } else {
                // Don't lose substitute: append to notes (optional)
                if ($subUsername) {
                    $append = "\nStedfortreder: " . $subUsername;
                    $pdo->prepare("UPDATE contracts SET notes = CONCAT(COALESCE(notes,''), :a) WHERE id=:id")
                        ->execute([':a' => $append, ':id' => $contractId]);
                }
            }

            $pdo->commit();

            header('Location: /?page=contracts&created=' . urlencode((string)$contractId));
            exit;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Kunne ikke lagre avtalen: ' . $e->getMessage();
        }
    }
}

// Dropdown options
$partyTypes = ['Kunde', 'Leverandør', 'Partner', 'Intern', 'Annet'];
$statuses   = ['Aktiv', 'Pågår', 'Utløpt', 'Terminert', 'Arkivert'];
$kpiBases   = ['KPI', 'CPI', 'Konsumprisindeks', 'Annet'];

// Build user label map for picklist (server-side)
$userLabelById = [];
foreach ($users as $u) {
    $id = (int)($u['id'] ?? 0);
    if ($id <= 0) continue;
    $name  = (string)($u['display_name'] ?? ($u['username'] ?? ''));
    $uname = (string)($u['username'] ?? '');
    $label = $name;
    if ($uname !== '' && $uname !== $name) $label .= ' (' . $uname . ')';
    $userLabelById[$id] = $label;
}
$selectedNotify = array_values(array_unique(array_filter(array_map('intval', $form['notify_user_ids'] ?? []), fn($x) => $x > 0)));

$ownerIdInit = (int)($form['owner_user_id'] ?? 0);
$subIdInit   = (int)($form['substitute_user_id'] ?? 0);

// Available list = all active users minus notify-selected minus owner/sub (for cleanliness)
$availableUserIds = [];
foreach ($userLabelById as $uid => $_lbl) {
    if ($uid === $ownerIdInit || $uid === $subIdInit) continue;
    if (in_array($uid, $selectedNotify, true)) continue;
    $availableUserIds[] = $uid;
}
sort($availableUserIds);
?>
<style>
.section-title{
    display:flex; align-items:center; gap:.5rem;
    font-weight:600; margin:.25rem 0 .35rem 0;
}
.section-sub{ color: #6c757d; font-size:.875rem; }
.hr-tight{ margin-top:.35rem; }

.pickbox{
    border:1px solid rgba(0,0,0,.125);
    border-radius:.5rem;
    background:#fff;
}
.pickbox-header{
    padding:.6rem .75rem;
    border-bottom:1px solid rgba(0,0,0,.08);
    background:rgba(0,0,0,.02);
    border-top-left-radius:.5rem;
    border-top-right-radius:.5rem;
}
.picklist{
    list-style:none;
    margin:0;
    padding:.5rem;
    min-height: 240px;
    max-height: 360px;
    overflow:auto;
}
.pickitem{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:.75rem;
    padding:.45rem .6rem;
    border:1px solid rgba(0,0,0,.08);
    border-radius:.5rem;
    background:#fff;
    margin-bottom:.5rem;
    cursor:grab;
    user-select:none;
}
.pickitem:active{ cursor:grabbing; }
.pickitem.dragging{ opacity:.6; }
.pickitem .meta{
    font-size:.75rem;
    color:#6c757d;
}
.pickdrop-hint{
    font-size:.8rem;
    color:#6c757d;
}
.pickbox-footer{
    padding:.5rem .75rem;
    border-top:1px solid rgba(0,0,0,.08);
    background:rgba(0,0,0,.02);
    border-bottom-left-radius:.5rem;
    border-bottom-right-radius:.5rem;
}
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h5 mb-1"><i class="bi bi-plus-circle me-1"></i> Ny avtale</h1>
        <div class="text-muted small">
            Registrer metadata og lenke til dokumentet. Selve avtalen lagres eksternt.
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="/?page=contracts" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Til oversikt
        </a>
    </div>
</div>

<?php if (!$hasContracts): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Tabellen <code>contracts</code> finnes ikke i databasen ennå. Kjør databasescriptet først.
    </div>
<?php endif; ?>

<?php if (!$hasUsers): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Tabellen <code>users</code> finnes ikke eller kan ikke leses. Ansvarlig/stedfortreder kan ikke velges.
    </div>
<?php elseif (empty($users)): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Fant ingen <strong>aktive</strong> brukere i <code>users</code> (is_active=1). Da blir listene tomme.
        <div class="small text-muted mt-1">
            Dette styres ikke av roller – kun av at brukere finnes i <code>users</code> og er aktive.
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1"><i class="bi bi-bug me-1"></i> Skjemaet inneholder feil</div>
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="/?page=contracts_new" class="card shadow-sm">
    <div class="card-body">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <!-- =================== 1) Grunninfo =================== -->
        <div class="section-title"><i class="bi bi-card-text"></i> Grunninformasjon</div>
        <div class="section-sub">Tittel, status og type</div>
        <hr class="hr-tight">

        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-6">
                <label class="form-label"><i class="bi bi-type me-1"></i> Tittel <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control"
                       value="<?= htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="F.eks. Rammeavtale – Leverandør X" required>
            </div>

            <div class="col-12 col-lg-3">
                <label class="form-label"><i class="bi bi-hash me-1"></i> Avtalenr</label>
                <input type="text" name="contract_no" class="form-control"
                       value="<?= htmlspecialchars($form['contract_no'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Ref/nummer (valgfritt)">
            </div>

            <div class="col-12 col-lg-3">
                <label class="form-label"><i class="bi bi-flag me-1"></i> Status</label>
                <select name="status" class="form-select">
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>" <?= $form['status'] === $s ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-lg-3">
                <label class="form-label"><i class="bi bi-people me-1"></i> Motpart-type</label>
                <select name="party_type" class="form-select">
                    <option value="">— Velg —</option>
                    <?php foreach ($partyTypes as $pt): ?>
                        <option value="<?= htmlspecialchars($pt, ENT_QUOTES, 'UTF-8') ?>" <?= $form['party_type'] === $pt ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pt, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-lg-3">
                <label class="form-label"><i class="bi bi-diagram-3 me-1"></i> Avtaletype</label>
                <input type="text" name="contract_type" class="form-control"
                       value="<?= htmlspecialchars($form['contract_type'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="F.eks. SLA, Rammeavtale (valgfritt)">
            </div>
        </div>

        <!-- =================== 2) Motpart =================== -->
        <div class="section-title"><i class="bi bi-building"></i> Motpart</div>
        <div class="section-sub">
            Velg fra <strong>Kunder &amp; partnere</strong> eller fyll inn manuelt.
            <a class="ms-2" href="/?page=crm_accounts">
                <i class="bi bi-box-arrow-up-right"></i> Åpne Kunder &amp; partnere
            </a>
        </div>
        <hr class="hr-tight">

        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-7">
                <label class="form-label"><i class="bi bi-list-check me-1"></i> Velg motpart fra database</label>
                <select name="counterparty_account_id" id="counterparty_account_id" class="form-select" <?= $hasCrmAccounts ? '' : 'disabled' ?>>
                    <?php render_account_options($accounts, ($form['counterparty_account_id'] !== '' ? (int)$form['counterparty_account_id'] : null)); ?>
                </select>
                <div class="form-text">
                    Hvis du velger her, brukes navn/ref automatisk. Hvis ikke: fyll inn manuelt under.
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <div class="alert alert-light border mb-0">
                    <div class="small text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Mangler motpart i listen? Legg den inn under
                        <a href="/?page=crm_accounts">Kunder &amp; partnere</a>.
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <label class="form-label"><i class="bi bi-pencil me-1"></i> Motpart (manuelt)</label>
                <input type="text" name="counterparty" id="counterparty" class="form-control"
                       value="<?= htmlspecialchars($form['counterparty'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Skriv inn hvis ikke valgt fra database">
            </div>

            <div class="col-12 col-lg-6">
                <label class="form-label"><i class="bi bi-credit-card-2-front me-1"></i> Motpart ref (manuelt)</label>
                <input type="text" name="counterparty_ref" id="counterparty_ref" class="form-control"
                       value="<?= htmlspecialchars($form['counterparty_ref'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Orgnr/kundenr (valgfritt)">
            </div>
        </div>

        <!-- =================== 3) Tidslinje =================== -->
        <div class="section-title"><i class="bi bi-calendar-event"></i> Tidslinje</div>
        <div class="section-sub">Datoer som brukes til varsling</div>
        <hr class="hr-tight">

        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-3">
                <label class="form-label"><i class="bi bi-play-circle me-1"></i> Startdato</label>
                <input type="date" name="start_date" class="form-control"
                       value="<?= htmlspecialchars($form['start_date'], ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-12 col-lg-3">
                <label class="form-label"><i class="bi bi-stop-circle me-1"></i> Sluttdato</label>
                <input type="date" name="end_date" class="form-control"
                       value="<?= htmlspecialchars($form['end_date'], ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-12 col-lg-3">
                <label class="form-label"><i class="bi bi-arrow-repeat me-1"></i> Fornyelsesdato</label>
                <input type="date" name="renewal_date" class="form-control"
                       value="<?= htmlspecialchars($form['renewal_date'], ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-12 col-lg-3">
                <label class="form-label"><i class="bi bi-graph-up-arrow me-1"></i> KPI/indeksdato</label>
                <input type="date" name="kpi_adjust_date" class="form-control"
                       value="<?= htmlspecialchars($form['kpi_adjust_date'], ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-12 col-lg-3">
                <label class="form-label"><i class="bi bi-sliders me-1"></i> KPI/indeksbasis</label>
                <select name="kpi_basis" class="form-select">
                    <option value="">— Velg —</option>
                    <?php foreach ($kpiBases as $kb): ?>
                        <option value="<?= htmlspecialchars($kb, ENT_QUOTES, 'UTF-8') ?>" <?= $form['kpi_basis'] === $kb ? 'selected' : '' ?>>
                            <?= htmlspecialchars($kb, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-lg-9">
                <label class="form-label"><i class="bi bi-chat-left-text me-1"></i> KPI/indeksnotat</label>
                <input type="text" name="kpi_note" class="form-control"
                       value="<?= htmlspecialchars($form['kpi_note'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="F.eks. indeksregulering årlig pr 1. jan (valgfritt)">
            </div>
        </div>

        <!-- =================== 4) Lenke =================== -->
        <div class="section-title"><i class="bi bi-link-45deg"></i> Lenke</div>
        <div class="section-sub">Hvor avtalen ligger lagret</div>
        <hr class="hr-tight">

        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-8">
                <label class="form-label"><i class="bi bi-box-arrow-up-right me-1"></i> Lenke til avtale</label>
                <input type="url" name="link_url" class="form-control"
                       value="<?= htmlspecialchars($form['link_url'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="https://... (Teams/SharePoint/filområde)">
            </div>

            <div class="col-12 col-lg-4">
                <label class="form-label"><i class="bi bi-tag me-1"></i> Lenketekst</label>
                <input type="text" name="link_label" class="form-control"
                       value="<?= htmlspecialchars($form['link_label'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="F.eks. Åpne i Teams (valgfritt)">
            </div>
        </div>

        <!-- =================== 5) Roller & varsling =================== -->
        <div class="section-title"><i class="bi bi-person-badge"></i> Roller &amp; varsling</div>
        <div class="section-sub">Ansvarlig, stedfortreder og øvrige mottakere</div>
        <hr class="hr-tight">

        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-6">
                <label class="form-label"><i class="bi bi-person-check me-1"></i> Ansvarlig <span class="text-danger">*</span></label>

                <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="owner_search" placeholder="Søk i listen (skriv for å hoppe til) …" autocomplete="off">
                </div>

                <select name="owner_user_id" id="owner_user_id" class="form-select" <?= $hasUsers ? '' : 'disabled' ?>>
                    <?php render_user_options($users, ($form['owner_user_id'] !== '' ? (int)$form['owner_user_id'] : null)); ?>
                </select>
                <div class="form-text">Denne personen “eier” avtalen og har primær oppfølging.</div>
            </div>

            <div class="col-12 col-lg-6">
                <label class="form-label"><i class="bi bi-person-gear me-1"></i> Stedfortreder</label>

                <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="sub_search" placeholder="Søk i listen (skriv for å hoppe til) …" autocomplete="off">
                </div>

                <select name="substitute_user_id" id="substitute_user_id" class="form-select" <?= $hasUsers ? '' : 'disabled' ?>>
                    <?php render_user_options($users, ($form['substitute_user_id'] !== '' ? (int)$form['substitute_user_id'] : null)); ?>
                </select>
                <div class="form-text">Brukes ved fravær. Kun én stedfortreder per avtale.</div>
            </div>

            <div class="col-12">
                <label class="form-label"><i class="bi bi-bell me-1"></i> Varsle også</label>
                <div class="text-muted small mb-2">
                    Dra brukere fra venstre til høyre (eller dobbeltklikk) for å legge til mottakere.
                    <span class="ms-1 pickdrop-hint">Tips: Søkefeltene filtrerer listene.</span>
                    <?php if (!$hasRecipients): ?>
                        <span class="text-warning ms-2">(listen lagres ikke før contracts_notify_recipients finnes)</span>
                    <?php endif; ?>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <div class="pickbox">
                            <div class="pickbox-header d-flex align-items-center justify-content-between gap-2">
                                <div class="fw-semibold"><i class="bi bi-people me-1"></i> Tilgjengelige</div>
                                <div class="input-group input-group-sm" style="max-width: 320px;">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" id="notifySearchAvailable" placeholder="Søk brukere…" autocomplete="off">
                                </div>
                            </div>
                            <ul id="notifyAvailable" class="picklist" aria-label="Tilgjengelige brukere">
                                <?php foreach ($availableUserIds as $uid): ?>
                                    <li class="pickitem" draggable="true" data-user-id="<?= (int)$uid ?>">
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($userLabelById[$uid] ?? ('Bruker #' . $uid), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="meta">Dra til “Varsles”</div>
                                        </div>
                                        <span class="badge text-bg-light border"><i class="bi bi-plus"></i></span>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (empty($availableUserIds)): ?>
                                    <li class="text-muted small px-2 py-2">Ingen tilgjengelige brukere å vise.</li>
                                <?php endif; ?>
                            </ul>
                            <div class="pickbox-footer small text-muted">
                                Dobbeltklikk på en bruker for å flytte.
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <div class="pickbox">
                            <div class="pickbox-header d-flex align-items-center justify-content-between gap-2">
                                <div class="fw-semibold"><i class="bi bi-bell me-1"></i> Varsles</div>
                                <div class="input-group input-group-sm" style="max-width: 320px;">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" id="notifySearchSelected" placeholder="Søk i varsles…" autocomplete="off">
                                </div>
                            </div>

                            <ul id="notifySelected" class="picklist" aria-label="Valgte mottakere">
                                <?php
                                foreach ($selectedNotify as $uid):
                                    if ($uid === $ownerIdInit || $uid === $subIdInit) continue;
                                    if (!isset($userLabelById[$uid])) continue;
                                ?>
                                    <li class="pickitem" draggable="true" data-user-id="<?= (int)$uid ?>">
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($userLabelById[$uid], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="meta">Dra tilbake for å fjerne</div>
                                        </div>
                                        <span class="badge text-bg-light border"><i class="bi bi-x"></i></span>
                                    </li>
                                <?php endforeach; ?>

                                <?php if (empty($selectedNotify)): ?>
                                    <li class="text-muted small px-2 py-2">Ingen ekstra mottakere valgt.</li>
                                <?php endif; ?>
                            </ul>

                            <div class="pickbox-footer small text-muted">
                                Disse mottar varsler i tillegg til ansvarlig (og stedfortreder).
                            </div>
                        </div>

                        <!-- Hidden inputs populated by JS -->
                        <div id="notifyHidden"></div>
                    </div>
                </div>

                <div class="small text-muted mt-2">
                    Ansvarlig varsles alltid. Stedfortreder brukes ved fravær (lagres som “substitute” hvis tabellen finnes).
                </div>
            </div>
        </div>

        <!-- =================== 6) Notat =================== -->
        <div class="section-title"><i class="bi bi-journal-text"></i> Interne notater</div>
        <div class="section-sub">Til oppfølging (ikke avtaletekst)</div>
        <hr class="hr-tight">

        <div class="row g-3">
            <div class="col-12">
                <label class="form-label"><i class="bi bi-pencil-square me-1"></i> Notat</label>
                <textarea name="notes" class="form-control" rows="4"
                          placeholder="Interne notater, oppfølging, nøkkelpunkt (ikke avtaletekst)"><?= htmlspecialchars($form['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="col-12 d-flex justify-content-between align-items-center mt-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active" <?= $form['is_active'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">
                        <i class="bi bi-check-circle me-1"></i> Aktiv
                    </label>
                </div>

                <div class="d-flex gap-2">
                    <a href="/?page=contracts" class="btn btn-outline-secondary">
                        Avbryt
                    </a>
                    <button type="submit" class="btn btn-primary" <?= ($hasContracts && $hasUsers) ? '' : 'disabled' ?>>
                        <i class="bi bi-check2-circle me-1"></i> Lagre avtale
                    </button>
                </div>
            </div>
        </div>

    </div>
</form>

<script>
// UX: Hvis du velger motpart fra CRM, deaktiver manuelle felter og tøm dem visuelt.
document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('counterparty_account_id');
    var manualName = document.getElementById('counterparty');
    var manualRef  = document.getElementById('counterparty_ref');

    if (sel && manualName && manualRef) {
        function sync() {
            var hasPick = (sel.value || '').trim() !== '';
            manualName.disabled = hasPick;
            manualRef.disabled  = hasPick;

            if (hasPick) {
                manualName.classList.add('bg-light');
                manualRef.classList.add('bg-light');
            } else {
                manualName.classList.remove('bg-light');
                manualRef.classList.remove('bg-light');
            }
        }
        sel.addEventListener('change', sync);
        sync();
    }

    // -----------------------------
    // "Type-to-select" for dropdowns
    // -----------------------------
    function wireTypeSelect(inputId, selectId) {
        var inp = document.getElementById(inputId);
        var sel = document.getElementById(selectId);
        if (!inp || !sel) return;

        function pickFirstMatch(q) {
            q = (q || '').trim().toLowerCase();
            if (!q) return;

            var opts = sel.options;
            for (var i = 0; i < opts.length; i++) {
                var t = (opts[i].text || '').toLowerCase();
                if (t.indexOf(q) !== -1) {
                    sel.selectedIndex = i;
                    sel.dispatchEvent(new Event('change'));
                    return;
                }
            }
        }

        var last = '';
        inp.addEventListener('input', function () {
            var q = inp.value || '';
            if (q === last) return;
            last = q;
            pickFirstMatch(q);
        });
    }
    wireTypeSelect('owner_search', 'owner_user_id');
    wireTypeSelect('sub_search', 'substitute_user_id');

    // -----------------------------
    // Drag & drop picklist for notify users
    // -----------------------------
    var ulAvail = document.getElementById('notifyAvailable');
    var ulSel   = document.getElementById('notifySelected');
    var hidden  = document.getElementById('notifyHidden');

    function isPlaceholderLi(li) {
        return li && li.tagName === 'LI' && li.className.indexOf('pickitem') === -1;
    }

    function ensureEmptyPlaceholder(ul, text) {
        // if no pickitems -> add placeholder li (non-draggable)
        var items = ul.querySelectorAll('li.pickitem');
        var existing = ul.querySelector('li[data-placeholder="1"]');
        if (items.length === 0) {
            if (!existing) {
                var pli = document.createElement('li');
                pli.dataset.placeholder = '1';
                pli.className = 'text-muted small px-2 py-2';
                pli.textContent = text;
                ul.appendChild(pli);
            } else {
                existing.textContent = text;
            }
        } else {
            if (existing) existing.remove();
        }
    }

    function rebuildHiddenInputs() {
        if (!hidden) return;
        hidden.innerHTML = '';

        if (!ulSel) return;
        var items = ulSel.querySelectorAll('li.pickitem');
        items.forEach(function (li) {
            var uid = parseInt(li.dataset.userId || '0', 10);
            if (!uid) return;

            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'notify_user_ids[]';
            inp.value = String(uid);
            hidden.appendChild(inp);
        });

        ensureEmptyPlaceholder(ulSel, 'Ingen ekstra mottakere valgt.');
        ensureEmptyPlaceholder(ulAvail, 'Ingen tilgjengelige brukere å vise.');
    }

    function moveItem(li, targetUl) {
        if (!li || !targetUl) return;
        // remove placeholder if present
        var ph = targetUl.querySelector('li[data-placeholder="1"]');
        if (ph) ph.remove();

        targetUl.appendChild(li);
        rebuildHiddenInputs();
    }

    function attachPickItem(li) {
        if (!li || li.className.indexOf('pickitem') === -1) return;

        li.addEventListener('dragstart', function (e) {
            li.classList.add('dragging');
            e.dataTransfer.setData('text/plain', li.dataset.userId || '');
            e.dataTransfer.setData('source', li.parentElement && li.parentElement.id ? li.parentElement.id : '');
            e.dataTransfer.effectAllowed = 'move';
        });

        li.addEventListener('dragend', function () {
            li.classList.remove('dragging');
        });

        // Double click toggles between lists
        li.addEventListener('dblclick', function () {
            var parentId = li.parentElement ? li.parentElement.id : '';
            if (parentId === 'notifyAvailable') {
                moveItem(li, ulSel);
            } else {
                moveItem(li, ulAvail);
            }
        });
    }

    function wireDropZone(ul) {
        if (!ul) return;
        ul.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        });

        ul.addEventListener('drop', function (e) {
            e.preventDefault();
            var uid = parseInt(e.dataTransfer.getData('text/plain') || '0', 10);
            if (!uid) return;

            // find the dragged li anywhere
            var li = document.querySelector('li.pickitem[data-user-id="' + uid + '"]');
            if (!li) return;

            // If dropping into same list, ignore
            var from = li.parentElement ? li.parentElement.id : '';
            if (from === ul.id) return;

            moveItem(li, ul);
        });
    }

    function wireSearch(inputId, ulId) {
        var inp = document.getElementById(inputId);
        var ul  = document.getElementById(ulId);
        if (!inp || !ul) return;

        inp.addEventListener('input', function () {
            var q = (inp.value || '').trim().toLowerCase();
            var items = ul.querySelectorAll('li.pickitem');
            var visibleCount = 0;

            items.forEach(function (li) {
                var t = (li.textContent || '').toLowerCase();
                var show = !q || t.indexOf(q) !== -1;
                li.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            // Placeholder logic shouldn't fight filtering; only show placeholder when list has no items at all
            // (not when all are filtered out)
            if (items.length === 0) {
                ensureEmptyPlaceholder(ul, ul.id === 'notifySelected' ? 'Ingen ekstra mottakere valgt.' : 'Ingen tilgjengelige brukere å vise.');
            } else {
                // remove placeholder if items exist
                var ph = ul.querySelector('li[data-placeholder="1"]');
                if (ph) ph.remove();

                if (visibleCount === 0) {
                    // show a temporary "no match" row
                    var nm = ul.querySelector('li[data-nomatch="1"]');
                    if (!nm) {
                        nm = document.createElement('li');
                        nm.dataset.nomatch = '1';
                        nm.className = 'text-muted small px-2 py-2';
                        nm.textContent = 'Ingen treff.';
                        ul.appendChild(nm);
                    }
                } else {
                    var nm2 = ul.querySelector('li[data-nomatch="1"]');
                    if (nm2) nm2.remove();
                }
            }
        });
    }

    // Init picklist
    if (ulAvail && ulSel) {
        ulAvail.querySelectorAll('li.pickitem').forEach(attachPickItem);
        ulSel.querySelectorAll('li.pickitem').forEach(attachPickItem);
        wireDropZone(ulAvail);
        wireDropZone(ulSel);
        wireSearch('notifySearchAvailable', 'notifyAvailable');
        wireSearch('notifySearchSelected', 'notifySelected');
        rebuildHiddenInputs();
    }

    // When owner/sub changes: ensure they are not in notify-selected list
    function removeFromSelectedIf(uid) {
        if (!uid || !ulSel) return;
        var li = ulSel.querySelector('li.pickitem[data-user-id="' + uid + '"]');
        if (li && ulAvail) moveItem(li, ulAvail);
    }
    var ownerSel = document.getElementById('owner_user_id');
    var subSel   = document.getElementById('substitute_user_id');

    function onRolesChanged() {
        var ownerId = ownerSel ? parseInt(ownerSel.value || '0', 10) : 0;
        var subId   = subSel ? parseInt(subSel.value || '0', 10) : 0;
        removeFromSelectedIf(ownerId);
        removeFromSelectedIf(subId);
    }

    if (ownerSel) ownerSel.addEventListener('change', onRolesChanged);
    if (subSel) subSel.addEventListener('change', onRolesChanged);
});
</script>

<?php if (!$hasRecipients): ?>
    <div class="alert alert-info mt-3">
        <div class="fw-semibold"><i class="bi bi-info-circle me-1"></i> For å lagre “Varsle også”-listen</div>
        <div class="small text-muted">
            Legg inn denne tabellen (kan kjøres nå uten å påvirke annet):
        </div>
        <pre class="mb-0"><code>CREATE TABLE IF NOT EXISTS contracts_notify_recipients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contract_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  recipient_role ENUM('substitute','notify') NOT NULL DEFAULT 'notify',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contract_user (contract_id, user_id),
  KEY idx_contract_recip_contract (contract_id),
  KEY idx_contract_recip_user (user_id),
  CONSTRAINT fk_contract_recip_contract
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_recip_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</code></pre>
    </div>
<?php endif; ?>
