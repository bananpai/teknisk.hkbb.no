<?php
// /public/pages/report_kpi_entry.php
//
// KPI / Mål – Innrapportering (per måned)
// - Krever rolle: report_user eller report_admin (eller admin)
// - Viser KPI-er som er delegert til innlogget bruker (ansvarlig eller stedfortreder)
// - Registrerer måned / YTD (avhengig av KPI-oppsett), kommentar, kilde og metode
// - Skriver endringshistorikk til kpi_value_history ved oppdatering (hvis tabellen finnes)
//
// NYTT (Desimaler):
// - KPI kan ha egen "decimals" (0–6) i kpi_metrics
// - Verdier rundes av ved lagring iht. KPI-desimaler
// - Inputfelt viser formattert verdi iht. KPI-desimaler (ikke rå DB-streng)
//
// NYTT (Måned/år bevares ved POST):
// - hidden year/month på alle POST-forms
// - server bruker POST-year/month når action kommer fra POST
//
// NYTT (Registrer faktiske tall direkte i prognosetabellen):
// - Prognoseform har ekstra kolonne "Månedsverdi"
// - action save_forecast_table lagrer prognose + faktiske verdier (for alle måneder som er fylt)
//
// NYTT (AUTO-YTD for "antall"):
// - Hvis KPI har value_format = 'count' og value_mode = 'mtd_ytd':
//   YTD beregnes automatisk som SUM av månedsverdier (jan -> valgt måned) basert på Månedsverdi.
//
// NYTT (UI):
// - KPIene vises som Bootstrap accordion (ingen primary header)
// - KPIer med antall (count) gråer ut YTD (readonly + bg-light)
// - Progress bar øverst for valgt måned: striped bg-info, viser prosent
//
// NYTT (Navigering):
// - Knapper under statusbaren for forrige/neste måned

declare(strict_types=1);

use App\Database;

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * pexec(): Robust execute() for PDOStatement.
 * - Named params: finner alle :param i SQL og sørger for at alle finnes i execute-arrayet (mangler => NULL).
 * - Positional ?: trimmer/padder parametre til riktig antall ? (mangler => NULL).
 */
