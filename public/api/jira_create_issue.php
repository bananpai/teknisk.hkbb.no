<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\api\jira_create_issue.php

declare(strict_types=1);

/**
 * Enkel Jira Cloud issue-oppretter for teknisk.hkbb.no
 *
 * POST JSON:
 * {
 *   "summary": "Min nye sak",
 *   "description": "Valgfri beskrivelse",
 *   "issue_type": "Task"
 * }
 *
 * Alternativt form-data / query:
 * - summary
 * - description
 * - issue_type
 *
 * Svar:
 * - JSON med status, Jira-saksnummer og rå respons ved behov
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Leser .env-fil uten eksterne biblioteker.
 */
function load_env_file(string $envPath): array
{
    $result = [];

    if (!is_file($envPath) || !is_readable($envPath)) {
        return $result;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $result;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));

        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))
        ) {
            $val = substr($val, 1, -1);
        }

        $result[$key] = $val;
        $_ENV[$key] = $val;

        if (getenv($key) === false) {
            putenv($key . '=' . $val);
        }
    }

    return $result;
}

/**
 * Henter env-verdi med fallback.
 */
function env_value(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string)$value;
}

/**
 * Leser JSON body hvis sendt som application/json.
 */
function get_request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    $raw = file_get_contents('php://input');
    $json = [];

    if (stripos($contentType, 'application/json') !== false && is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return array_merge($_GET, $_POST, $json);
}

/**
 * Lager Jira ADF description fra enkel tekst.
 */
function build_adf_description(string $text): array
{
    $text = trim($text);

    if ($text === '') {
        $text = 'Opprettet fra teknisk.hkbb.no';
    }

    $paragraphs = preg_split("/\r\n|\r|\n/", $text) ?: [];
    $content = [];

    foreach ($paragraphs as $line) {
        $line = trim((string)$line);

        $content[] = [
            'type' => 'paragraph',
            'content' => $line !== ''
                ? [[
                    'type' => 'text',
                    'text' => $line,
                ]]
                : [],
        ];
    }

    if ($content === []) {
        $content[] = [
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'Opprettet fra teknisk.hkbb.no',
            ]],
        ];
    }

    return [
        'type' => 'doc',
        'version' => 1,
        'content' => $content,
    ];
}

/**
 * Sender JSON-respons og avslutter.
 */
function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// Last .env fra prosjektrot
$projectRoot = dirname(__DIR__, 2);
$envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';
load_env_file($envPath);

$jiraBaseUrl   = rtrim((string)env_value('JIRA_BASE_URL', ''), '/');
$jiraUserEmail = (string)env_value('JIRA_USER_EMAIL', '');
$jiraApiToken  = (string)env_value('JIRA_API_TOKEN', '');
$projectKey    = (string)env_value('JIRA_PROJECT_KEY', 'FTD');
$defaultType   = (string)env_value('JIRA_ISSUE_TYPE', 'Task');

if ($jiraBaseUrl === '' || $jiraUserEmail === '' || $jiraApiToken === '' || $projectKey === '') {
    respond(500, [
        'ok' => false,
        'error' => 'Mangler Jira-konfigurasjon i .env',
        'required_env' => [
            'JIRA_BASE_URL',
            'JIRA_USER_EMAIL',
            'JIRA_API_TOKEN',
            'JIRA_PROJECT_KEY',
            'JIRA_ISSUE_TYPE',
        ],
    ]);
}

$data = get_request_data();

$summary = trim((string)($data['summary'] ?? ''));
$description = trim((string)($data['description'] ?? ''));
$issueType = trim((string)($data['issue_type'] ?? $defaultType));

if ($summary === '') {
    respond(422, [
        'ok' => false,
        'error' => 'Feltet "summary" er påkrevd.',
        'example_request' => [
            'summary' => 'Testsak fra API',
            'description' => 'Dette er en test.',
            'issue_type' => $defaultType,
        ],
    ]);
}

$payload = [
    'fields' => [
        'project' => [
            'key' => $projectKey,
        ],
        'summary' => $summary,
        'issuetype' => [
            'name' => $issueType !== '' ? $issueType : $defaultType,
        ],
        'description' => build_adf_description($description),
    ],
];

$ch = curl_init($jiraBaseUrl . '/rest/api/3/issue');

if ($ch === false) {
    respond(500, [
        'ok' => false,
        'error' => 'Kunne ikke initialisere cURL.',
    ]);
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($jiraUserEmail . ':' . $jiraApiToken),
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 30,
]);

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($responseBody === false) {
    respond(502, [
        'ok' => false,
        'error' => 'Feil ved kall mot Jira.',
        'curl_error' => $curlError,
    ]);
}

$decoded = json_decode($responseBody, true);

if ($httpCode < 200 || $httpCode >= 300) {
    respond($httpCode > 0 ? $httpCode : 500, [
        'ok' => false,
        'error' => 'Jira returnerte feil.',
        'jira_http_code' => $httpCode,
        'jira_response' => $decoded ?? $responseBody,
        'sent_payload' => $payload,
    ]);
}

$issueKey = (string)($decoded['key'] ?? '');
$issueId  = (string)($decoded['id'] ?? '');
$issueUrl = $issueKey !== '' ? ($jiraBaseUrl . '/browse/' . rawurlencode($issueKey)) : null;

respond(200, [
    'ok' => true,
    'message' => 'Jira-sak opprettet.',
    'project_key' => $projectKey,
    'saksnummer' => $issueKey,
    'issue_key' => $issueKey,
    'issue_id' => $issueId,
    'issue_url' => $issueUrl,
    'jira_response' => $decoded,
]);