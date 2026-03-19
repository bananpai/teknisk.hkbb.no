<?php
// public/pages/contracts_view.php
//
// Avtaler & kontrakter – Vis avtale (MVP)
// - Viser kontraktdata fra contracts
// - Viser motpart (crm_accounts) hvis counterparty_account_id finnes
// - Viser ansvarlig (owner_username -> users)
// - Viser stedfortreder + "varsle også" fra contracts_notify_recipients (hvis finnes)
// - Viser varsling-innstillinger (contracts_notify_settings hvis finnes)
// - Viser aktivitet/varsel-logg (contracts_activity_log / contracts_alert_log hvis finnes)
// - NYTT: Endringslogg per avtale (contracts_change_log) – kan felles ned
//
// Forutsetter:
// - contracts
// - users (for navn)
// - crm_accounts (valgfritt)
// - contracts_notify_recipients (valgfritt)
// - contracts_notify_settings (valgfritt)
// - contracts_activity_log (valgfritt)
// - contracts_alert_log (valgfritt)
// - contracts_change_log (valgfritt)

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
if (!function_exists('fmt_date')) {
    function fmt_date($s): string {
        $s = (string)($s ?? '');
        if ($s === '') return '–';
        return h($s);
    }
}
if (!function_exists('badge_status')) {
    function badge_status(?string $status): string {
        $s = trim((string)$status);
        if ($s === '') return '<span class="badge text-bg-secondary">Ukjent</span>';
        $low = mb_strtolower($s);
        if (in_array($low, ['aktiv','pågår','active','open'], true)) return '<span class="badge text-bg-success">'.h($s).'</span>';
        if (in_array($low, ['utløpt','expired'], true)) return '<span class="badge text-bg-warning">'.h($s).'</span>';
        if (in_array($low, ['terminert','terminated','canceled','cancelled'], true)) return '<span class="badge text-bg-danger">'.h($s).'</span>';
        if (in_array($low, ['arkivert','archived'], true)) return '<span class="badge text-bg-secondary">'.h($s).'</span>';
        return '<span class="badge text-bg-info">'.h($s).'</span>';
    }
}
if (!function_exists('pretty_field')) {
    function pretty_field(string $f): string {
        $map = [
            'title' => 'Tittel',
            'contract_no' => 'Avtalenr',
            'status' => 'Status',
            'contract_type' => 'Avtaletype',
            'party_type' => 'Motpart-type',
            'counterparty' => 'Motpart',
            'counterparty_ref' => 'Motpart ref',
            'counterparty_account_id' => 'CRM-motpart',
            'owner_username' => 'Ansvarlig',
            'is_active' => 'Aktiv',
            'start_date' => 'Startdato',
            'end_date' => 'Sluttdato',
            'renewal_date' => 'Fornyelsesdato',
            'kpi_adjust_date' => 'KPI/indeksdato',
            'kpi_basis' => 'KPI/indeksbasis',
            'kpi_note' => 'KPI/indeksnotat',
            'notes' => 'Interne notater',
            'link_url' => 'Lenke URL',
            'link_label' => 'Lenketekst',
            'revised_at' => 'Sist revidert',
            'next_revision_date' => 'Neste revisjon',
            'revision_note' => 'Revisjonsnotat',
            'updated_at' => 'Oppdatert',
            'updated_by_username' => 'Oppdatert av',
        ];
        return $map[$f] ?? $f;
    }
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
$hasContracts   = contracts_table_exists($pdo, 'contracts');
$hasUsers       = contracts_table_exists($pdo, 'users');
$hasCrmAccounts = contracts_table_exists($pdo, 'crm_accounts');

$hasRecipients  = contracts_table_exists($pdo, 'contracts_notify_recipients');
$hasNotifySet   = contracts_table_exists($pdo, 'contracts_notify_settings');

$hasActLog      = contracts_table_exists($pdo, 'contracts_activity_log');
$hasAlertLog    = contracts_table_exists($pdo, 'contracts_alert_log');
$hasChangeLog   = contracts_table_exists($pdo, 'contracts_change_log');

$hasCounterpartyAccountCol = $hasContracts ? contracts_column_exists($pdo, 'contracts', 'counterparty_account_id') : false;

if (!$hasContracts) {
    ?>
    <div class="alert alert-danger mt-3">Tabellen <code>contracts</code> finnes ikke.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Load contract (and counterparty from CRM if possible)
// ---------------------------------------------------------
try {
    $sql = "
        SELECT
            c.*,
            ca.name AS crm_counterparty_name,
            ca.org_no AS crm_org_no,
            ca.reference AS crm_reference,
            ca.type AS crm_type
        FROM contracts c
        LEFT JOIN crm_accounts ca ON ca.id = c.counterparty_account_id
        WHERE c.id = :id
        LIMIT 1
    ";
    if (!$hasCrmAccounts || !$hasCounterpartyAccountCol) {
        $sql = "SELECT c.* FROM contracts c WHERE c.id = :id LIMIT 1";
    }
    $st = $pdo->prepare($sql);
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
// Resolve owner display name from owner_username -> users
// ---------------------------------------------------------
$ownerUsername = (string)($contract['owner_username'] ?? '');
$ownerDisplay = $ownerUsername !== '' ? $ownerUsername : '–';
if ($hasUsers && $ownerUsername !== '') {
    try {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(display_name,''), username) AS name FROM users WHERE username = :u LIMIT 1");
        $st->execute([':u' => $ownerUsername]);
        $n = (string)($st->fetchColumn() ?: '');
        if ($n !== '') $ownerDisplay = $n . ' (' . $ownerUsername . ')';
    } catch (\Throwable $e) { /* ignore */ }
}

// ---------------------------------------------------------
// Recipients (substitute + notify list)
// ---------------------------------------------------------
$substitute = null; // ['id'=>, 'label'=>]
$notifyList = [];   // array of labels
if ($hasRecipients) {
    try {
        $st = $pdo->prepare("
            SELECT
              r.user_id, r.recipient_role,
              COALESCE(NULLIF(u.display_name,''), u.username) AS display_name,
              u.username
            FROM contracts_notify_recipients r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.contract_id = :cid AND r.is_active = 1
            ORDER BY FIELD(r.recipient_role,'substitute','notify'), display_name
        ");
        $st->execute([':cid' => $contractId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $role = (string)($r['recipient_role'] ?? '');
            $dn = (string)($r['display_name'] ?? '');
            $un = (string)($r['username'] ?? '');
            $label = $dn !== '' ? $dn : ($un !== '' ? $un : ('User #' . (int)($r['user_id'] ?? 0)));
            if ($un !== '' && $dn !== '' && $un !== $dn) $label .= " ($un)";

            if ($role === 'substitute') {
                $substitute = ['id' => (int)($r['user_id'] ?? 0), 'label' => $label];
            } elseif ($role === 'notify') {
                if ($un !== '' && $un === $ownerUsername) continue;
                if ($substitute && (int)$r['user_id'] === (int)$substitute['id']) continue;
                $notifyList[] = $label;
            }
        }
    } catch (\Throwable $e) {
        $substitute = null;
        $notifyList = [];
    }
}

// ---------------------------------------------------------
// Notify settings (per contract)
// ---------------------------------------------------------
$notifySettings = null;
if ($hasNotifySet) {
    try {
        $st = $pdo->prepare("
            SELECT enabled, days_before_end, days_before_renewal, days_before_kpi
            FROM contracts_notify_settings
            WHERE contract_id = :cid
            LIMIT 1
        ");
        $st->execute([':cid' => $contractId]);
        $notifySettings = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { $notifySettings = null; }
}

// ---------------------------------------------------------
// Logs
// ---------------------------------------------------------
$activity = [];
if ($hasActLog) {
    try {
        $st = $pdo->prepare("
            SELECT action, username, message, ip, created_at
            FROM contracts_activity_log
            WHERE contract_id = :cid
            ORDER BY created_at DESC, id DESC
            LIMIT 50
        ");
        $st->execute([':cid' => $contractId]);
        $activity = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { $activity = []; }
}

$alerts = [];
if ($hasAlertLog) {
    try {
        $st = $pdo->prepare("
            SELECT alert_type, alert_date, days_before, sent_to, sent_by, created_at
            FROM contracts_alert_log
            WHERE contract_id = :cid
            ORDER BY created_at DESC, id DESC
            LIMIT 50
        ");
        $st->execute([':cid' => $contractId]);
        $alerts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { $alerts = []; }
}

$changeLog = [];
if ($hasChangeLog) {
    try {
        $st = $pdo->prepare("
            SELECT username, action, field_name, old_value, new_value, note, ip, created_at
            FROM contracts_change_log
            WHERE contract_id = :cid
            ORDER BY created_at DESC, id DESC
            LIMIT 100
        ");
        $st->execute([':cid' => $contractId]);
        $changeLog = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { $changeLog = []; }
}

// ---------------------------------------------------------
// Derived fields
// ---------------------------------------------------------
$cpName = (string)($contract['counterparty'] ?? '');
$cpRef  = (string)($contract['counterparty_ref'] ?? '');
$cpAccId = $hasCounterpartyAccountCol ? (int)($contract['counterparty_account_id'] ?? 0) : 0;

if ($hasCrmAccounts && $hasCounterpartyAccountCol && $cpAccId > 0) {
    $crmName = (string)($contract['crm_counterparty_name'] ?? '');
    if ($crmName !== '') $cpName = $crmName;
    $org = (string)($contract['crm_org_no'] ?? '');
    $ref = (string)($contract['crm_reference'] ?? '');
    if ($cpRef === '') $cpRef = $org !== '' ? $org : $ref;
}

$linkUrl = (string)($contract['link_url'] ?? '');
$linkLabel = (string)($contract['link_label'] ?? '');
if ($linkLabel === '' && $linkUrl !== '') $linkLabel = 'Åpne avtale';

$isActive = (int)($contract['is_active'] ?? 1) === 1;
?>
<style>
.kv dt{ color:#6c757d; font-weight:600; }
.kv dd{ margin-bottom:.65rem; }
.section-title{ display:flex; align-items:center; gap:.5rem; font-weight:600; }
.prewrap{ white-space:pre-wrap; }
.small-muted{ color:#6c757d; font-size:.875rem; }
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h1 class="h5 mb-1"><i class="bi bi-file-earmark-text me-1"></i> <?= h($contract['title'] ?? 'Avtale') ?></h1>
    <div class="text-muted small">
      Avtale-ID: <code><?= (int)$contractId ?></code>
      <?php if (!empty($contract['contract_no'])): ?> · Avtalenr: <code><?= h($contract['contract_no']) ?></code><?php endif; ?>
    </div>
  </div>

  <div class="d-flex gap-2">
    <a href="/?page=contracts" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i> Til oversikt
    </a>
    <a href="/?page=contracts_edit&id=<?= (int)$contractId ?>" class="btn btn-sm btn-primary">
      <i class="bi bi-pencil me-1"></i> Rediger
    </a>
  </div>
</div>

<div class="row g-3">
  <!-- Left: main -->
  <div class="col-12 col-lg-8">

    <section class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
          <div class="section-title"><i class="bi bi-info-circle"></i> Oversikt</div>
          <div>
            <?= badge_status((string)($contract['status'] ?? '')) ?>
            <?php if (!$isActive): ?><span class="badge text-bg-secondary ms-1">Inaktiv</span><?php endif; ?>
          </div>
        </div>
        <hr>

        <dl class="row kv small mb-0">
          <dt class="col-sm-4"><i class="bi bi-diagram-3 me-1"></i> Avtaletype</dt>
          <dd class="col-sm-8"><?= $contract['contract_type'] ? h($contract['contract_type']) : '–' ?></dd>

          <dt class="col-sm-4"><i class="bi bi-people me-1"></i> Motpart-type</dt>
          <dd class="col-sm-8"><?= $contract['party_type'] ? h($contract['party_type']) : '–' ?></dd>

          <dt class="col-sm-4"><i class="bi bi-building me-1"></i> Motpart</dt>
          <dd class="col-sm-8">
            <?= $cpName !== '' ? h($cpName) : '–' ?>
            <?php if ($hasCrmAccounts && $hasCounterpartyAccountCol && $cpAccId > 0): ?>
              <span class="text-muted small"> · <a href="/?page=crm_accounts&view=<?= (int)$cpAccId ?>"><i class="bi bi-box-arrow-up-right"></i> Åpne i CRM</a></span>
            <?php endif; ?>
            <?php if ($cpRef !== ''): ?><div class="text-muted small">Ref: <?= h($cpRef) ?></div><?php endif; ?>
          </dd>

          <dt class="col-sm-4"><i class="bi bi-person-check me-1"></i> Ansvarlig</dt>
          <dd class="col-sm-8"><?= h($ownerDisplay) ?></dd>

          <dt class="col-sm-4"><i class="bi bi-person-gear me-1"></i> Stedfortreder</dt>
          <dd class="col-sm-8">
            <?php if ($substitute): ?>
              <?= h($substitute['label']) ?>
            <?php else: ?>
              <span class="text-muted">–</span>
              <?php if (!$hasRecipients): ?><span class="text-muted small"> (krever contracts_notify_recipients)</span><?php endif; ?>
            <?php endif; ?>
          </dd>

          <dt class="col-sm-4"><i class="bi bi-bell me-1"></i> Varsle også</dt>
          <dd class="col-sm-8">
            <?php if (!empty($notifyList)): ?>
              <div class="d-flex flex-wrap gap-1">
                <?php foreach ($notifyList as $lbl): ?>
                  <span class="badge text-bg-light border"><?= h($lbl) ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <span class="text-muted">–</span>
              <?php if (!$hasRecipients): ?><span class="text-muted small"> (krever contracts_notify_recipients)</span><?php endif; ?>
            <?php endif; ?>
          </dd>
        </dl>
      </div>
    </section>

    <section class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="section-title mb-2"><i class="bi bi-calendar-event"></i> Tidslinje</div>
        <hr>
        <dl class="row kv small mb-0">
          <dt class="col-sm-4"><i class="bi bi-play-circle me-1"></i> Startdato</dt>
          <dd class="col-sm-8"><?= fmt_date($contract['start_date'] ?? null) ?></dd>

          <dt class="col-sm-4"><i class="bi bi-stop-circle me-1"></i> Sluttdato</dt>
          <dd class="col-sm-8"><?= fmt_date($contract['end_date'] ?? null) ?></dd>

          <dt class="col-sm-4"><i class="bi bi-arrow-repeat me-1"></i> Fornyelsesdato</dt>
          <dd class="col-sm-8"><?= fmt_date($contract['renewal_date'] ?? null) ?></dd>

          <dt class="col-sm-4"><i class="bi bi-graph-up-arrow me-1"></i> KPI/indeksdato</dt>
          <dd class="col-sm-8"><?= fmt_date($contract['kpi_adjust_date'] ?? null) ?></dd>

          <dt class="col-sm-4"><i class="bi bi-sliders me-1"></i> KPI/indeksbasis</dt>
          <dd class="col-sm-8"><?= $contract['kpi_basis'] ? h($contract['kpi_basis']) : '–' ?></dd>

          <dt class="col-sm-4"><i class="bi bi-chat-left-text me-1"></i> KPI/indeksnotat</dt>
          <dd class="col-sm-8"><?= $contract['kpi_note'] ? h($contract['kpi_note']) : '–' ?></dd>

          <?php if (contracts_column_exists($pdo, 'contracts', 'revised_at')): ?>
            <dt class="col-sm-4"><i class="bi bi-journal-check me-1"></i> Sist revidert</dt>
            <dd class="col-sm-8"><?= fmt_date($contract['revised_at'] ?? null) ?></dd>
          <?php endif; ?>

          <?php if (contracts_column_exists($pdo, 'contracts', 'next_revision_date')): ?>
            <dt class="col-sm-4"><i class="bi bi-calendar2-check me-1"></i> Neste revisjon</dt>
            <dd class="col-sm-8"><?= fmt_date($contract['next_revision_date'] ?? null) ?></dd>
          <?php endif; ?>

          <?php if (contracts_column_exists($pdo, 'contracts', 'revision_note')): ?>
            <dt class="col-sm-4"><i class="bi bi-card-text me-1"></i> Revisjonsnotat</dt>
            <dd class="col-sm-8"><?= !empty($contract['revision_note']) ? '<div class="prewrap">'.h($contract['revision_note']).'</div>' : '–' ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </section>

    <section class="card shadow-sm">
      <div class="card-body">
        <div class="section-title mb-2"><i class="bi bi-journal-text"></i> Interne notater</div>
        <hr>
        <div class="small">
          <?php if (!empty($contract['notes'])): ?>
            <div class="border rounded p-2 bg-light prewrap"><?= h($contract['notes']) ?></div>
          <?php else: ?>
            <div class="text-muted">Ingen notater.</div>
          <?php endif; ?>
        </div>
      </div>
    </section>

  </div>

  <!-- Right: sidebar -->
  <div class="col-12 col-lg-4">

    <section class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="section-title mb-2"><i class="bi bi-link-45deg"></i> Lenke</div>
        <hr>
        <?php if ($linkUrl !== ''): ?>
          <a class="btn btn-sm btn-primary w-100" href="<?= h($linkUrl) ?>" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right me-1"></i> <?= h($linkLabel) ?>
          </a>
          <div class="small text-muted mt-2" style="word-break: break-all;"><?= h($linkUrl) ?></div>
        <?php else: ?>
          <div class="text-muted small">Ingen lenke registrert.</div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="section-title mb-2"><i class="bi bi-bell"></i> Varslingsinnstillinger</div>
        <hr>
        <?php if ($notifySettings): ?>
          <?php $enabled = (int)($notifySettings['enabled'] ?? 1) === 1; ?>
          <div class="mb-2">
            <?php if ($enabled): ?>
              <span class="badge text-bg-success">På</span>
            <?php else: ?>
              <span class="badge text-bg-secondary">Av</span>
            <?php endif; ?>
          </div>
          <dl class="row kv small mb-0">
            <dt class="col-7">Dager før utløp</dt>
            <dd class="col-5 text-end"><?= (int)($notifySettings['days_before_end'] ?? 0) ?></dd>

            <dt class="col-7">Dager før fornyelse</dt>
            <dd class="col-5 text-end"><?= (int)($notifySettings['days_before_renewal'] ?? 0) ?></dd>

            <dt class="col-7">Dager før KPI</dt>
            <dd class="col-5 text-end"><?= (int)($notifySettings['days_before_kpi'] ?? 0) ?></dd>
          </dl>
        <?php else: ?>
          <div class="text-muted small">
            Ingen egne innstillinger funnet.
            <?php if (!$hasNotifySet): ?><span class="text-muted">(tabellen contracts_notify_settings finnes ikke)</span><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="section-title mb-2"><i class="bi bi-clock-history"></i> Metadata</div>
        <hr>
        <dl class="row kv small mb-0">
          <dt class="col-6">Opprettet</dt>
          <dd class="col-6 text-end"><?= $contract['created_at'] ? h($contract['created_at']) : '–' ?></dd>

          <dt class="col-6">Opprettet av</dt>
          <dd class="col-6 text-end"><?= $contract['created_by_username'] ? h($contract['created_by_username']) : '–' ?></dd>

          <dt class="col-6">Oppdatert</dt>
          <dd class="col-6 text-end"><?= $contract['updated_at'] ? h($contract['updated_at']) : '–' ?></dd>

          <dt class="col-6">Oppdatert av</dt>
          <dd class="col-6 text-end"><?= $contract['updated_by_username'] ? h($contract['updated_by_username']) : '–' ?></dd>
        </dl>
      </div>
    </section>

    <section class="card shadow-sm">
      <div class="card-body">
        <div class="section-title mb-2"><i class="bi bi-journal-check"></i> Historikk</div>
        <hr>

        <div class="accordion" id="contractsLogsAcc">

          <div class="accordion-item">
            <h2 class="accordion-header" id="headingChg">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseChg">
                Endringslogg (siste 100)
              </button>
            </h2>
            <div id="collapseChg" class="accordion-collapse collapse" data-bs-parent="#contractsLogsAcc">
              <div class="accordion-body small">
                <?php if (!$hasChangeLog): ?>
                  <div class="text-muted">
                    Endringslogg ikke aktiv. (Opprett <code>contracts_change_log</code> for felt-for-felt historikk.)
                  </div>
                <?php elseif (empty($changeLog)): ?>
                  <div class="text-muted">Ingen endringer logget.</div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead>
                        <tr>
                          <th>Tid</th>
                          <th>Bruker</th>
                          <th>Felt</th>
                          <th>Gammel</th>
                          <th>Ny</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($changeLog as $cl): ?>
                          <tr>
                            <td class="text-muted"><?= h($cl['created_at'] ?? '') ?></td>
                            <td><?= h($cl['username'] ?? '') ?></td>
                            <td><?= h(pretty_field((string)($cl['field_name'] ?? ''))) ?></td>
                            <td class="text-muted"><?= $cl['old_value'] !== null && $cl['old_value'] !== '' ? h($cl['old_value']) : '–' ?></td>
                            <td><?= $cl['new_value'] !== null && $cl['new_value'] !== '' ? h($cl['new_value']) : '–' ?></td>
                          </tr>
                          <?php if (!empty($cl['note'])): ?>
                            <tr>
                              <td colspan="5" class="small-muted">
                                <i class="bi bi-chat-left-text me-1"></i><?= h($cl['note']) ?>
                              </td>
                            </tr>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="accordion-item">
            <h2 class="accordion-header" id="headingAct">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAct">
                Aktivitet (siste 50)
              </button>
            </h2>
            <div id="collapseAct" class="accordion-collapse collapse" data-bs-parent="#contractsLogsAcc">
              <div class="accordion-body small">
                <?php if (!$hasActLog): ?>
                  <div class="text-muted">contracts_activity_log finnes ikke.</div>
                <?php elseif (empty($activity)): ?>
                  <div class="text-muted">Ingen aktivitet registrert.</div>
                <?php else: ?>
                  <div class="list-group list-group-flush">
                    <?php foreach ($activity as $a): ?>
                      <div class="list-group-item">
                        <div class="d-flex justify-content-between gap-2">
                          <div class="fw-semibold"><?= h($a['action'] ?? '') ?></div>
                          <div class="text-muted"><?= h($a['created_at'] ?? '') ?></div>
                        </div>
                        <div class="text-muted">
                          <?= h($a['username'] ?? '') ?>
                          <?php if (!empty($a['ip'])): ?> · <?= h($a['ip']) ?><?php endif; ?>
                        </div>
                        <?php if (!empty($a['message'])): ?>
                          <div><?= h($a['message']) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="accordion-item">
            <h2 class="accordion-header" id="headingAlert">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAlert">
                Varsel-logg (siste 50)
              </button>
            </h2>
            <div id="collapseAlert" class="accordion-collapse collapse" data-bs-parent="#contractsLogsAcc">
              <div class="accordion-body small">
                <?php if (!$hasAlertLog): ?>
                  <div class="text-muted">contracts_alert_log finnes ikke.</div>
                <?php elseif (empty($alerts)): ?>
                  <div class="text-muted">Ingen varsler sendt/logget.</div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead>
                        <tr>
                          <th>Type</th>
                          <th>Dato</th>
                          <th class="text-end">Dager før</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($alerts as $al): ?>
                          <tr>
                            <td><?= h($al['alert_type'] ?? '') ?></td>
                            <td><?= h($al['alert_date'] ?? '') ?></td>
                            <td class="text-end"><?= (int)($al['days_before'] ?? 0) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <div class="text-muted small mt-2">(Detaljer: sent_to/sent_by ligger i tabellen og kan vises når dere ønsker.)</div>
                <?php endif; ?>
              </div>
            </div>
          </div>

        </div><!-- /accordion -->
      </div>
    </section>

  </div>
</div>
