<?php
// public/api/node_locations.php
use App\Database;

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();

function jsonOut(int $code, $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function readJsonBody(): array {
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function apiKeyHash(string $key): string {
    return hash('sha256', $key);
}
function requireApi(PDO $pdo): array {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($key === '') jsonOut(401, ['error' => 'Missing X-Api-Key']);

    $hash = apiKeyHash($key);
    $st = $pdo->prepare("SELECT * FROM api_clients WHERE api_key_hash=:h AND is_active=1");
    $st->execute([':h' => $hash]);
    $client = $st->fetch(PDO::FETCH_ASSOC);
    if (!$client) jsonOut(401, ['error' => 'Invalid API key']);

    $pdo->prepare("UPDATE api_clients SET last_used_at=NOW() WHERE id=:id")->execute([':id' => (int)$client['id']]);
    return $client;
}

/**
 * Bygger korrekt link til "view"-siden i UI:
 *   /?page=node_location_view&id=123
 * Fungerer også om løsningen ligger i en undermappe (best effort).
 */
function uiBaseUrl(): string {
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($proto) === 'https';
    $scheme = $https ? 'https' : 'http';

    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));

    // Finn "rot" ved å ta bort /api fra script-dir (best effort)
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    $dir = rtrim($dir, '/');

    // typisk: /api -> ''  eller  /public/api -> /public
    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }

    return $scheme . '://' . $host . $dir;
}
function nodeLocationViewUrl(int $id): string {
    return uiBaseUrl() . '/?page=node_location_view&id=' . $id;
}

function loadTemplateFields(PDO $pdo, int $templateId): array {
    $st = $pdo->prepare("SELECT * FROM node_location_custom_fields WHERE template_id=:t");
    $st->execute([':t' => $templateId]);
    $fields = $st->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($fields as $f) $map[$f['field_key']] = $f;
    return $map;
}
function upsertValue(PDO $pdo, int $nodeId, array $field, $raw, string $actor): void {
    $type = $field['field_type'];

    $payload = [
        'vt' => null, 'vn' => null, 'vd' => null, 'vdt' => null, 'vb' => null, 'vj' => null
    ];

    if ($type === 'bool') {
        $payload['vb'] = ($raw ? 1 : 0);
    } elseif ($type === 'number') {
        $payload['vn'] = ($raw === '' || $raw === null) ? null : (string)(0 + $raw);
    } elseif ($type === 'date') {
        $payload['vd'] = ($raw === '' || $raw === null) ? null : (string)$raw;
    } elseif ($type === 'datetime') {
        $payload['vdt'] = ($raw === '' || $raw === null) ? null : (string)$raw;
    } elseif ($type === 'json') {
        $payload['vj'] = ($raw === '' || $raw === null) ? null : json_encode($raw, JSON_UNESCAPED_UNICODE);
    } elseif ($type === 'multiselect') {
        $arr = is_array($raw) ? array_values($raw) : [];
        $payload['vj'] = json_encode($arr, JSON_UNESCAPED_UNICODE);
    } else {
        $payload['vt'] = ($raw === '' || $raw === null) ? null : (string)$raw;
    }

    $pdo->prepare("
      INSERT INTO node_location_custom_field_values
        (node_location_id, field_id, value_text, value_number, value_date, value_datetime, value_bool, value_json, updated_by)
      VALUES
        (:nid, :fid, :vt, :vn, :vd, :vdt, :vb, :vj, :ub)
      ON DUPLICATE KEY UPDATE
        value_text=VALUES(value_text),
        value_number=VALUES(value_number),
        value_date=VALUES(value_date),
        value_datetime=VALUES(value_datetime),
        value_bool=VALUES(value_bool),
        value_json=VALUES(value_json),
        updated_by=VALUES(updated_by)
    ")->execute([
        ':nid' => $nodeId,
        ':fid' => (int)$field['id'],
        ':vt' => $payload['vt'],
        ':vn' => $payload['vn'],
        ':vd' => $payload['vd'],
        ':vdt' => $payload['vdt'],
        ':vb' => $payload['vb'],
        ':vj' => $payload['vj'],
        ':ub' => $actor
    ]);
}

