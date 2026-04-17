<?php
// public/pages/contracts_alerts.php
//
// Avtaler & kontrakter – Varsler & fornyelser
//
// Viser:
//  - Dashboard: kommende hendelser (utløp, fornyelse, KPI) i 14/30/60/90 dager
//  - Liste over avtaler som snart trenger handling, med ansvarlig og mottakere
//  - Konfigurasjon av varslingsinnstillinger per avtale (dager_før + av/på)
//  - Konfigurasjon av mottakere per avtale (Varsle også / Stedfortreder)
//  - Varsel-logg for siste sendte varsler
//
// POST-handlinger:
//  - save_notify_settings : oppdaterer contracts_notify_settings
//  - save_recipients      : oppdaterer contracts_notify_recipients
//
// Krever: contracts, users
// Valgfritt: contracts_notify_settings, contracts_notify_recipients, contracts_alert_log

use App\Database;
use App\Mail\Mailer;
use PHPMailer\PHPMailer\Exception as MailException;

$username = $_SESSION['username'] ?? '';
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

// ------------------------------------------------------------------
// DB
// ------------------------------------------------------------------
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    ?>
    <div class="alert alert-danger mt-3">Kunne ikke koble til databasen.</div>
    <?php
    return;
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------
if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('contracts_table_exists')) {
    function contracts_table_exists(PDO $pdo, string $table): bool {
        try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
        catch (\Throwable $e) { return false; }
    }
}

// ------------------------------------------------------------------
// Schema checks
// ------------------------------------------------------------------
$hasContracts    = contracts_table_exists($pdo, 'contracts');
$hasUsers        = contracts_table_exists($pdo, 'users');
$hasNotifySet    = contracts_table_exists($pdo, 'contracts_notify_settings');
$hasRecipients   = contracts_table_exists($pdo, 'contracts_notify_recipients');
$hasAlertLog     = contracts_table_exists($pdo, 'contracts_alert_log');

// ACL (samme modell som contracts.php)
if (!function_exists('alerts_session_perms')) {
    function alerts_session_perms(): array {
        $keys = ['permissions', 'roles', 'groups', 'user_groups', 'ad_groups'];
        $out  = [];
        foreach ($keys as $k) {
            if (!isset($_SESSION[$k])) continue;
            $v = $_SESSION[$k];
            if (is_array($v)) {
                foreach ($v as $item) { $s = trim((string)$item); if ($s !== '') $out[] = mb_strtolower($s); }
            } else {
                foreach (preg_split('/[,\s;]+/', trim((string)$v)) ?: [] as $p) {
                    $p = trim((string)$p); if ($p !== '') $out[] = mb_strtolower($p);
                }
            }
        }
        return array_values(array_unique($out));
    }
}

$sessionPerms = alerts_session_perms();
$isAdmin      = (bool)($_SESSION['is_admin'] ?? false) || in_array('admin', $sessionPerms, true);
$canWrite     = $isAdmin || in_array('contracts_write', $sessionPerms, true);

