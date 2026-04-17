<?php
// /public/pages/contracts.php
//
// Avtaler & kontrakter – startsiden / oversikt
// - Skal kun være metadata/oversikt (ingen selve avtaleteksten)
// - Link-felt peker til hvor avtalen ligger (Teams/SharePoint/filområde/etc.)
// - Robust: hvis DB-tabell/kolonner ikke finnes enda, vises "tom" UI + hint.
//
// RETTIGHETER (OPPDATERT):
// - Rolle/gruppe for les:  contracts_read
// - Rolle/gruppe for skriv: contracts_write
// - Admin har ALLTID tilgang
// - Rettigheter kan komme fra:
//   A) Session (permissions/roles/groups/user_groups/ad_groups)
//   B) DB (user_roles for innlogget bruker)
// - Vi slår sammen session + DB til ett "effektivt" sett, slik at du ikke låses ute
//   når session inneholder AD-grupper men rettighetene ligger i DB.
//
// UI/UX OPPDATERT:
// - Filterfelt mer kompakt (side-by-side)
// - Klikk på hele raden for å åpne contracts_view
// - Egen "Innstillinger"-knapp (btn-info btn-sm)
// - Sortering på alle kolonner via overskrifter (sort/dir)
// - Fjernet "ID:" under tittel
// - Ansvarlig vises som fullt navn (users.fullname) med fallback til username

use App\Database;

// ---------------------------------------------------------
// Guard: må være innlogget
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Rettighets-helper
// ---------------------------------------------------------
if (!function_exists('contracts_normalize_username')) {
    function contracts_normalize_username(string $u): string {
        $u = trim($u);
        if ($u === '') return '';

        if (strpos($u, '\\') !== false) {
            $parts = explode('\\', $u);
            $u = end($parts) ?: $u;
        }
        if (strpos($u, '@') !== false) {
            $u = explode('@', $u)[0] ?: $u;
        }
        return trim($u);
    }
}

if (!function_exists('contracts_session_list')) {
    /**
     * Returnerer liste over "grupper/roller/permissions" fra session på tvers av mulige nøkler og formater.
     * Støtter både array og streng (komma/semicolon/space-separert).
     */
    function contracts_session_list(): array {
        $keys = ['permissions', 'roles', 'groups', 'user_groups', 'ad_groups'];
        $out = [];

        foreach ($keys as $k) {
            if (!isset($_SESSION[$k])) continue;

            $v = $_SESSION[$k];

            if (is_array($v)) {
                foreach ($v as $item) {
                    $s = trim((string)$item);
                    if ($s !== '') $out[] = $s;
                }
            } else {
                $s = trim((string)$v);
                if ($s !== '') {
                    $parts = preg_split('/[,\s;]+/', $s) ?: [];
                    foreach ($parts as $p) {
                        $p = trim((string)$p);
                        if ($p !== '') $out[] = $p;
                    }
                }
            }
        }

        $norm = [];
        foreach ($out as $g) {
            $norm[] = mb_strtolower($g, 'UTF-8');
        }

        return array_values(array_unique($norm));
    }
}

if (!function_exists('contracts_has_any')) {
    function contracts_has_any(array $needles, array $haystack): bool {
        if (empty($needles)) return false;
        $set = array_flip($haystack);
        foreach ($needles as $n) {
            $n = mb_strtolower(trim((string)$n), 'UTF-8');
            if ($n !== '' && isset($set[$n])) return true;
        }
        return false;
    }
}

