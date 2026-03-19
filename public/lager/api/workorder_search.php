<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\lager\api\workorder_search.php
// Rent JSON-endepunkt for søk av arbeidsordre via Workforce GraphQL + OAuth2.
// Søker både på wf_workordernumber og wf_name i to separate GraphQL-kall.

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

require_lager_login();

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('wf_api_json_out')) {
    function wf_api_json_out(array $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('wf_api_env_cache')) {
    function wf_api_env_cache(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $cache = [];

        $candidateFiles = [
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env',
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env',
            dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . '.env',
        ];

        foreach ($candidateFiles as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (str_starts_with($line, 'export ')) {
                    $line = trim(substr($line, 7));
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

                $val = str_replace(["\r", "\n"], '', $val);

                if (!array_key_exists($key, $cache)) {
                    $cache[$key] = $val;
                }
            }

            if (!empty($cache)) {
                break;
            }
        }

        return $cache;
    }
}

if (!function_exists('wf_api_env_value')) {
    function wf_api_env_value(string $key, ?string $default = null): ?string
    {
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return trim((string)$val);
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return trim((string)$_ENV[$key]);
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return trim((string)$_SERVER[$key]);
        }

        $cache = wf_api_env_cache();
        if (array_key_exists($key, $cache) && $cache[$key] !== '') {
            return trim((string)$cache[$key]);
        }

        return $default;
    }
}

if (!function_exists('wf_api_normalize_number_search')) {
    function wf_api_normalize_number_search(string $value): string
    {
        $v = trim($value);
        $v = preg_replace('/\s+/', '', $v);
        $v = strtoupper((string)$v);

        if ($v === '') {
            return '';
        }

        if (preg_match('/^\d+$/', $v)) {
            return $v;
        }

        if (preg_match('/^HFA\-?\d+$/', $v)) {
            return (string)preg_replace('/^HFA\-?/i', '', $v);
        }

        return $v;
    }
}

if (!function_exists('wf_api_graphql_string')) {
    function wf_api_graphql_string(string $value): string
    {
        $value = str_replace(
            ["\\", "\"", "\r", "\n", "\t"],
            ["\\\\", "\\\"", "\\r", "\\n", "\\t"],
            $value
        );
        return '"' . $value . '"';
    }
}

if (!function_exists('wf_api_http_post_form')) {
    function wf_api_http_post_form(string $url, array $fields, array $headers = [], int $timeout = 20): array
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
            CURLOPT_HEADER         => true,
        ]);

        $response = curl_exec($ch);
        $err      = curl_error($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype    = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $hsize    = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('HTTP-kall feilet: ' . ($err ?: 'ukjent feil'));
        }

        $rawHeaders = substr((string)$response, 0, $hsize);
        $body       = substr((string)$response, $hsize);

        return [
            'status'       => $code,
            'content_type' => $ctype,
            'headers'      => $rawHeaders,
            'body'         => (string)$body,
        ];
    }
}

if (!function_exists('wf_api_http_post_json')) {
    function wf_api_http_post_json(string $url, array $payload, array $headers = [], int $timeout = 20): array
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
            CURLOPT_HEADER         => true,
        ]);

        $response = curl_exec($ch);
        $err      = curl_error($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype    = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $hsize    = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('HTTP-kall feilet: ' . ($err ?: 'ukjent feil'));
        }

        $rawHeaders = substr((string)$response, 0, $hsize);
        $body       = substr((string)$response, $hsize);

        return [
            'status'       => $code,
            'content_type' => $ctype,
            'headers'      => $rawHeaders,
            'body'         => (string)$body,
        ];
    }
}

