<?php
// Path: /public/pages/events_view.php
// Sak (Endring/Hendelse) – Vis / rediger (EN side, dynamisk UI)
// Formål: Registrere sak + informere eksterne parter (Kundesenter/Chat/Jira). Saksbehandling skjer i Jira.
//
// NYTT (Berørte adresser):
// - Lim inn leveransepunkt_id (liste) -> server-side API-oppslag mot https://bestillfiber.no/api/adresses/?leveransepunkt_id=...
// - Viser fremdrift i UI (live) og antall matcher
// - Lagrer treff i tabell event_affected_addresses (per event)
// - Oppdaterer events.customer_impact og events.affected_customers
//
// NYTT: Sletting (AJAX) av berørte adresser:
// - Knapp "Slett" på hver rad
// - Knapp "Slett alle"
// - Tabellen + badges oppdateres automatisk
//
// NYTT: Kart
// - Lenke til /?page=events_map&id=... som viser alle berørte leveransepunkt på kart (lat/lng)
//
// NYTT: Jira-saksnummer
// - Felt "JIRA saksnummer" (placeholder FTD-123)
// - Hvis utfylt vises knapp "Åpne i Jira" (https://hkraft.atlassian.net/browse/<key>)
// - "Link til sak" er fjernet (kommer fra Jira-saksnummer)
//
// NYTT: Alvorlighet (fikset DB truncation)
// - severity lagres som kode: none/minor/moderate/major/critical (VARCHAR(32))
//
// NYTT: Admin hard delete
// - Admin kan slette saken permanent inkl. event_updates, event_targets, event_affected_addresses, event_integrations.

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
   Auth (valgfritt)
