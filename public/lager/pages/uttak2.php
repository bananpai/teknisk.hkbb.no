<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\lager\pages\uttak.php
// Uttak wizard steg 1/3
//
// Endret 2026-03-10:
// - Arbeidsordre søkes direkte via /lager/api/workorder_search.php
// - Auto-suggest vises under feltet mens bruker skriver
// - Uttak skjer mot arbeidsordre, ikke prosjekt
// - Søker både på arbeidsordrenummer og wf_name
// - Viser ikke statecode eller prosjekt-ID i UI

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

    $flat = strtoupper(preg_replace('/\s+/', '', $normalized));
    foreach ($rows as $row) {
        $num = strtoupper(trim((string)($row['number'] ?? '')));
        $cmp = preg_replace('/^HFA\-?/i', '', $num);

        if ($cmp === $flat || $num === ('HFA-' . $flat)) {
            return $row;
        }
    }

    return null;
}

$errors = [];

if (!tableExists($pdo, 'inv_locations')) {
    $errors[] = 'Mangler tabell inv_locations.';
}

if (!isset($_SESSION['lager_uttak_wizard']) || !is_array($_SESSION['lager_uttak_wizard'])) {
    $_SESSION['lager_uttak_wizard'] = [
        'source_location_id'               => 0,
        'target_project_id'                => 0,
        'target_work_order_id'             => 0,
        'target_work_order_text'           => '',
        'target_work_order_name'           => '',
        'target_work_order_project_id'     => '',
        'target_work_order_statecode'      => '',
        'cart'                             => [],
        'note'                             => '',
    ];
}
$wiz =& $_SESSION['lager_uttak_wizard'];

$wiz['target_work_order_name']       = (string)($wiz['target_work_order_name'] ?? '');
$wiz['target_work_order_project_id'] = (string)($wiz['target_work_order_project_id'] ?? '');
$wiz['target_work_order_statecode']  = (string)($wiz['target_work_order_statecode'] ?? '');
$wiz['target_project_id']            = (int)($wiz['target_project_id'] ?? 0);
$wiz['target_work_order_id']         = (int)($wiz['target_work_order_id'] ?? 0);

$currentUsername = (string)($u['username'] ?? $u['user'] ?? $u['email'] ?? '');
$lastLocKey = 'lager_uttak_last_location_id__' . sha1($currentUsername ?: 'unknown');

if (!isset($_SESSION[$lastLocKey])) {
    $_SESSION[$lastLocKey] = 0;
}

if (isset($_GET['reset']) && (string)$_GET['reset'] === '1') {
    $wiz = [
        'source_location_id'               => 0,
        'target_project_id'                => 0,
        'target_work_order_id'             => 0,
        'target_work_order_text'           => '',
        'target_work_order_name'           => '',
        'target_work_order_project_id'     => '',
        'target_work_order_statecode'      => '',
        'cart'                             => [],
        'note'                             => '',
    ];
    header('Location: /lager/?page=uttak');
    exit;
}

$locations = [];
if (!$errors) {
    try {
        $stmt = $pdo->query("SELECT id, code, name FROM inv_locations WHERE is_active=1 ORDER BY name, code");
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $id = (int)$r['id'];
            $label = trim((string)$r['code']) !== '' ? ($r['code'] . ' – ' . $r['name']) : (string)$r['name'];
            $locations[] = ['id' => $id, 'label' => $label];
        }
    } catch (\Throwable $e) {
    }
}

$sourceLocationUi = (int)($wiz['source_location_id'] ?? 0);
if ($sourceLocationUi <= 0) {
    $sourceLocationUi = (int)($_SESSION[$lastLocKey] ?? 0);
}

$workOrderTextUi      = (string)($wiz['target_work_order_text'] ?? '');
$workOrderNameUi      = (string)($wiz['target_work_order_name'] ?? '');
$workOrderProjectIdUi = (string)($wiz['target_work_order_project_id'] ?? '');
$workOrderStatecodeUi = (string)($wiz['target_work_order_statecode'] ?? '');

$lastLocId = (int)($_SESSION[$lastLocKey] ?? 0);
if ($lastLocId > 0 && !empty($locations)) {
    usort($locations, function ($a, $b) use ($lastLocId) {
        $ai = (int)$a['id'];
        $bi = (int)$b['id'];
        if ($ai === $lastLocId) return -1;
        if ($bi === $lastLocId) return 1;
        return strcasecmp((string)$a['label'], (string)$b['label']);
    });
}

