<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\lager\pages\uttak.php
// Uttak wizard steg 1/3
//
// Oppdatert 2026-03-16:
// - Fysiske lagerlokasjoner hentes nå ut fra faktisk beholdning på lokasjon
// - Lokasjoner med varer vises også når batch/project_id er NULL
// - Lagerprosjekt er ikke lenger et hardt krav for å få opp lokasjoner
// - "Alle / uten prosjekt" er mulig som kildevalg
// - Flytting: til-prosjekt følger alltid valgt lagerprosjekt
// - Flytting: til-lager kan velges blant alle aktive fysiske lokasjoner
// - Validering er gjort robust for både prosjektbasert og prosjektløst lager
//
// Viktig:
// - Primær beholdningskilde er inv_batches.qty_remaining > 0 per physical_location_id
// - Fallback bruker inv_movements aggregert per physical_location_id
// - Prosjektlisten viser bare prosjekter med positiv beholdning, men uttak kan fortsatt gjøres
//   fra lokasjoner som har varer uten prosjektkobling.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

$u   = require_lager_login();
$pdo = get_pdo();

if (!function_exists('h')) {
    function h(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('tableExists')) {
    function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :t
            ");
            $stmt->execute([':t' => $table]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
if (!function_exists('columnExists')) {
    function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND column_name = :c
            ");
            $stmt->execute([':t' => $table, ':c' => $column]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

function env_value(string $key, ?string $default = null): ?string
{
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return (string)$val;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string)$_SERVER[$key];
    }
    return $default;
}

function normalize_work_order_search(string $value): string
{
    $v = trim($value);
    $v = preg_replace('/\s+/', ' ', $v);
    $v = strtoupper((string)$v);

    if ($v === '') {
        return '';
    }

    if (preg_match('/^\d+$/', $v)) {
        return $v;
    }

    if (preg_match('/^HFA\-?\d+$/', str_replace(' ', '', $v))) {
        return (string)preg_replace('/^HFA\-?/i', '', str_replace(' ', '', $v));
    }

    return trim((string)$v);
}

function graphql_string(string $value): string
{
    $value = str_replace(["\\", "\"", "\r", "\n", "\t"], ["\\\\", "\\\"", "\\r", "\\n", "\\t"], $value);
    return '"' . $value . '"';
}

function http_post_form(string $url, array $fields, array $headers = [], int $timeout = 20): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL er ikke tilgjengelig på serveren.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Kunne ikke initialisere cURL.');
    }

    $defaultHeaders = [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => array_merge($defaultHeaders, $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('HTTP-kall feilet: ' . ($err ?: 'ukjent feil'));
    }

    return ['status' => $code, 'body' => (string)$body];
}

function http_post_json(string $url, array $payload, array $headers = [], int $timeout = 20): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL er ikke tilgjengelig på serveren.');
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Kunne ikke serialisere JSON.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Kunne ikke initialisere cURL.');
    }

    $defaultHeaders = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json),
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => array_merge($defaultHeaders, $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('HTTP-kall feilet: ' . ($err ?: 'ukjent feil'));
    }

    return ['status' => $code, 'body' => (string)$body];
}

function wf_get_access_token(): string
{
    $clientId     = env_value('WF_CLIENT_ID', '');
    $clientSecret = env_value('WF_CLIENT_SECRET', '');
    $tokenUrl     = env_value('WF_TOKEN_URL', '');
    $scope        = env_value('WF_SCOPE', '');

    if ($clientId === '' || $clientSecret === '' || $tokenUrl === '') {
        throw new RuntimeException('WF OAuth-innstillinger mangler i .env.');
    }

    $postFields = [
        'grant_type'    => 'client_credentials',
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
    ];
    if ($scope !== '') {
        $postFields['scope'] = $scope;
    }

    $resp = http_post_form($tokenUrl, $postFields);

    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException('Token-endepunkt svarte med HTTP ' . $resp['status'] . '.');
    }

    $json = json_decode($resp['body'], true);
    if (!is_array($json)) {
        throw new RuntimeException('Ugyldig JSON fra token-endepunkt.');
    }

    $token = (string)($json['access_token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('Fikk ikke access_token fra token-endepunkt.');
    }

    return $token;
}

function wf_search_work_orders(string $search, int $limit = 10): array
{
    $apiUrl = env_value('WF_API_URL', '');
    if ($apiUrl === '') {
        return [];
    }

    $limit = max(1, min(25, $limit));
    $token = wf_get_access_token();

    $search = trim($search);
    if ($search === '') {
        return [];
    }

    $searchNoSpaces = preg_replace('/\s+/', '', $search);
    $searchUpper = strtoupper((string)$searchNoSpaces);
    $numericOnly = preg_match('/^\d+$/', (string)$searchUpper) === 1
        ? (string)$searchUpper
        : preg_replace('/^HFA\-?/i', '', (string)$searchUpper);

    $numberContains = graphql_string((string)$numericOnly !== '' ? (string)$numericOnly : $search);
    $nameContains   = graphql_string($search);

    $query = 'query { silver_wf_workforceworkorders(filter: { or: ['
        . '{ wf_workordernumber: { contains: ' . $numberContains . ' } }, '
        . '{ wf_name: { contains: ' . $nameContains . ' } }'
        . '] }, first: ' . $limit . ') { items { wf_workordernumber wf_name _wf_workforceprojectid_value statecode } } }';

    $resp = http_post_json($apiUrl, ['query' => $query], [
        'Authorization: Bearer ' . $token,
    ]);

    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException('GraphQL-endepunkt svarte med HTTP ' . $resp['status'] . '.');
    }

    $json = json_decode($resp['body'], true);
    if (!is_array($json)) {
        throw new RuntimeException('Ugyldig JSON fra GraphQL API.');
    }

    if (!empty($json['errors']) && is_array($json['errors'])) {
        $first = $json['errors'][0]['message'] ?? 'Ukjent GraphQL-feil.';
        throw new RuntimeException('GraphQL-feil: ' . (string)$first);
    }

    $items = $json['data']['silver_wf_workforceworkorders']['items'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $out = [];
    $seen = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $number    = trim((string)($item['wf_workordernumber'] ?? ''));
        $name      = trim((string)($item['wf_name'] ?? ''));
        $projectId = trim((string)($item['_wf_workforceprojectid_value'] ?? ''));
        $statecode = isset($item['statecode']) ? (string)$item['statecode'] : '';

        if ($number === '') {
            continue;
        }

        if (isset($seen[$number])) {
            continue;
        }
        $seen[$number] = true;

        $out[] = [
            'number'      => $number,
            'name'        => $name,
            'project_id'  => $projectId,
            'statecode'   => $statecode,
            'label'       => $name !== '' ? ($number . ' - ' . $name) : $number,
        ];
    }

    usort($out, static function (array $a, array $b) use ($search): int {
        $needle = mb_strtolower(trim($search));
        $aNum   = mb_strtolower((string)($a['number'] ?? ''));
        $bNum   = mb_strtolower((string)($b['number'] ?? ''));
        $aName  = mb_strtolower((string)($a['name'] ?? ''));
        $bName  = mb_strtolower((string)($b['name'] ?? ''));

        $aStarts = str_starts_with($aNum, 'hfa-' . $needle) || str_starts_with($aName, $needle);
        $bStarts = str_starts_with($bNum, 'hfa-' . $needle) || str_starts_with($bName, $needle);

        if ($aStarts && !$bStarts) return -1;
        if ($bStarts && !$aStarts) return 1;

        return strcmp($aNum, $bNum);
    });

    return $out;
}

