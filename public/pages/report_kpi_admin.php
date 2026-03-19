<?php
// /public/pages/report_kpi_admin.php
//
// KPI / Mål – Admin
// - Krever rolle: report_admin (eller admin-rolle i user_roles/session)
// - Oppsett av avdelinger
// - Oppsett av KPI-er (name, format, mode, standard kilde/metode)
// - Delegering: KPI + avdeling -> ansvarlig + stedfortreder
//
// NYTT (Prognose):
// - KPI kan ha retning: higher/lower (høyere er bedre / lavere er bedre)
// - KPI kan ha forecast_enabled (slå prognose på/av)
// - Bruker kpi_forecasts (månedlige prognoser)
//
// NYTT (Desimaler):
// - KPI kan ha decimals (0–6) for visning/avrunding i innrapportering + dashboard.
// - Vises kun hvis kolonnen finnes i kpi_metrics.
//
// NYTT (Årsmål):
// - KPI kan ha målverdi per år via kpi_targets (metric_id + target_year -> target_value)
//
// NYTT (KPI-kategorier/grupper):
// - KPI kan knyttes til en fast gruppe-liste (Arbeidsmiljø, Bedriftskultur, ...)
// - Lagrer i kpi_metrics.category hvis kolonnen finnes (robust)
//
// NYTT (Årsmål-desimaler):
// - Ved lagring av årsmål rundes/lagres målverdi med samme antall desimaler som KPIen
// - UI setter input step dynamisk basert på valgt KPI
//
// Robusthet:
// - Hvis noen prøver å opprette KPI med eksisterende navn, redirectes de til Rediger KPI
//   (i stedet for å kaste SQLSTATE 23000 duplicate key)

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

