<?php
/**
 * scripts/contracts_send_alerts.php
 *
 * Kontraktvarsler – daglig cron-script
 * =====================================
 * Kjøres typisk én gang daglig, f.eks.:
 *   php C:\inetpub\wwwroot\teknisk.hkbb.no\scripts\contracts_send_alerts.php
 *
 * Windows Task Scheduler:
 *   Program:  C:\php\php.exe
 *   Args:     C:\inetpub\wwwroot\teknisk.hkbb.no\scripts\contracts_send_alerts.php
 *   Kjøres:   Daglig kl. 07:00
 *
 * Hva scriptet gjør:
 *  1. Henter alle aktive avtaler med end_date, renewal_date eller kpi_adjust_date
 *  2. Sjekker contracts_notify_settings for terskler (dager_før) per avtale
 *     → Fallback: globale standardverdier (DEFAULT_DAYS_*)
 *  3. Sjekker contracts_alert_log for å unngå dupliserte varsler
 *     → Sender IKKE samme type varsel for samme avtale mer enn én gang per 24 timer
 *  4. Henter mottakere: ansvarlig (owner) + contracts_notify_recipients
 *  5. Sender e-post via App\Mail\Mailer (Domeneshop SMTP)
 *  6. Logger til contracts_alert_log
 *
 * Logg skrives til scripts/contracts_send_alerts.log
 */

declare(strict_types=1);

// -----------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------
$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Mail\Mailer;
use PHPMailer\PHPMailer\Exception as MailException;

$dotenv = Dotenv::createImmutable($root);
$dotenv->load();

// -----------------------------------------------------------------
// Logging helper
// -----------------------------------------------------------------
$logFile = __DIR__ . '/contracts_send_alerts.log';

function logLine(string $level, string $msg): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

logLine('info', '=== contracts_send_alerts startet ===');

// -----------------------------------------------------------------
// DB
// -----------------------------------------------------------------
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    logLine('error', 'DB-tilkobling feilet: ' . $e->getMessage());
    exit(1);
}

// -----------------------------------------------------------------
// Defaults (brukes dersom contracts_notify_settings ikke finnes
//           eller avtalen ikke har egne innstillinger)
// -----------------------------------------------------------------
const DEFAULT_DAYS_END     = 30;
const DEFAULT_DAYS_RENEWAL = 30;
const DEFAULT_DAYS_KPI     = 30;

// -----------------------------------------------------------------
// Schema checks
// -----------------------------------------------------------------
function tbl_exists(PDO $pdo, string $t): bool
{
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }
    catch (\Throwable $e) { return false; }
}

function col_exists(PDO $pdo, string $t, string $c): bool
{
    try {
        $st = $pdo->query("DESCRIBE `$t`");
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ((string)($r['Field'] ?? '') === $c) return true;
        }
        return false;
    } catch (\Throwable $e) { return false; }
}

$hasContracts  = tbl_exists($pdo, 'contracts');
$hasUsers      = tbl_exists($pdo, 'users');
$hasNotifySet  = tbl_exists($pdo, 'contracts_notify_settings');
$hasRecipients = tbl_exists($pdo, 'contracts_notify_recipients');
$hasAlertLog   = tbl_exists($pdo, 'contracts_alert_log');