if (!function_exists('pexec')) {
    function pexec(PDOStatement $st, array $params = []): void {
        $sql = (string)$st->queryString;

        if (strpos($sql, '?') !== false) {
            $qCount = substr_count($sql, '?');

            $vals = [];
            $allIntKeys = true;
            foreach (array_keys($params) as $k) {
                if (!is_int($k)) { $allIntKeys = false; break; }
            }

            if ($allIntKeys) {
                ksort($params);
                $vals = array_values($params);
            } else {
                $tmp = [];
                foreach ($params as $k => $v) {
                    if (is_int($k)) $tmp[$k] = $v;
                }
                ksort($tmp);
                $vals = array_values($tmp);
            }

            $cur = count($vals);
            if ($cur < $qCount) {
                $vals = array_merge($vals, array_fill(0, $qCount - $cur, null));
            } elseif ($cur > $qCount) {
                $vals = array_slice($vals, 0, $qCount);
            }

            $st->execute($vals);
            return;
        }

        preg_match_all('/:([a-zA-Z0-9_]+)/', $sql, $m);
        $names = array_values(array_unique($m[1] ?? []));

        $filtered = [];
        foreach ($names as $n) {
            $withColon = ':' . $n;

            if (array_key_exists($withColon, $params)) {
                $filtered[$withColon] = $params[$withColon];
            } elseif (array_key_exists($n, $params)) {
                $filtered[$withColon] = $params[$n];
            } else {
                $filtered[$withColon] = null;
            }
        }

        $st->execute($filtered);
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
            pexec($st, [':t' => $table, ':c' => $column]);
            return ((int)$st->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

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
            $parts = array_map('trim', array_map('strval', $parts));
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

if (!function_exists('session_roles_list')) {
    function session_roles_list(): array {
        $keys = ['roles', 'permissions', 'groups', 'user_groups', 'ad_groups'];
        $out = [];
        foreach ($keys as $k) {
            if (!isset($_SESSION[$k])) continue;
            $out = array_merge($out, normalize_list($_SESSION[$k]));
        }
        $out = array_map(static fn($x) => mb_strtolower((string)$x, 'UTF-8'), $out);
        return array_values(array_unique($out));
    }
}

if (!function_exists('resolve_user_id')) {
    function resolve_user_id(PDO $pdo, string $sessionUsername): int {
        if (!table_exists($pdo, 'users')) return 0;

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
            pexec($st, [':u' => $raw]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) return $id;
        }
        if ($norm !== '' && $norm !== $raw) {
            pexec($st, [':u' => $norm]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) return $id;
        }
        return 0;
    }
}

if (!function_exists('db_roles_for_user')) {
    function db_roles_for_user(PDO $pdo, string $sessionUsername): array {
        if (!table_exists($pdo, 'user_roles') || !table_exists($pdo, 'users')) return [];

        $uid = resolve_user_id($pdo, $sessionUsername);
        if ($uid <= 0) return [];

        $st = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :uid");
        pexec($st, [':uid' => $uid]);

        $roles = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $roles = normalize_list($roles);
        $roles = array_map(static fn($x) => mb_strtolower((string)$x, 'UTF-8'), $roles);

        return array_values(array_unique($roles));
    }
}

if (!function_exists('get_session_roles')) {
    function get_session_roles(PDO $pdo, string $username): array {
        $sess = session_roles_list();
        $db   = db_roles_for_user($pdo, $username);
        $all  = array_values(array_unique(array_merge($sess, $db)));
        $_SESSION['roles'] = $all;
        return $all;
    }
}

if (!function_exists('has_role')) {
    function has_role(PDO $pdo, string $username, string $role): bool {
        $roles = get_session_roles($pdo, $username);
        return has_any([$role], $roles) || has_any(['admin'], $roles);
    }
}

function normalize_decimal(?string $s): ?string {
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '') return null;
    $s = str_replace(' ', '', $s);
    $s = str_replace(',', '.', $s);
    return $s;
}

function clamp_decimals(int $d): int {
    if ($d < 0) return 0;
    if ($d > 6) return 6;
    return $d;
}

function default_decimals_for_format(string $fmt): int {
    return match ($fmt) {
        'percent' => 1,
        'nok' => 0,
        'nok_thousand' => 0,
        default => 0,
    };
}

function fmt_num_display($raw, int $dec): string {
    if ($raw === null) return '—';
    $s = trim((string)$raw);
    if ($s === '') return '—';
    $n = normalize_decimal($s);
    if ($n === null || !is_numeric($n)) return $s;
    return number_format((float)$n, $dec, ',', ' ');
}

function fmt_input_value($raw, int $dec): string {
    if ($raw === null) return '';
    $s = trim((string)$raw);
    if ($s === '') return '';
    $n = normalize_decimal($s);
    if ($n === null || !is_numeric($n)) return $s;
    return number_format((float)$n, $dec, ',', '');
}

function month_name(int $m): string {
    return match ($m) {
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
        default => (string)$m
    };
}

function fmt_label(string $fmt): string {
    return match ($fmt) {
        'percent' => 'Prosent (%)',
        'nok' => 'Beløp (NOK)',
        'nok_thousand' => 'Beløp (NOK i tusen)',
        default => 'Antall / teller',
    };
}

function mode_label(string $mode): string {
    return match ($mode) {
        'month' => 'Kun måned',
        'ytd' => 'Kun YTD',
        'single' => 'Kun måned',
        'mtd_ytd' => 'Måned + YTD',
        default => 'Måned + YTD',
    };
}

function dir_label(?string $dir): string {
    return ($dir === 'lower') ? 'Lavere er bedre' : 'Høyere er bedre';
}

function calc_result_goodness(float $actual, float $ref, string $dir): float {
    return ($dir === 'lower') ? ($ref - $actual) : ($actual - $ref);
}

function badge_class_for_result(?float $r): string {
    if ($r === null) return 'secondary';
    if ($r > 0) return 'success';
    if ($r < 0) return 'danger';
    return 'secondary';
}

function build_named_in(array $values, string $prefix, array &$params): string {
    $values = array_values($values);
    if (!$values) return "(NULL)";

    $placeholders = [];
    foreach ($values as $i => $v) {
        $ph = ':' . $prefix . $i;
        $placeholders[] = $ph;
        $params[$ph] = (int)$v;
    }
    return '(' . implode(',', $placeholders) . ')';
}

function first_day_of_month(int $year, int $month): string {
    return sprintf('%04d-%02d-01', $year, $month);
}

function calc_ytd_count_from_db(
    PDO $pdo,
    int $metricId,
    int $deptId,
    int $year,
    int $month,
    ?string $newMonthVal,
    bool $kvHasPeriodMonth,
    bool $kvHasYearMonth,
    string $kvColMtd
): float {
    $cur = 0.0;
    if ($newMonthVal !== null && $newMonthVal !== '' && is_numeric((string)$newMonthVal)) {
        $cur = (float)$newMonthVal;
    }

    if ($month <= 1) return $cur;

    if ($kvHasPeriodMonth) {
        $start = sprintf('%04d-01-01', $year);
        $end   = sprintf('%04d-%02d-01', $year, $month);

        $st = $pdo->prepare("
            SELECT COALESCE(SUM(COALESCE($kvColMtd,0)),0) AS s
              FROM kpi_values
             WHERE metric_id = :m
               AND department_id = :d
               AND period_month >= :s
               AND period_month <  :e
        ");
        pexec($st, [':m'=>$metricId, ':d'=>$deptId, ':s'=>$start, ':e'=>$end]);
        $prev = (float)($st->fetchColumn() ?: 0);
        return $prev + $cur;
    }

    if ($kvHasYearMonth) {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(COALESCE($kvColMtd,0)),0) AS s
              FROM kpi_values
             WHERE metric_id = :m
               AND department_id = :d
               AND year = :y
               AND month >= 1
               AND month < :mo
        ");
        pexec($st, [':m'=>$metricId, ':d'=>$deptId, ':y'=>$year, ':mo'=>$month]);
        $prev = (float)($st->fetchColumn() ?: 0);
        return $prev + $cur;
    }

    return $cur;
}

function actual_get_month_value(?array $row, string $mode, string $colMtd, ?string $colSingle = null): ?float {
    if (!$row) return null;
    if ($mode === 'ytd') return null;

    if ($mode === 'single' || $mode === 'month') {
        if ($colSingle !== null && array_key_exists($colSingle, $row) && $row[$colSingle] !== null) {
            return (float)$row[$colSingle];
        }
        if (array_key_exists($colMtd, $row) && $row[$colMtd] !== null) {
            return (float)$row[$colMtd];
        }
        return null;
    }

    if (array_key_exists($colMtd, $row) && $row[$colMtd] !== null) {
        return (float)$row[$colMtd];
    }
    return null;
}

function actual_get_ytd_value(?array $row, ?string $colYtd, string $mode, string $colMtd): ?float {
    if (!$row) return null;

    if ($colYtd !== null && array_key_exists($colYtd, $row) && $row[$colYtd] !== null) {
        return (float)$row[$colYtd];
    }
    if ($mode === 'ytd' && array_key_exists($colMtd, $row) && $row[$colMtd] !== null) {
        return (float)$row[$colMtd];
    }
    return null;
}

function has_value($v): bool {
    if ($v === null) return false;
    if (is_string($v)) return trim($v) !== '';
    return true;
}

// ---------------------------------------------------------
// Guard: innlogging + rettighet
// ---------------------------------------------------------
$username = trim((string)($_SESSION['username'] ?? ''));
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

$pdo = Database::getConnection();

if (!has_role($pdo, $username, 'report_user') && !has_role($pdo, $username, 'report_admin')) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang (krever report_user).</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Krev basetabeller
// ---------------------------------------------------------
$required = ['kpi_departments','kpi_metrics','kpi_assignments'];
$missing = [];
foreach ($required as $t) {
    if (!table_exists($pdo, $t)) $missing[] = $t;
}
if ($missing) {
    ?>
    <div class="alert alert-warning mt-3">
        <div class="fw-semibold mb-1">KPI-modulen er ikke installert i databasen.</div>
        <div>Mangler tabeller: <code><?= h(implode(', ', $missing)) ?></code></div>
    </div>
    <?php
    return;
}

$hasValues      = table_exists($pdo, 'kpi_values');
$hasDecimalsCol = column_exists($pdo, 'kpi_metrics', 'decimals');

$hasForecasts = table_exists($pdo, 'kpi_forecasts')
    && column_exists($pdo, 'kpi_metrics', 'success_direction')
    && column_exists($pdo, 'kpi_metrics', 'forecast_enabled');

// ---------------------------------------------------------
// Finn kpi_values schema (gammel vs ny)
// ---------------------------------------------------------
$kvHasPeriodMonth = $hasValues && column_exists($pdo, 'kpi_values', 'period_month');
$kvHasYearMonth   = $hasValues && column_exists($pdo, 'kpi_values', 'year') && column_exists($pdo, 'kpi_values', 'month');

$kvColMtd = $hasValues
    ? (column_exists($pdo, 'kpi_values', 'value_mtd') ? 'value_mtd' : (column_exists($pdo, 'kpi_values', 'mtd_value') ? 'mtd_value' : 'value_mtd'))
    : 'value_mtd';
$kvColYtd = $hasValues
    ? (column_exists($pdo, 'kpi_values', 'value_ytd') ? 'value_ytd' : (column_exists($pdo, 'kpi_values', 'ytd_value') ? 'ytd_value' : null))
    : null;
$kvColSingle = $hasValues && column_exists($pdo, 'kpi_values', 'single_value') ? 'single_value' : null;

$kvColComment = $hasValues && column_exists($pdo, 'kpi_values', 'comment') ? 'comment' : 'comment';
$kvColSource  = $hasValues && column_exists($pdo, 'kpi_values', 'source') ? 'source' : 'source';
$kvColMethod  = $hasValues && column_exists($pdo, 'kpi_values', 'method') ? 'method' : 'method';

// ---------------------------------------------------------
// År/måned
// ---------------------------------------------------------
$now = new DateTimeImmutable('now');

$year = (int)($_GET['year'] ?? (int)$now->format('Y'));
$month = (int)($_GET['month'] ?? (int)$now->format('n'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['year']))  $year  = (int)$_POST['year'];
    if (isset($_POST['month'])) $month = (int)$_POST['month'];
}