function wf_find_single_work_order(string $input): ?array
{
    $normalized = normalize_work_order_search($input);
    if ($normalized === '') {
        return null;
    }

    $rows = wf_search_work_orders($normalized, 15);
    if (count($rows) === 1) {
        return $rows[0];
    }

    $flat = strtoupper((string)preg_replace('/\s+/', '', $normalized));
    foreach ($rows as $row) {
        $num = strtoupper(trim((string)($row['number'] ?? '')));
        $cmp = preg_replace('/^HFA\-?/i', '', $num);

        if ($cmp === $flat || $num === ('HFA-' . $flat)) {
            return $row;
        }
    }

    return null;
}

function findLocationProjectColumn(PDO $pdo): ?string
{
    foreach (['project_id', 'lager_project_id', 'logical_project_id', 'owner_project_id'] as $candidate) {
        if (columnExists($pdo, 'inv_locations', $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function findProjectsDisplayCols(PDO $pdo): array
{
    $nameCol = columnExists($pdo, 'projects', 'name') ? 'name' : null;
    $noCol   = null;

    foreach (['project_no', 'code', 'number', 'project_number'] as $candidate) {
        if (columnExists($pdo, 'projects', $candidate)) {
            $noCol = $candidate;
            break;
        }
    }

    return [$nameCol, $noCol];
}

/**
 * Returnerer:
 * [
 *   'projects' => [projectId => true, ...],
 *   'locations_by_project' => [projectId => [locId => true, ...], '__none__' => [...]],
 *   'all_locations' => [locId => true, ...]
 * ]
 */
function getStockMap(PDO $pdo): array
{
    $projects = [];
    $locationsByProject = [];
    $allLocations = [];

    if (tableExists($pdo, 'inv_batches')
        && columnExists($pdo, 'inv_batches', 'physical_location_id')
        && columnExists($pdo, 'inv_batches', 'qty_remaining')
    ) {
        try {
            $hasProjectCol = columnExists($pdo, 'inv_batches', 'project_id');
            $sql = "
                SELECT
                    physical_location_id,
                    " . ($hasProjectCol ? "project_id" : "NULL AS project_id") . ",
                    SUM(qty_remaining) AS qty_sum
                FROM inv_batches
                WHERE physical_location_id IS NOT NULL
                  AND qty_remaining > 0
                GROUP BY physical_location_id, " . ($hasProjectCol ? "project_id" : "NULL") . "
                HAVING qty_sum > 0
            ";
            $stmt = $pdo->query($sql);
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

            foreach ($rows as $r) {
                $locId = (int)($r['physical_location_id'] ?? 0);
                if ($locId <= 0) {
                    continue;
                }

                $allLocations[$locId] = true;

                $projectRaw = $r['project_id'] ?? null;
                if ($projectRaw !== null && (int)$projectRaw > 0) {
                    $pid = (int)$projectRaw;
                    $projects[$pid] = true;
                    $locationsByProject[$pid][$locId] = true;
                } else {
                    $locationsByProject['__none__'][$locId] = true;
                }
            }
        } catch (\Throwable $e) {
        }
    }

    if (empty($allLocations)
        && tableExists($pdo, 'inv_movements')
        && columnExists($pdo, 'inv_movements', 'physical_location_id')
        && columnExists($pdo, 'inv_movements', 'type')
        && columnExists($pdo, 'inv_movements', 'qty')
    ) {
        try {
            $hasProjectCol = columnExists($pdo, 'inv_movements', 'project_id');
            $sql = "
                SELECT
                    physical_location_id,
                    " . ($hasProjectCol ? "project_id" : "NULL AS project_id") . ",
                    SUM(
                        CASE
                            WHEN type = 'IN' THEN qty
                            WHEN type = 'OUT' THEN -qty
                            ELSE qty
                        END
                    ) AS bal
                FROM inv_movements
                WHERE physical_location_id IS NOT NULL
                GROUP BY physical_location_id, " . ($hasProjectCol ? "project_id" : "NULL") . "
                HAVING bal > 0
            ";
            $stmt = $pdo->query($sql);
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

            foreach ($rows as $r) {
                $locId = (int)($r['physical_location_id'] ?? 0);
                if ($locId <= 0) {
                    continue;
                }

                $allLocations[$locId] = true;

                $projectRaw = $r['project_id'] ?? null;
                if ($projectRaw !== null && (int)$projectRaw > 0) {
                    $pid = (int)$projectRaw;
                    $projects[$pid] = true;
                    $locationsByProject[$pid][$locId] = true;
                } else {
                    $locationsByProject['__none__'][$locId] = true;
                }
            }
        } catch (\Throwable $e) {
        }
    }

    return [
        'projects'             => $projects,
        'locations_by_project' => $locationsByProject,
        'all_locations'        => $allLocations,
    ];
}

function fetchProjectIdsWithStock(PDO $pdo): array
{
    $map = getStockMap($pdo);
    return array_map('intval', array_keys($map['projects']));
}

function fetchProjects(PDO $pdo): array
{
    if (!tableExists($pdo, 'projects')) {
        return [];
    }

    $stockProjectIds = fetchProjectIdsWithStock($pdo);
    if (empty($stockProjectIds)) {
        return [];
    }

    [$nameCol, $noCol] = findProjectsDisplayCols($pdo);

    $parts = ['id'];
    $parts[] = $nameCol ? "`{$nameCol}` AS project_name" : "'' AS project_name";
    $parts[] = $noCol ? "`{$noCol}` AS project_no" : "'' AS project_no";

    $where = [];
    $where[] = 'id IN (' . implode(',', array_map('intval', $stockProjectIds)) . ')';

    foreach (['is_active', 'active', 'enabled'] as $candidate) {
        if (columnExists($pdo, 'projects', $candidate)) {
            $where[] = "`{$candidate}` = 1";
            break;
        }
    }

    $sql = "SELECT " . implode(', ', $parts) . " FROM projects WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY project_name, project_no, id";

    try {
        $stmt = $pdo->query($sql);
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (\Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $name = trim((string)($r['project_name'] ?? ''));
        $no   = trim((string)($r['project_no'] ?? ''));

        $label = $name !== '' ? $name : ('Prosjekt #' . $id);
        if ($no !== '') {
            $label .= ' (' . $no . ')';
        }

        $out[] = [
            'id'    => $id,
            'label' => $label,
        ];
    }

    return $out;
}

function fetchLocations(PDO $pdo, ?int $projectId = null, ?string $locationProjectCol = null, bool $onlyWithStock = true): array
{
    if (!tableExists($pdo, 'inv_locations')) {
        return [];
    }

    $stockMap = getStockMap($pdo);
    $allowedLocationIds = [];

    if ($onlyWithStock) {
        if ($projectId !== null && $projectId > 0) {
            $allowedLocationIds = array_map('intval', array_keys($stockMap['locations_by_project'][$projectId] ?? []));
        } elseif ($projectId === 0) {
            $allowedLocationIds = array_map('intval', array_keys($stockMap['all_locations'] ?? []));
        } else {
            $allowedLocationIds = array_map('intval', array_keys($stockMap['all_locations'] ?? []));
        }

        if (empty($allowedLocationIds)) {
            return [];
        }
    }

    $sql = "SELECT id, code, name FROM inv_locations WHERE is_active = 1";
    $params = [];

    if ($onlyWithStock && !empty($allowedLocationIds)) {
        $sql .= " AND id IN (" . implode(',', array_map('intval', $allowedLocationIds)) . ")";
    }

    if ($projectId !== null && $projectId > 0 && $locationProjectCol) {
        $sql .= " AND `{$locationProjectCol}` = :pid";
        $params[':pid'] = $projectId;
    }

    $sql .= " ORDER BY name, code, id";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $code = trim((string)($r['code'] ?? ''));
        $name = trim((string)($r['name'] ?? ''));
        $label = $code !== '' ? ($code . ' – ' . $name) : ($name !== '' ? $name : ('Lokasjon #' . $id));

        $out[] = [
            'id'    => $id,
            'label' => $label,
        ];
    }

    return $out;
}

function fetchAllActiveLocations(PDO $pdo): array
{
    if (!tableExists($pdo, 'inv_locations')) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, code, name
            FROM inv_locations
            WHERE is_active = 1
            ORDER BY name, code, id
        ");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (\Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $code = trim((string)($r['code'] ?? ''));
        $name = trim((string)($r['name'] ?? ''));
        $label = $code !== '' ? ($code . ' – ' . $name) : ($name !== '' ? $name : ('Lokasjon #' . $id));

        $out[] = [
            'id'    => $id,
            'label' => $label,
        ];
    }

    return $out;
}

$errors = [];

if (!tableExists($pdo, 'inv_locations')) {
    $errors[] = 'Mangler tabell inv_locations.';
}

$hasProjectsTable   = tableExists($pdo, 'projects');
$locationProjectCol = !$errors ? findLocationProjectColumn($pdo) : null;
$projectRows        = !$errors ? fetchProjects($pdo) : [];
$projectsEnabled    = $hasProjectsTable && !empty($projectRows);

if (!$errors) {
    $probeLocations = fetchLocations($pdo, 0, $locationProjectCol, true);
    if (empty($probeLocations)) {
        $errors[] = 'Fant ingen lagerlokasjoner med positiv beholdning.';
    }
}

if (!isset($_SESSION['lager_uttak_wizard']) || !is_array($_SESSION['lager_uttak_wizard'])) {
    $_SESSION['lager_uttak_wizard'] = [
        'action_mode'                     => 'workorder',
        'source_project_id'               => 0,
        'source_location_id'              => 0,
        'target_project_id'               => 0,
        'target_location_id'              => 0,
        'target_work_order_id'            => 0,
        'target_work_order_local_id'      => 0,
        'target_work_order_text'          => '',
        'target_work_order_name'          => '',
        'target_work_order_project_id'    => '',
        'target_work_order_statecode'     => '',
        'cart'                            => [],
        'note'                            => '',
    ];
}
$wiz =& $_SESSION['lager_uttak_wizard'];

$wiz['action_mode']                  = in_array((string)($wiz['action_mode'] ?? 'workorder'), ['workorder', 'transfer'], true)
    ? (string)$wiz['action_mode']
    : 'workorder';
$wiz['source_project_id']            = (int)($wiz['source_project_id'] ?? 0);
$wiz['source_location_id']           = (int)($wiz['source_location_id'] ?? 0);
$wiz['target_project_id']            = (int)($wiz['target_project_id'] ?? 0);
$wiz['target_location_id']           = (int)($wiz['target_location_id'] ?? 0);
$wiz['target_work_order_name']       = (string)($wiz['target_work_order_name'] ?? '');
$wiz['target_work_order_project_id'] = (string)($wiz['target_work_order_project_id'] ?? '');
$wiz['target_work_order_statecode']  = (string)($wiz['target_work_order_statecode'] ?? '');
$wiz['target_work_order_id']         = (int)($wiz['target_work_order_id'] ?? 0);
$wiz['target_work_order_local_id']   = (int)($wiz['target_work_order_local_id'] ?? 0);

$currentUsername = (string)($u['username'] ?? $u['user'] ?? $u['email'] ?? '');
$lastLocKey      = 'lager_uttak_last_location_id__' . sha1($currentUsername ?: 'unknown');
$lastProjKey     = 'lager_uttak_last_project_id__' . sha1($currentUsername ?: 'unknown');

if (!isset($_SESSION[$lastLocKey])) {
    $_SESSION[$lastLocKey] = 0;
}
if (!isset($_SESSION[$lastProjKey])) {
    $_SESSION[$lastProjKey] = 0;
}

if (isset($_GET['reset']) && (string)$_GET['reset'] === '1') {
    $wiz = [
        'action_mode'                     => 'workorder',
        'source_project_id'               => 0,
        'source_location_id'              => 0,
        'target_project_id'               => 0,
        'target_location_id'              => 0,
        'target_work_order_id'            => 0,
        'target_work_order_local_id'      => 0,
        'target_work_order_text'          => '',
        'target_work_order_name'          => '',
        'target_work_order_project_id'    => '',
        'target_work_order_statecode'     => '',
        'cart'                            => [],
        'note'                            => '',
    ];
    header('Location: /lager/?page=uttak');
    exit;
}

$actionModeUi      = (string)($wiz['action_mode'] ?? 'workorder');
$sourceProjectUi   = (int)($wiz['source_project_id'] ?? 0);
$sourceLocationUi  = (int)($wiz['source_location_id'] ?? 0);
$targetProjectUi   = (int)($wiz['target_project_id'] ?? 0);
$targetLocationUi  = (int)($wiz['target_location_id'] ?? 0);

if ($sourceProjectUi === 0 && isset($_SESSION[$lastProjKey])) {
    $sourceProjectUi = (int)$_SESSION[$lastProjKey];
}
if ($sourceLocationUi <= 0) {
    $sourceLocationUi = (int)($_SESSION[$lastLocKey] ?? 0);
}
if ($actionModeUi === 'transfer') {
    $targetProjectUi = $sourceProjectUi;
}

$workOrderTextUi      = (string)($wiz['target_work_order_text'] ?? '');
$workOrderNameUi      = (string)($wiz['target_work_order_name'] ?? '');
$workOrderProjectIdUi = (string)($wiz['target_work_order_project_id'] ?? '');
$workOrderStatecodeUi = (string)($wiz['target_work_order_statecode'] ?? '');

$locationsSource = !$errors ? fetchLocations($pdo, $sourceProjectUi, $locationProjectCol, true) : [];
$locationsTarget = !$errors ? fetchAllActiveLocations($pdo) : [];

$lastProjId = (int)($_SESSION[$lastProjKey] ?? 0);
if ($lastProjId >= 0 && !empty($projectRows)) {
    usort($projectRows, static function (array $a, array $b) use ($lastProjId): int {
        $ai = (int)$a['id'];
        $bi = (int)$b['id'];
        if ($ai === $lastProjId) return -1;
        if ($bi === $lastProjId) return 1;
        return strcasecmp((string)$a['label'], (string)$b['label']);
    });
}

$lastLocId = (int)($_SESSION[$lastLocKey] ?? 0);
if ($lastLocId > 0 && !empty($locationsSource)) {
    usort($locationsSource, static function (array $a, array $b) use ($lastLocId): int {
        $ai = (int)$a['id'];
        $bi = (int)$b['id'];
        if ($ai === $lastLocId) return -1;
        if ($bi === $lastLocId) return 1;
        return strcasecmp((string)$a['label'], (string)$b['label']);
    });
}

$validSourceLocationIds = array_column($locationsSource, 'id');
$validTargetLocationIds = array_column($locationsTarget, 'id');

if ($sourceLocationUi > 0 && !in_array($sourceLocationUi, $validSourceLocationIds, true)) {
    $sourceLocationUi = 0;
}
if ($targetLocationUi > 0 && !in_array($targetLocationUi, $validTargetLocationIds, true)) {
    $targetLocationUi = 0;
}

$action = (string)($_POST['action'] ?? '');
if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'next') {
    $actionMode = (string)($_POST['action_mode'] ?? 'workorder');
    if (!in_array($actionMode, ['workorder', 'transfer'], true)) {
        $actionMode = 'workorder';
    }

    $sourceProjectId  = (int)($_POST['source_project_id'] ?? 0);
    $sourceLocationId = (int)($_POST['source_location_id'] ?? 0);
    $targetProjectId  = $sourceProjectId;
    $targetLocationId = (int)($_POST['target_location_id'] ?? 0);

    $woNumber    = trim((string)($_POST['target_work_order_text'] ?? ''));
    $woName      = trim((string)($_POST['target_work_order_name'] ?? ''));
    $woProjectId = trim((string)($_POST['target_work_order_project_id'] ?? ''));
    $woStatecode = trim((string)($_POST['target_work_order_statecode'] ?? ''));

    $manualSearch = trim((string)($_POST['target_work_order_search'] ?? ''));

    $postSourceLocations = fetchLocations($pdo, $sourceProjectId, $locationProjectCol, true);
    $postTargetLocations = fetchAllActiveLocations($pdo);

    $postSourceLocationIds = array_column($postSourceLocations, 'id');
    $postTargetLocationIds = array_column($postTargetLocations, 'id');

    if ($sourceLocationId <= 0) {
        $errors[] = $actionMode === 'transfer' ? 'Velg fra-lager.' : 'Velg uttakslokasjon.';
    } elseif (!in_array($sourceLocationId, $postSourceLocationIds, true)) {
        $errors[] = 'Valgt fra-lager har ikke tilgjengelig beholdning i valgt filter.';
    }

    if ($actionMode === 'transfer') {
        if ($targetLocationId <= 0) {
            $errors[] = 'Velg til-lager / bil / forbrukslager.';
        } elseif (!in_array($targetLocationId, $postTargetLocationIds, true)) {
            $errors[] = 'Valgt til-lager finnes ikke blant aktive fysiske lokasjoner.';
        }

        if (
            $sourceLocationId > 0 &&
            $targetLocationId > 0 &&
            $sourceLocationId === $targetLocationId
        ) {
            $errors[] = 'Fra og til kan ikke være samme fysiske lokasjon.';
        }

        $woNumber = '';
        $woName = '';
        $woProjectId = '';
        $woStatecode = '';
    } else {
        $targetProjectId = 0;
        $targetLocationId = 0;

        if ($woNumber === '' && $manualSearch !== '') {
            try {
                $resolved = wf_find_single_work_order($manualSearch);
                if ($resolved !== null) {
                    $woNumber    = (string)$resolved['number'];
                    $woName      = (string)$resolved['name'];
                    $woProjectId = (string)$resolved['project_id'];
                    $woStatecode = (string)$resolved['statecode'];
                }
            } catch (\Throwable $e) {
                $errors[] = 'Kunne ikke slå opp arbeidsordre: ' . $e->getMessage();
            }
        }

        if ($woNumber === '') {
            $errors[] = 'Velg arbeidsordre fra listen.';
        }
    }

    $contextChanged =
        ((string)$wiz['action_mode'] !== (string)$actionMode) ||
        ((int)$wiz['source_project_id'] !== (int)$sourceProjectId) ||
        ((int)$wiz['source_location_id'] !== (int)$sourceLocationId) ||
        ((int)$wiz['target_project_id'] !== (int)$targetProjectId) ||
        ((int)$wiz['target_location_id'] !== (int)$targetLocationId) ||
        ((string)$wiz['target_work_order_text'] !== (string)$woNumber);

    if (!$errors) {
        $wiz['action_mode']                  = $actionMode;
        $wiz['source_project_id']            = $sourceProjectId;
        $wiz['source_location_id']           = $sourceLocationId;
        $wiz['target_project_id']            = $targetProjectId;
        $wiz['target_location_id']           = $targetLocationId;
        $wiz['target_work_order_id']         = 0;
        $wiz['target_work_order_text']       = $woNumber;
        $wiz['target_work_order_name']       = $woName;
        $wiz['target_work_order_project_id'] = $woProjectId;
        $wiz['target_work_order_statecode']  = $woStatecode;

        $_SESSION[$lastProjKey] = $sourceProjectId;
        $_SESSION[$lastLocKey]  = $sourceLocationId;

        if ($contextChanged) {
            $wiz['cart'] = [];
            $wiz['note'] = '';
        }

        header('Location: /lager/?page=uttak_shop');
        exit;
    }

    $actionModeUi         = $actionMode;
    $sourceProjectUi      = $sourceProjectId;
    $sourceLocationUi     = $sourceLocationId;
    $targetProjectUi      = $targetProjectId;
    $targetLocationUi     = $targetLocationId;
    $workOrderTextUi      = $woNumber;
    $workOrderNameUi      = $woName;
    $workOrderProjectIdUi = $woProjectId;
    $workOrderStatecodeUi = $woStatecode;

    $locationsSource = $postSourceLocations;
    $locationsTarget = $postTargetLocations;
}

$ajaxEndpoint = '/lager/api/workorder_search.php';
?>
<style>
    .wiz-head {
        display: flex;
        align-items: start;
        justify-content: space-between;
        gap: .75rem;
        flex-wrap: wrap;
        margin-top: .25rem;
        margin-bottom: .75rem;
    }
    .wiz-title {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 700;
        line-height: 1.2;
    }
    .wiz-sub {
        color: rgba(0,0,0,.55);
        font-size: .9rem;
        margin-top: .15rem;
    }
    .wiz-card {
        border-radius: 14px;
        border: 1px solid rgba(0,0,0,.06);
        box-shadow: 0 10px 20px rgba(0,0,0,.04);
    }
    .wiz-progress {
        height: 8px;
        border-radius: 999px;
        background: rgba(13,110,253,.10);
        overflow: hidden;
    }
    .wiz-progress > div {
        height: 100%;
        width: 33.333%;
        background: rgba(13,110,253,.55);
    }
    .wiz-field-lg.form-select,
    .wiz-field-lg.form-control {
        font-size: .95rem;
        line-height: 1.2;
    }
    .wiz-field-lg.form-select {
        padding-top: .55rem;
        padding-bottom: .55rem;
        padding-left: 1rem;
        padding-right: 2.25rem;
        min-height: 44px;
    }
    .wiz-field-lg.form-control {
        padding-top: .55rem;
        padding-bottom: .55rem;
        padding-left: 1rem;
        padding-right: 1rem;
        min-height: 44px;
    }
    .wiz-card .form-label {
        font-size: .95rem;
        margin-bottom: .35rem;
    }
    .mode-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: .85rem;
        margin-bottom: 1rem;
    }
    .mode-option { position: relative; }
    .mode-option input {
        position: absolute;
        inset: 0;
        opacity: 0;
        pointer-events: none;
    }
    .mode-card {
        display: block;
        padding: 1rem 1rem;
        border-radius: 14px;
        border: 1px solid rgba(0,0,0,.10);
        background: #fff;
        cursor: pointer;
        transition: .15s ease;
        box-shadow: 0 3px 10px rgba(0,0,0,.03);
        height: 100%;
    }
    .mode-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 22px rgba(0,0,0,.07);
        border-color: rgba(13,110,253,.25);
    }
    .mode-option input:checked + .mode-card {
        border-color: rgba(13,110,253,.65);
        background: rgba(13,110,253,.05);
        box-shadow: 0 12px 28px rgba(13,110,253,.12);
    }
    .mode-title {
        font-weight: 700;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: .55rem;
    }
    .mode-desc {
        margin-top: .45rem;
        color: rgba(0,0,0,.65);
        font-size: .9rem;
        line-height: 1.35;
    }
    .mode-badge {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        margin-top: .6rem;
        font-size: .78rem;
        color: rgba(0,0,0,.65);
        background: rgba(0,0,0,.04);
        padding: .28rem .55rem;
        border-radius: 999px;
    }
    .section-toggle { display: none; }
    .section-toggle.show { display: block; }
    .wo-search-wrap { position: relative; }
    .wo-results {
        position: absolute;
        inset: calc(100% + 6px) 0 auto 0;
        z-index: 50;
        background: #fff;
        border: 1px solid rgba(0,0,0,.12);
        border-radius: 12px;
        box-shadow: 0 12px 28px rgba(0,0,0,.12);
        max-height: 320px;
        overflow: auto;
        display: none;
    }
    .wo-results.show { display: block; }
    .wo-result-item {
        padding: .75rem .9rem;
        border-bottom: 1px solid rgba(0,0,0,.06);
        cursor: pointer;
    }
    .wo-result-item:last-child { border-bottom: 0; }
    .wo-result-item:hover,
    .wo-result-item.active { background: rgba(13,110,253,.06); }
    .wo-result-title {
        font-weight: 700;
        font-size: .95rem;
        line-height: 1.25;
    }
    .wo-result-sub {
        color: rgba(0,0,0,.65);
        font-size: .85rem;
        margin-top: .15rem;
        line-height: 1.25;
    }
    .wo-meta,
    .transfer-meta,
    .lager-meta {
        margin-top: .75rem;
        padding: .75rem .9rem;
        border-radius: 12px;
        background: rgba(13,110,253,.05);
        border: 1px solid rgba(13,110,253,.10);
    }
    .wo-meta-title,
    .transfer-meta-title,
    .lager-meta-title {
        font-weight: 700;
        margin-bottom: .25rem;
    }
    .wo-help,
    .transfer-help,
    .lager-help {
        font-size: .86rem;
        color: rgba(0,0,0,.58);
        margin-top: .35rem;
    }
    .wo-spinner {
        display: none;
        position: absolute;
        right: .9rem;
        top: 50%;
        transform: translateY(-50%);
        color: rgba(0,0,0,.45);
        font-size: .95rem;
        pointer-events: none;
    }
    .wo-spinner.show { display: block; }
    .wo-status {
        margin-top: .5rem;
        font-size: .88rem;
        color: rgba(0,0,0,.6);
        min-height: 1.2rem;
        white-space: pre-wrap;
    }
    .wo-debug {
        margin-top: .75rem;
        padding: .75rem .9rem;
        border-radius: 12px;
        background: #fff8e1;
        border: 1px solid #f2d48a;
        font-size: .85rem;
        white-space: pre-wrap;
        display: none;
    }
    .wo-debug.show { display: block; }