------------------------------------------------------------ */
$u = null;
if (function_exists('require_login')) {
  $u = require_login();
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
   Helpers
------------------------------------------------------------ */
if (!function_exists('esc')) {
  function esc(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('safe_stmt')) {
  function safe_stmt(PDO $pdo, string $sql, array $params = []): bool {
    try { $st = $pdo->prepare($sql); $st->execute($params); return true; } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('table_exists')) {
  function table_exists(PDO $pdo, string $table): bool {
    try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; } catch (Throwable $e) { return false; }
  }
}
if (!function_exists('col_exists')) {
  function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
      $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
      $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
      $st->execute([$db, $table, $col]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
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

function fmt_dt_in(?string $dt): string { if (!$dt) return ''; return date('Y-m-d\TH:i', strtotime($dt)); }
function fmt_dt_out(?string $dt): string { if (!$dt) return ''; return date('Y-m-d H:i', strtotime($dt)); }
function as_int($v, int $default = 0): int {
  if ($v === null) return $default;
  if (is_int($v)) return $v;
  if (is_numeric($v)) return (int)$v;
  return $default;
}

/* ------------------------------------------------------------
   Route-key (noen sider bruker ?pg=..., andre ?page=...)
------------------------------------------------------------ */
$routeKey = isset($_GET['pg']) ? 'pg' : 'page';

/* ------------------------------------------------------------
   Base URL
------------------------------------------------------------ */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = (string)($_SERVER['HTTP_HOST'] ?? '');
$baseUrl = ($host !== '') ? ($scheme . '://' . $host) : '';

/* ------------------------------------------------------------
   Jira config (les fra .env via App\Support\Env)
------------------------------------------------------------ */
\App\Support\Env::load();
$JIRA_SITE              = (string)\App\Support\Env::get('JIRA_BASE_URL',              'https://hkraft.atlassian.net');
$JIRA_EMAIL             = (string)\App\Support\Env::get('JIRA_USER_EMAIL',            '');
$JIRA_API_TOKEN         = (string)\App\Support\Env::get('JIRA_API_TOKEN',             '');
$JIRA_PROJECT_KEY       = (string)\App\Support\Env::get('JIRA_PROJECT_KEY',           '');
$JIRA_ISSUE_TYPE_INC    = (string)\App\Support\Env::get('JIRA_ISSUE_TYPE_INCIDENT',   'Hendelse');
$JIRA_ISSUE_TYPE_PLAN   = (string)\App\Support\Env::get('JIRA_ISSUE_TYPE_PLANNED',    'Endringsordre med godkjenning');

/* ------------------------------------------------------------
   Address API (BestillFiber) config
------------------------------------------------------------ */
$ADDRESS_API_BASE  = defined('EVENTS_ADDRESS_API_BASE')
  ? (string)EVENTS_ADDRESS_API_BASE
  : 'https://bestillfiber.no/api/adresses/';

$ADDRESS_API_TOKEN = defined('EVENTS_ADDRESS_API_TOKEN')
  ? (string)EVENTS_ADDRESS_API_TOKEN
  : '7de5c78d4b3cf62285b4a71864da64464c4526c1694c7da16a45c0209c61ba38';

/* ------------------------------------------------------------
   Mini-migrering (fail-soft)
------------------------------------------------------------ */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS event_integrations (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      event_id INT NOT NULL,
      `system` VARCHAR(32) NOT NULL,
      external_id VARCHAR(64) NULL,
      external_url VARCHAR(255) NULL,
      sync_status VARCHAR(32) NOT NULL DEFAULT 'not_linked',
      last_error TEXT NULL,
      meta_json JSON NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_event_system (event_id, `system`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {}

try {
  if (table_exists($pdo, 'event_integrations')) {
    if (!col_exists($pdo, 'event_integrations', 'external_id'))  { $pdo->exec("ALTER TABLE event_integrations ADD COLUMN external_id VARCHAR(64) NULL"); }
    if (!col_exists($pdo, 'event_integrations', 'external_url')) { $pdo->exec("ALTER TABLE event_integrations ADD COLUMN external_url VARCHAR(255) NULL"); }
    if (!col_exists($pdo, 'event_integrations', 'sync_status'))  { $pdo->exec("ALTER TABLE event_integrations ADD COLUMN sync_status VARCHAR(32) NOT NULL DEFAULT 'not_linked'"); }
    if (!col_exists($pdo, 'event_integrations', 'last_error'))   { $pdo->exec("ALTER TABLE event_integrations ADD COLUMN last_error TEXT NULL"); }
    if (!col_exists($pdo, 'event_integrations', 'updated_at'))   { $pdo->exec("ALTER TABLE event_integrations ADD COLUMN updated_at DATETIME NULL"); }

    try {
      $pdo->exec("ALTER TABLE event_integrations MODIFY COLUMN sync_status VARCHAR(32) NOT NULL DEFAULT 'not_linked'");
    } catch (Throwable $e2) {}

    if (!col_exists($pdo, 'event_integrations', 'meta_json')) {
      try { $pdo->exec("ALTER TABLE event_integrations ADD COLUMN meta_json JSON NULL"); }
      catch (Throwable $e2) { try { $pdo->exec("ALTER TABLE event_integrations ADD COLUMN meta_json LONGTEXT NULL"); } catch (Throwable $e3) {} }
    }
  }
} catch (Throwable $e) {}

try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS event_updates (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      event_id INT NOT NULL,
      visibility VARCHAR(16) NOT NULL DEFAULT 'public',
      message TEXT NOT NULL,
      created_by VARCHAR(80) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_event (event_id),
      KEY idx_vis (visibility)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {}

try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS event_targets (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      event_id INT NOT NULL,
      target_type VARCHAR(32) NOT NULL,
      target_value VARCHAR(255) NOT NULL,
      is_exclude TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_event (event_id),
      KEY idx_type (target_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {}

try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS event_affected_addresses (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      event_id INT NOT NULL,
      leveransepunkt_id VARCHAR(64) NOT NULL,
      address_id BIGINT NULL,
      street VARCHAR(190) NULL,
      house_number VARCHAR(32) NULL,
      house_letter VARCHAR(16) NULL,
      postal_code VARCHAR(16) NULL,
      city VARCHAR(80) NULL,
      lat DECIMAL(10,7) NULL,
      lng DECIMAL(10,7) NULL,
      raw_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_event_lp_addr (event_id, leveransepunkt_id, address_id),
      KEY idx_event (event_id),
      KEY idx_lp (leveransepunkt_id),
      KEY idx_city (city),
      KEY idx_postal (postal_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {}

try {
  if (table_exists($pdo, 'events')) {
    if (!col_exists($pdo, 'events', 'available_kundesenter')) { $pdo->exec("ALTER TABLE events ADD COLUMN available_kundesenter TINYINT(1) NOT NULL DEFAULT 0"); }
    if (!col_exists($pdo, 'events', 'available_chat'))       { $pdo->exec("ALTER TABLE events ADD COLUMN available_chat TINYINT(1) NOT NULL DEFAULT 0"); }
    if (!col_exists($pdo, 'events', 'available_jira'))       { $pdo->exec("ALTER TABLE events ADD COLUMN available_jira TINYINT(1) NOT NULL DEFAULT 0"); }

    if (!col_exists($pdo, 'events', 'customer_impact'))      { $pdo->exec("ALTER TABLE events ADD COLUMN customer_impact TINYINT(1) NOT NULL DEFAULT 0"); }
    if (!col_exists($pdo, 'events', 'affected_customers'))   { $pdo->exec("ALTER TABLE events ADD COLUMN affected_customers INT NOT NULL DEFAULT 0"); }

    if (!col_exists($pdo, 'events', 'severity')) {
      $pdo->exec("ALTER TABLE events ADD COLUMN severity VARCHAR(32) NULL");
    } else {
      try { $pdo->exec("ALTER TABLE events MODIFY COLUMN severity VARCHAR(32) NULL"); } catch (Throwable $e2) {}
    }
  }
} catch (Throwable $e) {}

/* ------------------------------------------------------------
   Permissions
------------------------------------------------------------ */
$username = (string)($_SESSION['username'] ?? $_SESSION['user'] ?? '');
$userId   = (int)($_SESSION['user_id'] ?? 0);
$isAdmin  = user_is_admin($pdo, $userId);

$canRead    = $isAdmin || ($userId > 0 && user_has_role($pdo, $userId, 'events_read'));
$canWrite   = $isAdmin || ($userId > 0 && user_has_role($pdo, $userId, 'events_write'));
$canPublish = $isAdmin || ($userId > 0 && user_has_role($pdo, $userId, 'events_publish'));

if (!$canRead) {
  http_response_code(403);
  echo '<div class="alert alert-danger mt-3">Du har ikke tilgang (events_read).</div>';
  return;
}

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
   Loaders
------------------------------------------------------------ */
function load_event(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
function load_updates(PDO $pdo, int $id): array {
  try {
    $st = $pdo->prepare("SELECT * FROM event_updates WHERE event_id=? ORDER BY created_at DESC, id DESC LIMIT 200");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { return []; }
}
function load_jira(PDO $pdo, int $id): array {
  $jira = ['sync_status' => 'not_linked', 'external_id' => '', 'external_url' => ''];
  try {
    $st = $pdo->prepare("SELECT * FROM event_integrations WHERE event_id=? AND `system`='jira' LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  } catch (Throwable $e) {}
  return $jira;
}

function load_affected_addresses(PDO $pdo, int $eventId, int $limit = 250): array {
  try {
    $st = $pdo->prepare("
      SELECT *
        FROM event_affected_addresses
       WHERE event_id=?
       ORDER BY created_at DESC, id DESC
       LIMIT ?
    ");
    $st->bindValue(1, $eventId, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { return []; }
}
function count_affected_addresses(PDO $pdo, int $eventId): int {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM event_affected_addresses WHERE event_id=?");
    $st->execute([$eventId]);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) { return 0; }
}
function count_affected_leveransepunkt(PDO $pdo, int $eventId): int {
  try {
    $st = $pdo->prepare("SELECT COUNT(DISTINCT leveransepunkt_id) FROM event_affected_addresses WHERE event_id=?");
    $st->execute([$eventId]);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) { return 0; }
}

/* ------------------------------------------------------------
   Render affected HTML fragment (server-side) for AJAX refresh
------------------------------------------------------------ */
function render_affected_rows_html(array $affectedRows, bool $canWrite, string $csrf): string
{
  ob_start();
  if ($affectedRows): ?>
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0">
        <thead>
          <tr>
            <th>Leveransepunkt</th>
            <th>Adresse</th>
            <th>Postnr/sted</th>
            <th class="text-muted">Lat/Lng</th>
            <th class="text-muted">Tid</th>
            <?php if ($canWrite): ?><th class="text-end">Slett</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($affectedRows as $r): ?>
            <?php
              $rowId = (int)($r['id'] ?? 0);
              $addr = trim((string)($r['street'] ?? ''));
              $hn = trim((string)($r['house_number'] ?? ''));
              $hl = trim((string)($r['house_letter'] ?? ''));
              $addrLine = $addr;
              if ($hn !== '') $addrLine .= ' ' . $hn;
              if ($hl !== '') $addrLine .= $hl;
              $pc = (string)($r['postal_code'] ?? '');
              $city = (string)($r['city'] ?? '');
              $lat = $r['lat'] ?? null;
              $lng = $r['lng'] ?? null;
            ?>
            <tr>
              <td><code><?= esc((string)$r['leveransepunkt_id']) ?></code></td>
              <td><?= esc($addrLine !== '' ? $addrLine : '-') ?></td>
              <td><?= esc(trim($pc . ' ' . $city)) ?></td>
              <td class="text-muted small"><?= esc(($lat !== null ? (string)$lat : '-') . ' / ' . ($lng !== null ? (string)$lng : '-')) ?></td>
              <td class="text-muted small"><?= esc(fmt_dt_out((string)($r['created_at'] ?? ''))) ?></td>
              <?php if ($canWrite): ?>
                <td class="text-end">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-danger js-aa-delete"
                    data-aa-id="<?= (int)$rowId ?>"
                    title="Slett denne raden"
                  >Slett</button>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="text-muted">Ingen berørte adresser lagret på denne saken ennå.</div>
  <?php endif;
  return (string)ob_get_clean();
}

/* ------------------------------------------------------------
   Ensure Jira integration row exists
------------------------------------------------------------ */
safe_stmt($pdo, "INSERT IGNORE INTO event_integrations(event_id, `system`, sync_status) VALUES(?, 'jira', 'not_linked')", [$id]);

$event = load_event($pdo, $id);
if (!$event) { echo '<div class="alert alert-danger mt-3">Fant ikke saken.</div>'; return; }

$updates = load_updates($pdo, $id);
$jira    = load_jira($pdo, $id);

/* ------------------------------------------------------------
   Jira helpers
------------------------------------------------------------ */
function jira_basic_auth_header(string $email, string $apiToken): string {
  return 'Authorization: Basic ' . base64_encode($email . ':' . $apiToken);
}
function jira_adf_doc_from_text(string $text): array {
  $lines = preg_split("/\r\n|\n|\r/", trim($text));
  $content = [];
  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln === '') continue;
    $content[] = [
      'type' => 'paragraph',
      'content' => [
        ['type' => 'text', 'text' => $ln]
      ]
    ];
  }
  if (!$content) $content[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]];
  return ['type' => 'doc', 'version' => 1, 'content' => $content];
}
function jira_http_json(string $method, string $url, array $headers, ?array $payload, int &$httpCode, string &$rawOut): ?array {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

  $h = $headers;
  $h[] = 'Accept: application/json';
  if ($payload !== null) {
    $h[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }
  curl_setopt($ch, CURLOPT_HTTPHEADER, $h);

  $out = curl_exec($ch);
  $rawOut = is_string($out) ? $out : '';
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);

  // Midlertidig Jira-debug logging for feilsøking mot support
  error_log('Jira HTTP ' . $httpCode . ': ' . ($rawOut !== '' ? $rawOut : '[empty response]'));
  if ($out === false) {
    error_log('Jira cURL error: ' . ($err !== '' ? $err : 'unknown_error'));
  }

  curl_close($ch);

  if ($out === false) { $httpCode = 0; return ['_curl_error' => $err ?: 'unknown_error']; }
  $decoded = json_decode($rawOut, true);
  return is_array($decoded) ? $decoded : null;
}

/* ------------------------------------------------------------
   Address API helper (server-side)
------------------------------------------------------------ */
function address_api_lookup_leveransepunkt(string $baseUrl, string $bearerToken, string $leveransepunktId, int &$httpCode, string &$raw): ?array
{
  $httpCode = 0;
  $raw = '';

  $lp = trim($leveransepunktId);
  if ($lp === '') return null;

  $url = rtrim($baseUrl, '/') . '/?leveransepunkt_id=' . rawurlencode($lp);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $bearerToken,
  ]);

  $out = curl_exec($ch);
  $raw = is_string($out) ? $out : '';
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($out === false) { $httpCode = 0; return ['_curl_error' => $err ?: 'unknown_error']; }

  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : null;
}

function affected_store_items(PDO $pdo, int $eventId, string $leveransepunktId, array $items, array $fullPayload): int
{
  $saved = 0;
  if (!$items) return 0;

  $rawJson = json_encode($fullPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $sql = "
    INSERT INTO event_affected_addresses
      (event_id, leveransepunkt_id, address_id, street, house_number, house_letter, postal_code, city, lat, lng, raw_json)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      street=VALUES(street),
      house_number=VALUES(house_number),
      house_letter=VALUES(house_letter),
      postal_code=VALUES(postal_code),
      city=VALUES(city),
      lat=VALUES(lat),
      lng=VALUES(lng),
      raw_json=VALUES(raw_json)
  ";
  $st = $pdo->prepare($sql);

  foreach ($items as $it) {
    $addrId = isset($it['id']) ? (int)$it['id'] : null;

    $street = isset($it['street']) ? (string)$it['street'] : null;
    $hn     = isset($it['house_number']) ? (string)$it['house_number'] : null;
    $hl     = isset($it['house_letter']) ? (string)$it['house_letter'] : null;
    $pc     = isset($it['postal_code']) ? (string)$it['postal_code'] : null;
    $city   = isset($it['city']) ? (string)$it['city'] : null;

    $lat = null; $lng = null;
    if (isset($it['lat']) && $it['lat'] !== null && $it['lat'] !== '') $lat = (float)$it['lat'];
    if (isset($it['lng']) && $it['lng'] !== null && $it['lng'] !== '') $lng = (float)$it['lng'];

    try {
      $st->execute([
        $eventId, $leveransepunktId, $addrId, $street, $hn, $hl, $pc, $city, $lat, $lng, $rawJson
      ]);
      $saved++;
    } catch (Throwable $e) {
    }
  }

  return $saved;
}

function affected_recalc_event(PDO $pdo, int $eventId, string $updatedBy): void
{
  $cnt = 0;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM event_affected_addresses WHERE event_id=?");
    $st->execute([$eventId]);
    $cnt = (int)$st->fetchColumn();
  } catch (Throwable $e) { $cnt = 0; }

  $impact = ($cnt > 0) ? 1 : 0;

  try {
    $pdo->prepare("UPDATE events SET customer_impact=?, affected_customers=?, updated_by=? WHERE id=?")
        ->execute([$impact, $cnt, $updatedBy, $eventId]);
  } catch (Throwable $e) {}
}

/* ------------------------------------------------------------
   AJAX: leveransepunkt lookup
------------------------------------------------------------ */
if (($_GET['ajax'] ?? '') === 'lp_lookup') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $out = function(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  };

  if (!$canWrite) $out(403, ['ok' => false, 'error' => 'no_access']);
  $postedCsrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $postedCsrf)) $out(400, ['ok' => false, 'error' => 'csrf']);

  $lp = trim((string)($_POST['leveransepunkt_id'] ?? ''));
  if ($lp === '') $out(400, ['ok' => false, 'error' => 'missing_leveransepunkt_id']);

  $http = 0; $raw = '';
  $resp = address_api_lookup_leveransepunkt($ADDRESS_API_BASE, $ADDRESS_API_TOKEN, $lp, $http, $raw);

  if ($http === 401 || $http === 403) $out(200, ['ok' => false, 'http' => $http, 'error' => 'unauthorized']);
  if ($http < 200 || $http >= 300 || !is_array($resp)) {
    $out(200, ['ok' => false, 'http' => $http, 'error' => 'api_error', 'raw' => mb_substr($raw, 0, 500)]);
  }

  $items = [];
  if (isset($resp['items']) && is_array($resp['items'])) $items = $resp['items'];

  $saved = 0;
  try { $saved = affected_store_items($pdo, $id, $lp, $items, $resp); } catch (Throwable $e) {}

  affected_recalc_event($pdo, $id, $username);

  $out(200, [
    'ok' => true,
    'http' => $http,
    'leveransepunkt_id' => $lp,
    'match_count' => count($items),
    'saved_rows' => $saved,
    'event_affected_total' => count_affected_addresses($pdo, $id),
    'event_affected_lp_total' => count_affected_leveransepunkt($pdo, $id),
  ]);
}

/* ------------------------------------------------------------
   AJAX: refresh affected table fragment
------------------------------------------------------------ */
if (($_GET['ajax'] ?? '') === 'affected_fragment') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $out = function(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  };

  if (!$canRead) $out(403, ['ok' => false, 'error' => 'no_access']);

  $rows = load_affected_addresses($pdo, $id, 250);
  $tot  = count_affected_addresses($pdo, $id);
  $lpt  = count_affected_leveransepunkt($pdo, $id);

  $out(200, [
    'ok' => true,
    'event_affected_total' => $tot,
    'event_affected_lp_total' => $lpt,
    'html' => render_affected_rows_html($rows, (bool)$canWrite, (string)$csrf),
  ]);
}

/* ------------------------------------------------------------
   AJAX: delete one affected row
------------------------------------------------------------ */
if (($_GET['ajax'] ?? '') === 'affected_delete') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $out = function(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  };

  if (!$canWrite) $out(403, ['ok' => false, 'error' => 'no_access']);
  $postedCsrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $postedCsrf)) $out(400, ['ok' => false, 'error' => 'csrf']);

  $rowId = (int)($_POST['row_id'] ?? 0);
  if ($rowId <= 0) $out(400, ['ok' => false, 'error' => 'missing_row_id']);

  try {
    $st = $pdo->prepare("DELETE FROM event_affected_addresses WHERE id=? AND event_id=?");
    $st->execute([$rowId, $id]);
    if ($st->rowCount() < 1) $out(200, ['ok' => false, 'error' => 'not_found']);
  } catch (Throwable $e) {
    $out(200, ['ok' => false, 'error' => 'delete_failed']);
  }

  affected_recalc_event($pdo, $id, $username);

  $rows = load_affected_addresses($pdo, $id, 250);
  $tot  = count_affected_addresses($pdo, $id);
  $lpt  = count_affected_leveransepunkt($pdo, $id);

  $out(200, [
    'ok' => true,
    'event_affected_total' => $tot,
    'event_affected_lp_total' => $lpt,
    'html' => render_affected_rows_html($rows, (bool)$canWrite, (string)$csrf),
  ]);
}

/* ------------------------------------------------------------
   AJAX: delete ALL affected rows
------------------------------------------------------------ */
if (($_GET['ajax'] ?? '') === 'affected_delete_all') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $out = function(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  };

  if (!$canWrite) $out(403, ['ok' => false, 'error' => 'no_access']);
  $postedCsrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $postedCsrf)) $out(400, ['ok' => false, 'error' => 'csrf']);

  try {
    $st = $pdo->prepare("DELETE FROM event_affected_addresses WHERE event_id=?");
    $st->execute([$id]);
  } catch (Throwable $e) {
    $out(200, ['ok' => false, 'error' => 'delete_failed']);
  }

  affected_recalc_event($pdo, $id, $username);

  $rows = load_affected_addresses($pdo, $id, 250);
  $tot  = count_affected_addresses($pdo, $id);
  $lpt  = count_affected_leveransepunkt($pdo, $id);

  $out(200, [
    'ok' => true,
    'event_affected_total' => $tot,
    'event_affected_lp_total' => $lpt,
    'html' => render_affected_rows_html($rows, (bool)$canWrite, (string)$csrf),
  ]);
}

/* ------------------------------------------------------------
   Actions (POST)
------------------------------------------------------------ */
$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedCsrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($csrf, $postedCsrf)) {
    $err = 'Ugyldig CSRF-token.';
  } else {
    try {

      if ($action === 'delete_event' && $isAdmin) {
        $pdo->beginTransaction();
        try {
          $pdo->prepare("DELETE FROM event_updates WHERE event_id=?")->execute([$id]);
          $pdo->prepare("DELETE FROM event_targets WHERE event_id=?")->execute([$id]);
          $pdo->prepare("DELETE FROM event_affected_addresses WHERE event_id=?")->execute([$id]);
          $pdo->prepare("DELETE FROM event_integrations WHERE event_id=?")->execute([$id]);
          $pdo->prepare("DELETE FROM events WHERE id=?")->execute([$id]);
          $pdo->commit();

          header('Location: /?' . $routeKey . '=events&deleted=1');
          exit;
        } catch (Throwable $e) {
          $pdo->rollBack();
          $err = 'Kunne ikke slette saken: ' . $e->getMessage();
        }
      }

      if ($action === 'save_all' && ($canWrite || $canPublish)) {

        if ($canWrite) {
          $type = (string)($_POST['type'] ?? $event['type']);
          if (!in_array($type, ['incident','planned'], true)) $type = (string)$event['type'];

          $status = (string)($_POST['status'] ?? $event['status']);
          $allowedStatus = ['scheduled','in_progress','resolved','draft','monitoring','cancelled'];
          if (!in_array($status, $allowedStatus, true)) $status = (string)$event['status'];

          $title_public = trim((string)($_POST['title_public'] ?? ''));
          if ($title_public === '') $title_public = (string)($event['title_public'] ?? '');

          $summary_public   = trim((string)($_POST['summary_public'] ?? ''));
          $customer_actions = trim((string)($_POST['customer_actions'] ?? ''));

          $customer_impact = isset($_POST['customer_impact']) ? 1 : 0;
          $affected_customers = as_int($_POST['affected_customers'] ?? 0, 0);
          if ($affected_customers < 0) $affected_customers = 0;

          $severity = (string)($_POST['severity'] ?? '');
          $allowedSev = ['', 'none','minor','moderate','major','critical'];
          if (!in_array($severity, $allowedSev, true)) $severity = '';

          $schedule_start = trim((string)($_POST['schedule_start'] ?? ''));
          $schedule_end   = trim((string)($_POST['schedule_end'] ?? ''));

          $actual_start   = trim((string)($_POST['actual_start'] ?? ''));
          $actual_end     = trim((string)($_POST['actual_end'] ?? ''));
          $next_update_eta = trim((string)($_POST['next_update_eta'] ?? ''));

          if ($type === 'incident') {
            $schedule_start = '';
            $schedule_end   = '';
          }

          $schedule_start  = $schedule_start !== '' ? date('Y-m-d H:i:s', strtotime($schedule_start)) : null;
          $schedule_end    = $schedule_end !== '' ? date('Y-m-d H:i:s', strtotime($schedule_end)) : null;
          $actual_start    = $actual_start !== '' ? date('Y-m-d H:i:s', strtotime($actual_start)) : null;
          $actual_end      = $actual_end !== '' ? date('Y-m-d H:i:s', strtotime($actual_end)) : null;
          $next_update_eta = $next_update_eta !== '' ? date('Y-m-d H:i:s', strtotime($next_update_eta)) : null;

          $pdo->prepare("
            UPDATE events SET
              type=?,
              status=?,
              title_public=?,
              summary_public=?,
              customer_actions=?,
              customer_impact=?,
              affected_customers=?,
              severity=?,
              schedule_start=?,
              schedule_end=?,
              actual_start=?,
              actual_end=?,
              next_update_eta=?,
              updated_by=?
            WHERE id=?
          ")->execute([
            $type,
            $status,
            $title_public,
            $summary_public !== '' ? $summary_public : null,
            $customer_actions !== '' ? $customer_actions : null,
            $customer_impact,
            $affected_customers,
            $severity !== '' ? $severity : null,
            $schedule_start,
            $schedule_end,
            $actual_start,
            $actual_end,
            $next_update_eta,
            $username,
            $id
          ]);

          $jira_issue_key = strtoupper(trim((string)($_POST['jira_issue_key'] ?? '')));
          $jira_system_pick = (string)($_POST['jira_issuetype_pick'] ?? 'jira');
          if (!in_array($jira_system_pick, ['jira','other'], true)) $jira_system_pick = 'jira';

          if ($jira_issue_key !== '') {
            $jira_issue_key = preg_replace('/[^A-Z0-9\-]/', '', $jira_issue_key) ?? $jira_issue_key;
            $browse = rtrim($JIRA_SITE, '/') . '/browse/' . $jira_issue_key;

            $meta = [
              'issuetype_pick' => $jira_system_pick,
            ];

            $pdo->prepare("
              UPDATE event_integrations
                 SET external_id=?, external_url=?, sync_status='linked', last_error=NULL, meta_json=?, updated_at=NOW()
               WHERE event_id=? AND `system`='jira'
            ")->execute([
              $jira_issue_key,
              $browse,
              json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
              $id
            ]);
          } else {
            $meta = [
              'issuetype_pick' => $jira_system_pick,
            ];
            safe_stmt($pdo, "
              UPDATE event_integrations
                 SET meta_json=?, updated_at=NOW()
               WHERE event_id=? AND `system`='jira'
            ", [
              json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
              $id
            ]);
          }
        }

        if ($canPublish) {
          $available_kundesenter  = isset($_POST['available_kundesenter']) ? 1 : 0;
          $available_hkon         = isset($_POST['available_hkon']) ? 1 : 0;
          $available_dashboard    = isset($_POST['available_dashboard']) ? 1 : 0;

          $pdo->prepare("
            UPDATE events SET
              available_kundesenter  = ?,
              published_to_chatbot   = ?,
              available_chat         = ?,
              is_public              = ?,
              published_to_dashboard = ?,
              updated_by             = ?
            WHERE id = ?
          ")->execute([
            $available_kundesenter,
            $available_hkon,
            $available_hkon,
            $available_hkon,
            $available_dashboard,
            $username,
            $id
          ]);
        }

        $ok = 'Lagret.';
      }

      if ($action === 'add_update' && $canWrite) {
        $visibility = (string)($_POST['visibility'] ?? 'public');
        if (!in_array($visibility, ['public','internal'], true)) $visibility = 'public';
        $message = trim((string)($_POST['message'] ?? ''));
        if ($message === '') {
          $err = 'Melding kan ikke være tom.';
        } else {
          $pdo->prepare("INSERT INTO event_updates(event_id, visibility, message, created_by) VALUES(?, ?, ?, ?)")
              ->execute([$id, $visibility, $message, $username]);
          $ok = 'Oppdatering lagt til.';
        }
      }

      if ($action === 'create_jira' && $canWrite) {
        $jiraRow = load_jira($pdo, $id);
        $alreadyKey = (string)($jiraRow['external_id'] ?? '');
        if ($alreadyKey !== '') {
          $ok = 'Saken er allerede koblet til Jira (' . $alreadyKey . ').';
        } else {
          $meta = [];
          try {
            $mj = (string)($jiraRow['meta_json'] ?? '');
            if ($mj !== '') { $d = json_decode($mj, true); if (is_array($d)) $meta = $d; }
          } catch (Throwable $e) {}

          $pick = (string)($meta['issuetype_pick'] ?? 'jira');
          if ($pick !== 'jira') {
            $err = 'Sakssystem er satt til "Annet". Kan ikke opprette i Jira.';
          } elseif ($JIRA_SITE === '' || $JIRA_EMAIL === '' || $JIRA_API_TOKEN === '' || $JIRA_PROJECT_KEY === '') {
            $err = 'Jira-credentials mangler i config (JIRA_SITE/JIRA_EMAIL/JIRA_API_TOKEN/JIRA_PROJECT_KEY).';
          } else {

            $kind = ((string)$event['type'] === 'planned') ? 'Endring' : 'Hendelse';
            $stMap = (string)($event['status'] ?? '');
            $statusNo = $stMap;
            if ($stMap === 'scheduled') $statusNo = 'Planlagt';
            else if ($stMap === 'in_progress') $statusNo = 'Pågående';
            else if ($stMap === 'resolved') $statusNo = 'Utført';

            $impactYesNo = ((int)($event['customer_impact'] ?? 0) === 1) ? 'Ja' : 'Nei';
            $affected = (int)($event['affected_customers'] ?? 0);

            $linkBack = '';
            if ($baseUrl !== '') $linkBack = $baseUrl . '/?' . $GLOBALS['routeKey'] . '=events_view&id=' . (int)$event['id'];

            $descLines = [
              $kind . ' registrert i Teknisk Side (varsling/kommunikasjon). Saksbehandling skjer i Jira.',
              'Status: ' . $statusNo,
              'Påvirkning på kunder: ' . $impactYesNo,
              'Antall berørte kunder: ' . $affected,
            ];

            if (!empty($event['schedule_start'])) $descLines[] = 'Planlagt start: ' . fmt_dt_out((string)$event['schedule_start']);
            if (!empty($event['schedule_end']))   $descLines[] = 'Planlagt slutt: ' . fmt_dt_out((string)$event['schedule_end']);
            if (!empty($event['actual_start']))   $descLines[] = 'Faktisk start: ' . fmt_dt_out((string)$event['actual_start']);
            if (!empty($event['actual_end']))     $descLines[] = 'Faktisk slutt: ' . fmt_dt_out((string)$event['actual_end']);

            $publicSummary = trim((string)($event['summary_public'] ?? ''));
            if ($publicSummary !== '') {
              $descLines[] = '';
              $descLines[] = 'Sammendrag:';
              $descLines[] = $publicSummary;
            }

            if ($linkBack !== '') {
              $descLines[] = '';
              $descLines[] = 'Lenke til registrering i Teknisk Side:';
              $descLines[] = $linkBack;
            }

            $jiraIssueType = ((string)$event['type'] === 'planned') ? $JIRA_ISSUE_TYPE_PLAN : $JIRA_ISSUE_TYPE_INC;

            $payload = [
              'fields' => [
                'project' => ['key' => $JIRA_PROJECT_KEY],
                'summary' => (string)($event['title_public'] ?? 'Sak fra Teknisk Side'),
                'description' => jira_adf_doc_from_text(implode("\n", $descLines)),
                'issuetype' => ['name' => $jiraIssueType],
              ]
            ];

            $url = rtrim($JIRA_SITE, '/') . '/rest/api/3/issue';
            $http = 0; $raw = '';
            $resp = jira_http_json('POST', $url, [jira_basic_auth_header($JIRA_EMAIL, $JIRA_API_TOKEN)], $payload, $http, $raw);

            if ($http >= 200 && $http < 300 && is_array($resp) && !empty($resp['key'])) {
              $key = (string)$resp['key'];
              $browse = rtrim($JIRA_SITE, '/') . '/browse/' . $key;

              $pdo->prepare("
                UPDATE event_integrations
                   SET external_id=?, external_url=?, sync_status='linked', last_error=NULL, updated_at=NOW()
                 WHERE event_id=? AND `system`='jira'
              ")->execute([$key, $browse, $id]);

              $ok = 'Opprettet i Jira: ' . $key;
            } else {
              $msg = 'Jira-feil (' . $http . ').';
              if (is_array($resp) && isset($resp['errorMessages'])) {
                $msg .= ' ' . implode(' | ', array_map('strval', (array)$resp['errorMessages']));
              } else if (is_array($resp) && isset($resp['_curl_error'])) {
                $msg .= ' ' . (string)$resp['_curl_error'];
              } else if ($raw !== '') {
                $msg .= ' ' . mb_substr($raw, 0, 800);
              }

              $pdo->prepare("
                UPDATE event_integrations
                   SET sync_status='error', last_error=?, updated_at=NOW()
                 WHERE event_id=? AND `system`='jira'
              ")->execute([$msg, $id]);

              $err = $msg;
            }
          }
        }
      }

    } catch (Throwable $e) {
      $err = 'Feil: ' . $e->getMessage();
    }
  }

  $event   = load_event($pdo, $id) ?: $event;
  $updates = load_updates($pdo, $id);
  $jira    = load_jira($pdo, $id);
}

