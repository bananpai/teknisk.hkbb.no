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
$allStatuses = ['draft','scheduled','in_progress','monitoring','resolved','cancelled'];

// status kan komme som:
// 1) status[]=in_progress&status[]=scheduled  (multi-select)
// 2) status=in_progress,scheduled            (komma-separert, for enkel copy/paste)
// 3) status=in_progress                      (enkelt)
$selectedStatuses = [];
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
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h3 class="mb-0">Hendelser &amp; planlagte jobber</h3>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <span class="badge bg-success" id="liveBadge" title="Siden oppdateres automatisk">Live</span>
    <?php if ($canWrite || $canPublish): ?>
      <a class="btn btn-primary" href="/?page=events_new">Ny sak</a>
    <?php endif; ?>
  </div>
</div>

<form class="row g-2 mb-3" method="get" action="/">
  <input type="hidden" name="page" value="events">

  <div class="col-12 col-md-6">
    <input class="form-control" name="q" value="<?= esc($q) ?>" placeholder="Søk: tittel, leveransepunkt/adresse/kunde/område/product-id …">
  </div>

  <div class="col-12 col-md-4">
    <select class="form-select" name="status[]" multiple size="1" id="statusSelect" aria-label="Velg status">
      <?php foreach ($allStatuses as $s): ?>
        <option value="<?= esc($s) ?>" <?= in_array($s, $selectedStatuses, true) ? 'selected' : '' ?>>
          <?= esc(status_label_no($s)) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Hold Ctrl/⌘ for flere. Tomt = alle statuser.</div>
  </div>

  <div class="col-12 col-md-2 d-grid">
    <button class="btn btn-outline-primary">Filtrer</button>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="alert alert-info">Ingen saker å vise med valgt filter.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle" id="eventsTable">
      <thead>
      <tr class="text-muted">
        <th style="width:120px;">Status</th>
        <th style="width:120px;">Type</th>
        <th>Tittel</th>
        <th style="width:140px;" class="text-end">Berørte kunder</th>
        <th style="width:170px;">Tid</th>
        <th style="width:130px;">Publisert</th>
        <th style="width:200px;">Jira</th>
        <th style="width:140px;" class="text-end">Oppdatert</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $id = (int)($r['id'] ?? 0);
        $status = (string)($r['status'] ?? '');
        $type = (string)($r['type'] ?? '');

        $pubDash = (int)($r['published_to_dashboard'] ?? 0) === 1;
        $pubBot  = (int)($r['published_to_chatbot'] ?? 0) === 1;
        $isPublic = (int)($r['is_public'] ?? 0) === 1;

        $time = '';
        if ($type === 'planned') {
          $time = trim(fmt_dt($r['schedule_start'] ?? null) . '–' . fmt_dt($r['schedule_end'] ?? null), '–');
        } else {
          $time = !empty($r['actual_start']) ? ('Siden ' . fmt_dt((string)$r['actual_start'])) : '';
        }

        $jiraKey   = (string)($r['jira_key'] ?? '');
        $jiraSync  = (string)($r['jira_sync'] ?? 'not_linked');
        $jiraProj  = trim((string)($r['jira_project_key'] ?? ''));
        $jiraType  = trim((string)($r['jira_issuetype_id'] ?? ''));

        $jiraBadge = match($jiraSync) {
          'linked'     => 'success',
          'pending'    => 'warning',
          'error'      => 'danger',
          'not_linked' => 'secondary',
          default      => 'secondary'
        };

        $jiraMetaLine = '';
        if ($jiraProj !== '' || $jiraType !== '') {
          $parts = [];
          if ($jiraProj !== '') $parts[] = 'Proj: ' . $jiraProj;
          if ($jiraType !== '') $parts[] = 'Type: ' . $jiraType;
          $jiraMetaLine = implode(' · ', $parts);
        }

        $updatedAt = (string)($r['updated_at'] ?? '');
        $sev = (string)($r['severity'] ?? '');
        $affected = (int)($r['affected_customers'] ?? 0);
        ?>
        <tr data-event-id="<?= $id ?>"
            data-status="<?= esc($status) ?>"
            data-type="<?= esc($type) ?>"
            style="cursor:pointer"
            onclick="window.location='/?page=events_view&id=<?= $id ?>'">

          <td>
            <span class="badge bg-<?= badge_class_status($status) ?> js-status-badge"
                  data-status-key="<?= esc($status) ?>"><?= esc(status_label_no($status)) ?></span>
            <?php if ($sev !== ''): ?>
              <span class="badge bg-dark ms-1 js-severity-badge"><?= esc($sev) ?></span>
            <?php endif; ?>
          </td>

          <td>
            <span class="badge bg-<?= badge_class_type($type) ?> js-type-badge"><?= $type==='planned'?'Planlagt':'Hendelse' ?></span>
          </td>

          <td>
            <div class="fw-semibold"><?= esc((string)($r['title_public'] ?? '')) ?></div>
          </td>

          <td class="text-end fw-semibold js-affected-cell"><?= $affected ?></td>

          <td class="small js-time-cell"><?= esc($time) ?></td>

          <td class="small">
            <span class="badge bg-<?= $pubDash?'success':'secondary' ?>">KS</span>
            <span class="badge bg-<?= $pubBot?'success':'secondary' ?>">Hkon</span>
            <span class="badge bg-<?= $isPublic?'success':'secondary' ?>">Public</span>
          </td>

          <td class="small">
            <div><span class="badge bg-<?= $jiraBadge ?>"><?= $jiraKey !== '' ? esc($jiraKey) : 'Ikke koblet' ?></span></div>
            <?php if ($jiraMetaLine !== ''): ?>
              <div class="text-muted small mt-1"><?= esc($jiraMetaLine) ?></div>
            <?php endif; ?>
          </td>

          <td class="text-end small text-muted js-updated-cell"><?= esc(fmt_dt($updatedAt)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

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