// ------------------------------------------------------------------
// CSRF
// ------------------------------------------------------------------
if (empty($_SESSION['csrf_alerts'])) {
    $_SESSION['csrf_alerts'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['csrf_alerts'];

// ------------------------------------------------------------------
// POST handler
// ------------------------------------------------------------------
$postOk    = null;
$postError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite) {
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
        $postError = 'Ugyldig token. Last siden på nytt og prøv igjen.';
    } else {
        $action     = (string)($_POST['action'] ?? '');
        $contractId = (int)($_POST['contract_id'] ?? 0);

        if ($action === 'save_notify_settings' && $contractId > 0) {
            if (!$hasNotifySet) {
                $postError = 'Tabellen contracts_notify_settings finnes ikke ennå.';
            } else {
                try {
                    $enabled        = (int)(($_POST['enabled'] ?? '1') === '1');
                    $dayEnd         = max(0, (int)($_POST['days_before_end']     ?? 30));
                    $dayRenewal     = max(0, (int)($_POST['days_before_renewal'] ?? 30));
                    $dayKpi         = max(0, (int)($_POST['days_before_kpi']     ?? 30));

                    $pdo->prepare("
                        INSERT INTO contracts_notify_settings
                            (contract_id, enabled, days_before_end, days_before_renewal, days_before_kpi, updated_at)
                        VALUES
                            (:cid, :en, :de, :dr, :dk, NOW())
                        ON DUPLICATE KEY UPDATE
                            enabled = VALUES(enabled),
                            days_before_end     = VALUES(days_before_end),
                            days_before_renewal = VALUES(days_before_renewal),
                            days_before_kpi     = VALUES(days_before_kpi),
                            updated_at          = NOW()
                    ")->execute([
                        ':cid' => $contractId,
                        ':en'  => $enabled,
                        ':de'  => $dayEnd,
                        ':dr'  => $dayRenewal,
                        ':dk'  => $dayKpi,
                    ]);

                    $postOk = 'Varslingsinnstillinger lagret.';
                    // refresh csrf
                    $_SESSION['csrf_alerts'] = bin2hex(random_bytes(24));
                    $csrf = (string)$_SESSION['csrf_alerts'];
                } catch (\Throwable $e) {
                    $postError = 'Kunne ikke lagre: ' . h($e->getMessage());
                }
            }
        } elseif ($action === 'send_test_email') {
            // Send test email to the logged-in user
            $toEmail = trim((string)($_POST['test_email'] ?? ''));
            if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                $postError = 'Ugyldig e-postadresse for testepost.';
            } else {
                try {
                    $mailer = new Mailer();
                    $mailer->sendTest($toEmail);
                    $postOk = "Testepost sendt til $toEmail.";
                } catch (MailException | \Throwable $e) {
                    $postError = 'Kunne ikke sende testepost: ' . h($e->getMessage());
                }
            }
        } elseif ($action === 'send_alert_now' && $contractId > 0) {
            // Manually send alert(s) for a specific contract right now
            $alertType = (string)($_POST['alert_type'] ?? '');
            $validTypes = ['end' => 'end_date', 'renewal' => 'renewal_date', 'kpi' => 'kpi_adjust_date'];

            if (!isset($validTypes[$alertType])) {
                $postError = 'Ugyldig varseltype.';
            } else {
                try {
                    // Fetch contract
                    $stC = $pdo->prepare("SELECT id, title, owner_username, end_date, renewal_date, kpi_adjust_date FROM contracts WHERE id = :id LIMIT 1");
                    $stC->execute([':id' => $contractId]);
                    $cRow = $stC->fetch(\PDO::FETCH_ASSOC) ?: null;

                    if (!$cRow) {
                        $postError = 'Fant ikke avtalen.';
                    } else {
                        $dateVal = trim((string)($cRow[$validTypes[$alertType]] ?? ''));
                        if ($dateVal === '') throw new \RuntimeException('Datofelt er tomt for denne avtalen.');

                        $ts = strtotime($dateVal);
                        $daysUntil = $ts ? (int)round(($ts - strtotime('today')) / 86400) : 0;

                        // Build recipients
                        $to = [];
                        if ($hasUsers) {
                            $ownerUn = mb_strtolower(trim((string)($cRow['owner_username'] ?? '')));
                            if ($ownerUn !== '') {
                                $stU = $pdo->prepare("SELECT email, COALESCE(NULLIF(display_name,''), username) AS dn FROM users WHERE LOWER(username) = :u AND is_active = 1 LIMIT 1");
                                $stU->execute([':u' => $ownerUn]);
                                $uRow = $stU->fetch(\PDO::FETCH_ASSOC);
                                if ($uRow && trim((string)$uRow['email']) !== '') {
                                    $to[trim($uRow['email'])] = trim((string)$uRow['dn']);
                                }
                            }
                        }
                        if ($hasRecipients) {
                            $stR = $pdo->prepare("
                                SELECT u.email, COALESCE(NULLIF(u.display_name,''), u.username) AS dn
                                FROM contracts_notify_recipients r
                                JOIN users u ON u.id = r.user_id
                                WHERE r.contract_id = :cid AND r.is_active = 1 AND u.is_active = 1 AND u.email <> ''
                            ");
                            $stR->execute([':cid' => $contractId]);
                            foreach ($stR->fetchAll(\PDO::FETCH_ASSOC) as $rr) {
                                $em = trim((string)$rr['email']);
                                if ($em !== '') $to[$em] = trim((string)$rr['dn']);
                            }
                        }

                        if (empty($to)) throw new \RuntimeException('Ingen e-postadresser funnet for denne avtalen. Sjekk at ansvarlig har e-post registrert.');

                        $typeLabels = ['end' => 'Utløper', 'renewal' => 'Fornyes', 'kpi' => 'KPI/indeks'];
                        $typeLabel = $typeLabels[$alertType];
                        $title = htmlspecialchars((string)$cRow['title'], ENT_QUOTES, 'UTF-8');
                        $dateFormatted = $ts ? date('d.m.Y', $ts) : $dateVal;
                        $baseUrl = rtrim((string)(getenv('APP_URL') ?: 'https://teknisk.hkbb.no'), '/');
                        $viewUrl = $baseUrl . '/?page=contracts_view&id=' . $contractId;
                        $urgencyColor = $daysUntil <= 7 ? '#dc3545' : ($daysUntil <= 14 ? '#fd7e14' : '#0d6efd');
                        $daysText = $daysUntil > 0 ? "$daysUntil dag" . ($daysUntil !== 1 ? 'er' : '') : 'i dag';
                        $subject = "[HKBB Teknisk] $typeLabel om $daysText: $title";

                        $html = "<!DOCTYPE html><html lang='no'><head><meta charset='UTF-8'></head><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:16px;'>
                        <div style='background:#0d6efd;color:#fff;padding:16px 20px;border-radius:6px 6px 0 0;'><strong>HKBB Teknisk – Avtalevarsel</strong></div>
                        <div style='border:1px solid #dee2e6;border-top:none;padding:20px;border-radius:0 0 6px 6px;'>
                        <p>Avtalen <strong>$title</strong> har en hendelse om <strong style='color:$urgencyColor;'>$daysText</strong>:</p>
                        <table style='border-collapse:collapse;width:100%;margin-bottom:16px;'>
                          <tr><td style='padding:6px 10px;background:#f8f9fa;font-weight:bold;width:40%;'>Hendelse</td><td style='padding:6px 10px;border-left:1px solid #dee2e6;'><span style='background:$urgencyColor;color:#fff;padding:3px 10px;border-radius:4px;font-size:.9em;'>$typeLabel</span></td></tr>
                          <tr><td style='padding:6px 10px;background:#f8f9fa;font-weight:bold;'>Dato</td><td style='padding:6px 10px;border-left:1px solid #dee2e6;'><strong>$dateFormatted</strong></td></tr>
                        </table>
                        <p><a href='$viewUrl' style='display:inline-block;background:#0d6efd;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;font-weight:bold;'>Åpne avtale i Teknisk</a></p>
                        <p style='color:#6c757d;font-size:.85em;'>Manuelt sendt varsling fra HKBB Teknisk – Avtaler &amp; kontrakter.</p>
                        </div></body></html>";

                        $mailerInst = new Mailer();
                        $mailerInst->send($to, $subject, $html);

                        $sentToStr = implode(', ', array_keys($to));
                        $postOk = "Varsel sendt til: $sentToStr";

                        // Log
                        if ($hasAlertLog) {
                            try {
                                $pdo->prepare("INSERT INTO contracts_alert_log (contract_id, alert_type, alert_date, days_before, sent_to, sent_by, created_at) VALUES (:cid, :type, :date, :days, :sent_to, :by, NOW())")
                                    ->execute([':cid' => $contractId, ':type' => $alertType, ':date' => $dateVal, ':days' => $daysUntil, ':sent_to' => $sentToStr, ':by' => $username]);
                            } catch (\Throwable $e) { /* ignore log errors */ }
                        }
                    }
                } catch (MailException | \Throwable $e) {
                    $postError = 'Sending feilet: ' . h($e->getMessage());
                }
            }
        } elseif ($action === 'save_recipients' && $contractId > 0) {
            if (!$hasRecipients) {
                $postError = 'Tabellen contracts_notify_recipients finnes ikke ennå.';
            } else {
                try {
                    $subId     = (int)($_POST['substitute_user_id'] ?? 0);
                    $notifyIds = array_values(array_unique(array_filter(
                        array_map('intval', (array)($_POST['notify_user_ids'] ?? [])),
                        fn($x) => $x > 0
                    )));

                    $pdo->beginTransaction();

                    // Slett eksisterende
                    $pdo->prepare("DELETE FROM contracts_notify_recipients WHERE contract_id = :cid")
                        ->execute([':cid' => $contractId]);

                    $ins = $pdo->prepare("
                        INSERT INTO contracts_notify_recipients
                            (contract_id, user_id, recipient_role, is_active, created_at)
                        VALUES
                            (:cid, :uid, :role, 1, NOW())
                    ");

                    if ($subId > 0) {
                        $ins->execute([':cid' => $contractId, ':uid' => $subId, ':role' => 'substitute']);
                    }
                    foreach ($notifyIds as $uid) {
                        if ($uid === $subId) continue;
                        $ins->execute([':cid' => $contractId, ':uid' => (int)$uid, ':role' => 'notify']);
                    }

                    $pdo->commit();
                    $postOk = 'Mottakere lagret.';
                    $_SESSION['csrf_alerts'] = bin2hex(random_bytes(24));
                    $csrf = (string)$_SESSION['csrf_alerts'];
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $postError = 'Kunne ikke lagre: ' . h($e->getMessage());
                }
            }
        }
    }
}