if ($month < 1 || $month > 12) $month = (int)$now->format('n');
if ($year < 2000 || $year > 2100) $year = (int)$now->format('Y');

$periodMonth = first_day_of_month($year, $month);

// NYTT: beregn forrige/neste måned til navigeringsknapper
$curMonthDt = DateTimeImmutable::createFromFormat('Y-m-d', $periodMonth) ?: new DateTimeImmutable($periodMonth);
$prevDt = $curMonthDt->modify('-1 month');
$nextDt = $curMonthDt->modify('+1 month');
$prevYear = (int)$prevDt->format('Y');
$prevMonth = (int)$prevDt->format('n');
$nextYear = (int)$nextDt->format('Y');
$nextMonth = (int)$nextDt->format('n');

$flash = '';
$flashType = 'success';

// ---------------------------------------------------------
// Hent KPI-assignments
// ---------------------------------------------------------
$uNorm = normalizeUsername($username);

$assignments = $pdo->prepare("
    SELECT
        a.id AS assignment_id,
        a.metric_id,
        a.department_id,
        d.name AS department_name,
        m.name AS metric_name,
        m.description,
        m.value_mode,
        m.value_format,
        m.unit_label,
        " . ($hasDecimalsCol ? "m.decimals," : "NULL AS decimals,") . "
        COALESCE(m.success_direction,'higher') AS success_direction,
        COALESCE(m.forecast_enabled,1) AS forecast_enabled
    FROM kpi_assignments a
    JOIN kpi_departments d ON d.id = a.department_id AND d.is_active = 1
    JOIN kpi_metrics m ON m.id = a.metric_id AND m.is_active = 1
    WHERE a.is_active = 1
      AND (
        LOWER(a.responsible_username) = LOWER(:u1)
        OR LOWER(COALESCE(a.backup_username,'')) = LOWER(:u2)
      )
    ORDER BY d.sort_order ASC, d.name ASC, m.sort_order ASC, m.name ASC
");
pexec($assignments, [':u1' => $uNorm, ':u2' => $uNorm]);
$rows = $assignments->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ---------------------------------------------------------
// Upsert helper (samme som før)
// ---------------------------------------------------------
$upsert_actual_for_month = function(
    int $metricId,
    int $deptId,
    int $y,
    int $mo,
    string $mode,
    ?string $valMonth,
    ?string $valYtd,
    ?string $comment,
    ?string $source,
    ?string $method,
    string $by,
    int $dec
) use ($pdo, $kvHasPeriodMonth, $kvHasYearMonth, $kvColMtd, $kvColYtd, $kvColComment, $kvColSource, $kvColMethod): void {

    $pm = first_day_of_month($y, $mo);

    if ($valMonth !== null && $valMonth !== '' && is_numeric((string)$valMonth)) {
        $valMonth = (string)round((float)$valMonth, $dec);
    }
    if ($valYtd !== null && $valYtd !== '' && is_numeric((string)$valYtd)) {
        $valYtd = (string)round((float)$valYtd, $dec);
    }

    $storeMtd = null;
    $storeYtd = null;

    if ($mode === 'single' || $mode === 'month') {
        $storeMtd = $valMonth;
        $storeYtd = null;
    } elseif ($mode === 'ytd') {
        $storeMtd = null;
        $storeYtd = $valYtd;
        if ($kvColYtd === null) {
            $storeMtd = $valYtd;
            $storeYtd = null;
        }
    } else {
        $storeMtd = $valMonth;
        $storeYtd = $valYtd;
    }

    if ($kvHasPeriodMonth) {
        $stOld = $pdo->prepare("
            SELECT *
              FROM kpi_values
             WHERE metric_id = :m
               AND department_id = :d
               AND period_month = :pm
             LIMIT 1
        ");
        pexec($stOld, [':m'=>$metricId, ':d'=>$deptId, ':pm'=>$pm]);
    } elseif ($kvHasYearMonth) {
        $stOld = $pdo->prepare("
            SELECT *
              FROM kpi_values
             WHERE metric_id = :m
               AND department_id = :d
               AND year = :y
               AND month = :mo
             LIMIT 1
        ");
        pexec($stOld, [':m'=>$metricId, ':d'=>$deptId, ':y'=>$y, ':mo'=>$mo]);
    } else {
        throw new RuntimeException('kpi_values har ukjent schema.');
    }

    $old = $stOld->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($old) {
        if ($kvHasPeriodMonth) {
            $sql = "
                UPDATE kpi_values
                   SET {$kvColMtd} = :mtd,
                       " . ($kvColYtd ? "{$kvColYtd} = :ytd," : "") . "
                       {$kvColComment} = :c,
                       {$kvColSource} = :s,
                       {$kvColMethod} = :me,
                       updated_by = :ub,
                       updated_at = NOW()
                 WHERE metric_id = :m
                   AND department_id = :d
                   AND period_month = :pm
            ";
            $upd = $pdo->prepare($sql);
            $params = [
                ':mtd'=>$storeMtd,
                ':c'=>$comment,
                ':s'=>$source,
                ':me'=>$method,
                ':ub'=>$by,
                ':m'=>$metricId,
                ':d'=>$deptId,
                ':pm'=>$pm,
            ];
            if ($kvColYtd) $params[':ytd'] = $storeYtd;
            pexec($upd, $params);
        } else {
            $sql = "
                UPDATE kpi_values
                   SET {$kvColMtd} = :mtd,
                       " . ($kvColYtd ? "{$kvColYtd} = :ytd," : "") . "
                       {$kvColComment} = :c,
                       {$kvColSource} = :s,
                       {$kvColMethod} = :me,
                       updated_by = :ub,
                       updated_at = NOW()
                 WHERE metric_id = :m
                   AND department_id = :d
                   AND year = :y
                   AND month = :mo
            ";
            $upd = $pdo->prepare($sql);
            $params = [
                ':mtd'=>$storeMtd,
                ':c'=>$comment,
                ':s'=>$source,
                ':me'=>$method,
                ':ub'=>$by,
                ':m'=>$metricId,
                ':d'=>$deptId,
                ':y'=>$y,
                ':mo'=>$mo,
            ];
            if ($kvColYtd) $params[':ytd'] = $storeYtd;
            pexec($upd, $params);
        }
    } else {
        if ($kvHasPeriodMonth) {
            $sql = "
                INSERT INTO kpi_values
                    (metric_id, department_id, period_month,
                     {$kvColMtd}" . ($kvColYtd ? ", {$kvColYtd}" : "") . ",
                     {$kvColComment}, {$kvColSource}, {$kvColMethod},
                     submitted_by, submitted_at)
                VALUES
                    (:m, :d, :pm,
                     :mtd" . ($kvColYtd ? ", :ytd" : "") . ",
                     :c, :s, :me,
                     :sb, NOW())
            ";
            $ins = $pdo->prepare($sql);
            $params = [
                ':m'=>$metricId,
                ':d'=>$deptId,
                ':pm'=>$pm,
                ':mtd'=>$storeMtd,
                ':c'=>$comment,
                ':s'=>$source,
                ':me'=>$method,
                ':sb'=>$by,
            ];
            if ($kvColYtd) $params[':ytd'] = $storeYtd;
            pexec($ins, $params);
        } else {
            $sql = "
                INSERT INTO kpi_values
                    (metric_id, department_id, year, month,
                     {$kvColMtd}" . ($kvColYtd ? ", {$kvColYtd}" : "") . ",
                     {$kvColComment}, {$kvColSource}, {$kvColMethod},
                     created_by, created_at)
                VALUES
                    (:m, :d, :y, :mo,
                     :mtd" . ($kvColYtd ? ", :ytd" : "") . ",
                     :c, :s, :me,
                     :cb, NOW())
            ";
            $ins = $pdo->prepare($sql);
            $params = [
                ':m'=>$metricId,
                ':d'=>$deptId,
                ':y'=>$y,
                ':mo'=>$mo,
                ':mtd'=>$storeMtd,
                ':c'=>$comment,
                ':s'=>$source,
                ':me'=>$method,
                ':cb'=>$by,
            ];
            if ($kvColYtd) $params[':ytd'] = $storeYtd;
            pexec($ins, $params);
        }
    }
};

// ---------------------------------------------------------
// POST: save_actual
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_actual') {
            if (!$hasValues) throw new RuntimeException('kpi_values-tabellen mangler. Kjør KPI-migrering.');

            $metricId = (int)($_POST['metric_id'] ?? 0);
            $deptId   = (int)($_POST['department_id'] ?? 0);
            $mode     = (string)($_POST['value_mode'] ?? 'mtd_ytd');

            if ($metricId <= 0 || $deptId <= 0) throw new RuntimeException('Ugyldig KPI/avdeling.');

            $metricRow = null;
            foreach ($rows as $rr) {
                if ((int)$rr['metric_id'] === $metricId && (int)$rr['department_id'] === $deptId) { $metricRow = $rr; break; }
            }
            if (!$metricRow) throw new RuntimeException('Du har ikke tilgang til denne KPI-en.');

            $fmt = (string)($metricRow['value_format'] ?? 'count');
            $dec = default_decimals_for_format($fmt);
            if ($hasDecimalsCol && isset($metricRow['decimals']) && $metricRow['decimals'] !== null && $metricRow['decimals'] !== '') {
                $dec = clamp_decimals((int)$metricRow['decimals']);
            }

            $comment = trim((string)($_POST['comment'] ?? ''));
            $comment = ($comment !== '' ? $comment : null);

            $mtdIn    = normalize_decimal($_POST['mtd_value'] ?? null);
            $ytdIn    = normalize_decimal($_POST['ytd_value'] ?? null);
            $singleIn = normalize_decimal($_POST['single_value'] ?? null);

            $valMonth = null;
            $valYtd   = null;

            if ($mode === 'single' || $mode === 'month') {
                $valMonth = $singleIn;
            } elseif ($mode === 'ytd') {
                $valYtd = $ytdIn;
            } else {
                $valMonth = $mtdIn;
                $valYtd   = $ytdIn;
            }

            if ($fmt === 'count' && $mode === 'mtd_ytd' && $kvColYtd !== null) {
                $ytdAuto = calc_ytd_count_from_db(
                    $pdo, $metricId, $deptId, $year, $month, $valMonth,
                    $kvHasPeriodMonth, $kvHasYearMonth, $kvColMtd
                );
                $valYtd = (string)round($ytdAuto, $dec);
            }

            $upsert_actual_for_month(
                $metricId, $deptId, $year, $month, $mode,
                $valMonth, $valYtd,
                $comment, null, null,
                $uNorm, $dec
            );

            $flash = 'Lagret.';
        }

        pexec($assignments, [':u1' => $uNorm, ':u2' => $uNorm]);
        $rows = $assignments->fetchAll(PDO::FETCH_ASSOC) ?: [];

    } catch (\Throwable $e) {
        $flashType = 'danger';
        $flash = $e->getMessage();
    }
}

