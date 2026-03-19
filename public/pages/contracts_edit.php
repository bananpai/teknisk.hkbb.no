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
if ($hasUsers) {
    try {
        $st = $pdo->query("SELECT username, COALESCE(NULLIF(display_name,''), username) AS name FROM users ORDER BY name");
        $users = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (\Throwable $e) { $users = []; }
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

            if (empty($changes) && $logNote === '') {
                $errors[] = "Ingen endringer å lagre.";
            } else {
                try {
                    $pdo->beginTransaction();

                    // bygg UPDATE
                    $sets = [];
                    $params = [':id' => $contractId];

                    foreach ($new as $field => $val) {
                        // Oppdater kun felter som faktisk finnes i contract-arrayet eller som vi vet finnes via col-check
                        // (Dette hindrer feil hvis noen felt ikke finnes i tabellen)
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
                        $sql = "UPDATE contracts SET " . implode(", ", $sets) . " WHERE id = :id LIMIT 1";
                        $st = $pdo->prepare($sql);
                        $st->execute($params);
                    }

                    // endringslogg per felt
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

                    if ($hasChangeLog) {
                        $stLog = $pdo->prepare("
                            INSERT INTO contracts_change_log
                              (contract_id, username, action, field_name, old_value, new_value, note, ip, created_at)
                            VALUES
                              (:cid, :u, :a, :f, :ov, :nv, :note, :ip, NOW())
                        ");

                        if (!empty($changes)) {
                            foreach ($changes as $field => [$ov, $nv]) {
                                $stLog->execute([
                                    ':cid'  => $contractId,
                                    ':u'    => $username,
                                    ':a'    => 'update',
                                    ':f'    => $field,
                                    ':ov'   => is_null($ov) ? null : (string)$ov,
                                    ':nv'   => is_null($nv) ? null : (string)$nv,
                                    ':note' => $logNote !== '' ? $logNote : null,
                                    ':ip'   => $ip,
                                ]);
                            }
                        } else {
                            // Kun notat uten feltendringer
                            $stLog->execute([
                                ':cid'  => $contractId,
                                ':u'    => $username,
                                ':a'    => 'note',
                                ':f'    => null,
                                ':ov'   => null,
                                ':nv'   => null,
                                ':note' => $logNote !== '' ? $logNote : null,
                                ':ip'   => $ip,
                            ]);
                        }
                    }

                    // aktivitet (valgfritt)
                    if ($hasActivityLog) {
                        $msg = $logNote !== '' ? $logNote : null;
                        $act = "Oppdatert avtale";
                        if (!empty($changes)) {
                            $act .= " (" . count($changes) . " felt)";
                        }
                        $stAct = $pdo->prepare("
                            INSERT INTO contracts_activity_log (contract_id, action, username, message, ip, created_at)
                            VALUES (:cid, :action, :u, :msg, :ip, NOW())
                        ");
                        $stAct->execute([
                            ':cid' => $contractId,
                            ':action' => 'update',
                            ':u' => $username,
                            ':msg' => $msg ? mb_substr($msg, 0, 1000) : $act,
                            ':ip' => $ip,
                        ]);
                    }

                    $pdo->commit();
                    $success = true;

                    // reload
                    $st = $pdo->prepare("SELECT * FROM contracts WHERE id = :id LIMIT 1");
                    $st->execute([':id' => $contractId]);
                    $contract = $st->fetch(PDO::FETCH_ASSOC) ?: $contract;

                    // ny csrf
                    $_SESSION['csrf_contracts_edit'] = bin2hex(random_bytes(16));
                    $csrf = (string)$_SESSION['csrf_contracts_edit'];
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = "Kunne ikke lagre. (" . h($e->getMessage()) . ")";
                }
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
      </div>
    </div>

  </div>

  <div class="card-footer d-flex justify-content-end gap-2">
    <a class="btn btn-outline-secondary" href="/?page=contracts_view&id=<?= (int)$contractId ?>">Avbryt</a>
    <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Lagre</button>
  </div>
</form>
