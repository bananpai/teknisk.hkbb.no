<?php
// public/pages/security.php

declare(strict_types=1);

use App\Database;

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
        try {
            $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('get_client_ip')) {
    function get_client_ip(): string {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        // Minimal "trygg" proxy-støtte: stol kun på XFF hvis request kommer fra localhost-proxy
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '' && ($remote === '127.0.0.1' || $remote === '::1')) {
            $parts = array_map('trim', explode(',', $xff));
            foreach ($parts as $p) {
                if (filter_var($p, FILTER_VALIDATE_IP)) return $p;
            }
        }

        return $remote;
    }
}

if (!function_exists('ip_matches_rule')) {
    function ip_matches_rule(string $ip, string $rule): bool {
        $ip = trim($ip);
        $rule = trim($rule);

        if ($ip === '' || $rule === '') return false;

        // Eksakt match (ingen /)
        if (strpos($rule, '/') === false) {
            return $ip === $rule;
        }

        // CIDR
        [$subnet, $bits] = explode('/', $rule, 2);
        $subnet = trim($subnet);
        $bits = (int)trim($bits);

        $ipBin  = @inet_pton($ip);
        $subBin = @inet_pton($subnet);

        if ($ipBin === false || $subBin === false) return false;
        if (strlen($ipBin) !== strlen($subBin)) return false;

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0) $bits = 0;
        if ($bits > $maxBits) $bits = $maxBits;

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($bytes > 0) {
            if (substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) return false;
        }

        if ($rem === 0) return true;

        $mask = (0xFF << (8 - $rem)) & 0xFF;
        $ipByte  = ord($ipBin[$bytes] ?? "\0");
        $subByte = ord($subBin[$bytes] ?? "\0");

        return (($ipByte & $mask) === ($subByte & $mask));
    }
}

if (!function_exists('validate_ip_rule')) {
    function validate_ip_rule(string $rule, ?string &$err = null): bool {
        $rule = trim($rule);
        if ($rule === '') {
            $err = 'IP-regel kan ikke være tom.';
            return false;
        }

        if (strpos($rule, '/') === false) {
            if (!filter_var($rule, FILTER_VALIDATE_IP)) {
                $err = 'Ugyldig IP-adresse.';
                return false;
            }
            return true;
        }

        [$subnet, $bits] = array_pad(explode('/', $rule, 2), 2, '');
        $subnet = trim($subnet);
        $bitsStr = trim($bits);

        if (!filter_var($subnet, FILTER_VALIDATE_IP)) {
            $err = 'Ugyldig subnet i CIDR.';
            return false;
        }
        if ($bitsStr === '' || !ctype_digit($bitsStr)) {
            $err = 'Ugyldig CIDR-bits (må være tall).';
            return false;
        }

        $bitsInt = (int)$bitsStr;
        $isV6 = (strpos($subnet, ':') !== false);
        $max = $isV6 ? 128 : 32;

        if ($bitsInt < 0 || $bitsInt > $max) {
            $err = "CIDR-bits må være mellom 0 og $max.";
            return false;
        }

        return true;
    }
}

// ---------------------------------------------------------
// Krev innlogging + admin-rolle (user_roles)
// ---------------------------------------------------------

$username = (string)($_SESSION['username'] ?? '');
if ($username === '') {
    http_response_code(403);
    echo "<div class='alert alert-danger mt-3'>Du må være innlogget.</div>";
    return;
}

$pdo = $pdo ?? Database::getConnection();

// Finn user_id
$userId = 0;
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
$stmt->execute([':u' => $username]);
if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $userId = (int)($u['id'] ?? 0);
}

