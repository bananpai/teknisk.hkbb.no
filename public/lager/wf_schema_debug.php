<?php
// One-shot debug: lists all queryable entity names from WF Fabric GraphQL endpoint.
// DELETE this file after use.

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_lager_login();

function env_val(string $key): string
{
    $v = getenv($key);
    if ($v !== false && $v !== '') return trim($v);
    if (!empty($_ENV[$key]))    return trim((string)$_ENV[$key]);
    if (!empty($_SERVER[$key])) return trim((string)$_SERVER[$key]);

    $candidates = [
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env',
        dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . '.env',
    ];
    foreach ($candidates as $f) {
        if (!is_file($f)) continue;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            if (trim(substr($line, 0, $pos)) === $key) {
                $val = trim(substr($line, $pos + 1));
                if (strlen($val) >= 2 && $val[0] === '"' && $val[-1] === '"') $val = substr($val, 1, -1);
                if (strlen($val) >= 2 && $val[0] === "'" && $val[-1] === "'") $val = substr($val, 1, -1);
                return $val;
            }
        }
    }
    return '';
}

function fabric_post(string $url, array $payload, string $token): array
{
    $json = json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => $body];
}

$errors = [];
$token  = null;
$schema = null;

try {
    $clientId     = env_val('WF_CLIENT_ID');
    $clientSecret = env_val('WF_CLIENT_SECRET');
    $tokenUrl     = env_val('WF_TOKEN_URL');
    $scope        = env_val('WF_SCOPE');
    $apiUrl       = env_val('WF_API_URL');

    if (!$clientId || !$clientSecret || !$tokenUrl || !$apiUrl) {
        throw new RuntimeException('WF env-variabler mangler. Sjekk .env.');
    }

    // Get token
    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'scope'         => $scope,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $tokenBody = (string)curl_exec($ch);
    $tokenCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($tokenCode < 200 || $tokenCode >= 300) {
        throw new RuntimeException("Token-kall feilet HTTP $tokenCode: " . mb_substr($tokenBody, 0, 500));
    }

    $tokenJson = json_decode($tokenBody, true);
    $token = (string)($tokenJson['access_token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('Fikk ikke access_token. Body: ' . mb_substr($tokenBody, 0, 500));
    }

    // Introspection query — list all query root fields
    $introspectionQuery = '{ __schema { queryType { fields { name description } } } }';
    $resp = fabric_post($apiUrl, ['query' => $introspectionQuery], $token);

    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        throw new RuntimeException("Introspection HTTP {$resp['status']}: " . mb_substr($resp['body'], 0, 800));
    }

    $schema = json_decode($resp['body'], true);
    if (!is_array($schema)) {
        throw new RuntimeException('Ugyldig JSON fra introspection. Body: ' . mb_substr($resp['body'], 0, 800));
    }

    if (!empty($schema['errors'])) {
        $errors[] = 'GraphQL errors: ' . json_encode($schema['errors']);
    }

} catch (\Throwable $e) {
    $errors[] = $e->getMessage();
}

$fields = $schema['data']['__schema']['queryType']['fields'] ?? [];

?><!DOCTYPE html>
<html lang="no">
<head>
<meta charset="UTF-8">
<title>WF Schema Debug</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
<h2>WF GraphQL Schema — tilgjengelige entiteter</h2>
<p class="text-muted small">API-URL: <code><?= htmlspecialchars(env_val('WF_API_URL')) ?></code></p>

<?php if ($errors): ?>
<div class="alert alert-danger">
<?php foreach ($errors as $e): ?>
<p class="mb-1"><?= htmlspecialchars($e) ?></p>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($token && !$errors): ?>
<div class="alert alert-success mb-3">Token OK.</div>
<?php endif; ?>

<?php if ($fields): ?>
<p><strong><?= count($fields) ?> entiteter funnet:</strong></p>
<table class="table table-sm table-bordered table-striped">
<thead><tr><th>Navn</th><th>Beskrivelse</th></tr></thead>
<tbody>
<?php foreach ($fields as $f): ?>
<tr>
  <td><code><?= htmlspecialchars((string)($f['name'] ?? '')) ?></code></td>
  <td class="text-muted small"><?= htmlspecialchars((string)($f['description'] ?? '')) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h5 class="mt-4">Entiteter som inneholder "workorder":</h5>
<?php
$wo = array_filter($fields, fn($f) => stripos((string)($f['name'] ?? ''), 'workorder') !== false);
?>
<?php if ($wo): ?>
<ul>
<?php foreach ($wo as $f): ?>
<li><code><?= htmlspecialchars((string)$f['name']) ?></code></li>
<?php endforeach; ?>
</ul>
<?php else: ?>
<p class="text-warning">Ingen entiteter med "workorder" funnet.</p>
<?php endif; ?>

<?php else: ?>
<?php if (!$errors): ?>
<div class="alert alert-warning">Ingen entiteter returnert fra introspection.</div>
<?php endif; ?>
<?php endif; ?>

<hr>
<p class="text-danger small">Slett denne filen etter bruk.</p>
</div>
</body>
</html>
