<?php
// public/pages/contracts_edit.php
//
// Avtaler & kontrakter – Rediger avtale (MVP+)
// - Oppdaterer metadata på contracts
// - Støtter revisjon: revised_at + next_revision_date + revision_note (hvis kolonner finnes)
// - Skriver endringslogg per felt i contracts_change_log (hvis finnes)
// - Oppdaterer updated_at/updated_by_username
// - Valgfritt: skriver også til contracts_activity_log (hvis tabellen finnes)

use App\Database;

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
if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('contracts_table_exists')) {
    function contracts_table_exists(PDO $pdo, string $table): bool {
        try { $pdo->query("SELECT 1 FROM $table LIMIT 1"); return true; }
        catch (\Throwable $e) { return false; }
    }
}
if (!function_exists('contracts_column_exists')) {
    function contracts_column_exists(PDO $pdo, string $table, string $col): bool {
        try {
            $stmt = $pdo->query("DESCRIBE $table");
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($rows as $r) if ((string)($r['Field'] ?? '') === $col) return true;
            return false;
        } catch (\Throwable $e) { return false; }
    }
}
if (!function_exists('norm_date_nullable')) {
    function norm_date_nullable($s) {
        $s = trim((string)($s ?? ''));
        return $s === '' ? null : $s; // forventer YYYY-MM-DD
    }
}
if (!function_exists('norm_str_nullable')) {
    function norm_str_nullable($s) {
        $s = trim((string)($s ?? ''));
        return $s === '' ? null : $s;
    }
}
if (!function_exists('as_int01')) {
    function as_int01($v): int { return ((string)$v === '1' || $v === 1 || $v === true) ? 1 : 0; }
}