// ------------------------------------------------------------------
// Load data
// ------------------------------------------------------------------
$horizonDays = max(7, min(365, (int)($_GET['days'] ?? 90)));
$filterType  = trim((string)($_GET['type'] ?? ''));   // '' | 'end' | 'renewal' | 'kpi'
$filterQ     = trim((string)($_GET['q'] ?? ''));

// User fullname map
$userByUsername = []; // username (lc) => ['display_name', 'email', 'id']
$usersAll       = []; // for picklist: [{id, display_name, username}]
if ($hasUsers) {
    try {
        $st = $pdo->query("SELECT id, username, COALESCE(NULLIF(display_name,''), username) AS display_name, email FROM users WHERE is_active = 1 ORDER BY display_name");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $r) {
            $un = trim((string)($r['username'] ?? ''));
            if ($un === '') continue;
            $userByUsername[mb_strtolower($un)] = [
                'display_name' => (string)($r['display_name'] ?? $un),
                'email'        => (string)($r['email'] ?? ''),
                'id'           => (int)($r['id'] ?? 0),
            ];
            $usersAll[] = [
                'id'           => (int)($r['id'] ?? 0),
                'username'     => $un,
                'display_name' => (string)($r['display_name'] ?? $un),
            ];
        }
    } catch (\Throwable $e) { /* ignore */ }
}

// Notify settings map: contract_id => {enabled, days_before_end, days_before_renewal, days_before_kpi}
$notifySettingsMap = [];
if ($hasNotifySet) {
    try {
        $st = $pdo->query("SELECT contract_id, enabled, days_before_end, days_before_renewal, days_before_kpi FROM contracts_notify_settings");
        foreach ($st ? $st->fetchAll(PDO::FETCH_ASSOC) : [] as $r) {
            $notifySettingsMap[(int)$r['contract_id']] = $r;
        }
    } catch (\Throwable $e) { /* ignore */ }
}

