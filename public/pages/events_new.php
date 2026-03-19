<?php
// Path: /public/pages/events_new.php
// Hendelser & planlagte jobber – Opprett ny sak
// - Krever rolle: events_write (eller admin)
// - Oppretter event i status=draft og sender til events_view for videre redigering

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
   Innlogget bruker + tilgang
------------------------------------------------------------ */
$username = (string)($_SESSION['username'] ?? $_SESSION['user'] ?? '');
if ($username === '') {
  http_response_code(401);
  echo '<div class="alert alert-danger mt-3">Ikke innlogget.</div>';
  return;
}

// Finn user_id (bruk session hvis finnes, ellers slå opp på username)
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
  try {
    $st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $userId = (int)($st->fetchColumn() ?: 0);
    if ($userId > 0) {
      $_SESSION['user_id'] = $userId;
    }
  } catch (\Throwable $e) {
    $userId = 0;
  }
}

// Roller fra DB
$roles = $userId > 0 ? load_roles_for_user($pdo, $userId) : [];

// is_admin: bruk session hvis satt, ellers sjekk rolle admin
$isAdmin = (bool)($_SESSION['is_admin'] ?? false);
if (!$isAdmin && $roles) {
  $isAdmin = in_array('admin', $roles, true);
}

// events_write tilgang
$canWrite = $isAdmin || in_array('events_write', $roles, true) || in_array('events_publish', $roles, true);
if (!$canWrite) {
  http_response_code(403);
  echo '<div class="alert alert-danger mt-3">Du har ikke tilgang (events_write).</div>';
  return;
}

/* ------------------------------------------------------------
   CSRF
------------------------------------------------------------ */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['csrf_token'];

$err = '';

/* ------------------------------------------------------------
   POST: Opprett draft
------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedCsrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($csrf, $postedCsrf)) {
    $err = 'Ugyldig CSRF-token.';
  } else {
    $type = (string)($_POST['type'] ?? 'incident');
    if (!in_array($type, ['incident','planned'], true)) $type = 'incident';

    $title = trim((string)($_POST['title_public'] ?? ''));
    if ($title === '') $title = ($type === 'planned' ? 'Planlagt jobb' : 'Hendelse');

    try {
      $st = $pdo->prepare("
        INSERT INTO events(type, status, impact, title_public, created_by, updated_by, owner_username)
        VALUES(?, 'draft', 'none', ?, ?, ?, ?)
      ");
      $st->execute([$type, $title, $username, $username, $username]);
      $id = (int)$pdo->lastInsertId();

      // Opprett integrasjonsrad (Jira placeholder) hvis tabellen finnes
      try {
        $pdo->prepare("INSERT IGNORE INTO event_integrations(event_id, system, sync_status) VALUES(?, 'jira', 'not_linked')")
            ->execute([$id]);
      } catch (\Throwable $e) {
        // OK å ignorere hvis integrasjonstabell ikke finnes enda
      }

      header('Location: /?page=events_view&id=' . $id);
      exit;
    } catch (\Throwable $e) {
      $err = 'Kunne ikke opprette sak: ' . $e->getMessage();
    }
  }
}

?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h3 class="mb-0">Ny sak</h3>
    <div class="text-muted small">Opprett hendelse eller planlagt jobb (utkast).</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="/?page=events">Tilbake</a>
  </div>
</div>

<?php if ($err !== ''): ?>
  <div class="alert alert-danger"><?= esc($err) ?></div>
<?php endif; ?>

<form method="post" class="card">
  <div class="card-body">
    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">

    <div class="row g-3">
      <div class="col-12 col-md-4">
        <label class="form-label">Type</label>
        <select class="form-select" name="type">
          <option value="incident">Hendelse</option>
          <option value="planned">Planlagt jobb</option>
        </select>
      </div>
      <div class="col-12 col-md-8">
        <label class="form-label">Tittel (kundeklar)</label>
        <input class="form-control" name="title_public" placeholder="Kort og tydelig, brukes av kundesenter/Hkon">
      </div>
    </div>

    <div class="mt-3 d-grid d-md-flex gap-2">
      <button class="btn btn-primary">Opprett utkast</button>
      <a class="btn btn-outline-secondary" href="/?page=events">Avbryt</a>
    </div>
  </div>
</form>