</style>

<div class="wiz-head">
    <div>
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-box-arrow-up-right"></i>
            <h3 class="wiz-title">Uttak / flytting</h3>
        </div>
        <div class="wiz-sub">Steg 1 av 3 — velg lagerfilter, lokasjon og mål</div>
        <div class="wiz-progress mt-2" aria-label="Fremdrift uttak">
            <div></div>
        </div>
    </div>

    <div class="wiz-actions">
        <a class="btn btn-outline-secondary btn-sm" href="?page=uttak&reset=1">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Nullstill
        </a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Feil:</strong><br><?= nl2br(h(implode("\n", $errors))) ?>
    </div>
<?php endif; ?>

<form method="post" class="mt-2" autocomplete="off">
    <input type="hidden" name="action" value="next">

    <div class="card wiz-card">
        <div class="card-body">

            <div class="mb-4">
                <label class="form-label"><strong>Hva vil du gjøre?</strong></label>

                <div class="mode-grid">
                    <label class="mode-option">
                        <input type="radio" name="action_mode" value="workorder" <?= $actionModeUi === 'workorder' ? 'checked' : '' ?>>
                        <span class="mode-card">
                            <span class="mode-title">
                                <i class="bi bi-tools"></i>
                                Uttak mot arbeidsordre
                            </span>
                            <span class="mode-desc">
                                Brukes når varen faktisk skal forbrukes på en arbeidsordre.
                            </span>
                            <span class="mode-badge">
                                <i class="bi bi-arrow-up-right-circle"></i>
                                Kostnadsføres mot jobb
                            </span>
                        </span>
                    </label>

                    <label class="mode-option">
                        <input type="radio" name="action_mode" value="transfer" <?= $actionModeUi === 'transfer' ? 'checked' : '' ?>>
                        <span class="mode-card">
                            <span class="mode-title">
                                <i class="bi bi-arrow-left-right"></i>
                                Flytting til annet lager / bil / forbrukslager
                            </span>
                            <span class="mode-desc">
                                Brukes når varen bare flyttes internt mellom lagerlokasjoner.
                            </span>
                            <span class="mode-badge">
                                <i class="bi bi-truck"></i>
                                Ingen arbeidsordre nødvendig
                            </span>
                        </span>
                    </label>
                </div>
            </div>

            <?php if ($projectsEnabled): ?>
                <div class="mb-3">
                    <label class="form-label"><strong>Lagerprosjekt / logisk lager</strong></label>
                    <select name="source_project_id" id="source_project_id" class="form-select form-select-lg wiz-field-lg">
                        <option value="0" <?= $sourceProjectUi === 0 ? 'selected' : '' ?>>Alle / uten prosjekt</option>
                        <?php foreach ($projectRows as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $sourceProjectUi === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= h($p['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="lager-help">Velg lagerprosjekt for kilden. Ved flytting følger til-prosjektet automatisk dette valget.</div>
                </div>
            <?php else: ?>
                <input type="hidden" name="source_project_id" id="source_project_id" value="0">
            <?php endif; ?>

            <div id="sourceLocationSection" class="section-toggle show">
                <div class="mb-3">
                    <label class="form-label" id="sourceLocationLabel"><strong><?= $actionModeUi === 'transfer' ? 'Fra-lager' : 'Uttakslokasjon' ?></strong></label>
                    <select name="source_location_id" id="source_location_id" class="form-select form-select-lg wiz-field-lg" required>
                        <option value="0">Velg lokasjon…</option>
                        <?php foreach ($locationsSource as $l): ?>
                            <option value="<?= (int)$l['id'] ?>" <?= $sourceLocationUi === (int)$l['id'] ? 'selected' : '' ?>>
                                <?= h($l['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($lastLocId > 0): ?>
                        <div class="text-muted small mt-1">Sist brukt lokasjon ligger øverst når den finnes i valgt filter.</div>
                    <?php endif; ?>
                </div>

                <div class="lager-meta" id="lagerMeta">
                    <div class="lager-meta-title">Valgt lagergrunnlag</div>
                    <div id="lagerMetaText">Velg fysisk lokasjon.</div>
                </div>
            </div>

            <div id="transferSection" class="section-toggle <?= ($actionModeUi === 'transfer' && $sourceLocationUi > 0) ? 'show' : '' ?>">
                <div class="mb-3 mt-3">
                    <label class="form-label"><strong>Til-prosjekt / logisk lager</strong></label>
                    <input
                        type="text"
                        id="target_project_display"
                        class="form-control form-control-lg wiz-field-lg"
                        value=""
                        readonly
                    >
                    <input type="hidden" name="target_project_id" id="target_project_id" value="<?= (int)$targetProjectUi ?>">
                    <div class="transfer-help">Denne følger alltid valgt lagerprosjekt automatisk.</div>
                </div>

                <div id="targetLocationSection" class="section-toggle <?= ($actionModeUi === 'transfer' && $sourceLocationUi > 0) ? 'show' : '' ?>">
                    <div class="mb-3">
                        <label class="form-label"><strong>Til-lager / bil / forbrukslager</strong></label>
                        <select name="target_location_id" id="target_location_id" class="form-select form-select-lg wiz-field-lg">
                            <option value="0">Velg mållokasjon…</option>
                            <?php foreach ($locationsTarget as $l): ?>
                                <option value="<?= (int)$l['id'] ?>" <?= $targetLocationUi === (int)$l['id'] ? 'selected' : '' ?>>
                                    <?= h($l['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="transfer-help">Her kan du velge blant alle aktive fysiske lokasjoner.</div>

                        <div class="transfer-meta mt-2" id="transferMeta">
                            <div class="transfer-meta-title">Intern flytting</div>
                            <div id="transferMetaText">Velg til-lager.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="workorderSection" class="section-toggle <?= ($actionModeUi === 'workorder' && $sourceLocationUi > 0) ? 'show' : '' ?>">
                <div class="mb-3 mt-3">
                    <label class="form-label"><strong>Arbeidsordre</strong></label>

                    <div class="wo-search-wrap">
                        <input
                            type="text"
                            class="form-control form-control-lg wiz-field-lg"
                            id="target_work_order_search"
                            name="target_work_order_search"
                            value="<?= h($workOrderTextUi !== '' ? ($workOrderTextUi . ($workOrderNameUi !== '' ? ' - ' . $workOrderNameUi : '')) : '') ?>"
                            placeholder="Søk på ordrenummer eller navn"
                            aria-label="Søk arbeidsordre"
                            spellcheck="false"
                            autocapitalize="off"
                            autocomplete="off"
                        >
                        <div class="wo-spinner" id="woSpinner">
                            <i class="bi bi-arrow-repeat"></i>
                        </div>
                        <div class="wo-results" id="woResults" role="listbox" aria-label="Søkeresultat arbeidsordre"></div>
                    </div>

                    <input type="hidden" name="target_work_order_text" id="target_work_order_text" value="<?= h($workOrderTextUi) ?>">
                    <input type="hidden" name="target_work_order_name" id="target_work_order_name" value="<?= h($workOrderNameUi) ?>">
                    <input type="hidden" name="target_work_order_project_id" id="target_work_order_project_id" value="<?= h($workOrderProjectIdUi) ?>">
                    <input type="hidden" name="target_work_order_statecode" id="target_work_order_statecode" value="<?= h($workOrderStatecodeUi) ?>">

                    <div class="wo-help">Arbeidsordre åpnes først når uttakslokasjon er valgt.</div>
                    <div class="wo-status" id="woStatus"></div>
                    <div class="wo-debug" id="woDebug"></div>

                    <div class="wo-meta" id="woMeta" style="<?= $workOrderTextUi !== '' ? '' : 'display:none;' ?>">
                        <div class="wo-meta-title" id="woMetaTitle"><?= h($workOrderTextUi) ?></div>
                        <div id="woMetaName"><?= h($workOrderNameUi) ?></div>
                    </div>
                </div>
            </div>

            <div class="d-grid">
                <button class="btn btn-primary btn-lg">
                    Neste <i class="bi bi-chevron-right ms-1"></i>
                </button>
            </div>

        </div>
    </div>
</form>

<script>
(function () {
    const ajaxEndpoint = <?= json_encode($ajaxEndpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    const inputEl               = document.getElementById('target_work_order_search');
    const resultsEl             = document.getElementById('woResults');
    const spinnerEl             = document.getElementById('woSpinner');
    const statusEl              = document.getElementById('woStatus');
    const debugEl               = document.getElementById('woDebug');

    const hiddenNumEl           = document.getElementById('target_work_order_text');
    const hiddenNameEl          = document.getElementById('target_work_order_name');
    const hiddenProjEl          = document.getElementById('target_work_order_project_id');
    const hiddenStateEl         = document.getElementById('target_work_order_statecode');

    const metaWrap              = document.getElementById('woMeta');
    const metaTitle             = document.getElementById('woMetaTitle');
    const metaName              = document.getElementById('woMetaName');

    const sourceLocationSection = document.getElementById('sourceLocationSection');
    const transferSection       = document.getElementById('transferSection');
    const targetLocationSection = document.getElementById('targetLocationSection');
    const workorderSection      = document.getElementById('workorderSection');
    const sourceLabel           = document.getElementById('sourceLocationLabel');
    const targetLocationEl      = document.getElementById('target_location_id');
    const sourceLocationEl      = document.getElementById('source_location_id');
    const sourceProjectEl       = document.getElementById('source_project_id');
    const targetProjectEl       = document.getElementById('target_project_id');
    const targetProjectDisplayEl = document.getElementById('target_project_display');
    const transferMetaText      = document.getElementById('transferMetaText');
    const lagerMetaText         = document.getElementById('lagerMetaText');
    const modeEls               = document.querySelectorAll('input[name="action_mode"]');

    if (!inputEl || !resultsEl) return;

    let debounceTimer = null;
    let abortCtrl = null;
    let items = [];
    let activeIndex = -1;

    function normalize(v) {
        v = (v || '').toString().trim().replace(/\s+/g, ' ');
        if (/^\d+$/.test(v)) return v;
        const flat = v.toUpperCase().replace(/\s+/g, '');
        if (/^HFA\-?\d+$/.test(flat)) return flat.replace(/^HFA\-?/i, '');
        return v;
    }

    function getMode() {
        const checked = document.querySelector('input[name="action_mode"]:checked');
        return checked ? checked.value : 'workorder';
    }

    function selectedText(selectEl) {
        if (!selectEl) return '';
        const opt = selectEl.options[selectEl.selectedIndex];
        return opt && opt.value !== '' ? (opt.text || '').trim() : '';
    }

    function selectedValue(selectEl) {
        return selectEl ? ((selectEl.value || '').trim()) : '';
    }

    function syncTargetProject() {
        if (!sourceProjectEl || !targetProjectEl) return;

        targetProjectEl.value = sourceProjectEl.value || '0';

        if (targetProjectDisplayEl) {
            const txt = selectedText(sourceProjectEl);
            targetProjectDisplayEl.value = txt || 'Alle / uten prosjekt';
        }
    }

    function setStatus(msg) {
        if (statusEl) statusEl.textContent = msg || '';
    }

    function setDebug(obj) {
        if (!debugEl) return;
        if (!obj) {
            debugEl.textContent = '';
            debugEl.classList.remove('show');
            return;
        }
        debugEl.textContent = typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2);
        debugEl.classList.add('show');
    }

    function showSpinner(show) {
        if (!spinnerEl) return;
        spinnerEl.classList.toggle('show', !!show);
    }

    function clearSelection(keepInputValue) {
        hiddenNumEl.value = '';
        hiddenNameEl.value = '';
        hiddenProjEl.value = '';
        hiddenStateEl.value = '';

        if (!keepInputValue) {
            inputEl.value = '';
        }

        if (metaWrap) metaWrap.style.display = 'none';
        if (metaTitle) metaTitle.textContent = '';
        if (metaName) metaName.textContent = '';
    }

    function applySelection(item) {
        hiddenNumEl.value   = item.number || '';
        hiddenNameEl.value  = item.name || '';
        hiddenProjEl.value  = item.project_id || '';
        hiddenStateEl.value = item.statecode || '';

        inputEl.value = item.label || item.number || '';

        if (metaWrap) metaWrap.style.display = '';
        if (metaTitle) metaTitle.textContent = item.number || '';
        if (metaName) metaName.textContent = item.name || '';

        setStatus('Valgt arbeidsordre.');
        setDebug(null);
        closeResults();
    }

    function closeResults() {
        resultsEl.classList.remove('show');
        resultsEl.innerHTML = '';
        items = [];
        activeIndex = -1;
    }

    function escapeHtml(str) {
        return (str || '').toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderResults(rows) {
        items = Array.isArray(rows) ? rows : [];
        activeIndex = -1;

        if (!items.length) {
            resultsEl.innerHTML = '<div class="wo-result-item"><div class="wo-result-sub">Ingen treff.</div></div>';
            resultsEl.classList.add('show');
            return;
        }

        const html = items.map((item, idx) => {
            const title = escapeHtml(item.number || '');
            const name  = escapeHtml(item.name || '');

            return ''
                + '<div class="wo-result-item" data-idx="' + idx + '" role="option" aria-selected="false">'
                +   '<div class="wo-result-title">' + title + '</div>'
                +   '<div class="wo-result-sub">' + (name || '&nbsp;') + '</div>'
                + '</div>';
        }).join('');

        resultsEl.innerHTML = html;
        resultsEl.classList.add('show');

        resultsEl.querySelectorAll('.wo-result-item[data-idx]').forEach(function (el) {
            el.addEventListener('mousedown', function (ev) {
                ev.preventDefault();
                const idx = parseInt(el.getAttribute('data-idx') || '-1', 10);
                if (idx >= 0 && items[idx]) {
                    applySelection(items[idx]);
                }
            });
        });
    }

    function setActiveIndex(nextIndex) {
        const nodes = resultsEl.querySelectorAll('.wo-result-item[data-idx]');
        if (!nodes.length) return;

        if (nextIndex < 0) nextIndex = nodes.length - 1;
        if (nextIndex >= nodes.length) nextIndex = 0;
        activeIndex = nextIndex;

        nodes.forEach(function (node, idx) {
            const active = idx === activeIndex;
            node.classList.toggle('active', active);
            node.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        const activeNode = nodes[activeIndex];
        if (activeNode && typeof activeNode.scrollIntoView === 'function') {
            activeNode.scrollIntoView({ block: 'nearest' });
        }
    }

    function updateLagerMeta() {
        const sourceProjectText = selectedText(sourceProjectEl);
        const sourceLocationText = selectedText(sourceLocationEl);

        if (!lagerMetaText) return;

        if (sourceLocationText) {
            if (sourceProjectText && selectedValue(sourceProjectEl) !== '0') {
                lagerMetaText.textContent = 'Tar fra ' + sourceProjectText + ' / ' + sourceLocationText + '.';
            } else {
                lagerMetaText.textContent = 'Tar fra ' + sourceLocationText + '.';
            }
            return;
        }

        if (sourceProjectText && selectedValue(sourceProjectEl) !== '0') {
            lagerMetaText.textContent = 'Valgt lagerfilter: ' + sourceProjectText + '. Velg lokasjon.';
            return;
        }

        lagerMetaText.textContent = 'Velg fysisk lokasjon med varer.';
    }

    function updateTransferMeta() {
        if (!transferMetaText) return;

        const fromProject = selectedText(sourceProjectEl);
        const fromLocation = selectedText(sourceLocationEl);
        const toLocation = selectedText(targetLocationEl);

        if (!toLocation) {
            transferMetaText.textContent = 'Velg til-lager.';
            return;
        }

        let text = 'Flytter';
        if (fromLocation) {
            text += ' fra ' + fromLocation;
            if (fromProject && selectedValue(sourceProjectEl) !== '0') {
                text += ' (' + fromProject + ')';
            }
        }

        text += ' til ' + toLocation + '.';
        transferMetaText.textContent = text;
    }

    function updateModeUi() {
        const mode = getMode();
        const hasSourceLocation = selectedValue(sourceLocationEl) !== '' && selectedValue(sourceLocationEl) !== '0';

        syncTargetProject();

        if (sourceLocationSection) {
            sourceLocationSection.classList.add('show');
        }

        if (mode === 'transfer') {
            if (transferSection) transferSection.classList.toggle('show', hasSourceLocation);
            if (workorderSection) workorderSection.classList.remove('show');
            if (targetLocationSection) targetLocationSection.classList.toggle('show', hasSourceLocation);
            if (sourceLabel) sourceLabel.innerHTML = '<strong>Fra-lager</strong>';
            closeResults();
            setStatus('');
            setDebug(null);
            updateTransferMeta();
        } else {
            if (transferSection) transferSection.classList.remove('show');
            if (targetLocationSection) targetLocationSection.classList.remove('show');
            if (workorderSection) workorderSection.classList.toggle('show', hasSourceLocation);
            if (sourceLabel) sourceLabel.innerHTML = '<strong>Uttakslokasjon</strong>';
        }

        updateLagerMeta();
    }

    async function doSearch(raw) {
        if (getMode() !== 'workorder') {
            closeResults();
            showSpinner(false);
            setStatus('');
            setDebug(null);
            return;
        }

        if (selectedValue(sourceLocationEl) === '' || selectedValue(sourceLocationEl) === '0') {
            closeResults();
            showSpinner(false);
            setStatus('');
            setDebug(null);
            return;
        }

        const q = normalize(raw);

        if (!q || q.length < 2) {
            closeResults();
            showSpinner(false);
            setStatus(q.length === 1 ? 'Skriv minst 2 tegn for å søke.' : '');
            setDebug(null);
            return;
        }

        if (abortCtrl) {
            abortCtrl.abort();
        }

        abortCtrl = new AbortController();
        showSpinner(true);
        setStatus('Søker…');
        setDebug(null);

        try {
            const url = ajaxEndpoint + '?q=' + encodeURIComponent(q);
            const res = await fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                signal: abortCtrl.signal,
                credentials: 'same-origin'
            });

            const text = await res.text();
            let json = null;

            try {
                json = JSON.parse(text);
            } catch (parseErr) {
                throw new Error(
                    'Server svarte ikke med gyldig JSON.\n' +
                    'URL: ' + url + '\n' +
                    'HTTP-status: ' + res.status + '\n' +
                    'Responsstart:\n' + text.slice(0, 1200)
                );
            }

            if (!res.ok || !json || json.ok !== true) {
                setDebug(json && json.debug ? json.debug : {
                    url: url,
                    status: res.status,
                    response: json
                });
                throw new Error((json && json.error) ? json.error : 'Ukjent feil ved søk.');
            }

            renderResults(Array.isArray(json.items) ? json.items : []);
            setStatus((json.items || []).length ? 'Velg arbeidsordre fra listen.' : 'Ingen treff.');
            setDebug(null);
        } catch (err) {
            if (err && err.name === 'AbortError') {
                return;
            }
            closeResults();
            setStatus('Søk feilet.');
            setDebug((err && err.message) ? err.message : 'Ukjent feil');
        } finally {
            showSpinner(false);
        }
    }

    inputEl.addEventListener('input', function () {
        clearSelection(true);

        const val = inputEl.value || '';
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(function () {
            doSearch(val);
        }, 250);
    });

    inputEl.addEventListener('focus', function () {
        const val = inputEl.value || '';
        if (getMode() === 'workorder' && normalize(val).length >= 2 && selectedValue(sourceLocationEl) !== '0') {
            doSearch(val);
        }
    });

    inputEl.addEventListener('keydown', function (e) {
        if (!resultsEl.classList.contains('show')) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex(activeIndex + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex(activeIndex - 1);
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && items[activeIndex]) {
                e.preventDefault();
                applySelection(items[activeIndex]);
            }
        } else if (e.key === 'Escape') {
            closeResults();
        }
    });

    document.addEventListener('click', function (e) {
        if (!resultsEl.contains(e.target) && e.target !== inputEl) {
            closeResults();
        }
    });

    modeEls.forEach(function (el) {
        el.addEventListener('change', updateModeUi);
    });

    if (sourceProjectEl) {
        sourceProjectEl.addEventListener('change', function () {
            syncTargetProject();
            updateModeUi();
        });
    }
    if (sourceLocationEl) {
        sourceLocationEl.addEventListener('change', function () {
            updateModeUi();
            updateTransferMeta();
        });
    }
    if (targetLocationEl) {
        targetLocationEl.addEventListener('change', updateTransferMeta);
    }

    syncTargetProject();
    updateModeUi();
    updateLagerMeta();
    updateTransferMeta();
})();
</script>