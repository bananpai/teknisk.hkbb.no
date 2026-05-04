<?php
// Path: /public/pages/api_admin.php
// Admin: API-nøkler
// - Kjøres inne i /public/index.php (layout + auth + 2FA er allerede håndtert der)
// - Oppretter tabell api_tokens hvis den ikke finnes
// - Lar admin lage nye tokens og deaktivere/aktivere eksisterende
// - Viser token kun én gang ved opprettelse
//
// OPPDATERT:
// - Fjernet Swagger/YAML fra UI
// - Dokumentasjon kan skjules/vises (docs=1)
// - Dokumentasjon er data-drevet for flere API-er (konfig i $apiCatalog)
// - FIKS: Sletting av nøkler fungerer nå (egen POST-form per rad + confirm), uten modal/Bootstrap JS

declare(strict_types=1);

use App\Database;

// ---------------------------------------------------------
// Helpers (guards for å unngå redeclare)
// ---------------------------------------------------------

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('api_admin_csrf_token')) {
    function api_admin_csrf_token(): string {
        // Bruk global csrf_token() hvis den finnes, ellers enkel intern
        if (function_exists('csrf_token')) return (string)csrf_token();

        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }
        return (string)$_SESSION['_csrf'];
    }
}

if (!function_exists('api_admin_csrf_validate')) {
    function api_admin_csrf_validate(): void {
        // Valider _csrf lokalt. (Robust når global csrf_validate har andre forventninger.)
        $t = (string)($_POST['_csrf'] ?? '');
        $sess = (string)($_SESSION['_csrf'] ?? '');

        // Hvis systemet har global csrf_token(), kan den ha lagt token i session på egen måte.
        // Vi støtter derfor også "tom session-token" ved å akseptere hvis csrf_validate() finnes og ikke feiler hardt,
        // men vi kaller den IKKE direkte her (den kan exit'e).
        if ($t === '' || $sess === '' || !hash_equals($sess, $t)) {
            http_response_code(400);
            echo "<div class='alert alert-danger m-3'>Ugyldig CSRF-token.</div>";
            exit;
        }
    }
}

