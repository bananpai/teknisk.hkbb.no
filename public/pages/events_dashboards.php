<?php
// Path: /public/pages/events_dashboards.php
//
// Events Dashboards – Oversikt + administrasjon av "shared secret" keys
// Formål:
// - Oversikt over publike dashboards vi ønsker å vise på storskjermer
// - Administrere shared secret key (URL-key) som kreves av public dashboards
//
// Sikkerhet:
// - Denne siden ligger inne i systemet og forutsetter normal innlogging/tilgang.
// - I tillegg gjør vi en lett rolle-sjekk (admin eller incidents_* / support/drift/noc)
//
// DB:
// - Oppretter tabell v4_public_dashboard_keys (trygt) for lagring av nøkkel-hash og rotasjon.
//
// Bruk:
// - /?page=events_dashboards
// - TV-mode (for selve katalogen): /?page=events_dashboards&mode=tv

declare(strict_types=1);

use App\Database;

if (!function_exists('esc')) {
    function esc(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$pageTitle = $pageTitle ?? 'Events Dashboards';

$mode = (string)($_GET['mode'] ?? '');
$isTv = in_array(strtolower($mode), ['tv', 'screen', 'kiosk'], true);

// --- DB ---
$pdo = null;
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    echo '<div class="alert alert-danger">Databasefeil: kunne ikke koble til.</div>';
    return;
}

// --- Lett tilgangssjekk (fail-soft hvis deres app allerede guarder) ---
$username = (string)($_SESSION['username'] ?? '');
$isAdmin  = false;
$canIncidents = false;

function normalize_list_local($v): array {
    if (is_array($v)) return array_values(array_filter(array_map('strval', $v)));
    if (is_string($v) && trim($v) !== '') {
        $parts = preg_split('/[,\s;]+/', $v);
        return array_values(array_filter(array_map('strval', $parts)));
    }
    return [];
}
function has_any_local(array $needles, array $haystack): bool {
    $haystack = array_map('strtolower', $haystack);
    foreach ($needles as $n) {
        if (in_array(strtolower($n), $haystack, true)) return true;
    }
    return false;
}

$roles = normalize_list_local($_SESSION['roles'] ?? null);
$perms = normalize_list_local($_SESSION['permissions'] ?? null);

try {
    if ($username !== '') {
        $st = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $st->execute([':u' => $username]);
        $uid = (int)($st->fetchColumn() ?: 0);

        if ($uid > 0) {
            // user_roles
            try {
                $st = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
                $st->execute([':uid' => $uid]);
                $dbRoles = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $roles = array_merge($roles, normalize_list_local($dbRoles));
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }
} catch (\Throwable $e) {
    // ignore
}

$roles = array_values(array_unique(array_map('strtolower', $roles)));
$perms = array_values(array_unique(array_map('strtolower', $perms)));

$isAdmin = has_any_local(['admin'], $roles);

$canIncidents = $isAdmin
    || has_any_local([
        'incidents_read','incidents_write','incidents_admin',
        'incident','incidents','hendelse','hendelser','endring','endringer',
        'maintenance','planned_work','outage',
        'support','drift','noc'
    ], $roles)
    || has_any_local([
        'incidents_read','incidents_write','incidents_admin',
        'incident','incidents','hendelse','hendelser','endring','endringer',
        'maintenance','planned_work','outage',
        'support','drift','noc'
    ], $perms);

if (!$canIncidents) {
    echo '<div class="alert alert-danger">Ingen tilgang.</div>';
    return;
}

// --- CSRF ---
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['csrf_token'];

// --- Ensure table exists ---
$pdo->exec("
CREATE TABLE IF NOT EXISTS v4_public_dashboard_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dashboard VARCHAR(60) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  revoked_at DATETIME NULL,
  revoked_by INT NULL,
  PRIMARY KEY (id),
  KEY idx_dashboard (dashboard),
  KEY idx_revoked (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Dashboard vi styrer nøkkel for nå
$DASH_EVENTS_TV = 'events_tv';

// --- Actions: create/rotate/revoke ---
$flash = null;
$newPlainKey = null;

function sha256(string $s): string {
    return hash('sha256', $s);
}
function b64url_bytes(int $len = 32): string {
    $bin = random_bytes($len);
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $postCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $postCsrf)) {
        $flash = ['type' => 'danger', 'msg' => 'Ugyldig CSRF. Prøv igjen.'];
    } else {
        $meUserId = 0;
        try {
            if ($username !== '') {
                $st = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
                $st->execute([':u' => $username]);
                $meUserId = (int)($st->fetchColumn() ?: 0);
            }
        } catch (\Throwable $e) {
            $meUserId = 0;
        }

        if ($action === 'create_key' || $action === 'rotate_key') {
            $note = trim((string)($_POST['note'] ?? ''));
            if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

            // Revoke all active keys first if rotate (or create behaves like rotate)
            try {
                $st = $pdo->prepare("
                    UPDATE v4_public_dashboard_keys
                       SET revoked_at = NOW(), revoked_by = :uid
                     WHERE dashboard = :d
                       AND revoked_at IS NULL
                ");
                $st->execute([':uid' => $meUserId ?: null, ':d' => $DASH_EVENTS_TV]);
            } catch (\Throwable $e) {
                // ignore
            }

            // Generate new key and store hash
            $plain = 'tv_' . b64url_bytes(24); // URL-safe, passe lang
            $hash  = sha256($plain);

            try {
                $st = $pdo->prepare("
                    INSERT INTO v4_public_dashboard_keys (dashboard, key_hash, note, created_by)
                    VALUES (:d, :h, :n, :uid)
                ");
                $st->execute([
                    ':d'   => $DASH_EVENTS_TV,
                    ':h'   => $hash,
                    ':n'   => ($note !== '' ? $note : null),
                    ':uid' => $meUserId ?: null,
                ]);
                $newPlainKey = $plain;
                $flash = ['type' => 'success', 'msg' => 'Ny nøkkel er opprettet. Husk å lagre den – den vises bare én gang.'];
            } catch (\Throwable $e) {
                $flash = ['type' => 'danger', 'msg' => 'Kunne ikke opprette nøkkel i DB.'];
            }
        }

        if ($action === 'revoke_key') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $st = $pdo->prepare("
                        UPDATE v4_public_dashboard_keys
                           SET revoked_at = NOW(), revoked_by = :uid
                         WHERE id = :id AND dashboard = :d
                    ");
                    $st->execute([':uid' => $meUserId ?: null, ':id' => $id, ':d' => $DASH_EVENTS_TV]);
                    $flash = ['type' => 'success', 'msg' => 'Nøkkel er revokert.'];
                } catch (\Throwable $e) {
                    $flash = ['type' => 'danger', 'msg' => 'Kunne ikke revokere nøkkel.'];
                }
            }
        }
    }
}

// --- Fetch active key status + history ---
$activeKey = null;
$history = [];

try {
    $st = $pdo->prepare("
        SELECT id, note, created_at, created_by
          FROM v4_public_dashboard_keys
         WHERE dashboard = :d AND revoked_at IS NULL
         ORDER BY created_at DESC
         LIMIT 1
    ");
    $st->execute([':d' => $DASH_EVENTS_TV]);
    $activeKey = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) {
    $activeKey = null;
}

try {
    $st = $pdo->prepare("
        SELECT id, note, created_at, revoked_at
          FROM v4_public_dashboard_keys
         WHERE dashboard = :d
         ORDER BY created_at DESC
         LIMIT 15
    ");
    $st->execute([':d' => $DASH_EVENTS_TV]);
    $history = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $history = [];
}

// --- Dashboard list (katalog) ---
$dashboards = [
    [
        'key'         => 'events_tv',
        'title'       => 'TV – Hendelser (public)',
        'description' => 'Public TV-visning uten innlogging. Krever IP-allowlist + shared secret key.',
        'category'    => 'TV',
        'status'      => 'Aktiv',
        'url'         => '/dashboard/events/', // public
        'badge'       => 'Public',
        'icon'        => 'bi-tv',
    ],
    [
        'key'         => 'events_overview',
        'title'       => 'Hendelser – Oversikt (system)',
        'description' => 'Intern oversikt i systemet (innlogging).',
        'category'    => 'System',
        'status'      => 'Aktiv',
        'url'         => '/?page=events',
        'badge'       => 'Intern',
        'icon'        => 'bi-exclamation-triangle',
    ],
    [
        'key'         => 'map_view',
        'title'       => 'Kart – Hendelser og berørte adresser',
        'description' => 'Kartvisning (bygges videre).',
        'category'    => 'Kart',
        'status'      => 'Kommer',
        'url'         => '/?page=events_map',
        'badge'       => 'Kart',
        'icon'        => 'bi-geo-alt',
    ],
];

$q        = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$status   = trim((string)($_GET['status'] ?? ''));

$allCategories = [];
$allStatuses   = [];
foreach ($dashboards as $d) {
    $allCategories[] = (string)($d['category'] ?? '');
    $allStatuses[]   = (string)($d['status'] ?? '');
}
$allCategories = array_values(array_unique(array_filter($allCategories)));
sort($allCategories);
$allStatuses = array_values(array_unique(array_filter($allStatuses)));
sort($allStatuses);

$filtered = array_values(array_filter($dashboards, function(array $d) use ($q, $category, $status): bool {
    $title = strtolower((string)($d['title'] ?? ''));
    $desc  = strtolower((string)($d['description'] ?? ''));
    $cat   = (string)($d['category'] ?? '');
    $st    = (string)($d['status'] ?? '');

    if ($category !== '' && strcasecmp($cat, $category) !== 0) return false;
    if ($status !== '' && strcasecmp($st, $status) !== 0) return false;

    if ($q !== '') {
        $qq = strtolower($q);
        if (strpos($title, $qq) === false && strpos($desc, $qq) === false) return false;
    }
    return true;
}));

?>
<style>
.dash-card{
    border:1px solid rgba(0,0,0,.08);
    border-radius:12px;
    background:#fff;
}
.dash-icon{
    width:44px;height:44px;border-radius:12px;
    display:inline-flex;align-items:center;justify-content:center;
    background:rgba(13,110,253,.08);
    border:1px solid rgba(13,110,253,.12);
}
.dash-icon i{font-size:1.25rem;}
.dash-meta{font-size:.9rem;opacity:.85;}
.dash-actions .btn{border-radius:10px;}
<?php if ($isTv): ?>
.app-topbar{display:none!important;}
.app-sidebar{display:none!important;}
.app-main{margin-left:0!important;}
<?php endif; ?>
</style>

<div class="container-fluid py-3">

    <div class="d-flex align-items-start justify-content-between mb-3">
        <div>
            <h3 class="mb-1">Events Dashboards</h3>
            <div class="text-muted">Oversikt over publike dashboards for storskjerm (og interne lenker).</div>
        </div>
        <div class="d-flex gap-2">
            <?php if (!$isTv): ?>
                <a class="btn btn-outline-secondary" href="/?page=events_dashboards&mode=tv">
                    <i class="bi bi-tv me-1"></i>Storskjerm
                </a>
            <?php else: ?>
                <a class="btn btn-outline-secondary" href="/?page=events_dashboards">
                    <i class="bi bi-window me-1"></i>Normal visning
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= esc($flash['type']) ?>"><?= esc($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($newPlainKey !== null): ?>
        <div class="alert alert-warning">
            <div class="fw-semibold mb-1">Ny shared secret key (vises bare nå):</div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <code style="font-size:1rem; padding:.35rem .55rem; border-radius:.5rem; background:rgba(0,0,0,.06);">
                    <?= esc($newPlainKey) ?>
                </code>
                <button class="btn btn-sm btn-outline-dark" type="button" data-copy="<?= esc($newPlainKey) ?>">
                    <i class="bi bi-clipboard me-1"></i>Kopiér
                </button>
            </div>
            <div class="small text-muted mt-2">
                Public TV-link blir f.eks:
                <code>/dashboard/events/?key=<?= esc($newPlainKey) ?></code>
            </div>
        </div>
    <?php endif; ?>

    <!-- Shared secret admin -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                <div>
                    <h5 class="mb-1">Public TV – Shared secret key</h5>
                    <div class="text-muted">
                        Den offentlige siden <code>/dashboard/events/</code> krever både IP-allowlist og <code>?key=</code>.
                    </div>
                </div>

                <div class="text-end">
                    <?php if ($activeKey): ?>
                        <span class="badge bg-success">Aktiv nøkkel</span>
                        <div class="small text-muted mt-1">
                            Opprettet: <?= esc((string)$activeKey['created_at']) ?>
                        </div>
                    <?php else: ?>
                        <span class="badge bg-danger">Ingen aktiv nøkkel</span>
                    <?php endif; ?>
                </div>
            </div>

            <hr>

            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">

                <div class="col-12 col-md-6">
                    <label class="form-label">Notat (valgfritt)</label>
                    <input class="form-control" name="note" placeholder="F.eks. TV i driftssenter / vaktrom...">
                </div>

                <div class="col-12 col-md-6 d-flex gap-2 justify-content-md-end">
                    <button class="btn btn-primary" name="action" value="create_key" type="submit">
                        <i class="bi bi-key me-1"></i>Opprett nøkkel
                    </button>
                    <button class="btn btn-outline-danger" name="action" value="rotate_key" type="submit"
                            onclick="return confirm('Rotere nøkkel? Den gamle slutter å virke umiddelbart.')">
                        <i class="bi bi-arrow-repeat me-1"></i>Roter nøkkel
                    </button>
                </div>
            </form>

            <div class="small text-muted mt-3">
                Anbefalt praksis: bruk én aktiv nøkkel og roter ved behov (f.eks. månedlig eller ved mistanke).
            </div>
        </div>

        <?php if (!empty($history)): ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Notat</th>
                            <th>Opprettet</th>
                            <th>Revokert</th>
                            <th class="text-end">Handling</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $h): ?>
                        <?php
                        $id = (int)($h['id'] ?? 0);
                        $revokedAt = (string)($h['revoked_at'] ?? '');
                        $isActive = ($revokedAt === '' || $revokedAt === null);
                        ?>
                        <tr>
                            <td><?= (int)$id ?></td>
                            <td><?= esc((string)($h['note'] ?? '')) ?></td>
                            <td><?= esc((string)($h['created_at'] ?? '')) ?></td>
                            <td><?= esc($revokedAt !== '' ? $revokedAt : '—') ?></td>
                            <td class="text-end">
                                <?php if ($isActive): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                                        <button class="btn btn-sm btn-outline-danger" name="action" value="revoke_key"
                                                onclick="return confirm('Revokere denne nøkkelen?')">
                                            Revoker
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Dashboard-katalog -->
    <form class="card mb-3" method="get" action="/">
        <input type="hidden" name="page" value="events_dashboards">
        <?php if ($isTv): ?><input type="hidden" name="mode" value="tv"><?php endif; ?>

        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label class="form-label">Søk</label>
                    <input class="form-control" type="text" name="q" value="<?= esc($q) ?>" placeholder="Søk i tittel eller beskrivelse...">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Kategori</label>
                    <select class="form-select" name="category">
                        <option value="">Alle</option>
                        <?php foreach ($allCategories as $c): ?>
                            <option value="<?= esc($c) ?>" <?= (strcasecmp($c, $category) === 0) ? 'selected' : '' ?>>
                                <?= esc($c) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="">Alle</option>
                        <?php foreach ($allStatuses as $s): ?>
                            <option value="<?= esc($s) ?>" <?= (strcasecmp($s, $status) === 0) ? 'selected' : '' ?>>
                                <?= esc($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-funnel me-1"></i>Filtrer
                    </button>
                </div>

                <?php if ($q !== '' || $category !== '' || $status !== '' || $isTv): ?>
                    <div class="col-12">
                        <a class="small" href="/?page=events_dashboards<?= $isTv ? '&mode=tv' : '' ?>">Nullstill filtre</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="row g-3">
        <?php foreach ($filtered as $d): ?>
            <?php
            $title = (string)($d['title'] ?? '');
            $desc  = (string)($d['description'] ?? '');
            $url   = (string)($d['url'] ?? '#');
            $badge = (string)($d['badge'] ?? '');
            $icon  = (string)($d['icon'] ?? 'bi-speedometer2');

            $urlDisabled = ($url === '#' || $url === '');
            ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="dash-card p-3 h-100">
                    <div class="d-flex align-items-start justify-content-between">
                        <div class="d-flex gap-3">
                            <div class="dash-icon"><i class="bi <?= esc($icon) ?>"></i></div>
                            <div style="min-width:0;">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <h5 class="mb-0"><?= esc($title) ?></h5>
                                    <?php if ($badge !== ''): ?>
                                        <span class="badge bg-light text-dark border"><?= esc($badge) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="dash-meta mt-1 text-muted">
                                    <?= esc($desc) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="dash-actions d-flex gap-2 flex-wrap mt-3">
                        <a class="btn btn-sm btn-outline-primary <?= $urlDisabled ? 'disabled' : '' ?>"
                           href="<?= $urlDisabled ? '#' : esc($url) ?>"
                           <?= $urlDisabled ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                            <i class="bi bi-box-arrow-up-right me-1"></i>Åpne
                        </a>

                        <?php if (!$urlDisabled): ?>
                            <button class="btn btn-sm btn-outline-dark" type="button" data-copy="<?= esc($url) ?>">
                                <i class="bi bi-clipboard me-1"></i>Kopiér lenke
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if (($d['key'] ?? '') === 'events_tv'): ?>
                        <div class="small text-muted mt-3">
                            Public TV krever: <code>/dashboard/events/?key=...</code> + IP-allowlist.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<script>
(function() {
    document.addEventListener('click', async function(e) {
        var btn = e.target.closest('[data-copy]');
        if (!btn) return;

        var val = btn.getAttribute('data-copy') || '';
        if (!val) return;

        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(val);
            } else {
                var t = document.createElement('textarea');
                t.value = val;
                document.body.appendChild(t);
                t.select();
                document.execCommand('copy');
                t.remove();
            }

            var old = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Kopiert';
            btn.disabled = true;
            setTimeout(function() {
                btn.innerHTML = old;
                btn.disabled = false;
            }, 1200);
        } catch (err) {
            alert('Kunne ikke kopiere.');
        }
    });
})();
</script>