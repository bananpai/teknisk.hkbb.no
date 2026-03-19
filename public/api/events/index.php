<?php
// Path: /public/api/events/index.php
// Public API for Hendelser & Planlagte jobber
// Auth: Bearer token via api_tokens (tabell finnes i DB)
// Scopes:
//  - events:read  (les/list)
//  - events:write (ikke implementert i denne API-filen nå)
//
// Endepunkter:
//  - GET /api/events?mode=active|planned|recent|all&limit=50
//  - GET /api/events?id=123 (hent én)
//  - GET /api/events/public?target_type=...&target_value=... (public feed for Hkon)
//    - Returnerer bare der is_public=1 og published_to_chatbot=1
//
// NB: Dette er et “trygt” feed: kun kundeklar tekst + status/impact/tid + scope.

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

ini_set('display_errors', '0');
error_reporting(0);

function json_out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function get_bearer_token(): string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!$h) return '';
  if (stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
  return '';
}

function sha256hex(string $s): string { return hash('sha256', $s); }

function token_scopes(PDO $pdo, string $token): ?array {
  $hash = sha256hex($token);
  $st = $pdo->prepare("SELECT scopes, is_active, revoked_at FROM api_tokens WHERE token_hash=? LIMIT 1");
  $st->execute([$hash]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  if ((int)$row['is_active'] !== 1) return null;
  if (!empty($row['revoked_at'])) return null;

  // touch last_used_at
  $pdo->prepare("UPDATE api_tokens SET last_used_at=NOW() WHERE token_hash=?")->execute([$hash]);

  $scopes = array_filter(array_map('trim', explode(' ', str_replace(',', ' ', (string)$row['scopes']))));
  return $scopes ?: [];
}

function require_scope(array $scopes, string $need): void {
  if (!in_array($need, $scopes, true)) {
    json_out(403, ['error'=>'forbidden','error_description'=>"Missing scope: $need"]);
  }
}

try {
  $pdo = getPDO();
} catch (Throwable $e) {
  json_out(500, ['error'=>'server_error','error_description'=>'DB connection failed']);
}

$token = get_bearer_token();
if ($token === '') {
  json_out(401, ['error'=>'invalid_token','error_description'=>'Missing bearer token']);
}

$scopes = token_scopes($pdo, $token);
if ($scopes === null) {
  json_out(401, ['error'=>'invalid_token','error_description'=>'Invalid or inactive token']);
}

require_scope($scopes, 'events:read');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$isPublicFeed = (strpos($path, '/api/events/public') !== false);

$id = (int)($_GET['id'] ?? 0);
$mode = (string)($_GET['mode'] ?? 'active');
$limit = (int)($_GET['limit'] ?? 50);
if ($limit < 1) $limit = 50;
if ($limit > 200) $limit = 200;

if ($id > 0) {
  $st = $pdo->prepare("
    SELECT
      id, type, status, severity, impact, services,
      title_public, summary_public, customer_actions,
      schedule_start, schedule_end, actual_start, actual_end,
      next_update_eta,
      published_to_dashboard, published_to_chatbot, is_public,
      updated_at
    FROM events
    WHERE id=?
    LIMIT 1
  ");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_out(404, ['error'=>'not_found']);

  $t = $pdo->prepare("SELECT target_type, target_value, is_exclude FROM event_targets WHERE event_id=? ORDER BY is_exclude ASC, target_type ASC, id ASC");
  $t->execute([$id]);
  $targets = $t->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $u = $pdo->prepare("SELECT visibility, message, created_at FROM event_updates WHERE event_id=? ORDER BY created_at DESC LIMIT 50");
  $u->execute([$id]);
  $updates = $u->fetchAll(PDO::FETCH_ASSOC) ?: [];

  json_out(200, ['event'=>$row,'targets'=>$targets,'updates'=>$updates]);
}

$where = [];
$args  = [];

if ($isPublicFeed) {
  // Public feed for Hkon: only public + published_to_chatbot
  $where[] = "e.is_public=1 AND e.published_to_chatbot=1";
  $where[] = "e.status IN ('scheduled','in_progress','monitoring')";

  $target_type = trim((string)($_GET['target_type'] ?? ''));
  $target_value = trim((string)($_GET['target_value'] ?? ''));

  if ($target_type !== '' && $target_value !== '') {
    $where[] = "EXISTS (
      SELECT 1 FROM event_targets t
      WHERE t.event_id=e.id AND t.is_exclude=0 AND t.target_type=? AND t.target_value=?
    )";
    $args[] = $target_type;
    $args[] = $target_value;
  }
} else {
  if (!in_array($mode, ['active','planned','recent','all'], true)) $mode = 'active';

  if ($mode === 'active') {
    $where[] = "e.status IN ('in_progress','monitoring')";
  } elseif ($mode === 'planned') {
    $where[] = "e.type='planned' AND e.status IN ('scheduled','in_progress','monitoring')";
  } elseif ($mode === 'recent') {
    $where[] = "e.status IN ('resolved','cancelled') AND e.updated_at >= (NOW() - INTERVAL 7 DAY)";
  }
}

$sqlWhere = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$sql = "
SELECT
  e.id, e.type, e.status, e.severity, e.impact, e.services,
  e.title_public, e.summary_public,
  e.schedule_start, e.schedule_end, e.actual_start, e.actual_end,
  e.next_update_eta,
  e.published_to_dashboard, e.published_to_chatbot, e.is_public,
  e.updated_at,
  (SELECT i.external_id FROM event_integrations i WHERE i.event_id=e.id AND i.system='jira' LIMIT 1) AS jira_key
FROM events e
$sqlWhere
ORDER BY COALESCE(e.schedule_start, e.actual_start, e.updated_at) DESC, e.id DESC
LIMIT $limit
";

$st = $pdo->prepare($sql);
$st->execute($args);
$list = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

json_out(200, [
  'mode' => $isPublicFeed ? 'public' : $mode,
  'count' => count($list),
  'items' => $list
]);