if (!$hasContracts) {
    logLine('error', 'Tabellen contracts finnes ikke. Avbryter.');
    exit(1);
}
if (!$hasAlertLog) {
    logLine('warning', 'Tabellen contracts_alert_log finnes ikke – varsler sendes men logges ikke. Se SQL nedenfor.');
    echo <<<SQL

-- Kjør dette for å opprette logg-tabellen:
CREATE TABLE IF NOT EXISTS contracts_alert_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contract_id   BIGINT UNSIGNED NOT NULL,
  alert_type    ENUM('end','renewal','kpi') NOT NULL,
  alert_date    DATE NOT NULL,
  days_before   INT NOT NULL DEFAULT 0,
  sent_to       TEXT,
  sent_by       VARCHAR(100) DEFAULT 'cron',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_al_contract (contract_id),
  KEY idx_al_type_date (contract_id, alert_type, alert_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SQL;
}

// -----------------------------------------------------------------
// Load notify settings (all at once for performance)
// -----------------------------------------------------------------
$settingsMap = []; // contract_id => [enabled, days_before_end, days_before_renewal, days_before_kpi]
if ($hasNotifySet) {
    try {
        $st = $pdo->query("SELECT contract_id, enabled, days_before_end, days_before_renewal, days_before_kpi FROM contracts_notify_settings");
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $settingsMap[(int)$r['contract_id']] = $r;
        }
    } catch (\Throwable $e) {
        logLine('warning', 'Kunne ikke lese contracts_notify_settings: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------
// Load recipients (all at once)
// -----------------------------------------------------------------
// recipientsMap[contract_id] = ['substitute' => [email => name], 'notify' => [email => name]]
$recipientsMap = [];
if ($hasRecipients && $hasUsers) {
    try {
        $st = $pdo->query("
            SELECT r.contract_id, r.recipient_role,
                   u.email, COALESCE(NULLIF(u.display_name,''), u.username) AS display_name
            FROM contracts_notify_recipients r
            JOIN users u ON u.id = r.user_id
            WHERE r.is_active = 1
              AND u.is_active = 1
              AND u.email IS NOT NULL
              AND u.email <> ''
        ");
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $cid   = (int)$r['contract_id'];
            $role  = (string)$r['recipient_role'];
            $email = trim((string)$r['email']);
            $name  = trim((string)$r['display_name']);
            if ($email === '') continue;

            if (!isset($recipientsMap[$cid])) {
                $recipientsMap[$cid] = ['substitute' => [], 'notify' => []];
            }
            $recipientsMap[$cid][$role][$email] = $name;
        }
    } catch (\Throwable $e) {
        logLine('warning', 'Kunne ikke lese recipients: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------
// Already-sent log (today) – avoids duplicate sends
// -----------------------------------------------------------------
$sentToday = []; // "contract_id:alert_type" => true
if ($hasAlertLog) {
    try {
        $st = $pdo->prepare("
            SELECT contract_id, alert_type
            FROM contracts_alert_log
            WHERE DATE(created_at) = CURDATE()
        ");
        $st->execute();
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $key = (int)$r['contract_id'] . ':' . (string)$r['alert_type'];
            $sentToday[$key] = true;
        }
    } catch (\Throwable $e) {
        logLine('warning', 'Kunne ikke sjekke varsel-logg: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------
// Fetch owner email map  username (lc) => [email, display_name]
// -----------------------------------------------------------------
$ownerMap = []; // owner_username (lc) => [email, display_name]
if ($hasUsers) {
    try {
        $st = $pdo->query("
            SELECT username, email, COALESCE(NULLIF(display_name,''), username) AS display_name
            FROM users WHERE is_active = 1
        ");
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $un = mb_strtolower(trim((string)$r['username']));
            if ($un === '') continue;
            $ownerMap[$un] = [
                'email'        => trim((string)$r['email']),
                'display_name' => trim((string)$r['display_name']),
            ];
        }
    } catch (\Throwable $e) {
        logLine('warning', 'Kunne ikke lese users: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------
// Build the list of contracts to check
// -----------------------------------------------------------------
$eventDefs = [
    'end'     => ['col' => 'end_date',       'label' => 'Utløper'],
    'renewal' => ['col' => 'renewal_date',   'label' => 'Fornyes'],
    'kpi'     => ['col' => 'kpi_adjust_date','label' => 'KPI/indeks'],
];

// Maximum look-ahead so we fetch everything potentially relevant
$maxDays = 365;

// Collect [contract_id => [end_date|renewal_date|kpi_adjust_date, title, owner_username]]
$candidates = []; // contract_id => row

$cols = [];
foreach ($eventDefs as $type => $def) {
    if (col_exists($pdo, 'contracts', $def['col'])) {
        $cols[$type] = $def['col'];
    }
}

if (!empty($cols)) {
    $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
    try {
        $st = $pdo->prepare("
            SELECT id, title, owner_username, $colList
            FROM contracts
            WHERE is_active = 1
            ORDER BY id
            LIMIT 2000
        ");
        $st->execute();
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $candidates[(int)$r['id']] = $r;
        }
    } catch (\Throwable $e) {
        logLine('error', 'Kunne ikke hente kontrakter: ' . $e->getMessage());
        exit(1);
    }
}

logLine('info', 'Totalt ' . count($candidates) . ' aktive kontrakter å sjekke.');

// -----------------------------------------------------------------
// Mailer
// -----------------------------------------------------------------
$mailer = new Mailer();

// -----------------------------------------------------------------
// Process
// -----------------------------------------------------------------
$totalSent   = 0;
$totalErrors = 0;

foreach ($candidates as $cid => $row) {
    $settings = $settingsMap[$cid] ?? null;

    // Skip if notifications disabled for this contract
    if ($settings !== null && (int)$settings['enabled'] === 0) {
        continue;
    }

    // Thresholds
    $thresholds = [
        'end'     => (int)($settings['days_before_end']     ?? DEFAULT_DAYS_END),
        'renewal' => (int)($settings['days_before_renewal'] ?? DEFAULT_DAYS_RENEWAL),
        'kpi'     => (int)($settings['days_before_kpi']     ?? DEFAULT_DAYS_KPI),
    ];

    foreach ($cols as $type => $col) {
        $dateVal = trim((string)($row[$col] ?? ''));
        if ($dateVal === '' || $dateVal === '0000-00-00') continue;

        $ts = strtotime($dateVal);
        if (!$ts) continue;

        $daysUntil = (int)round(($ts - strtotime('today')) / 86400);

        // Only notify if we're exactly at the threshold (or past it but not already sent)
        // Strategy: send when daysUntil is <= threshold AND > 0
        if ($daysUntil <= 0 || $daysUntil > $thresholds[$type]) continue;

        // De-duplicate: only one email per contract+type per day
        $dedupeKey = $cid . ':' . $type;
        if (isset($sentToday[$dedupeKey])) {
            logLine('info', "Hopper over (allerede sendt i dag): cid=$cid type=$type");
            continue;
        }

        // Build recipient list
        $to = []; // email => name

        // 1. Owner
        $ownerUn   = mb_strtolower(trim((string)($row['owner_username'] ?? '')));
        $ownerInfo = $ownerUn !== '' ? ($ownerMap[$ownerUn] ?? null) : null;
        if ($ownerInfo && $ownerInfo['email'] !== '') {
            $to[$ownerInfo['email']] = $ownerInfo['display_name'];
        }

        // 2. Substitute
        if (isset($recipientsMap[$cid]['substitute'])) {
            foreach ($recipientsMap[$cid]['substitute'] as $email => $name) {
                $to[$email] = $name;
            }
        }

        // 3. Notify list
        if (isset($recipientsMap[$cid]['notify'])) {
            foreach ($recipientsMap[$cid]['notify'] as $email => $name) {
                $to[$email] = $name;
            }
        }

        if (empty($to)) {
            logLine('warning', "Ingen e-postadresser for cid=$cid type=$type (mangler e-post på ansvarlig og mottakere). Hopper over.");
            continue;
        }

        // Build email
        $typeLabels = ['end' => 'Utløper', 'renewal' => 'Fornyes', 'kpi' => 'KPI/indeks'];
        $typeLabel  = $typeLabels[$type] ?? $type;

        $urgencyColor = $daysUntil <= 7 ? '#dc3545' : ($daysUntil <= 14 ? '#fd7e14' : '#0d6efd');
        $title        = htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8');
        $dateFormatted = date('d.m.Y', $ts);
        $baseUrl      = rtrim((string)(getenv('APP_URL') ?: 'https://teknisk.hkbb.no'), '/');
        $viewUrl      = $baseUrl . '/?page=contracts_view&id=' . $cid;

        $ownerDisplay = $ownerInfo ? $ownerInfo['display_name'] : ($ownerUn ?: '–');

        $subject = "[HKBB Teknisk] $typeLabel om $daysUntil dag" . ($daysUntil !== 1 ? 'er' : '') . ": $title";

        $html = <<<HTML
<!DOCTYPE html>
<html lang="no">
<head><meta charset="UTF-8"><title>$subject</title></head>
<body style="font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 16px;">

  <div style="background:#0d6efd; color:#fff; padding:16px 20px; border-radius:6px 6px 0 0;">
    <strong style="font-size:1.1em;">HKBB Teknisk – Avtalevarsel</strong>
  </div>

  <div style="border:1px solid #dee2e6; border-top:none; padding:20px; border-radius:0 0 6px 6px;">

    <p style="font-size:1.05em;">
      Avtalen <strong>$title</strong> har en hendelse om
      <strong style="color:$urgencyColor;">$daysUntil dag{$daysUntil_plural}</strong>:
    </p>

    <table style="border-collapse:collapse; width:100%; margin-bottom:16px;">
      <tr>
        <td style="padding:6px 10px; background:#f8f9fa; font-weight:bold; width:40%;">Hendelse</td>
        <td style="padding:6px 10px; border-left:1px solid #dee2e6;">
          <span style="background:$urgencyColor; color:#fff; padding:3px 10px; border-radius:4px; font-size:.9em;">
            $typeLabel
          </span>
        </td>
      </tr>
      <tr>
        <td style="padding:6px 10px; background:#f8f9fa; font-weight:bold;">Dato</td>
        <td style="padding:6px 10px; border-left:1px solid #dee2e6;"><strong>$dateFormatted</strong></td>
      </tr>
      <tr>
        <td style="padding:6px 10px; background:#f8f9fa; font-weight:bold;">Dager igjen</td>
        <td style="padding:6px 10px; border-left:1px solid #dee2e6;">
          <strong style="color:$urgencyColor;">$daysUntil</strong>
        </td>
      </tr>
      <tr>
        <td style="padding:6px 10px; background:#f8f9fa; font-weight:bold;">Ansvarlig</td>
        <td style="padding:6px 10px; border-left:1px solid #dee2e6;">$ownerDisplay</td>
      </tr>
    </table>

    <p>
      <a href="$viewUrl"
         style="display:inline-block; background:#0d6efd; color:#fff; padding:10px 20px;
                text-decoration:none; border-radius:4px; font-weight:bold;">
        Åpne avtale i Teknisk
      </a>
    </p>

    <hr style="border:none; border-top:1px solid #dee2e6; margin:20px 0;">
    <p style="color:#6c757d; font-size:.85em;">
      Dette er en automatisk melding fra HKBB Teknisk – Avtaler &amp; kontrakter.<br>
      For å endre varslingsinnstillinger, gå til
      <a href="$baseUrl/?page=contracts_alerts">Varsler &amp; fornyelser</a>.
    </p>
  </div>
</body>
</html>
HTML;

        // Fix plural in the HTML (PHP heredoc doesn't allow expressions)
        $daysUntil_plural = $daysUntil !== 1 ? 'er' : '';
        $html = str_replace('{$daysUntil_plural}', $daysUntil_plural, $html);

        // Send
        try {
            $mailer->send($to, $subject, $html);

            $sentToEmail = implode(', ', array_keys($to));
            logLine('info', "Sendt: cid=$cid type=$type dager=$daysUntil til=[$sentToEmail] tittel=" . $row['title']);
            $totalSent++;

            // Log
            if ($hasAlertLog) {
                try {
                    $pdo->prepare("
                        INSERT INTO contracts_alert_log
                            (contract_id, alert_type, alert_date, days_before, sent_to, sent_by, created_at)
                        VALUES
                            (:cid, :type, :date, :days, :sent_to, 'cron', NOW())
                    ")->execute([
                        ':cid'     => $cid,
                        ':type'    => $type,
                        ':date'    => $dateVal,
                        ':days'    => $daysUntil,
                        ':sent_to' => $sentToEmail,
                    ]);
                } catch (\Throwable $e) {
                    logLine('warning', 'Logging til contracts_alert_log feilet: ' . $e->getMessage());
                }
            }

            // Mark as sent today (in-memory)
            $sentToday[$dedupeKey] = true;

        } catch (MailException | \Throwable $e) {
            logLine('error', "Kunne ikke sende e-post for cid=$cid type=$type: " . $e->getMessage());
            $totalErrors++;
        }
    }
}

logLine('info', "=== Ferdig: $totalSent varsler sendt, $totalErrors feil ===");
exit($totalErrors > 0 ? 1 : 0);
