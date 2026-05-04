<?php
// public/pages/security.php
declare(strict_types=1);

use App\Audit;
use App\Database;

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
        try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
        catch (\Throwable $e) { return false; }
    }
}
if (!function_exists('get_client_ip')) {
    function get_client_ip(): string {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '' && ($remote === '127.0.0.1' || $remote === '::1')) {
            foreach (array_map('trim', explode(',', $xff)) as $p)
                if (filter_var($p, FILTER_VALIDATE_IP)) return $p;
        }
        return $remote;
    }
}
if (!function_exists('ip_matches_rule')) {
    function ip_matches_rule(string $ip, string $rule): bool {
        $ip = trim($ip); $rule = trim($rule);
        if ($ip === '' || $rule === '') return false;
        if (strpos($rule, '/') === false) return $ip === $rule;
        [$subnet, $bits] = explode('/', $rule, 2);
        $subnet = trim($subnet); $bits = (int)trim($bits);
        $ipBin  = @inet_pton($ip); $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false) return false;
        if (strlen($ipBin) !== strlen($subBin)) return false;
        $maxBits = strlen($ipBin) * 8;
        $bits = max(0, min($bits, $maxBits));
        $bytes = intdiv($bits, 8); $rem = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) return false;
        if ($rem === 0) return true;
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return ((ord($ipBin[$bytes] ?? "\0") & $mask) === (ord($subBin[$bytes] ?? "\0") & $mask));
    }
}
if (!function_exists('validate_ip_rule')) {
    function validate_ip_rule(string $rule, ?string &$err = null): bool {
        $rule = trim($rule);
        if ($rule === '') { $err = 'IP-regel kan ikke være tom.'; return false; }
        if (strpos($rule, '/') === false) {
            if (!filter_var($rule, FILTER_VALIDATE_IP)) { $err = 'Ugyldig IP-adresse.'; return false; }
            return true;
        }
        [$subnet, $bitsStr] = array_pad(explode('/', $rule, 2), 2, '');
        if (!filter_var(trim($subnet), FILTER_VALIDATE_IP)) { $err = 'Ugyldig subnet i CIDR.'; return false; }
        $bitsStr = trim($bitsStr);
        if ($bitsStr === '' || !ctype_digit($bitsStr)) { $err = 'Ugyldig CIDR-bits.'; return false; }
        $b = (int)$bitsStr;
        $max = strpos($subnet, ':') !== false ? 128 : 32;
        if ($b < 0 || $b > $max) { $err = "CIDR-bits må være 0–$max."; return false; }
        return true;
    }
}

// ---------------------------------------------------------
// Auth
// ---------------------------------------------------------
$username = (string)($_SESSION['username'] ?? '');
if ($username === '') {
    http_response_code(403);
    echo "<div class='alert alert-danger mt-3'>Du må være innlogget.</div>";
    return;
}

$pdo = $pdo ?? Database::getConnection();

$userId = 0;
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
$stmt->execute([':u' => $username]);
if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) $userId = (int)($u['id'] ?? 0);

$isAdmin = false;
if ($userId > 0) {
    $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :uid");
    $stmt->execute([':uid' => $userId]);
    $roles = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $isAdmin = in_array('admin', $roles, true);
}
if (!$isAdmin) {
    http_response_code(403);
    echo "<div class='alert alert-danger mt-3'>Du har ikke tilgang.</div>";
    return;
}

// ---------------------------------------------------------
// Schema: opprett/migrer kolonner
// ---------------------------------------------------------
$hasSettings = table_exists($pdo, 'security_ip_settings');
$hasAllow    = table_exists($pdo, 'security_ip_allowlist');
$hasLog      = table_exists($pdo, 'security_ip_log');