$client = requireApi($pdo);
$actor = 'api:' . ($client['name'] ?? 'client');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);

    if ($id > 0) {
        $st = $pdo->prepare("SELECT * FROM node_locations WHERE id=:id");
        $st->execute([':id' => $id]);
        $nl = $st->fetch(PDO::FETCH_ASSOC);
        if (!$nl) jsonOut(404, ['error' => 'Not found']);

        $st = $pdo->prepare("
          SELECT f.field_key, f.field_type, v.value_text, v.value_number, v.value_date, v.value_datetime, v.value_bool, v.value_json
            FROM node_location_custom_field_values v
            JOIN node_location_custom_fields f ON f.id = v.field_id
           WHERE v.node_location_id=:id
        ");
        $st->execute([':id' => $id]);
        $fields = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $type = $r['field_type'];
            if ($type === 'bool') $fields[$r['field_key']] = (int)$r['value_bool'];
            elseif ($type === 'number') $fields[$r['field_key']] = $r['value_number'] !== null ? (0 + $r['value_number']) : null;
            elseif ($type === 'date') $fields[$r['field_key']] = $r['value_date'];
            elseif ($type === 'datetime') $fields[$r['field_key']] = $r['value_datetime'];
            elseif ($type === 'multiselect') $fields[$r['field_key']] = $r['value_json'] ? json_decode($r['value_json'], true) : [];
            elseif ($type === 'json') $fields[$r['field_key']] = $r['value_json'] ? json_decode($r['value_json'], true) : null;
            else $fields[$r['field_key']] = $r['value_text'];
        }

        $nl['fields'] = $fields;

        // ✅ Korrekt link til view-siden
        $nl['view_url'] = nodeLocationViewUrl((int)$nl['id']);

        jsonOut(200, $nl);
    }

    // list
    $rows = $pdo->query("SELECT id, template_id, name, slug, status, lat, lon, external_source, external_id, updated_at FROM node_locations ORDER BY updated_at DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

    // ✅ Legg på view-link per rad
    foreach ($rows as &$r) {
        $r['view_url'] = nodeLocationViewUrl((int)$r['id']);
    }
    unset($r);

    jsonOut(200, ['results' => $rows]);
}

$body = readJsonBody();

if ($method === 'POST') {
    $templateId = (int)($body['template_id'] ?? 0);
    $name       = trim((string)($body['name'] ?? ''));
    $slug       = trim((string)($body['slug'] ?? ''));
    $status     = trim((string)($body['status'] ?? 'active'));
    $lat        = $body['lat'] ?? null;
    $lon        = $body['lon'] ?? null;

    if ($templateId <= 0) jsonOut(400, ['error' => 'template_id required']);
    if ($name === '') jsonOut(400, ['error' => 'name required']);
    if ($slug === '') $slug = preg_replace('/[^a-z0-9_]+/', '-', strtolower($name));

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
          INSERT INTO node_locations (template_id, name, slug, status, lat, lon, external_source, external_id, created_by)
          VALUES (:t, :n, :s, :st, :lat, :lon, :es, :eid, :cb)
        ")->execute([
            ':t' => $templateId,
            ':n' => $name,
            ':s' => $slug,
            ':st' => $status,
            ':lat' => ($lat === null ? null : $lat),
            ':lon' => ($lon === null ? null : $lon),
            ':es' => ($body['external_source'] ?? null),
            ':eid' => ($body['external_id'] ?? null),
            ':cb' => $actor
        ]);
        $id = (int)$pdo->lastInsertId();

        $fieldMap = loadTemplateFields($pdo, $templateId);
        $fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];
        foreach ($fields as $k => $v) {
            if (!isset($fieldMap[$k])) continue;
            upsertValue($pdo, $id, $fieldMap[$k], $v, $actor);
        }

        $pdo->commit();

        // ✅ Returner også korrekt view-link ved opprettelse
        jsonOut(201, ['id' => $id, 'view_url' => nodeLocationViewUrl($id)]);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        jsonOut(500, ['error' => $e->getMessage()]);
    }
}

if ($method === 'PATCH') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) jsonOut(400, ['error' => 'id required']);

    $st = $pdo->prepare("SELECT * FROM node_locations WHERE id=:id");
    $st->execute([':id' => $id]);
    $nl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$nl) jsonOut(404, ['error' => 'Not found']);

    $templateId = (int)$nl['template_id'];

    $pdo->beginTransaction();
    try {
        $updates = [];
        $params = [':id' => $id];

        foreach (['name','slug','status','lat','lon','external_source','external_id'] as $k) {
            if (array_key_exists($k, $body)) {
                $updates[] = "$k=:$k";
                $params[":$k"] = $body[$k];
            }
        }
        if ($updates) {
            $pdo->prepare("UPDATE node_locations SET " . implode(',', $updates) . ", updated_at=NOW() WHERE id=:id")->execute($params);
        }

        $fieldMap = loadTemplateFields($pdo, $templateId);
        $fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];
        foreach ($fields as $k => $v) {
            if (!isset($fieldMap[$k])) continue;
            upsertValue($pdo, $id, $fieldMap[$k], $v, $actor);
        }

        $pdo->commit();

        // ✅ Returner view-link etter oppdatering også
        jsonOut(200, ['ok' => true, 'view_url' => nodeLocationViewUrl($id)]);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        jsonOut(500, ['error' => $e->getMessage()]);
    }
}

jsonOut(405, ['error' => 'Method not allowed']);