$action = (string)($_POST['action'] ?? '');
if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'next') {
    $sourceLocationId = (int)($_POST['source_location_id'] ?? 0);

    $woNumber    = trim((string)($_POST['target_work_order_text'] ?? ''));
    $woName      = trim((string)($_POST['target_work_order_name'] ?? ''));
    $woProjectId = trim((string)($_POST['target_work_order_project_id'] ?? ''));
    $woStatecode = trim((string)($_POST['target_work_order_statecode'] ?? ''));

    $manualSearch = trim((string)($_POST['target_work_order_search'] ?? ''));

    if ($sourceLocationId <= 0) {
        $errors[] = 'Velg uttakslokasjon.';
    }

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

    $contextChanged =
        ((int)$wiz['source_location_id'] !== (int)$sourceLocationId) ||
        ((string)$wiz['target_work_order_text'] !== (string)$woNumber);

    if (!$errors) {
        $wiz['source_location_id']           = $sourceLocationId;
        $wiz['target_project_id']            = 0;
        $wiz['target_work_order_id']         = 0;
        $wiz['target_work_order_text']       = $woNumber;
        $wiz['target_work_order_name']       = $woName;
        $wiz['target_work_order_project_id'] = $woProjectId;
        $wiz['target_work_order_statecode']  = $woStatecode;

        $_SESSION[$lastLocKey] = $sourceLocationId;

        if ($contextChanged) {
            $wiz['cart'] = [];
            $wiz['note'] = '';
        }

        header('Location: /lager/?page=uttak_shop');
        exit;
    }

    $sourceLocationUi      = $sourceLocationId;
    $workOrderTextUi       = $woNumber;
    $workOrderNameUi       = $woName;
    $workOrderProjectIdUi  = $woProjectId;
    $workOrderStatecodeUi  = $woStatecode;
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
    .wiz-actions .btn { border-radius: 10px; }
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
    .wo-search-wrap {
        position: relative;
    }
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
    .wo-results.show {
        display: block;
    }
    .wo-result-item {
        padding: .75rem .9rem;
        border-bottom: 1px solid rgba(0,0,0,.06);
        cursor: pointer;
    }
    .wo-result-item:last-child {
        border-bottom: 0;
    }
    .wo-result-item:hover,
    .wo-result-item.active {
        background: rgba(13,110,253,.06);
    }
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
    .wo-meta {
        margin-top: .75rem;
        padding: .75rem .9rem;
        border-radius: 12px;
        background: rgba(13,110,253,.05);
        border: 1px solid rgba(13,110,253,.10);
    }
    .wo-meta-title {
        font-weight: 700;
        margin-bottom: .25rem;
    }
    .wo-help {
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
    .wo-spinner.show {
        display: block;
    }
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
    .wo-debug.show {
        display: block;
    }
</style>

<div class="wiz-head">
    <div>
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-box-arrow-up-right"></i>
            <h3 class="wiz-title">Uttak</h3>
        </div>
        <div class="wiz-sub">Steg 1 av 3 — velg lokasjon og arbeidsordre</div>
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

            <div class="mb-3">
                <label class="form-label"><strong>Uttakslokasjon</strong></label>
                <select name="source_location_id" class="form-select form-select-lg wiz-field-lg" required>
                    <option value="0">Velg lokasjon…</option>
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" <?= $sourceLocationUi === (int)$l['id'] ? 'selected' : '' ?>>
                            <?= h($l['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($lastLocId > 0): ?>
                    <div class="text-muted small mt-1">Sist brukt lokasjon ligger øverst.</div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
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

                <div class="wo-help">Begynn å skrive arbeidsordrenummer eller navn. Trefflisten åpner automatisk under feltet.</div>
                <div class="wo-status" id="woStatus"></div>
                <div class="wo-debug" id="woDebug"></div>

                <div class="wo-meta" id="woMeta" style="<?= $workOrderTextUi !== '' ? '' : 'display:none;' ?>">
                    <div class="wo-meta-title" id="woMetaTitle"><?= h($workOrderTextUi) ?></div>
                    <div id="woMetaName"><?= h($workOrderNameUi) ?></div>
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

    const inputEl       = document.getElementById('target_work_order_search');
    const resultsEl     = document.getElementById('woResults');
    const spinnerEl     = document.getElementById('woSpinner');
    const statusEl      = document.getElementById('woStatus');
    const debugEl       = document.getElementById('woDebug');

    const hiddenNumEl   = document.getElementById('target_work_order_text');
    const hiddenNameEl  = document.getElementById('target_work_order_name');
    const hiddenProjEl  = document.getElementById('target_work_order_project_id');
    const hiddenStateEl = document.getElementById('target_work_order_statecode');

    const metaWrap      = document.getElementById('woMeta');
    const metaTitle     = document.getElementById('woMetaTitle');
    const metaName      = document.getElementById('woMetaName');

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

    async function doSearch(raw) {
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
        if (normalize(val).length >= 2) {
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
})();
</script>