// ---------------------------------------------------------
// Hent faktiske for valgt måned (for UI) + progress
// ---------------------------------------------------------
$actualMap = [];
if ($hasValues && $rows) {
    $metricIds = array_values(array_unique(array_map(fn($r) => (int)$r['metric_id'], $rows)));
    $deptIds   = array_values(array_unique(array_map(fn($r) => (int)$r['department_id'], $rows)));

    if ($metricIds && $deptIds) {
        $params = [];
        $inM = build_named_in($metricIds, 'm', $params);
        $inD = build_named_in($deptIds, 'd', $params);

        if ($kvHasPeriodMonth) {
            $params[':pm'] = $periodMonth;
            $st = $pdo->prepare("
                SELECT *
                  FROM kpi_values
                 WHERE period_month = :pm
                   AND metric_id IN $inM
                   AND department_id IN $inD
            ");
        } else {
            $params[':y']  = $year;
            $params[':mo'] = $month;
            $st = $pdo->prepare("
                SELECT *
                  FROM kpi_values
                 WHERE year = :y
                   AND month = :mo
                   AND metric_id IN $inM
                   AND department_id IN $inD
            ");
        }

        pexec($st, $params);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $v) {
            $k = ((int)$v['metric_id']) . '-' . ((int)$v['department_id']);
            $actualMap[$k] = $v;
        }
    }
}

$progressTotal = $rows ? count($rows) : 0;
$progressDone = 0;

if ($rows) {
    foreach ($rows as $r) {
        $mid = (int)$r['metric_id'];
        $did = (int)$r['department_id'];
        $key = $mid . '-' . $did;

        $mode = (string)($r['value_mode'] ?? 'mtd_ytd');
        $fmt  = (string)($r['value_format'] ?? 'count');

        $actual = $actualMap[$key] ?? null;
        $done = false;

        if ($mode === 'single' || $mode === 'month') {
            $v = $actual[$kvColSingle ?? $kvColMtd] ?? null;
            $done = has_value($v);
        } elseif ($mode === 'ytd') {
            $v = $kvColYtd ? ($actual[$kvColYtd] ?? null) : ($actual[$kvColMtd] ?? null);
            $done = has_value($v);
        } else { // mtd_ytd
            $mVal = $actual[$kvColMtd] ?? null;
            $hasM = has_value($mVal);

            if ($fmt === 'count') {
                $done = $hasM;
            } else {
                if ($kvColYtd === null) {
                    $done = $hasM;
                } else {
                    $yVal = $actual[$kvColYtd] ?? null;
                    $done = $hasM && has_value($yVal);
                }
            }
        }

        if ($done) $progressDone++;
    }
}

$progressPercent = ($progressTotal > 0) ? (int)round(($progressDone / $progressTotal) * 100) : 0;

// ---------------------------------------------------------
// UI
// ---------------------------------------------------------
?>
<div class="d-flex align-items-center justify-content-between mt-3 mb-2">
    <div>
        <h3 class="mb-0">KPI / Mål – Innrapportering</h3>
        <div class="text-muted">Registrer faktiske tall per måned.</div>
    </div>
    <div class="text-end">
        <?php if (has_role($pdo, $username, 'report_admin')): ?>
            <a class="btn btn-outline-secondary btn-sm" href="/?page=report_kpi_admin">Admin</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="/?page=report_kpi_dashboard">Dashboard</a>
    </div>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?= h($flashType) ?> mt-2"><?= h($flash) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mt-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get" action="/">
            <input type="hidden" name="page" value="report_kpi_entry">
            <div class="col-sm-3 col-md-2">
                <label class="form-label">År</label>
                <input class="form-control" type="number" name="year" value="<?= (int)$year ?>" min="2000" max="2100">
            </div>
            <div class="col-sm-3 col-md-2">
                <label class="form-label">Måned</label>
                <select class="form-select" name="month">
                    <?php for ($m=1;$m<=12;$m++): ?>
                        <option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= h(month_name($m)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-8">
                <button class="btn btn-primary">Vis</button>
                <span class="text-muted ms-2 small">Valgt: <?= (int)$year ?>-<?= str_pad((string)$month, 2, '0', STR_PAD_LEFT) ?></span>
            </div>
        </form>

        <?php if ($rows): ?>
            <div class="mt-3">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <div>Utfylt for valgt måned</div>
                    <div><?= (int)$progressDone ?>/<?= (int)$progressTotal ?> (<?= (int)$progressPercent ?>%)</div>
                </div>
                <div class="progress" style="height: 18px;">
                    <div class="progress-bar progress-bar-striped bg-info"
                         role="progressbar"
                         style="width: <?= (int)$progressPercent ?>%;"
                         aria-valuenow="<?= (int)$progressPercent ?>"
                         aria-valuemin="0"
                         aria-valuemax="100">
                        <?= (int)$progressPercent ?>%
                    </div>
                </div>

                <!-- NYTT: Forrige/Neste måned-knapper under statusbaren -->
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <a class="btn btn-outline-secondary btn-sm"
                       href="/?page=report_kpi_entry&year=<?= (int)$prevYear ?>&month=<?= (int)$prevMonth ?>">
                        ← Forrige (<?= (int)$prevYear ?>-<?= str_pad((string)$prevMonth, 2, '0', STR_PAD_LEFT) ?>)
                    </a>
                    <a class="btn btn-outline-secondary btn-sm"
                       href="/?page=report_kpi_entry&year=<?= (int)$nextYear ?>&month=<?= (int)$nextMonth ?>">
                        Neste (<?= (int)$nextYear ?>-<?= str_pad((string)$nextMonth, 2, '0', STR_PAD_LEFT) ?>) →
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$rows): ?>
            <div class="alert alert-info mt-3 mb-0">
                Ingen KPI-er er delegert til deg (som ansvarlig eller stedfortreder).
            </div>
        <?php else: ?>
            <div class="accordion mt-3" id="kpiAccordion">
                <?php foreach ($rows as $idx => $r): ?>
                    <?php
                        $mid = (int)$r['metric_id'];
                        $did = (int)$r['department_id'];
                        $key = $mid . '-' . $did;

                        $mode = (string)$r['value_mode'];
                        $fmt  = (string)$r['value_format'];
                        $unit = (string)($r['unit_label'] ?? '');
                        $dir  = (string)($r['success_direction'] ?? 'higher');
                        $forecastEnabled = (int)($r['forecast_enabled'] ?? 1) === 1;

                        $dec = default_decimals_for_format($fmt);
                        if ($hasDecimalsCol && isset($r['decimals']) && $r['decimals'] !== null && $r['decimals'] !== '') {
                            $dec = clamp_decimals((int)$r['decimals']);
                        }

                        $actual = $actualMap[$key] ?? null;

                        $autoYtdForCount = ($fmt === 'count' && $mode === 'mtd_ytd');

                        $ytdComputed = null;
                        if ($autoYtdForCount) {
                            $monthRaw = $actual ? (string)($actual[$kvColMtd] ?? '') : null;
                            $ytdComputed = calc_ytd_count_from_db(
                                $pdo, $mid, $did, $year, $month,
                                ($monthRaw !== null ? normalize_decimal($monthRaw) : null),
                                $kvHasPeriodMonth, $kvHasYearMonth, $kvColMtd
                            );
                        }

                        $headingId = "kpiHeading{$idx}";
                        $collapseId = "kpiCollapse{$idx}";

                        $doneThis = false;
                        if ($mode === 'single' || $mode === 'month') {
                            $doneThis = has_value($actual[$kvColSingle ?? $kvColMtd] ?? null);
                        } elseif ($mode === 'ytd') {
                            $doneThis = has_value($kvColYtd ? ($actual[$kvColYtd] ?? null) : ($actual[$kvColMtd] ?? null));
                        } else {
                            $mVal = $actual[$kvColMtd] ?? null;
                            $hasM = has_value($mVal);
                            if ($fmt === 'count') $doneThis = $hasM;
                            else $doneThis = $hasM && ($kvColYtd ? has_value($actual[$kvColYtd] ?? null) : true);
                        }
                    ?>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="<?= h($headingId) ?>">
                            <button class="accordion-button <?= $idx === 0 ? '' : 'collapsed' ?>" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#<?= h($collapseId) ?>"
                                    aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>" aria-controls="<?= h($collapseId) ?>">
                                <div class="d-flex flex-column w-100">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="me-3">
                                            <span class="text-muted small"><?= h($r['department_name']) ?></span><br>
                                            <span class="fw-semibold"><?= h($r['metric_name']) ?></span>
                                            <?php if ($unit !== ''): ?><span class="text-muted small"> · <?= h($unit) ?></span><?php endif; ?>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($doneThis): ?>
                                                <span class="badge bg-success">Utfylt</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Mangler</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-muted small mt-1">
                                        <?= h(mode_label($mode)) ?> · <?= h(fmt_label($fmt)) ?> · <?= h(dir_label($dir)) ?> · <?= (int)$dec ?> des
                                    </div>
                                </div>
                            </button>
                        </h2>

                        <div id="<?= h($collapseId) ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>"
                             aria-labelledby="<?= h($headingId) ?>" data-bs-parent="#kpiAccordion">
                            <div class="accordion-body">

                                <?php if (!empty($r['description'])): ?>
                                    <div class="text-muted small mb-2"><?= h((string)$r['description']) ?></div>
                                <?php endif; ?>

                                <form method="post" class="mb-0">
                                    <input type="hidden" name="action" value="save_actual">
                                    <input type="hidden" name="metric_id" value="<?= (int)$mid ?>">
                                    <input type="hidden" name="department_id" value="<?= (int)$did ?>">
                                    <input type="hidden" name="value_mode" value="<?= h($mode) ?>">
                                    <input type="hidden" name="year" value="<?= (int)$year ?>">
                                    <input type="hidden" name="month" value="<?= (int)$month ?>">

                                    <div class="row g-2">
                                        <?php if ($mode === 'single' || $mode === 'month'): ?>
                                            <div class="col-md-3">
                                                <label class="form-label">Månedsverdi</label>
                                                <input class="form-control" name="single_value" inputmode="decimal"
                                                       value="<?= h(fmt_input_value($actual[$kvColSingle ?? $kvColMtd] ?? '', $dec)) ?>">
                                            </div>

                                        <?php elseif ($mode === 'ytd'): ?>
                                            <div class="col-md-3">
                                                <label class="form-label">YTD (akkumulert)</label>
                                                <input class="form-control" name="ytd_value" inputmode="decimal"
                                                       value="<?= h(fmt_input_value(($kvColYtd ? ($actual[$kvColYtd] ?? '') : ($actual[$kvColMtd] ?? '')), $dec)) ?>">
                                            </div>

                                        <?php else: ?>
                                            <div class="col-md-3">
                                                <label class="form-label">Månedsverdi</label>
                                                <input class="form-control" name="mtd_value" inputmode="decimal"
                                                       value="<?= h(fmt_input_value($actual[$kvColMtd] ?? '', $dec)) ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">YTD (akkumulert)</label>
                                                <?php
                                                    $ytdVal = '';
                                                    if ($autoYtdForCount) {
                                                        $ytdVal = ($ytdComputed !== null) ? (string)$ytdComputed : '';
                                                    } else {
                                                        $ytdVal = $kvColYtd ? (string)($actual[$kvColYtd] ?? '') : '';
                                                    }
                                                    $cls = 'form-control';
                                                    if ($autoYtdForCount) $cls .= ' bg-light';
                                                ?>
                                                <input class="<?= h($cls) ?>" name="ytd_value" inputmode="decimal"
                                                       value="<?= h(fmt_input_value($ytdVal, $dec)) ?>"
                                                       <?= $autoYtdForCount ? 'readonly' : '' ?>>
                                            </div>
                                        <?php endif; ?>

                                        <div class="col-md-6">
                                            <label class="form-label">Kommentar</label>
                                            <input class="form-control" name="comment" value="<?= h($actual[$kvColComment] ?? '') ?>">
                                        </div>
                                    </div>

                                    <div class="mt-3 d-flex justify-content-end">
                                        <button class="btn btn-primary btn-sm">Lagre</button>
                                    </div>
                                </form>

                            </div>
                        </div>
                    </div>

                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>