// Roller
$isAdmin = false;
if ($userId > 0) {
    $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :uid");
    $stmt->execute([':uid' => $userId]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $roles = array_map('strtolower', array_map('trim', $roles));
    $isAdmin = in_array('admin', $roles, true);
}

if (!$isAdmin) {
    http_response_code(403);
    echo "<div class='alert alert-danger mt-3'>Du har ikke tilgang.</div>";
    return;
}

// ---------------------------------------------------------
// Sjekk tabeller
// ---------------------------------------------------------

$hasSettings = table_exists($pdo, 'security_ip_settings');
$hasAllow    = table_exists($pdo, 'security_ip_allowlist');
$hasLog      = table_exists($pdo, 'security_ip_log');

$flashOk = null;
$flashErr = null;

// ---------------------------------------------------------
// Hent / init settings
// ---------------------------------------------------------
$enabled = 0;
if ($hasSettings) {
    try {
        $enabled = (int)($pdo->query("SELECT enabled FROM security_ip_settings WHERE id=1")->fetchColumn() ?: 0);
        $pdo->exec("INSERT INTO security_ip_settings (id, enabled, updated_at) VALUES (1, $enabled, NOW())
                    ON DUPLICATE KEY UPDATE id=id");
    } catch (\Throwable $e) {
        $flashErr = "Kunne ikke lese settings: " . $e->getMessage();
    }
}

// ---------------------------------------------------------
// POST-handling
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if (!$hasSettings || !$hasAllow) {
            throw new RuntimeException("Tabellene for IP-filter er ikke opprettet ennå.");
        }

        if ($action === 'toggle_enabled') {
            $newEnabled = !empty($_POST['enabled']) ? 1 : 0;

            $stmt = $pdo->prepare("
                UPDATE security_ip_settings
                   SET enabled = :en,
                       updated_at = NOW(),
                       updated_by = :ub
                 WHERE id = 1
            ");
            $stmt->execute([':en' => $newEnabled, ':ub' => $username]);

            $enabled = $newEnabled;
            $flashOk = $enabled ? "IP-filter er aktivert." : "IP-filter er deaktivert.";
        }

        if ($action === 'add_rule') {
            $rule  = trim((string)($_POST['ip_rule'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));

            $err = null;
            if (!validate_ip_rule($rule, $err)) {
                throw new RuntimeException($err ?: 'Ugyldig regel.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO security_ip_allowlist (ip_rule, label, is_active, created_at, created_by)
                VALUES (:r, :l, 1, NOW(), :cb)
            ");
            $stmt->execute([
                ':r'  => $rule,
                ':l'  => ($label !== '' ? $label : null),
                ':cb' => $username,
            ]);

            $flashOk = "Regel lagt til: " . $rule;
        }

        // ✅ NY: Tillat IP direkte fra blokk-logg
        if ($action === 'allow_ip_from_log') {
            $ip   = trim((string)($_POST['ip'] ?? ''));
            $hint = trim((string)($_POST['hint'] ?? ''));

            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new RuntimeException("Ugyldig IP i logg.");
            }

            // Label: litt kontekst (kort og trygt)
            $labelParts = ['Fra blokk-logg'];
            if ($hint !== '') $labelParts[] = mb_substr($hint, 0, 120);
            $label = implode(' – ', $labelParts);

            // Finn eksisterende
            $stmt = $pdo->prepare("SELECT id, is_active FROM security_ip_allowlist WHERE ip_rule = :ip LIMIT 1");
            $stmt->execute([':ip' => $ip]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $id = (int)$existing['id'];

                $stmt = $pdo->prepare("
                    UPDATE security_ip_allowlist
                       SET is_active = 1,
                           updated_at = NOW(),
                           updated_by = :ub
                     WHERE id = :id
                     LIMIT 1
                ");
                $stmt->execute([':ub' => $username, ':id' => $id]);

                $flashOk = "IP finnes allerede i allowlist og er nå aktivert: $ip";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO security_ip_allowlist (ip_rule, label, is_active, created_at, created_by)
                    VALUES (:ip, :label, 1, NOW(), :cb)
                ");
                $stmt->execute([
                    ':ip'    => $ip,
                    ':label' => $label,
                    ':cb'    => $username,
                ]);

                $flashOk = "IP tillatt og lagt til i allowlist: $ip";
            }
        }

        if ($action === 'set_active') {
            $id = (int)($_POST['id'] ?? 0);
            $to = (int)($_POST['to'] ?? 0);

            if ($id <= 0) throw new RuntimeException("Ugyldig ID.");

            $stmt = $pdo->prepare("
                UPDATE security_ip_allowlist
                   SET is_active = :to,
                       updated_at = NOW(),
                       updated_by = :ub
                 WHERE id = :id
                 LIMIT 1
            ");
            $stmt->execute([':to' => $to, ':ub' => $username, ':id' => $id]);

            $flashOk = $to ? "Regel aktivert." : "Regel deaktivert.";
        }

        if ($action === 'delete_rule') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException("Ugyldig ID.");

            $stmt = $pdo->prepare("DELETE FROM security_ip_allowlist WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);

            $flashOk = "Regel slettet.";
        }
    } catch (\Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

// ---------------------------------------------------------
// Data for visning
// ---------------------------------------------------------

$clientIp = get_client_ip();

$rules = [];
if ($hasAllow) {
    $stmt = $pdo->query("
        SELECT id, ip_rule, label, is_active, created_at, created_by, updated_at, updated_by
          FROM security_ip_allowlist
         ORDER BY is_active DESC, id DESC
    ");
    $rules = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

// Match-status (hvis enabled + finnes aktive regler)
$activeRules = array_values(array_filter($rules, fn($r) => (int)($r['is_active'] ?? 0) === 1));
$wouldEnforce = ($enabled === 1 && count($activeRules) > 0);
$isAllowedNow = false;

if ($wouldEnforce) {
    foreach ($activeRules as $r) {
        if (ip_matches_rule($clientIp, (string)($r['ip_rule'] ?? ''))) {
            $isAllowedNow = true;
            break;
        }
    }
}

// Loggvisning (siste 200)
$logRows = [];
$logFilters = [
    'ip' => trim((string)($_GET['ip'] ?? '')),
    'username' => trim((string)($_GET['username'] ?? '')),
    'action' => trim((string)($_GET['action'] ?? '')),
];

if ($hasLog) {
    $where = [];
    $params = [];

    if ($logFilters['ip'] !== '') {
        $where[] = "ip = :ip";
        $params[':ip'] = $logFilters['ip'];
    }
    if ($logFilters['username'] !== '') {
        $where[] = "username = :un";
        $params[':un'] = $logFilters['username'];
    }
    if ($logFilters['action'] !== '') {
        $where[] = "action = :ac";
        $params[':ac'] = $logFilters['action'];
    }

    $sql = "SELECT id, action, ip, username, request_uri, page, method, user_agent, created_at
              FROM security_ip_log";
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY id DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

?>
<div class="d-flex align-items-center justify-content-between mt-3">
    <div>
        <h3 class="mb-1">Sikkerhet</h3>
        <div class="text-muted small">
            IP-filter (allowlist) for <code>/public/index.php</code>
        </div>
    </div>
</div>

<?php if ($flashOk): ?>
    <div class="alert alert-success mt-3"><?= h($flashOk) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="alert alert-danger mt-3"><?= h($flashErr) ?></div>
<?php endif; ?>

<?php if (!$hasSettings || !$hasAllow): ?>
    <div class="alert alert-warning mt-3">
        <div class="fw-semibold mb-1">IP-filter-tabeller mangler</div>
        <div class="small text-muted">
            Opprett tabellene <code>security_ip_settings</code> og <code>security_ip_allowlist</code> (og gjerne <code>security_ip_log</code>) før du kan administrere IP-filteret.
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mt-1">
    <div class="col-12 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-2">Status</h5>

                <div class="mb-2">
                    <div class="small text-muted">Din IP</div>
                    <div class="fw-semibold"><code><?= h($clientIp) ?></code></div>
                </div>

                <div class="mb-3">
                    <div class="small text-muted">Håndheving</div>
                    <?php if ($wouldEnforce): ?>
                        <div class="fw-semibold">
                            Aktivert, og listen har regler →
                            <?= $isAllowedNow ? "<span class='text-success'>TILLATT</span>" : "<span class='text-danger'>BLOKKERT</span>" ?>
                        </div>
                        <div class="small text-muted">
                            (Når aktivert, og det finnes minst én aktiv regel, vil kun matcher slippe inn.)
                        </div>
                    <?php else: ?>
                        <div class="fw-semibold">
                            <?= ($enabled === 1) ? "Aktivert, men ingen aktive regler → <span class='text-warning'>ikke håndhevet</span>" : "Deaktivert" ?>
                        </div>
                        <div class="small text-muted">
                            Tips: Legg inn minst én aktiv regel før du aktiverer, ellers håndheves ikke filteret.
                        </div>
                    <?php endif; ?>
                </div>

                <form method="post" class="border-top pt-3">
                    <input type="hidden" name="action" value="toggle_enabled">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="enabledSwitch"
                               name="enabled" value="1" <?= ($enabled === 1 ? 'checked' : '') ?>
                               <?= (!$hasSettings ? 'disabled' : '') ?>>
                        <label class="form-check-label" for="enabledSwitch">
                            Aktiver IP-filter
                        </label>
                    </div>
                    <button class="btn btn-primary btn-sm mt-2" <?= (!$hasSettings ? 'disabled' : '') ?>>
                        Lagre
                    </button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mt-3">
            <div class="card-body">
                <h5 class="card-title mb-2">Legg til IP / CIDR</h5>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add_rule">
                    <div class="col-12">
                        <label class="form-label small mb-1">IP-regel</label>
                        <input type="text" name="ip_rule" class="form-control"
                               placeholder="F.eks. 1.2.3.4 eller 10.0.0.0/24 eller 2001:db8::/32"
                               <?= (!$hasAllow ? 'disabled' : '') ?>>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Beskrivelse (valgfri)</label>
                        <input type="text" name="label" class="form-control" placeholder="Kontor / VPN / Partner"
                               <?= (!$hasAllow ? 'disabled' : '') ?>>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-success btn-sm" <?= (!$hasAllow ? 'disabled' : '') ?>>
                            Legg til
                        </button>
                    </div>
                </form>

                <div class="small text-muted mt-2">
                    Støtter både enkelt-IP og CIDR. Eksempel: <code>192.168.10.0/24</code>.
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-2">Allowlist</h5>

                <?php if (empty($rules)): ?>
                    <div class="text-muted">Ingen regler lagt inn.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr class="text-muted">
                                <th>Status</th>
                                <th>Regel</th>
                                <th>Beskrivelse</th>
                                <th>Opprettet</th>
                                <th class="text-end">Handling</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rules as $r): ?>
                                <?php
                                $id = (int)$r['id'];
                                $active = (int)($r['is_active'] ?? 0) === 1;
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($active): ?>
                                            <span class="badge bg-success">Aktiv</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inaktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?= h((string)$r['ip_rule']) ?></code></td>
                                    <td><?= h((string)($r['label'] ?? '')) ?></td>
                                    <td class="text-muted small">
                                        <?= h((string)($r['created_at'] ?? '')) ?>
                                        <?php if (!empty($r['created_by'])): ?>
                                            <div>av <?= h((string)$r['created_by']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="set_active">
                                                <input type="hidden" name="id" value="<?= (int)$id ?>">
                                                <input type="hidden" name="to" value="<?= $active ? 0 : 1 ?>">
                                                <button class="btn btn-sm <?= $active ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                                    <?= $active ? 'Deaktiver' : 'Aktiver' ?>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Slette regelen permanent?');">
                                                <input type="hidden" name="action" value="delete_rule">
                                                <input type="hidden" name="id" value="<?= (int)$id ?>">
                                                <button class="btn btn-sm btn-outline-danger">Slett</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="small text-muted">
                    NB: Når IP-filter er aktivert og minst én regel er aktiv, vil alle andre IP-er bli blokkert.
                </div>
            </div>
        </div>

        <div class="card shadow-sm mt-3">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <h5 class="card-title mb-0">Blokk-logg</h5>
                    <span class="text-muted small"><?= $hasLog ? "Siste 200" : "Logg-tabell mangler" ?></span>
                </div>

                <?php if ($hasLog): ?>
                    <form method="get" class="row g-2 mt-2">
                        <input type="hidden" name="page" value="security">
                        <div class="col-12 col-md-4">
                            <input class="form-control form-control-sm" name="ip" placeholder="IP"
                                   value="<?= h($logFilters['ip']) ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <input class="form-control form-control-sm" name="username" placeholder="Bruker"
                                   value="<?= h($logFilters['username']) ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <input class="form-control form-control-sm" name="action" placeholder="Action (deny)"
                                   value="<?= h($logFilters['action']) ?>">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-sm btn-outline-primary">Filtrer</button>
                            <a class="btn btn-sm btn-outline-secondary" href="/?page=security">Nullstill</a>
                        </div>
                    </form>

                    <div class="table-responsive mt-2">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr class="text-muted">
                                <th>Tid</th>
                                <th>IP</th>
                                <th>Bruker</th>
                                <th>Side</th>
                                <th>URI</th>
                                <th>UA</th>
                                <th class="text-end">Handling</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($logRows)): ?>
                                <tr><td colspan="7" class="text-muted">Ingen treff.</td></tr>
                            <?php else: ?>
                                <?php foreach ($logRows as $lr): ?>
                                    <?php
                                    $logIp = (string)($lr['ip'] ?? '');
                                    $hint = trim((string)($lr['username'] ?? ''));
                                    if ($hint === '') $hint = trim((string)($lr['page'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="text-muted small"><?= h((string)$lr['created_at']) ?></td>
                                        <td><code><?= h($logIp) ?></code></td>
                                        <td class="small"><?= h((string)($lr['username'] ?? '')) ?></td>
                                        <td class="small"><?= h((string)($lr['page'] ?? '')) ?></td>
                                        <td class="small text-truncate" style="max-width:260px;">
                                            <?= h((string)($lr['request_uri'] ?? '')) ?>
                                        </td>
                                        <td class="small text-truncate" style="max-width:260px;">
                                            <?= h((string)($lr['user_agent'] ?? '')) ?>
                                        </td>
                                        <td class="text-end">
                                            <form method="post" class="d-inline"
                                                  onsubmit="return confirm('Tillate IP ' + <?= json_encode($logIp) ?> + ' i allowlist?');">
                                                <input type="hidden" name="action" value="allow_ip_from_log">
                                                <input type="hidden" name="ip" value="<?= h($logIp) ?>">
                                                <input type="hidden" name="hint" value="<?= h($hint) ?>">
                                                <button class="btn btn-sm btn-outline-success" <?= (!$hasAllow ? 'disabled' : '') ?>>
                                                    Tillat
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-muted mt-2">
                        Opprett <code>security_ip_log</code> for å se blokk-logg.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