if (!function_exists('contracts_db_roles_for_session_user')) {
    /**
     * Hent roller fra DB (user_roles) for innlogget bruker.
     * Returnerer lowercase roller (f.eks. ['admin','contracts_read']).
     */
    function contracts_db_roles_for_session_user(PDO $pdo, string $sessionUsername): array {
        $raw  = trim($sessionUsername);
        $norm = contracts_normalize_username($raw);

        // Finn user_id via users.username (case-insensitive). Prøv raw, så norm.
        $st = $pdo->prepare("
            SELECT u.id
              FROM users u
             WHERE LOWER(u.username) = LOWER(:u)
             LIMIT 1
        ");

        $uid = 0;

        $st->execute([':u' => $raw]);
        $uid = (int)($st->fetchColumn() ?: 0);

        if ($uid <= 0 && $norm !== '' && $norm !== $raw) {
            $st->execute([':u' => $norm]);
            $uid = (int)($st->fetchColumn() ?: 0);
        }

        if ($uid <= 0) {
            return [];
        }

        $st2 = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :uid");
        $st2->execute([':uid' => $uid]);
        $roles = $st2->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $out = [];
        foreach ($roles as $r) {
            $r = trim((string)$r);
            if ($r !== '') $out[] = mb_strtolower($r, 'UTF-8');
        }

        return array_values(array_unique($out));
    }
}

// ---------------------------------------------------------
// DB-tilgang (må uansett brukes videre på siden)
// ---------------------------------------------------------
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    http_response_code(500);
    ?>
    <div class="alert alert-danger mt-3">
        Kunne ikke koble til databasen.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Rettighetsmodell
// ---------------------------------------------------------
$contractsReadGroup  = 'contracts_read';
$contractsWriteGroup = 'contracts_write';

// Session-baserte grupper + DB-roller
$sessionGroups = contracts_session_list();
$dbRoles       = contracts_db_roles_for_session_user($pdo, $username);

// Effektive "roller/grupper" = union(session, db)
$effective = array_values(array_unique(array_merge($sessionGroups, $dbRoles)));

// Håndhev ACL hvis vi faktisk har noen rolledata (fra session eller DB)
$enforceAcl = !empty($effective);

// Admin kan komme fra session-flag, session-gruppe, eller DB-rolle
$isAdmin = (bool)($_SESSION['is_admin'] ?? false)
    || in_array('admin', $effective, true);

// Tilganger
$canWrite = $isAdmin || contracts_has_any([$contractsWriteGroup], $effective);
$canRead  = $isAdmin || $canWrite || contracts_has_any([$contractsReadGroup], $effective);

// Sperr hvis ACL håndheves og bruker ikke kan lese (admin overstyrer)
if ($enforceAcl && !$canRead && !$isAdmin) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        <div class="fw-semibold">Ingen tilgang</div>
        <div class="small text-muted">
            Du mangler rettighet til å lese Avtaler &amp; kontrakter.
            Krever rolle <code><?= htmlspecialchars($contractsReadGroup, ENT_QUOTES, 'UTF-8') ?></code>
            (eller <code><?= htmlspecialchars($contractsWriteGroup, ENT_QUOTES, 'UTF-8') ?></code>).
        </div>
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Helpers (DB/visning)
// ---------------------------------------------------------
if (!function_exists('contracts_table_exists')) {
    function contracts_table_exists(PDO $pdo, string $table): bool {
        try {
            $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('contracts_columns')) {
    function contracts_columns(PDO $pdo, string $table): array {
        try {
            $stmt = $pdo->query("DESCRIBE `$table`");
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $cols = [];
            foreach ($rows as $r) {
                $c = (string)($r['Field'] ?? '');
                if ($c !== '') $cols[$c] = true;
            }
            return $cols;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('contracts_has_col')) {
    function contracts_has_col(array $cols, string $col): bool {
        return isset($cols[$col]);
    }
}

if (!function_exists('contracts_pick_col')) {
    function contracts_pick_col(array $cols, array $candidates, string $fallback): string {
        foreach ($candidates as $c) {
            if (isset($cols[$c])) return $c;
        }
        return $fallback;
    }
}

if (!function_exists('fmt_date')) {
    function fmt_date(?string $d): string {
        if (!$d) return '';
        if ($d === '0000-00-00') return '';
        $ts = strtotime($d);
        if (!$ts) return '';
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('safe_date_ts')) {
    function safe_date_ts(?string $d): int {
        if (!$d) return 0;
        if ($d === '0000-00-00') return 0;
        $ts = strtotime($d);
        return $ts ?: 0;
    }
}

if (!function_exists('contracts_build_url')) {
    /**
     * Bygg URL til current page med eksisterende query + overrides.
     */
    function contracts_build_url(array $overrides = []): string {
        $base = '/?page=contracts';
        $q = $_GET ?? [];
        unset($q['page']);

        foreach ($overrides as $k => $v) {
            if ($v === null) {
                unset($q[$k]);
            } else {
                $q[$k] = $v;
            }
        }

        $qs = http_build_query($q);
        return $qs ? ($base . '&' . $qs) : $base;
    }
}

if (!function_exists('contracts_sort_link')) {
    function contracts_sort_link(string $key, string $currentSort, string $currentDir): array {
        $isActive = ($currentSort === $key);
        $nextDir  = 'asc';
        if ($isActive) {
            $nextDir = ($currentDir === 'asc') ? 'desc' : 'asc';
        }
        $href = contracts_build_url([
            'sort' => $key,
            'dir'  => $nextDir,
        ]);
        return [$href, $isActive, $isActive ? $currentDir : ''];
    }
}

if (!function_exists('contracts_user_fullname_map')) {
    /**
     * Lager map: username(lowercase) => fullname.
     * Brukes for å vise fullt navn på ansvarlig.
     */
    function contracts_user_fullname_map(PDO $pdo): array {
        try {
            $st = $pdo->query("SELECT username, fullname FROM users");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $map = [];
            foreach ($rows as $r) {
                $u = trim((string)($r['username'] ?? ''));
                if ($u === '') continue;
                $f = trim((string)($r['fullname'] ?? ''));
                $map[mb_strtolower($u, 'UTF-8')] = $f;
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

// ---------------------------------------------------------
// Input (filter/søk)
// ---------------------------------------------------------
$q      = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$type   = trim((string)($_GET['type'] ?? '')); // kunde/leverandør/partner/annet
$limit  = (int)($_GET['limit'] ?? 50);
if ($limit < 10) $limit = 10;
if ($limit > 200) $limit = 200;

// Sort
$sort = trim((string)($_GET['sort'] ?? ''));
$dir  = mb_strtolower(trim((string)($_GET['dir'] ?? 'asc')), 'UTF-8');
$dir  = ($dir === 'desc') ? 'desc' : 'asc';

// ---------------------------------------------------------
// Datagrunnlag
// ---------------------------------------------------------
$table = 'contracts';
$hasTable = contracts_table_exists($pdo, $table);
$cols = $hasTable ? contracts_columns($pdo, $table) : [];

// KPI/fornyelse “innen X dager”
$days = 30;

// Kolonner (fallback)
$idCol        = $hasTable ? contracts_pick_col($cols, ['id', 'contract_id'], 'id') : 'id';
$titleCol     = $hasTable ? contracts_pick_col($cols, ['title', 'name', 'contract_name'], 'title') : 'title';
$typeCol      = $hasTable ? contracts_pick_col($cols, ['party_type', 'type', 'contract_type'], 'party_type') : 'party_type';
$counterCol   = $hasTable ? contracts_pick_col($cols, ['counterparty', 'party', 'vendor', 'customer', 'account_name'], 'counterparty') : 'counterparty';
$statusCol    = $hasTable ? contracts_pick_col($cols, ['status', 'state'], 'status') : 'status';
$startCol     = $hasTable ? contracts_pick_col($cols, ['start_date', 'valid_from', 'from_date'], 'start_date') : 'start_date';
$endCol       = $hasTable ? contracts_pick_col($cols, ['end_date', 'valid_to', 'expiry_date', 'expires_at'], 'end_date') : 'end_date';
$renewCol     = $hasTable ? contracts_pick_col($cols, ['renewal_date', 'renew_at', 'renew_by'], 'renewal_date') : 'renewal_date';
$kpiCol       = $hasTable ? contracts_pick_col($cols, ['kpi_adjust_date', 'kpi_date', 'index_adjust_date', 'cpi_adjust_date'], 'kpi_adjust_date') : 'kpi_adjust_date';
$linkCol      = $hasTable ? contracts_pick_col($cols, ['link_url', 'link', 'url', 'document_url'], 'link_url') : 'link_url';
$ownerCol     = $hasTable ? contracts_pick_col($cols, ['owner_username','owner','responsible','responsible_username'], 'owner_username') : 'owner_username';
$createdByCol = $hasTable ? contracts_pick_col($cols, ['created_by_username','created_by','created_by_user'], 'created_by_username') : 'created_by_username';

// Sort-nøkler -> DB-kolonne
$sortMap = [
    'title'        => $titleCol,
    'type'         => $typeCol,
    'counterparty' => $counterCol,
    'status'       => $statusCol,
    'start'        => $startCol,
    'end'          => $endCol,
    'renew'        => $renewCol,
    'kpi'          => $kpiCol,
    'owner'        => $ownerCol,
    'link'         => $linkCol,
    'id'           => $idCol,
];

// Fullname-map (kun hvis users-tabellen finnes i DB)
$userFullname = contracts_user_fullname_map($pdo);

// Dashboard-tall
$stats = [
    'total'      => null,
    'expires30'  => null,
    'renew30'    => null,
    'kpi30'      => null,
];

$rows = [];
$error = null;

if ($hasTable) {
    try {
        $stats['total'] = (int)($pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() ?: 0);

        if (contracts_has_col($cols, $endCol)) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                  FROM `$table`
                 WHERE `$endCol` IS NOT NULL
                   AND `$endCol` >= CURDATE()
                   AND `$endCol` <= DATE_ADD(CURDATE(), INTERVAL :d DAY)
            ");
            $stmt->execute([':d' => $days]);
            $stats['expires30'] = (int)($stmt->fetchColumn() ?: 0);
        }

        if (contracts_has_col($cols, $renewCol)) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                  FROM `$table`
                 WHERE `$renewCol` IS NOT NULL
                   AND `$renewCol` >= CURDATE()
                   AND `$renewCol` <= DATE_ADD(CURDATE(), INTERVAL :d DAY)
            ");
            $stmt->execute([':d' => $days]);
            $stats['renew30'] = (int)($stmt->fetchColumn() ?: 0);
        }

        if (contracts_has_col($cols, $kpiCol)) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                  FROM `$table`
                 WHERE `$kpiCol` IS NOT NULL
                   AND `$kpiCol` >= CURDATE()
                   AND `$kpiCol` <= DATE_ADD(CURDATE(), INTERVAL :d DAY)
            ");
            $stmt->execute([':d' => $days]);
            $stats['kpi30'] = (int)($stmt->fetchColumn() ?: 0);
        }

        $where = [];
        $params = [];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $parts = [];

            if (contracts_has_col($cols, $titleCol)) {
                $parts[] = "`$titleCol` LIKE :q1";
                $params[':q1'] = $like;
            }
            if (contracts_has_col($cols, $counterCol)) {
                $parts[] = "`$counterCol` LIKE :q2";
                $params[':q2'] = $like;
            }
            if (contracts_has_col($cols, $linkCol)) {
                $parts[] = "`$linkCol` LIKE :q3";
                $params[':q3'] = $like;
            }

            if (!empty($parts)) {
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        if ($status !== '' && contracts_has_col($cols, $statusCol)) {
            $where[] = "`$statusCol` = :status";
            $params[':status'] = $status;
        }

        if ($type !== '' && contracts_has_col($cols, $typeCol)) {
            $where[] = "`$typeCol` = :type";
            $params[':type'] = $type;
        }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        // ORDER BY (sorter på alle rubrikker)
        $orderSql = '';
        if ($sort !== '' && isset($sortMap[$sort])) {
            $col = $sortMap[$sort];
            if ($hasTable && contracts_has_col($cols, $col)) {
                $dateKeys = ['start','end','renew','kpi'];
                if (in_array($sort, $dateKeys, true)) {
                    $orderSql = "ORDER BY (`$col` IS NULL) ASC, `$col` " . strtoupper($dir);
                } else {
                    $orderSql = "ORDER BY `$col` " . strtoupper($dir);
                }
            }
        }

        // Fallback
        if ($orderSql === '') {
            if (contracts_has_col($cols, $endCol)) {
                $orderSql = "ORDER BY (`$endCol` IS NULL) ASC, `$endCol` ASC";
            } elseif (contracts_has_col($cols, $idCol)) {
                $orderSql = "ORDER BY `$idCol` DESC";
            }
        }

        $selectCols = [];
        foreach ([$idCol,$titleCol,$typeCol,$counterCol,$statusCol,$startCol,$endCol,$renewCol,$kpiCol,$linkCol,$ownerCol,$createdByCol] as $c) {
            if (contracts_has_col($cols, $c)) {
                $selectCols[] = "`$c`";
            }
        }
        if (empty($selectCols)) {
            $selectCols = ["`$idCol`"];
        }

        $sql = "SELECT " . implode(', ', $selectCols) . " FROM `$table` $whereSql $orderSql LIMIT " . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $rows = [];
    }
}

$statusOptions = [];
$typeOptions   = [];

if ($hasTable) {
    try {
        if (contracts_has_col($cols, $statusCol)) {
            $st = $pdo->query("SELECT DISTINCT `$statusCol` AS v FROM `$table` WHERE `$statusCol` IS NOT NULL AND `$statusCol` <> '' ORDER BY `$statusCol`");
            $statusOptions = $st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        }
        if (contracts_has_col($cols, $typeCol)) {
            $st = $pdo->query("SELECT DISTINCT `$typeCol` AS v FROM `$table` WHERE `$typeCol` IS NOT NULL AND `$typeCol` <> '' ORDER BY `$typeCol`");
            $typeOptions = $st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        }
    } catch (\Throwable $e) {
        // ignore
    }
}
?>

<style>
/* /public/pages/contracts.php */
.contracts-filterbar { width: 100%; }

/* Gjør rad klikkbar */
.table-row-link { cursor: pointer; }
.table-row-link:hover { background: rgba(0,0,0,.03); }
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h5 mb-1">Avtaler &amp; kontrakter</h1>
        <div class="text-muted small">
            Oversikt over avtaler (kun metadata). Selve avtalen lagres eksternt – her ligger kun lenke.
        </div>
    </div>

    <div class="d-flex gap-2">
        <?php if ($canWrite): ?>
            <a href="/?page=contracts_new" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Ny avtale
            </a>
        <?php else: ?>
            <button type="button" class="btn btn-sm btn-primary" disabled
                    title="Du har ikke skriverettighet (<?= htmlspecialchars($contractsWriteGroup, ENT_QUOTES, 'UTF-8') ?>)">
                <i class="bi bi-plus-circle me-1"></i> Ny avtale
            </button>
        <?php endif; ?>

        <a href="/?page=contracts_alerts" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-bell me-1"></i> Varsler
        </a>
    </div>
</div>

<?php if ($enforceAcl && !$canWrite && !$isAdmin): ?>
    <div class="alert alert-info">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-info-circle mt-1"></i>
            <div>
                <div class="fw-semibold">Du har kun lesetilgang</div>
                <div class="small text-muted">
                    For å opprette/endre avtaler kreves rolle <code><?= htmlspecialchars($contractsWriteGroup, ENT_QUOTES, 'UTF-8') ?></code>.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$hasTable): ?>
    <div class="alert alert-warning">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle mt-1"></i>
            <div>
                <div class="fw-semibold">Avtalemodulen er ikke aktivert i databasen ennå</div>
                <div class="small text-muted">
                    Tabellen <code>contracts</code> finnes ikke. Denne siden viser derfor kun layout/placeholder.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <div class="fw-semibold">Kunne ikke hente avtaler</div>
        <div class="small text-muted"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
<?php endif; ?>

<?php
$alertExpires = $stats['expires30'] !== null && $stats['expires30'] > 0;
$alertRenew   = $stats['renew30']   !== null && $stats['renew30']   > 0;
$alertKpi     = $stats['kpi30']     !== null && $stats['kpi30']     > 0;
?>
<div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Totalt</div>
                <div class="h4 mb-0">
                    <?= $stats['total'] === null ? '—' : (int)$stats['total'] ?>
                </div>
                <div class="small text-muted mt-2">Alle registrerte avtaler</div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-3">
        <a href="/?page=contracts_alerts&type=end" class="text-decoration-none">
        <div class="card shadow-sm h-100 <?= $alertExpires ? 'border-danger border' : '' ?>">
            <div class="card-body">
                <div class="text-muted small d-flex justify-content-between">
                    <span>Utløper ≤ <?= (int)$days ?> dager</span>
                    <?php if ($alertExpires): ?><i class="bi bi-exclamation-circle text-danger"></i><?php endif; ?>
                </div>
                <div class="h4 mb-0 <?= $alertExpires ? 'text-danger' : '' ?>">
                    <?= $stats['expires30'] === null ? '—' : (int)$stats['expires30'] ?>
                </div>
                <div class="small text-muted mt-2">Basert på sluttdato</div>
            </div>
        </div>
        </a>
    </div>

    <div class="col-12 col-md-3">
        <a href="/?page=contracts_alerts&type=renewal" class="text-decoration-none">
        <div class="card shadow-sm h-100 <?= $alertRenew ? 'border-warning border' : '' ?>">
            <div class="card-body">
                <div class="text-muted small d-flex justify-content-between">
                    <span>Fornyes ≤ <?= (int)$days ?> dager</span>
                    <?php if ($alertRenew): ?><i class="bi bi-arrow-repeat text-warning"></i><?php endif; ?>
                </div>
                <div class="h4 mb-0 <?= $alertRenew ? 'text-warning' : '' ?>">
                    <?= $stats['renew30'] === null ? '—' : (int)$stats['renew30'] ?>
                </div>
                <div class="small text-muted mt-2">Basert på fornyelsesdato</div>
            </div>
        </div>
        </a>
    </div>

    <div class="col-12 col-md-3">
        <a href="/?page=contracts_alerts&type=kpi" class="text-decoration-none">
        <div class="card shadow-sm h-100 <?= $alertKpi ? 'border-info border' : '' ?>">
            <div class="card-body">
                <div class="text-muted small d-flex justify-content-between">
                    <span>KPI/indeks ≤ <?= (int)$days ?> dager</span>
                    <?php if ($alertKpi): ?><i class="bi bi-graph-up-arrow text-info"></i><?php endif; ?>
                </div>
                <div class="h4 mb-0 <?= $alertKpi ? 'text-info' : '' ?>">
                    <?= $stats['kpi30'] === null ? '—' : (int)$stats['kpi30'] ?>
                </div>
                <div class="small text-muted mt-2">Basert på KPI-justeringsdato</div>
            </div>
        </div>
        </a>
    </div>
</div>

<section class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div class="fw-semibold">Avtaleoversikt</div>

            <form class="contracts-filterbar d-flex flex-wrap flex-md-nowrap align-items-center gap-2" method="get" action="/">
                <input type="hidden" name="page" value="contracts">

                <div class="input-group input-group-sm" style="min-width:220px; max-width:360px;">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="q"
                           value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Søk">
                </div>

                <select name="type" class="form-select form-select-sm" style="min-width:150px;">
                    <option value="">Type</option>
                    <?php foreach ($typeOptions as $opt): ?>
                        <?php $opt = (string)$opt; ?>
                        <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>" <?= $type === $opt ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="form-select form-select-sm" style="min-width:150px;">
                    <option value="">Status</option>
                    <?php foreach ($statusOptions as $opt): ?>
                        <?php $opt = (string)$opt; ?>
                        <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>" <?= $status === $opt ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="limit" class="form-select form-select-sm" style="min-width:120px;">
                    <?php foreach ([25,50,100,200] as $n): ?>
                        <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?> rader</option>
                    <?php endforeach; ?>
                </select>

                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="bi bi-funnel me-1"></i> Filtrer
                </button>

                <a class="btn btn-sm btn-outline-secondary" href="/?page=contracts">
                    Nullstill
                </a>
            </form>
        </div>

        <?php
        [$hrefTitle,  $actTitle,  $dirTitle]  = contracts_sort_link('title', $sort, $dir);
        [$hrefType,   $actType,   $dirType]   = contracts_sort_link('type', $sort, $dir);
        [$hrefCount,  $actCount,  $dirCount]  = contracts_sort_link('counterparty', $sort, $dir);
        [$hrefStatus, $actStatus, $dirStatus] = contracts_sort_link('status', $sort, $dir);
        [$hrefStart,  $actStart,  $dirStart]  = contracts_sort_link('start', $sort, $dir);
        [$hrefEnd,    $actEnd,    $dirEnd]    = contracts_sort_link('end', $sort, $dir);
        [$hrefRenew,  $actRenew,  $dirRenew]  = contracts_sort_link('renew', $sort, $dir);
        [$hrefKpi,    $actKpi,    $dirKpi]    = contracts_sort_link('kpi', $sort, $dir);
        [$hrefOwner,  $actOwner,  $dirOwner]  = contracts_sort_link('owner', $sort, $dir);
        [$hrefLink,   $actLink,   $dirLink]   = contracts_sort_link('link', $sort, $dir);
        ?>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th style="min-width:300px;">
                        <a class="text-decoration-none" href="<?= htmlspecialchars($hrefTitle, ENT_QUOTES, 'UTF-8') ?>">
                            Avtale
                            <?php if ($actTitle): ?>
                                <i class="bi <?= $dirTitle === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill' ?>"></i>
                            <?php endif; ?>
                        </a>
                    </th>

                    <th style="min-width:140px;">
                        <a class="text-decoration-none" href="<?= htmlspecialchars($hrefType, ENT_QUOTES, 'UTF-8') ?>">
                            Type
                            <?php if ($actType): ?>
                                <i class="bi <?= $dirType === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill' ?>"></i>
                            <?php endif; ?>
                        </a>
                    </th>

                    <th style="min-width:220px;">
                        <a class="text-decoration-none" href="<?= htmlspecialchars($hrefCount, ENT_QUOTES, 'UTF-8') ?>">
                            Motpart
                            <?php if ($actCount): ?>
                                <i class="bi <?= $dirCount === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill' ?>"></i>
                            <?php endif; ?>
                        </a>
                    </th>

                    <th style="min-width:120px;">
                        <a class="text-decoration-none" href="<?= htmlspecialchars($hrefStatus, ENT_QUOTES, 'UTF-8') ?>">
                            Status
                            <?php if ($actStatus): ?>
                                <i class="bi <?= $dirStatus === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill' ?>"></i>
                            <?php endif; ?>
                        </a>
                    </th>

                    <th style="min-width:120px;">
                        <a class="text-decoration-none" href="<?= htmlspecialchars($hrefStart, ENT_QUOTES, 'UTF-8') ?>">
                            Start
                            <?php if ($actStart): ?>
                                <i class="bi <?= $dirStart === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill' ?>"></i>
                            <?php endif; ?>
                        </a>
                    </th>

                    <th style="min-width:120px;">
                        <a class="text-decoration-none" href="<?= htmlspecialchars($hrefEnd, ENT_QUOTES, 'UTF-8') ?>">
                            Slutt
                            <?php if ($actEnd): ?>
                                <i class="bi <?= $dirEnd === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill' ?>"></i>
                            <?php endif; ?>
                        </a>
                    </th>

                    <th style="min-width:120px;">
                        <a class="text-decoration-none" href="<?= htmlspecialchars($hrefRenew, ENT_QUOTES, 'UTF-8') ?>">
                            Fornyes
                            <?php if ($actRenew): ?>
                                <i class="bi <?= $dirRenew === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill' ?>"></i>
                            <?php endif; ?>
                        </a>
                    </th>

                    <th style="min-width:120px;">
                        <a class="text-decoration-none" href="<?= htmlspecialchars($hrefKpi, ENT_QUOTES, 'UTF-8') ?>">
                            KPI
                            <?php if ($actKpi): ?>
                                <i class="bi <?= $dirKpi === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill' ?>"></i>
                            <?php endif; ?>
                        </a>
                    </th>

                    <th style="min-width:180px;">
                        <a class="text-decoration-none" href="<?= htmlspecialchars($hrefOwner, ENT_QUOTES, 'UTF-8') ?>">
                            Ansvarlig
                            <?php if ($actOwner): ?>
                                <i class="bi <?= $dirOwner === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill' ?>"></i>
                            <?php endif; ?>
                        </a>
                    </th>

                    <th style="min-width:200px;">
                        <a class="text-decoration-none" href="<?= htmlspecialchars($hrefLink, ENT_QUOTES, 'UTF-8') ?>">
                            Handling
                            <?php if ($actLink): ?>
                                <i class="bi <?= $dirLink === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill' ?>"></i>
                            <?php endif; ?>
                        </a>
                    </th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="10" class="text-muted">
                            Ingen avtaler å vise.
                            <?php if (!$hasTable): ?>
                                <span class="ms-1">Opprett tabell og felter for å aktivere oversikten.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $id        = (string)($r[$idCol] ?? '');
                        $title     = (string)($r[$titleCol] ?? ('Avtale #' . $id));
                        $ptype     = (string)($r[$typeCol] ?? '');
                        $counter   = (string)($r[$counterCol] ?? '');
                        $st        = (string)($r[$statusCol] ?? '');
                        $start     = (string)($r[$startCol] ?? '');
                        $end       = (string)($r[$endCol] ?? '');
                        $renew     = (string)($r[$renewCol] ?? '');
                        $kpi       = (string)($r[$kpiCol] ?? '');
                        $link      = (string)($r[$linkCol] ?? '');
                        $owner     = (string)($r[$ownerCol] ?? '');
                        $createdBy = (string)($r[$createdByCol] ?? '');

                        $responsibleUser = $owner !== '' ? $owner : $createdBy;
                        $responsibleKey  = mb_strtolower(trim($responsibleUser), 'UTF-8');
                        $fullName        = $userFullname[$responsibleKey] ?? '';
                        $responsibleDisp = $fullName !== '' ? $fullName : $responsibleUser;

                        $endTs   = safe_date_ts($end);
                        $renewTs = safe_date_ts($renew);
                        $kpiTs   = safe_date_ts($kpi);

                        $soonEnd   = ($endTs > 0 && $endTs <= strtotime('+' . $days . ' days'));
                        $soonRenew = ($renewTs > 0 && $renewTs <= strtotime('+' . $days . ' days'));
                        $soonKpi   = ($kpiTs > 0 && $kpiTs <= strtotime('+' . $days . ' days'));

                        $viewUrl = '/?page=contracts_view&id=' . urlencode($id);
                        ?>
                        <tr class="table-row-link" data-href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <div class="fw-semibold">
                                    <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </td>

                            <td><?= htmlspecialchars($ptype, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($counter, ENT_QUOTES, 'UTF-8') ?></td>

                            <td>
                                <?php if ($st !== ''): ?>
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </td>

                            <td><?= htmlspecialchars(fmt_date($start), ENT_QUOTES, 'UTF-8') ?></td>

                            <td>
                                <?php if (fmt_date($end) !== ''): ?>
                                    <span class="<?= $soonEnd ? 'text-danger fw-semibold' : '' ?>">
                                        <?= htmlspecialchars(fmt_date($end), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (fmt_date($renew) !== ''): ?>
                                    <span class="<?= $soonRenew ? 'text-warning fw-semibold' : '' ?>">
                                        <?= htmlspecialchars(fmt_date($renew), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (fmt_date($kpi) !== ''): ?>
                                    <span class="<?= $soonKpi ? 'text-warning fw-semibold' : '' ?>">
                                        <?= htmlspecialchars(fmt_date($kpi), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td class="text-muted small">
                                <?= htmlspecialchars($responsibleDisp, ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>"
                                       class="btn btn-sm btn-info">
                                        <i class="bi bi-gear me-1"></i> Innstillinger
                                    </a>

                                    <?php if ($link !== ''): ?>
                                        <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-box-arrow-up-right me-1"></i> Åpne
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small align-self-center">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="small text-muted mt-2">
            Tips: Klikk på en rad for å åpne avtalen. Du kan også sortere ved å klikke på kolonneoverskrifter.
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Klikkbar rad som åpner contracts_view
    // Unngå å trigge når man klikker på knapper/lenker/inputs.
    document.querySelectorAll('tr.table-row-link[data-href]').forEach(function (row) {
        row.addEventListener('click', function (e) {
            var t = e.target;
            if (!t) return;
            if (t.closest('a, button, input, select, textarea, label')) return;

            var href = row.getAttribute('data-href');
            if (href) window.location.href = href;
        });
    });
});
</script>