if (!function_exists('wf_api_get_access_token_debug')) {
    function wf_api_get_access_token_debug(): array
    {
        $clientId     = wf_api_env_value('WF_CLIENT_ID', '');
        $clientSecret = wf_api_env_value('WF_CLIENT_SECRET', '');
        $tokenUrl     = wf_api_env_value('WF_TOKEN_URL', '');
        $scope        = wf_api_env_value('WF_SCOPE', '');

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

        $resp = wf_api_http_post_form($tokenUrl, $postFields);

        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new RuntimeException(
                'Token-endepunkt svarte med HTTP ' . $resp['status'] .
                '. Body: ' . mb_substr((string)$resp['body'], 0, 1000)
            );
        }

        $json = json_decode((string)$resp['body'], true);
        if (!is_array($json)) {
            throw new RuntimeException(
                'Ugyldig JSON fra token-endepunkt. Content-Type: ' .
                ($resp['content_type'] ?: '(ukjent)') .
                '. Body: ' . mb_substr((string)$resp['body'], 0, 1000)
            );
        }

        $token = (string)($json['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException(
                'Fikk ikke access_token fra token-endepunkt. Body: ' .
                mb_substr((string)$resp['body'], 0, 1000)
            );
        }

        return [
            'token'        => $token,
            'token_status' => $resp['status'],
            'token_type'   => (string)$resp['content_type'],
        ];
    }
}

if (!function_exists('wf_api_extract_items')) {
    function wf_api_extract_items(array $json): array
    {
        $items = $json['data']['silver_wf_workforceworkorders']['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $number    = trim((string)($item['wf_workordernumber'] ?? ''));
            $name      = trim((string)($item['wf_name'] ?? ''));
            $projectId = trim((string)($item['_wf_workforceprojectid_value'] ?? ''));

            if ($number === '') {
                continue;
            }

            $out[] = [
                'number'     => $number,
                'name'       => $name,
                'project_id' => $projectId,
                'label'      => $name !== '' ? ($number . ' - ' . $name) : $number,
            ];
        }

        return $out;
    }
}

if (!function_exists('wf_api_graphql_search')) {
    function wf_api_graphql_search(string $query, string $token, string $apiUrl): array
    {
        $resp = wf_api_http_post_json($apiUrl, ['query' => $query], [
            'Authorization: Bearer ' . $token,
        ]);

        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new RuntimeException(
                'GraphQL-endepunkt svarte med HTTP ' . $resp['status'] .
                '. Content-Type: ' . ($resp['content_type'] ?: '(ukjent)') .
                '. Body: ' . mb_substr((string)$resp['body'], 0, 1200)
            );
        }

        $json = json_decode((string)$resp['body'], true);
        if (!is_array($json)) {
            throw new RuntimeException(
                'Ugyldig JSON fra GraphQL API. Content-Type: ' .
                ($resp['content_type'] ?: '(ukjent)') .
                '. Body: ' . mb_substr((string)$resp['body'], 0, 1200)
            );
        }

        if (!empty($json['errors']) && is_array($json['errors'])) {
            $first = (string)($json['errors'][0]['message'] ?? 'Ukjent GraphQL-feil.');
            throw new RuntimeException(
                'GraphQL-feil: ' . $first .
                '. Full respons: ' . mb_substr((string)$resp['body'], 0, 1200)
            );
        }

        return [
            'json' => $json,
            'resp' => $resp,
        ];
    }
}

