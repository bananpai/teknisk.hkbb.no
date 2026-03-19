<?php
// Path: /public/pages/events_map.php
// Kartvisning for "Berørte adresser" på en sak (event)
// - Krever rolle: events_read (eller admin)
// - Viser Leaflet-kart med markører for alle lagrede adresser på event_affected_addresses
// - "Slett alle" (valgfritt) -> sletter alle lagrede treff for saken (krever events_write/admin)
// - Fail-soft DB resolver (samme mønster som events_view.php)

declare(strict_types=1);

/* ------------------------------------------------------------
   Session tidlig
------------------------------------------------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ------------------------------------------------------------
   DB resolver (robust)
------------------------------------------------------------ */
if (!function_exists('resolve_pdo')) {
  function resolve_pdo(): ?PDO
  {
    if (function_exists('pdo')) {
      try { $p = pdo(); if ($p instanceof PDO) return $p; } catch (Throwable $e) {}
    }
    if (function_exists('getPDO')) {
      try { $p = getPDO(); if ($p instanceof PDO) return $p; } catch (Throwable $e) {}
    }
    if (class_exists('\\App\\Database')) {
      try { $p = \App\Database::getConnection(); if ($p instanceof PDO) return $p; } catch (Throwable $e) {}
    }
    if (class_exists('Database') && method_exists('Database', 'getConnection')) {
      try { $p = Database::getConnection(); if ($p instanceof PDO) return $p; } catch (Throwable $e) {}
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];

    $candidates = [
      __DIR__ . '/../../config.php',
      __DIR__ . '/../../config/config.php',
      __DIR__ . '/../../core/config.php',
    ];
    foreach ($candidates as $cfg) {
      if (is_file($cfg)) { try { require_once $cfg; } catch (Throwable $e) {} break; }
    }

    if (function_exists('getPDO')) {
      try { $p = getPDO(); if ($p instanceof PDO) return $p; } catch (Throwable $e) {}
    }
    return null;
  }
}

/* ------------------------------------------------------------
   Helpers
------------------------------------------------------------ */
if (!function_exists('esc')) {
  function esc(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('user_has_role')) {
  function user_has_role(PDO $pdo, int $userId, string $role): bool {
    $st = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id=? AND role=? LIMIT 1");
    $st->execute([$userId, $role]);
    return (bool)$st->fetchColumn();
  }
}
if (!function_exists('user_is_admin')) {
  function user_is_admin(PDO $pdo, int $userId): bool
  {
    $v = $_SESSION['is_admin'] ?? false;
    if ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'True' || $v === 'TRUE') return true;

    $roles = $_SESSION['roles'] ?? [];
    if (is_string($roles) && $roles !== '') $roles = [$roles];
    if (!is_array($roles)) $roles = [];
    $rolesLower = array_map(static fn($r) => strtolower(trim((string)$r)), $roles);
    if (in_array('admin', $rolesLower, true) || in_array('administrator', $rolesLower, true)) return true;

    if ($userId > 0) {
      foreach (['admin','administrator','Administrator'] as $r) {
        try { if (user_has_role($pdo, $userId, $r)) return true; } catch (Throwable $e) {}
      }
    }
    return false;
  }
}
function fmt_dt_out(?string $dt): string { if (!$dt) return ''; return date('Y-m-d H:i', strtotime($dt)); }

/* ------------------------------------------------------------
   Auth (valgfritt)
------------------------------------------------------------ */
if (function_exists('require_login')) {
  require_login();
}

/* ------------------------------------------------------------
   DB
------------------------------------------------------------ */
$pdo = resolve_pdo();
if (!$pdo instanceof PDO) {
  http_response_code(500);
  echo '<div class="alert alert-danger mt-3">Mangler database-tilkobling (pdo()).</div>';
  return;
}

/* ------------------------------------------------------------
   Permissions
------------------------------------------------------------ */
$username = (string)($_SESSION['username'] ?? $_SESSION['user'] ?? '');
$userId   = (int)($_SESSION['user_id'] ?? 0);
$isAdmin  = user_is_admin($pdo, $userId);

$canRead  = $isAdmin || ($userId > 0 && user_has_role($pdo, $userId, 'events_read'));
$canWrite = $isAdmin || ($userId > 0 && user_has_role($pdo, $userId, 'events_write'));

if (!$canRead) {
  http_response_code(403);
  echo '<div class="alert alert-danger mt-3">Du har ikke tilgang (events_read).</div>';
  return;
}

/* ------------------------------------------------------------
   Route-key (noen sider bruker ?pg=..., andre ?page=...)
------------------------------------------------------------ */
$routeKey = isset($_GET['pg']) ? 'pg' : 'page';