/* ------------------------------------------------------------
   Derived labels
------------------------------------------------------------ */
$typeLabel = ((string)$event['type'] === 'planned') ? 'Endring' : 'Hendelse';
$typeBadge = ((string)$event['type'] === 'planned') ? 'primary' : 'danger';

$statusRaw = (string)($event['status'] ?? '');
$statusLabel = match($statusRaw) {
  'draft'       => 'Utkast',
  'scheduled'   => 'Planlagt',
  'in_progress' => 'Pågår',
  'monitoring'  => 'Overvåker',
  'resolved'    => 'Utført',
  'cancelled'   => 'Avbrutt',
  default       => $statusRaw,
};
$statusBadge = match($statusRaw) {
  'draft'       => 'secondary',
  'scheduled'   => 'info',
  'in_progress' => 'danger',
  'monitoring'  => 'warning',
  'resolved'    => 'success',
  'cancelled'   => 'dark',
  default       => 'secondary',
};

$impactYesNo = ((int)($event['customer_impact'] ?? 0) === 1) ? 'Ja' : 'Nei';
$affected = (int)($event['affected_customers'] ?? 0);

$jiraKey = (string)($jira['external_id'] ?? '');
$jiraUrl = (string)($jira['external_url'] ?? '');

$meta = [];
try {
  $mj = (string)($jira['meta_json'] ?? '');
  if ($mj !== '') { $d = json_decode($mj, true); if (is_array($d)) $meta = $d; }
} catch (Throwable $e) {}
$jiraPick = (string)($meta['issuetype_pick'] ?? 'jira');
if (!in_array($jiraPick, ['jira','other'], true)) $jiraPick = 'jira';

