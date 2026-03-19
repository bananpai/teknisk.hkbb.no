<?php
// /public/pages/report_kpi_dashboard.php
//
// KPI / Mål – Dashboard
// - Viser KPIer for valgt måned (period_month = YYYY-MM-01)
// - Bruker robust rolleoppslag:
//   * Session-roller/perms (flere mulige nøkler) + DB (users -> user_roles)
// - FIKS: user_roles har ikke kolonnen "username" (har user_id + role)
//         -> vi slår opp user_id i users-tabellen og henter roller deretter.
//
// NYTT I DENNE FILEN (mål + status + bedre visning):
// - Henter ÅRSMÅL fra kpi_targets (hvis tabell finnes)
// - Henter prognose for valgt måned fra kpi_forecasts (hvis tabell finnes)
// - Viser status-badges basert på:
//    * Mangler/rapportert
//    * Prognose-avvik (hvis prognose finnes/aktivert)
//    * Måloppnåelse (hvis årsmål finnes)
// - Grupperer KPI-er per avdeling i egne kort, med tydeligere kolonner og “detaljer” som kan felles ut.
// - Støtter desimaler per KPI (kpi_metrics.decimals) hvis kolonnen finnes.
//
// NYTT (UI/Filter):
// - "Fra måned" / "Til måned" picker (type="month") som begrenser måneds-navigering
// - Måneds-pills innenfor valgt intervall
// - Forrige/Neste klamper innenfor fra/til-intervallet
//
// FIKS (DB):
// - kpi_forecasts hos deg bruker year + month (ikke period_month). Join justeres dynamisk.
//
// UI-OPPDATERING (etter ønsket):
// - Ansvarlig vises i egen kolonne med fullt navn (fra users hvis mulig)
// - Retning vises i egen kolonne
// - Fjernet "Registrer/Oppdater" per rad – hele raden er klikkbar til report_kpi_entry

declare(strict_types=1);

use App\Database;

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Normaliser session-username til typisk users.username:
 * - "DOMENE\bruker" -> "bruker"
 * - "bruker@domene" -> "bruker"
 */
if (!function_exists('normalizeUsername')) {
    function normalizeUsername(string $u): string {
        $u = trim($u);
        if ($u === '') return '';

        if (strpos($u, '\\') !== false) {
            $parts = explode('\\', $u);
            $u = (string)(end($parts) ?: $u);
        }
        if (strpos($u, '@') !== false) {
            $u = (string)(explode('@', $u)[0] ?: $u);
        }
        return trim($u);
    }
}

if (!function_exists('normalize_list')) {
    function normalize_list($v): array {
        if (is_array($v)) {
            return array_values(array_filter(array_map('strval', $v), fn($x) => trim((string)$x) !== ''));
        }
        if (is_string($v) && trim($v) !== '') {
            $parts = preg_split('/[,\s;]+/', $v) ?: [];
            $parts = array_map('strval', $parts);
            $parts = array_map('trim', $parts);
            return array_values(array_filter($parts, fn($x) => $x !== ''));
        }
        return [];
    }
}

