<?php
// Path: /public/pages/events.php
// Hendelser & planlagte jobber – Oversikt (alle) + statusfilter
//
// Live oppdatering (uten refresh):
// - Alle som kan lese: poller via AJAX (ajax=list) og oppdaterer status/tid/oppdatert/berørte i tabellen
// - De som kan skrive/publisere/admin: kjører også auto-start + auto-finish (ajax=refresh_status) før polling
//
// Auto-status:
// - planned + schedule_start <= NOW() + status IN ('scheduled','draft') => in_progress (+ actual_start)
// - planned + schedule_end   <= NOW() + status IN ('scheduled','in_progress','monitoring') => resolved ("Utført") (+ actual_end hvis kolonne finnes)
//
// UI:
// - Ingen tekst under tittelen
// - Egen kolonne "Berørte kunder"
// - Filter: søk + status (multi-select)
// - Status vises på norsk (DB-status beholdes internt)

declare(strict_types=1);

use App\Database;

/* ------------------------------------------------------------
   DB-tilkobling
------------------------------------------------------------ */
$pdo = null;
try {
  if (class_exists(Database::class)) {
    $pdo = Database::getConnection();
  } elseif (function_exists('pdo')) {
    $pdo = pdo();
  }
} catch (\Throwable $e) {
  $pdo = null;
}