// Recipients map: contract_id => {substitute: label, notify: [labels]}
$recipientsMap = []; // contract_id => ['substitute' => [...], 'notify' => [...]]
$recipientSubIdMap = []; // contract_id => user_id (for form)
$recipientNotifyIdsMap = []; // contract_id => [user_id, ...]
if ($hasRecipients) {
    try {
        $st = $pdo->query("
            SELECT r.contract_id, r.user_id, r.recipient_role,
                   COALESCE(NULLIF(u.display_name,''), u.username) AS display_name,
                   u.username, u.email
            FROM contracts_notify_recipients r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.is_active = 1
        ");
        foreach ($st ? $st->fetchAll(PDO::FETCH_ASSOC) : [] as $r) {
            $cid  = (int)$r['contract_id'];
            $role = (string)$r['recipient_role'];
            $dn   = (string)($r['display_name'] ?? '');
            $un   = (string)($r['username'] ?? '');
            $uid  = (int)$r['user_id'];
            $label = $dn !== '' ? $dn : ($un !== '' ? $un : 'Bruker #' . $uid);
            if ($un !== '' && $dn !== '' && $un !== $dn) $label .= " ($un)";

            if (!isset($recipientsMap[$cid])) {
                $recipientsMap[$cid] = ['substitute' => null, 'notify' => []];
            }

            if ($role === 'substitute') {
                $recipientsMap[$cid]['substitute'] = ['label' => $label, 'email' => (string)($r['email'] ?? '')];
                $recipientSubIdMap[$cid] = $uid;
            } elseif ($role === 'notify') {
                $recipientsMap[$cid]['notify'][] = ['label' => $label, 'email' => (string)($r['email'] ?? '')];
                $recipientNotifyIdsMap[$cid][] = $uid;
            }
        }
    } catch (\Throwable $e) { /* ignore */ }
}

// ---- Build "upcoming events" list ----
$events = []; // [{type, contract_id, title, owner_username, date, days_until}]

if ($hasContracts) {
    // Helper: run one query per event type and collect events
    $eventDefs = [
        'end'     => ['col' => 'end_date',        'label' => 'Utløper'],
        'renewal' => ['col' => 'renewal_date',     'label' => 'Fornyes'],
        'kpi'     => ['col' => 'kpi_adjust_date',  'label' => 'KPI/indeks'],
    ];

    foreach ($eventDefs as $etype => $def) {
        if ($filterType !== '' && $filterType !== $etype) continue;

        $col = $def['col'];

        // Check column exists
        try {
            $pdo->query("SELECT `$col` FROM contracts LIMIT 0");
        } catch (\Throwable $e) {
            continue;
        }

        $whereParts = [
            "`$col` IS NOT NULL",
            "`$col` >= CURDATE()",
            "`$col` <= DATE_ADD(CURDATE(), INTERVAL :days DAY)",
        ];
        $params = [':days' => $horizonDays];

        if ($filterQ !== '') {
            $whereParts[] = "(title LIKE :q OR counterparty LIKE :q2)";
            $params[':q']  = '%' . $filterQ . '%';
            $params[':q2'] = '%' . $filterQ . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

        try {
            $st = $pdo->prepare("
                SELECT id, title, counterparty, owner_username, `$col` AS event_date,
                       DATEDIFF(`$col`, CURDATE()) AS days_until
                FROM contracts
                $whereSql
                ORDER BY `$col` ASC
                LIMIT 200
            ");
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $events[] = [
                    'type'           => $etype,
                    'type_label'     => $def['label'],
                    'contract_id'    => (int)$r['id'],
                    'title'          => (string)$r['title'],
                    'counterparty'   => (string)($r['counterparty'] ?? ''),
                    'owner_username' => (string)($r['owner_username'] ?? ''),
                    'event_date'     => (string)$r['event_date'],
                    'days_until'     => (int)$r['days_until'],
                ];
            }
        } catch (\Throwable $e) { /* column might not exist */ }
    }
}

// Sort events by days_until ascending
usort($events, fn($a, $b) => $a['days_until'] <=> $b['days_until']);

// Dashboard counts
$count14  = count(array_filter($events, fn($e) => $e['days_until'] <= 14));
$count30  = count(array_filter($events, fn($e) => $e['days_until'] <= 30));
$count90  = count(array_filter($events, fn($e) => $e['days_until'] <= 90));
$countAll = count($events);

// Logged-in user's email (for test email pre-fill)
$currentUserEmail = '';
if ($hasUsers) {
    try {
        $stMe = $pdo->prepare("SELECT email FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
        $stMe->execute([':u' => $username]);
        $currentUserEmail = trim((string)($stMe->fetchColumn() ?: ''));
    } catch (\Throwable $e) { /* ignore */ }
}

// Alert log
$alertLog = [];
if ($hasAlertLog) {
    try {
        $st = $pdo->prepare("
            SELECT al.contract_id, al.alert_type, al.alert_date, al.days_before,
                   al.sent_to, al.created_at,
                   c.title AS contract_title
            FROM contracts_alert_log al
            LEFT JOIN contracts c ON c.id = al.contract_id
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT 50
        ");
        $st->execute();
        $alertLog = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { /* ignore */ }
}

// Helper: days_until color class
function daysClass(int $d): string {
    if ($d <= 7)  return 'text-bg-danger';
    if ($d <= 14) return 'text-bg-warning';
    if ($d <= 30) return 'text-bg-info text-dark';
    return 'text-bg-light text-dark';
}

// Helper: event type badge
function eventTypeBadge(string $type): string {
    $map = [
        'end'     => 'text-bg-danger',
        'renewal' => 'text-bg-warning',
        'kpi'     => 'text-bg-info text-dark',
    ];
    $labels = ['end' => 'Utløper', 'renewal' => 'Fornyes', 'kpi' => 'KPI/indeks'];
    $cls = $map[$type] ?? 'text-bg-secondary';
    $lbl = h($labels[$type] ?? $type);
    return "<span class=\"badge $cls\">$lbl</span>";
}
?>

<style>
.ca-section-title { font-weight: 600; display: flex; align-items: center; gap: .5rem; }
.ca-small-muted   { font-size: .8rem; color: #6c757d; }
.days-badge       { font-size: .75rem; font-weight: 700; padding: .25em .55em; border-radius: .5rem; }
.recipient-pill   { font-size: .75rem; }
</style>

<!-- Page header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h5 mb-1"><i class="bi bi-bell me-1"></i> Varsler &amp; fornyelser</h1>
        <div class="text-muted small">Oversikt over avtaler som snart utløper, fornyes eller skal KPI-justeres – og hvem som varsles.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/?page=contracts" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Til oversikt
        </a>
    </div>
</div>

<?php if ($postOk): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-1"></i> <?= h($postOk) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($postError): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle me-1"></i> <?= h($postError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Test email card -->
<?php if ($canWrite): ?>
<div class="card shadow-sm mb-3 border-0 bg-light">
    <div class="card-body py-2">
        <form class="d-flex flex-wrap align-items-center gap-2" method="post" action="/?page=contracts_alerts">
            <input type="hidden" name="csrf"       value="<?= h($csrf) ?>">
            <input type="hidden" name="action"     value="send_test_email">
            <input type="hidden" name="test_email" value="<?= h($currentUserEmail) ?>">
            <i class="bi bi-envelope-check text-muted"></i>
            <span class="small text-muted fw-semibold">Test SMTP-tilkobling:</span>
            <?php if ($currentUserEmail !== ''): ?>
                <span class="small">
                    Sender til <strong><?= h($currentUserEmail) ?></strong>
                    <a href="/?page=minside" class="ms-1 text-muted" title="Endre e-post på Min side">
                        <i class="bi bi-pencil"></i>
                    </a>
                </span>
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-send me-1"></i> Send testepost til meg
                </button>
            <?php else: ?>
                <span class="text-warning small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Ingen e-post registrert på din bruker.
                    <a href="/?page=minside">Legg til e-post på Min side</a>
                </span>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$hasContracts): ?>
    <div class="alert alert-warning">
        Tabellen <code>contracts</code> finnes ikke ennå.
    </div>
    <?php return; ?>
<?php endif; ?>

<!-- Missing tables info -->
<?php if (!$hasNotifySet || !$hasRecipients): ?>
    <div class="alert alert-info">
        <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i> Valgfrie tabeller mangler</div>
        <div class="small">
            Varslingsmodulen fungerer uten disse tabellene, men for å lagre innstillinger og mottakere trenger du:
        </div>
        <?php if (!$hasNotifySet): ?>
            <div class="mt-2">
                <strong>contracts_notify_settings</strong> (varslingsinnstillinger per avtale):
                <pre class="mb-0 mt-1 small"><code>CREATE TABLE IF NOT EXISTS contracts_notify_settings (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contract_id   BIGINT UNSIGNED NOT NULL,
  enabled       TINYINT(1) NOT NULL DEFAULT 1,
  days_before_end      INT NOT NULL DEFAULT 30,
  days_before_renewal  INT NOT NULL DEFAULT 30,
  days_before_kpi      INT NOT NULL DEFAULT 30,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cns_contract (contract_id),
  CONSTRAINT fk_cns_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</code></pre>
            </div>
        <?php endif; ?>
        <?php if (!$hasRecipients): ?>
            <div class="mt-2">
                <strong>contracts_notify_recipients</strong> (mottakere per avtale):
                <pre class="mb-0 mt-1 small"><code>CREATE TABLE IF NOT EXISTS contracts_notify_recipients (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contract_id   BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  recipient_role ENUM('substitute','notify') NOT NULL DEFAULT 'notify',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cnr_cu (contract_id, user_id),
  KEY idx_cnr_contract (contract_id),
  KEY idx_cnr_user (user_id),
  CONSTRAINT fk_cnr_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  CONSTRAINT fk_cnr_user    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</code></pre>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Dashboard stats -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100 border-0 <?= $count14 > 0 ? 'border-danger border' : '' ?>">
            <div class="card-body">
                <div class="text-muted small">Innen 14 dager</div>
                <div class="h4 mb-0 <?= $count14 > 0 ? 'text-danger' : '' ?>"><?= $count14 ?></div>
                <div class="small text-muted mt-1">hendelser</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100 border-0 <?= $count30 > 0 ? 'border-warning border' : '' ?>">
            <div class="card-body">
                <div class="text-muted small">Innen 30 dager</div>
                <div class="h4 mb-0 <?= $count30 > 0 ? 'text-warning' : '' ?>"><?= $count30 ?></div>
                <div class="small text-muted mt-1">hendelser</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Innen 90 dager</div>
                <div class="h4 mb-0"><?= $count90 ?></div>
                <div class="small text-muted mt-1">hendelser</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Innen <?= (int)$horizonDays ?> dager</div>
                <div class="h4 mb-0"><?= $countAll ?></div>
                <div class="small text-muted mt-1">hendelser totalt</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter bar -->
<section class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="d-flex flex-wrap align-items-center gap-2" method="get" action="/">
            <input type="hidden" name="page" value="contracts_alerts">

            <div class="input-group input-group-sm" style="min-width:200px;max-width:320px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" name="q"
                       value="<?= h($filterQ) ?>" placeholder="Søk avtale/motpart">
            </div>

            <select name="type" class="form-select form-select-sm" style="width:160px;">
                <option value="" <?= $filterType === '' ? 'selected' : '' ?>>Alle typer</option>
                <option value="end"     <?= $filterType === 'end'     ? 'selected' : '' ?>>Utløper</option>
                <option value="renewal" <?= $filterType === 'renewal' ? 'selected' : '' ?>>Fornyes</option>
                <option value="kpi"     <?= $filterType === 'kpi'     ? 'selected' : '' ?>>KPI/indeks</option>
            </select>

            <select name="days" class="form-select form-select-sm" style="width:150px;">
                <?php foreach ([14, 30, 60, 90, 180, 365] as $d): ?>
                    <option value="<?= $d ?>" <?= $horizonDays === $d ? 'selected' : '' ?>><?= $d ?> dager</option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn-sm btn-primary" type="submit">
                <i class="bi bi-funnel me-1"></i> Filtrer
            </button>
            <a class="btn btn-sm btn-outline-secondary" href="/?page=contracts_alerts">Nullstill</a>
        </form>
    </div>
</section>

<!-- Events table -->
<section class="card shadow-sm mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div class="fw-semibold"><i class="bi bi-calendar-event me-1"></i> Kommende hendelser</div>
        <div class="small text-muted"><?= $countAll ?> totalt innen <?= (int)$horizonDays ?> dager</div>
    </div>

    <?php if (empty($events)): ?>
        <div class="card-body text-muted">
            <i class="bi bi-check-circle me-1 text-success"></i>
            Ingen avtaler med hendelser innen <?= (int)$horizonDays ?> dager<?= $filterQ !== '' || $filterType !== '' ? ' (filter aktiv)' : '' ?>.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th style="min-width:260px;">Avtale</th>
                    <th style="min-width:110px;">Type</th>
                    <th style="min-width:110px;">Dato</th>
                    <th style="min-width:80px;" class="text-end">Dager</th>
                    <th style="min-width:180px;">Ansvarlig</th>
                    <th style="min-width:180px;">Mottakere</th>
                    <th style="min-width:200px;">Varslingsinnstillinger</th>
                    <?php if ($canWrite): ?>
                        <th style="min-width:90px;" class="text-end">Handling</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($events as $ev):
                    $cid          = $ev['contract_id'];
                    $ownerUn      = $ev['owner_username'];
                    $ownerKey     = mb_strtolower($ownerUn);
                    $ownerInfo    = $userByUsername[$ownerKey] ?? null;
                    $ownerDisplay = $ownerInfo ? $ownerInfo['display_name'] : ($ownerUn ?: '–');
                    $ownerEmail   = $ownerInfo ? $ownerInfo['email'] : '';

                    $ns          = $notifySettingsMap[$cid] ?? null;
                    $nsEnabled   = $ns ? ((int)$ns['enabled'] === 1) : true;
                    $nsEnd       = $ns ? (int)$ns['days_before_end']     : 30;
                    $nsRenewal   = $ns ? (int)$ns['days_before_renewal'] : 30;
                    $nsKpi       = $ns ? (int)$ns['days_before_kpi']     : 30;

                    $recips      = $recipientsMap[$cid] ?? null;
                    $subInfo     = $recips['substitute'] ?? null;
                    $notifyList  = $recips['notify'] ?? [];

                    $subId       = $recipientSubIdMap[$cid] ?? 0;
                    $notifyIds   = $recipientNotifyIdsMap[$cid] ?? [];
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold">
                            <a href="/?page=contracts_view&id=<?= $cid ?>" class="text-decoration-none">
                                <?= h($ev['title']) ?>
                            </a>
                        </div>
                        <?php if ($ev['counterparty'] !== ''): ?>
                            <div class="ca-small-muted"><?= h($ev['counterparty']) ?></div>
                        <?php endif; ?>
                    </td>

                    <td><?= eventTypeBadge($ev['type']) ?></td>

                    <td class="small"><?= h($ev['event_date']) ?></td>

                    <td class="text-end">
                        <span class="days-badge badge <?= daysClass($ev['days_until']) ?>">
                            <?= $ev['days_until'] === 0 ? 'I dag' : $ev['days_until'] . 'd' ?>
                        </span>
                    </td>

                    <td>
                        <div class="small">
                            <?= h($ownerDisplay) ?>
                            <?php if ($ownerEmail !== ''): ?>
                                <a href="mailto:<?= h($ownerEmail) ?>" class="ms-1 text-muted" title="<?= h($ownerEmail) ?>">
                                    <i class="bi bi-envelope"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php if ($subInfo): ?>
                            <div class="ca-small-muted">
                                <i class="bi bi-person-gear me-1"></i><?= h($subInfo['label']) ?>
                                <?php if ($subInfo['email'] !== ''): ?>
                                    <a href="mailto:<?= h($subInfo['email']) ?>" class="ms-1 text-muted" title="<?= h($subInfo['email']) ?>">
                                        <i class="bi bi-envelope"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if (!empty($notifyList)): ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($notifyList as $nr): ?>
                                    <span class="badge text-bg-light border recipient-pill" title="<?= h($nr['email']) ?>">
                                        <i class="bi bi-bell me-1"></i><?= h($nr['label']) ?>
                                        <?php if ($nr['email'] !== ''): ?>
                                            <a href="mailto:<?= h($nr['email']) ?>" class="ms-1 text-muted">
                                                <i class="bi bi-envelope"></i>
                                            </a>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted small">Kun ansvarlig</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if ($ns): ?>
                            <div class="d-flex flex-wrap gap-1 align-items-center">
                                <?php if ($nsEnabled): ?>
                                    <span class="badge text-bg-success">På</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Av</span>
                                <?php endif; ?>
                                <span class="ca-small-muted">
                                    Utløp: <?= $nsEnd ?>d · Forny: <?= $nsRenewal ?>d · KPI: <?= $nsKpi ?>d
                                </span>
                            </div>
                        <?php else: ?>
                            <span class="text-muted small">Standard (30d)</span>
                        <?php endif; ?>
                    </td>

                    <?php if ($canWrite): ?>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalSettings"
                                        data-contract-id="<?= $cid ?>"
                                        data-contract-title="<?= h($ev['title']) ?>"
                                        data-ns-enabled="<?= $nsEnabled ? '1' : '0' ?>"
                                        data-ns-end="<?= $nsEnd ?>"
                                        data-ns-renewal="<?= $nsRenewal ?>"
                                        data-ns-kpi="<?= $nsKpi ?>"
                                        data-sub-id="<?= (int)$subId ?>"
                                        data-notify-ids="<?= h(implode(',', $notifyIds)) ?>"
                                        title="Innstillinger">
                                    <i class="bi bi-gear"></i>
                                </button>
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary btn-send-now"
                                        data-contract-id="<?= $cid ?>"
                                        data-contract-title="<?= h($ev['title']) ?>"
                                        data-alert-type="<?= h($ev['type']) ?>"
                                        data-csrf="<?= h($csrf) ?>"
                                        title="Send varsel nå">
                                    <i class="bi bi-send"></i>
                                </button>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Alert log -->
<?php if ($hasAlertLog): ?>
<section class="card shadow-sm mb-3">
    <div class="card-header">
        <div class="fw-semibold"><i class="bi bi-clock-history me-1"></i> Varsel-logg (siste 50)</div>
    </div>
    <?php if (empty($alertLog)): ?>
        <div class="card-body text-muted small">Ingen varsler logget ennå.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>Avtale</th>
                    <th>Type</th>
                    <th>Dato</th>
                    <th>Sendt til</th>
                    <th>Tidspunkt</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($alertLog as $al): ?>
                    <tr>
                        <td>
                            <?php if ($al['contract_id']): ?>
                                <a href="/?page=contracts_view&id=<?= (int)$al['contract_id'] ?>">
                                    <?= h($al['contract_title'] ?? 'Avtale #' . $al['contract_id']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td><?= eventTypeBadge((string)($al['alert_type'] ?? '')) ?></td>
                        <td class="small"><?= h($al['alert_date'] ?? '') ?></td>
                        <td class="small"><?= h($al['sent_to'] ?? '–') ?></td>
                        <td class="small text-muted"><?= h($al['created_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($canWrite): ?>
<!-- ================================================================
     MODAL: Varslingskonfigurasjon
================================================================ -->
<div class="modal fade" id="modalSettings" tabindex="-1" aria-labelledby="modalSettingsLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalSettingsLabel">
                    <i class="bi bi-bell me-1"></i>
                    Varslingskonfigurasjon – <span id="modalContractTitle"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Lukk"></button>
            </div>
            <div class="modal-body">

                <!-- Tab nav -->
                <ul class="nav nav-tabs mb-3" id="settingsTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-settings" data-bs-toggle="tab"
                                data-bs-target="#tabSettings" type="button" role="tab">
                            <i class="bi bi-sliders me-1"></i> Innstillinger
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-recip" data-bs-toggle="tab"
                                data-bs-target="#tabRecip" type="button" role="tab">
                            <i class="bi bi-people me-1"></i> Mottakere
                        </button>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- Tab 1: Innstillinger -->
                    <div class="tab-pane fade show active" id="tabSettings" role="tabpanel">
                        <form method="post" action="/?page=contracts_alerts" id="formSettings">
                            <input type="hidden" name="csrf"        value="<?= h($csrf) ?>">
                            <input type="hidden" name="action"      value="save_notify_settings">
                            <input type="hidden" name="contract_id" id="settings_contract_id">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Varsling</label>
                                <select class="form-select" name="enabled" id="settings_enabled">
                                    <option value="1">Aktivert – send varsler</option>
                                    <option value="0">Deaktivert – ikke send varsler</option>
                                </select>
                            </div>

                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Dager før utløp</label>
                                    <input type="number" class="form-control" name="days_before_end"
                                           id="settings_days_end" min="0" max="365" value="30">
                                    <div class="form-text">Varsle X dager før sluttdato</div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Dager før fornyelse</label>
                                    <input type="number" class="form-control" name="days_before_renewal"
                                           id="settings_days_renewal" min="0" max="365" value="30">
                                    <div class="form-text">Varsle X dager før fornyelsesdato</div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Dager før KPI</label>
                                    <input type="number" class="form-control" name="days_before_kpi"
                                           id="settings_days_kpi" min="0" max="365" value="30">
                                    <div class="form-text">Varsle X dager før KPI-dato</div>
                                </div>
                            </div>

                            <div class="mt-3 d-flex gap-2 justify-content-end">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Avbryt</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i> Lagre innstillinger
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Tab 2: Mottakere -->
                    <div class="tab-pane fade" id="tabRecip" role="tabpanel">
                        <form method="post" action="/?page=contracts_alerts" id="formRecip">
                            <input type="hidden" name="csrf"        value="<?= h($csrf) ?>">
                            <input type="hidden" name="action"      value="save_recipients">
                            <input type="hidden" name="contract_id" id="recip_contract_id">

                            <div class="row g-3 mb-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Stedfortreder</label>
                                    <select class="form-select" name="substitute_user_id" id="recip_sub">
                                        <option value="0">– Ingen –</option>
                                        <?php foreach ($usersAll as $u): ?>
                                            <option value="<?= (int)$u['id'] ?>">
                                                <?= h($u['display_name']) ?> (<?= h($u['username']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Brukes ved fravær. Varsles i stedet for / i tillegg til ansvarlig.</div>
                                </div>
                            </div>

                            <label class="form-label fw-semibold">Varsle også</label>
                            <div class="row g-2 mb-3">
                                <!-- Available -->
                                <div class="col-12 col-md-6">
                                    <div class="border rounded">
                                        <div class="px-2 py-1 border-bottom bg-light d-flex justify-content-between align-items-center">
                                            <span class="small fw-semibold">Tilgjengelige</span>
                                            <input type="text" class="form-control form-control-sm" id="searchAvail"
                                                   placeholder="Søk…" style="max-width:160px;" autocomplete="off">
                                        </div>
                                        <ul id="availList" class="list-unstyled m-0 p-2"
                                            style="min-height:160px;max-height:260px;overflow-y:auto;">
                                            <?php foreach ($usersAll as $u): ?>
                                                <li class="d-flex align-items-center justify-content-between py-1 px-2 mb-1 border rounded small avail-item"
                                                    data-user-id="<?= (int)$u['id'] ?>"
                                                    data-label="<?= h($u['display_name'] . ' ' . $u['username']) ?>">
                                                    <span><?= h($u['display_name']) ?> <span class="text-muted">(<?= h($u['username']) ?>)</span></span>
                                                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-1 btn-add-recip" title="Legg til">
                                                        <i class="bi bi-plus"></i>
                                                    </button>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>

                                <!-- Selected -->
                                <div class="col-12 col-md-6">
                                    <div class="border rounded">
                                        <div class="px-2 py-1 border-bottom bg-light d-flex justify-content-between align-items-center">
                                            <span class="small fw-semibold"><i class="bi bi-bell me-1"></i> Varsles</span>
                                            <span class="badge text-bg-secondary" id="selectedCount">0</span>
                                        </div>
                                        <ul id="selectedList" class="list-unstyled m-0 p-2"
                                            style="min-height:160px;max-height:260px;overflow-y:auto;">
                                            <li class="text-muted small p-2" id="noSelectedPlaceholder">Ingen ekstra mottakere valgt.</li>
                                        </ul>
                                    </div>
                                    <div id="notifyHiddenFields"></div>
                                </div>
                            </div>

                            <div class="small text-muted mb-3">
                                <i class="bi bi-info-circle me-1"></i>
                                Ansvarlig varsles alltid. Stedfortreder og "Varsle også"-brukere varsles i tillegg.
                            </div>

                            <div class="d-flex gap-2 justify-content-end">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Avbryt</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i> Lagre mottakere
                                </button>
                            </div>
                        </form>
                    </div><!-- /tabRecip -->
                </div><!-- /tab-content -->
            </div><!-- /modal-body -->
        </div>
    </div>
</div><!-- /modal -->
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // -------------------------------------------------------
    // "Send varsel nå" buttons
    // -------------------------------------------------------
    document.querySelectorAll('.btn-send-now').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var cid   = btn.getAttribute('data-contract-id');
            var title = btn.getAttribute('data-contract-title');
            var type  = btn.getAttribute('data-alert-type');
            var csrf  = btn.getAttribute('data-csrf');

            var typeLabels = {end: 'Utløper', renewal: 'Fornyes', kpi: 'KPI/indeks'};
            var typeLabel  = typeLabels[type] || type;

            if (!confirm('Send varsel «' + typeLabel + '» for avtalen «' + title + '» til alle mottakere nå?')) return;

            var form = document.createElement('form');
            form.method = 'post';
            form.action = '/?page=contracts_alerts';

            function addInput(name, value) {
                var inp = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = name;
                inp.value = value;
                form.appendChild(inp);
            }

            addInput('csrf',        csrf);
            addInput('action',      'send_alert_now');
            addInput('contract_id', cid);
            addInput('alert_type',  type);

            document.body.appendChild(form);
            form.submit();
        });
    });

    var modal = document.getElementById('modalSettings');
    if (!modal) return;

    // -------------------------------------------------------
    // Populate modal when opened via trigger button
    // -------------------------------------------------------
    modal.addEventListener('show.bs.modal', function (ev) {
        var btn = ev.relatedTarget;
        if (!btn) return;

        var cid   = btn.getAttribute('data-contract-id') || '';
        var title = btn.getAttribute('data-contract-title') || '';

        // Header title
        var titleEl = document.getElementById('modalContractTitle');
        if (titleEl) titleEl.textContent = title;

        // Settings tab
        document.getElementById('settings_contract_id').value = cid;
        document.getElementById('settings_enabled').value    = btn.getAttribute('data-ns-enabled') || '1';
        document.getElementById('settings_days_end').value    = btn.getAttribute('data-ns-end') || '30';
        document.getElementById('settings_days_renewal').value = btn.getAttribute('data-ns-renewal') || '30';
        document.getElementById('settings_days_kpi').value    = btn.getAttribute('data-ns-kpi') || '30';

        // Recipients tab
        document.getElementById('recip_contract_id').value = cid;

        var subId     = parseInt(btn.getAttribute('data-sub-id') || '0', 10);
        var notifyRaw = (btn.getAttribute('data-notify-ids') || '').trim();
        var notifyIds = notifyRaw !== '' ? notifyRaw.split(',').map(Number).filter(Boolean) : [];

        var subSel = document.getElementById('recip_sub');
        if (subSel) {
            subSel.value = subId > 0 ? String(subId) : '0';
        }

        // Reset selected list
        resetSelectedList(notifyIds, subId);
    });

    // -------------------------------------------------------
    // Recipients picklist logic
    // -------------------------------------------------------
    function resetSelectedList(selectedIds, excludeSubId) {
        var selList = document.getElementById('selectedList');
        var hidFields = document.getElementById('notifyHiddenFields');
        var placeholder = document.getElementById('noSelectedPlaceholder');

        // Remove previously added items
        selList.querySelectorAll('.sel-recip-item').forEach(function (el) { el.remove(); });
        hidFields.innerHTML = '';

        // Show/hide available items
        document.querySelectorAll('#availList .avail-item').forEach(function (li) {
            var uid = parseInt(li.getAttribute('data-user-id') || '0', 10);
            if (selectedIds.indexOf(uid) !== -1) {
                li.style.display = 'none';
                addToSelected(uid, li.getAttribute('data-label') || ('Bruker #' + uid), selList, hidFields);
            } else {
                li.style.display = '';
            }
        });

        updatePlaceholder(selList, placeholder, hidFields);
        updateCount();
    }

    function addToSelected(uid, label, selList, hidFields) {
        var placeholder = document.getElementById('noSelectedPlaceholder');

        // Create list item in selectedList
        var li = document.createElement('li');
        li.className = 'd-flex align-items-center justify-content-between py-1 px-2 mb-1 border rounded small sel-recip-item';
        li.setAttribute('data-user-id', uid);
        li.innerHTML = '<span>' + escHtml(label.split('(')[0].trim()) + '</span>' +
            '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 btn-remove-recip" title="Fjern"><i class="bi bi-x"></i></button>';
        selList.appendChild(li);

        // Remove-button handler
        li.querySelector('.btn-remove-recip').addEventListener('click', function () {
            li.remove();
            // Restore in available list
            var avail = document.querySelector('#availList .avail-item[data-user-id="' + uid + '"]');
            if (avail) avail.style.display = '';

            // Remove hidden input
            var hidInp = hidFields.querySelector('input[value="' + uid + '"]');
            if (hidInp) hidInp.remove();

            updatePlaceholder(selList, placeholder, hidFields);
            updateCount();
        });

        // Add hidden input
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'notify_user_ids[]';
        inp.value = uid;
        hidFields.appendChild(inp);

        if (placeholder) placeholder.style.display = 'none';
        updateCount();
    }

    function updatePlaceholder(selList, placeholder, hidFields) {
        if (!placeholder) return;
        var hasItems = selList.querySelectorAll('.sel-recip-item').length > 0;
        placeholder.style.display = hasItems ? 'none' : '';
    }

    function updateCount() {
        var countEl = document.getElementById('selectedCount');
        if (!countEl) return;
        var n = document.querySelectorAll('#selectedList .sel-recip-item').length;
        countEl.textContent = n;
    }

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Wire "add" buttons in available list
    document.querySelectorAll('#availList .btn-add-recip').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var li  = btn.closest('.avail-item');
            var uid = parseInt(li.getAttribute('data-user-id') || '0', 10);
            var lbl = li.getAttribute('data-label') || 'Bruker #' + uid;
            li.style.display = 'none';
            addToSelected(uid, lbl,
                document.getElementById('selectedList'),
                document.getElementById('notifyHiddenFields'));
        });
    });

    // -------------------------------------------------------
    // Search in available list
    // -------------------------------------------------------
    var searchAvail = document.getElementById('searchAvail');
    if (searchAvail) {
        searchAvail.addEventListener('input', function () {
            var q = (searchAvail.value || '').toLowerCase().trim();
            document.querySelectorAll('#availList .avail-item').forEach(function (li) {
                if (li.style.display === 'none' && li.getAttribute('data-selected') !== '1') return; // already hidden (selected)
                if (li.style.display === 'none') return;
                var txt = (li.getAttribute('data-label') || '').toLowerCase();
                li.style.display = (q === '' || txt.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    // -------------------------------------------------------
    // When substitute changes, remove from notify list if present
    // -------------------------------------------------------
    var subSel = document.getElementById('recip_sub');
    if (subSel) {
        subSel.addEventListener('change', function () {
            var uid = parseInt(subSel.value || '0', 10);
            if (uid > 0) {
                var selItem = document.querySelector('#selectedList .sel-recip-item[data-user-id="' + uid + '"]');
                if (selItem) {
                    var removeBtn = selItem.querySelector('.btn-remove-recip');
                    if (removeBtn) removeBtn.click();
                }
            }
        });
    }
});
</script>
