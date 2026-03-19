<?php
// Path: /public/dashboard/events/index.php
//
// Public TV Dashboard: Hendelser / Endringer (uten innlogging)
// Sikkerhet: kun KEY i URL: ?key=...
//
// UI:
// - Kun standard Bootstrap-klasser (ingen egen CSS)
// - Bootswatch Materia (Bootstrap 5)
//
// Datoformat: "Mandag 2 mars"
//
// Viktig (ERR_BLOCKED_BY_RESPONSE):
// - Minimal header-policy i PHP (ikke CSP/XFO her).
// - Overstyr arvede blokk-headere i /public/dashboard/events/web.config ved behov.

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$root = dirname(__DIR__, 3); // /public/dashboard/events -> prosjektroot
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    echo "Mangler autoload.";
    exit;
}
require $autoload;

use App\Database;

/* ------------------------------------------------------------
   Helpers
------------------------------------------------------------ */
function esc(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function table_exists(PDO $pdo, string $table): bool {
    try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
    catch (\Throwable $e) { return false; }
}

function sha256(string $s): string { return hash('sha256', $s); }

function deny(string $msg): void {
    http_response_code(403);
    echo "<!doctype html><meta charset='utf-8'><title>Ikke tilgang</title>";
    echo "<div style='font-family:system-ui;padding:24px'>";
    echo "<h2>Ikke tilgang</h2>";
    echo "<p>" . esc($msg) . "</p>";
    echo "</div>";
    exit;
}

function fmt_dt(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if (!$ts) return '';
    return date('Y-m-d H:i', $ts);
}

function fmt_no_daydate(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if (!$ts) return '';

    $days = [
        'Monday'    => 'Mandag',
        'Tuesday'   => 'Tirsdag',
        'Wednesday' => 'Onsdag',
        'Thursday'  => 'Torsdag',
        'Friday'    => 'Fredag',
        'Saturday'  => 'Lørdag',
        'Sunday'    => 'Søndag',
    ];
    $months = [
        'January'   => 'januar',
        'February'  => 'februar',
        'March'     => 'mars',
        'April'     => 'april',
        'May'       => 'mai',
        'June'      => 'juni',
        'July'      => 'juli',
        'August'    => 'august',
        'September' => 'september',
        'October'   => 'oktober',
        'November'  => 'november',
        'December'  => 'desember',
    ];

    $enDay   = date('l', $ts);
    $enMonth = date('F', $ts);
    $dayNum  = date('j', $ts);

    $noDay   = $days[$enDay] ?? $enDay;
    $noMonth = $months[$enMonth] ?? $enMonth;

    return $noDay . ' ' . $dayNum . ' ' . $noMonth;
}

function format_tv_date(): string {
    return fmt_no_daydate(date('Y-m-d'));
}

function status_no(string $status): string {
    return match (strtolower(trim($status))) {
        'draft'       => 'Utkast',
        'scheduled'   => 'Planlagt',
        'in_progress' => 'Pågår',
        'monitoring'  => 'Overvåkes',
        'resolved'    => 'Utført',
        'cancelled'   => 'Avbrutt',
        default       => $status,
    };
}

function type_no(string $type): string {
    return match (strtolower(trim($type))) {
        'planned'  => 'Endring',
        'incident' => 'Hendelse',
        default    => $type,
    };
}

function time_text(array $r): string {
    $type = (string)($r['type'] ?? '');
    if (strtolower($type) === 'planned') {
        $a = fmt_dt((string)($r['schedule_start'] ?? ''));
        $b = fmt_dt((string)($r['schedule_end'] ?? ''));
        if ($a !== '' && $b !== '') return $a . '–' . $b;
        return $a !== '' ? $a : ($b !== '' ? $b : '');
    }
    $as = fmt_dt((string)($r['actual_start'] ?? ''));
    return $as !== '' ? ('Siden ' . $as) : '';
}

function planned_day_text(array $r): string {
    $type = strtolower(trim((string)($r['type'] ?? '')));
    if ($type !== 'planned') return '';
    $start = (string)($r['schedule_start'] ?? '');
    return $start ? fmt_no_daydate($start) : '';
}

function eta_text(array $r): string {
    $status = strtolower(trim((string)($r['status'] ?? '')));
    if (!in_array($status, ['in_progress','monitoring'], true)) return '';
    $eta = (string)($r['next_update_eta'] ?? '');
    return $eta ? fmt_dt($eta) : '';
}

function status_badge_class(string $status): string {
    return match (strtolower(trim($status))) {
        'in_progress' => 'text-bg-success',
        'scheduled'   => 'text-bg-primary',
        'monitoring'  => 'text-bg-warning',
        'resolved'    => 'text-bg-secondary',
        'cancelled'   => 'text-bg-danger',
        default       => 'text-bg-light',
    };
}

/** Card-stil pr type: planned=primary, incident=danger */
function card_style_for_type(string $type): array {
    $t = strtolower(trim($type));
    return match ($t) {
        'planned'  => ['border' => 'border-primary', 'header' => 'text-bg-primary'],
        'incident' => ['border' => 'border-danger',  'header' => 'text-bg-danger'],
        default    => ['border' => 'border-secondary','header' => 'text-bg-secondary'],
    };
}

/* ------------------------------------------------------------
   DB
------------------------------------------------------------ */
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    http_response_code(500);
    echo "Databasefeil.";
    exit;
}