$sevLabelMap = [
  'none' => 'Ingen',
  'minor' => 'Mindre / Lokalisert',
  'moderate' => 'Moderat / Begrenset',
  'major' => 'Betydlig / Stor',
  'critical' => 'Kritisk / Globalt',
];
$sevBadgeText = '';
if (!empty($event['severity'])) $sevBadgeText = $sevLabelMap[(string)$event['severity']] ?? (string)$event['severity'];

$affectedRows = load_affected_addresses($pdo, $id, 250);
$affectedAddrTotal = count_affected_addresses($pdo, $id);
$affectedLpTotal = count_affected_leveransepunkt($pdo, $id);

$mapUrl = '/?' . $routeKey . '=events_map&id=' . (int)$id;

?>
<!-- Sideheader -->
<div class="mb-3">
  <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
    <div style="min-width:0;">
      <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
        <a href="/?<?= esc($routeKey) ?>=events" class="text-muted small text-decoration-none">
          <i class="bi bi-arrow-left me-1"></i>Hendelser
        </a>
        <span class="text-muted small">/</span>
        <span class="text-muted small">Sak #<?= (int)$event['id'] ?></span>
      </div>
      <h4 class="mb-1 fw-semibold" style="word-break:break-word;"><?= esc((string)$event['title_public']) ?></h4>
      <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
        <span class="badge text-bg-<?= $statusBadge ?>"><?= esc($statusLabel) ?></span>
        <span class="badge text-bg-<?= $typeBadge ?>"><?= esc($typeLabel) ?></span>
        <?php if ($sevBadgeText !== ''): ?>
          <span class="badge text-bg-secondary"><?= esc($sevBadgeText) ?></span>
        <?php endif; ?>
        <?php if ($affected > 0): ?>
          <span class="badge text-bg-dark"><i class="bi bi-people me-1"></i><?= (int)$affected ?> kunder</span>
        <?php endif; ?>
        <?php if ($jiraKey !== ''): ?>
          <a class="badge text-bg-success text-decoration-none"
             href="<?= esc(rtrim($JIRA_SITE, '/') . '/browse/' . $jiraKey) ?>"
             target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right me-1"></i><?= esc($jiraKey) ?>
          </a>
        <?php endif; ?>
        <span class="text-muted small ms-1">
          Oppdatert <?= esc(fmt_dt_out((string)($event['updated_at'] ?? ''))) ?>
        </span>
      </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
      <a class="btn btn-sm btn-outline-secondary" href="<?= esc($mapUrl) ?>">
        <i class="bi bi-map me-1"></i>Kart
      </a>
      <?php if ($jiraKey !== ''): ?>
        <a class="btn btn-sm btn-outline-primary"
           href="<?= esc(rtrim($JIRA_SITE, '/') . '/browse/' . $jiraKey) ?>"
           target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right me-1"></i>Åpne i Jira
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($err !== ''): ?><div class="alert alert-danger"><?= esc($err) ?></div><?php endif; ?>
<?php if ($ok !== ''): ?><div class="alert alert-success"><?= esc($ok) ?></div><?php endif; ?>