/* ------------------------------------------------------------
   Input + CSRF
------------------------------------------------------------ */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo '<div class="alert alert-danger mt-3">Mangler id.</div>'; return; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['csrf_token'];

$err = '';
$ok  = '';

/* ------------------------------------------------------------
   Load event + affected addresses
------------------------------------------------------------ */
function load_event(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
function load_affected(PDO $pdo, int $eventId): array {
  $st = $pdo->prepare("
    SELECT id, event_id, leveransepunkt_id, address_id, street, house_number, house_letter,
           postal_code, city, lat, lng, created_at
      FROM event_affected_addresses
     WHERE event_id=?
     ORDER BY created_at DESC, id DESC
  ");
  $st->execute([$eventId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function count_affected(PDO $pdo, int $eventId): int {
  $st = $pdo->prepare("SELECT COUNT(*) FROM event_affected_addresses WHERE event_id=?");
  $st->execute([$eventId]);
  return (int)$st->fetchColumn();
}
function count_lp(PDO $pdo, int $eventId): int {
  $st = $pdo->prepare("SELECT COUNT(DISTINCT leveransepunkt_id) FROM event_affected_addresses WHERE event_id=?");
  $st->execute([$eventId]);
  return (int)$st->fetchColumn();
}

$event = load_event($pdo, $id);
if (!$event) { echo '<div class="alert alert-danger mt-3">Fant ikke saken.</div>'; return; }

/* ------------------------------------------------------------
   POST: delete all affected addresses (valgfritt)
------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedCsrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($csrf, $postedCsrf)) {
    $err = 'Ugyldig CSRF-token.';
  } elseif (!$canWrite) {
    $err = 'Du har ikke skrivetilgang (events_write).';
  } else {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete_all_affected') {
      try {
        $st = $pdo->prepare("DELETE FROM event_affected_addresses WHERE event_id=?");
        $st->execute([$id]);

        // Oppdater event counters (fail-soft)
        try {
          $pdo->prepare("UPDATE events SET customer_impact=0, affected_customers=0, updated_by=? WHERE id=?")
              ->execute([$username, $id]);
        } catch (Throwable $e) {}

        $ok = 'Alle lagrede treff er slettet.';
      } catch (Throwable $e) {
        $err = 'Kunne ikke slette. (' . $e->getMessage() . ')';
      }
    }
  }
}

/* ------------------------------------------------------------
   Data
------------------------------------------------------------ */
$rows = load_affected($pdo, $id);
$total = count_affected($pdo, $id);
$lpTotal = count_lp($pdo, $id);

/* ------------------------------------------------------------
   Build points (only rows with lat/lng)
------------------------------------------------------------ */
$points = [];
$missing = 0;

foreach ($rows as $r) {
  $lat = $r['lat'];
  $lng = $r['lng'];

  $has = ($lat !== null && $lng !== null && $lat !== '' && $lng !== '');
  if (!$has) { $missing++; continue; }

  $addr = trim((string)($r['street'] ?? ''));
  $hn   = trim((string)($r['house_number'] ?? ''));
  $hl   = trim((string)($r['house_letter'] ?? ''));
  $line = $addr;
  if ($hn !== '') $line .= ' ' . $hn;
  if ($hl !== '') $line .= $hl;

  $pc = (string)($r['postal_code'] ?? '');
  $city = (string)($r['city'] ?? '');

  $points[] = [
    'id' => (int)$r['id'],
    'leveransepunkt_id' => (string)($r['leveransepunkt_id'] ?? ''),
    'address' => $line !== '' ? $line : '-',
    'post' => trim($pc . ' ' . $city),
    'lat' => (float)$lat,
    'lng' => (float)$lng,
    'created_at' => (string)($r['created_at'] ?? ''),
  ];
}

/* ------------------------------------------------------------
   Derived labels (nice)
------------------------------------------------------------ */
$title = (string)($event['title_public'] ?? ('Sak #' . $id));
$typeLabel = ((string)($event['type'] ?? '') === 'planned') ? 'Endring' : 'Hendelse';
$status = (string)($event['status'] ?? '');
$statusLabel = $status;
if ($statusLabel === 'scheduled') $statusLabel = 'Planlagt';
else if ($statusLabel === 'in_progress') $statusLabel = 'Pågående';
else if ($statusLabel === 'resolved') $statusLabel = 'Utført';
else if ($statusLabel === 'draft') $statusLabel = 'Utkast';
else if ($statusLabel === 'monitoring') $statusLabel = 'Overvåking';
else if ($statusLabel === 'cancelled') $statusLabel = 'Avlyst';

?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <div class="text-muted small">Sak #<?= (int)$id ?> · Kart</div>
    <h3 class="mb-0"><?= esc($title) ?></h3>
    <div class="text-muted small mt-1">
      <span class="badge bg-secondary"><?= esc($typeLabel) ?></span>
      <span class="badge bg-secondary"><?= esc($statusLabel) ?></span>
      <span class="badge bg-dark"><?= (int)$total ?> adresser</span>
      <span class="badge bg-secondary"><?= (int)$lpTotal ?> leveransepunkt</span>
      <?php if ($missing > 0): ?>
        <span class="badge bg-warning text-dark"><?= (int)$missing ?> uten koordinater</span>
      <?php endif; ?>
      · Oppdatert: <?= esc(fmt_dt_out((string)($event['updated_at'] ?? ''))) ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="/?<?= esc($routeKey) ?>=events_view&id=<?= (int)$id ?>">Tilbake</a>
    <?php if ($canWrite && $total > 0): ?>
      <form method="post" class="d-inline" onsubmit="return confirm('Slette ALLE lagrede treff for denne saken?');">
        <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
        <input type="hidden" name="action" value="delete_all_affected">
        <button type="submit" class="btn btn-outline-danger">Slett alle</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($err !== ''): ?><div class="alert alert-danger"><?= esc($err) ?></div><?php endif; ?>
<?php if ($ok !== ''): ?><div class="alert alert-success"><?= esc($ok) ?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-header d-flex align-items-center justify-content-between">
    <div class="fw-semibold">Kart</div>
    <div class="text-muted small">
      Viser kun rader med lat/lng. Totalt på kart: <span class="badge bg-success"><?= (int)count($points) ?></span>
    </div>
  </div>
  <div class="card-body p-0">
    <div id="map" style="height: 520px; width: 100%;"></div>
  </div>
</div>

<div class="card">
  <div class="card-header fw-semibold">Liste</div>
  <div class="card-body">
    <?php if (!$rows): ?>
      <div class="text-muted">Ingen berørte adresser lagret på denne saken.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
          <thead>
            <tr>
              <th>Leveransepunkt</th>
              <th>Adresse</th>
              <th>Postnr/sted</th>
              <th class="text-muted">Lat/Lng</th>
              <th class="text-muted">Tid</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
                $addr = trim((string)($r['street'] ?? ''));
                $hn = trim((string)($r['house_number'] ?? ''));
                $hl = trim((string)($r['house_letter'] ?? ''));
                $line = $addr;
                if ($hn !== '') $line .= ' ' . $hn;
                if ($hl !== '') $line .= $hl;
                if ($line === '') $line = '-';
                $pc = (string)($r['postal_code'] ?? '');
                $city = (string)($r['city'] ?? '');
                $lat = $r['lat'];
                $lng = $r['lng'];
              ?>
              <tr>
                <td><code><?= esc((string)($r['leveransepunkt_id'] ?? '')) ?></code></td>
                <td><?= esc($line) ?></td>
                <td><?= esc(trim($pc . ' ' . $city)) ?></td>
                <td class="text-muted small"><?= esc(($lat !== null && $lat !== '' ? (string)$lat : '-') . ' / ' . ($lng !== null && $lng !== '' ? (string)$lng : '-')) ?></td>
                <td class="text-muted small"><?= esc(fmt_dt_out((string)($r['created_at'] ?? ''))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Leaflet (ingen API-key nødvendig) -->
<link
  rel="stylesheet"
  href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
  crossorigin=""
>
<script
  src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
  crossorigin=""
></script>

<script>
(function(){
  var points = <?= json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  var map = L.map('map', { zoomControl: true });

  // OpenStreetMap tiles
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
  }).addTo(map);

  // Default center (Haugalandet-ish) hvis vi ikke har punkter
  var fallback = [59.413, 5.268];
  map.setView(fallback, 11);

  if (!points || !points.length) {
    var msg = L.control({position:'topright'});
    msg.onAdd = function(){
      var div = L.DomUtil.create('div', 'p-2 bg-white border rounded small');
      div.innerHTML = 'Ingen punkter med koordinater å vise.';
      return div;
    };
    msg.addTo(map);
    return;
  }

  var bounds = [];
  points.forEach(function(p){
    var lat = Number(p.lat), lng = Number(p.lng);
    if (!isFinite(lat) || !isFinite(lng)) return;

    bounds.push([lat, lng]);

    var html = ''
      + '<div class="small">'
      + '<div><b>Leveransepunkt</b>: <code>' + escapeHtml(String(p.leveransepunkt_id||'')) + '</code></div>'
      + '<div><b>Adresse</b>: ' + escapeHtml(String(p.address||'')) + '</div>'
      + '<div><b>Post</b>: ' + escapeHtml(String(p.post||'')) + '</div>'
      + '</div>';

    L.marker([lat, lng]).addTo(map).bindPopup(html);
  });

  if (bounds.length) {
    map.fitBounds(bounds, { padding: [20, 20] });
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }
})();
</script>