// ---------------------------------------------------------
// Input
// ---------------------------------------------------------
$contractId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($contractId <= 0) {
    ?>
    <div class="alert alert-warning mt-3">Ugyldig avtale-ID.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Schema checks
// ---------------------------------------------------------
$hasContracts = contracts_table_exists($pdo, 'contracts');
if (!$hasContracts) {
    ?>
    <div class="alert alert-danger mt-3">Tabellen <code>contracts</code> finnes ikke.</div>
    <?php
    return;
}

$hasUsers       = contracts_table_exists($pdo, 'users');
$hasCrmAccounts = contracts_table_exists($pdo, 'crm_accounts');

$hasCounterpartyAccountCol = contracts_column_exists($pdo, 'contracts', 'counterparty_account_id');
$hasIsActiveCol            = contracts_column_exists($pdo, 'contracts', 'is_active');
$hasUpdatedAtCol           = contracts_column_exists($pdo, 'contracts', 'updated_at');
$hasUpdatedByCol           = contracts_column_exists($pdo, 'contracts', 'updated_by_username');

$hasRevisedAtCol           = contracts_column_exists($pdo, 'contracts', 'revised_at');
$hasNextRevisionCol        = contracts_column_exists($pdo, 'contracts', 'next_revision_date');
$hasRevisionNoteCol        = contracts_column_exists($pdo, 'contracts', 'revision_note');

$hasChangeLog              = contracts_table_exists($pdo, 'contracts_change_log');
$hasActivityLog            = contracts_table_exists($pdo, 'contracts_activity_log');
$hasRecipients             = contracts_table_exists($pdo, 'contracts_notify_recipients');
$hasNotifySet              = contracts_table_exists($pdo, 'contracts_notify_settings');

// ---------------------------------------------------------
// Load contract
// ---------------------------------------------------------
try {
    $st = $pdo->prepare("SELECT * FROM contracts WHERE id = :id LIMIT 1");
    $st->execute([':id' => $contractId]);
    $contract = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) {
    $contract = null;
}
if (!$contract) {
    ?>
    <div class="alert alert-warning mt-3">Fant ikke avtalen.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Load dropdown data
// ---------------------------------------------------------
$users = [];
$usersById = []; // id => {id, username, display_name}
if ($hasUsers) {
    try {
        $st = $pdo->query("SELECT id, username, COALESCE(NULLIF(display_name,''), username) AS name FROM users WHERE is_active = 1 ORDER BY name");
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $r) {
            $users[] = $r;
            $usersById[(int)$r['id']] = $r;
        }
    } catch (\Throwable $e) { $users = []; }
}

// Load existing recipients for this contract
$existingSubId     = 0;
$existingNotifyIds = [];
if ($hasRecipients) {
    try {
        $st = $pdo->prepare("SELECT user_id, recipient_role FROM contracts_notify_recipients WHERE contract_id = :cid AND is_active = 1");
        $st->execute([':cid' => $contractId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid  = (int)$r['user_id'];
            $role = (string)$r['recipient_role'];
            if ($role === 'substitute') {
                $existingSubId = $uid;
            } elseif ($role === 'notify') {
                $existingNotifyIds[] = $uid;
            }
        }
    } catch (\Throwable $e) { /* ignore */ }
}

// Load existing notify settings
$existingNotifySettings = null;
if ($hasNotifySet) {
    try {
        $st = $pdo->prepare("SELECT enabled, days_before_end, days_before_renewal, days_before_kpi FROM contracts_notify_settings WHERE contract_id = :cid LIMIT 1");
        $st->execute([':cid' => $contractId]);
        $existingNotifySettings = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { /* ignore */ }
}

$crmAccounts = [];
if ($hasCrmAccounts) {
    try {
        $st = $pdo->query("SELECT id, name, org_no FROM crm_accounts ORDER BY name");
        $crmAccounts = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (\Throwable $e) { $crmAccounts = []; }
}

// ---------------------------------------------------------
// CSRF (enkel MVP)
// ---------------------------------------------------------
if (empty($_SESSION['csrf_contracts_edit'])) {
    $_SESSION['csrf_contracts_edit'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_contracts_edit'];

$errors = [];
$success = false;

// ---------------------------------------------------------
// Handle POST
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
        $errors[] = "Ugyldig token. Last siden på nytt og prøv igjen.";
    } else {
        // Hent inn felter
        $new = [];

        $new['title']          = trim((string)($_POST['title'] ?? ''));
        $new['contract_no']    = trim((string)($_POST['contract_no'] ?? ''));
        $new['status']         = trim((string)($_POST['status'] ?? ''));
        $new['contract_type']  = trim((string)($_POST['contract_type'] ?? ''));
        $new['party_type']     = trim((string)($_POST['party_type'] ?? ''));

        $new['owner_username'] = trim((string)($_POST['owner_username'] ?? ''));
        $new['counterparty']   = trim((string)($_POST['counterparty'] ?? ''));
        $new['counterparty_ref'] = trim((string)($_POST['counterparty_ref'] ?? ''));

        if ($hasCounterpartyAccountCol) {
            $new['counterparty_account_id'] = (int)($_POST['counterparty_account_id'] ?? 0);
            if ($new['counterparty_account_id'] <= 0) $new['counterparty_account_id'] = null;
        }

        if ($hasIsActiveCol) {
            $new['is_active'] = as_int01($_POST['is_active'] ?? 1);
        }

        $new['start_date']    = norm_date_nullable($_POST['start_date'] ?? null);
        $new['end_date']      = norm_date_nullable($_POST['end_date'] ?? null);
        $new['renewal_date']  = norm_date_nullable($_POST['renewal_date'] ?? null);
        $new['kpi_adjust_date'] = norm_date_nullable($_POST['kpi_adjust_date'] ?? null);

        $new['kpi_basis']     = trim((string)($_POST['kpi_basis'] ?? ''));
        $new['kpi_note']      = trim((string)($_POST['kpi_note'] ?? ''));
        $new['notes']         = (string)($_POST['notes'] ?? '');

        $new['link_url']      = trim((string)($_POST['link_url'] ?? ''));
        $new['link_label']    = trim((string)($_POST['link_label'] ?? ''));

        if ($hasRevisedAtCol) {
            $new['revised_at'] = norm_date_nullable($_POST['revised_at'] ?? null);
        }
        if ($hasNextRevisionCol) {
            $new['next_revision_date'] = norm_date_nullable($_POST['next_revision_date'] ?? null);
        }
        if ($hasRevisionNoteCol) {
            $new['revision_note'] = (string)($_POST['revision_note'] ?? '');
        }

        $logNote = trim((string)($_POST['change_note'] ?? ''));

        // Enkel validering
        if ($new['title'] === '') $errors[] = "Tittel kan ikke være tom.";

        // Hvis ok: lagre
        if (empty($errors)) {
            // Finn endringer
            $changes = []; // [field => [old, new]]
            foreach ($new as $field => $val) {
                $oldVal = $contract[$field] ?? null;

                // normaliser for sammenligning
                $oldCmp = is_string($oldVal) ? trim($oldVal) : $oldVal;
                $newCmp = is_string($val) ? trim($val) : $val;

                // MySQL kan gi "0"/0 vs null, håndter litt:
                if ($oldCmp === '') $oldCmp = null;
                if ($newCmp === '') $newCmp = null;

                if ($oldCmp != $newCmp) {
                    $changes[$field] = [$oldVal, $val];
                }
            }

            // --- DEL 1: oppdater kontraktfelt (kun hvis noe faktisk endret) ---
            if (!empty($changes) || $logNote !== '') {
                try {
                    $pdo->beginTransaction();

                    $sets   = [];
                    $params = [':id' => $contractId];

                    foreach ($new as $field => $val) {
                        if (!contracts_column_exists($pdo, 'contracts', $field)) continue;
                        $sets[] = "$field = :$field";
                        $params[":$field"] = $val;
                    }

                    if ($hasUpdatedAtCol) {
                        $sets[] = "updated_at = NOW()";
                    }
                    if ($hasUpdatedByCol) {
                        $sets[] = "updated_by_username = :updated_by_username";
                        $params[':updated_by_username'] = $username;
                    }

                    if (!empty($sets)) {
                        $st = $pdo->prepare("UPDATE contracts SET " . implode(", ", $sets) . " WHERE id = :id LIMIT 1");
                        $st->execute($params);
                    }

                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

                    if ($hasChangeLog) {
                        $stLog = $pdo->prepare("
                            INSERT INTO contracts_change_log
                              (contract_id, username, action, field_name, old_value, new_value, note, ip, created_at)
                            VALUES (:cid, :u, :a, :f, :ov, :nv, :note, :ip, NOW())
                        ");
                        if (!empty($changes)) {
                            foreach ($changes as $field => [$ov, $nv]) {
                                $stLog->execute([
                                    ':cid'  => $contractId, ':u' => $username, ':a' => 'update',
                                    ':f'    => $field,
                                    ':ov'   => is_null($ov) ? null : (string)$ov,
                                    ':nv'   => is_null($nv) ? null : (string)$nv,
                                    ':note' => $logNote !== '' ? $logNote : null,
                                    ':ip'   => $ip,
                                ]);
                            }
                        } else {
                            $stLog->execute([
                                ':cid' => $contractId, ':u' => $username, ':a' => 'note',
                                ':f' => null, ':ov' => null, ':nv' => null,
                                ':note' => $logNote !== '' ? $logNote : null, ':ip' => $ip,
                            ]);
                        }
                    }

                    if ($hasActivityLog) {
                        $act = "Oppdatert avtale" . (!empty($changes) ? ' (' . count($changes) . ' felt)' : '');
                        $pdo->prepare("
                            INSERT INTO contracts_activity_log (contract_id, action, username, message, ip, created_at)
                            VALUES (:cid, :action, :u, :msg, :ip, NOW())
                        ")->execute([
                            ':cid'    => $contractId, ':action' => 'update', ':u' => $username,
                            ':msg'    => $logNote !== '' ? mb_substr($logNote, 0, 1000) : $act,
                            ':ip'     => $ip,
                        ]);
                    }

                    $pdo->commit();
                    $success = true;
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = "Kunne ikke lagre avtalefelter: " . h($e->getMessage());
                }
            }

            // --- DEL 2: mottakere og varslingsinnstillinger (alltid, uavhengig av del 1) ---
            if (empty($errors)) {
                try {
                    // Mottakere
                    if ($hasRecipients) {
                        $newSubId     = (int)($_POST['substitute_user_id'] ?? 0);
                        $newNotifyRaw = (array)($_POST['notify_user_ids'] ?? []);
                        $newNotifyIds = array_values(array_unique(array_filter(
                            array_map('intval', $newNotifyRaw),
                            fn($x) => $x > 0
                        )));

                        // Finn owner user_id for å ekskludere fra notify
                        $ownerUid = 0;
                        if (($new['owner_username'] ?? '') !== '') {
                            $stOu = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
                            $stOu->execute([':u' => $new['owner_username']]);
                            $ownerUid = (int)($stOu->fetchColumn() ?: 0);
                        }

                        $pdo->prepare("DELETE FROM contracts_notify_recipients WHERE contract_id = :cid")
                            ->execute([':cid' => $contractId]);

                        if ($newSubId > 0 && $newSubId !== $ownerUid) {
                            $pdo->prepare("INSERT INTO contracts_notify_recipients (contract_id, user_id, recipient_role, is_active, created_at) VALUES (:cid, :uid, 'substitute', 1, NOW())")
                                ->execute([':cid' => $contractId, ':uid' => $newSubId]);
                        }
                        foreach ($newNotifyIds as $nuid) {
                            if ($nuid === $ownerUid || $nuid === $newSubId) continue;
                            $pdo->prepare("INSERT INTO contracts_notify_recipients (contract_id, user_id, recipient_role, is_active, created_at) VALUES (:cid, :uid, 'notify', 1, NOW())")
                                ->execute([':cid' => $contractId, ':uid' => (int)$nuid]);
                        }

                        $existingSubId     = $newSubId;
                        $existingNotifyIds = $newNotifyIds;
                        $success = true;
                    }

                    // Varslingsinnstillinger
                    if ($hasNotifySet) {
                        $nsEnabled   = (int)(($_POST['ns_enabled'] ?? '1') === '1');
                        $nsDaysEnd   = max(0, (int)($_POST['ns_days_before_end']     ?? 30));
                        $nsDaysRenew = max(0, (int)($_POST['ns_days_before_renewal'] ?? 30));
                        $nsDaysKpi   = max(0, (int)($_POST['ns_days_before_kpi']     ?? 30));

                        $pdo->prepare("
                            INSERT INTO contracts_notify_settings
                                (contract_id, enabled, days_before_end, days_before_renewal, days_before_kpi, updated_at)
                            VALUES (:cid, :en, :de, :dr, :dk, NOW())
                            ON DUPLICATE KEY UPDATE
                                enabled = VALUES(enabled),
                                days_before_end = VALUES(days_before_end),
                                days_before_renewal = VALUES(days_before_renewal),
                                days_before_kpi = VALUES(days_before_kpi),
                                updated_at = NOW()
                        ")->execute([
                            ':cid' => $contractId, ':en' => $nsEnabled,
                            ':de' => $nsDaysEnd, ':dr' => $nsDaysRenew, ':dk' => $nsDaysKpi,
                        ]);

                        $existingNotifySettings = [
                            'enabled'             => $nsEnabled,
                            'days_before_end'     => $nsDaysEnd,
                            'days_before_renewal' => $nsDaysRenew,
                            'days_before_kpi'     => $nsDaysKpi,
                        ];
                        $success = true;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Kunne ikke lagre mottakere/innstillinger: " . h($e->getMessage());
                }
            }

            // Hvis ingenting ble lagret og ingen feil: ingen endringer
            if (!$success && empty($errors)) {
                $errors[] = "Ingen endringer å lagre.";
            }

            if ($success && empty($errors)) {
                // reload contract
                $st = $pdo->prepare("SELECT * FROM contracts WHERE id = :id LIMIT 1");
                $st->execute([':id' => $contractId]);
                $contract = $st->fetch(PDO::FETCH_ASSOC) ?: $contract;

                // ny csrf
                $_SESSION['csrf_contracts_edit'] = bin2hex(random_bytes(16));
                $csrf = (string)$_SESSION['csrf_contracts_edit'];
            }
        }
    }
}

// ---------------------------------------------------------
// Values for form
// ---------------------------------------------------------
function v($arr, $key, $fallback = '') {
    return isset($arr[$key]) ? (string)$arr[$key] : (string)$fallback;
}
?>
<style>
.form-hint{ color:#6c757d; font-size:.875rem; }
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h1 class="h5 mb-1"><i class="bi bi-pencil-square me-1"></i> Rediger avtale</h1>
    <div class="text-muted small">
      Avtale-ID: <code><?= (int)$contractId ?></code>
      <?php if (!empty($contract['contract_no'])): ?> · Avtalenr: <code><?= h($contract['contract_no']) ?></code><?php endif; ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/?page=contracts_view&id=<?= (int)$contractId ?>">
      <i class="bi bi-arrow-left me-1"></i> Tilbake
    </a>
    <a class="btn btn-sm btn-outline-secondary" href="/?page=contracts">
      <i class="bi bi-list me-1"></i> Oversikt
    </a>
  </div>
</div>

<?php if ($success): ?>
  <div class="alert alert-success">Endringer lagret.</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <div class="fw-semibold mb-1">Kunne ikke lagre:</div>
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if (!$hasChangeLog): ?>
  <div class="alert alert-warning">
    <div class="fw-semibold">Endringslogg er ikke aktiv</div>
    <div class="small text-muted">Opprett tabellen <code>contracts_change_log</code> for å få felt-for-felt historikk.</div>
  </div>
<?php endif; ?>

<form method="post" class="card shadow-sm">
  <div class="card-body">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

    <div class="row g-3">
      <div class="col-12 col-lg-8">
        <div class="mb-2 fw-semibold"><i class="bi bi-info-circle me-1"></i> Grunninfo</div>

        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Tittel *</label>
            <input class="form-control" name="title" value="<?= h(v($contract,'title')) ?>" required>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Avtalenr</label>
            <input class="form-control" name="contract_no" value="<?= h(v($contract,'contract_no')) ?>">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Status</label>
            <input class="form-control" name="status" value="<?= h(v($contract,'status')) ?>" placeholder="Aktiv / Utløpt / Terminert ...">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Aktiv</label>
            <?php if ($hasIsActiveCol): ?>
              <select class="form-select" name="is_active">
                <option value="1" <?= ((int)($contract['is_active'] ?? 1)===1?'selected':'') ?>>Ja</option>
                <option value="0" <?= ((int)($contract['is_active'] ?? 1)===0?'selected':'') ?>>Nei</option>
              </select>
            <?php else: ?>
              <div class="form-hint">Kolonnen <code>is_active</code> finnes ikke.</div>
            <?php endif; ?>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Avtaletype</label>
            <input class="form-control" name="contract_type" value="<?= h(v($contract,'contract_type')) ?>">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Motpart-type</label>
            <input class="form-control" name="party_type" value="<?= h(v($contract,'party_type')) ?>">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Ansvarlig</label>
            <?php if ($hasUsers && !empty($users)): ?>
              <select class="form-select" name="owner_username">
                <option value="">–</option>
                <?php foreach ($users as $u): ?>
                  <?php $un=(string)$u['username']; $nm=(string)$u['name']; ?>
                  <option value="<?= h($un) ?>" <?= ($un===v($contract,'owner_username')?'selected':'') ?>>
                    <?= h($nm) ?> (<?= h($un) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input class="form-control" name="owner_username" value="<?= h(v($contract,'owner_username')) ?>" placeholder="username">
              <div class="form-hint">users-tabellen mangler eller er tom — fritekst brukes.</div>
            <?php endif; ?>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Motpart (fritekst)</label>
            <input class="form-control" name="counterparty" value="<?= h(v($contract,'counterparty')) ?>">
            <div class="form-hint">Brukes som fallback / hvis dere ikke velger CRM-motpart.</div>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Motpart ref (org.nr / ref)</label>
            <input class="form-control" name="counterparty_ref" value="<?= h(v($contract,'counterparty_ref')) ?>">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">CRM-motpart</label>
            <?php if ($hasCounterpartyAccountCol && $hasCrmAccounts && !empty($crmAccounts)): ?>
              <select class="form-select" name="counterparty_account_id">
                <option value="0">– Ingen valgt</option>
                <?php $cur = (int)($contract['counterparty_account_id'] ?? 0); ?>
                <?php foreach ($crmAccounts as $a): ?>
                  <?php $id=(int)$a['id']; $nm=(string)$a['name']; $org=(string)($a['org_no'] ?? ''); ?>
                  <option value="<?= $id ?>" <?= ($id===$cur?'selected':'') ?>>
                    <?= h($nm) ?><?= $org!=='' ? ' · '.$org : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-hint">Hvis valgt: view-siden kan hente navn/orgnr fra CRM.</div>
            <?php else: ?>
              <div class="form-hint">CRM-kobling ikke tilgjengelig (mangler kolonne/tabell).</div>
            <?php endif; ?>
          </div>
        </div>

        <hr class="my-4">

        <div class="mb-2 fw-semibold"><i class="bi bi-calendar-event me-1"></i> Datoer</div>
        <div class="row g-2">
          <div class="col-12 col-md-3">
            <label class="form-label">Startdato</label>
            <input type="date" class="form-control" name="start_date" value="<?= h(v($contract,'start_date')) ?>">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Sluttdato</label>
            <input type="date" class="form-control" name="end_date" value="<?= h(v($contract,'end_date')) ?>">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Fornyelsesdato</label>
            <input type="date" class="form-control" name="renewal_date" value="<?= h(v($contract,'renewal_date')) ?>">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">KPI/indeksdato</label>
            <input type="date" class="form-control" name="kpi_adjust_date" value="<?= h(v($contract,'kpi_adjust_date')) ?>">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">KPI/indeksbasis</label>
            <input class="form-control" name="kpi_basis" value="<?= h(v($contract,'kpi_basis')) ?>">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">KPI/indeksnotat</label>
            <input class="form-control" name="kpi_note" value="<?= h(v($contract,'kpi_note')) ?>">
          </div>
        </div>

        <hr class="my-4">

        <div class="mb-2 fw-semibold"><i class="bi bi-journal-check me-1"></i> Revisjon</div>
        <?php if (!$hasRevisedAtCol && !$hasNextRevisionCol && !$hasRevisionNoteCol): ?>
          <div class="text-muted small">
            Revisjonsfelter finnes ikke i <code>contracts</code>. (Legg til: <code>revised_at</code>, <code>next_revision_date</code>, <code>revision_note</code>)
          </div>
        <?php else: ?>
          <div class="row g-2">
            <?php if ($hasRevisedAtCol): ?>
              <div class="col-12 col-md-4">
                <label class="form-label">Sist revidert</label>
                <input type="date" class="form-control" name="revised_at" value="<?= h(v($contract,'revised_at')) ?>">
              </div>
            <?php endif; ?>
            <?php if ($hasNextRevisionCol): ?>
              <div class="col-12 col-md-4">
                <label class="form-label">Neste revisjon</label>
                <input type="date" class="form-control" name="next_revision_date" value="<?= h(v($contract,'next_revision_date')) ?>">
              </div>
            <?php endif; ?>
          </div>
          <?php if ($hasRevisionNoteCol): ?>
            <div class="mt-2">
              <label class="form-label">Revisjonsnotat</label>
              <textarea class="form-control" name="revision_note" rows="3"><?= h(v($contract,'revision_note')) ?></textarea>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <hr class="my-4">

        <div class="mb-2 fw-semibold"><i class="bi bi-journal-text me-1"></i> Interne notater</div>
        <textarea class="form-control" name="notes" rows="6"><?= h(v($contract,'notes')) ?></textarea>

        <hr class="my-4">

        <!-- ===== Varsling: ansvarlig & mottakere ===== -->
        <div class="mb-2 fw-semibold"><i class="bi bi-bell me-1"></i> Varsling – mottakere</div>
        <div class="text-muted small mb-3">
            Ansvarlig varsles alltid. Legg til stedfortreder og ekstra mottakere her.
            <?php if (!$hasRecipients): ?>
                <span class="text-warning">
                    (<a href="/?page=contracts_alerts">contracts_notify_recipients</a> finnes ikke ennå – opprett tabellen for å lagre mottakere)
                </span>
            <?php endif; ?>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6">
            <label class="form-label">Stedfortreder</label>
            <select class="form-select" name="substitute_user_id" id="edit_sub_user" <?= $hasRecipients && $hasUsers ? '' : 'disabled' ?>>
              <option value="0">– Ingen –</option>
              <?php foreach ($users as $u):
                $uid = (int)$u['id'];
                $nm  = (string)$u['name'];
                $un  = (string)$u['username'];
              ?>
                <option value="<?= $uid ?>" <?= ($existingSubId === $uid) ? 'selected' : '' ?>>
                  <?= h($nm) ?> (<?= h($un) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Brukes ved fravær – varsles i stedet for / i tillegg til ansvarlig.</div>
          </div>
        </div>

        <label class="form-label">Varsle også</label>
        <div class="row g-2 mb-3">
          <!-- Available -->
          <div class="col-12 col-md-6">
            <div class="border rounded">
              <div class="px-2 py-1 border-bottom bg-light d-flex justify-content-between align-items-center">
                <span class="small fw-semibold">Tilgjengelige</span>
                <input type="text" class="form-control form-control-sm" id="editSearchAvail"
                       placeholder="Søk…" style="max-width:160px;" autocomplete="off">
              </div>
              <ul id="editAvailList" class="list-unstyled m-0 p-2"
                  style="min-height:140px;max-height:220px;overflow-y:auto;">
                <?php foreach ($users as $u):
                  $uid = (int)$u['id'];
                  $nm  = (string)$u['name'];
                  $un  = (string)$u['username'];
                  $hidden = in_array($uid, $existingNotifyIds, true) ? 'style="display:none;"' : '';
                ?>
                  <li class="d-flex align-items-center justify-content-between py-1 px-2 mb-1 border rounded small"
                      data-user-id="<?= $uid ?>"
                      data-label="<?= h($nm . ' ' . $un) ?>"
                      <?= $hidden ?>>
                    <span><?= h($nm) ?> <span class="text-muted">(<?= h($un) ?>)</span></span>
                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-1 edit-btn-add" title="Legg til" <?= $hasRecipients ? '' : 'disabled' ?>>
                      <i class="bi bi-plus"></i>
                    </button>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>

          <!-- Selected -->
          <div class="col-12 col-md-6">
            <div class="border rounded">
              <div class="px-2 py-1 border-bottom bg-light d-flex justify-content-between align-items-center">
                <span class="small fw-semibold"><i class="bi bi-bell me-1"></i> Varsles</span>
                <span class="badge text-bg-secondary" id="editSelectedCount"><?= count($existingNotifyIds) ?></span>
              </div>
              <ul id="editSelectedList" class="list-unstyled m-0 p-2"
                  style="min-height:140px;max-height:220px;overflow-y:auto;">
                <?php if (empty($existingNotifyIds)): ?>
                  <li class="text-muted small p-2" id="editNoSelected">Ingen ekstra mottakere valgt.</li>
                <?php else: ?>
                  <?php foreach ($existingNotifyIds as $nuid):
                    $nu = $usersById[$nuid] ?? null;
                    $nm = $nu ? (string)$nu['name'] : 'Bruker #' . $nuid;
                    $un = $nu ? (string)$nu['username'] : '';
                  ?>
                    <li class="d-flex align-items-center justify-content-between py-1 px-2 mb-1 border rounded small edit-sel-item"
                        data-user-id="<?= $nuid ?>">
                      <span><?= h($nm) ?><?= $un !== '' ? ' <span class="text-muted">(' . h($un) . ')</span>' : '' ?></span>
                      <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 edit-btn-remove" title="Fjern" <?= $hasRecipients ? '' : 'disabled' ?>>
                        <i class="bi bi-x"></i>
                      </button>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>
              </ul>
            </div>
            <div id="editNotifyHidden">
              <?php foreach ($existingNotifyIds as $nuid): ?>
                <input type="hidden" name="notify_user_ids[]" value="<?= (int)$nuid ?>">
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="small text-muted">
          Ansvarlig varsles alltid. Stedfortreder brukes ved fravær.
        </div>
      </div>

      <div class="col-12 col-lg-4">
        <div class="mb-2 fw-semibold"><i class="bi bi-link-45deg me-1"></i> Lenke</div>
        <div class="mb-2">
          <label class="form-label">URL</label>
          <input class="form-control" name="link_url" value="<?= h(v($contract,'link_url')) ?>" placeholder="https://...">
        </div>
        <div class="mb-2">
          <label class="form-label">Lenketekst</label>
          <input class="form-control" name="link_label" value="<?= h(v($contract,'link_label')) ?>" placeholder="Åpne avtale">
        </div>

        <hr class="my-4">

        <div class="mb-2 fw-semibold"><i class="bi bi-card-text me-1"></i> Endringsnotat</div>
        <textarea class="form-control" name="change_note" rows="4" placeholder="Hva ble endret og hvorfor? (valgfritt)"></textarea>
        <div class="form-hint mt-1">
          Notatet lagres sammen med endringsloggen (hvis aktiv).
        </div>

        <hr class="my-4">

        <!-- ===== Varslingsinnstillinger ===== -->
        <div class="mb-2 fw-semibold"><i class="bi bi-sliders me-1"></i> Varslingsinnstillinger</div>
        <?php if (!$hasNotifySet): ?>
          <div class="text-muted small">
            Tabellen <code>contracts_notify_settings</code> finnes ikke ennå.
            <a href="/?page=contracts_alerts">Se SQL for å opprette den.</a>
          </div>
        <?php else: ?>
          <?php
            $nsEnabled   = $existingNotifySettings ? (int)$existingNotifySettings['enabled']            : 1;
            $nsDaysEnd   = $existingNotifySettings ? (int)$existingNotifySettings['days_before_end']    : 30;
            $nsDaysRenew = $existingNotifySettings ? (int)$existingNotifySettings['days_before_renewal'] : 30;
            $nsDaysKpi   = $existingNotifySettings ? (int)$existingNotifySettings['days_before_kpi']    : 30;
          ?>
          <div class="mb-2">
            <label class="form-label">Varsling</label>
            <select class="form-select form-select-sm" name="ns_enabled">
              <option value="1" <?= $nsEnabled === 1 ? 'selected' : '' ?>>Aktivert</option>
              <option value="0" <?= $nsEnabled === 0 ? 'selected' : '' ?>>Deaktivert</option>
            </select>
          </div>
          <div class="row g-2">
            <div class="col-4">
              <label class="form-label">Dager før utløp</label>
              <input type="number" class="form-control form-control-sm" name="ns_days_before_end"
                     min="0" max="365" value="<?= $nsDaysEnd ?>">
            </div>
            <div class="col-4">
              <label class="form-label">Dager før fornyelse</label>
              <input type="number" class="form-control form-control-sm" name="ns_days_before_renewal"
                     min="0" max="365" value="<?= $nsDaysRenew ?>">
            </div>
            <div class="col-4">
              <label class="form-label">Dager før KPI</label>
              <input type="number" class="form-control form-control-sm" name="ns_days_before_kpi"
                     min="0" max="365" value="<?= $nsDaysKpi ?>">
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <div class="card-footer d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="/?page=contracts_view&id=<?= (int)$contractId ?>">Avbryt</a>
    <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Lagre</button>
  </div>
</form>

<script>
// -------------------------------------------------------------------
// Picklist for "Varsle også" in contracts_edit
// -------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function () {
    var availList   = document.getElementById('editAvailList');
    var selList     = document.getElementById('editSelectedList');
    var hidContainer = document.getElementById('editNotifyHidden');
    var countBadge  = document.getElementById('editSelectedCount');
    var placeholder = document.getElementById('editNoSelected');

    if (!availList || !selList) return;

    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function updateCount() {
        if (countBadge) countBadge.textContent = selList.querySelectorAll('.edit-sel-item').length;
    }

    function updatePlaceholder() {
        if (!placeholder) return;
        placeholder.style.display = selList.querySelectorAll('.edit-sel-item').length > 0 ? 'none' : '';
    }

    function addToSelected(uid, label) {
        // hide in avail
        var availItem = availList.querySelector('[data-user-id="' + uid + '"]');
        if (availItem) availItem.style.display = 'none';

        // add to sel list
        var li = document.createElement('li');
        li.className = 'd-flex align-items-center justify-content-between py-1 px-2 mb-1 border rounded small edit-sel-item';
        li.setAttribute('data-user-id', uid);
        li.innerHTML = '<span>' + escHtml(label) + '</span>'
            + '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 edit-btn-remove" title="Fjern">'
            + '<i class="bi bi-x"></i></button>';
        selList.appendChild(li);

        li.querySelector('.edit-btn-remove').addEventListener('click', function () {
            removeFromSelected(uid);
        });

        // hidden input
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'notify_user_ids[]';
        inp.value = uid;
        inp.setAttribute('data-uid', uid);
        if (hidContainer) hidContainer.appendChild(inp);

        if (placeholder) placeholder.style.display = 'none';
        updateCount();
    }

    function removeFromSelected(uid) {
        // remove from sel
        var selItem = selList.querySelector('.edit-sel-item[data-user-id="' + uid + '"]');
        if (selItem) selItem.remove();

        // restore in avail
        var availItem = availList.querySelector('[data-user-id="' + uid + '"]');
        if (availItem) availItem.style.display = '';

        // remove hidden input
        if (hidContainer) {
            var hidInp = hidContainer.querySelector('input[data-uid="' + uid + '"]');
            if (hidInp) hidInp.remove();
        }

        updateCount();
        updatePlaceholder();
    }

    // Wire "add" buttons in avail list
    availList.querySelectorAll('.edit-btn-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var li  = btn.closest('[data-user-id]');
            var uid = li ? li.getAttribute('data-user-id') : null;
            if (!uid) return;
            var nm  = li.querySelector('span') ? li.querySelector('span').textContent.trim() : ('Bruker #' + uid);
            addToSelected(uid, nm);
        });
    });

    // Wire "remove" buttons already in selected (server-rendered)
    selList.querySelectorAll('.edit-btn-remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var li  = btn.closest('.edit-sel-item');
            var uid = li ? li.getAttribute('data-user-id') : null;
            if (uid) removeFromSelected(uid);
        });
    });

    // Search in available list
    var searchInp = document.getElementById('editSearchAvail');
    if (searchInp) {
        searchInp.addEventListener('input', function () {
            var q = (searchInp.value || '').toLowerCase().trim();
            availList.querySelectorAll('[data-user-id]').forEach(function (li) {
                if (li.style.display === 'none' && !q) return; // already hidden because selected
                var lbl = (li.getAttribute('data-label') || '').toLowerCase();
                li.style.display = (!q || lbl.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    // When owner or sub changes, remove from selected list if present
    var ownerSel = document.querySelector('select[name="owner_username"]');
    var subSel   = document.getElementById('edit_sub_user');

    function onOwnerChange() {
        // owner is by username, not user_id – we can't easily cross-reference without extra work
        // so we skip auto-removal for owner (it's validated server-side)
    }

    if (subSel) {
        subSel.addEventListener('change', function () {
            var uid = subSel.value;
            if (uid && uid !== '0') {
                var selItem = selList.querySelector('.edit-sel-item[data-user-id="' + uid + '"]');
                if (selItem) removeFromSelected(uid);
            }
        });
    }

    updateCount();
    updatePlaceholder();

    // Sync existing server-rendered hidden inputs with data-uid attribute
    if (hidContainer) {
        hidContainer.querySelectorAll('input[type="hidden"]').forEach(function (inp) {
            var uid = inp.value;
            if (uid && !inp.getAttribute('data-uid')) {
                inp.setAttribute('data-uid', uid);
            }
        });
    }
});
</script>