<script>
(function(){
  function applyTypeVisibility() {
    var typeSel = document.querySelector('select[name="type"]');
    if (!typeSel) return;
    var v = typeSel.value;

    document.querySelectorAll('[data-only-planned="1"]').forEach(function(el){
      el.style.display = (v === 'planned') ? '' : 'none';
    });
    document.querySelectorAll('[data-only-incident="1"]').forEach(function(el){
      el.style.display = (v === 'incident') ? '' : 'none';
    });
  }

  document.addEventListener('change', function(e){
    if (e.target && e.target.matches('select[name="type"]')) applyTypeVisibility();
  });

  document.addEventListener('DOMContentLoaded', applyTypeVisibility);
})();
</script>

<form method="post" class="card mb-3">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold">Detaljer</span>
    <span class="text-muted small">Saksbehandling skjer i Jira</span>
  </div>

  <div class="card-body">
    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
    <input type="hidden" name="action" value="save_all">

    <div class="row g-3">

      <div class="col-12 col-md-4">
        <label class="form-label">Type</label>
        <select class="form-select" name="type" <?= !$canWrite?'disabled':'' ?>>
          <option value="planned"  <?= (string)$event['type']==='planned'?'selected':'' ?>>Endring</option>
          <option value="incident" <?= (string)$event['type']==='incident'?'selected':'' ?>>Hendelse</option>
        </select>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Status</label>
        <select class="form-select" name="status" <?= !$canWrite?'disabled':'' ?>>
          <option value="scheduled"   <?= (string)$event['status']==='scheduled'?'selected':'' ?>>Planlagt</option>
          <option value="in_progress" <?= (string)$event['status']==='in_progress'?'selected':'' ?>>Pågående</option>
          <option value="resolved"    <?= (string)$event['status']==='resolved'?'selected':'' ?>>Utført</option>
          <option value="draft"       <?= (string)$event['status']==='draft'?'selected':'' ?>>Utkast</option>
          <option value="monitoring"  <?= (string)$event['status']==='monitoring'?'selected':'' ?>>Overvåking</option>
          <option value="cancelled"   <?= (string)$event['status']==='cancelled'?'selected':'' ?>>Avlyst</option>
        </select>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Alvorlighet</label>
        <?php
          $sevVal = (string)($event['severity'] ?? '');
          $sevOptions = [
            '' => '—',
            'none' => 'Ingen',
            'minor' => 'Mindre / Lokalisert',
            'moderate' => 'Moderat / Begrenset',
            'major' => 'Betydlig / Stor',
            'critical' => 'Kritisk / Globalt',
          ];
        ?>
        <select class="form-select" name="severity" <?= !$canWrite?'disabled':'' ?>>
          <?php foreach ($sevOptions as $k => $label): ?>
            <option value="<?= esc($k) ?>" <?= $sevVal === (string)$k ? 'selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-8">
        <label class="form-label">Tittel</label>
        <input class="form-control" name="title_public" value="<?= esc((string)$event['title_public']) ?>" <?= !$canWrite?'disabled':'' ?>>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Påvirkning på kunder</label>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="customer_impact" name="customer_impact"
                 <?= ((int)($event['customer_impact'] ?? 0)===1)?'checked':'' ?> <?= !$canWrite?'disabled':'' ?>>
          <label class="form-check-label" for="customer_impact">Ja</label>
        </div>
        <div class="mt-2">
          <label class="form-label mb-1">Berørte kunder</label>
          <input class="form-control" type="number" min="0" name="affected_customers" value="<?= (int)($event['affected_customers'] ?? 0) ?>" <?= !$canWrite?'disabled':'' ?>>
          <div class="form-text">Oppdateres automatisk ved import av leveransepunkt.</div>
        </div>
      </div>

      <div class="col-12">
        <label class="form-label">Kort sammendrag</label>
        <textarea class="form-control" name="summary_public" rows="3" <?= !$canWrite?'disabled':'' ?>><?= esc((string)($event['summary_public'] ?? '')) ?></textarea>
      </div>

      <div class="col-12">
        <label class="form-label">Hva kan kunden gjøre?</label>
        <textarea class="form-control" name="customer_actions" rows="2" <?= !$canWrite?'disabled':'' ?>><?= esc((string)($event['customer_actions'] ?? '')) ?></textarea>
      </div>

      <div class="col-12 col-md-6" data-only-planned="1">
        <label class="form-label">Planlagt start</label>
        <input type="datetime-local" class="form-control" name="schedule_start" value="<?= esc(fmt_dt_in((string)($event['schedule_start'] ?? ''))) ?>" <?= !$canWrite?'disabled':'' ?>>
      </div>
      <div class="col-12 col-md-6" data-only-planned="1">
        <label class="form-label">Planlagt slutt</label>
        <input type="datetime-local" class="form-control" name="schedule_end" value="<?= esc(fmt_dt_in((string)($event['schedule_end'] ?? ''))) ?>" <?= !$canWrite?'disabled':'' ?>>
      </div>

      <div class="col-12 col-md-4" data-only-incident="1">
        <label class="form-label">Faktisk start</label>
        <input type="datetime-local" class="form-control" name="actual_start" value="<?= esc(fmt_dt_in((string)($event['actual_start'] ?? ''))) ?>" <?= !$canWrite?'disabled':'' ?>>
      </div>
      <div class="col-12 col-md-4" data-only-incident="1">
        <label class="form-label">Faktisk slutt</label>
        <input type="datetime-local" class="form-control" name="actual_end" value="<?= esc(fmt_dt_in((string)($event['actual_end'] ?? ''))) ?>" <?= !$canWrite?'disabled':'' ?>>
      </div>
      <div class="col-12 col-md-4" data-only-incident="1">
        <label class="form-label">Neste oppdatering (ETA)</label>
        <input type="datetime-local" class="form-control" name="next_update_eta" value="<?= esc(fmt_dt_in((string)($event['next_update_eta'] ?? ''))) ?>" <?= !$canWrite?'disabled':'' ?>>
      </div>

      <div class="col-12">
        <hr class="my-2">
        <div class="row g-3 align-items-end">
          <div class="col-12 col-md-6">
            <label class="form-label">Jira saksnummer</label>
            <input
              class="form-control"
              name="jira_issue_key"
              value="<?= esc($jiraKey) ?>"
              <?= !$canWrite?'disabled':'' ?>
              placeholder="FTD-123"
            >
            <div class="form-text">Koble saken manuelt til en eksisterende Jira-sak.</div>
          </div>
          <input type="hidden" name="jira_issuetype_pick" value="<?= esc($jiraPick) ?>">

          <div class="col-12 col-md-6 d-flex align-items-end gap-2">
            <?php if ($jiraKey !== ''): ?>
              <a class="btn btn-outline-primary" href="<?= esc(rtrim($JIRA_SITE, '/') . '/browse/' . $jiraKey) ?>" target="_blank" rel="noopener">
                <i class="bi bi-box-arrow-up-right me-1"></i>Åpne i Jira
              </a>
            <?php elseif ($canWrite): ?>
              <button class="btn btn-success" type="submit" name="action" value="create_jira"
                      onclick="return confirm('Opprette ny sak i Jira?')">
                <i class="bi bi-plus-lg me-1"></i>Opprett i Jira
              </button>
            <?php endif; ?>
          </div>

          <?php if (!empty($jira['last_error'])): ?>
            <div class="col-12">
              <div class="alert alert-danger mb-0 small"><?= esc((string)$jira['last_error']) ?></div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($canPublish): ?>
      <div class="col-12">
        <hr class="my-2">
        <label class="form-label fw-semibold">Distribusjon</label>
        <div class="form-text mb-2">Velg hvilke systemer og kanaler som skal ha tilgang til denne saken.</div>
        <div class="d-flex flex-wrap gap-3">

          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="available_kundesenter" name="available_kundesenter"
                   <?= ((int)($event['available_kundesenter'] ?? 0) === 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="available_kundesenter">
              <i class="bi bi-headset me-1"></i> Kundesenter
            </label>
          </div>

          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="available_hkon" name="available_hkon"
                   <?= ((int)($event['published_to_chatbot'] ?? 0) === 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="available_hkon">
              <i class="bi bi-robot me-1"></i> Hkon (chatbot)
            </label>
          </div>

          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="available_dashboard" name="available_dashboard"
                   <?= ((int)($event['published_to_dashboard'] ?? 0) === 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="available_dashboard">
              <i class="bi bi-display me-1"></i> Dashboard
            </label>
          </div>

        </div>
      </div>
      <?php endif; ?>

      <div class="col-12 d-flex gap-2">
        <?php if ($canWrite || $canPublish): ?>
          <button class="btn btn-primary" type="submit">Lagre</button>
        <?php endif; ?>
      </div>

    </div>
  </div>
</form>

<div class="card mb-3">
  <div class="card-header d-flex align-items-center justify-content-between">
    <div>Berørte adresser</div>
    <div class="d-flex align-items-center gap-2">
      <div class="text-muted small me-2">
        Lagret: <span class="badge bg-dark" id="aaTotalBadge"><?= (int)$affectedAddrTotal ?></span> adresser ·
        <span class="badge bg-secondary" id="aaLpBadge"><?= (int)$affectedLpTotal ?></span> leveransepunkt
      </div>
      <a class="btn btn-sm btn-outline-info" href="<?= esc($mapUrl) ?>">Kart</a>
      <?php if ($canWrite): ?>
        <button type="button" class="btn btn-sm btn-outline-danger" id="aaDeleteAllBtn"
                <?= $affectedAddrTotal > 0 ? '' : 'disabled' ?>
        >Slett alle</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <?php if (!$canWrite): ?>
      <div class="text-muted">Du har ikke skrivetilgang til å importere leveransepunkt.</div>
    <?php else: ?>
      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <label class="form-label">Lim inn leveransepunkt_id</label>
          <textarea id="lpInput" class="form-control" rows="7" placeholder="1003-128384122-1&#10;1003-...&#10;(du kan også bruke komma/space)"></textarea>
          <div class="form-text">Oppslag skjer server-side (token blir ikke synlig i nettleseren).</div>

          <div class="d-flex gap-2 mt-2">
            <button id="lpRun" type="button" class="btn btn-outline-primary">Slå opp og lagre</button>
            <button id="lpClear" type="button" class="btn btn-outline-secondary">Tøm</button>
          </div>

          <div class="mt-3">
            <div class="d-flex align-items-center justify-content-between">
              <div>Status</div>
              <div class="small text-muted"><span id="lpCounter">0/0</span></div>
            </div>
            <div class="progress mt-2" style="height: 10px;">
              <div id="lpProg" class="progress-bar" role="progressbar" style="width:0%"></div>
            </div>
            <div class="small mt-2">
              Treff totalt: <span class="badge bg-success" id="lpTotalMatches">0</span>
              · Lagret totalt i saken: <span class="badge bg-dark" id="lpEventTotal"><?= (int)$affectedAddrTotal ?></span>
            </div>
            <div id="lpLog" class="mt-2" style="max-height:220px; overflow:auto;"></div>
          </div>
        </div>

        <div class="col-12 col-lg-6">
          <div class="alert alert-info mb-0">
            <div>Hvordan dette brukes</div>
            <div class="mt-1">
              Når leveransepunkt er importert kan Kundesenter/Chat raskt sjekke om en kunde er berørt.
              Antall “berørte kunder” oppdateres automatisk basert på antall adresser lagret for saken.
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <hr class="my-3">
    <div id="affectedTableWrap">
      <?= render_affected_rows_html($affectedRows, (bool)$canWrite, (string)$csrf) ?>
    </div>

    <?php if ($canWrite): ?>
      <script>
      (function(){
        var btn = document.getElementById('lpRun');
        var btnClear = document.getElementById('lpClear');
        var ta = document.getElementById('lpInput');
        var log = document.getElementById('lpLog');
        var counter = document.getElementById('lpCounter');
        var prog = document.getElementById('lpProg');
        var totalMatchesEl = document.getElementById('lpTotalMatches');
        var eventTotalEl = document.getElementById('lpEventTotal');

        var aaTotalBadge = document.getElementById('aaTotalBadge');
        var aaLpBadge = document.getElementById('aaLpBadge');
        var aaDeleteAllBtn = document.getElementById('aaDeleteAllBtn');

        function getAffectedWrap(){ return document.getElementById('affectedTableWrap'); }

        function escHtml(s){
          return String(s).replace(/[&<>"']/g, function(m){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
          });
        }

        function parseIds(text){
          var t = (text || '').trim();
          if (!t) return [];
          var parts = t.split(/[\n\r,; \t]+/g).map(function(x){ return x.trim(); }).filter(Boolean);
          var seen = {};
          var out = [];
          parts.forEach(function(p){
            if (!seen[p]) { seen[p] = 1; out.push(p); }
          });
          return out;
        }

        function setTextareaFromIds(ids){
          ta.value = (ids || []).join("\n");
        }

        function appendLine(html){
          var div = document.createElement('div');
          div.className = 'small';
          div.innerHTML = html;
          log.prepend(div);
        }

        function updateBadges(js){
          if (!js) return;
          if (js.event_affected_total != null) {
            eventTotalEl.textContent = String(js.event_affected_total);
            if (aaTotalBadge) aaTotalBadge.textContent = String(js.event_affected_total);
            if (aaDeleteAllBtn) aaDeleteAllBtn.disabled = (Number(js.event_affected_total) <= 0);
          }
          if (js.event_affected_lp_total != null) {
            if (aaLpBadge) aaLpBadge.textContent = String(js.event_affected_lp_total);
          }
        }

        function makeAjaxUrl(ajaxName){
          var u = new URL(window.location.href);
          u.searchParams.set('ajax', ajaxName);
          u.searchParams.set('_', String(Date.now()));
          return u.toString();
        }

        async function fetchJson(url, options){
          var r = await fetch(url, options || {});
          var txt = await r.text();
          try { return JSON.parse(txt); } catch (e) { return null; }
        }

        async function refreshAffected(){
          try {
            var js = await fetchJson(makeAjaxUrl('affected_fragment'), { method: 'GET' });
            if (!js || js.ok !== true) return;

            updateBadges(js);

            var wrap = getAffectedWrap();
            if (wrap && typeof js.html === 'string') {
              wrap.innerHTML = js.html;
            }
          } catch (e) {}
        }

        async function postLookup(lp){
          return await fetchJson(makeAjaxUrl('lp_lookup'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({
              csrf: '<?= esc($csrf) ?>',
              leveransepunkt_id: lp
            })
          });
        }

        async function deleteAffectedRow(rowId){
          return await fetchJson(makeAjaxUrl('affected_delete'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({
              csrf: '<?= esc($csrf) ?>',
              row_id: String(rowId || '')
            })
          });
        }

        async function deleteAllAffected(){
          return await fetchJson(makeAjaxUrl('affected_delete_all'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({ csrf: '<?= esc($csrf) ?>' })
          });
        }

        function wasImported(js){
          if (!js || js.ok !== true) return false;
          var saved = Number(js.saved_rows || 0);
          var mc = Number(js.match_count || 0);
          return (saved > 0) || (mc > 0);
        }

        async function run(){
          var ids = parseIds(ta.value);
          log.innerHTML = '';
          totalMatchesEl.textContent = '0';

          if (!ids.length) {
            appendLine('<span class="text-danger">Ingen leveransepunkt å slå opp.</span>');
            return;
          }

          btn.disabled = true;
          btn.innerHTML = 'Jobber...';

          var remaining = ids.slice();
          var total = ids.length;
          var totalMatches = 0;

          counter.textContent = '0/' + total;
          prog.style.width = '0%';

          await refreshAffected();

          for (var i=0; i<ids.length; i++){
            var lp = ids[i];

            counter.textContent = (i + 1) + '/' + total;
            prog.style.width = Math.round(((i+1)/total)*100) + '%';

            try {
              var js = await postLookup(lp);

              if (!wasImported(js)) {
                if (js && js.error) {
                  appendLine('⚪ <code>' + escHtml(lp) + '</code> <span class="text-muted">Ingen treff</span> <span class="text-muted">(' + escHtml(String(js.error)) + ')</span>');
                } else if (js) {
                  appendLine('⚪ <code>' + escHtml(lp) + '</code> <span class="text-muted">Ingen treff</span>');
                }
                continue;
              }

              var mc = Number((js && js.match_count) ? js.match_count : 0);
              if (mc > 0) {
                totalMatches += mc;
                totalMatchesEl.textContent = String(totalMatches);
              }

              remaining = remaining.filter(function(x){ return x !== lp; });
              setTextareaFromIds(remaining);
              if (remaining.length === 0) ta.value = '';

              updateBadges(js);
              await refreshAffected();

            } catch (e) {
            }
          }

          btn.disabled = false;
          btn.innerHTML = 'Slå opp og lagre';

          await refreshAffected();
          appendLine('✅ Ferdig.');
        }

        document.addEventListener('click', async function(e){
          var btnDel = e.target && e.target.closest ? e.target.closest('.js-aa-delete') : null;
          if (!btnDel) return;
          e.preventDefault();

          var rowId = Number(btnDel.getAttribute('data-aa-id') || 0);
          if (!rowId) return;

          if (!confirm('Slette denne adressen fra saken?')) return;

          btnDel.disabled = true;
          try {
            var js = await deleteAffectedRow(rowId);
            if (js && js.ok === true) {
              updateBadges(js);
              var wrap = getAffectedWrap();
              if (wrap && typeof js.html === 'string') wrap.innerHTML = js.html;
            } else {
              alert('Kunne ikke slette.');
            }
          } catch (err) {
            alert('Kunne ikke slette (nettverk).');
          } finally {
            btnDel.disabled = false;
          }
        });

        if (aaDeleteAllBtn) {
          aaDeleteAllBtn.addEventListener('click', async function(){
            if (aaDeleteAllBtn.disabled) return;
            if (!confirm('Slette ALLE lagrede adresser for denne saken?')) return;

            aaDeleteAllBtn.disabled = true;
            try {
              var js = await deleteAllAffected();
              if (js && js.ok === true) {
                updateBadges(js);
                var wrap = getAffectedWrap();
                if (wrap && typeof js.html === 'string') wrap.innerHTML = js.html;
              } else {
                alert('Kunne ikke slette alle.');
              }
            } catch (e) {
              alert('Kunne ikke slette alle (nettverk).');
            } finally {
              if (aaDeleteAllBtn) aaDeleteAllBtn.disabled = (Number((aaTotalBadge && aaTotalBadge.textContent) || 0) <= 0);
            }
          });
        }

        btn.addEventListener('click', run);
        btnClear.addEventListener('click', function(){
          ta.value = '';
          log.innerHTML = '';
          counter.textContent = '0/0';
          prog.style.width = '0%';
          totalMatchesEl.textContent = '0';
        });
      })();
      </script>
    <?php endif; ?>

  </div>
</div>

<div class="card mb-3">
  <div class="card-header fw-semibold">Oppdateringer</div>
  <div class="card-body">
    <?php if ($canWrite): ?>
    <form method="post" class="mb-3">
      <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
      <input type="hidden" name="action" value="add_update">
      <div class="mb-2">
        <textarea class="form-control" name="message" rows="2"
                  placeholder="Skriv en oppdatering…"></textarea>
      </div>
      <div class="d-flex align-items-center gap-2">
        <select class="form-select form-select-sm" name="visibility" style="width:auto;">
          <option value="public">Offentlig</option>
          <option value="internal">Intern</option>
        </select>
        <button class="btn btn-sm btn-primary" type="submit">
          <i class="bi bi-send me-1"></i>Legg til
        </button>
      </div>
    </form>
    <?php endif; ?>

    <?php if (!$updates): ?>
      <div class="text-muted small"><i class="bi bi-chat-left opacity-50 me-2"></i>Ingen oppdateringer ennå.</div>
    <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($updates as $up):
          $vis = (string)($up['visibility'] ?? 'public');
          $visLabel = $vis === 'internal' ? 'Intern' : 'Offentlig';
          $visBadge = $vis === 'internal' ? 'secondary' : 'primary';
        ?>
          <div class="list-group-item px-0">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <span class="small text-muted">
                <?= esc(fmt_dt_out((string)($up['created_at'] ?? ''))) ?>
                <?php if (!empty($up['created_by'])): ?>
                  · <?= esc((string)$up['created_by']) ?>
                <?php endif; ?>
              </span>
              <span class="badge text-bg-<?= $visBadge ?> fw-normal"><?= $visLabel ?></span>
            </div>
            <div style="white-space:pre-wrap;" class="small"><?= esc((string)($up['message'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($isAdmin): ?>
  <details class="mt-4">
    <summary class="text-danger small" style="cursor:pointer;user-select:none;">
      <i class="bi bi-shield-exclamation me-1"></i>Farlig sone (admin)
    </summary>
    <div class="card border-danger mt-2">
      <div class="card-body">
        <p class="small text-muted mb-2">
          Sletter saken permanent — inkludert berørte adresser, oppdateringer og Jira-integrasjon.
          Kan ikke angres.
        </p>
        <form method="post" onsubmit="return confirm('Slette saken PERMANENT? Dette kan ikke angres.');">
          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
          <input type="hidden" name="action" value="delete_event">
          <button class="btn btn-sm btn-danger" type="submit">
            <i class="bi bi-trash me-1"></i>Slett sak permanent
          </button>
        </form>
      </div>
    </div>
  </details>
<?php endif; ?>