if (!function_exists('has_any')) {
    function has_any(array $needles, array $haystack): bool {
        $haystack = array_map(static fn($x) => mb_strtolower((string)$x, 'UTF-8'), $haystack);
        $set = array_flip($haystack);
        foreach ($needles as $n) {
            $n = mb_strtolower(trim((string)$n), 'UTF-8');
            if ($n !== '' && isset($set[$n])) return true;
        }
        return false;
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

if (!function_exists('column_exists')) {
    function column_exists(PDO $pdo, string $table, string $column): bool {
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*)
                  FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :t
                   AND COLUMN_NAME = :c
            ");
            $st->execute([':t' => $table, ':c' => $column]);
            return ((int)$st->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('first_existing_column')) {
    function first_existing_column(PDO $pdo, string $table, array $candidates): ?string {
        foreach ($candidates as $c) {
            if (column_exists($pdo, $table, $c)) return $c;
        }
        return null;
    }
}

/**
 * Hent session-liste av roller/perms/grupper (bakoverkompatibelt).
 */
if (!function_exists('session_role_list')) {
    function session_role_list(): array {
        $keys = ['permissions', 'roles', 'groups', 'user_groups', 'ad_groups'];
        $out = [];
        foreach ($keys as $k) {
            if (!isset($_SESSION[$k])) continue;
            $out = array_merge($out, normalize_list($_SESSION[$k]));
        }
        $out = array_map(static fn($x) => mb_strtolower((string)$x, 'UTF-8'), $out);
        return array_values(array_unique($out));
    }
}

/**
 * Finn user_id basert på session-username (raw eller normalisert), case-insensitive.
 */
if (!function_exists('resolve_user_id')) {
    function resolve_user_id(PDO $pdo, string $sessionUsername): int {
        $raw  = trim($sessionUsername);
        $norm = normalizeUsername($raw);

        if ($raw === '' && $norm === '') return 0;

        $st = $pdo->prepare("
            SELECT u.id
              FROM users u
             WHERE LOWER(u.username) = LOWER(:u)
             LIMIT 1
        ");

        if ($raw !== '') {
            $st->execute([':u' => $raw]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) return $id;
        }

        if ($norm !== '' && $norm !== $raw) {
            $st->execute([':u' => $norm]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) return $id;
        }

        return 0;
    }
}

/**
 * Hent roller fra DB (user_roles) ved å slå opp user_id først.
 * VIKTIG: user_roles har ikke username-kolonne.
 */
if (!function_exists('db_roles_for_user')) {
    function db_roles_for_user(PDO $pdo, string $sessionUsername): array {
        if (!table_exists($pdo, 'users') || !table_exists($pdo, 'user_roles')) return [];

        $uid = resolve_user_id($pdo, $sessionUsername);
        if ($uid <= 0) return [];

        $st = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :uid");
        $st->execute([':uid' => $uid]);
        $roles = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $roles = normalize_list($roles);
        $roles = array_map(static fn($x) => mb_strtolower((string)$x, 'UTF-8'), $roles);
        return array_values(array_unique($roles));
    }
}

function default_decimals_for_format(string $fmt): int {
    return match ($fmt) {
        'count'        => 0,
        'percent'      => 2,
        'nok'          => 0,
        'nok_thousand' => 0,
        default        => 2,
    };
}

function clamp_decimals(int $d): int {
    if ($d < 0) return 0;
    if ($d > 6) return 6;
    return $d;
}

function format_number_no(float $x, int $decimals): string {
    return number_format($x, $decimals, ',', ' ');
}

function fmt_value(?string $v, string $format, string $unitLabel = '', ?int $decimalsOverride = null): string {
    if ($v === null || $v === '') return '—';
    $num = (float)str_replace(',', '.', (string)$v);

    $dec = $decimalsOverride ?? default_decimals_for_format($format);
    $dec = clamp_decimals((int)$dec);

    switch ($format) {
        case 'percent':
            return format_number_no($num, $dec) . ' %';
        case 'nok':
            return format_number_no($num, $dec) . ' NOK';
        case 'nok_thousand':
            return format_number_no($num, $dec) . ' (tusen NOK)';
        case 'count':
        default:
            $out = format_number_no($num, $dec);
            $u = trim((string)$unitLabel);
            return $u !== '' ? ($out . ' ' . $u) : $out;
    }
}

function badge(string $text, string $type): string {
    // type: success|warning|danger|info|secondary|primary
    return '<span class="badge text-bg-' . h($type) . '">' . h($text) . '</span>';
}

/**
 * Evaluer status mot prognose/target.
 */
function evaluate_status(
    bool $hasValue,
    ?float $actualMonth,
    ?float $actualYtd,
    ?float $forecastMonth,
    ?float $targetYear,
    string $direction, // higher|lower
    bool $forecastEnabled,
    string $mode // mtd_ytd|single
): array {
    if (!$hasValue) {
        return [
            'badge'  => badge('Mangler', 'warning'),
            'detail' => 'Ingen rapporterte verdier for perioden.',
            'delta'  => null,
            'ratio'  => null,
        ];
    }

    // Prognose-status (hvis finnes og aktiv)
    if ($forecastEnabled && $forecastMonth !== null && $actualMonth !== null) {
        $delta = $actualMonth - $forecastMonth; // positiv betyr "høyere enn prognose"
        $tol = max(0.000001, abs($forecastMonth) * 0.001); // 0.1% eller minst 1e-6

        if (abs($delta) <= $tol) {
            return [
                'badge'  => badge('På prognose', 'info'),
                'detail' => 'Avvik innenfor toleranse.',
                'delta'  => $delta,
                'ratio'  => null,
            ];
        }

        $better = ($direction === 'higher') ? ($delta > 0) : ($delta < 0);
        if ($better) {
            return [
                'badge'  => badge('Bedre enn prognose', 'success'),
                'detail' => 'Faktisk verdi er bedre enn prognose.',
                'delta'  => $delta,
                'ratio'  => null,
            ];
        }

        return [
            'badge'  => badge('Svake avvik', 'danger'),
            'detail' => 'Faktisk verdi er svakere enn prognose.',
            'delta'  => $delta,
            'ratio'  => null,
        ];
    }

    // Målstatus (hvis årsmål finnes)
    if ($targetYear !== null) {
        $ref = ($mode === 'single' || $actualYtd === null) ? $actualMonth : $actualYtd;

        if ($ref === null) {
            return [
                'badge'  => badge('Rapportert', 'success'),
                'detail' => 'Rapportert, men mangler sammenligningsgrunnlag mot mål.',
                'delta'  => null,
                'ratio'  => null,
            ];
        }

        $ratio = null;
        if ($targetYear != 0.0) {
            $ratio = $ref / $targetYear;
        }

        if ($direction === 'lower') {
            if ($ref <= $targetYear) {
                return [
                    'badge'  => badge('Innen mål', 'success'),
                    'detail' => 'Verdien er innenfor (≤) årsmålet.',
                    'delta'  => $ref - $targetYear,
                    'ratio'  => $ratio,
                ];
            }
            return [
                'badge'  => badge('Over mål', 'danger'),
                'detail' => 'Verdien er over (>) årsmålet.',
                'delta'  => $ref - $targetYear,
                'ratio'  => $ratio,
            ];
        }

        if ($ref >= $targetYear) {
            return [
                'badge'  => badge('Mål nådd', 'success'),
                'detail' => 'Verdien er ≥ årsmålet.',
                'delta'  => $ref - $targetYear,
                'ratio'  => $ratio,
            ];
        }

        return [
            'badge'  => badge('Under mål', 'warning'),
            'detail' => 'Verdien er under (<) årsmålet.',
            'delta'  => $ref - $targetYear,
            'ratio'  => $ratio,
        ];
    }

    return [
        'badge'  => badge('Rapportert', 'success'),
        'detail' => 'Verdier er rapportert.',
        'delta'  => null,
        'ratio'  => null,
    ];
}

/**
 * Parse "YYYY-MM" eller "YYYY-MM-01" til DateTime på 1. i måneden.
 */
function parse_month_param(string $s, DateTimeZone $tz): ?DateTime {
    $s = trim($s);
    if ($s === '') return null;

    if (preg_match('/^\d{4}-\d{2}$/', $s)) {
        $s .= '-01';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;

    try {
        $d = new DateTime($s, $tz);
        $d->setDate((int)$d->format('Y'), (int)$d->format('m'), 1);
        $d->setTime(0, 0, 0);
        return $d;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Norsk månedsnavn hvis Intl finnes.
 */
function format_month_label(DateTime $d, string $tzName = 'Europe/Oslo'): string {
    if (class_exists('IntlDateFormatter')) {
        try {
            $fmt = new IntlDateFormatter(
                'nb_NO',
                IntlDateFormatter::LONG,
                IntlDateFormatter::NONE,
                $tzName,
                IntlDateFormatter::GREGORIAN,
                'LLLL yyyy'
            );
            $out = $fmt->format($d);
            if (is_string($out) && trim($out) !== '') {
                $out = mb_strtoupper(mb_substr($out, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($out, 1, null, 'UTF-8');
                return $out;
            }
        } catch (\Throwable $e) {
            // fallback
        }
    }
    return $d->format('F Y');
}

/**
 * Lag liste av months (DateTime) fra->til inkl.
 */
function months_between(DateTime $from, DateTime $to, int $max = 36): array {
    $out = [];
    $cur = (clone $from);
    $cur->setDate((int)$cur->format('Y'), (int)$cur->format('m'), 1);
    $to2 = (clone $to);
    $to2->setDate((int)$to2->format('Y'), (int)$to2->format('m'), 1);

    $i = 0;
    while ($cur <= $to2) {
        $out[] = (clone $cur);
        $cur->modify('+1 month');
        $i++;
        if ($i >= $max) break;
    }
    return $out;
}

// ---------------------------------------------------------
// Guard: må være innlogget
// ---------------------------------------------------------
$sessionUsername = trim((string)($_SESSION['username'] ?? ''));
if ($sessionUsername === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    http_response_code(500);
    ?>
    <div class="alert alert-danger mt-3">Kunne ikke koble til databasen.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Roller: session + DB
// ---------------------------------------------------------
$sessionRoles = session_role_list();
$dbRoles      = db_roles_for_user($pdo, $sessionUsername);
$roles        = array_values(array_unique(array_merge($sessionRoles, $dbRoles)));

$isAdmin     = has_any(['admin'], $roles);
$canKpi      = $isAdmin || has_any(['report_admin', 'report_user', 'kpi_admin', 'kpi_user'], $roles);
$canKpiAdmin = $isAdmin || has_any(['report_admin', 'kpi_admin'], $roles);

if (!$canKpi) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang til Mål &amp; KPI.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Perioder / filtre (periode + fra/til "picker")
// ---------------------------------------------------------
$tz = new DateTimeZone('Europe/Oslo');
$now = new DateTime('now', $tz);
$now->setDate((int)$now->format('Y'), (int)$now->format('m'), 1);
$now->setTime(0, 0, 0);

$period = (clone $now); // default: inneværende måned

$periodParam = trim((string)($_GET['period'] ?? ''));
$fromParam   = trim((string)($_GET['from'] ?? ''));
$toParam     = trim((string)($_GET['to'] ?? ''));

$rangeFrom = parse_month_param($fromParam, $tz) ?? null;
$rangeTo   = parse_month_param($toParam, $tz) ?? null;

if ($rangeFrom === null && $rangeTo === null) {
    $rangeFrom = (clone $now);
    $rangeTo   = (clone $now);
} elseif ($rangeFrom !== null && $rangeTo === null) {
    $rangeTo = (clone $rangeFrom);
} elseif ($rangeFrom === null && $rangeTo !== null) {
    $rangeFrom = (clone $rangeTo);
}

// swap om feil rekkefølge
if ($rangeFrom !== null && $rangeTo !== null && $rangeFrom > $rangeTo) {
    $tmp = $rangeFrom;
    $rangeFrom = $rangeTo;
    $rangeTo = $tmp;
}

// sett valgt periode fra param, ellers i intervallet
if ($periodParam !== '') {
    $p = parse_month_param($periodParam, $tz);
    if ($p !== null) $period = $p;
}

// clamp periode inn i [rangeFrom, rangeTo]
if ($rangeFrom !== null && $period < $rangeFrom) $period = (clone $rangeFrom);
if ($rangeTo !== null && $period > $rangeTo) $period = (clone $rangeTo);

// måneds-liste for "pills"
$monthPills = [];
$monthPillsLimited = false;
if ($rangeFrom !== null && $rangeTo !== null) {
    $monthPills = months_between($rangeFrom, $rangeTo, 36);
    $endIfUnlimited = (clone $rangeFrom);
    $endIfUnlimited->modify('+36 month');
    if ($rangeTo >= $endIfUnlimited) $monthPillsLimited = true;
}

$periodMonth = $period->format('Y-m-01');
$periodYear  = (int)$period->format('Y');
$periodM     = (int)$period->format('m');

$prevDt = (clone $period)->modify('-1 month');
$nextDt = (clone $period)->modify('+1 month');

// clamp prev/next innenfor range hvis valgt
if ($rangeFrom !== null && $prevDt < $rangeFrom) $prevDt = (clone $rangeFrom);
if ($rangeTo !== null && $nextDt > $rangeTo) $nextDt = (clone $rangeTo);

$prev = $prevDt->format('Y-m-01');
$next = $nextDt->format('Y-m-01');

$periodLabel = format_month_label($period, 'Europe/Oslo');

$fromVal = $rangeFrom ? $rangeFrom->format('Y-m') : $period->format('Y-m');
$toVal   = $rangeTo ? $rangeTo->format('Y-m') : $period->format('Y-m');

// ---------------------------------------------------------
// Sjekk tabeller / kolonner (robust)
// ---------------------------------------------------------
$missingTables = [];
foreach (['kpi_metrics','kpi_departments','kpi_assignments','kpi_values'] as $t) {
    if (!table_exists($pdo, $t)) $missingTables[] = $t;
}
if (!empty($missingTables)) {
    ?>
    <div class="alert alert-warning mt-3">
        KPI-modulen er ikke komplett i databasen enda. Mangler tabeller:
        <code><?= h(implode(', ', $missingTables)) ?></code>
    </div>
    <?php
    return;
}

$hasDecimalsCol = column_exists($pdo, 'kpi_metrics', 'decimals');
$hasDirCol      = column_exists($pdo, 'kpi_metrics', 'success_direction');
$hasFeCol       = column_exists($pdo, 'kpi_metrics', 'forecast_enabled');

$hasTargetsTable  = table_exists($pdo, 'kpi_targets');
$hasForecastTable = table_exists($pdo, 'kpi_forecasts');

$targetValueCol = $hasTargetsTable ? first_existing_column($pdo, 'kpi_targets', ['target_value','value','target']) : null;
$targetNoteCol  = $hasTargetsTable ? first_existing_column($pdo, 'kpi_targets', ['note','comment','remarks']) : null;

$forecastValueCol = $hasForecastTable ? first_existing_column($pdo, 'kpi_forecasts', ['forecast_value','value','value_forecast','forecast']) : null;

// Prognose-kolonner i kpi_forecasts (hos deg: year+month)
$forecastHasPeriodMonth = $hasForecastTable ? column_exists($pdo, 'kpi_forecasts', 'period_month') : false;
$forecastHasYear        = $hasForecastTable ? column_exists($pdo, 'kpi_forecasts', 'year') : false;
$forecastHasMonth       = $hasForecastTable ? column_exists($pdo, 'kpi_forecasts', 'month') : false;

// Fullt navn for ansvarlig (users.*)
$hasUsersTable   = table_exists($pdo, 'users');
$usersHasUsername = $hasUsersTable ? column_exists($pdo, 'users', 'username') : false;
$usersNameCol    = ($hasUsersTable && $usersHasUsername)
    ? first_existing_column($pdo, 'users', ['fullname','full_name','name','display_name','displayName'])
    : null;

// ---------------------------------------------------------
// Hent KPIer til dashboard
// - Admin/report_admin: alle aktive assignments
// - report_user: kun assignments hvor responsible/backup matcher innlogget bruker
// ---------------------------------------------------------
$who      = normalizeUsername($sessionUsername);
$whoLower = mb_strtolower($who, 'UTF-8');

$items = [];

try {
    $selectExtra = [];
    $joinExtra   = [];

    // Metrics-kolonner (desimaler + retning/prognose toggle)
    $selectExtra[] = $hasDecimalsCol ? "m.decimals AS decimals" : "NULL AS decimals";
    $selectExtra[] = $hasDirCol ? "m.success_direction AS success_direction" : "'higher' AS success_direction";
    $selectExtra[] = $hasFeCol ? "m.forecast_enabled AS forecast_enabled" : "1 AS forecast_enabled";

    // Ansvarlig fullt navn (users)
    if ($usersNameCol && $usersHasUsername) {
        $joinExtra[] = "LEFT JOIN users ur ON LOWER(ur.username) = LOWER(a.responsible_username)";
        $selectExtra[] = "ur.`{$usersNameCol}` AS responsible_fullname";
    } else {
        $selectExtra[] = "NULL AS responsible_fullname";
    }

    // Årsmål (targets)
    if ($hasTargetsTable && $targetValueCol) {
        $joinExtra[] = "LEFT JOIN kpi_targets t ON t.metric_id = a.metric_id AND t.target_year = :y";
        $selectExtra[] = "t.`{$targetValueCol}` AS target_value";
        $selectExtra[] = $targetNoteCol ? "t.`{$targetNoteCol}` AS target_note" : "NULL AS target_note";
    } else {
        $selectExtra[] = "NULL AS target_value";
        $selectExtra[] = "NULL AS target_note";
    }

    // Prognose (forecasts) for valgt måned
    if ($hasForecastTable && $forecastValueCol) {
        if ($forecastHasPeriodMonth) {
            $joinExtra[] = "LEFT JOIN kpi_forecasts f
                              ON f.metric_id = a.metric_id
                             AND f.department_id = a.department_id
                             AND f.period_month = :p";
            $selectExtra[] = "f.`{$forecastValueCol}` AS forecast_value";
        } elseif ($forecastHasYear && $forecastHasMonth) {
            $joinExtra[] = "LEFT JOIN kpi_forecasts f
                              ON f.metric_id = a.metric_id
                             AND f.department_id = a.department_id
                             AND f.year = :fy
                             AND f.month = :fm";
            $selectExtra[] = "f.`{$forecastValueCol}` AS forecast_value";
        } else {
            $selectExtra[] = "NULL AS forecast_value";
        }
    } else {
        $selectExtra[] = "NULL AS forecast_value";
    }

    $selectExtraSql = implode(",\n                ", $selectExtra);
    $joinExtraSql   = implode("\n            ", $joinExtra);

    $baseSql = "
        SELECT
            a.id                           AS assignment_id,
            m.id                           AS metric_id,
            m.name                         AS metric_name,
            m.value_mode                   AS value_mode,
            m.value_format                 AS value_format,
            m.unit_label                   AS unit_label,
            d.id                           AS department_id,
            d.name                         AS department_name,
            d.sort_order                   AS department_sort_order,
            m.sort_order                   AS metric_sort_order,
            a.responsible_username         AS responsible_username,
            a.backup_username              AS backup_username,

            v.id                           AS value_id,
            v.value_mtd                    AS value_mtd,
            v.value_ytd                    AS value_ytd,
            v.comment                      AS comment,
            v.source                       AS source,
            v.method                       AS method,
            v.submitted_by                 AS submitted_by,
            v.submitted_at                 AS submitted_at,
            v.updated_by                   AS updated_by,
            v.updated_at                   AS updated_at,

            {$selectExtraSql}
        FROM kpi_assignments a
        JOIN kpi_metrics m
          ON m.id = a.metric_id AND m.is_active = 1
        JOIN kpi_departments d
          ON d.id = a.department_id AND d.is_active = 1
        LEFT JOIN kpi_values v
          ON v.metric_id = a.metric_id
         AND v.department_id = a.department_id
         AND v.period_month = :p
        {$joinExtraSql}
        WHERE a.is_active = 1
    ";

    $paramsBase = [
        ':p'  => $periodMonth,
        ':y'  => $periodYear,
    ];

    if ($hasForecastTable && $forecastValueCol && !$forecastHasPeriodMonth && $forecastHasYear && $forecastHasMonth) {
        $paramsBase[':fy'] = $periodYear;
        $paramsBase[':fm'] = $periodM;
    }

    if ($canKpiAdmin) {
        $sql = $baseSql . "
            ORDER BY d.sort_order ASC, d.name ASC, m.sort_order ASC, m.name ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute($paramsBase);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $sql = $baseSql . "
          AND (
                LOWER(a.responsible_username) = :u
             OR (a.backup_username IS NOT NULL AND LOWER(a.backup_username) = :u)
          )
          ORDER BY d.sort_order ASC, d.name ASC, m.sort_order ASC, m.name ASC
        ";
        $st = $pdo->prepare($sql);
        $params = $paramsBase + [':u' => $whoLower];
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

} catch (\Throwable $e) {
    ?>
    <div class="alert alert-danger mt-3">
        Kunne ikke hente KPI-data (DB-feil).
        <div class="small text-muted mt-1">
            Tips: Sjekk at <code>kpi_forecasts</code> har forventede kolonner (hos deg: <code>year</code> + <code>month</code>).
        </div>
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Bygg struktur for visning (grupper per avdeling)
// ---------------------------------------------------------
$byDept = [];
$summary = [
    'total' => 0,
    'missing' => 0,
    'reported' => 0,
    'better' => 0,
    'on' => 0,
    'worse' => 0,
];

foreach ($items as $r) {
    $summary['total']++;

    $dept = (string)($r['department_name'] ?? 'Ukjent avdeling');
    if (!isset($byDept[$dept])) {
        $byDept[$dept] = [
            'department_id' => (int)($r['department_id'] ?? 0),
            'department_name' => $dept,
            'department_sort_order' => (int)($r['department_sort_order'] ?? 0),
            'rows' => [],
        ];
    }

    $valueMode   = (string)($r['value_mode'] ?? 'mtd_ytd');
    $valueFormat = (string)($r['value_format'] ?? 'count');
    $unitLabel   = (string)($r['unit_label'] ?? '');

    $decimals = ($r['decimals'] !== null) ? clamp_decimals((int)$r['decimals']) : default_decimals_for_format($valueFormat);

    $hasValue = ($r['value_id'] ?? null) !== null;

    $mtdRaw = $r['value_mtd'] ?? null;
    $ytdRaw = $r['value_ytd'] ?? null;

    $actualMonth = null;
    if ($mtdRaw !== null && $mtdRaw !== '') $actualMonth = (float)str_replace(',', '.', (string)$mtdRaw);

    $actualYtd = null;
    if ($ytdRaw !== null && $ytdRaw !== '') $actualYtd = (float)str_replace(',', '.', (string)$ytdRaw);

    $forecastEnabled = ((int)($r['forecast_enabled'] ?? 1) === 1);
    $direction = (string)($r['success_direction'] ?? 'higher');
    if (!in_array($direction, ['higher','lower'], true)) $direction = 'higher';

    $forecastMonth = null;
    if (($r['forecast_value'] ?? null) !== null && $r['forecast_value'] !== '') {
        $forecastMonth = (float)str_replace(',', '.', (string)$r['forecast_value']);
    }

    $targetYear = null;
    if (($r['target_value'] ?? null) !== null && $r['target_value'] !== '') {
        $targetYear = (float)str_replace(',', '.', (string)$r['target_value']);
    }

    $status = evaluate_status(
        $hasValue,
        $actualMonth,
        $actualYtd,
        $forecastMonth,
        $targetYear,
        $direction,
        $forecastEnabled,
        $valueMode
    );

    if (!$hasValue) {
        $summary['missing']++;
    } else {
        $summary['reported']++;
        $btxt = strip_tags((string)$status['badge']);
        if (mb_stripos($btxt, 'Bedre', 0, 'UTF-8') !== false || mb_stripos($btxt, 'Mål nådd', 0, 'UTF-8') !== false || mb_stripos($btxt, 'Innen mål', 0, 'UTF-8') !== false) {
            $summary['better']++;
        } elseif (mb_stripos($btxt, 'På prognose', 0, 'UTF-8') !== false) {
            $summary['on']++;
        } elseif (mb_stripos($btxt, 'Svake', 0, 'UTF-8') !== false || mb_stripos($btxt, 'Over mål', 0, 'UTF-8') !== false) {
            $summary['worse']++;
        }
    }

    // ansvarlig navn (fallback)
    $respUser = (string)($r['responsible_username'] ?? '');
    $respName = trim((string)($r['responsible_fullname'] ?? ''));
    if ($respName === '') $respName = $respUser;

    $r['_meta'] = [
        'value_mode' => $valueMode,
        'value_format' => $valueFormat,
        'unit_label' => $unitLabel,
        'decimals' => $decimals,
        'has_value' => $hasValue,
        'actual_month' => $actualMonth,
        'actual_ytd' => $actualYtd,
        'forecast_value' => $forecastMonth,
        'target_value' => $targetYear,
        'status' => $status,
        'responsible_name' => $respName,
    ];

    $byDept[$dept]['rows'][] = $r;
}

// sorter avdelinger etter sort_order, så navn
uksort($byDept, function ($a, $b) use ($byDept) {
    $sa = $byDept[$a]['department_sort_order'] ?? 0;
    $sb = $byDept[$b]['department_sort_order'] ?? 0;
    if ($sa === $sb) return strcmp((string)$a, (string)$b);
    return $sa <=> $sb;
});

// ---------------------------------------------------------
// URL-helper for å bevare fra/til når man navigerer
// ---------------------------------------------------------
function dash_url(string $periodMonth, string $fromYM, string $toYM): string {
    $qs = [
        'page'   => 'report_kpi_dashboard',
        'period' => $periodMonth,
        'from'   => $fromYM,
        'to'     => $toYM,
    ];
    return '/?' . http_build_query($qs);
}

function entry_url(string $periodMonth, int $metricId, int $departmentId): string {
    $qs = [
        'page' => 'report_kpi_entry',
        'period' => $periodMonth,
        'metric_id' => $metricId,
        'department_id' => $departmentId,
    ];
    return '/?' . http_build_query($qs);
}

?>
<div class="mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h4 mb-1">Mål &amp; KPI</h1>
            <div class="text-muted small">
                Periode: <strong><?= h($periodLabel) ?></strong>
                <span class="ms-2">(<?= h($periodMonth) ?>)</span>
                <?php if ($canKpiAdmin): ?>
                    <span class="badge text-bg-secondary ms-2">Admin</span>
                <?php else: ?>
                    <span class="badge text-bg-secondary ms-2">Bruker</span>
                <?php endif; ?>

                <?php if (!$hasTargetsTable): ?>
                    <span class="badge text-bg-info ms-2">Årsmål: ikke aktivert</span>
                <?php endif; ?>
                <?php if (!$hasForecastTable): ?>
                    <span class="badge text-bg-info ms-2">Prognose: ikke aktivert</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= h(dash_url($prev, $fromVal, $toVal)) ?>">
                <i class="bi bi-chevron-left"></i> Forrige
            </a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h(dash_url($next, $fromVal, $toVal)) ?>">
                Neste <i class="bi bi-chevron-right"></i>
            </a>
            <?php if ($canKpiAdmin): ?>
                <a class="btn btn-sm btn-outline-secondary" href="/?page=report_kpi_admin">
                    <i class="bi bi-gear me-1"></i> Admin
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<section class="card shadow-sm mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <input type="hidden" name="page" value="report_kpi_dashboard">

            <div class="col-sm-3">
                <label class="form-label small">Periode</label>
                <input type="month" class="form-control form-control-sm" name="period" value="<?= h($period->format('Y-m')) ?>">
            </div>

            <div class="col-sm-3">
                <label class="form-label small">Fra</label>
                <input type="month" class="form-control form-control-sm" name="from" value="<?= h($fromVal) ?>">
            </div>

            <div class="col-sm-3">
                <label class="form-label small">Til</label>
                <input type="month" class="form-control form-control-sm" name="to" value="<?= h($toVal) ?>">
            </div>

            <div class="col-sm-3">
                <button class="btn btn-sm btn-outline-primary" type="submit">
                    <i class="bi bi-funnel me-1"></i> Vis
                </button>
            </div>

            <div class="col-12">
                <div class="text-muted small">
                    Tips: Velg <strong>Fra</strong> og <strong>Til</strong> for å begrense månedene du kan navigere i. Klikk på månedene under for raskt bytte.
                </div>
            </div>
        </form>

        <?php if (!empty($monthPills)): ?>
            <div class="mt-2 d-flex flex-wrap gap-1">
                <?php foreach ($monthPills as $md): ?>
                    <?php
                    $mStr = $md->format('Y-m-01');
                    $isActive = ($mStr === $periodMonth);
                    $lbl = format_month_label($md, 'Europe/Oslo');
                    ?>
                    <a class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-secondary' ?>"
                       href="<?= h(dash_url($mStr, $fromVal, $toVal)) ?>">
                        <?= h($lbl) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($monthPillsLimited): ?>
                <div class="alert alert-warning mt-2 mb-0 small">
                    Intervall er stort – viser maks 36 måneder i hurtiglisten. Bruk periodefeltet for å hoppe lengre.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php if (empty($items)): ?>
    <div class="alert alert-info">
        Ingen KPIer er tildelt deg for denne perioden.
        <?php if ($canKpiAdmin): ?>
            Du er admin – sjekk at assignments er opprettet under <a href="/?page=report_kpi_admin">Admin</a>.
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body py-2">
                    <div class="text-muted small">Totalt</div>
                    <div class="h5 mb-0"><?= (int)$summary['total'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body py-2">
                    <div class="text-muted small">Rapportert</div>
                    <div class="h5 mb-0">
                        <?= (int)$summary['reported'] ?>
                        <?= $summary['total'] ? '<span class="text-muted small">(' . h((string)round(($summary['reported']/$summary['total'])*100)) . '%)</span>' : '' ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body py-2">
                    <div class="text-muted small">Mangler</div>
                    <div class="h5 mb-0"><?= (int)$summary['missing'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body py-2">
                    <div class="text-muted small">Bedre / På / Svakere</div>
                    <div class="h6 mb-0">
                        <span class="me-2"><?= (int)$summary['better'] ?></span>
                        <span class="me-2 text-muted">/</span>
                        <span class="me-2"><?= (int)$summary['on'] ?></span>
                        <span class="me-2 text-muted">/</span>
                        <span><?= (int)$summary['worse'] ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    $accordionId = 'kpiDashAcc_' . substr(md5($periodMonth . '|' . $who), 0, 8);
    ?>

    <div class="accordion" id="<?= h($accordionId) ?>">
        <?php $deptIndex = 0; ?>
        <?php foreach ($byDept as $deptName => $deptData): ?>
            <?php
            $deptIndex++;
            $panelId = $accordionId . '_dept_' . $deptIndex;
            $headingId = $panelId . '_h';
            $collapseId = $panelId . '_c';

            $deptRows = $deptData['rows'];
            $deptTotal = count($deptRows);
            $deptMissing = 0;
            foreach ($deptRows as $rr) {
                if (!($rr['_meta']['has_value'] ?? false)) $deptMissing++;
            }
            ?>
            <div class="accordion-item shadow-sm mb-2">
                <h2 class="accordion-header" id="<?= h($headingId) ?>">
                    <button class="accordion-button <?= $deptIndex === 1 ? '' : 'collapsed' ?>"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#<?= h($collapseId) ?>"
                            aria-expanded="<?= $deptIndex === 1 ? 'true' : 'false' ?>"
                            aria-controls="<?= h($collapseId) ?>">
                        <div class="d-flex flex-wrap align-items-center justify-content-between w-100 pe-3">
                            <div class="fw-semibold"><?= h($deptName) ?></div>
                            <div class="small text-muted">
                                <?= (int)$deptTotal ?> KPI ·
                                <?= $deptMissing ? '<span class="text-danger">mangler ' . (int)$deptMissing . '</span>' : '<span class="text-success">alt rapportert</span>' ?>
                            </div>
                        </div>
                    </button>
                </h2>

                <div id="<?= h($collapseId) ?>"
                     class="accordion-collapse collapse <?= $deptIndex === 1 ? 'show' : '' ?>"
                     aria-labelledby="<?= h($headingId) ?>"
                     data-bs-parent="#<?= h($accordionId) ?>">
                    <div class="accordion-body pt-2">

                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                <tr class="text-muted small">
                                    <th style="min-width: 260px;">KPI</th>
                                    <th style="min-width: 200px;">Ansvarlig</th>
                                    <th style="min-width: 140px;">Retning</th>
                                    <th style="min-width: 110px;">Måned</th>
                                    <th style="min-width: 110px;">YTD</th>
                                    <th style="min-width: 140px;">Prognose (mnd)</th>
                                    <th style="min-width: 140px;">Årsmål</th>
                                    <th style="min-width: 130px;">Status</th>
                                </tr>
                                </thead>
                                <tbody>

                                <?php foreach ($deptRows as $r): ?>
                                    <?php
                                    $meta = $r['_meta'];
                                    $valueMode = (string)$meta['value_mode'];
                                    $valueFormat = (string)$meta['value_format'];
                                    $unitLabel = (string)$meta['unit_label'];
                                    $decimals = (int)$meta['decimals'];

                                    $hasValue = (bool)$meta['has_value'];

                                    $monthShow = fmt_value($r['value_mtd'] ?? null, $valueFormat, $unitLabel, $decimals);
                                    $ytdShow = ($valueMode === 'single')
                                        ? '—'
                                        : fmt_value($r['value_ytd'] ?? null, $valueFormat, $unitLabel, $decimals);

                                    $forecastShow = ($meta['forecast_value'] === null)
                                        ? '—'
                                        : fmt_value((string)$meta['forecast_value'], $valueFormat, $unitLabel, $decimals);

                                    $targetShow = ($meta['target_value'] === null)
                                        ? '—'
                                        : fmt_value((string)$meta['target_value'], $valueFormat, $unitLabel, $decimals);

                                    $status = $meta['status'];
                                    $statusBadge = (string)$status['badge'];

                                    $detailId = 'kpi_detail_' . (int)$r['assignment_id'] . '_' . substr(md5($periodMonth), 0, 6);

                                    $dir = (string)($r['success_direction'] ?? 'higher');
                                    $fe  = (int)($r['forecast_enabled'] ?? 1);
                                    $dirLabel = ($dir === 'lower') ? 'Lavere er bedre' : 'Høyere er bedre';

                                    $rowHref = entry_url($periodMonth, (int)$r['metric_id'], (int)$r['department_id']);
                                    $respName = (string)($meta['responsible_name'] ?? (string)($r['responsible_username'] ?? ''));
                                    ?>
                                    <tr class="kpi-click-row"
                                        data-href="<?= h($rowHref) ?>"
                                        style="cursor: pointer;">
                                        <td>
                                            <div class="fw-semibold"><?= h((string)($r['metric_name'] ?? '')) ?></div>

                                            <?php if (!empty($r['comment']) || !empty($r['source']) || !empty($r['method'])): ?>
                                                <a class="small text-decoration-none kpi-detail-toggle"
                                                   data-bs-toggle="collapse"
                                                   href="#<?= h($detailId) ?>"
                                                   role="button"
                                                   aria-expanded="false"
                                                   aria-controls="<?= h($detailId) ?>">
                                                    <i class="bi bi-chevron-down me-1"></i>Detaljer
                                                </a>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <div><?= h($respName) ?></div>
                                            <?php if (!empty($r['backup_username'])): ?>
                                                <div class="text-muted small">Backup: <?= h((string)$r['backup_username']) ?></div>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <div><?= h($dirLabel) ?></div>
                                            <div class="text-muted small">
                                                <?php if (!$hasForecastTable || $fe !== 1): ?>
                                                    <span class="badge text-bg-light">Prognose av</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-light">Prognose på</span>
                                                <?php endif; ?>
                                                <?php if (!$hasTargetsTable): ?>
                                                    <span class="badge text-bg-light ms-1">Årsmål av</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td><?= h($monthShow) ?></td>
                                        <td><?= h($ytdShow) ?></td>

                                        <td>
                                            <?= h($forecastShow) ?>
                                            <?php if ($hasValue && $meta['forecast_value'] !== null && $meta['actual_month'] !== null && ((int)($r['forecast_enabled'] ?? 1) === 1)): ?>
                                                <?php
                                                $delta = (float)$meta['actual_month'] - (float)$meta['forecast_value'];
                                                $deltaShow = fmt_value((string)$delta, $valueFormat, $unitLabel, $decimals);
                                                ?>
                                                <div class="text-muted small">Avvik: <?= h($deltaShow) ?></div>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= h($targetShow) ?>
                                            <?php if ($hasValue && $meta['target_value'] !== null): ?>
                                                <?php
                                                $ref = ($valueMode === 'single' || $meta['actual_ytd'] === null) ? $meta['actual_month'] : $meta['actual_ytd'];
                                                $ratio = ($ref !== null && (float)$meta['target_value'] != 0.0) ? ($ref / (float)$meta['target_value']) : null;
                                                ?>
                                                <?php if ($ratio !== null && is_finite($ratio)): ?>
                                                    <div class="text-muted small">
                                                        <?= $valueMode === 'single' ? 'Mot mål (mnd): ' : 'Mot mål (YTD): ' ?>
                                                        <?= h((string)round($ratio * 100)) ?>%
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($r['target_note'])): ?>
                                                <div class="text-muted small"><?= h((string)$r['target_note']) ?></div>
                                            <?php endif; ?>
                                        </td>

                                        <td><?= $statusBadge ?></td>
                                    </tr>

                                    <?php if (!empty($r['comment']) || !empty($r['source']) || !empty($r['method'])): ?>
                                        <tr class="table-light">
                                            <td colspan="8" class="p-0">
                                                <div class="collapse" id="<?= h($detailId) ?>">
                                                    <div class="p-3 small">
                                                        <?php if (!empty($r['comment'])): ?>
                                                            <div class="mb-1"><strong>Kommentar:</strong> <?= nl2br(h((string)$r['comment'])) ?></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($r['source'])): ?>
                                                            <div class="mb-1"><strong>Kilde:</strong> <?= nl2br(h((string)$r['source'])) ?></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($r['method'])): ?>
                                                            <div class="mb-0"><strong>Metode:</strong> <?= nl2br(h((string)$r['method'])) ?></div>
                                                        <?php endif; ?>

                                                        <?php if (!empty($r['updated_at']) || !empty($r['submitted_at'])): ?>
                                                            <hr class="my-2">
                                                            <div class="text-muted">
                                                                <?php if (!empty($r['updated_at'])): ?>
                                                                    Sist oppdatert: <?= h((string)$r['updated_at']) ?>
                                                                    <?php if (!empty($r['updated_by'])): ?>
                                                                        (<?= h((string)$r['updated_by']) ?>)
                                                                    <?php endif; ?>
                                                                <?php elseif (!empty($r['submitted_at'])): ?>
                                                                    Innsendt: <?= h((string)$r['submitted_at']) ?>
                                                                    <?php if (!empty($r['submitted_by'])): ?>
                                                                        (<?= h((string)$r['submitted_by']) ?>)
                                                                    <?php endif; ?>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>

                                <?php endforeach; ?>

                                </tbody>
                            </table>
                        </div>

                        <div class="text-muted small mt-2">
                            Tips: Klikk på en rad for å åpne innrapportering for KPIen i valgt periode.
                            Bruk “Detaljer” for kommentar/kilde/metode.
                        </div>

                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        (function () {
            function shouldIgnoreClick(e) {
                // Ikke naviger hvis brukeren klikker på detaljer-toggle eller inne i collapsible-området
                if (e.target.closest('.kpi-detail-toggle')) return true;

                // Ikke kapre vanlige lenker/knapper/inputs (for sikkerhets skyld)
                if (e.target.closest('a, button, input, select, textarea, label')) return true;

                return false;
            }

            document.querySelectorAll('tr.kpi-click-row[data-href]').forEach(function (tr) {
                tr.addEventListener('click', function (e) {
                    if (shouldIgnoreClick(e)) return;
                    var href = tr.getAttribute('data-href');
                    if (href) window.location.href = href;
                });

                // Litt “hover” feedback uten å måtte sette global CSS
                tr.addEventListener('mouseenter', function () {
                    tr.classList.add('table-active');
                });
                tr.addEventListener('mouseleave', function () {
                    tr.classList.remove('table-active');
                });
            });

            // Stopp bobling på detaljer-lenken så den ikke trigger rad-klikk i noen nettlesere
            document.querySelectorAll('.kpi-detail-toggle').forEach(function (a) {
                a.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            });
        })();
    </script>

<?php endif; ?>