if ($hasLog) {
    try { $pdo->exec("ALTER TABLE security_ip_log ADD COLUMN country_code VARCHAR(2)  NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE security_ip_log ADD COLUMN country_name VARCHAR(64) NULL"); } catch (\Throwable $e) {}
}

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
        if ($action !== 'resolve_geoip' && (!$hasSettings || !$hasAllow)) {
            throw new \RuntimeException("Tabellene for IP-filter er ikke opprettet ennå.");
        }

        if ($action === 'toggle_enabled') {
            $newEnabled = !empty($_POST['enabled']) ? 1 : 0;
            $pdo->prepare("UPDATE security_ip_settings SET enabled=:en, updated_at=NOW(), updated_by=:ub WHERE id=1")
                ->execute([':en' => $newEnabled, ':ub' => $username]);
            $enabled = $newEnabled;
            $flashOk = $enabled ? "IP-filter er aktivert." : "IP-filter er deaktivert.";
            try { Audit::log($pdo, Audit::CAT_SECURITY, 'ip_filter_toggled',
                $enabled ? 'IP-filter aktivert' : 'IP-filter deaktivert',
                ['new_value' => ['enabled' => $newEnabled]],
                Audit::SEV_CRITICAL
            ); } catch (\Throwable $ae) {}
        }

        if ($action === 'add_rule') {
            $rule  = trim((string)($_POST['ip_rule'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            $err = null;
            if (!validate_ip_rule($rule, $err)) throw new \RuntimeException($err ?: 'Ugyldig regel.');
            $pdo->prepare("INSERT INTO security_ip_allowlist (ip_rule, label, is_active, created_at, created_by) VALUES (:r, :l, 1, NOW(), :cb)")
                ->execute([':r' => $rule, ':l' => ($label !== '' ? $label : null), ':cb' => $username]);
            $flashOk = "Regel lagt til: $rule";
            try { Audit::log($pdo, Audit::CAT_SECURITY, 'ip_rule_added',
                'IP-regel lagt til: ' . $rule . ($label !== '' ? ' (' . $label . ')' : ''),
                ['target_type' => 'ip_rule', 'target_name' => $rule],
                Audit::SEV_WARNING
            ); } catch (\Throwable $ae) {}
        }

        if ($action === 'update_label') {
            $id    = (int)($_POST['id'] ?? 0);
            $label = trim((string)($_POST['label'] ?? ''));
            if ($id <= 0) throw new \RuntimeException("Ugyldig ID.");
            $pdo->prepare("UPDATE security_ip_allowlist SET label=?, updated_at=NOW(), updated_by=? WHERE id=? LIMIT 1")
                ->execute([$label !== '' ? $label : null, $username, $id]);
            $flashOk = "Navn oppdatert.";
        }

        if ($action === 'allow_ip_from_log') {
            $ip   = trim((string)($_POST['ip'] ?? ''));
            $hint = trim((string)($_POST['hint'] ?? ''));
            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP))
                throw new \RuntimeException("Ugyldig IP i logg.");
            $label = 'Fra blokk-logg' . ($hint !== '' ? ' – ' . mb_substr($hint, 0, 120) : '');
            $stmt = $pdo->prepare("SELECT id, is_active FROM security_ip_allowlist WHERE ip_rule = :ip LIMIT 1");
            $stmt->execute([':ip' => $ip]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $pdo->prepare("UPDATE security_ip_allowlist SET is_active=1, updated_at=NOW(), updated_by=:ub WHERE id=:id LIMIT 1")
                    ->execute([':ub' => $username, ':id' => (int)$existing['id']]);
                $flashOk = "IP aktivert i allowlist: $ip";
            } else {
                $pdo->prepare("INSERT INTO security_ip_allowlist (ip_rule, label, is_active, created_at, created_by) VALUES (:ip, :label, 1, NOW(), :cb)")
                    ->execute([':ip' => $ip, ':label' => $label, ':cb' => $username]);
                $flashOk = "IP tillatt og lagt til i allowlist: $ip";
            }
            try { Audit::log($pdo, Audit::CAT_SECURITY, 'ip_allowed_from_log',
                'IP manuelt tillatt fra blokk-logg: ' . $ip,
                ['target_type' => 'ip_rule', 'target_name' => $ip],
                Audit::SEV_WARNING
            ); } catch (\Throwable $ae) {}
        }

        if ($action === 'set_active') {
            $id = (int)($_POST['id'] ?? 0);
            $to = (int)($_POST['to'] ?? 0);
            if ($id <= 0) throw new \RuntimeException("Ugyldig ID.");
            $stRule = $pdo->prepare("SELECT ip_rule FROM security_ip_allowlist WHERE id=:id LIMIT 1");
            $stRule->execute([':id' => $id]);
            $ruleIp = (string)($stRule->fetchColumn() ?: '');
            $pdo->prepare("UPDATE security_ip_allowlist SET is_active=:to, updated_at=NOW(), updated_by=:ub WHERE id=:id LIMIT 1")
                ->execute([':to' => $to, ':ub' => $username, ':id' => $id]);
            $flashOk = $to ? "Regel aktivert." : "Regel deaktivert.";
            try { Audit::log($pdo, Audit::CAT_SECURITY, 'ip_rule_toggled',
                ($to ? 'IP-regel aktivert: ' : 'IP-regel deaktivert: ') . $ruleIp,
                ['target_type' => 'ip_rule', 'target_id' => $id, 'target_name' => $ruleIp],
                Audit::SEV_WARNING
            ); } catch (\Throwable $ae) {}
        }

        if ($action === 'delete_rule') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new \RuntimeException("Ugyldig ID.");
            $stRule = $pdo->prepare("SELECT ip_rule FROM security_ip_allowlist WHERE id=:id LIMIT 1");
            $stRule->execute([':id' => $id]);
            $ruleIp = (string)($stRule->fetchColumn() ?: '');
            $pdo->prepare("DELETE FROM security_ip_allowlist WHERE id=:id LIMIT 1")->execute([':id' => $id]);
            $flashOk = "Regel slettet.";
            try { Audit::log($pdo, Audit::CAT_SECURITY, 'ip_rule_deleted',
                'IP-regel slettet: ' . $ruleIp,
                ['target_type' => 'ip_rule', 'target_id' => $id, 'target_name' => $ruleIp],
                Audit::SEV_WARNING
            ); } catch (\Throwable $ae) {}
        }

        if ($action === 'resolve_geoip' && $hasLog) {
            $ips = $pdo->query(
                "SELECT DISTINCT ip FROM security_ip_log
                  WHERE action='deny' AND ip IS NOT NULL AND country_code IS NULL
                  LIMIT 100"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];

            if (!empty($ips)) {
                $batch = array_map(fn($ip) => ['query' => $ip, 'fields' => 'query,countryCode,country'], $ips);
                $ch = curl_init('http://ip-api.com/batch?fields=query,countryCode,country');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($batch),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT        => 12,
                ]);
                $resp = (string)curl_exec($ch);
                curl_close($ch);
                $results = json_decode($resp, true) ?: [];
                $stmt = $pdo->prepare(
                    "UPDATE security_ip_log SET country_code=?, country_name=?
                      WHERE ip=? AND country_code IS NULL"
                );
                $resolved = 0;
                foreach ($results as $r) {
                    $cc = (string)($r['countryCode'] ?? '');
                    $cn = (string)($r['country'] ?? '');
                    $q  = (string)($r['query'] ?? '');
                    if ($q !== '' && $cc !== '' && $cc !== 'XX') {
                        $stmt->execute([$cc, $cn !== '' ? $cn : null, $q]);
                        $resolved++;
                    }
                }
                $remaining = count($ips) - $resolved;
                $flashOk = "Land-info oppdatert for $resolved IP-er." .
                           ($remaining > 0 ? " $remaining uten svar (private/ukjente)." : '');
            } else {
                $flashOk = "Alle IP-er har allerede land-info (eller ingen blokkerte IP-er uten land).";
            }
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
    $stmt = $pdo->query("SELECT id, ip_rule, label, is_active, created_at, created_by FROM security_ip_allowlist ORDER BY is_active DESC, id DESC");
    $rules = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

