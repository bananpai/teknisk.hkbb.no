<?php
// Path: /public/pages/jira_create_test.php
// Enkel testside for å opprette Jira-sak direkte fra Teknisk Side.
// Formål:
// - Minimalt skjema for feilsøking
// - Kun opprette én Jira-sak
// - Tydelig logging til PHP error log
// - Ingen databasekrav

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('esc')) {
    function esc(?string $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('jira_basic_auth_header')) {
    function jira_basic_auth_header(string $email, string $apiToken): string
    {
        return 'Authorization: Basic ' . base64_encode($email . ':' . $apiToken);
    }
}

if (!function_exists('jira_adf_doc_from_text')) {
    function jira_adf_doc_from_text(string $text): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($text));
        $content = [];

        foreach ((array)$lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }

            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $line,
                    ],
                ],
            ];
        }

        if (!$content) {
            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => '',
                    ],
                ],
            ];
        }

        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $content,
        ];
    }
}

if (!function_exists('jira_http_json')) {
    function jira_http_json(string $method, string $url, array $headers, ?array $payload, int &$httpCode, string &$rawOut): ?array
    {
        $httpCode = 0;
        $rawOut = '';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $requestHeaders = $headers;
        $requestHeaders[] = 'Accept: application/json';

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $requestHeaders[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json !== false ? $json : '{}');

            error_log('Jira request payload: ' . ($json !== false ? $json : '[json_encode failed]'));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        $rawOut = is_string($response) ? $response : '';

        error_log('Jira HTTP ' . $httpCode . ': ' . ($rawOut !== '' ? $rawOut : '[empty response]'));
        if ($response === false) {
            error_log('Jira cURL error: ' . ($curlError !== '' ? $curlError : 'unknown_error'));
        }

        curl_close($ch);

        if ($response === false) {
            $httpCode = 0;
            return [
                '_curl_error' => $curlError !== '' ? $curlError : 'unknown_error',
            ];
        }

        $decoded = json_decode($rawOut, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (class_exists('\\App\\Support\\Env')) {
    \App\Support\Env::load();
}

$jBaseUrl           = (string)(class_exists('\\App\\Support\\Env') ? \App\Support\Env::get('JIRA_BASE_URL', 'https://hkraft.atlassian.net') : 'https://hkraft.atlassian.net');
$jUserEmail         = (string)(class_exists('\\App\\Support\\Env') ? \App\Support\Env::get('JIRA_USER_EMAIL', '') : '');
$jApiToken          = (string)(class_exists('\\App\\Support\\Env') ? \App\Support\Env::get('JIRA_API_TOKEN', '') : '');
$jProjectKey        = (string)(class_exists('\\App\\Support\\Env') ? \App\Support\Env::get('JIRA_PROJECT_KEY', 'FTD') : 'FTD');
$jIssueTypeIncident = (string)(class_exists('\\App\\Support\\Env') ? \App\Support\Env::get('JIRA_ISSUE_TYPE_INCIDENT', 'Hendelse') : 'Hendelse');
$jIssueTypePlanned  = (string)(class_exists('\\App\\Support\\Env') ? \App\Support\Env::get('JIRA_ISSUE_TYPE_PLANNED', 'Endringsordre med godkjenning') : 'Endringsordre med godkjenning');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_token'];

$ok = '';
$err = '';
$createdKey = '';
$createdUrl = '';
$debugHttp = null;
$debugRaw = '';

$formTitle = 'Testsak fra Teknisk Side';
$formType = 'incident';
$formDescription = "Dette er en test opprettet fra enkel Jira-testside.\nBrukes for å feilsøke API-oppretting.";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
        $err = 'Ugyldig CSRF-token.';
    } else {
        $formTitle = trim((string)($_POST['summary'] ?? ''));
        $formType = (string)($_POST['type'] ?? 'incident');
        $formDescription = trim((string)($_POST['description'] ?? ''));

        if ($formTitle === '') {
            $err = 'Tittel må fylles ut.';
        } elseif (!in_array($formType, ['incident', 'planned'], true)) {
            $err = 'Ugyldig type.';
        } elseif ($jBaseUrl === '' || $jUserEmail === '' || $jApiToken === '' || $jProjectKey === '') {
            $err = 'Mangler Jira-oppsett i .env (JIRA_BASE_URL, JIRA_USER_EMAIL, JIRA_API_TOKEN, JIRA_PROJECT_KEY).';
        } else {
            $issueTypeName = ($formType === 'planned') ? $jIssueTypePlanned : $jIssueTypeIncident;

            $payload = [
                'fields' => [
                    'project' => [
                        'key' => $jProjectKey,
                    ],
                    'summary' => $formTitle,
                    'description' => jira_adf_doc_from_text($formDescription),
                    'issuetype' => [
                        'name' => $issueTypeName,
                    ],
                ],
            ];

            $url = rtrim($jBaseUrl, '/') . '/rest/api/3/issue';
            $httpCode = 0;
            $rawOut = '';

            $response = jira_http_json(
                'POST',
                $url,
                [jira_basic_auth_header($jUserEmail, $jApiToken)],
                $payload,
                $httpCode,
                $rawOut
            );

            $debugHttp = $httpCode;
            $debugRaw = $rawOut;

            if ($httpCode >= 200 && $httpCode < 300 && is_array($response) && !empty($response['key'])) {
                $createdKey = (string)$response['key'];
                $createdUrl = rtrim($jBaseUrl, '/') . '/browse/' . $createdKey;
                $ok = 'Sak opprettet i Jira: ' . $createdKey;
            } else {
                $parts = [];
                $parts[] = 'Jira-feil (' . $httpCode . ')';

                if (is_array($response) && !empty($response['errorMessages']) && is_array($response['errorMessages'])) {
                    $parts[] = implode(' | ', array_map('strval', $response['errorMessages']));
                }

                if (is_array($response) && !empty($response['errors']) && is_array($response['errors'])) {
                    foreach ($response['errors'] as $field => $message) {
                        $parts[] = $field . ': ' . (string)$message;
                    }
                }

                if (is_array($response) && isset($response['_curl_error'])) {
                    $parts[] = (string)$response['_curl_error'];
                }

                if ($rawOut !== '') {
                    $parts[] = mb_substr($rawOut, 0, 2000);
                }

                $err = implode(' — ', array_filter($parts, static fn($v) => trim((string)$v) !== ''));
            }
        }
    }
}
?>

<div class="container py-4" style="max-width: 900px;">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h3 class="mb-1">Jira test – opprett sak</h3>
            <div class="text-muted small">Enkel testside for kun å opprette ordre/sak i Jira Cloud</div>
        </div>
        <a href="/?page=events" class="btn btn-outline-secondary btn-sm">Tilbake</a>
    </div>

    <?php if ($ok !== ''): ?>
        <div class="alert alert-success">
            <div><?= esc($ok) ?></div>
            <?php if ($createdUrl !== ''): ?>
                <div class="mt-2">
                    <a href="<?= esc($createdUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success">
                        Åpne i Jira
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($err !== ''): ?>
        <div class="alert alert-danger" style="white-space: pre-wrap;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="post" class="card shadow-sm">
        <div class="card-header">
            <strong>Opprett test-sak</strong>
        </div>
        <div class="card-body">
            <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">

            <div class="mb-3">
                <label class="form-label">Prosjekt</label>
                <input type="text" class="form-control" value="<?= esc($jProjectKey) ?>" disabled>
                <div class="form-text">Leses fra .env</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Type</label>
                <select name="type" class="form-select">
                    <option value="incident" <?= $formType === 'incident' ? 'selected' : '' ?>>Hendelse</option>
                    <option value="planned" <?= $formType === 'planned' ? 'selected' : '' ?>>Endringsordre med godkjenning</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Tittel</label>
                <input type="text" name="summary" class="form-control" value="<?= esc($formTitle) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Beskrivelse</label>
                <textarea name="description" class="form-control" rows="8"><?= esc($formDescription) ?></textarea>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Opprett i Jira</button>
                <a href="/?page=jira_create_test" class="btn btn-outline-secondary">Nullstill</a>
            </div>
        </div>
    </form>

    <div class="card mt-3">
        <div class="card-header">
            <strong>Debug</strong>
        </div>
        <div class="card-body">
            <div class="small text-muted mb-2">
                Denne siden logger til PHP error log med format som support ba om.
            </div>
            <ul class="small mb-3">
                <li><code>Jira HTTP &lt;kode&gt;: &lt;respons&gt;</code></li>
                <li><code>Jira cURL error: &lt;feil&gt;</code></li>
                <li><code>Jira request payload: &lt;json&gt;</code></li>
            </ul>

            <?php if ($debugHttp !== null): ?>
                <div class="mb-2"><strong>HTTP-kode:</strong> <?= (int)$debugHttp ?></div>
            <?php endif; ?>

            <?php if ($debugRaw !== ''): ?>
                <div>
                    <strong>Rå respons:</strong>
                    <pre class="mt-2 p-3 bg-light border rounded small" style="white-space: pre-wrap;"><?= esc($debugRaw) ?></pre>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>