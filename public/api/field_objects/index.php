<?php
// Path: \public\api\field_objects\index.php
// API: Feltobjekter (read-only)
// - URL: /api/field_objects/
// - Auth: Authorization: Bearer <token>  ELLER  X-Api-Key: <token>
// - Tokens lagres i tabell: api_tokens (hash sha256)
// - Krever scope: field_objects:read
//
// Returnerer:
// - base: alle kolonner fra node_locations (dynamisk SELECT * via information_schema)
// - dynamic_fields: alle dynamiske felter (custom fields) per node_location
//   * Bygger fra node_location_custom_fields (+ groups/options hvis finnes)
//   * Verdier fra node_location_custom_field_values
//
// Query params:
//    * limit   (valgfri; hvis utelatt eller 0 => ingen limit)
//    * offset  (default 0)
//    * q       (valgfri; søker i vanlige tekstkolonner hvis de finnes)
//    * fields  (valgfri; komma-separert whitelist av base-kolonner fra node_locations)
//    * exclude (valgfri; komma-separert blacklist av base-kolonner fra node_locations)
//    * include_dynamic (default 1) 0/1
//    * dynamic_format  (default "map")  map | detailed
//        - map:  dynamic_fields blir { "<fieldKey>": value, ... }
//        - detailed: dynamic_fields blir liste med {id,key,label,type,group,value}
//
//    * resolve_options (default 0) 0/1
//        - hvis 1 og option-tabell finnes: select/multiselect får i tillegg labels (best-effort)
//
// Merk:
// - Ingen hard max på limit. Bruk limit/offset ved behov.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');

function json_out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function safe_int($v, int $default = 0): int {
    if ($v === null) return $default;
    if (is_numeric($v)) return (int)$v;
    return $default;
}

function safe_bool01($v, int $default = 0): int {
    if ($v === null) return $default;
    $s = strtolower(trim((string)$v));
    if ($s === '1' || $s === 'true' || $s === 'yes' || $s === 'on') return 1;
    if ($s === '0' || $s === 'false' || $s === 'no' || $s === 'off') return 0;
    return $default;
}

function parse_csv_list(string $csv): array {
    $csv = trim($csv);
    if ($csv === '') return [];
    $parts = preg_split('/[,\s;]+/', $csv) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
    return $parts;
}

function quote_ident(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT 1
              FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
             LIMIT 1
        ");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}

function get_table_columns(PDO $pdo, string $table): array {
    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
             ORDER BY ORDINAL_POSITION
        ");
        $stmt->execute([':t' => $table]);
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $cols = array_values(array_unique(array_map('strval', $cols)));
        return $cols;
    } catch (\Throwable $e) {
        return [];
    }
}

function pick_columns(array $existing, array $wanted): array {
    $set = array_flip(array_map('strtolower', $existing));
    $out = [];
    foreach ($wanted as $w) {
        if (isset($set[strtolower($w)])) $out[] = $w;
    }
    return $out;
}

function get_token_from_request(): string {
    // 1) Authorization: Bearer <token>
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    // Noen IIS-oppsett sender Authorization via alternate keys
    if ($auth === '') $auth = (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    // getallheaders fallback
    if ($auth === '' && function_exists('getallheaders')) {
        $h = getallheaders();
        if (is_array($h)) {
            foreach ($h as $k => $v) {
                if (strcasecmp((string)$k, 'Authorization') === 0) { $auth = (string)$v; break; }
            }
        }
    }

    if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
        return trim($m[1]);
    }

    // 2) X-Api-Key: <token>
    $x = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($x !== '') return trim($x);

    // 3) api_key query param (valgfritt)
    $q = (string)($_GET['api_key'] ?? '');
    if ($q !== '') return trim($q);

    return '';
}

function token_hash(string $token): string {
    return hash('sha256', $token);
}

function token_has_scope(string $scopesCsv, string $need): bool {
    $scopes = array_values(array_filter(array_map('trim', explode(',', (string)$scopesCsv))));
    $scopesLower = array_map('strtolower', $scopes);
    return in_array(strtolower($need), $scopesLower, true);
}