if (!$pdo instanceof PDO) {
  http_response_code(500);
  echo '<div class="alert alert-danger mt-3">Mangler database-tilkobling.</div>';
  return;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ------------------------------------------------------------
   Helpers
------------------------------------------------------------ */
if (!function_exists('esc')) {
  function esc(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

if (!function_exists('ajax_mode')) {
  function ajax_mode(): void {
    ini_set('display_errors', '0');
    error_reporting(0);
    if (function_exists('ob_get_level')) {
      while (ob_get_level() > 0) {
        @ob_end_clean();
      }
    }
  }
}

if (!function_exists('json_out')) {
  function json_out(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

if (!function_exists('status_label_no')) {
  function status_label_no(string $status): string {
    return match (strtolower(trim($status))) {
      'draft'       => 'Utkast',
      'scheduled'   => 'Planlagt',
      'in_progress' => 'Pågår',
      'monitoring'  => 'Overvåker',
      'resolved'    => 'Utført',
      'cancelled'   => 'Avbrutt',
      default       => $status,
    };
  }
}

if (!function_exists('table_has_column')) {
  function table_has_column(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (\Throwable $e) {
      return false;
    }
  }
}

/**
 * Hent roller fra user_roles for user_id
 */
if (!function_exists('load_roles_for_user')) {
  function load_roles_for_user(PDO $pdo, int $userId): array {
    try {
      $st = $pdo->prepare("SELECT role FROM user_roles WHERE user_id=?");
      $st->execute([$userId]);
      $roles = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
      $roles = array_map('strtolower', array_map('trim', array_filter($roles, 'is_string')));
      return array_values(array_unique($roles));
    } catch (\Throwable $e) {
      return [];
    }
  }
}

/* ------------------------------------------------------------
   Bruker / tilgang
------------------------------------------------------------ */
$username = (string)($_SESSION['username'] ?? $_SESSION['user'] ?? '');
if ($username === '') {
  http_response_code(401);
  echo '<div class="alert alert-danger mt-3">Ikke innlogget.</div>';
  return;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  try {
    $st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $userId = (int)($st->fetchColumn() ?: 0);
    if ($userId > 0) $_SESSION['user_id'] = $userId;
  } catch (\Throwable $e) {
    $userId = 0;
  }
}

$roles = $userId > 0 ? load_roles_for_user($pdo, $userId) : [];

$isAdmin = (bool)($_SESSION['is_admin'] ?? false);
if (!$isAdmin && $roles) {
  $isAdmin = in_array('admin', $roles, true);
}

$canRead    = $isAdmin || in_array('events_read', $roles, true) || in_array('events_write', $roles, true) || in_array('events_publish', $roles, true);
$canWrite   = $isAdmin || in_array('events_write', $roles, true);
$canPublish = $isAdmin || in_array('events_publish', $roles, true);
$canAuto    = ($canWrite || $canPublish || $isAdmin);

if (!$canRead) {
  http_response_code(403);
  echo '<div class="alert alert-danger mt-3">Du har ikke tilgang (events_read).</div>';
  return;
}

/* ------------------------------------------------------------
   Filter: søk + status (multi-select)
------------------------------------------------------------ */
$q = trim((string)($_GET['q'] ?? ''));

// Tillatte statuser (DB-verdier)
$allStatuses     = ['draft','scheduled','in_progress','monitoring','resolved','cancelled'];
$defaultStatuses = ['draft','scheduled','in_progress','monitoring']; // skjul utførte/avlyste som default

// Bruk 'show_all=1' for å se alle statuser ufiltrert
$showAll = isset($_GET['show_all']);

// status kan komme som:
// 1) status[]=in_progress&status[]=scheduled  (multi-select)
// 2) status=in_progress,scheduled            (komma-separert)
// 3) status=in_progress                      (enkelt)
$selectedStatuses = [];
if (!$showAll) {
  if (isset($_GET['status']) && is_array($_GET['status'])) {
    $selectedStatuses = array_map('strtolower', array_map('trim', $_GET['status']));
  } else {
    $raw = (string)($_GET['status'] ?? '');
    if ($raw !== '') {
      $selectedStatuses = array_map('strtolower', array_map('trim', explode(',', $raw)));
    }
  }
  $selectedStatuses = array_values(array_unique(array_filter($selectedStatuses, function($s) use ($allStatuses) {
    return $s !== '' && in_array($s, $allStatuses, true);
  })));
  // Ingen eksplisitt filter valgt → bruk default (skjul utført/avlyst)
  if (empty($selectedStatuses)) {
    $selectedStatuses = $defaultStatuses;
  }
}

// Bygg WHERE
$where = [];
$args  = [];

if ($selectedStatuses) {
  $ph = implode(',', array_fill(0, count($selectedStatuses), '?'));
  $where[] = "e.status IN ($ph)";
  foreach ($selectedStatuses as $s) $args[] = $s;
}

if ($q !== '') {
  $where[] = "(e.title_public LIKE ? OR e.title_internal LIKE ? OR EXISTS (
      SELECT 1 FROM event_targets t
      WHERE t.event_id = e.id AND t.target_value LIKE ?
    ))";
  $args[] = "%$q%";
  $args[] = "%$q%";
  $args[] = "%$q%";
}

$sqlWhere = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/* ------------------------------------------------------------
   AJAX endpoints
------------------------------------------------------------ */
$ajax = (string)($_GET['ajax'] ?? '');
if ($ajax !== '') {
  ajax_mode();

  set_exception_handler(function($e) {
    json_out(500, ['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
  });
  set_error_handler(function() {
    json_out(500, ['ok' => false, 'error' => 'server_error', 'message' => 'PHP warning/notice in ajax response']);
  });

  if ($ajax === 'refresh_status') {
    if (!$canAuto) json_out(403, ['ok' => false, 'error' => 'forbidden']);

    $updatedStart  = 0;
    $updatedFinish = 0;

    // Sjekk om events.actual_end finnes (for å unngå SQL-feil hvis kolonnen ikke finnes)
    $hasActualEnd = table_has_column($pdo, 'events', 'actual_end');

    // 1) Auto-start: planned, scheduled/draft + schedule_start <= now -> in_progress
    $stSelStart = $pdo->prepare("
      SELECT id
        FROM events
       WHERE type='planned'
         AND status IN ('scheduled','draft')
         AND schedule_start IS NOT NULL
         AND schedule_start <= NOW()
       ORDER BY schedule_start ASC, id ASC
       LIMIT 500
    ");
    $stSelStart->execute();
    $idsStart = $stSelStart->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $idsStart = array_values(array_filter(array_map('intval', $idsStart)));

    if ($idsStart) {
      $ph = implode(',', array_fill(0, count($idsStart), '?'));
      $stUpStart = $pdo->prepare("
        UPDATE events
           SET status='in_progress',
               actual_start = COALESCE(actual_start, NOW()),
               updated_at=NOW(),
               updated_by='system'
         WHERE id IN ($ph)
      ");
      $stUpStart->execute($idsStart);
      $updatedStart = (int)$stUpStart->rowCount();
    }

    // 2) Auto-finish: planned, schedule_end <= now -> resolved
    $stSelEnd = $pdo->prepare("
      SELECT id
        FROM events
       WHERE type='planned'
         AND status IN ('scheduled','in_progress','monitoring')
         AND schedule_end IS NOT NULL
         AND schedule_end <= NOW()
       ORDER BY schedule_end ASC, id ASC
       LIMIT 500
    ");
    $stSelEnd->execute();
    $idsEnd = $stSelEnd->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $idsEnd = array_values(array_filter(array_map('intval', $idsEnd)));

    if ($idsEnd) {
      $ph = implode(',', array_fill(0, count($idsEnd), '?'));

      if ($hasActualEnd) {
        $stUpEnd = $pdo->prepare("
          UPDATE events
             SET status='resolved',
                 actual_end = COALESCE(actual_end, NOW()),
                 updated_at=NOW(),
                 updated_by='system'
           WHERE id IN ($ph)
        ");
      } else {
        $stUpEnd = $pdo->prepare("
          UPDATE events
             SET status='resolved',
                 updated_at=NOW(),
                 updated_by='system'
           WHERE id IN ($ph)
        ");
      }

      $stUpEnd->execute($idsEnd);
      $updatedFinish = (int)$stUpEnd->rowCount();
    }

    json_out(200, [
      'ok' => true,
      'updated_start' => $updatedStart,
      'updated_finish' => $updatedFinish,
      'updated_total' => ($updatedStart + $updatedFinish),
    ]);
  }

  if ($ajax === 'list') {
    $sqlList = "
      SELECT
        e.id, e.status, e.type,
        e.schedule_start, e.schedule_end, e.actual_start, e.updated_at,
        COALESCE(e.affected_customers, 0) AS affected_customers
      FROM events e
      $sqlWhere
      ORDER BY
        CASE e.status
          WHEN 'in_progress' THEN 1
          WHEN 'monitoring' THEN 2
          WHEN 'scheduled' THEN 3
          WHEN 'draft' THEN 4
          WHEN 'resolved' THEN 5
          WHEN 'cancelled' THEN 6
          ELSE 9
        END,
        COALESCE(e.schedule_start, e.actual_start, e.created_at) DESC,
        e.id DESC
      LIMIT 250
    ";
    $st = $pdo->prepare($sqlList);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $parts = [];
    foreach ($rows as $r) {
      $parts[] = (string)$r['id'] . '|' . (string)$r['status'] . '|' . (string)$r['updated_at'] . '|' . (string)($r['affected_customers'] ?? 0);
    }
    $checksum = hash('sha256', implode(';', $parts));

    json_out(200, [
      'ok' => true,
      'count' => count($rows),
      'checksum' => $checksum,
      'server_time_utc' => gmdate('c'),
      'rows' => $rows
    ]);
  }

  json_out(400, ['ok' => false, 'error' => 'bad_request']);
}

/* ------------------------------------------------------------
   Query (full render)
------------------------------------------------------------ */
$sql = "
SELECT
  e.*,
  (SELECT i.external_id
     FROM event_integrations i
    WHERE i.event_id=e.id AND i.system='jira'
    LIMIT 1) AS jira_key,
  (SELECT i.sync_status
     FROM event_integrations i
    WHERE i.event_id=e.id AND i.system='jira'
    LIMIT 1) AS jira_sync,
  (SELECT
      CASE
        WHEN i.meta_json IS NULL THEN NULL
        WHEN JSON_VALID(i.meta_json) THEN JSON_UNQUOTE(JSON_EXTRACT(i.meta_json, '$.project_key'))
        ELSE NULL
      END
     FROM event_integrations i
    WHERE i.event_id=e.id AND i.system='jira'
    LIMIT 1) AS jira_project_key,
  (SELECT
      CASE
        WHEN i.meta_json IS NULL THEN NULL
        WHEN JSON_VALID(i.meta_json) THEN JSON_UNQUOTE(JSON_EXTRACT(i.meta_json, '$.issuetype_id'))
        ELSE NULL
      END
     FROM event_integrations i
    WHERE i.event_id=e.id AND i.system='jira'
    LIMIT 1) AS jira_issuetype_id
FROM events e
$sqlWhere
ORDER BY
  CASE e.status
    WHEN 'in_progress' THEN 1
    WHEN 'monitoring' THEN 2
    WHEN 'scheduled' THEN 3
    WHEN 'draft' THEN 4
    WHEN 'resolved' THEN 5
    WHEN 'cancelled' THEN 6
    ELSE 9
  END,
  COALESCE(e.schedule_start, e.actual_start, e.created_at) DESC,
  e.id DESC
LIMIT 250
";

$rows = [];
try {
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
  echo '<div class="alert alert-danger mt-3">Kunne ikke hente saker: '.esc($e->getMessage()).'</div>';
  return;
}

/* ------------------------------------------------------------
   UI helpers
------------------------------------------------------------ */
function badge_class_status(string $status): string {
  return match ($status) {
    'draft'       => 'secondary',
    'scheduled'   => 'info',
    'in_progress' => 'danger',
    'monitoring'  => 'warning',
    'resolved'    => 'success',
    'cancelled'   => 'dark',
    default       => 'secondary',
  };
}
function badge_class_type(string $type): string {
  return $type === 'planned' ? 'primary' : 'danger';
}
function fmt_dt(?string $dt): string {
  if (!$dt) return '';
  $ts = strtotime($dt);
  if (!$ts) return '';
  return date('Y-m-d H:i', $ts);
}

?>
<style>
.ev-filter-btn {
  font-size: 12px;
  padding: 3px 10px;
  border-radius: 999px;
  border: 1.5px solid var(--bs-border-color, #dee2e6);
  background: transparent;
  cursor: pointer;
  transition: background .1s, color .1s, border-color .1s;
  white-space: nowrap;
  line-height: 1.6;
}
.ev-filter-btn.active {
  color: #fff !important;
}
.ev-filter-btn[data-status="draft"].active        { background:#6c757d; border-color:#6c757d; }
.ev-filter-btn[data-status="scheduled"].active    { background:#0dcaf0; border-color:#0dcaf0; color:#000!important; }
.ev-filter-btn[data-status="in_progress"].active  { background:#dc3545; border-color:#dc3545; }
.ev-filter-btn[data-status="monitoring"].active   { background:#ffc107; border-color:#ffc107; color:#000!important; }
.ev-filter-btn[data-status="resolved"].active     { background:#198754; border-color:#198754; }
.ev-filter-btn[data-status="cancelled"].active    { background:#212529; border-color:#212529; }

#eventsTable tbody tr { cursor: pointer; }
#eventsTable tbody tr:hover td { background: var(--bs-table-hover-bg, rgba(0,0,0,.04)); }

.ev-dist-icon { font-size: 11px; opacity: .45; }
.ev-dist-icon.on { opacity: 1; }

.ev-jira-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 999px;
}
</style>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-3">
    <h3 class="mb-0 h5">Hendelser</h3>
    <span class="badge text-bg-success" id="liveBadge" title="Siden oppdateres automatisk">
      <i class="bi bi-circle-fill me-1" style="font-size:7px;vertical-align:middle;"></i>Live
    </span>
  </div>
  <?php if ($canWrite || $canPublish): ?>
    <a class="btn btn-primary btn-sm" href="/?page=events_new">
      <i class="bi bi-plus-lg me-1"></i>Ny sak
    </a>
  <?php endif; ?>
</div>

<!-- Filter -->
<form id="evFilterForm" class="mb-3" method="get" action="/">
  <input type="hidden" name="page" value="events">
  <?php if ($showAll): ?>
    <input type="hidden" name="show_all" value="1">
  <?php else: ?>
    <?php
      // Skriv kun ut status-inputs hvis de avviker fra default,
      // så en "tom" URL gir default-filteret automatisk.
      $statusesToEmit = ($selectedStatuses !== $defaultStatuses) ? $selectedStatuses : [];
    ?>
    <?php foreach ($statusesToEmit as $s): ?>
      <input type="hidden" name="status[]" value="<?= esc($s) ?>" class="js-status-input">
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="d-flex flex-wrap gap-2 align-items-center">
    <!-- Søk -->
    <div class="input-group input-group-sm" style="width:260px;min-width:180px;">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input class="form-control" name="q" value="<?= esc($q) ?>" placeholder="Søk tittel, kunde …">
      <?php if ($q !== ''): ?>
        <a class="btn btn-outline-secondary" href="/?page=events<?= $selectedStatuses ? '&' . http_build_query(['status' => $selectedStatuses]) : '' ?>">
          <i class="bi bi-x"></i>
        </a>
      <?php endif; ?>
    </div>

    <!-- Status-filterknapper -->
    <div class="d-flex flex-wrap gap-1">
      <?php foreach ($allStatuses as $s):
        $active = in_array($s, $selectedStatuses, true);
      ?>
        <button type="button"
                class="ev-filter-btn <?= $active ? 'active' : '' ?>"
                data-status="<?= esc($s) ?>">
          <?= esc(status_label_no($s)) ?>
        </button>
      <?php endforeach; ?>
    </div>

    <?php
      $isDefault = ($selectedStatuses === $defaultStatuses && $q === '' && !$showAll);
    ?>
    <?php if ($showAll): ?>
      <a class="btn btn-sm btn-link text-muted p-0 ms-1" href="/?page=events">
        <i class="bi bi-eye-slash me-1"></i>Skjul utførte/avlyste
      </a>
    <?php elseif (!$isDefault || $q !== ''): ?>
      <a class="btn btn-sm btn-link text-muted p-0 ms-1" href="/?page=events">Nullstill</a>
    <?php else: ?>
      <a class="btn btn-sm btn-link text-muted p-0 ms-1" href="/?page=events&show_all=1">
        <i class="bi bi-eye me-1"></i>Vis utførte/avlyste
      </a>
    <?php endif; ?>
  </div>
</form>

<!-- Tabell -->
<?php if (!$rows): ?>
  <div class="alert alert-light border text-muted small">
    <i class="bi bi-inbox me-2"></i>Ingen saker å vise med valgt filter.
  </div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="eventsTable">
      <thead class="table-light">
        <tr class="small text-muted">
          <th style="width:150px;">Status</th>
          <th>Tittel</th>
          <th style="width:170px;">Tid</th>
          <th style="width:80px;" class="text-center">Kunder</th>
          <th style="width:90px;" class="text-center">Dist.</th>
          <th style="width:120px;" class="text-end">Oppdatert</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $id       = (int)($r['id'] ?? 0);
        $status   = (string)($r['status'] ?? '');
        $type     = (string)($r['type'] ?? '');
        $sev      = (string)($r['severity'] ?? '');
        $affected = (int)($r['affected_customers'] ?? 0);

        $pubDash  = (int)($r['published_to_dashboard'] ?? 0) === 1;
        $pubBot   = (int)($r['published_to_chatbot'] ?? 0) === 1;
        $isPublic = (int)($r['is_public'] ?? 0) === 1;

        $titlePublic   = (string)($r['title_public'] ?? '');
        $titleInternal = (string)($r['title_internal'] ?? '');

        if ($type === 'planned') {
          $time = trim(fmt_dt($r['schedule_start'] ?? null) . ' – ' . fmt_dt($r['schedule_end'] ?? null), ' – ');
        } else {
          $time = !empty($r['actual_start']) ? fmt_dt((string)$r['actual_start']) : '';
        }

        $jiraKey  = (string)($r['jira_key'] ?? '');
        $jiraSync = (string)($r['jira_sync'] ?? 'not_linked');

        $updatedAt = (string)($r['updated_at'] ?? '');
        ?>
        <tr data-event-id="<?= $id ?>"
            data-status="<?= esc($status) ?>"
            data-type="<?= esc($type) ?>"
            onclick="window.location='/?page=events_view&id=<?= $id ?>'">

          <!-- Status + type -->
          <td>
            <div class="d-flex flex-wrap gap-1 align-items-center">
              <span class="badge text-bg-<?= badge_class_status($status) ?> js-status-badge"
                    data-status-key="<?= esc($status) ?>"><?= esc(status_label_no($status)) ?></span>
              <span class="badge text-bg-<?= badge_class_type($type) ?>"><?= $type === 'planned' ? 'Planlagt' : 'Hendelse' ?></span>
              <?php if ($sev !== ''): ?>
                <span class="badge text-bg-dark"><?= esc($sev) ?></span>
              <?php endif; ?>
              <?php if ($jiraKey !== ''): ?>
                <span class="ev-jira-pill text-bg-<?= $jiraSync === 'error' ? 'danger' : 'secondary' ?>-subtle border">
                  <i class="bi bi-box-arrow-up-right" style="font-size:9px;"></i><?= esc($jiraKey) ?>
                </span>
              <?php endif; ?>
            </div>
          </td>

          <!-- Tittel -->
          <td>
            <div class="fw-semibold lh-sm"><?= esc($titlePublic) ?></div>
            <?php if ($titleInternal !== '' && $titleInternal !== $titlePublic): ?>
              <div class="small text-muted mt-1"><?= esc($titleInternal) ?></div>
            <?php endif; ?>
          </td>

          <!-- Tid -->
          <td class="small text-muted js-time-cell">
            <?php if ($type === 'planned'): ?>
              <i class="bi bi-calendar3 me-1 opacity-50"></i><?= esc($time) ?>
            <?php elseif ($time): ?>
              <i class="bi bi-exclamation-circle me-1 text-danger opacity-75"></i>Siden <?= esc($time) ?>
            <?php endif; ?>
          </td>

          <!-- Berørte kunder -->
          <td class="text-center js-affected-cell">
            <?php if ($affected > 0): ?>
              <span class="badge text-bg-secondary"><?= $affected ?></span>
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>

          <!-- Distribusjon (KS / Hkon / Public) -->
          <td class="text-center">
            <span class="ev-dist-icon <?= $pubDash ? 'on text-success' : '' ?>" title="Kundesenter">KS</span>
            <span class="ev-dist-icon <?= $pubBot  ? 'on text-primary' : '' ?>" title="Hkon">HK</span>
            <span class="ev-dist-icon <?= $isPublic ? 'on text-warning' : '' ?>" title="Offentlig">PUB</span>
          </td>

          <!-- Oppdatert -->
          <td class="text-end small text-muted js-updated-cell"><?= esc(fmt_dt($updatedAt)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="text-muted small mt-2 px-1">
    <?= count($rows) ?> sak<?= count($rows) !== 1 ? 'er' : '' ?>
    <?= count($rows) >= 250 ? ' (maks 250 vises)' : '' ?>
  </div>
<?php endif; ?>

<script>
// Status-filterknapper
(function(){
  var form = document.getElementById('evFilterForm');
  if (!form) return;

  var defaultStatuses = <?= json_encode($defaultStatuses) ?>;
  var currentSelected = <?= json_encode($selectedStatuses) ?>;
  var showAll         = <?= $showAll ? 'true' : 'false' ?>;

  var btns = form.querySelectorAll('.ev-filter-btn');

  function getInputs() { return Array.from(form.querySelectorAll('.js-status-input')); }

  function setSelected(arr) {
    getInputs().forEach(function(i){ i.remove(); });
    // Fjern show_all om den finnes
    var saInp = form.querySelector('input[name="show_all"]');
    if (saInp) saInp.remove();
    // Skriv kun ut hvis det avviker fra default
    var toWrite = (JSON.stringify(arr.slice().sort()) === JSON.stringify(defaultStatuses.slice().sort())) ? [] : arr;
    toWrite.forEach(function(s) {
      var inp = document.createElement('input');
      inp.type = 'hidden'; inp.name = 'status[]'; inp.value = s; inp.className = 'js-status-input';
      form.appendChild(inp);
    });
  }

  function updateBtns(arr) {
    btns.forEach(function(btn) {
      btn.classList.toggle('active', arr.includes(btn.getAttribute('data-status')));
    });
  }

  btns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var s = btn.getAttribute('data-status');
      var sel = showAll ? defaultStatuses.slice() : currentSelected.slice();
      showAll = false;
      var idx = sel.indexOf(s);
      idx === -1 ? sel.push(s) : sel.splice(idx, 1);
      currentSelected = sel;
      setSelected(sel);
      updateBtns(sel);
      form.submit();
    });
  });
})();
</script>

<script>
(function(){
  var canAuto = <?= $canAuto ? 'true' : 'false' ?>;

  var liveBadge = document.getElementById('liveBadge');

  function setLive(ok) {
    if (liveBadge) {
      liveBadge.className = 'badge ' + (ok ? 'bg-success' : 'bg-danger');
      liveBadge.textContent = ok ? 'Live' : 'Live (feil)';
    }
  }

  function statusLabelNo(status) {
    switch ((status || '').toLowerCase()) {
      case 'draft': return 'Utkast';
      case 'scheduled': return 'Planlagt';
      case 'in_progress': return 'Pågår';
      case 'monitoring': return 'Overvåker';
      case 'resolved': return 'Utført';
      case 'cancelled': return 'Avbrutt';
      default: return status || '';
    }
  }

  function badgeClassForStatus(status) {
    switch ((status || '').toLowerCase()) {
      case 'draft': return 'secondary';
      case 'scheduled': return 'info';
      case 'in_progress': return 'danger';
      case 'monitoring': return 'warning';
      case 'resolved': return 'success';
      case 'cancelled': return 'dark';
      default: return 'secondary';
    }
  }

  function fmtDtShort(s) {
    if (!s) return '';
    if (typeof s === 'string' && s.length >= 16) return s.substring(0,16);
    return String(s);
  }

  function setStatusBadge(tr, newStatus) {
    var b = tr.querySelector('.js-status-badge');
    if (!b) return;
    b.className = 'badge bg-' + badgeClassForStatus(newStatus) + ' js-status-badge';
    b.textContent = statusLabelNo(newStatus);
    b.setAttribute('data-status-key', newStatus || '');
    tr.setAttribute('data-status', newStatus || '');
  }

  function setTimeCell(tr, type, scheduleStart, scheduleEnd, actualStart) {
    var td = tr.querySelector('.js-time-cell');
    if (!td) return;

    var txt = '';
    if ((type || '').toLowerCase() === 'planned') {
      var a = fmtDtShort(scheduleStart);
      var b = fmtDtShort(scheduleEnd);
      txt = (a && b) ? (a + '–' + b) : (a || b || '');
    } else {
      txt = actualStart ? ('Siden ' + fmtDtShort(actualStart)) : '';
    }
    td.textContent = txt;
  }

  function setUpdatedCell(tr, updatedAt) {
    var td = tr.querySelector('.js-updated-cell');
    if (!td) return;
    td.textContent = fmtDtShort(updatedAt);
    tr.setAttribute('data-updated-at', updatedAt || '');
  }

  function setAffectedCell(tr, n) {
    var td = tr.querySelector('.js-affected-cell');
    if (!td) return;
    var val = 0;
    if (typeof n === 'number') val = n;
    else if (typeof n === 'string' && n.trim() !== '') val = parseInt(n, 10) || 0;
    td.textContent = String(val);
  }

  var busy = false;
  var lastChecksum = '';
  var lastCount = null;

  function fetchJsonWithDebug(url) {
    return fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).then(function(resp){
      return resp.text().then(function(text){
        var data = null;
        try { data = JSON.parse(text); } catch (e) {
          return { __parse_error: true, status: resp.status, statusText: resp.statusText, body: text };
        }
        data.__http_status = resp.status;
        return data;
      });
    }).catch(function(err){
      return { __net_error: true, message: (err && err.message) ? err.message : String(err) };
    });
  }

  function callRefreshStatus() {
    var url = new URL(window.location.href);
    url.searchParams.set('ajax', 'refresh_status');
    url.searchParams.set('_ts', String(Date.now()));
    return fetchJsonWithDebug(url.toString());
  }

  function callList() {
    var url = new URL(window.location.href);
    url.searchParams.set('ajax', 'list');
    url.searchParams.set('_ts', String(Date.now()));
    return fetchJsonWithDebug(url.toString());
  }

  function applyList(data) {
    if (!data || !data.ok) return false;

    if (data.checksum && data.checksum === lastChecksum && lastCount === data.count) {
      setLive(true);
      return true;
    }

    var rows = Array.isArray(data.rows) ? data.rows : [];
    var missing = 0;

    for (var i=0; i<rows.length; i++) {
      var row = rows[i] || {};
      var id = Number(row.id || 0);
      if (!id) continue;

      var tr = document.querySelector('tr[data-event-id="' + id + '"]');
      if (!tr) { missing++; continue; }

      setStatusBadge(tr, row.status || '');
      setTimeCell(tr, row.type, row.schedule_start, row.schedule_end, row.actual_start);
      setUpdatedCell(tr, row.updated_at);
      setAffectedCell(tr, (typeof row.affected_customers === 'undefined') ? 0 : row.affected_customers);
    }

    if (lastCount !== null && typeof data.count === 'number' && data.count !== lastCount) {
      window.location.reload();
      return true;
    }
    if (missing > 0) {
      window.location.reload();
      return true;
    }

    lastChecksum = data.checksum || '';
    lastCount = (typeof data.count === 'number') ? data.count : null;

    setLive(true);
    return true;
  }

  function tick() {
    if (busy) return;
    busy = true;

    var p = Promise.resolve(null);

    if (canAuto) {
      p = p.then(function(){ return callRefreshStatus(); })
        .then(function(){ return null; });
    }

    p.then(function(){ return callList(); })
     .then(function(listData){
        busy = false;
        if (!listData || listData.__net_error || listData.__parse_error || !listData.ok) {
          setLive(false);
          return;
        }
        applyList(listData);
     })
     .catch(function(){
        busy = false;
        setLive(false);
     });
  }

  setTimeout(tick, 1200);
  setInterval(tick, 15000);
})();
</script>