if (!function_exists('wf_api_merge_results')) {
    function wf_api_merge_results(array $numberRows, array $nameRows, string $search): array
    {
        $merged = [];
        $seen   = [];

        foreach (array_merge($numberRows, $nameRows) as $row) {
            $key = strtoupper((string)($row['number'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $row;
        }

        $needle = mb_strtolower(trim($search));

        usort($merged, static function (array $a, array $b) use ($needle): int {
            $aNum  = mb_strtolower((string)($a['number'] ?? ''));
            $bNum  = mb_strtolower((string)($b['number'] ?? ''));
            $aName = mb_strtolower((string)($a['name'] ?? ''));
            $bName = mb_strtolower((string)($b['name'] ?? ''));

            $aScore = 0;
            $bScore = 0;

            if ($needle !== '') {
                if (str_contains($aNum, $needle))  $aScore += 4;
                if (str_contains($aName, $needle)) $aScore += 3;
                if (str_starts_with($aNum, $needle))  $aScore += 3;
                if (str_starts_with($aName, $needle)) $aScore += 2;

                if (str_contains($bNum, $needle))  $bScore += 4;
                if (str_contains($bName, $needle)) $bScore += 3;
                if (str_starts_with($bNum, $needle))  $bScore += 3;
                if (str_starts_with($bName, $needle)) $bScore += 2;
            }

            if ($aScore !== $bScore) {
                return $bScore <=> $aScore;
            }

            return strcmp($aNum, $bNum);
        });

        return $merged;
    }
}

if (!function_exists('wf_api_search_work_orders_debug')) {
    function wf_api_search_work_orders_debug(string $search, int $limit = 10): array
    {
        $apiUrl = wf_api_env_value('WF_API_URL', '');
        if ($apiUrl === '') {
            throw new RuntimeException(
                'WF_API_URL mangler i .env. Sjekket getenv/$_ENV/$_SERVER + filene: '
                . dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env, '
                . dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env, '
                . dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . '.env'
            );
        }

        $search = trim($search);
        $limit  = max(1, min(25, $limit));

        if ($search === '') {
            return [
                'items' => [],
                'debug' => ['message' => 'Tom søkestreng.'],
            ];
        }

        $tokenInfo = wf_api_get_access_token_debug();

        $numberTerm = wf_api_normalize_number_search($search);
        $nameTerm   = $search;

        $numberQuery = 'query { silver_wf_workforceworkorders(filter: { wf_workordernumber: { contains: '
            . wf_api_graphql_string($numberTerm)
            . ' } }, first: ' . $limit
            . ') { items { wf_workordernumber wf_name _wf_workforceprojectid_value statecode } } }';

        $nameQuery = 'query { silver_wf_workforceworkorders(filter: { wf_name: { contains: '
            . wf_api_graphql_string($nameTerm)
            . ' } }, first: ' . $limit
            . ') { items { wf_workordernumber wf_name _wf_workforceprojectid_value statecode } } }';

        $numberResult = wf_api_graphql_search($numberQuery, $tokenInfo['token'], $apiUrl);
        $nameResult   = wf_api_graphql_search($nameQuery, $tokenInfo['token'], $apiUrl);

        $numberRows = wf_api_extract_items($numberResult['json']);
        $nameRows   = wf_api_extract_items($nameResult['json']);

        $merged = wf_api_merge_results($numberRows, $nameRows, $search);

        return [
            'items' => array_slice($merged, 0, $limit),
            'debug' => [
                'search'                 => $search,
                'number_term'            => $numberTerm,
                'name_term'              => $nameTerm,
                'token_http_status'      => $tokenInfo['token_status'],
                'token_content_type'     => $tokenInfo['token_type'],
                'number_query'           => $numberQuery,
                'name_query'             => $nameQuery,
                'number_count'           => count($numberRows),
                'name_count'             => count($nameRows),
                'merged_count'           => count($merged),
                'number_graphql_status'  => $numberResult['resp']['status'],
                'name_graphql_status'    => $nameResult['resp']['status'],
                'api_url'                => $apiUrl,
                'request_uri'            => (string)($_SERVER['REQUEST_URI'] ?? ''),
                'script_name'            => (string)($_SERVER['SCRIPT_NAME'] ?? ''),
            ],
        ];
    }
}

try {
    $q = trim((string)($_GET['q'] ?? ''));

    if ($q === '') {
        wf_api_json_out([
            'ok'    => true,
            'items' => [],
            'debug' => [
                'message'     => 'Tom søkestreng.',
                'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
                'script_name' => (string)($_SERVER['SCRIPT_NAME'] ?? ''),
            ],
        ]);
    }

    $result = wf_api_search_work_orders_debug($q, 10);

    wf_api_json_out([
        'ok'    => true,
        'items' => $result['items'] ?? [],
        'debug' => $result['debug'] ?? [],
    ]);
} catch (\Throwable $e) {
    wf_api_json_out([
        'ok'    => false,
        'items' => [],
        'error' => $e->getMessage(),
        'debug' => [
            'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'script_name' => (string)($_SERVER['SCRIPT_NAME'] ?? ''),
            'raw_q'       => (string)($_GET['q'] ?? ''),
            'env_sources' => [
                'getenv' => getenv('WF_API_URL') !== false ? 'present' : 'missing',
                '_ENV'   => isset($_ENV['WF_API_URL']) ? 'present' : 'missing',
                '_SERVER'=> isset($_SERVER['WF_API_URL']) ? 'present' : 'missing',
                'files_checked' => [
                    dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env',
                    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env',
                    dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . '.env',
                ],
            ],
        ],
    ], 500);
}