// ---------------------------------------------------------
// Bootstrap / Autoload (viktig fordi denne kjører utenfor /public/index.php)
// ---------------------------------------------------------

$autoloadCandidates = [
    __DIR__ . '/../../../vendor/autoload.php', // /public/api/field_objects -> /vendor
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

$autoloadOk = false;
foreach ($autoloadCandidates as $p) {
    if (is_file($p)) { require_once $p; $autoloadOk = true; break; }
}
if (!$autoloadOk) {
    json_out(500, ['error' => 'server_error', 'message' => 'Fant ikke vendor/autoload.php. Sjekk filstier.']);
}

// ---------------------------------------------------------
// DB
// ---------------------------------------------------------

$pdo = null;
try {
    if (class_exists('App\\Database') && method_exists('App\\Database', 'getConnection')) {
        $pdo = \App\Database::getConnection();
    }
} catch (\Throwable $e) {
    $pdo = null;
}
if (!$pdo instanceof PDO) {
    json_out(500, ['error' => 'server_error', 'message' => 'Fant ingen database-tilkobling (Database::getConnection()).']);
}

// ---------------------------------------------------------
// Auth: validate token
// ---------------------------------------------------------

$token = get_token_from_request();
if ($token === '') {
    json_out(401, ['error' => 'unauthorized', 'message' => 'Mangler token. Bruk Authorization: Bearer <token> eller X-Api-Key: <token>.']);
}

if (!table_exists($pdo, 'api_tokens')) {
    json_out(500, ['error' => 'server_error', 'message' => 'Manglar tabell api_tokens. Åpne /?page=api_admin for å opprette tokens-tabellen.']);
}

$hash = token_hash($token);

$stmt = $pdo->prepare("
    SELECT id, name, scopes, is_active
      FROM api_tokens
     WHERE token_hash = :h
     LIMIT 1
");
$stmt->execute([':h' => $hash]);
$trow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trow) json_out(401, ['error' => 'unauthorized', 'message' => 'Ugyldig token.']);
if ((int)($trow['is_active'] ?? 0) !== 1) json_out(403, ['error' => 'forbidden', 'message' => 'Token er deaktivert.']);

$scopesCsv = (string)($trow['scopes'] ?? '');
if (!token_has_scope($scopesCsv, 'field_objects:read')) {
    json_out(403, ['error' => 'forbidden', 'message' => 'Token mangler scope field_objects:read.']);
}

// Oppdater last_used_at (best effort)
try {
    $u = $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id");
    $u->execute([':id' => (int)$trow['id']]);
} catch (\Throwable $e) {}

// ---------------------------------------------------------
// Params
// ---------------------------------------------------------

$limitProvided = array_key_exists('limit', $_GET);
$limit  = safe_int($_GET['limit'] ?? 0, 0); // 0 => ingen limit
$offset = safe_int($_GET['offset'] ?? 0, 0);
$q      = trim((string)($_GET['q'] ?? ''));

if ($limit < 0) $limit = 0;
if ($offset < 0) $offset = 0;

$includeDynamic = safe_bool01($_GET['include_dynamic'] ?? '1', 1);
$dynamicFormat  = strtolower(trim((string)($_GET['dynamic_format'] ?? 'map'))); // map|detailed
if (!in_array($dynamicFormat, ['map', 'detailed'], true)) $dynamicFormat = 'map';

$resolveOptions = safe_bool01($_GET['resolve_options'] ?? '0', 0);

$fieldsReq  = parse_csv_list((string)($_GET['fields'] ?? ''));
$excludeReq = parse_csv_list((string)($_GET['exclude'] ?? ''));

// ---------------------------------------------------------
// Base data: node_locations (ALLE kolonner som default)
// ---------------------------------------------------------

$table = 'node_locations';
if (!table_exists($pdo, $table)) {
    json_out(500, ['error' => 'server_error', 'message' => "Manglar tabell {$table} (feltobjekter)."]);
}

$cols = get_table_columns($pdo, $table);
if (!$cols) json_out(500, ['error' => 'server_error', 'message' => "Fant ingen kolonner i {$table}."]);

$selectCols = $cols;

// fields whitelist (base)
if ($fieldsReq) {
    $selectCols = pick_columns($cols, $fieldsReq);
    if (!$selectCols) {
        json_out(400, ['error' => 'bad_request', 'message' => 'Ingen av feltene i "fields" finnes i node_locations.']);
    }
}

// exclude blacklist (base)
if ($excludeReq) {
    $excludeLower = array_flip(array_map('strtolower', $excludeReq));
    $selectCols = array_values(array_filter($selectCols, fn($c) => !isset($excludeLower[strtolower((string)$c)])));
    if (!$selectCols) {
        json_out(400, ['error' => 'bad_request', 'message' => 'Alle base-felter ble filtrert bort av "exclude".']);
    }
}

$selectSql = implode(', ', array_map('quote_ident', $selectCols));

// Best-effort søk i vanlige tekstkolonner
$where = [];
$args  = [];
if ($q !== '') {
    $searchCols = pick_columns($cols, ['name', 'description', 'address', 'street', 'city', 'type', 'slug', 'address_line1', 'address_line2']);
    if ($searchCols) {
        $parts = [];
        foreach ($searchCols as $c) {
            $parts[] = quote_ident($c) . " LIKE ?";
            $args[] = '%' . $q . '%';
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Sortering: updated_at -> created_at -> id
$orderBy = 'ORDER BY ' . (in_array('id', $cols, true) ? quote_ident('id') : quote_ident($cols[0])) . ' DESC';
if (in_array('updated_at', $cols, true) && in_array('id', $cols, true)) {
    $orderBy = 'ORDER BY ' . quote_ident('updated_at') . ' DESC, ' . quote_ident('id') . ' DESC';
} elseif (in_array('created_at', $cols, true) && in_array('id', $cols, true)) {
    $orderBy = 'ORDER BY ' . quote_ident('created_at') . ' DESC, ' . quote_ident('id') . ' DESC';
}

// Total count (best effort)
$total = null;
try {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM " . quote_ident($table) . " {$whereSql}");
    $cnt->execute($args);
    $total = (int)($cnt->fetchColumn() ?: 0);
} catch (\Throwable $e) {
    $total = null;
}

// LIMIT/OFFSET
$limitSql = '';
if ($limit > 0) {
    $limitSql = "LIMIT {$limit} OFFSET {$offset}";
} elseif ($offset > 0) {
    // MySQL: uendelig limit for offset
    $limitSql = "LIMIT 18446744073709551615 OFFSET {$offset}";
}

// Hent base items
$sql = "SELECT {$selectSql} FROM " . quote_ident($table) . " {$whereSql} {$orderBy} {$limitSql}";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ---------------------------------------------------------
// Dynamiske felter (custom fields) – bulk, uten N+1
// ---------------------------------------------------------

/**
 * Returnerer en "key" for feltet:
 * - Prioritet: field_key/key/slug/name (hvis finnes)
 * - fallback: label
 * - fallback: field_<id>
 */
function field_key_for(array $field, array $fieldCols): string {
    $candidates = ['field_key','key','slug','name','code'];
    foreach ($candidates as $c) {
        if (in_array($c, $fieldCols, true)) {
            $v = trim((string)($field[$c] ?? ''));
            if ($v !== '') return $v;
        }
    }
    $label = trim((string)($field['label'] ?? ''));
    if ($label !== '') return $label;
    $id = (int)($field['id'] ?? 0);
    return $id > 0 ? ('field_' . $id) : 'field';
}

/**
 * Type->verdi (samme logikk som view – men returnerer som JSON-vennlige typer)
 */
function dynamic_value_for(array $field, ?array $vrow): mixed {
    if (!$vrow) return null;

    $type = (string)($field['field_type'] ?? '');
    $type = strtolower($type);

    if ($type === 'bool') {
        return ((int)($vrow['value_bool'] ?? 0) === 1);
    }
    if ($type === 'number') {
        $n = $vrow['value_number'] ?? null;
        if ($n === null || $n === '') return null;
        // Returner som number hvis mulig
        return is_numeric($n) ? (0 + $n) : $n;
    }
    if ($type === 'date') {
        $d = (string)($vrow['value_date'] ?? '');
        return $d !== '' ? $d : null;
    }
    if ($type === 'datetime') {
        $d = (string)($vrow['value_datetime'] ?? '');
        return $d !== '' ? $d : null;
    }
    if ($type === 'json') {
        $j = (string)($vrow['value_json'] ?? '');
        if ($j === '') return null;
        $decoded = json_decode($j, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $j;
    }
    if ($type === 'multiselect') {
        $j = (string)($vrow['value_json'] ?? '');
        if ($j === '') return [];
        $decoded = json_decode($j, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
        return [];
    }
    if ($type === 'select') {
        $t = (string)($vrow['value_text'] ?? '');
        return $t !== '' ? $t : null;
    }

    $t = (string)($vrow['value_text'] ?? '');
    return $t !== '' ? $t : null;
}

$dynamicMeta = [
    'enabled' => (bool)$includeDynamic,
    'tables_present' => [],
    'field_count' => 0,
];

if ($includeDynamic && $items) {
    $t_fields   = 'node_location_custom_fields';
    $t_values   = 'node_location_custom_field_values';
    $t_groups   = 'node_location_field_groups';
    $t_options  = 'node_location_custom_field_options';

    $hasFields  = table_exists($pdo, $t_fields);
    $hasValues  = table_exists($pdo, $t_values);
    $hasGroups  = table_exists($pdo, $t_groups);
    $hasOptions = table_exists($pdo, $t_options);

    $dynamicMeta['tables_present'] = [
        $t_fields  => $hasFields,
        $t_values  => $hasValues,
        $t_groups  => $hasGroups,
        $t_options => $hasOptions,
    ];

    // Hvis disse tabellene ikke finnes, kan vi ikke hente dynamiske felter
    if ($hasFields && $hasValues) {
        // Samle node IDs + template IDs (hvis finnes i node_locations)
        $nodeIds = [];
        $templateIds = [];

        foreach ($items as $row) {
            if (isset($row['id'])) $nodeIds[] = (int)$row['id'];
            if (isset($row['template_id'])) {
                $tid = (int)$row['template_id'];
                if ($tid > 0) $templateIds[] = $tid;
            }
        }

        $nodeIds = array_values(array_unique(array_filter($nodeIds, fn($x) => $x > 0)));
        $templateIds = array_values(array_unique(array_filter($templateIds, fn($x) => $x > 0)));

        // Hent fields for alle templates i batch
        $fieldsByTemplate = [];
        $fieldCols = get_table_columns($pdo, $t_fields);

        // Kolonner vi prøver å hente hvis finnes
        $wantFieldCols = array_values(array_unique(array_merge(
            ['id','template_id','group_id','field_type','label','sort_order'],
            ['field_key','key','slug','name','code'] // mulige key-kolonner
        )));
        $actualFieldCols = pick_columns($fieldCols, $wantFieldCols);
        if (!in_array('id', $actualFieldCols, true)) $actualFieldCols[] = 'id';
        if (!in_array('template_id', $actualFieldCols, true)) $actualFieldCols[] = 'template_id';
        if (!in_array('field_type', $actualFieldCols, true)) $actualFieldCols[] = 'field_type';
        if (!in_array('label', $actualFieldCols, true)) $actualFieldCols[] = 'label';

        // Gruppemapping
        $groupsById = [];
        if ($hasGroups) {
            $gCols = get_table_columns($pdo, $t_groups);
            $wantG = pick_columns($gCols, ['id','name','sort_order']);
            if ($wantG) {
                $gsql = "SELECT " . implode(', ', array_map('quote_ident', $wantG)) . " FROM " . quote_ident($t_groups);
                $gst = $pdo->query($gsql);
                foreach (($gst ? $gst->fetchAll(PDO::FETCH_ASSOC) : []) as $g) {
                    $gid = (int)($g['id'] ?? 0);
                    if ($gid > 0) $groupsById[$gid] = $g;
                }
            }
        }

        // Options mapping (best-effort)
        $optionsByFieldId = [];
        if ($resolveOptions && $hasOptions) {
            $oCols = get_table_columns($pdo, $t_options);
            $wantO = pick_columns($oCols, ['id','field_id','opt_value','opt_label','sort_order']);
            if ($wantO) {
                $osql = "SELECT " . implode(', ', array_map('quote_ident', $wantO)) . " FROM " . quote_ident($t_options) . " ORDER BY " . (in_array('sort_order',$wantO,true) ? quote_ident('sort_order') . ', ' : '') . quote_ident('opt_label');
                $ost = $pdo->query($osql);
                foreach (($ost ? $ost->fetchAll(PDO::FETCH_ASSOC) : []) as $o) {
                    $fid = (int)($o['field_id'] ?? 0);
                    if ($fid > 0) $optionsByFieldId[$fid][] = $o;
                }
            }
        }

        if ($templateIds) {
            $in = implode(',', array_fill(0, count($templateIds), '?'));
            $fsql = "SELECT " . implode(', ', array_map('quote_ident', $actualFieldCols)) .
                    " FROM " . quote_ident($t_fields) .
                    " WHERE template_id IN ($in) " .
                    (in_array('sort_order', $actualFieldCols, true) ? " ORDER BY sort_order, label" : " ORDER BY label");
            $fst = $pdo->prepare($fsql);
            $fst->execute($templateIds);
            $allFields = $fst->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($allFields as &$f) {
                $gid = (int)($f['group_id'] ?? 0);
                $f['group_name'] = ($gid > 0 && isset($groupsById[$gid])) ? (string)($groupsById[$gid]['name'] ?? '') : '';
                $fid = (int)($f['id'] ?? 0);
                if ($resolveOptions && $fid > 0) {
                    $f['options'] = $optionsByFieldId[$fid] ?? [];
                }
            }
            unset($f);

            foreach ($allFields as $f) {
                $tid = (int)($f['template_id'] ?? 0);
                if ($tid > 0) $fieldsByTemplate[$tid][] = $f;
            }
        }

        // Hent values for alle nodeIds i batch
        $valuesByNode = []; // [node_id][field_id] = row
        $vCols = get_table_columns($pdo, $t_values);
        $wantV = pick_columns($vCols, ['node_location_id','field_id','value_text','value_number','value_date','value_datetime','value_bool','value_json']);
        // Sørg for nødvendige
        if (!in_array('node_location_id', $wantV, true)) $wantV[] = 'node_location_id';
        if (!in_array('field_id', $wantV, true)) $wantV[] = 'field_id';

        if ($nodeIds) {
            $in2 = implode(',', array_fill(0, count($nodeIds), '?'));
            $vsql = "SELECT " . implode(', ', array_map('quote_ident', $wantV)) .
                    " FROM " . quote_ident($t_values) .
                    " WHERE node_location_id IN ($in2)";
            $vst = $pdo->prepare($vsql);
            $vst->execute($nodeIds);
            $allVals = $vst->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($allVals as $v) {
                $nid = (int)($v['node_location_id'] ?? 0);
                $fid = (int)($v['field_id'] ?? 0);
                if ($nid > 0 && $fid > 0) {
                    $valuesByNode[$nid][$fid] = $v;
                }
            }
        }

        // Slå sammen per item
        $dynamicMeta['field_count'] = 0;

        foreach ($items as $i => $row) {
            $nid = isset($row['id']) ? (int)$row['id'] : 0;
            $tid = isset($row['template_id']) ? (int)$row['template_id'] : 0;

            $fieldsForTemplate = ($tid > 0) ? ($fieldsByTemplate[$tid] ?? []) : [];
            $valsForNode = ($nid > 0) ? ($valuesByNode[$nid] ?? []) : [];

            if (!$fieldsForTemplate) {
                // Ingen dynamiske fields for denne (mangler template eller ingen definert)
                $items[$i]['dynamic_fields'] = ($dynamicFormat === 'detailed') ? [] : new stdClass();
                continue;
            }

            $dynamicMeta['field_count'] += count($fieldsForTemplate);

            if ($dynamicFormat === 'detailed') {
                $out = [];
                foreach ($fieldsForTemplate as $f) {
                    $fid = (int)($f['id'] ?? 0);
                    $key = field_key_for($f, $fieldCols);
                    $valRow = ($fid > 0) ? ($valsForNode[$fid] ?? null) : null;

                    $value = dynamic_value_for($f, $valRow);

                    $entry = [
                        'id'    => $fid,
                        'key'   => $key,
                        'label' => (string)($f['label'] ?? ''),
                        'type'  => (string)($f['field_type'] ?? ''),
                        'group' => (string)($f['group_name'] ?? ''),
                        'value' => $value,
                    ];

                    // Best-effort labels for select/multiselect
                    if ($resolveOptions && isset($f['options']) && is_array($f['options'])) {
                        $map = [];
                        foreach ($f['options'] as $o) {
                            $ov = (string)($o['opt_value'] ?? '');
                            $ol = (string)($o['opt_label'] ?? '');
                            if ($ov !== '') $map[$ov] = $ol;
                        }
                        $t = strtolower((string)($f['field_type'] ?? ''));
                        if ($t === 'select' && is_string($value) && $value !== '' && isset($map[$value])) {
                            $entry['value_label'] = $map[$value];
                        }
                        if ($t === 'multiselect' && is_array($value)) {
                            $labels = [];
                            foreach ($value as $vv) {
                                $vv = (string)$vv;
                                $labels[] = $map[$vv] ?? $vv;
                            }
                            $entry['value_labels'] = $labels;
                        }
                    }

                    $out[] = $entry;
                }
                $items[$i]['dynamic_fields'] = $out;
            } else {
                // map
                $out = [];
                foreach ($fieldsForTemplate as $f) {
                    $fid = (int)($f['id'] ?? 0);
                    $key = field_key_for($f, $fieldCols);
                    $valRow = ($fid > 0) ? ($valsForNode[$fid] ?? null) : null;
                    $value = dynamic_value_for($f, $valRow);

                    // Unngå nøkkel-kollisjon ved like labels:
                    // Hvis key allerede finnes, suffix med _<id>
                    if (array_key_exists($key, $out) && $fid > 0) {
                        $key = $key . '_' . $fid;
                    }
                    $out[$key] = $value;

                    // Best-effort labels for select (resolve_options)
                    if ($resolveOptions && isset($f['options']) && is_array($f['options'])) {
                        $t = strtolower((string)($f['field_type'] ?? ''));
                        if ($t === 'select' && is_string($value) && $value !== '') {
                            $map = [];
                            foreach ($f['options'] as $o) {
                                $ov = (string)($o['opt_value'] ?? '');
                                $ol = (string)($o['opt_label'] ?? '');
                                if ($ov !== '') $map[$ov] = $ol;
                            }
                            if (isset($map[$value])) {
                                $out[$key . '_label'] = $map[$value];
                            }
                        }
                    }
                }

                // Hvis tomt, returner {} (ikke [])
                $items[$i]['dynamic_fields'] = $out ?: new stdClass();
            }
        }
    }
}

// ---------------------------------------------------------
// Output
// ---------------------------------------------------------

json_out(200, [
    'data' => $items,
    'paging' => [
        'limit'    => $limitProvided ? $limit : null,
        'offset'   => $offset,
        'total'    => $total,
        'returned' => count($items),
    ],
    'meta' => [
        'token_name' => (string)($trow['name'] ?? ''),
        'scopes'     => array_values(array_filter(array_map('trim', explode(',', $scopesCsv)))),
        'base_table' => $table,
        'base_fields_returned' => $selectCols,
        'include_dynamic' => (bool)$includeDynamic,
        'dynamic_format'  => $dynamicFormat,
        'resolve_options' => (bool)$resolveOptions,
        'dynamic' => $dynamicMeta,
        'search' => $q,
    ],
]);