/* ------------------------------------------------------------
   KEY (eneste adgang)
------------------------------------------------------------ */
$key = trim((string)($_GET['key'] ?? ''));
if ($key === '') deny("Mangler key. Bruk /dashboard/events/?key=...");

$keyTable = 'v4_public_dashboard_keys';
if (!table_exists($pdo, $keyTable)) deny("Nøkkeltabell mangler (`$keyTable`).");

$dash = 'events_tv';
$hash = sha256($key);

$okKey = false;
try {
    $st = $pdo->prepare("
        SELECT id
          FROM v4_public_dashboard_keys
         WHERE dashboard = :d
           AND revoked_at IS NULL
           AND key_hash = :h
         LIMIT 1
    ");
    $st->execute([':d' => $dash, ':h' => $hash]);
    $okKey = (bool)$st->fetchColumn();
} catch (\Throwable $e) {
    $okKey = false;
}
if (!$okKey) deny("Ugyldig key (eller revokert).");

/* ------------------------------------------------------------
   Data
------------------------------------------------------------ */
$rows = [];
$errorData = null;

try {
    // Jira nøkkel: external_id -> meta_json.key/issueKey -> siste segment i url
    $sql = "
        SELECT
            e.id,
            e.`type`,
            e.`status`,
            e.title_public,
            e.summary_public,
            e.schedule_start,
            e.schedule_end,
            e.actual_start,
            e.updated_at,
            e.next_update_eta,
            COALESCE(e.affected_customers, 0) AS affected_customers,
            COALESCE(e.published_to_dashboard, 0) AS published_to_dashboard,
            COALESCE(e.published_to_chatbot, 0) AS published_to_chatbot,
            COALESCE(e.is_public, 0) AS is_public,
            COALESCE(
              NULLIF(TRIM(j.external_id), ''),
              NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(j.meta_json,'$.key'))), ''),
              NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(j.meta_json,'$.issueKey'))), ''),
              NULLIF(TRIM(SUBSTRING_INDEX(j.external_url,'/',-1)), ''),
              ''
            ) AS jira_key,
            COALESCE(j.external_url, '') AS jira_url
        FROM events e
        LEFT JOIN event_integrations j
               ON j.event_id = e.id
              AND j.system = 'jira'
        WHERE
            e.`status` IN ('in_progress','scheduled','monitoring')
            OR (e.`status` IN ('resolved','cancelled') AND e.updated_at >= (NOW() - INTERVAL 12 HOUR))
        ORDER BY
            CASE e.`status`
                WHEN 'in_progress' THEN 1
                WHEN 'monitoring'  THEN 2
                WHEN 'scheduled'   THEN 3
                WHEN 'resolved'    THEN 4
                WHEN 'cancelled'   THEN 5
                ELSE 9
            END,
            COALESCE(e.schedule_start, e.actual_start, e.updated_at) ASC,
            e.id ASC
        LIMIT 80
    ";
    $st = $pdo->query($sql);
    $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (\Throwable $e) {
    $errorData = 'Kunne ikke hente events-data.';
    $rows = [];
}

$refresh = (int)($_GET['refresh'] ?? 60);
if ($refresh < 10) $refresh = 10;
if ($refresh > 600) $refresh = 600;