$activeRules    = array_values(array_filter($rules, fn($r) => (int)($r['is_active'] ?? 0) === 1));
$wouldEnforce   = ($enabled === 1 && count($activeRules) > 0);
$isAllowedNow   = false;
if ($wouldEnforce) {
    foreach ($activeRules as $r)
        if (ip_matches_rule($clientIp, (string)($r['ip_rule'] ?? ''))) { $isAllowedNow = true; break; }
}

// Dashboard-statistikk
$blockToday  = 0; $block7d = 0; $block30d = 0; $blockTotal = 0;
$blocksPerDay  = [];
$topCountries  = [];
$topIps        = [];
$unresolvedCnt = 0;
$recentBlocks  = [];

if ($hasLog) {
    try {
        $blockToday = (int)$pdo->query("SELECT COUNT(*) FROM security_ip_log WHERE action='deny' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
        $block7d    = (int)$pdo->query("SELECT COUNT(*) FROM security_ip_log WHERE action='deny' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
        $block30d   = (int)$pdo->query("SELECT COUNT(*) FROM security_ip_log WHERE action='deny' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $blockTotal = (int)$pdo->query("SELECT COUNT(*) FROM security_ip_log WHERE action='deny'")->fetchColumn();

        // Blokker per dag (siste 14 dager)
        $st = $pdo->query("
            SELECT DATE(created_at) AS day, COUNT(*) AS cnt
              FROM security_ip_log
             WHERE action='deny' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
             GROUP BY DATE(created_at)
             ORDER BY day ASC
        ");
        $blocksPerDay = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

        // Top land (siste 30 dager)
        $st = $pdo->query("
            SELECT COALESCE(NULLIF(country_name,''), NULLIF(country_code,''), 'Ukjent') AS country,
                   country_code,
                   COUNT(*) AS cnt
              FROM security_ip_log
             WHERE action='deny' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY country_name, country_code
             ORDER BY cnt DESC
             LIMIT 10
        ");
        $topCountries = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

        // Top IP-er (siste 30 dager)
        $st = $pdo->query("
            SELECT ip,
                   COALESCE(NULLIF(country_name,''), NULLIF(country_code,''), '?') AS country,
                   country_code,
                   COUNT(*) AS cnt,
                   MAX(created_at) AS last_seen
              FROM security_ip_log
             WHERE action='deny' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY ip, country_name, country_code
             ORDER BY cnt DESC
             LIMIT 20
        ");
        $topIps = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

        // Antall IP-er uten land (for "Oppdater"-knapp)
        $unresolvedCnt = (int)$pdo->query(
            "SELECT COUNT(DISTINCT ip) FROM security_ip_log WHERE action='deny' AND ip IS NOT NULL AND country_code IS NULL"
        )->fetchColumn();

        // Siste 15 blokker (for logg-snitt)
        $st = $pdo->query("
            SELECT ip, country_code, country_name, page, created_at
              FROM security_ip_log
             WHERE action='deny'
             ORDER BY id DESC LIMIT 15
        ");
        $recentBlocks = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

    } catch (\Throwable $e) {
        $flashErr = ($flashErr ? $flashErr . ' | ' : '') . "Stats-feil: " . $e->getMessage();
    }
}

// Bygg Chart.js-data
$chartDays   = [];
$chartCounts = [];
// Fyll ut alle 14 dager (null → 0)
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartDays[] = date('d.m', strtotime($d));
    $found = array_filter($blocksPerDay, fn($r) => $r['day'] === $d);
    $chartCounts[] = $found ? (int)array_values($found)[0]['cnt'] : 0;
}

$pieLabels  = array_column($topCountries, 'country');
$pieCounts  = array_column($topCountries, 'cnt');

$pieColors = [
    '#e63946','#457b9d','#2a9d8f','#e9c46a','#f4a261',
    '#264653','#a8dadc','#6d6875','#b5838d','#e07a5f',
];

?>
<div class="d-flex align-items-center justify-content-between mt-2 mb-3">
    <div>
        <h3 class="mb-1">Sikkerhet</h3>
        <div class="text-muted small">IP-filter (allowlist) og blokk-statistikk</div>
    </div>
</div>

<?php if ($flashOk): ?>
    <div class="alert alert-success"><?= h($flashOk) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="alert alert-danger"><?= h($flashErr) ?></div>
<?php endif; ?>

<?php if (!$hasSettings || !$hasAllow): ?>
    <div class="alert alert-warning">
        <strong>IP-filter-tabeller mangler.</strong>
        Opprett <code>security_ip_settings</code>, <code>security_ip_allowlist</code> og <code>security_ip_log</code>.
    </div>
<?php endif; ?>

<!-- ===================== DASHBOARD ===================== -->
<?php if ($hasLog): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-danger"><?= number_format($blockToday) ?></div>
                <div class="small text-muted">Blokkert siste 24t</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-warning"><?= number_format($block7d) ?></div>
                <div class="small text-muted">Siste 7 dager</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-primary"><?= number_format($block30d) ?></div>
                <div class="small text-muted">Siste 30 dager</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-secondary"><?= number_format($blockTotal) ?></div>
                <div class="small text-muted">Totalt alle tider</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- Blokker per dag -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>Blokker per dag (siste 14 dager)</span>
            </div>
            <div class="card-body">
                <canvas id="chartPerDay" style="max-height:220px;"></canvas>
            </div>
        </div>
    </div>

    <!-- Top land -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>Topp land – siste 30 dager</span>
                <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="resolve_geoip">
                    <button class="btn btn-outline-secondary btn-sm" title="Slå opp land for ukjente IP-er via ip-api.com">
                        <i class="bi bi-globe2 me-1"></i>Oppdater land<?php if ($unresolvedCnt > 0): ?> <span class="badge bg-warning text-dark"><?= $unresolvedCnt ?></span><?php endif; ?>
                    </button>
                </form>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if (!empty($pieLabels)): ?>
                    <canvas id="chartCountries" style="max-height:200px;max-width:340px;"></canvas>
                <?php else: ?>
                    <div class="text-muted small">Ingen land-data ennå. Trykk «Oppdater land».</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Top blokkerte IP-er -->
<div class="card mb-3">
    <div class="card-header">Top blokkerte IP-er – siste 30 dager</div>
    <div class="card-body p-0">
        <?php if (empty($topIps)): ?>
            <div class="p-3 text-muted">Ingen blokk-data ennå.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>IP</th>
                            <th>Land</th>
                            <th class="text-end">Treff</th>
                            <th>Sist sett</th>
                            <th class="text-end">Handling</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($topIps as $row): ?>
                        <?php
                        $rowIp  = (string)($row['ip'] ?? '');
                        $rowCc  = strtolower((string)($row['country_code'] ?? ''));
                        $rowCn  = (string)($row['country'] ?? '?');
                        $rowCnt = (int)($row['cnt'] ?? 0);
                        $lastSeen = (string)($row['last_seen'] ?? '');
                        ?>
                        <tr>
                            <td><code><?= h($rowIp) ?></code></td>
                            <td>
                                <?php if ($rowCc !== '' && $rowCc !== '?'): ?>
                                    <span class="me-1">
                                        <img src="https://flagcdn.com/16x12/<?= h($rowCc) ?>.png"
                                             width="16" height="12"
                                             alt="<?= h($rowCc) ?>"
                                             onerror="this.style.display='none'"
                                             loading="lazy">
                                    </span>
                                <?php endif; ?>
                                <?= h($rowCn) ?>
                            </td>
                            <td class="text-end">
                                <span class="badge bg-danger"><?= number_format($rowCnt) ?></span>
                            </td>
                            <td class="text-muted small"><?= h(substr($lastSeen, 0, 16)) ?></td>
                            <td class="text-end">
                                <?php if ($hasAllow): ?>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Tillate <?= h($rowIp) ?> i allowlist?');">
                                    <input type="hidden" name="action" value="allow_ip_from_log">
                                    <input type="hidden" name="ip" value="<?= h($rowIp) ?>">
                                    <input type="hidden" name="hint" value="<?= h($rowCn) ?>">
                                    <button class="btn btn-sm btn-outline-success">Tillat</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Siste blokkerte forespørsler (kompakt, sammenleggbar) -->
<div class="card mb-3">
    <div class="card-header">
        <button class="btn btn-link p-0 text-decoration-none fw-semibold text-body"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#recentBlocksCollapse">
            <i class="bi bi-chevron-down me-1"></i>Siste blokkerte forespørsler
        </button>
    </div>
    <div class="collapse" id="recentBlocksCollapse">
        <div class="card-body p-0">
            <?php if (empty($recentBlocks)): ?>
                <div class="p-3 text-muted">Ingen blokkerte forespørsler.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Tid</th><th>IP</th><th>Land</th><th>Side</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentBlocks as $lb):
                            $lbCc = strtolower((string)($lb['country_code'] ?? ''));
                            $lbCn = (string)($lb['country_name'] ?? '');
                        ?>
                            <tr>
                                <td class="text-muted small"><?= h(substr((string)$lb['created_at'], 0, 16)) ?></td>
                                <td><code class="small"><?= h((string)($lb['ip'] ?? '')) ?></code></td>
                                <td class="small">
                                    <?php if ($lbCc !== ''): ?>
                                        <img src="https://flagcdn.com/16x12/<?= h($lbCc) ?>.png" width="16" height="12" alt="<?= h($lbCc) ?>" onerror="this.style.display='none'" loading="lazy">
                                    <?php endif; ?>
                                    <?= h($lbCn ?: ($lbCc ?: '?')) ?>
                                </td>
                                <td class="small text-truncate" style="max-width:200px;"><?= h((string)($lb['page'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; // $hasLog ?>

<!-- ===================== ALLOWLIST + INNSTILLINGER ===================== -->
<div class="row g-3">
    <!-- Venstre: Status + Legg til -->
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm mb-3">
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
                            Aktivert →
                            <?= $isAllowedNow
                                ? "<span class='text-success'>DIN IP ER TILLATT</span>"
                                : "<span class='text-danger'>DIN IP VILLE BLITT BLOKKERT</span>" ?>
                        </div>
                    <?php else: ?>
                        <div class="fw-semibold">
                            <?= ($enabled === 1)
                                ? "Aktivert, men ingen aktive regler → <span class='text-warning'>ikke håndhevet</span>"
                                : "Deaktivert" ?>
                        </div>
                    <?php endif; ?>
                </div>
                <form method="post" class="border-top pt-3">
                    <input type="hidden" name="action" value="toggle_enabled">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="enabledSwitch"
                               name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>
                               <?= !$hasSettings ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="enabledSwitch">Aktiver IP-filter</label>
                    </div>
                    <button class="btn btn-primary btn-sm mt-2" <?= !$hasSettings ? 'disabled' : '' ?>>Lagre</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-2">Legg til nett i Allow-List</h5>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add_rule">
                    <div class="col-12">
                        <label class="form-label small mb-1">IP / CIDR</label>
                        <input type="text" name="ip_rule" class="form-control"
                               placeholder="10.0.0.0/24  eller  1.2.3.4"
                               <?= !$hasAllow ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Navn / beskrivelse</label>
                        <input type="text" name="label" class="form-control"
                               placeholder="Kontor, VPN, Partner …"
                               <?= !$hasAllow ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-success btn-sm" <?= !$hasAllow ? 'disabled' : '' ?>>
                            <i class="bi bi-plus-lg me-1"></i>Legg til
                        </button>
                    </div>
                </form>
                <div class="small text-muted mt-2">Støtter enkelt-IP og CIDR. Eks: <code>192.168.10.0/24</code></div>
            </div>
        </div>
    </div>

    <!-- Høyre: Allowlist-tabell -->
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header">Allow-List</div>
            <div class="card-body p-0">
                <?php if (empty($rules)): ?>
                    <div class="p-3 text-muted">Ingen regler lagt inn ennå.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Status</th>
                                <th>Nett / IP</th>
                                <th>Navn</th>
                                <th>Opprettet</th>
                                <th class="text-end">Handlinger</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rules as $r):
                                $rid    = (int)$r['id'];
                                $active = (int)($r['is_active'] ?? 0) === 1;
                                $lbl    = (string)($r['label'] ?? '');
                            ?>
                                <tr>
                                    <td>
                                        <span class="badge <?= $active ? 'bg-success' : 'bg-secondary' ?>">
                                            <?= $active ? 'Aktiv' : 'Inaktiv' ?>
                                        </span>
                                    </td>
                                    <td><code><?= h((string)$r['ip_rule']) ?></code></td>
                                    <td>
                                        <!-- Viser navn + inline redigering -->
                                        <span class="sec-label-text-<?= $rid ?>"><?= h($lbl) ?></span>
                                        <form method="post"
                                              class="sec-label-form-<?= $rid ?> d-none d-flex gap-1 mt-1"
                                              style="min-width:0;">
                                            <input type="hidden" name="action" value="update_label">
                                            <input type="hidden" name="id" value="<?= $rid ?>">
                                            <input type="text" name="label"
                                                   class="form-control form-control-sm"
                                                   value="<?= h($lbl) ?>"
                                                   placeholder="Navn / beskrivelse"
                                                   style="max-width:160px;">
                                            <button class="btn btn-sm btn-primary">Lagre</button>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary"
                                                    onclick="secLabelToggle(<?= $rid ?>)">Avbryt</button>
                                        </form>
                                    </td>
                                    <td class="text-muted small">
                                        <?= h(substr((string)($r['created_at'] ?? ''), 0, 10)) ?>
                                        <?php if (!empty($r['created_by'])): ?>
                                            <div><?= h((string)$r['created_by']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary"
                                                    onclick="secLabelToggle(<?= $rid ?>)"
                                                    title="Rediger navn">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="set_active">
                                                <input type="hidden" name="id" value="<?= $rid ?>">
                                                <input type="hidden" name="to" value="<?= $active ? 0 : 1 ?>">
                                                <button class="btn btn-sm <?= $active ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                                    <?= $active ? 'Deaktiver' : 'Aktiver' ?>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline"
                                                  onsubmit="return confirm('Slette regelen permanent?');">
                                                <input type="hidden" name="action" value="delete_rule">
                                                <input type="hidden" name="id" value="<?= $rid ?>">
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
                <div class="p-3 pt-2 small text-muted">
                    Når IP-filter er aktivert og minst én regel er aktiv, vil alle andre IP-er bli blokkert.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function () {
    // Bar chart: blokker per dag
    var ctxDay = document.getElementById('chartPerDay');
    if (ctxDay) {
        new Chart(ctxDay, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartDays) ?>,
                datasets: [{
                    label: 'Blokker',
                    data: <?= json_encode($chartCounts) ?>,
                    backgroundColor: 'rgba(220, 53, 69, 0.75)',
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }

    // Doughnut: top land
    var ctxCountry = document.getElementById('chartCountries');
    if (ctxCountry) {
        new Chart(ctxCountry, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($pieLabels) ?>,
                datasets: [{
                    data: <?= json_encode($pieCounts) ?>,
                    backgroundColor: <?= json_encode($pieColors) ?>,
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 14, font: { size: 12 } } }
                }
            }
        });
    }

    // Inline label editing
    window.secLabelToggle = function (id) {
        var textEl = document.querySelector('.sec-label-text-' + id);
        var formEl = document.querySelector('.sec-label-form-' + id);
        if (!textEl || !formEl) return;
        var hidden = formEl.classList.contains('d-none');
        textEl.style.display = hidden ? 'none' : '';
        formEl.classList.toggle('d-none', !hidden);
        if (hidden) formEl.querySelector('input[name=label]').focus();
    };
})();
</script>