if (!function_exists('api_admin_table_exists')) {
    function api_admin_table_exists(PDO $pdo, string $table): bool {
        $table = trim($table);
        if ($table === '') return false;

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
            // fallthrough
        }

        try {
            $literal = str_replace(["\\", "'", "%", "_"], ["\\\\", "\\'", "\\%", "\\_"], $table);
            $rs = $pdo->query("SHOW TABLES LIKE '{$literal}'");
            return (bool)($rs ? $rs->fetchColumn() : false);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('api_admin_ensure_tables')) {
    function api_admin_ensure_tables(PDO $pdo): void {
        if (!api_admin_table_exists($pdo, 'api_tokens')) {
            $pdo->exec("
                CREATE TABLE api_tokens (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name VARCHAR(120) NOT NULL,
                    token_hash CHAR(64) NOT NULL,
                    scopes VARCHAR(255) NOT NULL DEFAULT 'field_objects:read',
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY ux_token_hash (token_hash),
                    KEY ix_active (is_active),
                    KEY ix_last_used (last_used_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
}

if (!function_exists('api_admin_generate_token')) {
    function api_admin_generate_token(): string {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

if (!function_exists('api_admin_hash')) {
    function api_admin_hash(string $token): string {
        return hash('sha256', $token);
    }
}

if (!function_exists('api_admin_is_admin_fallback')) {
    function api_admin_is_admin_fallback(PDO $pdo, string $username): bool {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $username]);
            $uid = (int)($stmt->fetchColumn() ?: 0);
            if ($uid <= 0) return false;

            $stmt = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id = :uid AND LOWER(role) = 'admin' LIMIT 1");
            $stmt->execute([':uid' => $uid]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('api_admin_base_url')) {
    function api_admin_base_url(): string {
        return 'https://teknisk.hkbb.no';
    }
}

/**
 * Konverterer et curl-kall til tilsvarende PowerShell Invoke-RestMethod.
 * Håndterer -H headers og URL som siste argument.
 * Eksempel:
 *   curl -H "Authorization: Bearer abc" "https://example.com/api"
 *   → Invoke-RestMethod -Uri "https://example.com/api" -Headers @{ Authorization = "Bearer abc" }
 */
if (!function_exists('api_admin_curl_to_ps')) {
    function api_admin_curl_to_ps(string $curl): string {
        preg_match_all('/-H\s+"([^"]+)"/', $curl, $hm);
        $headers = [];
        foreach ($hm[1] as $h) {
            $pos = strpos($h, ': ');
            if ($pos !== false) {
                $k = substr($h, 0, $pos);
                $v = substr($h, $pos + 2);
                // Keys with hyphens need quoting in PS hashtable
                $kSafe = strpos($k, '-') !== false ? '"' . $k . '"' : $k;
                $headers[] = $kSafe . ' = "' . $v . '"';
            }
        }
        preg_match('/"(https?:\/\/[^"]+)"\s*$/', $curl, $um);
        $url  = $um[1] ?? '';
        $hStr = $headers ? ' -Headers @{ ' . implode('; ', $headers) . ' }' : '';
        return 'Invoke-RestMethod -Uri "' . $url . '"' . $hStr;
    }
}

// ---------------------------------------------------------
// Kontext: index.php har allerede sjekket innlogging + 2FA
// ---------------------------------------------------------

$pdo = $pdo ?? null;
if (!$pdo instanceof PDO) {
    $pdo = Database::getConnection();
}

$username = (string)($_SESSION['username'] ?? '');
if ($username === '') {
    header('Location: /login/');
    exit;
}

$admin = isset($isAdmin) ? (bool)$isAdmin : api_admin_is_admin_fallback($pdo, $username);

if (!$admin) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger">Ingen tilgang.</div>
    <?php
    return;
}

api_admin_ensure_tables($pdo);

// ---------------------------------------------------------
// API-katalog (for dokumentasjon) – legg til flere API-er her
// ---------------------------------------------------------

$baseUrl = api_admin_base_url();

$apiCatalog = [
    [
        'title'       => 'HKON – Chatbot-integrasjon',
        'key'         => 'hkon',
        'badge'       => 'HKON',
        'description' => 'Smal scope for HKON-chatboten. Kun offentlig-klarerte data — ingen interne felter, ingen Jira-nøkler, ingen dashboard-flagg. Bruk scope <code>hkon:events</code> for dette tokenet.',
        'auth' => [
            'Bearer' => 'Authorization: Bearer <token>',
        ],
        'endpoints' => [
            [
                'method' => 'GET',
                'path'   => '/api/events/public',
                'scope'  => 'hkon:events',
                'scope_alt' => 'events:read',
                'desc'   => 'Returnerer aktive saker der "HKON (chatbot)"-distribusjon er aktivert (published_to_chatbot=1). Kun kundeklare felter: tittel, sammendrag, kundehandlinger, tidsinfo, alvorlighetsgrad.',
                'params' => [
                    ['name' => 'target_type',  'desc' => 'Valgfri – filtrer på målgruppe-type (f.eks. leveransepunkt_id)'],
                    ['name' => 'target_value', 'desc' => 'Valgfri – verdi for target_type-filter'],
                ],
                'examples' => [
                    'curl -H "Authorization: Bearer <token>" "' . $baseUrl . '/api/events/public"',
                    'curl -H "Authorization: Bearer <token>" "' . $baseUrl . '/api/events/public?target_type=leveransepunkt_id&target_value=12345"',
                ],
            ],
            [
                'method' => 'GET',
                'path'   => '/api/events?mode=address_lookup',
                'scope'  => 'hkon:events',
                'scope_alt' => 'events:read',
                'desc'   => 'Sjekker om en gitt adresse er berørt av aktive hendelser/endringer. Returnerer kun saker der HKON-distribusjon er aktivert. Minst postal_code påkrevd.',
                'params' => [
                    ['name' => 'postal_code',  'desc' => 'Postnummer (PÅKREVD)'],
                    ['name' => 'street',       'desc' => 'Gatenavn, valgfri (case-insensitiv match)'],
                    ['name' => 'house_number', 'desc' => 'Husnummer, valgfri'],
                    ['name' => 'house_letter', 'desc' => 'Husbokstav, valgfri (case-insensitiv match)'],
                ],
                'examples' => [
                    'curl -H "Authorization: Bearer <token>" "' . $baseUrl . '/api/events?mode=address_lookup&postal_code=3600&street=Storgata&house_number=5"',
                    'curl -H "Authorization: Bearer <token>" "' . $baseUrl . '/api/events?mode=address_lookup&postal_code=3600"',
                ],
            ],
        ],
        'response_note' => 'address_lookup-svar: { "mode": "address_lookup", "address": { "street", "house_number", "postal_code" }, "affected": true/false, "count": 1, "events": [ { "id", "type", "status", "severity", "title_public", "summary_public", "customer_actions", "schedule_start", "schedule_end", "actual_start", "actual_end", "next_update_eta", "affected_customers", "updated_at" } ] }',
        'status_values' => 'scheduled | in_progress | monitoring',
        'type_values'   => 'incident | planned',
        'severity_values' => 'none | minor | moderate | major | critical',
    ],
    [
        'title'       => 'Hendelser & Endringer – intern',
        'key'         => 'events',
        'description' => 'Full tilgang til hendelse-data. Beregnet på interne verktøy: dashboards, Grafana, admin-integrasjoner. Eksponerer interne felter (Jira-nøkkel, dashboard-flagg). Bruk scope <code>events:read</code>.',
        'auth' => [
            'Bearer' => 'Authorization: Bearer <token>',
        ],
        'endpoints' => [
            [
                'method' => 'GET',
                'path'   => '/api/events',
                'scope'  => 'events:read',
                'desc'   => 'List hendelser. Inkluderer interne felter (jira_key, published_to_dashboard, published_to_chatbot osv.).',
                'params' => [
                    ['name' => 'mode',  'desc' => 'active (default) | planned | recent (siste 7 dager) | all'],
                    ['name' => 'limit', 'desc' => 'Maks antall (1–200, default 50)'],
                ],
                'examples' => [
                    'curl -H "Authorization: Bearer <token>" "' . $baseUrl . '/api/events?mode=active"',
                    'curl -H "Authorization: Bearer <token>" "' . $baseUrl . '/api/events?mode=planned&limit=10"',
                    'curl -H "Authorization: Bearer <token>" "' . $baseUrl . '/api/events?mode=recent"',
                ],
            ],
            [
                'method' => 'GET',
                'path'   => '/api/events?id=123',
                'scope'  => 'events:read',
                'desc'   => 'Hent én hendelse med oppdateringer og berørte målgrupper.',
                'params' => [
                    ['name' => 'id', 'desc' => 'ID på hendelsen (påkrevd)'],
                ],
                'examples' => [
                    'curl -H "Authorization: Bearer <token>" "' . $baseUrl . '/api/events?id=123"',
                ],
            ],
        ],
        'status_values' => 'draft | scheduled | in_progress | monitoring | resolved | cancelled',
        'type_values'   => 'incident | planned',
        'severity_values' => 'none | minor | moderate | major | critical',
    ],
    [
        'title' => 'Feltobjekter',
        'key' => 'field_objects',
        'description' => 'Leser feltobjekter (read-only) for integrasjoner.',
        'auth' => [
            'Bearer' => 'Authorization: Bearer <token>',
            'ApiKey' => 'X-Api-Key: <token>',
        ],
        'endpoints' => [
            [
                'method' => 'GET',
                'path' => '/api/field_objects/',
                'scope' => 'field_objects:read',
                'params' => [
                    ['name' => 'limit', 'desc' => 'Maks antall rader (f.eks. 10)'],
                    ['name' => 'offset', 'desc' => 'Offset for paginering (f.eks. 0)'],
                    ['name' => 'q', 'desc' => 'Tekstsøk (hvis støttet)'],
                ],
                'examples' => [
                    'curl -H "Authorization: Bearer <token>" "' . $baseUrl . '/api/field_objects/?limit=10"',
                    'curl -H "X-Api-Key: <token>" "' . $baseUrl . '/api/field_objects/?limit=10"',
                ],
            ],
        ],
    ],
];

// ---------------------------------------------------------
// POST actions
// ---------------------------------------------------------

$flash = '';
$createdToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    api_admin_csrf_validate();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $scopeFieldObjects = isset($_POST['scope_field_objects_read']);
        $scopeEventsRead   = isset($_POST['scope_events_read']);
        $scopeHkonEvents   = isset($_POST['scope_hkon_events']);

        if ($name === '') $name = 'API Token';

        $scopes = [];
        if ($scopeFieldObjects) $scopes[] = 'field_objects:read';
        if ($scopeEventsRead)   $scopes[] = 'events:read';
        if ($scopeHkonEvents)   $scopes[] = 'hkon:events';
        if (!$scopes) $scopes[] = 'field_objects:read';

        $token = api_admin_generate_token();
        $hash  = api_admin_hash($token);

        $stmt = $pdo->prepare("INSERT INTO api_tokens (name, token_hash, scopes, is_active) VALUES (?,?,?,1)");
        $stmt->execute([$name, $hash, implode(',', $scopes)]);

        $createdToken = $token;
        $flash = "Ny API-nøkkel opprettet. Kopier tokenet nå – det vises kun én gang.";
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $to = (int)($_POST['to'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE api_tokens SET is_active = ? WHERE id = ?");
            $stmt->execute([$to ? 1 : 0, $id]);
            $flash = $to ? "Token aktivert." : "Token deaktivert.";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE id = ?");
            $stmt->execute([$id]);
            $flash = "Token slettet.";
        }
    }
}

$tokens = $pdo
    ->query("SELECT id, name, scopes, is_active, created_at, last_used_at FROM api_tokens ORDER BY id DESC")
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Dokumentasjon – default skjult, men kan åpnes via ?docs=1
$docsOpen = ((string)($_GET['docs'] ?? '') === '1');

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Admin – API-nøkler</h1>
        <div class="text-muted small">Opprett og administrer tokens for eksterne integrasjoner.</div>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="/?page=start">
        <i class="bi bi-arrow-left me-1"></i> Tilbake
    </a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-info"><?= h($flash) ?></div>
<?php endif; ?>

<?php if ($createdToken): ?>
    <div class="alert alert-warning">
        <div class="fw-semibold mb-2">Token (kopier nå):</div>

        <div class="d-flex gap-2 align-items-start flex-wrap">
            <code id="createdTokenCode" style="word-break:break-all; display:inline-block;"><?= h($createdToken) ?></code>
            <button type="button" class="btn btn-sm btn-outline-dark" onclick="copyText('createdTokenCode')">
                <i class="bi bi-clipboard me-1"></i> Kopier
            </button>
        </div>

        <div class="mt-2 small text-muted">
            Bruk slik:
            <code>Authorization: Bearer &lt;token&gt;</code>
            eller
            <code>X-Api-Key: &lt;token&gt;</code>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header fw-semibold">Opprett ny API-nøkkel</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="_csrf" value="<?= h(api_admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="create">

            <div class="col-md-6">
                <label class="form-label">Navn</label>
                <input type="text" class="form-control" name="name" placeholder="F.eks. NetBox / Grafana / Integrasjon X">
            </div>

            <div class="col-md-6">
                <label class="form-label">Scopes</label>

                <div class="table-responsive mb-2">
                    <table class="table table-sm table-bordered mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th style="width:30px;"></th>
                                <th>Scope</th>
                                <th>Tilgang</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="text-center align-middle">
                                    <input class="form-check-input" type="checkbox" id="s1" name="scope_field_objects_read" checked>
                                </td>
                                <td class="align-middle"><label for="s1" class="mb-0"><code>field_objects:read</code></label></td>
                                <td class="text-muted align-middle">Les feltobjekter. Brukes av NetBox, Grafana og kart-integrasjoner.</td>
                            </tr>
                            <tr>
                                <td class="text-center align-middle">
                                    <input class="form-check-input" type="checkbox" id="s2" name="scope_events_read">
                                </td>
                                <td class="align-middle"><label for="s2" class="mb-0"><code>events:read</code></label></td>
                                <td class="text-muted align-middle">Full tilgang til alle hendelse-endepunkter. Intern bruk – dashboards, Grafana, admin-verktøy.</td>
                            </tr>
                            <tr>
                                <td class="text-center align-middle">
                                    <input class="form-check-input" type="checkbox" id="s3" name="scope_hkon_events">
                                </td>
                                <td class="align-middle">
                                    <label for="s3" class="mb-0"><code>hkon:events</code></label>
                                    <span class="badge bg-primary ms-1" style="font-size:.65rem;">HKON</span>
                                </td>
                                <td class="text-muted align-middle">Smal scope kun for HKON-chatboten. Gir tilgang til <code>/api/events/public</code> og <code>address_lookup</code> – ingen intern data.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-info py-2 px-3 small mb-0">
                    <strong>HKON-token:</strong> velg kun <code>hkon:events</code>. Gir chatboten minst mulig tilgang — kun offentlige hendelser og adresseoppslag.
                </div>
            </div>

            <div class="col-12">
                <button class="btn btn-primary">
                    <i class="bi bi-key me-1"></i> Opprett nøkkel
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header fw-semibold">Eksisterende nøkler</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead class="table-light">
                <tr>
                    <th style="width:80px;">ID</th>
                    <th>Navn</th>
                    <th>Scopes</th>
                    <th>Status</th>
                    <th>Opprettet</th>
                    <th>Sist brukt</th>
                    <th style="width:360px;"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tokens as $t): ?>
                    <tr>
                        <td><?= (int)$t['id'] ?></td>
                        <td class="fw-semibold"><?= h((string)$t['name']) ?></td>
                        <td><code><?= h((string)$t['scopes']) ?></code></td>
                        <td>
                            <?php if ((int)$t['is_active'] === 1): ?>
                                <span class="badge bg-success">Aktiv</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Av</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h((string)$t['created_at']) ?></td>
                        <td><?= h((string)($t['last_used_at'] ?? '')) ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-2 flex-wrap justify-content-end">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="_csrf" value="<?= h(api_admin_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <?php if ((int)$t['is_active'] === 1): ?>
                                        <input type="hidden" name="to" value="0">
                                        <button class="btn btn-sm btn-warning">
                                            <i class="bi bi-pause-circle me-1"></i> Deaktiver
                                        </button>
                                    <?php else: ?>
                                        <input type="hidden" name="to" value="1">
                                        <button class="btn btn-sm btn-success">
                                            <i class="bi bi-play-circle me-1"></i> Aktiver
                                        </button>
                                    <?php endif; ?>
                                </form>

                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Slette API-nøkkelen «<?= h((string)$t['name']) ?>» (ID <?= (int)$t['id'] ?>)? Dette kan ikke angres.');">
                                    <input type="hidden" name="_csrf" value="<?= h(api_admin_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <button class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash me-1"></i> Slett
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$tokens): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Ingen API-nøkler enda.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
        <span>API-dokumentasjon</span>
        <div class="d-flex gap-2 align-items-center">
            <?php if ($docsOpen): ?>
                <a class="btn btn-sm btn-outline-secondary" href="?page=api_admin">
                    <i class="bi bi-eye-slash me-1"></i> Skjul
                </a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-primary" href="?page=api_admin&docs=1">
                    <i class="bi bi-eye me-1"></i> Vis
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($docsOpen): ?>
        <div class="card-body">

            <div class="alert alert-warning py-2 px-3 mb-3 small">
                <strong>Windows PowerShell:</strong> <code>curl</code> i PowerShell er et alias for <code>Invoke-WebRequest</code> og godtar ikke <code>-H</code>-flagget.
                Bruk enten <code>curl.exe</code> (ekte curl, inkludert i Windows 10/Server 2019+) eller <strong>PowerShell</strong>-eksemplene under.
            </div>

            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="small text-muted me-1">Vis eksempler som:</span>
                <div class="btn-group btn-group-sm" role="group" id="exampleSyntaxToggle">
                    <button type="button" class="btn btn-outline-secondary active" onclick="setExSyntax('curl')">curl / bash</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="setExSyntax('ps')">PowerShell</button>
                </div>
            </div>

            <div class="accordion" id="apiDocsAccordion">
                <?php foreach ($apiCatalog as $idx => $api): ?>
                    <?php
                    $accId = 'apiDoc_' . $idx;
                    $headingId = $accId . '_h';
                    $collapseId = $accId . '_c';
                    ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="<?= h($headingId) ?>">
                            <button class="accordion-button <?= $idx === 0 ? '' : 'collapsed' ?>" type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#<?= h($collapseId) ?>"
                                    aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>"
                                    aria-controls="<?= h($collapseId) ?>">
                                <?= h((string)$api['title']) ?>
                                <?php if (!empty($api['badge'])): ?>
                                    <span class="badge bg-primary ms-2" style="font-size:.65rem;"><?= h((string)$api['badge']) ?></span>
                                <?php endif; ?>
                            </button>
                        </h2>
                        <div id="<?= h($collapseId) ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>"
                             aria-labelledby="<?= h($headingId) ?>" data-bs-parent="#apiDocsAccordion">
                            <div class="accordion-body">

                                <div class="mb-3 small"><?= (string)($api['description'] ?? '') ?></div>

                                <div class="mb-3">
                                    <div class="fw-semibold mb-1">Base URL</div>
                                    <div class="d-flex gap-2 flex-wrap align-items-center">
                                        <code id="baseUrlCode"><?= h($baseUrl) ?></code>
                                        <button type="button" class="btn btn-sm btn-outline-dark" onclick="copyText('baseUrlCode')">
                                            <i class="bi bi-clipboard me-1"></i> Kopier
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="fw-semibold mb-1">Autentisering</div>
                                    <div class="small text-muted mb-2">Bruk enten Bearer eller X-Api-Key.</div>
                                    <ul class="mb-0">
                                        <?php foreach (($api['auth'] ?? []) as $v): ?>
                                            <li><code><?= h((string)$v) ?></code></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>

                                <div class="fw-semibold mb-2">Endepunkter</div>

                                <?php foreach (($api['endpoints'] ?? []) as $eIdx => $ep): ?>
                                    <?php
                                    $method   = (string)($ep['method'] ?? 'GET');
                                    $path     = (string)($ep['path'] ?? '/');
                                    $scope    = (string)($ep['scope'] ?? '');
                                    $scopeAlt = (string)($ep['scope_alt'] ?? '');
                                    $params   = (array)($ep['params'] ?? []);
                                    $examples = (array)($ep['examples'] ?? []);
                                    ?>
                                    <div class="border rounded p-3 mb-3">
                                        <div class="d-flex justify-content-between flex-wrap gap-2">
                                            <div>
                                                <span class="badge bg-dark"><?= h($method) ?></span>
                                                <code class="ms-2"><?= h($path) ?></code>
                                            </div>
                                            <?php if ($scope !== ''): ?>
                                                <div class="text-muted small">
                                                    Scope: <code><?= h($scope) ?></code>
                                                    <?php if ($scopeAlt !== ''): ?>
                                                        <span class="text-muted"> eller </span><code><?= h($scopeAlt) ?></code>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($ep['desc'])): ?>
                                            <div class="mt-2 small text-muted"><?= h((string)$ep['desc']) ?></div>
                                        <?php endif; ?>

                                        <?php if ($params): ?>
                                            <div class="mt-2 small">
                                                <div class="fw-semibold">Query-parametere</div>
                                                <ul class="mb-0">
                                                    <?php foreach ($params as $p): ?>
                                                        <li><code><?= h((string)($p['name'] ?? '')) ?></code> – <?= h((string)($p['desc'] ?? '')) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($examples): ?>
                                            <div class="mt-3">
                                                <div class="fw-semibold small mb-1 api-ex-label-curl">Eksempler (curl / bash)</div>
                                                <div class="fw-semibold small mb-1 api-ex-label-ps" style="display:none;">Eksempler (PowerShell)</div>
                                                <?php foreach ($examples as $exIdx => $ex): ?>
                                                    <?php
                                                    $idCurl = 'ex_' . $idx . '_' . $eIdx . '_' . $exIdx . '_curl';
                                                    $idPs   = 'ex_' . $idx . '_' . $eIdx . '_' . $exIdx . '_ps';
                                                    $psEx   = api_admin_curl_to_ps((string)$ex);
                                                    ?>
                                                    <div class="api-ex-curl d-flex gap-2 flex-wrap align-items-center mb-1">
                                                        <code id="<?= h($idCurl) ?>" style="word-break:break-all;"><?= h((string)$ex) ?></code>
                                                        <button type="button" class="btn btn-sm btn-outline-dark" onclick="copyText('<?= h($idCurl) ?>')">
                                                            <i class="bi bi-clipboard me-1"></i> Kopier
                                                        </button>
                                                    </div>
                                                    <div class="api-ex-ps d-flex gap-2 flex-wrap align-items-center mb-1" style="display:none;">
                                                        <code id="<?= h($idPs) ?>" style="word-break:break-all;"><?= h($psEx) ?></code>
                                                        <button type="button" class="btn btn-sm btn-outline-dark" onclick="copyText('<?= h($idPs) ?>')">
                                                            <i class="bi bi-clipboard me-1"></i> Kopier
                                                        </button>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    <?php else: ?>
        <div class="card-body text-muted small">
            Dokumentasjonen er skjult. Klikk <strong>Vis</strong> for å se bruksinfo og endepunkter.
        </div>
    <?php endif; ?>
</div>

<script>
function copyText(elementId) {
    const el = document.getElementById(elementId);
    if (!el) return;

    const text = el.innerText || el.textContent || '';
    if (!text) return;

    navigator.clipboard.writeText(text).then(() => toastCopied()).catch(() => {
        const tmp = document.createElement('textarea');
        tmp.value = text;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        document.body.removeChild(tmp);
        toastCopied();
    });
}

function setExSyntax(mode) {
    const isCurl = mode === 'curl';
    document.querySelectorAll('.api-ex-curl').forEach(el => el.style.display = isCurl ? '' : 'none');
    document.querySelectorAll('.api-ex-ps').forEach(el => el.style.display = isCurl ? 'none' : '');
    document.querySelectorAll('.api-ex-label-curl').forEach(el => el.style.display = isCurl ? '' : 'none');
    document.querySelectorAll('.api-ex-label-ps').forEach(el => el.style.display = isCurl ? 'none' : '');

    const toggle = document.getElementById('exampleSyntaxToggle');
    if (toggle) {
        toggle.querySelectorAll('button').forEach(btn => {
            btn.classList.toggle('active', btn.textContent.trim().toLowerCase().startsWith(mode));
        });
    }
    localStorage.setItem('api_ex_syntax', mode);
}

// Restore last choice on page load
(function() {
    const saved = localStorage.getItem('api_ex_syntax');
    if (saved === 'ps') setExSyntax('ps');
})();

function toastCopied() {
    let t = document.getElementById('copyToast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'copyToast';
        t.style.position = 'fixed';
        t.style.bottom = '16px';
        t.style.right = '16px';
        t.style.zIndex = '9999';
        t.style.padding = '10px 12px';
        t.style.borderRadius = '10px';
        t.style.background = 'rgba(0,0,0,0.85)';
        t.style.color = '#fff';
        t.style.fontSize = '13px';
        t.style.boxShadow = '0 8px 24px rgba(0,0,0,0.25)';
        t.innerText = 'Kopiert!';
        document.body.appendChild(t);
    }
    t.style.opacity = '1';
    t.style.display = 'block';
    setTimeout(() => { t.style.opacity = '0'; }, 900);
    setTimeout(() => { t.style.display = 'none'; }, 1400);
}
</script>