?><!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <meta http-equiv="refresh" content="<?= (int)$refresh ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hendelser – TV</title>

    <!-- Bootswatch Materia (Bootstrap 5) -->
    <link rel="stylesheet" href="https://bootswatch.com/5/materia/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container-fluid py-2" style="max-width: 1600px;">

    <div class="d-flex align-items-end justify-content-between gap-3 mb-2">
        <div>
            <div class="h4 mb-0">Hendelser / endringer</div>
            <div class="text-muted">
                <?= esc(format_tv_date()) ?> • Oppdateres hvert <?= (int)$refresh ?>s
            </div>
        </div>
        <div class="text-muted text-end">
            Public TV-view<br>
            Sikkerhet: key
        </div>
    </div>

    <?php if ($errorData): ?>
        <div class="alert alert-danger mb-2" role="alert">
            <?= esc($errorData) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="alert alert-light mb-2" role="alert">
            Ingen aktive/planlagte hendelser å vise akkurat nå.
        </div>
    <?php else: ?>

        <?php foreach ($rows as $r): ?>
            <?php
            $status = (string)($r['status'] ?? '');
            $type   = (string)($r['type'] ?? '');

            $title  = trim((string)($r['title_public'] ?? ''));
            if ($title === '') $title = 'Sak #' . (string)($r['id'] ?? '');

            $summary = trim((string)($r['summary_public'] ?? ''));
            $time    = time_text($r);

            $plannedDay = planned_day_text($r);
            $eta = eta_text($r);

            $aff = (int)($r['affected_customers'] ?? 0);
            $upd = (string)($r['updated_at'] ?? '');

            $jira = trim((string)($r['jira_key'] ?? ''));
            if ($jira === '') $jira = '—';

            $pubDash  = (int)($r['published_to_dashboard'] ?? 0) === 1;
            $pubBot   = (int)($r['published_to_chatbot'] ?? 0) === 1;
            $isPublic = (int)($r['is_public'] ?? 0) === 1;

            $badgeCls = status_badge_class($status);

            $style = card_style_for_type($type);
            $cardBorder = $style['border'];
            $headerCls  = $style['header'];
            ?>

            <div class="card <?= esc($cardBorder) ?> mb-2">
                <div class="card-header <?= esc($headerCls) ?> py-1">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0">
                            <div class="small opacity-75 lh-1"><?= esc(type_no($type)) ?></div>
                            <div class="text-truncate lh-sm" style="max-width: 1300px;">
                                <?= esc($title) ?>
                            </div>
                        </div>
                        <span class="badge <?= esc($badgeCls) ?> py-1">
                            <?= esc(status_no($status)) ?>
                        </span>
                    </div>
                </div>

                <div class="card-body py-2">
                    <div class="row g-1">
                        <div class="col-12 col-lg-6">
                            <div class="bg-body-tertiary rounded px-2 py-1 h-100">
                                <div class="small text-muted lh-1">
                                    <?= (strtolower(trim($type)) === 'planned') ? 'Planlagt' : 'Tid' ?>
                                </div>
                                <div class="lh-sm"><?= esc($time !== '' ? $time : '—') ?></div>

                                <?php if ($plannedDay !== ''): ?>
                                    <div class="small text-muted lh-1 mt-1">Dag: <?= esc($plannedDay) ?></div>
                                <?php endif; ?>

                                <?php if ($eta !== ''): ?>
                                    <div class="small text-muted lh-1 mt-1">ETA: <?= esc($eta) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-6 col-lg-2">
                            <div class="bg-body-tertiary rounded px-2 py-1 h-100">
                                <div class="small text-muted lh-1">Berørte</div>
                                <div class="lh-sm"><?= esc((string)$aff) ?></div>
                            </div>
                        </div>

                        <div class="col-6 col-lg-2">
                            <div class="bg-body-tertiary rounded px-2 py-1 h-100">
                                <div class="small text-muted lh-1">Oppdatert</div>
                                <div class="lh-sm"><?= esc($upd !== '' ? fmt_dt($upd) : '—') ?></div>
                            </div>
                        </div>

                        <div class="col-6 col-lg-2">
                            <div class="bg-body-tertiary rounded px-2 py-1 h-100">
                                <div class="small text-muted lh-1">Jira</div>
                                <div class="lh-sm"><?= esc($jira) ?></div>
                            </div>
                        </div>
                    </div>

                    <?php if ($summary !== ''): ?>
                        <div class="mt-1">
                            <div class="small text-muted lh-1">Melding</div>
                            <div class="lh-sm"><?= esc($summary) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-end gap-1 mt-1">
                        <span class="badge <?= $pubDash ? 'text-bg-success' : 'text-bg-light text-muted' ?>">KS</span>
                        <span class="badge <?= $pubBot ? 'text-bg-success' : 'text-bg-light text-muted' ?>">Hkon</span>
                        <span class="badge <?= $isPublic ? 'text-bg-success' : 'text-bg-light text-muted' ?>">Public</span>
                    </div>
                </div>
            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>
</body>
</html>