if (!function_exists('normalizeUsername')) {
    function normalizeUsername(string $u): string {
        $u = trim($u);
        if ($u === '') return '';

        // "DOMENE\bruker" -> "bruker"
        if (strpos($u, '\\') !== false) {
            $parts = explode('\\', $u);
            $u = (string)(end($parts) ?: $u);
        }
        // "bruker@domene" -> "bruker"
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

/**
 * Samle roller/perms/grupper fra session (bakoverkompatibelt)
 */
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

/**
 * Finn user_id basert på session-username (raw eller normalisert)
 */
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
 * Hent roller fra DB via user_roles (NB: user_roles har user_id, IKKE username)
 */
if (!function_exists('db_roles_for_user')) {
    function db_roles_for_user(PDO $pdo, string $sessionUsername): array {
        if (!table_exists($pdo, 'user_roles') || !table_exists($pdo, 'users')) return [];

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

/**
 * Total rolle-liste: session + DB, cachet i session for requesten
 */
if (!function_exists('get_session_roles')) {
    function get_session_roles(PDO $pdo, string $username): array {
        $sess = session_roles_list();
        $db   = db_roles_for_user($pdo, $username);

        $all = array_values(array_unique(array_merge($sess, $db)));

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

function fmt_label(string $fmt): string {
    return match ($fmt) {
        'percent'      => 'Prosent (%)',
        'nok'          => 'Beløp (NOK)',
        'nok_thousand' => 'Beløp (NOK i tusen)',
        default        => 'Antall / teller',
    };
}

function mode_label(string $mode): string {
    return $mode === 'single' ? 'Kun måned' : 'MTD + YTD';
}

function dir_label(?string $dir): string {
    return ($dir === 'lower') ? 'Lavere er bedre' : 'Høyere er bedre';
}

function default_decimals_for_format(string $fmt): int {
    return match ($fmt) {
        'count'        => 0,
        'percent'      => 2,
        'nok'          => 2,
        'nok_thousand' => 1,
        default        => 2,
    };
}

function clamp_decimals(int $d): int {
    if ($d < 0) return 0;
    if ($d > 6) return 6;
    return $d;
}

function fmt_num_local(?string $v, int $decimals): string {
    if ($v === null || trim($v) === '') return '';
    $f = (float)str_replace(',', '.', $v);
    return number_format($f, $decimals, ',', ' ');
}

function to_fixed_string(float $v, int $decimals): string {
    // Stabil lagring (punktum som desimalskilletegn, ingen tusenskille)
    return number_format($v, $decimals, '.', '');
}

function kpi_groups(): array {
    return [
        'Arbeidsmiljø',
        'Bedriftskultur',
        'Bærekraft',
        'Kunder',
        'Økonomi',
        'Medarbeiderutvikling',
        'Drift',
        'Marked',
        'Salg',
        'Leveranse',
        'Teknologi',
        'Infrastruktur',
    ];
}

function normalize_group(?string $g): ?string {
    $g = trim((string)$g);
    if ($g === '') return null;
    $allowed = kpi_groups();
    foreach ($allowed as $a) {
        if (mb_strtolower($a, 'UTF-8') === mb_strtolower($g, 'UTF-8')) return $a;
    }
    return null;
}

function metric_decimals(PDO $pdo, int $metricId, bool $hasDecimalsCol): int {
    try {
        $st = $pdo->prepare("SELECT value_format" . ($hasDecimalsCol ? ", decimals" : "") . " FROM kpi_metrics WHERE id=:id LIMIT 1");
        $st->execute([':id' => $metricId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) return 2;

        $vf = (string)($row['value_format'] ?? 'count');

        if ($hasDecimalsCol) {
            $dc = $row['decimals'];
            if ($dc !== null && $dc !== '') return clamp_decimals((int)$dc);
        }
        return default_decimals_for_format($vf);
    } catch (\Throwable $e) {
        return 2;
    }
}

// ---------------------------------------------------------
// Guard: innlogging
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

// Krev report_admin (eller admin)
if (!has_role($pdo, $username, 'report_admin')) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang (krever report_admin).</div>
    <?php
    return;
}

// Guard for nødvendige tabeller
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
        <div class="mt-2">Kjør migrering: <code>/db/migrations/20260202_kpi_module.sql</code></div>
    </div>
    <?php
    return;
}

// Prognose-støtte?
$hasForecastTable = table_exists($pdo, 'kpi_forecasts');
$hasDirCol        = column_exists($pdo, 'kpi_metrics', 'success_direction');
$hasEnCol         = column_exists($pdo, 'kpi_metrics', 'forecast_enabled');
$forecastReady    = $hasForecastTable && $hasDirCol && $hasEnCol;

// Desimal-støtte?
$hasDecimalsCol   = column_exists($pdo, 'kpi_metrics', 'decimals');

// KPI-kategori/grupper?
$hasCategoryCol   = column_exists($pdo, 'kpi_metrics', 'category');

// Årsmål-støtte?
$hasTargetsTable  = table_exists($pdo, 'kpi_targets');

$tab = $_GET['tab'] ?? 'assign';
$flash = '';
$flashType = 'success';

// Hent flash fra redirect (og tøm)
if (!empty($_SESSION['flash'])) {
    $flash = (string)$_SESSION['flash'];
    $flashType = (string)($_SESSION['flash_type'] ?? 'success');
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ---------------------------------------------------------
// Hent brukerliste (valgfritt)
// ---------------------------------------------------------
$users = [];
$usersTableOk = table_exists($pdo, 'users');
if ($usersTableOk) {
    try {
        $stmt = $pdo->query("
            SELECT
                username,
                COALESCE(NULLIF(display_name,''), username) AS label
            FROM users
            WHERE is_active = 1
            ORDER BY label ASC
        ");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $users = [];
        $usersTableOk = false;
    }
}

// ---------------------------------------------------------
// POST actions
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'dept_save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 0);
            $act  = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

            if ($name === '') throw new RuntimeException('Avdelingsnavn kan ikke være tomt.');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE kpi_departments SET name=:n, sort_order=:s, is_active=:a WHERE id=:id");
                $stmt->execute([':n'=>$name, ':s'=>$sort, ':a'=>$act, ':id'=>$id]);
                $flash = 'Avdeling oppdatert.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO kpi_departments (name, sort_order, is_active) VALUES (:n,:s,:a)");
                $stmt->execute([':n'=>$name, ':s'=>$sort, ':a'=>$act]);
                $flash = 'Avdeling opprettet.';
            }
            $tab = 'departments';
        }

        if ($action === 'dept_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Ugyldig avdeling.');
            $stmt = $pdo->prepare("DELETE FROM kpi_departments WHERE id=:id");
            $stmt->execute([':id'=>$id]);
            $flash = 'Avdeling slettet.';
            $tab = 'departments';
        }

        if ($action === 'metric_save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $mode = (string)($_POST['value_mode'] ?? 'mtd_ytd');
            $fmt  = (string)($_POST['value_format'] ?? 'count');
            $unit = trim((string)($_POST['unit_label'] ?? ''));
            $src  = trim((string)($_POST['default_source'] ?? ''));
            $mth  = trim((string)($_POST['default_method'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 0);
            $act  = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

            // NYTT (kategori)
            $cat = null;
            if ($hasCategoryCol) {
                $cat = normalize_group((string)($_POST['category'] ?? ''));
            }

            // NYTT (prognose)
            $dir  = (string)($_POST['success_direction'] ?? 'higher');
            $fen  = (int)($_POST['forecast_enabled'] ?? 1) === 1 ? 1 : 0;

            // NYTT (desimaler)
            $decimalsIn = $_POST['decimals'] ?? null;
            if ($decimalsIn === null || $decimalsIn === '') {
                $decimals = default_decimals_for_format($fmt);
            } else {
                $decimals = clamp_decimals((int)$decimalsIn);
            }

            if ($name === '') throw new RuntimeException('KPI-navn kan ikke være tomt.');
            if (!in_array($mode, ['mtd_ytd','single'], true)) throw new RuntimeException('Ugyldig value_mode.');
            if (!in_array($fmt, ['count','percent','nok','nok_thousand'], true)) throw new RuntimeException('Ugyldig value_format.');
            if (!in_array($dir, ['higher','lower'], true)) $dir = 'higher';

            $u = normalizeUsername($username);

            $canWriteForecastCols = $hasDirCol && $hasEnCol;

            // ---------------------------------------------------------
            // NYTT: Hindrer duplicate key ved "ny KPI" med eksisterende navn
            // ---------------------------------------------------------
            if ($id <= 0) {
                $chk = $pdo->prepare("SELECT id FROM kpi_metrics WHERE LOWER(name) = LOWER(:n) LIMIT 1");
                $chk->execute([':n' => $name]);
                $existingId = (int)($chk->fetchColumn() ?: 0);

                if ($existingId > 0) {
                    $_SESSION['flash'] = 'KPI-navnet finnes allerede. Du ble sendt til eksisterende KPI for redigering.';
                    $_SESSION['flash_type'] = 'warning';
                    header('Location: /?page=report_kpi_admin&tab=metrics&edit_metric=' . $existingId);
                    exit;
                }
            }

            // Bygg SQL dynamisk basert på kolonner
            $setCols = [
                'name=:n',
                'description=:d',
                'value_mode=:vm',
                'value_format=:vf',
                'unit_label=:u',
                'default_source=:s',
                'default_method=:m',
                'sort_order=:so',
                'is_active=:a',
            ];

            $params = [
                ':n'=>$name,
                ':d'=>($desc!==''?$desc:null),
                ':vm'=>$mode,
                ':vf'=>$fmt,
                ':u'=>$unit,
                ':s'=>($src!==''?$src:null),
                ':m'=>($mth!==''?$mth:null),
                ':so'=>$sort,
                ':a'=>$act,
            ];

            if ($hasDecimalsCol) {
                $setCols[] = 'decimals=:dc';
                $params[':dc'] = $decimals;
            }

            if ($hasCategoryCol) {
                $setCols[] = 'category=:cat';
                $params[':cat'] = $cat;
            }

            if ($canWriteForecastCols) {
                $setCols[] = 'success_direction=:sd';
                $setCols[] = 'forecast_enabled=:fe';
                $params[':sd'] = $dir;
                $params[':fe'] = $fen;
            }

            if ($id > 0) {
                $sql = "UPDATE kpi_metrics SET " . implode(', ', $setCols) . ", updated_by=:ub, updated_at=NOW() WHERE id=:id";
                $params[':ub'] = $u;
                $params[':id'] = $id;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $flash = 'KPI oppdatert.';
            } else {
                $cols = ['name','description','value_mode','value_format','unit_label','default_source','default_method','is_active','sort_order','created_by'];
                $vals = [':n',':d',':vm',':vf',':u',':s',':m',':a',':so',':cb'];
                $params[':cb'] = $u;

                if ($hasDecimalsCol) {
                    $cols[] = 'decimals';
                    $vals[] = ':dc';
                }
                if ($hasCategoryCol) {
                    $cols[] = 'category';
                    $vals[] = ':cat';
                }
                if ($canWriteForecastCols) {
                    $cols[] = 'success_direction';
                    $cols[] = 'forecast_enabled';
                    $vals[] = ':sd';
                    $vals[] = ':fe';
                }

                $sql = "INSERT INTO kpi_metrics (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $flash = 'KPI opprettet.';
            }

            $tab = 'metrics';
        }

        if ($action === 'metric_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Ugyldig KPI.');
            $stmt = $pdo->prepare("DELETE FROM kpi_metrics WHERE id=:id");
            $stmt->execute([':id'=>$id]);
            $flash = 'KPI slettet.';
            $tab = 'metrics';
        }

        if ($action === 'assign_save') {
            $id   = (int)($_POST['id'] ?? 0);
            $mid  = (int)($_POST['metric_id'] ?? 0);
            $did  = (int)($_POST['department_id'] ?? 0);
            $ru   = normalizeUsername(trim((string)($_POST['responsible_username'] ?? '')));
            $bu   = normalizeUsername(trim((string)($_POST['backup_username'] ?? '')));
            $act  = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

            if ($mid<=0 || $did<=0) throw new RuntimeException('Velg KPI og avdeling.');
            if ($ru==='') throw new RuntimeException('Ansvarlig kan ikke være tom.');

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE kpi_assignments
                    SET metric_id=:m, department_id=:d, responsible_username=:ru, backup_username=:bu, is_active=:a
                    WHERE id=:id
                ");
                $stmt->execute([
                    ':m'=>$mid, ':d'=>$did, ':ru'=>$ru, ':bu'=>($bu!==''?$bu:null), ':a'=>$act, ':id'=>$id
                ]);
                $flash = 'Ansvar/delegering oppdatert.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO kpi_assignments (metric_id, department_id, responsible_username, backup_username, is_active)
                    VALUES (:m,:d,:ru,:bu,:a)
                ");
                $stmt->execute([
                    ':m'=>$mid, ':d'=>$did, ':ru'=>$ru, ':bu'=>($bu!==''?$bu:null), ':a'=>$act
                ]);
                $flash = 'Ansvar/delegering opprettet.';
            }
            $tab = 'assign';
        }

        if ($action === 'assign_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new RuntimeException('Ugyldig delegering.');
            $stmt = $pdo->prepare("DELETE FROM kpi_assignments WHERE id=:id");
            $stmt->execute([':id'=>$id]);
            $flash = 'Delegering slettet.';
            $tab = 'assign';
        }

        // ---------------------------------------------------------
        // ÅRSMÅL: opprett/endre/slette
        // ---------------------------------------------------------
        if ($action === 'target_save') {
            if (!$hasTargetsTable) {
                throw new RuntimeException('Årsmål-tabellen mangler. Kjør migrering: /db/migrations/20260202_kpi_targets.sql');
            }

            $id   = (int)($_POST['id'] ?? 0);
            $mid  = (int)($_POST['metric_id'] ?? 0);
            $year = (int)($_POST['target_year'] ?? 0);
            $valS = normalize_decimal((string)($_POST['target_value'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));

            if ($mid <= 0) throw new RuntimeException('Velg KPI.');
            if ($year < 2000 || $year > 2100) throw new RuntimeException('Ugyldig år (må være 2000–2100).');
            if ($valS === null || !is_numeric($valS)) throw new RuntimeException('Ugyldig målverdi.');

            // NYTT: avrund/lagre målverdi med KPIens desimaler
            $dec = metric_decimals($pdo, $mid, $hasDecimalsCol);
            $valFloat = (float)$valS;
            $valStore = to_fixed_string(round($valFloat, $dec), $dec);

            $u = normalizeUsername($username);

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE kpi_targets
                       SET metric_id=:m,
                           target_year=:y,
                           target_value=:v,
                           note=:n,
                           updated_by=:ub,
                           updated_at=NOW()
                     WHERE id=:id
                ");
                $stmt->execute([
                    ':m'=>$mid,
                    ':y'=>$year,
                    ':v'=>$valStore,
                    ':n'=>($note!==''?$note:null),
                    ':ub'=>$u,
                    ':id'=>$id
                ]);
                $flash = 'Årsmål oppdatert.';
            } else {
                // Unik nøkkel: (metric_id, target_year) – hvis finnes, oppdater
                $stmt = $pdo->prepare("
                    INSERT INTO kpi_targets (metric_id, target_year, target_value, note, created_by, created_at, updated_by, updated_at)
                    VALUES (:m, :y, :v, :n, :cb, NOW(), :ub, NOW())
                    ON DUPLICATE KEY UPDATE
                        target_value = VALUES(target_value),
                        note         = VALUES(note),
                        updated_by   = VALUES(updated_by),
                        updated_at   = NOW()
                ");
                $stmt->execute([
                    ':m'=>$mid,
                    ':y'=>$year,
                    ':v'=>$valStore,
                    ':n'=>($note!==''?$note:null),
                    ':cb'=>$u,
                    ':ub'=>$u
                ]);
                $flash = 'Årsmål lagret.';
            }

            $tab = 'targets';
        }

        if ($action === 'target_delete') {
            if (!$hasTargetsTable) {
                throw new RuntimeException('Årsmål-tabellen mangler. Kjør migrering: /db/migrations/20260202_kpi_targets.sql');
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new RuntimeException('Ugyldig årsmål.');
            $stmt = $pdo->prepare("DELETE FROM kpi_targets WHERE id=:id");
            $stmt->execute([':id'=>$id]);
            $flash = 'Årsmål slettet.';
            $tab = 'targets';
        }

    } catch (\Throwable $e) {
        $flashType = 'danger';
        $flash = $e->getMessage();
    }
}

// ---------------------------------------------------------
// Data til UI
// ---------------------------------------------------------
$departments = $pdo->query("SELECT * FROM kpi_departments ORDER BY is_active DESC, sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$metrics     = $pdo->query("SELECT * FROM kpi_metrics ORDER BY is_active DESC, sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$assignments = $pdo->query("
    SELECT a.*,
           d.name AS department_name,
           m.name AS metric_name,
           m.value_mode,
           m.value_format,
           m.unit_label
           " . ($hasCategoryCol ? ", m.category" : "") . "
           " . ($hasDecimalsCol ? ", m.decimals" : "") . "
    FROM kpi_assignments a
    JOIN kpi_departments d ON d.id = a.department_id
    JOIN kpi_metrics m ON m.id = a.metric_id
    ORDER BY a.is_active DESC, d.sort_order ASC, d.name ASC, m.sort_order ASC, m.name ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$targets = [];
if ($hasTargetsTable) {
    $targets = $pdo->query("
        SELECT
            t.*,
            m.name AS metric_name,
            m.value_format,
            m.value_mode,
            m.unit_label,
            " . ($hasDecimalsCol ? "m.decimals" : "NULL AS decimals") . "
            " . ($hasCategoryCol ? ", m.category" : "") . "
        FROM kpi_targets t
        JOIN kpi_metrics m ON m.id = t.metric_id
        ORDER BY t.target_year DESC, m.sort_order ASC, m.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$editDept = null;
$editMetric = null;
$editAssign = null;
$editTarget = null;

if (isset($_GET['edit_dept'])) {
    $id = (int)$_GET['edit_dept'];
    foreach ($departments as $d) if ((int)$d['id'] === $id) $editDept = $d;
    $tab = 'departments';
}
if (isset($_GET['edit_metric'])) {
    $id = (int)$_GET['edit_metric'];
    foreach ($metrics as $m) if ((int)$m['id'] === $id) $editMetric = $m;
    $tab = 'metrics';
}
if (isset($_GET['edit_assign'])) {
    $id = (int)$_GET['edit_assign'];
    foreach ($assignments as $a) if ((int)$a['id'] === $id) $editAssign = $a;
    $tab = 'assign';
}
if (isset($_GET['edit_target'])) {
    $id = (int)$_GET['edit_target'];
    foreach ($targets as $t) if ((int)$t['id'] === $id) $editTarget = $t;
    $tab = 'targets';
}

$yearNow = (int)date('Y');

?>
<div class="d-flex align-items-center justify-content-between mt-3 mb-2">
    <div>
        <h3 class="mb-0">KPI / Mål – Admin</h3>
        <div class="text-muted">Administrer KPI-er, avdelinger, deleger ansvar og årsmål.</div>
    </div>
    <div class="text-end">
        <a class="btn btn-outline-secondary btn-sm" href="/?page=report_kpi_entry">Innrapportering</a>
        <a class="btn btn-outline-secondary btn-sm" href="/?page=report_kpi_dashboard">Dashboard</a>
    </div>
</div>

<?php if (!$forecastReady): ?>
    <div class="alert alert-warning mt-2">
        <div class="fw-semibold">Prognose er ikke aktivert i databasen.</div>
        <div class="small">Kjør migrering: <code>/db/migrations/20260202_kpi_forecast.sql</code></div>
        <div class="small text-muted mt-1">Du kan fortsatt administrere KPI-er, men felt for “retning/prognose” vises først etter migrering.</div>
    </div>
<?php endif; ?>

<?php if (!$hasDecimalsCol): ?>
    <div class="alert alert-info mt-2">
        <div class="fw-semibold">Desimalvalg er ikke aktivert i databasen.</div>
        <div class="small">Legg til kolonnen <code>kpi_metrics.decimals</code> (0–6) for å kunne velge antall desimaler per KPI.</div>
    </div>
<?php endif; ?>

<?php if (!$hasCategoryCol): ?>
    <div class="alert alert-info mt-2">
        <div class="fw-semibold">KPI-grupper/kategorier er ikke aktivert i databasen.</div>
        <div class="small">Legg til kolonnen <code>kpi_metrics.category</code> (f.eks. VARCHAR(50)) for å kunne velge gruppe per KPI.</div>
    </div>
<?php endif; ?>

<?php if (!$hasTargetsTable): ?>
    <div class="alert alert-info mt-2">
        <div class="fw-semibold">Årsmål er ikke aktivert i databasen.</div>
        <div class="small">Kjør migrering: <code>/db/migrations/20260202_kpi_targets.sql</code></div>
        <div class="small text-muted mt-1">Når tabellen finnes, får du en egen fane for å registrere mål per KPI per år.</div>
    </div>
<?php endif; ?>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?= h($flashType) ?> mt-2"><?= h($flash) ?></div>
<?php endif; ?>

<ul class="nav nav-tabs mt-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab==='assign'?'active':'' ?>" href="/?page=report_kpi_admin&tab=assign">Delegering</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='metrics'?'active':'' ?>" href="/?page=report_kpi_admin&tab=metrics">KPI-definisjoner</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='targets'?'active':'' ?>" href="/?page=report_kpi_admin&tab=targets">Årsmål</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='departments'?'active':'' ?>" href="/?page=report_kpi_admin&tab=departments">Avdelinger</a>
    </li>
</ul>

<div class="card border-0 shadow-sm mt-3">
    <div class="card-body">
        <?php if ($tab === 'departments'): ?>
            <div class="row g-4">
                <div class="col-lg-5">
                    <h5 class="mb-3"><?= $editDept ? 'Rediger avdeling' : 'Ny avdeling' ?></h5>
                    <form method="post">
                        <input type="hidden" name="action" value="dept_save">
                        <input type="hidden" name="id" value="<?= (int)($editDept['id'] ?? 0) ?>">
                        <div class="mb-2">
                            <label class="form-label">Navn</label>
                            <input class="form-control" name="name" value="<?= h($editDept['name'] ?? '') ?>" required>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label">Sortering</label>
                                <input class="form-control" type="number" name="sort_order" value="<?= (int)($editDept['sort_order'] ?? 0) ?>">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Aktiv</label>
                                <select class="form-select" name="is_active">
                                    <?php $v = (int)($editDept['is_active'] ?? 1); ?>
                                    <option value="1" <?= $v===1?'selected':'' ?>>Ja</option>
                                    <option value="0" <?= $v===0?'selected':'' ?>>Nei</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3 d-flex gap-2">
                            <button class="btn btn-primary" type="submit">Lagre</button>
                            <?php if ($editDept): ?>
                                <a class="btn btn-outline-secondary" href="/?page=report_kpi_admin&tab=departments">Avbryt</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="col-lg-7">
                    <h5 class="mb-3">Avdelinger</h5>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Navn</th>
                                    <th class="text-muted">Sort</th>
                                    <th class="text-muted">Aktiv</th>
                                    <th class="text-end">Handling</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($departments as $d): ?>
                                <tr>
                                    <td><?= h($d['name']) ?></td>
                                    <td class="text-muted"><?= (int)$d['sort_order'] ?></td>
                                    <td class="text-muted"><?= ((int)$d['is_active']===1) ? 'Ja' : 'Nei' ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-outline-primary btn-sm" href="/?page=report_kpi_admin&tab=departments&edit_dept=<?= (int)$d['id'] ?>">Rediger</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Slette avdeling? Dette kan også slette delegeringer/verdier knyttet til avdelingen.');">
                                            <input type="hidden" name="action" value="dept_delete">
                                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">Slett</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$departments): ?>
                                <tr><td colspan="4" class="text-muted">Ingen avdelinger enda.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'metrics'): ?>
            <div class="row g-4">
                <div class="col-lg-5">
                    <h5 class="mb-3"><?= $editMetric ? 'Rediger KPI' : 'Ny KPI' ?></h5>
                    <form method="post">
                        <input type="hidden" name="action" value="metric_save">
                        <input type="hidden" name="id" value="<?= (int)($editMetric['id'] ?? 0) ?>">

                        <div class="mb-2">
                            <label class="form-label">Navn</label>
                            <input class="form-control" name="name" value="<?= h($editMetric['name'] ?? '') ?>" required>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Beskrivelse</label>
                            <textarea class="form-control" name="description" rows="2"><?= h($editMetric['description'] ?? '') ?></textarea>
                        </div>

                        <?php if ($hasCategoryCol): ?>
                            <div class="mb-2">
                                <label class="form-label">Gruppe</label>
                                <?php $selCat = (string)($editMetric['category'] ?? ''); ?>
                                <select class="form-select" name="category">
                                    <option value="">Ingen / ikke valgt</option>
                                    <?php foreach (kpi_groups() as $g): ?>
                                        <option value="<?= h($g) ?>" <?= (mb_strtolower($selCat,'UTF-8')===mb_strtolower($g,'UTF-8'))?'selected':'' ?>>
                                            <?= h($g) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Brukes for å gruppere KPI-definisjoner i rapportering/dashboards senere.</div>
                            </div>
                        <?php endif; ?>

                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">Inndata</label>
                                <?php $vm = (string)($editMetric['value_mode'] ?? 'mtd_ytd'); ?>
                                <select class="form-select" name="value_mode">
                                    <option value="mtd_ytd" <?= $vm==='mtd_ytd'?'selected':'' ?>>MTD + YTD</option>
                                    <option value="single" <?= $vm==='single'?'selected':'' ?>>Kun måned (én verdi)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Format</label>
                                <?php $vf = (string)($editMetric['value_format'] ?? 'count'); ?>
                                <select class="form-select" name="value_format">
                                    <option value="count" <?= $vf==='count'?'selected':'' ?>>Antall / teller</option>
                                    <option value="percent" <?= $vf==='percent'?'selected':'' ?>>Prosent (%)</option>
                                    <option value="nok" <?= $vf==='nok'?'selected':'' ?>>Beløp (NOK)</option>
                                    <option value="nok_thousand" <?= $vf==='nok_thousand'?'selected':'' ?>>Beløp (NOK i tusen)</option>
                                </select>
                            </div>
                        </div>

                        <?php if ($hasDecimalsCol): ?>
                            <div class="row g-2 mt-2">
                                <div class="col-md-6">
                                    <label class="form-label">Desimaler (visning)</label>
                                    <?php
                                        $dc = isset($editMetric['decimals']) ? clamp_decimals((int)$editMetric['decimals']) : default_decimals_for_format($vf);
                                    ?>
                                    <select class="form-select" name="decimals">
                                        <?php for ($i=0;$i<=6;$i++): ?>
                                            <option value="<?= $i ?>" <?= $dc===$i?'selected':'' ?>><?= $i ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <div class="form-text">Brukes når tall formateres i innrapportering, dashboard og årsmål.</div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($forecastReady): ?>
                            <div class="row g-2 mt-2">
                                <div class="col-md-6">
                                    <label class="form-label">Retning (resultat)</label>
                                    <?php $sd = (string)($editMetric['success_direction'] ?? 'higher'); ?>
                                    <select class="form-select" name="success_direction">
                                        <option value="higher" <?= $sd==='higher'?'selected':'' ?>>Høyere er bedre</option>
                                        <option value="lower" <?= $sd==='lower'?'selected':'' ?>>Lavere er bedre</option>
                                    </select>
                                    <div class="form-text">Brukes når avvik/resultat beregnes mot prognose.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Prognose</label>
                                    <?php $fe = (int)($editMetric['forecast_enabled'] ?? 1); ?>
                                    <select class="form-select" name="forecast_enabled">
                                        <option value="1" <?= $fe===1?'selected':'' ?>>Aktiv</option>
                                        <option value="0" <?= $fe===0?'selected':'' ?>>Av</option>
                                    </select>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="row g-2 mt-2">
                            <div class="col-md-6">
                                <label class="form-label">Enhet-tekst (visning)</label>
                                <input class="form-control" name="unit_label" placeholder="f.eks. %, NOK, stk" value="<?= h($editMetric['unit_label'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sortering</label>
                                <input class="form-control" type="number" name="sort_order" value="<?= (int)($editMetric['sort_order'] ?? 0) ?>">
                            </div>
                        </div>

                        <div class="mb-2 mt-2">
                            <label class="form-label">Standard: Kilde (hentes fra)</label>
                            <textarea class="form-control" name="default_source" rows="2"><?= h($editMetric['default_source'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Standard: Metode (hvordan)</label>
                            <textarea class="form-control" name="default_method" rows="2"><?= h($editMetric['default_method'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Aktiv</label>
                            <?php $a = (int)($editMetric['is_active'] ?? 1); ?>
                            <select class="form-select" name="is_active">
                                <option value="1" <?= $a===1?'selected':'' ?>>Ja</option>
                                <option value="0" <?= $a===0?'selected':'' ?>>Nei</option>
                            </select>
                        </div>

                        <div class="mt-3 d-flex gap-2">
                            <button class="btn btn-primary" type="submit">Lagre</button>
                            <?php if ($editMetric): ?>
                                <a class="btn btn-outline-secondary" href="/?page=report_kpi_admin&tab=metrics">Avbryt</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="col-lg-7">
                    <h5 class="mb-3">KPI-definisjoner</h5>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>KPI</th>
                                    <?php if ($hasCategoryCol): ?>
                                        <th class="text-muted">Gruppe</th>
                                    <?php endif; ?>
                                    <th class="text-muted">Inndata</th>
                                    <th class="text-muted">Format</th>
                                    <?php if ($hasDecimalsCol): ?>
                                        <th class="text-muted">Des</th>
                                    <?php endif; ?>
                                    <?php if ($forecastReady): ?>
                                        <th class="text-muted">Retning</th>
                                        <th class="text-muted">Prognose</th>
                                    <?php endif; ?>
                                    <th class="text-muted">Aktiv</th>
                                    <th class="text-end">Handling</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($metrics as $m): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= h($m['name']) ?></div>
                                        <?php if (!empty($m['description'])): ?>
                                            <div class="text-muted small"><?= h($m['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($hasCategoryCol): ?>
                                        <td class="text-muted"><?= h((string)($m['category'] ?? '')) ?></td>
                                    <?php endif; ?>
                                    <td class="text-muted"><?= h(mode_label((string)$m['value_mode'])) ?></td>
                                    <td class="text-muted"><?= h(fmt_label((string)$m['value_format'])) ?></td>
                                    <?php if ($hasDecimalsCol): ?>
                                        <td class="text-muted">
                                            <?= h((string)clamp_decimals((int)($m['decimals'] ?? default_decimals_for_format((string)$m['value_format'])))) ?>
                                        </td>
                                    <?php endif; ?>
                                    <?php if ($forecastReady): ?>
                                        <td class="text-muted"><?= h(dir_label((string)($m['success_direction'] ?? 'higher'))) ?></td>
                                        <td class="text-muted"><?= ((int)($m['forecast_enabled'] ?? 1)===1) ? 'På' : 'Av' ?></td>
                                    <?php endif; ?>
                                    <td class="text-muted"><?= ((int)$m['is_active']===1) ? 'Ja' : 'Nei' ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-outline-primary btn-sm" href="/?page=report_kpi_admin&tab=metrics&edit_metric=<?= (int)$m['id'] ?>">Rediger</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Slette KPI? Dette sletter også delegeringer og innrapporterte verdier.');">
                                            <input type="hidden" name="action" value="metric_delete">
                                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">Slett</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php
                                $colspan = 5;
                                if ($hasCategoryCol) $colspan++;
                                if ($hasDecimalsCol) $colspan++;
                                if ($forecastReady) $colspan += 2;
                            ?>
                            <?php if (!$metrics): ?>
                                <tr><td colspan="<?= (int)$colspan ?>" class="text-muted">Ingen KPI-er enda.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'targets'): ?>
            <div class="row g-4">
                <div class="col-lg-5">
                    <h5 class="mb-3"><?= $editTarget ? 'Rediger årsmål' : 'Nytt årsmål' ?></h5>

                    <?php if (!$hasTargetsTable): ?>
                        <div class="alert alert-warning">
                            Årsmål er ikke aktivert. Kjør migrering: <code>/db/migrations/20260202_kpi_targets.sql</code>
                        </div>
                    <?php elseif (!$metrics): ?>
                        <div class="alert alert-warning">
                            Du må opprette minst én KPI før du kan legge inn årsmål.
                        </div>
                    <?php else: ?>
                        <form method="post" id="targetForm">
                            <input type="hidden" name="action" value="target_save">
                            <input type="hidden" name="id" value="<?= (int)($editTarget['id'] ?? 0) ?>">

                            <div class="mb-2">
                                <label class="form-label">KPI</label>
                                <?php $selM = (int)($editTarget['metric_id'] ?? 0); ?>
                                <select class="form-select" name="metric_id" id="targetMetric" required>
                                    <option value="">Velg KPI…</option>
                                    <?php foreach ($metrics as $m): ?>
                                        <?php
                                            $vfOpt = (string)$m['value_format'];
                                            $decOpt = $hasDecimalsCol
                                                ? clamp_decimals((int)($m['decimals'] ?? default_decimals_for_format($vfOpt)))
                                                : default_decimals_for_format($vfOpt);
                                            $unitOpt = trim((string)($m['unit_label'] ?? ''));
                                            $catOpt  = $hasCategoryCol ? trim((string)($m['category'] ?? '')) : '';
                                            $labelExtra = [];
                                            if ($hasCategoryCol && $catOpt !== '') $labelExtra[] = $catOpt;
                                            $labelExtra[] = fmt_label($vfOpt);
                                            $labelExtra[] = 'des: ' . (int)$decOpt;
                                            if ($unitOpt !== '') $labelExtra[] = $unitOpt;
                                        ?>
                                        <option
                                            value="<?= (int)$m['id'] ?>"
                                            <?= $selM===(int)$m['id']?'selected':'' ?>
                                            data-decimals="<?= (int)$decOpt ?>"
                                            data-unit="<?= h($unitOpt) ?>"
                                        >
                                            <?= h($m['name']) ?> (<?= h(implode(', ', $labelExtra)) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Mål er unikt per KPI + år (lagrer/oppdaterer samme kombinasjon).</div>
                            </div>

                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label">År</label>
                                    <input class="form-control" type="number" name="target_year" min="2000" max="2100"
                                           value="<?= (int)($editTarget['target_year'] ?? $yearNow) ?>" required>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Målverdi</label>
                                    <input
                                        class="form-control"
                                        name="target_value"
                                        id="targetValue"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        value="<?= h((string)($editTarget['target_value'] ?? '')) ?>"
                                        placeholder="f.eks. 98,5 / 120000 / 45"
                                        required
                                    >
                                    <div class="form-text" id="targetHelp">Du kan bruke komma eller punktum. Verdien lagres avrundet iht. KPIens desimaler.</div>
                                </div>
                            </div>

                            <div class="mb-2 mt-2">
                                <label class="form-label">Notat (valgfritt)</label>
                                <textarea class="form-control" name="note" rows="2"><?= h((string)($editTarget['note'] ?? '')) ?></textarea>
                            </div>

                            <div class="mt-3 d-flex gap-2">
                                <button class="btn btn-primary" type="submit">Lagre</button>
                                <?php if ($editTarget): ?>
                                    <a class="btn btn-outline-secondary" href="/?page=report_kpi_admin&tab=targets">Avbryt</a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <script>
                        (function(){
                            function stepForDecimals(d){
                                d = parseInt(d || '0', 10);
                                if (!isFinite(d) || d < 0) d = 0;
                                if (d === 0) return '1';
                                return '0.' + '0'.repeat(Math.max(0, d-1)) + '1';
                            }
                            function applyMetricDecimals(){
                                var sel = document.getElementById('targetMetric');
                                var inp = document.getElementById('targetValue');
                                var help = document.getElementById('targetHelp');
                                if(!sel || !inp) return;

                                var opt = sel.options[sel.selectedIndex];
                                var dec = opt ? opt.getAttribute('data-decimals') : '2';
                                var unit = opt ? (opt.getAttribute('data-unit') || '') : '';
                                var step = stepForDecimals(dec);

                                inp.setAttribute('step', step);

                                if (help) {
                                    help.textContent = 'Du kan bruke komma eller punktum. Verdien lagres avrundet iht. KPIens desimaler (' + dec + ').' + (unit ? (' Enhet: ' + unit + '.') : '');
                                }
                            }
                            document.addEventListener('change', function(e){
                                if(e.target && e.target.id === 'targetMetric') applyMetricDecimals();
                            });
                            applyMetricDecimals();
                        })();
                        </script>
                    <?php endif; ?>
                </div>

                <div class="col-lg-7">
                    <h5 class="mb-3">Årsmål</h5>

                    <?php if ($hasTargetsTable): ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>År</th>
                                        <th>KPI</th>
                                        <th class="text-muted">Mål</th>
                                        <th class="text-muted">Notat</th>
                                        <th class="text-muted">Sist endret</th>
                                        <th class="text-end">Handling</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($targets as $t): ?>
                                    <?php
                                        $vf = (string)($t['value_format'] ?? 'count');
                                        $dec = $hasDecimalsCol
                                            ? clamp_decimals((int)($t['decimals'] ?? default_decimals_for_format($vf)))
                                            : default_decimals_for_format($vf);

                                        $unit = trim((string)($t['unit_label'] ?? ''));
                                        $valDisplay = fmt_num_local((string)$t['target_value'], $dec);
                                        if ($unit !== '') $valDisplay .= ' ' . $unit;

                                        $cat = $hasCategoryCol ? trim((string)($t['category'] ?? '')) : '';
                                    ?>
                                    <tr>
                                        <td class="text-muted"><?= (int)$t['target_year'] ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= h((string)$t['metric_name']) ?></div>
                                            <div class="text-muted small">
                                                <?= $cat !== '' ? h($cat) . ' · ' : '' ?>
                                                <?= h(fmt_label($vf)) ?> · <?= h(mode_label((string)($t['value_mode'] ?? 'mtd_ytd'))) ?>
                                                · des: <?= (int)$dec ?>
                                            </div>
                                        </td>
                                        <td class="text-muted"><?= h($valDisplay) ?></td>
                                        <td class="text-muted"><?= h((string)($t['note'] ?? '')) ?></td>
                                        <td class="text-muted small">
                                            <?php
                                                $ua = (string)($t['updated_at'] ?? '');
                                                $ub = (string)($t['updated_by'] ?? '');
                                                if ($ua !== '') {
                                                    echo h($ua) . ($ub !== '' ? '<br><span class="text-muted">av '.h($ub).'</span>' : '');
                                                } else {
                                                    $ca = (string)($t['created_at'] ?? '');
                                                    $cb = (string)($t['created_by'] ?? '');
                                                    echo $ca !== '' ? h($ca) . ($cb !== '' ? '<br><span class="text-muted">av '.h($cb).'</span>' : '') : '';
                                                }
                                            ?>
                                        </td>
                                        <td class="text-end">
                                            <a class="btn btn-outline-primary btn-sm" href="/?page=report_kpi_admin&tab=targets&edit_target=<?= (int)$t['id'] ?>">Rediger</a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Slette årsmål?');">
                                                <input type="hidden" name="action" value="target_delete">
                                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Slett</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$targets): ?>
                                    <tr><td colspan="6" class="text-muted">Ingen årsmål enda.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="alert alert-info mt-3 mb-0">
                            Tips: Når du har lagt inn mål for inneværende år (<?= (int)$yearNow ?>), kan vi vise “på rett spor?” i dashboard ved å sammenligne YTD (eller måned) mot årsmål.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <div class="row g-4">
                <div class="col-lg-5">
                    <h5 class="mb-3"><?= $editAssign ? 'Rediger delegering' : 'Ny delegering' ?></h5>

                    <?php if (!$departments || !$metrics): ?>
                        <div class="alert alert-warning">
                            Du må opprette minst én avdeling og én KPI før du kan delegere ansvar.
                        </div>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="action" value="assign_save">
                            <input type="hidden" name="id" value="<?= (int)($editAssign['id'] ?? 0) ?>">

                            <div class="mb-2">
                                <label class="form-label">KPI</label>
                                <?php $selM = (int)($editAssign['metric_id'] ?? 0); ?>
                                <select class="form-select" name="metric_id" required>
                                    <option value="">Velg KPI…</option>
                                    <?php foreach ($metrics as $m): ?>
                                        <?php
                                            $cat = $hasCategoryCol ? trim((string)($m['category'] ?? '')) : '';
                                            $extra = [];
                                            if ($cat !== '') $extra[] = $cat;
                                            $extra[] = mode_label((string)$m['value_mode']);
                                            $extra[] = fmt_label((string)$m['value_format']);
                                        ?>
                                        <option value="<?= (int)$m['id'] ?>" <?= $selM===(int)$m['id']?'selected':'' ?>>
                                            <?= h($m['name']) ?> (<?= h(implode(', ', $extra)) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Avdeling</label>
                                <?php $selD = (int)($editAssign['department_id'] ?? 0); ?>
                                <select class="form-select" name="department_id" required>
                                    <option value="">Velg avdeling…</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>" <?= $selD===(int)$d['id']?'selected':'' ?>>
                                            <?= h($d['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Ansvarlig (username)</label>
                                <?php $ru = (string)($editAssign['responsible_username'] ?? ''); ?>
                                <?php if ($usersTableOk && $users): ?>
                                    <select class="form-select" name="responsible_username" required>
                                        <option value="">Velg bruker…</option>
                                        <?php foreach ($users as $uRow): ?>
                                            <option value="<?= h($uRow['username']) ?>" <?= $ru===$uRow['username']?'selected':'' ?>>
                                                <?= h($uRow['label']) ?> (<?= h($uRow['username']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input class="form-control" name="responsible_username" value="<?= h($ru) ?>" required>
                                    <div class="form-text">Fant ikke users-tabell (eller tom). Tast inn username manuelt.</div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Stedfortreder (valgfritt)</label>
                                <?php $bu = (string)($editAssign['backup_username'] ?? ''); ?>
                                <?php if ($usersTableOk && $users): ?>
                                    <select class="form-select" name="backup_username">
                                        <option value="">Ingen</option>
                                        <?php foreach ($users as $uRow): ?>
                                            <option value="<?= h($uRow['username']) ?>" <?= $bu===$uRow['username']?'selected':'' ?>>
                                                <?= h($uRow['label']) ?> (<?= h($uRow['username']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input class="form-control" name="backup_username" value="<?= h($bu) ?>">
                                <?php endif; ?>
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Aktiv</label>
                                <?php $a = (int)($editAssign['is_active'] ?? 1); ?>
                                <select class="form-select" name="is_active">
                                    <option value="1" <?= $a===1?'selected':'' ?>>Ja</option>
                                    <option value="0" <?= $a===0?'selected':'' ?>>Nei</option>
                                </select>
                            </div>

                            <div class="mt-3 d-flex gap-2">
                                <button class="btn btn-primary" type="submit">Lagre</button>
                                <?php if ($editAssign): ?>
                                    <a class="btn btn-outline-secondary" href="/?page=report_kpi_admin&tab=assign">Avbryt</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="col-lg-7">
                    <h5 class="mb-3">Delegeringer</h5>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Avdeling</th>
                                    <th>KPI</th>
                                    <th class="text-muted">Ansvar</th>
                                    <th class="text-muted">Aktiv</th>
                                    <th class="text-end">Handling</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($assignments as $aRow): ?>
                                <?php
                                    $cat = $hasCategoryCol ? trim((string)($aRow['category'] ?? '')) : '';
                                    $vf = (string)($aRow['value_format'] ?? 'count');
                                    $dec = $hasDecimalsCol
                                        ? clamp_decimals((int)($aRow['decimals'] ?? default_decimals_for_format($vf)))
                                        : default_decimals_for_format($vf);
                                ?>
                                <tr>
                                    <td><?= h($aRow['department_name']) ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= h($aRow['metric_name']) ?></div>
                                        <div class="text-muted small">
                                            <?= $cat !== '' ? h($cat) . ' · ' : '' ?>
                                            <?= h(mode_label((string)$aRow['value_mode'])) ?> · <?= h(fmt_label((string)$aRow['value_format'])) ?>
                                            · des: <?= (int)$dec ?>
                                            <?= $aRow['unit_label'] ? ' · '.h((string)$aRow['unit_label']) : '' ?>
                                        </div>
                                    </td>
                                    <td class="text-muted">
                                        <div><?= h($aRow['responsible_username']) ?></div>
                                        <?php if (!empty($aRow['backup_username'])): ?>
                                            <div class="small">Stedfortreder: <?= h($aRow['backup_username']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted"><?= ((int)$aRow['is_active']===1) ? 'Ja' : 'Nei' ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-outline-primary btn-sm" href="/?page=report_kpi_admin&tab=assign&edit_assign=<?= (int)$aRow['id'] ?>">Rediger</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Slette delegering?');">
                                            <input type="hidden" name="action" value="assign_delete">
                                            <input type="hidden" name="id" value="<?= (int)$aRow['id'] ?>">
                                            <button class="btn btn-outline-danger btn-sm" type="submit">Slett</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$assignments): ?>
                                <tr><td colspan="5" class="text-muted">Ingen delegeringer enda.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        Tips: Gi brukere som skal rapportere rollen <code>report_user</code>. Admin må ha <code>report_admin</code>.
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
