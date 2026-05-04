<?php
// public/pages/audit_log.php – Audit-logg (kun admin)

declare(strict_types=1);

use App\Database;

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!$isAdmin) {
    echo '<div class="alert alert-danger">Tilgang nektet – kun administratorer.</div>';
    return;
}

$pdo = $pdo ?? Database::getConnection();

// ------------------------------------------------------------------
// Auto-migrering: opprett tabell + system_settings-nøkler
// ------------------------------------------------------------------
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `audit_log` (
            `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `event_category` VARCHAR(50)  NOT NULL,
            `event_type`     VARCHAR(100) NOT NULL,
            `severity`       ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
            `actor_username` VARCHAR(255) DEFAULT NULL,
            `actor_ip`       VARCHAR(45)  DEFAULT NULL,
            `actor_provider` VARCHAR(32)  DEFAULT NULL,
            `target_type`    VARCHAR(100) DEFAULT NULL,
            `target_id`      VARCHAR(255) DEFAULT NULL,
            `target_name`    VARCHAR(255) DEFAULT NULL,
            `description`    TEXT         DEFAULT NULL,
            `old_value`      JSON         DEFAULT NULL,
            `new_value`      JSON         DEFAULT NULL,
            `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_created_at` (`created_at`),
            INDEX `idx_category`   (`event_category`),
            INDEX `idx_actor`      (`actor_username`),
            INDEX `idx_severity`   (`severity`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    foreach (['audit_alert_email' => '', 'audit_alert_severity' => 'critical'] as $k => $v) {
        $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?,?)")
            ->execute([$k, $v]);
    }
} catch (\Throwable $e) {
    error_log('audit_log.php migrering: ' . $e->getMessage());
}

// ------------------------------------------------------------------
// POST: varslingskonfig + tøm logg
// ------------------------------------------------------------------
$flashOk  = null;
$flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_alert_config') {
        try {
            $email  = trim((string)($_POST['audit_alert_email']    ?? ''));
            $minSev = trim((string)($_POST['audit_alert_severity'] ?? 'critical'));
            if (!in_array($minSev, ['info','warning','critical'], true)) $minSev = 'critical';

            $up = $pdo->prepare(
                "INSERT INTO system_settings (setting_key, setting_value)
                 VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $up->execute([':k' => 'audit_alert_email',    ':v' => $email]);
            $up->execute([':k' => 'audit_alert_severity', ':v' => $minSev]);
            $flashOk = 'Varslingsinnstillinger lagret.';
        } catch (\Throwable $e) {
            $flashErr = 'Lagring feilet: ' . $e->getMessage();
        }
    }

    if ($action === 'purge_log') {
        $days = max(1, (int)($_POST['purge_days'] ?? 90));
        try {
            $del = $pdo->prepare(
                "DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :d DAY)"
            );
            $del->execute([':d' => $days]);
            $affected = $del->rowCount();
            $flashOk  = "Slettet {$affected} logg-poster eldre enn {$days} dager.";
        } catch (\Throwable $e) {
            $flashErr = 'Sletting feilet: ' . $e->getMessage();
        }
    }
}

// ------------------------------------------------------------------
// Hent varslingskonfig
// ------------------------------------------------------------------
$alertCfg = [];
try {
    $st = $pdo->query(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('audit_alert_email','audit_alert_severity')"
    );
    $alertCfg = $st ? $st->fetchAll(PDO::FETCH_KEY_PAIR) : [];
} catch (\Throwable $e) {}

$alertEmail  = (string)($alertCfg['audit_alert_email']    ?? '');
$alertMinSev = (string)($alertCfg['audit_alert_severity'] ?? 'critical');

// ------------------------------------------------------------------
// CSV-eksport
// ------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $rows = $pdo->query(
            "SELECT id, created_at, event_category, event_type, severity,
                    actor_username, actor_ip, actor_provider, target_type, target_name, description
               FROM audit_log ORDER BY id DESC LIMIT 10000"
        )->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Tidspunkt','Kategori','Hendelse','Alvorlighet','Bruker','IP','Provider','Mål-type','Mål','Beskrivelse'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['created_at'], $r['event_category'], $r['event_type'],
                $r['severity'], $r['actor_username'], $r['actor_ip'], $r['actor_provider'],
                $r['target_type'], $r['target_name'], $r['description'],
            ], ';');
        }
        fclose($out);
        exit;
    } catch (\Throwable $e) {
        $flashErr = 'Eksport feilet: ' . $e->getMessage();
    }
}

// ------------------------------------------------------------------
// Filter-parametre
// ------------------------------------------------------------------
$fCat    = trim((string)($_GET['cat']    ?? ''));
$fSev    = trim((string)($_GET['sev']    ?? ''));
$fActor  = trim((string)($_GET['actor']  ?? ''));
$fFrom   = trim((string)($_GET['from']   ?? ''));
$fTo     = trim((string)($_GET['to']     ?? ''));
$fSearch = trim((string)($_GET['search'] ?? ''));
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;

$where  = [];
$params = [];

if ($fCat !== '') {
    $where[] = 'event_category = :cat';
    $params[':cat'] = $fCat;
}
if ($fSev !== '' && in_array($fSev, ['info','warning','critical'], true)) {
    $where[] = 'severity = :sev';
    $params[':sev'] = $fSev;
}
if ($fActor !== '') {
    $where[] = 'actor_username LIKE :actor';
    $params[':actor'] = '%' . $fActor . '%';
}
if ($fFrom !== '') {
    $where[] = 'created_at >= :from';
    $params[':from'] = $fFrom . ' 00:00:00';
}
if ($fTo !== '') {
    $where[] = 'created_at <= :to';
    $params[':to'] = $fTo . ' 23:59:59';
}
if ($fSearch !== '') {
    $where[] = '(description LIKE :q OR event_type LIKE :q2 OR target_name LIKE :q3)';
    $params[':q']  = '%' . $fSearch . '%';
    $params[':q2'] = '%' . $fSearch . '%';
    $params[':q3'] = '%' . $fSearch . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Totalt antall
$total = 0;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM audit_log {$whereSql}");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
} catch (\Throwable $e) {}

$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Hent logg-poster
$rows = [];
try {
    $st = $pdo->prepare(
        "SELECT id, created_at, event_category, event_type, severity,
                actor_username, actor_ip, actor_provider,
                target_type, target_id, target_name, description, old_value, new_value
           FROM audit_log
           {$whereSql}
           ORDER BY id DESC
           LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $st->bindValue(':off', $offset,  PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $flashErr = ($flashErr ? $flashErr . ' | ' : '') . 'Henting feilet: ' . $e->getMessage();
}

// Statistikk for topp-banner
$stats = ['total' => $total, 'today' => 0, '7d' => 0, 'critical' => 0];
try {
    $stats['today']    = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= CURDATE()")->fetchColumn();
    $stats['7d']       = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $stats['critical'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE severity='critical' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
} catch (\Throwable $e) {}

// Distinkte kategorier for filter
$categories = [];
try {
    $categories = $pdo->query("SELECT DISTINCT event_category FROM audit_log ORDER BY event_category")
        ->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (\Throwable $e) {}

// Distinkte aktører for autocomplete
$actors = [];
try {
    $actors = $pdo->query(
        "SELECT DISTINCT actor_username FROM audit_log
          WHERE actor_username IS NOT NULL ORDER BY actor_username LIMIT 200"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (\Throwable $e) {}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------
function auditSevBadge(string $sev): string {
    return match($sev) {
        'critical' => '<span class="badge bg-danger">Kritisk</span>',
        'warning'  => '<span class="badge bg-warning text-dark">Advarsel</span>',
        default    => '<span class="badge bg-secondary">Info</span>',
    };
}

function auditCatBadge(string $cat): string {
    return match($cat) {
        'auth'      => '<span class="badge bg-primary">Auth</span>',
        'user_mgmt' => '<span class="badge bg-info text-dark">Bruker</span>',
        'security'  => '<span class="badge bg-danger bg-opacity-75">Sikkerhet</span>',
        'system'    => '<span class="badge bg-dark">System</span>',
        default     => '<span class="badge bg-secondary">' . h($cat) . '</span>',
    };
}

function auditEventLabel(string $type): string {
    return match($type) {
        'ad_login_success'       => 'AD-innlogging',
        'ad_login_failed'        => 'AD-innlogging feilet',
        'entra_login_success'    => 'Entra ID-innlogging',
        'logout'                 => 'Utlogging',
        '2fa_verified'           => '2FA verifisert',
        '2fa_setup_started'      => '2FA oppsett startet',
        'login_blocked_inactive' => 'Blokkert – inaktiv konto',
        'user_activated'         => 'Bruker aktivert',
        'user_deactivated'       => 'Bruker deaktivert',
        '2fa_reset'              => '2FA tilbakestilt',
        'user_deleted'           => 'Bruker slettet',
        'roles_updated'          => 'Roller endret',
        'password_changed'       => 'Passord endret',
        'password_change_failed' => 'Passordendring feilet',
        'ip_filter_toggled'      => 'IP-filter toggled',
        'ip_rule_added'          => 'IP-regel lagt til',
        'ip_rule_deleted'        => 'IP-regel slettet',
        'ip_rule_toggled'        => 'IP-regel toggled',
        'ip_allowed_from_log'    => 'IP tillatt fra logg',
        'entra_settings_updated' => 'Entra-innstillinger endret',
        default                  => $type,
    };
}

function auditBuildUrl(array $override = []): string {
    $p = array_merge([
        'page'   => 'audit_log',
        'cat'    => $_GET['cat']    ?? '',
        'sev'    => $_GET['sev']    ?? '',
        'actor'  => $_GET['actor']  ?? '',
        'from'   => $_GET['from']   ?? '',
        'to'     => $_GET['to']     ?? '',
        'search' => $_GET['search'] ?? '',
        'p'      => $_GET['p']      ?? 1,
    ], $override);
    return '/?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== 0 && $v !== '0'));
}

?>

<div class="d-flex align-items-center justify-content-between mt-2 mb-3 flex-wrap gap-2">
    <div>
        <h3 class="mb-1">Audit-logg</h3>
        <div class="text-muted small">Sikkerhetshendelser, tilganger og systemaktivitet</div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= h(auditBuildUrl(['export' => 'csv', 'p' => ''])) ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Eksporter CSV
        </a>
    </div>
</div>

<?php if ($flashOk): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= h($flashOk) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="alert alert-danger"><?= h($flashErr) ?></div>
<?php endif; ?>

<!-- Statistikk-banner -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold"><?= number_format($stats['today']) ?></div>
                <div class="small text-muted">I dag</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-primary"><?= number_format($stats['7d']) ?></div>
                <div class="small text-muted">Siste 7 dager</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-danger"><?= number_format($stats['critical']) ?></div>
                <div class="small text-muted">Kritiske (30d)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-secondary"><?= number_format($stats['total']) ?></div>
                <div class="small text-muted">Totalt</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="audit_log">

            <div class="col-12 col-sm-6 col-md-2">
                <label class="form-label small mb-1">Kategori</label>
                <select name="cat" class="form-select form-select-sm">
                    <option value="">Alle</option>
                    <option value="auth"      <?= $fCat === 'auth'      ? 'selected' : '' ?>>Auth</option>
                    <option value="user_mgmt" <?= $fCat === 'user_mgmt' ? 'selected' : '' ?>>Bruker</option>
                    <option value="security"  <?= $fCat === 'security'  ? 'selected' : '' ?>>Sikkerhet</option>
                    <option value="system"    <?= $fCat === 'system'    ? 'selected' : '' ?>>System</option>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-2">
                <label class="form-label small mb-1">Alvorlighet</label>
                <select name="sev" class="form-select form-select-sm">
                    <option value="">Alle</option>
                    <option value="info"     <?= $fSev === 'info'     ? 'selected' : '' ?>>Info</option>
                    <option value="warning"  <?= $fSev === 'warning'  ? 'selected' : '' ?>>Advarsel</option>
                    <option value="critical" <?= $fSev === 'critical' ? 'selected' : '' ?>>Kritisk</option>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-2">
                <label class="form-label small mb-1">Bruker</label>
                <input type="text" name="actor" class="form-control form-control-sm"
                       value="<?= h($fActor) ?>" placeholder="Søk bruker…"
                       list="actor-list">
                <datalist id="actor-list">
                    <?php foreach ($actors as $a): ?>
                        <option value="<?= h($a) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Fra dato</label>
                <input type="date" name="from" class="form-control form-control-sm"
                       value="<?= h($fFrom) ?>">
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Til dato</label>
                <input type="date" name="to" class="form-control form-control-sm"
                       value="<?= h($fTo) ?>">
            </div>

            <div class="col-12 col-md-2 d-flex gap-1">
                <button class="btn btn-primary btn-sm flex-grow-1">
                    <i class="bi bi-search me-1"></i>Filtrer
                </button>
                <a href="/?page=audit_log" class="btn btn-outline-secondary btn-sm" title="Nullstill">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Logg-tabell -->
<div class="card mb-3">
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
            <div class="p-4 text-muted">Ingen logg-poster funnet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:.85rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:130px;">Tidspunkt</th>
                        <th style="width:90px;">Kategori</th>
                        <th style="width:85px;">Alvorlighet</th>
                        <th>Hendelse</th>
                        <th>Beskrivelse</th>
                        <th>Bruker</th>
                        <th>IP</th>
                        <th style="width:30px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $row):
                    $hasExtra = ($row['old_value'] || $row['new_value'] || $row['target_type']);
                    $rowId    = 'al-' . (int)$row['id'];
                ?>
                    <tr class="<?= $row['severity'] === 'critical' ? 'table-danger' : ($row['severity'] === 'warning' ? 'table-warning' : '') ?>">
                        <td class="text-muted" style="white-space:nowrap;">
                            <?= h(substr((string)$row['created_at'], 0, 16)) ?>
                        </td>
                        <td><?= auditCatBadge((string)$row['event_category']) ?></td>
                        <td><?= auditSevBadge((string)$row['severity']) ?></td>
                        <td style="white-space:nowrap;">
                            <?= h(auditEventLabel((string)$row['event_type'])) ?>
                        </td>
                        <td class="text-truncate" style="max-width:300px;" title="<?= h((string)$row['description']) ?>">
                            <?= h((string)$row['description']) ?>
                            <?php if ($row['target_name']): ?>
                                <span class="text-muted ms-1">— <?= h((string)$row['target_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['actor_username']): ?>
                                <code class="small"><?= h((string)$row['actor_username']) ?></code>
                                <?php if ($row['actor_provider']): ?>
                                    <span class="badge bg-light text-muted border" style="font-size:.7rem;">
                                        <?= h((string)$row['actor_provider']) ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code class="small"><?= h((string)$row['actor_ip']) ?></code>
                        </td>
                        <td>
                            <?php if ($hasExtra): ?>
                                <button class="btn btn-link btn-sm p-0 text-muted"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= $rowId ?>"
                                        title="Detaljer">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($hasExtra): ?>
                    <tr class="collapse" id="<?= $rowId ?>">
                        <td colspan="8" class="bg-light py-2 px-3">
                            <div class="row g-2 small">
                                <?php if ($row['target_type']): ?>
                                <div class="col-12 col-md-4">
                                    <strong>Mål-type:</strong> <?= h((string)$row['target_type']) ?>
                                    <?php if ($row['target_id']): ?>
                                        <span class="text-muted">(ID: <?= h((string)$row['target_id']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($row['old_value']): ?>
                                <div class="col-12 col-md-4">
                                    <strong>Gammel verdi:</strong>
                                    <pre class="mb-0 small bg-white border rounded p-1 mt-1" style="max-height:120px;overflow:auto;"><?= h(json_encode(json_decode((string)$row['old_value']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </div>
                                <?php endif; ?>
                                <?php if ($row['new_value']): ?>
                                <div class="col-12 col-md-4">
                                    <strong>Ny verdi:</strong>
                                    <pre class="mb-0 small bg-white border rounded p-1 mt-1" style="max-height:120px;overflow:auto;"><?= h(json_encode(json_decode((string)$row['new_value']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Paginering -->
<?php if ($totalPages > 1): ?>
<nav class="mb-3">
    <ul class="pagination pagination-sm justify-content-center flex-wrap">
        <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="<?= h(auditBuildUrl(['p' => $page - 1])) ?>">«</a>
            </li>
        <?php endif; ?>
        <?php
        $pStart = max(1, $page - 3);
        $pEnd   = min($totalPages, $page + 3);
        for ($pi = $pStart; $pi <= $pEnd; $pi++):
        ?>
            <li class="page-item <?= $pi === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= h(auditBuildUrl(['p' => $pi])) ?>"><?= $pi ?></a>
            </li>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <li class="page-item">
                <a class="page-link" href="<?= h(auditBuildUrl(['p' => $page + 1])) ?>">»</a>
            </li>
        <?php endif; ?>
    </ul>
    <div class="text-center text-muted small">
        Side <?= $page ?> av <?= $totalPages ?> (<?= number_format($total) ?> poster)
    </div>
</nav>
<?php endif; ?>

<!-- Innstillinger (varsling + tøm) -->
<div class="row g-3">
    <!-- Varsling -->
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bell me-1"></i>Varsling ved hendelser
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="save_alert_config">
                    <div class="col-12">
                        <label class="form-label small mb-1">E-post for varsler</label>
                        <input type="email" name="audit_alert_email"
                               class="form-control form-control-sm"
                               value="<?= h($alertEmail) ?>"
                               placeholder="admin@hkbb.no">
                        <div class="form-text">La stå tom for å deaktivere e-postvarsling.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">Minimum alvorlighet for varsel</label>
                        <select name="audit_alert_severity" class="form-select form-select-sm">
                            <option value="warning"  <?= $alertMinSev === 'warning'  ? 'selected' : '' ?>>Advarsel og høyere</option>
                            <option value="critical" <?= $alertMinSev === 'critical' ? 'selected' : '' ?>>Kun kritiske</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm">
                            <i class="bi bi-save me-1"></i>Lagre
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Tøm logg -->
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-trash me-1"></i>Vedlikehold
            </div>
            <div class="card-body">
                <form method="post"
                      onsubmit="return confirm('Slette logg-poster? Dette kan ikke angres.');">
                    <input type="hidden" name="action" value="purge_log">
                    <label class="form-label small mb-1">Slett poster eldre enn</label>
                    <div class="input-group input-group-sm" style="max-width:200px;">
                        <input type="number" name="purge_days"
                               class="form-control" value="90" min="1" max="3650">
                        <span class="input-group-text">dager</span>
                    </div>
                    <button class="btn btn-outline-danger btn-sm mt-2">
                        <i class="bi bi-trash me-1"></i>Tøm gamle poster
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
