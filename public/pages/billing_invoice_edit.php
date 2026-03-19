<?php
// public/pages/billing_invoice_edit.php
//
// Forutsetter tabeller:
// - crm_accounts
// - billing_invoice_drafts
// - billing_invoice_lines
// - inv_products
// - inv_batches
// - inv_locations (fysisk lager)
// - projects (valgfritt / for ERP-prosjekt mapping)
//
// Funksjoner:
// - Opprette/endre fakturagrunnlag (draft)
// - Låse/åpne
// - Legge til/redigere/slette linjer
// - Slette fakturagrunnlag som ikke er sendt + legge varer tilbake til lager (batch)
//
// Krav:
// - Antall (qty) skal vises og tastes uten desimal (heltall)
// - Pris kan være med to desimaler
//
// Viktig i denne versjonen (etter dine krav):
// - Uttak på fakturagrunnlag skal IKKE kreve prosjekt/arbeidsordre i UI.
// - Uttak registreres på kunden (account_id på fakturagrunnlaget).
// - Kostnadsprosjekt (project_id på linjene) settes automatisk til kundens ERP-prosjekt (intern referanse).
// - Fysisk lager skal velges fra "Ny linje" før batch vises (ellers feil på telling).
// - Hode (header) skal kunne skjules under en pil når det er fylt ut.
//
// Merk om ERP-prosjekt:
// - crm_accounts.php kan bruke et annet feltnavn enn vi forventer.
// - Denne filen prøver derfor å finne "ERP/intern prosjekt"-kolonne dynamisk og mappe til projects.id.

use App\Database;

// ---------------------------------------------------------
// Krev innlogging
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';

// Ajax-flagg tidlig (slik at vi kan returnere JSON ved 403/500)
$isAjaxBatches = (isset($_GET['ajax']) && $_GET['ajax'] === 'batches');

function json_response(array $data, int $statusCode = 200): void {
    // Ikke la warnings/notices ødelegge JSON
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    // Rens eventuell output-buffer så JSON ikke "forurenses"
    if (ob_get_level() > 0) {
        @ob_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($username === '') {
    if ($isAjaxBatches) {
        json_response(['ok' => false, 'error' => 'Ikke innlogget (403).'], 403);
    }
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Rolle/tilgang (SAMME som billing_invoice_new.php)
// - Admin via user_roles eller legacy session/is_admin
// - Tillat: invoice / faktura / billing_write / billing (+ legacy roller dere har brukt)
// ---------------------------------------------------------
if (!function_exists('normalize_list')) {
    function normalize_list($v): array {
        if (is_array($v)) {
            return array_values(array_filter(array_map('strval', $v)));
        }
        if (is_string($v) && trim($v) !== '') {
            $parts = preg_split('/[,\s;]+/', $v);
            return array_values(array_filter(array_map('strval', $parts)));
        }
        return [];
    }
}

if (!function_exists('has_any')) {
    function has_any(array $needles, array $haystack): bool {
        $haystack = array_map('strtolower', $haystack);
        foreach ($needles as $n) {
            if (in_array(strtolower($n), $haystack, true)) return true;
        }
        return false;
    }
}

if (!function_exists('normalizeUsername')) {
    /**
     * Normaliser session-username til typisk users.username:
     * - "DOMENE\bruker" -> "bruker"
     * - "bruker@domene" -> "bruker"
     */
    function normalizeUsername(string $u): string {
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

// Start med session-roller/perms hvis de finnes (kan være tomt)
$roles = normalize_list($_SESSION['roles'] ?? null);
$perms = normalize_list($_SESSION['permissions'] ?? null);

// Legacy admin-fallback
$isAdmin = (bool)($_SESSION['is_admin'] ?? false);
if ($username === 'rsv') {
    $isAdmin = true;
}

$pdo = null;
$userId = 0;

try {
    $pdo = Database::getConnection();

    // Finn user_id (case-insensitive) – prøv raw og normalisert
    $raw  = trim((string)$username);
    $norm = normalizeUsername($raw);

    $st = $pdo->prepare("
        SELECT u.id
          FROM users u
         WHERE LOWER(u.username) = LOWER(:u)
         LIMIT 1
    ");

    $st->execute([':u' => $raw]);
    $userId = (int)($st->fetchColumn() ?: 0);

    if ($userId <= 0 && $norm !== '' && $norm !== $raw) {
        $st->execute([':u' => $norm]);
        $userId = (int)($st->fetchColumn() ?: 0);
    }

    // Hent roller fra user_roles (faktisk fasit)
    if ($userId > 0) {
        $st2 = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $st2->execute([':uid' => $userId]);
        $dbRoles = $st2->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $roles = array_merge($roles, normalize_list($dbRoles));
    }
} catch (\Throwable $e) {
    // DB nede => behold sessionverdier
    $pdo = null;
}

// Normaliser
$roles = array_values(array_unique(array_map('strtolower', $roles)));
$perms = array_values(array_unique(array_map('strtolower', $perms)));

// Admin via rolle også
if (!$isAdmin && has_any(['admin'], $roles)) {
    $isAdmin = true;
}

// Samme tilgang som i billing_invoice_new.php (men behold litt legacy/backwards)
$canBilling = $isAdmin
    || has_any(['invoice','faktura','billing_write','billing','crm','logistikk','lager','inventory','support'], $roles)
    || has_any(['invoice','faktura','billing_write','billing','crm','logistikk','lager','inventory','support'], $perms);

if (!$canBilling) {
    if ($isAjaxBatches) {
        json_response(['ok' => false, 'error' => 'Ingen tilgang til fakturagrunnlag (403).'], 403);
    }
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til fakturagrunnlag.
    </div>
    <?php
    return;
}

// Sørg for PDO før resten kjører
if (!$pdo) {
    try {
        $pdo = Database::getConnection();
    } catch (\Throwable $e) {
        if ($isAjaxBatches) {
            json_response(['ok' => false, 'error' => 'Klarte ikke koble til databasen.'], 500);
        }
        http_response_code(500);
        ?>
        <div class="alert alert-danger mt-3">
            Klarte ikke koble til databasen.
        </div>
        <?php
        return;
    }
}

/// ---------------------------------------------------------
// Helpers (guard mot redeclare pga frontcontroller include)
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

if (!function_exists('table_columns')) {
    function table_columns(PDO $pdo, string $table): array {
        $cols = [];
        $st = $pdo->query("SHOW COLUMNS FROM `$table`");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[] = (string)$r['Field'];
        }
        return $cols;
    }
}

if (!function_exists('get_int')) {
    function get_int(array $src, string $key, int $default = 0): int {
        $v = $src[$key] ?? null;
        if ($v === null || $v === '') return $default;
        return (int)$v;
    }
}

if (!function_exists('get_str')) {
    function get_str(array $src, string $key, int $maxLen = 255, string $default = ''): string {
        $v = trim((string)($src[$key] ?? $default));
        if ($maxLen > 0 && mb_strlen($v, 'UTF-8') > $maxLen) {
            $v = mb_substr($v, 0, $maxLen, 'UTF-8');
        }
        return $v;
    }
}

if (!function_exists('parse_decimal')) {
    function parse_decimal(string $s, float $default = 0.0): float {
        $s = trim($s);
        if ($s === '') return $default;
        $s = str_replace(' ', '', $s);
        $s = str_replace(',', '.', $s);
        $s = preg_replace('/[^0-9\.\-]/', '', $s);
        if ($s === '' || $s === '-' || $s === '.' || $s === '-.') return $default;
        return (float)$s;
    }
}

if (!function_exists('parse_int_qty')) {
    // Heltall for antall (tillater "10", "10,0", "10.0" -> 10)
    function parse_int_qty(string $s, int $default = 1): int {
        $s = trim($s);
        if ($s === '') return $default;
        $s = str_replace(' ', '', $s);
        $s = str_replace(',', '.', $s);
        $s = preg_replace('/[^0-9\.\-]/', '', $s);
        if ($s === '' || $s === '-' || $s === '.' || $s === '-.') return $default;
        $v = (float)$s;
        return (int)round($v, 0);
    }
}

if (!function_exists('money')) {
    function money(float $v): string {
        return number_format($v, 2, ',', ' ');
    }
}

if (!function_exists('qtyfmt')) {
    function qtyfmt(float $v): string {
        return number_format((float)round($v, 0), 0, ',', ' ');
    }
}

if (!function_exists('is_locked')) {
    function is_locked(array $invoice): bool {
        return ($invoice['status'] ?? '') !== 'draft';
    }
}

if (!function_exists('column_exists')) {
    function column_exists(PDO $pdo, string $table, string $column): bool {
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND column_name = :c
            ");
            $st->execute([':t' => $table, ':c' => $column]);
            return (int)$st->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}


if (!function_exists('find_first_existing_column')) {
    function find_first_existing_column(PDO $pdo, string $table, array $candidates): ?string {
        $cols = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[strtolower($r['Field'])] = $r['Field'];
        }
        foreach ($candidates as $c) {
            $k = strtolower($c);
            if (isset($cols[$k])) return $cols[$k];
        }
        return null;
    }
}

function recalc_invoice_totals(PDO $pdo, int $invoiceId): void {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(sell_total), 0) AS subtotal,
            COALESCE(SUM(vat_amount), 0) AS vat_amount
        FROM billing_invoice_lines
        WHERE invoice_id = :id
    ");
    $stmt->execute([':id' => $invoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['subtotal' => 0, 'vat_amount' => 0];

    $subtotal  = (float)$row['subtotal'];
    $vatAmount = (float)$row['vat_amount'];
    $total     = $subtotal + $vatAmount;

    $upd = $pdo->prepare("
        UPDATE billing_invoice_drafts
           SET subtotal = :subtotal,
               vat_amount = :vat_amount,
               total = :total
         WHERE id = :id
         LIMIT 1
    ");
    $upd->execute([
        ':subtotal' => $subtotal,
        ':vat_amount' => $vatAmount,
        ':total' => $total,
        ':id' => $invoiceId,
    ]);
}

// ---------------------------------------------------------
// Lager-retur ved sletting av fakturagrunnlag (PRODUCT-linjer)
// ---------------------------------------------------------
if (!function_exists('restock_invoice_products')) {
    function restock_invoice_products(PDO $pdo, int $invoiceId): void {
        $st = $pdo->prepare("
            SELECT line_type, batch_id, qty
              FROM billing_invoice_lines
             WHERE invoice_id = :id
        ");
        $st->execute([':id' => $invoiceId]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byBatch = [];
        foreach ($lines as $ln) {
            if (($ln['line_type'] ?? '') !== 'PRODUCT') continue;
            $batchId = (int)($ln['batch_id'] ?? 0);
            if ($batchId <= 0) continue;

            $qty = (float)($ln['qty'] ?? 0);
            if ($qty <= 0) continue;

            if (!isset($byBatch[$batchId])) $byBatch[$batchId] = 0.0;
            $byBatch[$batchId] += $qty;
        }

        if (!$byBatch) return;

        $stockCol = find_first_existing_column($pdo, 'inv_batches', [
            'qty_remaining',
            'quantity_remaining',
            'available_qty',
            'qty_available',
            'stock_qty',
            'qty',
            'quantity',
            'balance',
            'remaining',
        ]);

        if (!$stockCol) {
            throw new RuntimeException("Fant ingen lagerkolonne i inv_batches (qty/qty_remaining/etc).");
        }

        $upd = $pdo->prepare("
            UPDATE inv_batches
               SET `$stockCol` = COALESCE(`$stockCol`,0) + :q1
             WHERE id = :id
             LIMIT 1
        ");

        foreach ($byBatch as $batchId => $qty) {
            $upd->execute([':q1' => $qty, ':id' => $batchId]);
            if ($upd->rowCount() < 1) {
                throw new RuntimeException("Klarte ikke oppdatere inv_batches for batch_id=$batchId.");
            }
        }
    }
}

// ---------------------------------------------------------
// Draft: lagre valgt fysisk lager på fakturagrunnlaget (autoforsøk)
// (Vi bruker dette kun som "huskelapp" på draftet. Selve valget gjøres i Ny linje.)
// ---------------------------------------------------------
$draftLocationCol = null;
try {
    $draftLocationCol = find_first_existing_column($pdo, 'billing_invoice_drafts', [
        'withdrawal_location_id',
        'inv_location_id',
        'location_id',
    ]);

    if (!$draftLocationCol) {
        try {
            $pdo->exec("ALTER TABLE billing_invoice_drafts ADD COLUMN withdrawal_location_id INT NULL");
            $draftLocationCol = 'withdrawal_location_id';
        } catch (\Throwable $e) {
            $draftLocationCol = null;
        }
    }
} catch (\Throwable $e) {
    $draftLocationCol = null;
}

// ---------------------------------------------------------
// ERP-prosjekt: finn kolonne + map til projects.id (best effort)
// ---------------------------------------------------------
function detect_erp_project_column(PDO $pdo): ?string {
    $cols = [];
    try { $cols = table_columns($pdo, 'crm_accounts'); } catch (\Throwable $e) { return null; }

    $lc = array_map('strtolower', $cols);

    // Viktig: crm_accounts.php hos dere bruker erp_project_ref (varchar)
    $preferred = [
        'erp_project_ref',
        'erp_project_id',
        'erp_project',
        'erpprosjekt_id',
        'erp_prosjekt_id',
        'internal_project_id',
        'intern_project_id',
        'internprosjekt_id',
        'cost_project_id',
        'kost_project_id',
        'cost_project',
        'kostprosjekt_id',
        'customer_project_id',
        'project_id',
        'default_project_id',
        'invoice_project_id',
        'withdrawal_project_id',
    ];

    foreach ($preferred as $p) {
        $idx = array_search(strtolower($p), $lc, true);
        if ($idx !== false) return $cols[$idx];
    }

    foreach ($cols as $c) {
        $x = strtolower($c);
        if (str_contains($x, 'erp') && (str_contains($x, 'project') || str_contains($x, 'prosjekt') || str_contains($x, 'proj'))) {
            return $c;
        }
    }

    foreach ($cols as $c) {
        $x = strtolower($c);
        if ((str_contains($x, 'intern') || str_contains($x, 'internal')) && (str_contains($x, 'project') || str_contains($x, 'prosjekt') || str_contains($x, 'proj'))) {
            return $c;
        }
    }

    return null;
}

function project_exists(PDO $pdo, int $projectId): bool {
    if ($projectId <= 0) return false;
    if (!table_exists($pdo, 'projects')) return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM projects WHERE id = :id LIMIT 1");
        $st->execute([':id' => $projectId]);
        return (bool)$st->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}

function resolve_project_id_from_value(PDO $pdo, $val, string $sourceCol = ''): int {
    if ($val === null) return 0;

    $sourceColLc = strtolower(trim($sourceCol));

    // Hvis kilden faktisk er en *_id-kolonne, kan vi tolke numerisk som ID.
    $treatNumericAsId = ($sourceColLc !== '' && preg_match('/_id$/', $sourceColLc));

    // Spesial: erp_project_ref er en REF (varchar) og må IKKE behandles som projects.id
    if ($sourceColLc === 'erp_project_ref') {
        $treatNumericAsId = false;
    }

    // 1) Numerisk -> id (kun hvis det er *_id)
    if ($treatNumericAsId) {
        if (is_int($val) || is_float($val) || (is_string($val) && preg_match('/^\s*\d+\s*$/', $val))) {
            $id = (int)$val;
            if ($id > 0 && project_exists($pdo, $id)) return $id;
        }
    }

    // 2) Ellers: behandle som ref-streng og slå opp i projects
    $s = trim((string)$val);
    if ($s === '') return 0;

    if (!table_exists($pdo, 'projects')) return 0;

    $projCols = [];
    try { $projCols = table_columns($pdo, 'projects'); } catch (\Throwable $e) { return 0; }
    $projColsLc = array_map('strtolower', $projCols);

    // Kandidatkolonner i projects som kan inneholde ERP-ref / prosjektnr / kode
    $candidates = [
        'erp_project_ref',
        'erp_ref',
        'erp_id',
        'project_no',
        'project_number',
        'number',
        'code',
        'project_code',
        'external_id',
    ];

    $matchCol = null;
    foreach ($candidates as $c) {
        $idx = array_search($c, $projColsLc, true);
        if ($idx !== false) { $matchCol = $projCols[$idx]; break; }
    }

    if (!$matchCol) return 0;

    try {
        $st = $pdo->prepare("SELECT id FROM projects WHERE `$matchCol` = :v LIMIT 1");
        $st->execute([':v' => $s]);
        $pid = (int)($st->fetchColumn() ?: 0);
        return ($pid > 0 && project_exists($pdo, $pid)) ? $pid : 0;
    } catch (\Throwable $e) {
        return 0;
    }
}

function get_project_name(PDO $pdo, int $projectId): string {
    if ($projectId <= 0 || !table_exists($pdo, 'projects')) return '';
    try {
        $st = $pdo->prepare("SELECT name FROM projects WHERE id = :id LIMIT 1");
        $st->execute([':id' => $projectId]);
        return (string)($st->fetchColumn() ?: '');
    } catch (\Throwable $e) {
        return '';
    }
}

function get_erp_project(PDO $pdo, int $accountId): array {
    $col = detect_erp_project_column($pdo);
    if (!$col) return ['project_id' => 0, 'project_name' => '', 'source_col' => '', 'raw' => null];

    try {
        $st = $pdo->prepare("SELECT `$col` FROM crm_accounts WHERE id = :id LIMIT 1");
        $st->execute([':id' => $accountId]);
        $raw = $st->fetchColumn();

        $pid = resolve_project_id_from_value($pdo, $raw, $col);

        // Siste sikring: hvis ikke finnes -> 0
        if ($pid > 0 && !project_exists($pdo, $pid)) $pid = 0;

        $pname = $pid > 0 ? get_project_name($pdo, $pid) : '';

        return ['project_id' => $pid, 'project_name' => $pname, 'source_col' => $col, 'raw' => $raw];
    } catch (\Throwable $e) {
        return ['project_id' => 0, 'project_name' => '', 'source_col' => $col, 'raw' => null];
    }
}

// ---------------------------------------------------------
// Input
// ---------------------------------------------------------
$invoiceId    = (int)($_GET['id'] ?? $_GET['invoice_id'] ?? 0);
$accountIdNew = (int)($_GET['account_id'] ?? 0);
$editLineId   = (int)($_GET['edit_line_id'] ?? 0);

$errors  = [];
$success = null;

// ---------------------------------------------------------
// AJAX: batches for product (dropdown) - krever fysisk lager valgt i "Ny linje"
// ---------------------------------------------------------
if ($isAjaxBatches) {
    $pid = (int)($_GET['product_id'] ?? 0);
    $loc = (int)($_GET['location_id'] ?? 0);

    if ($pid <= 0) {
        json_response(['ok' => true, 'batches' => []], 200);
    }
    if ($loc <= 0) {
        json_response(['ok' => true, 'batches' => [], 'hint' => 'Velg fysisk lager først.'], 200);
    }

    try {
        $stockCol = find_first_existing_column($pdo, 'inv_batches', [
            'qty_remaining',
            'quantity_remaining',
            'available_qty',
            'qty_available',
            'stock_qty',
            'qty',
            'quantity',
            'balance',
            'remaining',
        ]);

        if (!$stockCol) {
            throw new RuntimeException("Fant ingen lagerkolonne i inv_batches (qty/qty_remaining/etc).");
        }

        // Finn evt lokasjonskolonne i inv_batches
        $batchLocCol = find_first_existing_column($pdo, 'inv_batches', [
            'location_id',
            'inv_location_id',
            'warehouse_location_id',
            'physical_location_id',
        ]);

        $sql = "
            SELECT id, `$stockCol` AS qty_remaining, unit_price, received_at
            FROM inv_batches
            WHERE product_id = :pid
              AND COALESCE(`$stockCol`,0) > 0
        ";
        $params = [':pid' => $pid];

        // Filtrer på valgt fysisk lager hvis mulig
        if ($batchLocCol) {
            $sql .= " AND `$batchLocCol` = :loc ";
            $params[':loc'] = $loc;
        }

        $sql .= " ORDER BY received_at ASC, id ASC LIMIT 500 ";

        $st = $pdo->prepare($sql);
        $st->execute($params);

        $out = [];
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $b) {
            $bid = (int)$b['id'];

            $rem = (float)$b['qty_remaining'];
            $remInt = (int)floor($rem + 1e-9);
            if ($remInt <= 0) continue;

            $unitPrice = (float)$b['unit_price'];

            $out[] = [
                'id' => $bid,
                'qty_remaining' => $remInt,
                'unit_price' => $unitPrice,
                'label' => "Batch #{$bid} – {$remInt} stk – kost " . money($unitPrice),
            ];
        }

        json_response(['ok' => true, 'batches' => $out], 200);

    } catch (\Throwable $e) {
        error_log("billing_invoice_edit ajax=batches ERROR: " . $e->getMessage());
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// ---------------------------------------------------------
// Opprett nytt draft hvis account_id er oppgitt og invoice mangler
// ---------------------------------------------------------
if ($invoiceId <= 0 && $accountIdNew > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, payment_terms_days FROM crm_accounts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $accountIdNew]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc) {
            $errors[] = 'Fant ikke kunde/partner.';
        } else {
            $terms = (int)$acc['payment_terms_days'];
            if ($terms < 0 || $terms > 365) $terms = 14;

            $issue = (new DateTime())->format('Y-m-d');
            $due   = (new DateTime())->modify('+' . $terms . ' day')->format('Y-m-d');

            $ins = $pdo->prepare("
                INSERT INTO billing_invoice_drafts
                    (account_id, status, title, currency, issue_date, due_date, created_by, created_at)
                VALUES
                    (:account_id, 'draft', 'Fakturagrunnlag', 'NOK', :issue_date, :due_date, :created_by, NOW())
            ");
            $ins->execute([
                ':account_id' => $accountIdNew,
                ':issue_date' => $issue,
                ':due_date' => $due,
                ':created_by' => $username ?: null,
            ]);
            $invoiceId = (int)$pdo->lastInsertId();

            header('Location: /?page=billing_invoice_edit&id=' . $invoiceId);
            exit;
        }
    } catch (\Throwable $e) {
        $errors[] = 'Databasefeil ved oppretting: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------
// Hent invoice + account
// ---------------------------------------------------------
$invoice = null;
$account = null;

$erpProjectId = 0;
$erpProjectName = '';
$erpProjectSourceCol = '';

if ($invoiceId > 0) {
    try {
        $selLoc = '';
        if ($draftLocationCol) $selLoc = ", d.`$draftLocationCol` AS withdrawal_location_id";

        $stmt = $pdo->prepare("
            SELECT d.*, a.name AS account_name, a.type AS account_type, a.payment_terms_days
            $selLoc
            FROM billing_invoice_drafts d
            JOIN crm_accounts a ON a.id = d.account_id
            WHERE d.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$invoice) {
            $errors[] = 'Fant ikke fakturagrunnlag.';
            $invoiceId = 0;
        } else {
            $account = [
                'id' => (int)$invoice['account_id'],
                'name' => (string)$invoice['account_name'],
                'type' => (string)$invoice['account_type'],
                'payment_terms_days' => (int)$invoice['payment_terms_days'],
            ];

            $erp = get_erp_project($pdo, (int)$invoice['account_id']);
            $erpProjectId = (int)($erp['project_id'] ?? 0);
            $erpProjectName = (string)($erp['project_name'] ?? '');
            $erpProjectSourceCol = (string)($erp['source_col'] ?? '');
        }
    } catch (\Throwable $e) {
        $errors[] = 'Databasefeil: ' . $e->getMessage();
        $invoiceId = 0;
    }
}

// ---------------------------------------------------------
// Fetch dropdown data (accounts/products/locations)
// ---------------------------------------------------------
$accounts  = [];
$products  = [];
$locations = [];

try {
    $accounts = $pdo->query("
        SELECT id, name, type, is_active
        FROM crm_accounts
        ORDER BY is_active DESC, name ASC
        LIMIT 1000
    ")->fetchAll(PDO::FETCH_ASSOC);

    $products = $pdo->query("
        SELECT id, name, unit, is_active
        FROM inv_products
        WHERE is_active = 1
        ORDER BY name ASC
        LIMIT 2000
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (table_exists($pdo, 'inv_locations')) {
        $locations = $pdo->query("
            SELECT id, name, is_active
            FROM inv_locations
            WHERE COALESCE(is_active,1) = 1
            ORDER BY name ASC
            LIMIT 2000
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) {
    // Ikke kritisk
}

// ---------------------------------------------------------
// POST handling (header + lines + lock + delete invoice)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invoiceId > 0 && $invoice) {
    $do     = $_POST['do'] ?? '';
    $locked = is_locked($invoice);

    try {
        // -------------------------------------------------
        // Slett fakturagrunnlag + legg varer tilbake til lager
        // -------------------------------------------------
        if ($do === 'delete_invoice') {
            $status  = (string)($invoice['status'] ?? 'draft');
            $blocked = ['sent', 'paid', 'booked', 'exported', 'closed'];

            if (in_array($status, $blocked, true)) {
                throw new RuntimeException('Kan ikke slette: faktura er sendt/bokført.');
            }

            $pdo->beginTransaction();
            try {
                restock_invoice_products($pdo, $invoiceId);

                $delLines = $pdo->prepare("DELETE FROM billing_invoice_lines WHERE invoice_id = :id");
                $delLines->execute([':id' => $invoiceId]);

                $delInv = $pdo->prepare("DELETE FROM billing_invoice_drafts WHERE id = :id LIMIT 1");
                $delInv->execute([':id' => $invoiceId]);

                $pdo->commit();

                header('Location: /?page=billing_invoices&msg=deleted');
                exit;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($do === 'save_header') {
            if ($locked) throw new RuntimeException('Fakturagrunnlaget er låst.');

            $newAccountId = get_int($_POST, 'account_id', (int)$invoice['account_id']);
            $title        = get_str($_POST, 'title', 255, 'Fakturagrunnlag');
            $yourRef      = get_str($_POST, 'your_ref', 128);
            $theirRef     = get_str($_POST, 'their_ref', 128);
            $currency     = strtoupper(get_str($_POST, 'currency', 3, 'NOK'));
            $issueDate    = get_str($_POST, 'issue_date', 10);
            $dueDate      = get_str($_POST, 'due_date', 10);
            $notes        = get_str($_POST, 'notes', 5000);

            if (!preg_match('/^[A-Z]{3}$/', $currency)) $currency = 'NOK';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) $issueDate = $invoice['issue_date'] ?: null;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate))   $dueDate   = $invoice['due_date'] ?: null;

            $st = $pdo->prepare("SELECT id FROM crm_accounts WHERE id = :id LIMIT 1");
            $st->execute([':id' => $newAccountId]);
            if (!$st->fetchColumn()) {
                throw new RuntimeException('Ugyldig kunde/partner.');
            }

            $upd = $pdo->prepare("
                UPDATE billing_invoice_drafts
                   SET account_id = :account_id,
                       title = :title,
                       your_ref = :your_ref,
                       their_ref = :their_ref,
                       currency = :currency,
                       issue_date = :issue_date,
                       due_date = :due_date,
                       notes = :notes
                 WHERE id = :id
                 LIMIT 1
            ");
            $upd->execute([
                ':account_id' => $newAccountId,
                ':title' => $title !== '' ? $title : null,
                ':your_ref' => $yourRef !== '' ? $yourRef : null,
                ':their_ref' => $theirRef !== '' ? $theirRef : null,
                ':currency' => $currency,
                ':issue_date' => $issueDate,
                ':due_date' => $dueDate,
                ':notes' => $notes !== '' ? $notes : null,
                ':id' => $invoiceId,
            ]);

            header('Location: /?page=billing_invoice_edit&id=' . $invoiceId . '&msg=saved&header_saved=1#preview');
            exit;
        }

        if ($do === 'lock') {
            if ($locked) throw new RuntimeException('Allerede låst.');
            recalc_invoice_totals($pdo, $invoiceId);

            $upd = $pdo->prepare("UPDATE billing_invoice_drafts SET status = 'locked' WHERE id = :id LIMIT 1");
            $upd->execute([':id' => $invoiceId]);

            header('Location: /?page=billing_invoice_edit&id=' . $invoiceId . '&msg=locked');
            exit;
        }

        if ($do === 'unlock') {
            $upd = $pdo->prepare("UPDATE billing_invoice_drafts SET status = 'draft' WHERE id = :id LIMIT 1");
            $upd->execute([':id' => $invoiceId]);

            header('Location: /?page=billing_invoice_edit&id=' . $invoiceId . '&msg=unlocked');
            exit;
        }

        if ($do === 'delete_line') {
            if ($locked) throw new RuntimeException('Fakturagrunnlaget er låst.');
            $lineId = get_int($_POST, 'line_id', 0);
            if ($lineId <= 0) throw new RuntimeException('Ugyldig linje.');

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare("
                    SELECT id, line_type, batch_id, qty
                    FROM billing_invoice_lines
                    WHERE id = :id AND invoice_id = :inv
                    LIMIT 1
                    FOR UPDATE
                ");
                $st->execute([':id' => $lineId, ':inv' => $invoiceId]);
                $ln = $st->fetch(PDO::FETCH_ASSOC);
                if (!$ln) throw new RuntimeException('Fant ikke linje.');

                if (($ln['line_type'] ?? '') === 'PRODUCT') {
                    $batchId = (int)($ln['batch_id'] ?? 0);
                    $qty = (float)($ln['qty'] ?? 0);
                    if ($batchId > 0 && $qty > 0) {
                        // NB: dersom dere har annet kolonnenavn enn qty_remaining, bruk restock_invoice_products-strategien i stedet.
                        $updB = $pdo->prepare("
                            UPDATE inv_batches
                               SET qty_remaining = qty_remaining + :q1
                             WHERE id = :bid
                             LIMIT 1
                        ");
                        $updB->execute([':q1' => $qty, ':bid' => $batchId]);
                    }
                }

                $del = $pdo->prepare("DELETE FROM billing_invoice_lines WHERE id = :id AND invoice_id = :inv LIMIT 1");
                $del->execute([':id' => $lineId, ':inv' => $invoiceId]);

                recalc_invoice_totals($pdo, $invoiceId);
                $pdo->commit();

                header('Location: /?page=billing_invoice_edit&id=' . $invoiceId . '&msg=line_deleted&header_saved=1');
                exit;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($do === 'save_line') {
            if ($locked) throw new RuntimeException('Fakturagrunnlaget er låst.');

            $lineId   = get_int($_POST, 'line_id', 0);
            $lineType = get_str($_POST, 'line_type', 16, 'PRODUCT');
            if (!in_array($lineType, ['PRODUCT','SERVICE','MANUAL'], true)) $lineType = 'MANUAL';

            $desc = get_str($_POST, 'description', 255);
            $unit = get_str($_POST, 'unit', 32, 'stk');

            $qty = parse_int_qty(get_str($_POST, 'qty', 64, '1'), 1);
            if ($qty <= 0) $qty = 1;

            $productId = get_int($_POST, 'product_id', 0);
            $batchId   = (int)($_POST['batch_id'] ?? 0);

            // Fysisk lager velges i "Ny linje"
            $withdrawalLocationId = get_int($_POST, 'withdrawal_location_id', 0);

            // IKKE prosjekt/arbeidsordre i UI:
            $workOrderId = 0;

            // ERP-prosjekt (kost) hentes fra kunden
            $erp = get_erp_project($pdo, (int)$invoice['account_id']);
            $projectId = (int)($erp['project_id'] ?? 0); // kan være 0 -> lagre NULL

            // Må ha valgt fysisk lager før PRODUCT
            if ($lineType === 'PRODUCT' && $withdrawalLocationId <= 0) {
                throw new RuntimeException('Velg fysisk lager (lokasjon) i "Ny linje" før du velger batch.');
            }

            $vatRate = parse_decimal(get_str($_POST, 'vat_rate', 16, '0'), 0.0);
            if ($vatRate < 0) $vatRate = 0.0;
            if ($vatRate > 100) $vatRate = 100.0;

            // Intern kost - for PRODUCT settes denne alltid fra batch og kan ikke endres
            $costUnitPosted = parse_decimal(get_str($_POST, 'cost_unit', 64, '0'), 0.0);

            // Pricing mode
            $pricingMode = get_str($_POST, 'pricing_mode', 16, 'pct'); // pct|manual
            $markupPct   = null;
            $sellUnit    = 0.0;

            // Vi beregner først, men kan re-beregne etter at PRODUCT-kost er hentet fra batch
            if ($pricingMode === 'pct') {
                $markupPctVal = parse_decimal(get_str($_POST, 'markup_pct', 32, ''), 0.0);
                $markupPct = $markupPctVal;
                $sellUnit = $costUnitPosted > 0 ? ($costUnitPosted * (1.0 + ($markupPctVal / 100.0))) : 0.0;

                if ($lineType !== 'PRODUCT' && $sellUnit == 0.0) {
                    $sellUnit = parse_decimal(get_str($_POST, 'sell_unit', 64, '0'), 0.0);
                }
            } else {
                $sellUnit = parse_decimal(get_str($_POST, 'sell_unit', 64, '0'), 0.0);
                if ($costUnitPosted > 0 && $sellUnit > 0) {
                    $markupPct = (($sellUnit / $costUnitPosted) - 1.0) * 100.0;
                } else {
                    $markupPct = null;
                }
            }

            $costUnit = $costUnitPosted;

            // Finn evt lokasjonskolonne i inv_batches, for validering
            $batchLocCol = find_first_existing_column($pdo, 'inv_batches', [
                'location_id',
                'inv_location_id',
                'warehouse_location_id',
                'physical_location_id',
            ]);

            $pdo->beginTransaction();
            try {
                // Husk valgt lokasjon på draft (valgfritt, hvis kolonne finnes)
                if ($draftLocationCol) {
                    $updLoc = $pdo->prepare("UPDATE billing_invoice_drafts SET `$draftLocationCol` = :loc WHERE id = :id LIMIT 1");
                    $updLoc->execute([
                        ':loc' => ($withdrawalLocationId > 0 ? $withdrawalLocationId : null),
                        ':id' => $invoiceId
                    ]);
                }

                $prevLine = null;
                if ($lineId > 0) {
                    $st = $pdo->prepare("
                        SELECT *
                        FROM billing_invoice_lines
                        WHERE id = :id AND invoice_id = :inv
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $st->execute([':id' => $lineId, ':inv' => $invoiceId]);
                    $prevLine = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$prevLine) throw new RuntimeException('Fant ikke linje å oppdatere.');
                }

                // Hvis eksisterende linje var PRODUCT: tilbakefør den først (før vi tar ny)
                if ($prevLine && ($prevLine['line_type'] ?? '') === 'PRODUCT') {
                    $prevBatchId = (int)($prevLine['batch_id'] ?? 0);
                    $prevQty = (float)($prevLine['qty'] ?? 0);
                    if ($prevBatchId > 0 && $prevQty > 0) {
                        $updPrev = $pdo->prepare("
                            UPDATE inv_batches
                               SET qty_remaining = qty_remaining + :q1
                             WHERE id = :bid
                             LIMIT 1
                        ");
                        $updPrev->execute([':q1' => $prevQty, ':bid' => $prevBatchId]);
                    }
                }

                if ($lineType === 'PRODUCT') {
                    if ($productId <= 0) throw new RuntimeException('Velg vare.');
                    if ($batchId <= 0) throw new RuntimeException('Velg batch (påkrevd for lagervare).');

                    // Hent produkt (for desc/unit fallback)
                    if ($desc === '' || $unit === '' || $unit === 'stk') {
                        $st = $pdo->prepare("SELECT name, unit FROM inv_products WHERE id = :id LIMIT 1");
                        $st->execute([':id' => $productId]);
                        $p = $st->fetch(PDO::FETCH_ASSOC);
                        if ($p) {
                            if ($desc === '') $desc = (string)$p['name'];
                            if ($unit === '' || $unit === 'stk') $unit = (string)($p['unit'] ?? 'stk');
                        }
                    }

                    // Lås batch og sjekk beholdning + hent kost (og lokasjon hvis mulig)
                    $sel = "SELECT id, product_id, unit_price, qty_remaining";
                    if ($batchLocCol) $sel .= ", `$batchLocCol` AS batch_location_id";
                    $sel .= " FROM inv_batches WHERE id = :bid LIMIT 1 FOR UPDATE";

                    $st = $pdo->prepare($sel);
                    $st->execute([':bid' => $batchId]);
                    $b = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$b) throw new RuntimeException('Ugyldig batch.');
                    if ((int)$b['product_id'] !== $productId) {
                        throw new RuntimeException('Batch matcher ikke valgt vare.');
                    }

                    if ($batchLocCol) {
                        $batchLocId = (int)($b['batch_location_id'] ?? 0);
                        if ($withdrawalLocationId > 0 && $batchLocId > 0 && $batchLocId !== $withdrawalLocationId) {
                            throw new RuntimeException('Valgt batch tilhører et annet fysisk lager. Velg riktig batch.');
                        }
                    }

                    $rem = (float)$b['qty_remaining'];
                    $remInt = (int)floor($rem + 1e-9);
                    if ($qty > $remInt) {
                        throw new RuntimeException("Ikke nok på batch #{$batchId}. Tilgjengelig: {$remInt} stk.");
                    }

                    $costUnit = (float)$b['unit_price'];

                    $updB = $pdo->prepare("
                        UPDATE inv_batches
                           SET qty_remaining = qty_remaining - :q1
                         WHERE id = :bid
                           AND qty_remaining >= :q2
                         LIMIT 1
                    ");
                    $updB->execute([':q1' => (float)$qty, ':q2' => (float)$qty, ':bid' => $batchId]);
                    if ($updB->rowCount() !== 1) {
                        throw new RuntimeException("Kunne ikke reservere batch #{$batchId}. Prøv igjen.");
                    }
                } else {
                    // Ikke PRODUCT: batch og produkt nulles
                    $productId = 0;
                    $batchId = 0;
                }

                if ($desc === '') throw new RuntimeException('Beskrivelse må fylles ut.');

                // Recalc sell_unit etter at PRODUCT-kost er satt fra batch
                if ($pricingMode === 'pct') {
                    $markupPctVal = $markupPct ?? 0.0;
                    $sellUnit = $costUnit > 0 ? ($costUnit * (1.0 + ($markupPctVal / 100.0))) : $sellUnit;

                    if ($lineType !== 'PRODUCT' && $sellUnit == 0.0) {
                        $sellUnit = parse_decimal(get_str($_POST, 'sell_unit', 64, '0'), 0.0);
                    }
                } else {
                    if ($costUnit > 0 && $sellUnit > 0) {
                        $markupPct = (($sellUnit / $costUnit) - 1.0) * 100.0;
                    } else {
                        $markupPct = null;
                    }
                }

                $costTotal = ((float)$qty) * $costUnit;
                $sellTotal = ((float)$qty) * $sellUnit;

                $vatAmount = 0.0;
                if ($vatRate > 0) $vatAmount = $sellTotal * ($vatRate / 100.0);

                $projectIdDb = ($projectId > 0 ? $projectId : null);

                if ($lineId <= 0) {
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(line_no),0)+1 AS next_no FROM billing_invoice_lines WHERE invoice_id = :id");
                    $stmt->execute([':id' => $invoiceId]);
                    $nextNo = (int)($stmt->fetchColumn() ?: 1);

                    $ins = $pdo->prepare("
                        INSERT INTO billing_invoice_lines
                            (invoice_id, line_no, line_type,
                             product_id, batch_id, work_order_id, project_id,
                             description, unit, qty,
                             cost_unit, cost_total, markup_pct,
                             sell_unit, sell_total,
                             vat_rate, vat_amount,
                             created_at)
                        VALUES
                            (:invoice_id, :line_no, :line_type,
                             :product_id, :batch_id, :work_order_id, :project_id,
                             :description, :unit, :qty,
                             :cost_unit, :cost_total, :markup_pct,
                             :sell_unit, :sell_total,
                             :vat_rate, :vat_amount,
                             NOW())
                    ");
                    $ins->execute([
                        ':invoice_id' => $invoiceId,
                        ':line_no' => $nextNo,
                        ':line_type' => $lineType,
                        ':product_id' => $lineType === 'PRODUCT' ? $productId : null,
                        ':batch_id' => ($lineType === 'PRODUCT' && $batchId > 0) ? $batchId : null,
                        ':work_order_id' => null,
                        ':project_id' => $projectIdDb,
                        ':description' => $desc,
                        ':unit' => $unit !== '' ? $unit : 'stk',
                        ':qty' => (float)$qty,
                        ':cost_unit' => $costUnit,
                        ':cost_total' => $costTotal,
                        ':markup_pct' => $markupPct,
                        ':sell_unit' => $sellUnit,
                        ':sell_total' => $sellTotal,
                        ':vat_rate' => $vatRate,
                        ':vat_amount' => $vatAmount,
                    ]);
                } else {
                    $upd = $pdo->prepare("
                        UPDATE billing_invoice_lines
                           SET line_type = :line_type,
                               product_id = :product_id,
                               batch_id = :batch_id,
                               work_order_id = :work_order_id,
                               project_id = :project_id,
                               description = :description,
                               unit = :unit,
                               qty = :qty,
                               cost_unit = :cost_unit,
                               cost_total = :cost_total,
                               markup_pct = :markup_pct,
                               sell_unit = :sell_unit,
                               sell_total = :sell_total,
                               vat_rate = :vat_rate,
                               vat_amount = :vat_amount
                         WHERE id = :id AND invoice_id = :invoice_id
                         LIMIT 1
                    ");
                    $upd->execute([
                        ':line_type' => $lineType,
                        ':product_id' => $lineType === 'PRODUCT' ? $productId : null,
                        ':batch_id' => ($lineType === 'PRODUCT' && $batchId > 0) ? $batchId : null,
                        ':work_order_id' => null,
                        ':project_id' => $projectIdDb,
                        ':description' => $desc,
                        ':unit' => $unit !== '' ? $unit : 'stk',
                        ':qty' => (float)$qty,
                        ':cost_unit' => $costUnit,
                        ':cost_total' => $costTotal,
                        ':markup_pct' => $markupPct,
                        ':sell_unit' => $sellUnit,
                        ':sell_total' => $sellTotal,
                        ':vat_rate' => $vatRate,
                        ':vat_amount' => $vatAmount,
                        ':id' => $lineId,
                        ':invoice_id' => $invoiceId,
                    ]);
                }

                recalc_invoice_totals($pdo, $invoiceId);
                $pdo->commit();

                header('Location: /?page=billing_invoice_edit&id=' . $invoiceId . '&msg=line_saved&header_saved=1');
                exit;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// msg feedback
if (isset($_GET['msg'])) {
    $map = [
        'saved' => 'Lagret. Forhåndsvisning vises under.',
        'locked' => 'Fakturagrunnlaget er låst.',
        'unlocked' => 'Fakturagrunnlaget er åpnet igjen.',
        'line_saved' => 'Linje lagret.',
        'line_deleted' => 'Linje slettet.',
        'deleted' => 'Fakturagrunnlaget er slettet og varer er lagt tilbake til lager.',
    ];
    if (isset($map[$_GET['msg']])) $success = $map[$_GET['msg']];
}

// Refresh invoice after updates
if ($invoiceId > 0) {
    try {
        $selLoc = '';
        if ($draftLocationCol) $selLoc = ", d.`$draftLocationCol` AS withdrawal_location_id";

        $stmt = $pdo->prepare("
            SELECT d.*, a.name AS account_name, a.type AS account_type, a.payment_terms_days
            $selLoc
            FROM billing_invoice_drafts d
            JOIN crm_accounts a ON a.id = d.account_id
            WHERE d.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: $invoice;

        $account = [
            'id' => (int)$invoice['account_id'],
            'name' => (string)$invoice['account_name'],
            'type' => (string)$invoice['account_type'],
            'payment_terms_days' => (int)$invoice['payment_terms_days'],
        ];

        $erp = get_erp_project($pdo, (int)$invoice['account_id']);
        $erpProjectId = (int)($erp['project_id'] ?? 0);
        $erpProjectName = (string)($erp['project_name'] ?? '');
        $erpProjectSourceCol = (string)($erp['source_col'] ?? '');
    } catch (\Throwable $e) {}
}

$locked = ($invoice && is_locked($invoice));

// ---------------------------------------------------------
// Hent linjer + edit-linje hvis valgt
// ---------------------------------------------------------
$lines = [];
$editLine = null;

if ($invoiceId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT l.*,
                   p.name AS product_name,
                   p.unit AS product_unit
            FROM billing_invoice_lines l
            LEFT JOIN inv_products p ON p.id = l.product_id
            WHERE l.invoice_id = :id
            ORDER BY l.line_no ASC, l.id ASC
        ");
        $stmt->execute([':id' => $invoiceId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($editLineId > 0) {
            foreach ($lines as $ln) {
                if ((int)$ln['id'] === $editLineId) {
                    $editLine = $ln;
                    break;
                }
            }
            if (!$editLine) {
                $errors[] = 'Fant ikke linje å redigere.';
                $editLineId = 0;
            }
        }
    } catch (\Throwable $e) {
        $errors[] = 'Databasefeil ved henting av linjer: ' . $e->getMessage();
        $lines = [];
    }
}

// ---------------------------------------------------------
// Opprett nytt fakturagrunnlag UI hvis ingen invoice
// ---------------------------------------------------------
if ($invoiceId <= 0): ?>
    <div class="mt-3">
        <h3>Fakturagrunnlag</h3>
        <?php if ($errors): ?>
            <div class="alert alert-danger mt-3">
                <?= nl2br(h(implode("\n", $errors))) ?>
            </div>
        <?php endif; ?>

        <div class="card mt-3">
            <div class="card-header"><strong>Opprett nytt fakturagrunnlag</strong></div>
            <div class="card-body">
                <form method="get" class="row g-2">
                    <input type="hidden" name="page" value="billing_invoice_edit">
                    <div class="col-md-8">
                        <label class="form-label">Kunde/partner</label>
                        <select class="form-select" name="account_id" required>
                            <option value="">Velg...</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= (int)$a['id'] ?>">
                                    <?= h($a['name']) ?> <?= $a['type']==='partner' ? '(Partner)' : '(Kunde)' ?><?= ((int)$a['is_active']===0) ? ' [inaktiv]' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 align-self-end">
                        <button class="btn btn-primary">Opprett</button>
                    </div>
                </form>
                <div class="text-muted small mt-2">
                    Oppretting lager et draft med forfallsdato basert på betalingsbetingelser.
                </div>
            </div>
        </div>
    </div>
<?php
return;
endif;

// ---------------------------------------------------------
// Form defaults for line editor
// ---------------------------------------------------------
$draftRememberedLocationId = (int)($invoice['withdrawal_location_id'] ?? 0);

$lineForm = [
    'line_id' => $editLine ? (int)$editLine['id'] : 0,
    'line_type' => $editLine['line_type'] ?? 'PRODUCT',
    'product_id' => $editLine ? (int)($editLine['product_id'] ?? 0) : 0,
    'batch_id' => $editLine ? (int)($editLine['batch_id'] ?? 0) : 0,
    'description' => $editLine['description'] ?? '',
    'unit' => $editLine['unit'] ?? 'stk',
    'qty' => $editLine ? (string)((int)round((float)$editLine['qty'])) : '1',
    'cost_unit' => $editLine ? (string)$editLine['cost_unit'] : '0.00',
    'markup_pct' => $editLine ? (string)($editLine['markup_pct'] ?? '') : '',
    'sell_unit' => $editLine ? (string)$editLine['sell_unit'] : '0.00',
    'vat_rate' => $editLine ? (string)$editLine['vat_rate'] : '0',
    'pricing_mode' => 'pct',
    'withdrawal_location_id' => $draftRememberedLocationId,
];

if ($editLine) {
    if ($editLine['markup_pct'] === null || $editLine['markup_pct'] === '') {
        $lineForm['pricing_mode'] = 'manual';
    } else {
        $lineForm['pricing_mode'] = 'pct';
    }
}

// ---------------------------------------------------------
// Page header
// ---------------------------------------------------------
$status  = (string)($invoice['status'] ?? 'draft');
$blocked = ['sent', 'paid', 'booked', 'exported', 'closed'];
$canDeleteInvoice = !in_array($status, $blocked, true);

$headerSaved = (isset($_GET['header_saved']) && (string)$_GET['header_saved'] === '1');

// Hode "fylt ut"? -> vi bruker headerSaved som indikator (etter "Lagre hode")
$collapseHeaderByDefault = $headerSaved;

// Vis "sist valgte" fysiske lager (huskelapp på draft)
$withdrawalLocationName = '';
if ($draftRememberedLocationId > 0 && $locations) {
    foreach ($locations as $l) {
        if ((int)$l['id'] === $draftRememberedLocationId) {
            $withdrawalLocationName = (string)$l['name'];
            break;
        }
    }
}
?>
<div class="d-flex align-items-start justify-content-between mt-3">
    <div>
        <h3 class="mb-1">Fakturagrunnlag #<?= (int)$invoice['id'] ?></h3>
        <div class="text-muted">
            <?= h($account['name']) ?> · Status:
            <?php if ($invoice['status'] === 'draft'): ?>
                <span class="badge bg-secondary">Draft</span>
            <?php elseif ($invoice['status'] === 'locked'): ?>
                <span class="badge bg-warning text-dark">Låst</span>
            <?php else: ?>
                <span class="badge bg-info text-dark"><?= h($invoice['status']) ?></span>
            <?php endif; ?>
        </div>

        <div class="text-muted small mt-1">
            ERP-prosjekt (intern): <strong>
                <?php if ($erpProjectId > 0): ?>
                    <?= h($erpProjectName !== '' ? $erpProjectName : ('#'.$erpProjectId)) ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </strong>
            <?php if ($erpProjectSourceCol !== ''): ?>
                <span class="text-muted"> (fra crm_accounts.<?= h($erpProjectSourceCol) ?>)</span>
            <?php endif; ?>
        </div>

        <?php if ($draftRememberedLocationId > 0): ?>
            <div class="text-muted small mt-1">
                Sist valgt fysisk lager: <strong><?= h($withdrawalLocationName !== '' ? $withdrawalLocationName : ('#'.$draftRememberedLocationId)) ?></strong>
            </div>
        <?php endif; ?>
    </div>

    <div class="text-end">
        <div class="text-muted">Sum</div>
        <div style="font-size:1.25rem;">
            <strong><?= money((float)$invoice['total']) ?></strong> <?= h($invoice['currency'] ?? 'NOK') ?>
        </div>
        <div class="text-muted small">
            Del: <?= money((float)$invoice['subtotal']) ?> + MVA <?= money((float)$invoice['vat_amount']) ?>
        </div>

        <div class="mt-2 d-flex justify-content-end gap-2">
            <a class="btn btn-sm btn-outline-secondary"
               href="/?page=billing_invoice_print&id=<?= (int)$invoice['id'] ?>"
               target="_blank">
                <i class="bi bi-printer"></i> Utskrift
            </a>

            <?php if (!$locked): ?>
                <form method="post" onsubmit="return confirm('Låse fakturagrunnlaget? Etter låsing bør det ikke endres.');">
                    <input type="hidden" name="do" value="lock">
                    <button class="btn btn-sm btn-warning">
                        <i class="bi bi-lock"></i> Lås
                    </button>
                </form>
            <?php else: ?>
                <form method="post" onsubmit="return confirm('Åpne igjen? (valgfritt)');">
                    <input type="hidden" name="do" value="unlock">
                    <button class="btn btn-sm btn-outline-light">
                        <i class="bi bi-unlock"></i> Åpne
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($canDeleteInvoice): ?>
                <form method="post"
                      onsubmit="return confirm('Slette fakturagrunnlaget? Varer legges tilbake til lager. Dette kan ikke angres.');">
                    <input type="hidden" name="do" value="delete_invoice">
                    <button class="btn btn-sm btn-danger">
                        <i class="bi bi-trash"></i> Slett
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success mt-3"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger mt-3">
        <strong>Feil:</strong><br>
        <?= nl2br(h(implode("\n", $errors))) ?>
    </div>
<?php endif; ?>

<?php if ($headerSaved): ?>
    <div class="card mt-3" id="preview">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Forhåndsvisning</strong>
            <span class="text-muted small">Slik vil fakturaen se ut. Legg så til linjer under.</span>
        </div>
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="fw-semibold"><?= h($account['name']) ?></div>
                    <div class="text-muted small">
                        <?= h(($account['type'] ?? '') === 'partner' ? 'Partner' : 'Kunde') ?>
                    </div>
                </div>
                <div class="text-end">
                    <div><strong><?= h($invoice['title'] ?? 'Fakturagrunnlag') ?></strong></div>
                    <div class="text-muted small">
                        Dato: <?= h($invoice['issue_date'] ?? '') ?> · Forfall: <?= h($invoice['due_date'] ?? '') ?>
                    </div>
                    <div class="text-muted small">Valuta: <?= h($invoice['currency'] ?? 'NOK') ?></div>
                </div>
            </div>

            <hr>

            <div class="row g-2">
                <div class="col-md-6">
                    <div class="text-muted small">Vår ref</div>
                    <div><?= h($invoice['your_ref'] ?? '—') ?></div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">Deres ref</div>
                    <div><?= h($invoice['their_ref'] ?? '—') ?></div>
                </div>
            </div>

            <?php if (!empty($invoice['notes'])): ?>
                <div class="mt-3">
                    <div class="text-muted small">Notat</div>
                    <div><?= nl2br(h($invoice['notes'])) ?></div>
                </div>
            <?php endif; ?>

            <hr>

            <div class="d-flex justify-content-end">
                <div style="min-width: 320px;">
                    <div class="d-flex justify-content-between">
                        <div class="text-muted">Subtotal</div>
                        <div><strong><?= money((float)$invoice['subtotal']) ?></strong></div>
                    </div>
                    <div class="d-flex justify-content-between">
                        <div class="text-muted">MVA</div>
                        <div><strong><?= money((float)$invoice['vat_amount']) ?></strong></div>
                    </div>
                    <div class="d-flex justify-content-between">
                        <div class="text-muted">Total</div>
                        <div style="font-size:1.1rem;"><strong><?= money((float)$invoice['total']) ?></strong></div>
                    </div>
                </div>
            </div>

            <?php if (!$lines): ?>
                <div class="alert alert-info mt-3 mb-0">
                    Ingen linjer enda. Legg til varelinjer under.
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mt-1">
    <!-- Header edit -->
    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div>
                    <strong>Hode</strong>
                    <?php if ($locked): ?>
                        <span class="badge bg-warning text-dark ms-2">Låst</span>
                    <?php endif; ?>
                </div>

                <button type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="collapse"
                        data-bs-target="#invoiceHeaderCollapse"
                        aria-expanded="<?= $collapseHeaderByDefault ? 'false' : 'true' ?>"
                        aria-controls="invoiceHeaderCollapse"
                        title="Vis/skjul hode">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>

            <div class="collapse <?= $collapseHeaderByDefault ? '' : 'show' ?>" id="invoiceHeaderCollapse">
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="do" value="save_header">

                        <div class="mb-2">
                            <label class="form-label">Kunde/partner</label>
                            <select class="form-select" name="account_id" <?= $locked ? 'disabled' : '' ?>>
                                <?php foreach ($accounts as $a): ?>
                                    <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id'] === (int)$invoice['account_id']) ? 'selected' : '' ?>>
                                        <?= h($a['name']) ?> <?= $a['type']==='partner' ? '(Partner)' : '(Kunde)' ?><?= ((int)$a['is_active']===0) ? ' [inaktiv]' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($locked): ?>
                                <div class="text-muted small mt-1">Låst: kunde kan ikke endres.</div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Tittel</label>
                            <input class="form-control" name="title" value="<?= h($invoice['title'] ?? 'Fakturagrunnlag') ?>" <?= $locked ? 'disabled' : '' ?>>
                        </div>

                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">Vår ref</label>
                                <input class="form-control" name="your_ref" value="<?= h($invoice['your_ref'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Deres ref</label>
                                <input class="form-control" name="their_ref" value="<?= h($invoice['their_ref'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>>
                            </div>
                        </div>

                        <div class="row g-2 mt-2">
                            <div class="col-md-4">
                                <label class="form-label">Valuta</label>
                                <input class="form-control" name="currency" value="<?= h($invoice['currency'] ?? 'NOK') ?>" <?= $locked ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Dato</label>
                                <input type="date" class="form-control" name="issue_date" value="<?= h($invoice['issue_date'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Forfall</label>
                                <input type="date" class="form-control" name="due_date" value="<?= h($invoice['due_date'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>>
                            </div>
                        </div>

                        <div class="mt-2">
                            <label class="form-label">Notat (vises på utskrift)</label>
                            <textarea class="form-control" name="notes" rows="3" <?= $locked ? 'disabled' : '' ?>><?= h($invoice['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="mt-3">
                            <button class="btn btn-primary" <?= $locked ? 'disabled' : '' ?>>
                                <i class="bi bi-save"></i> Lagre hode
                            </button>
                        </div>

                        <div class="text-muted small mt-2">
                            Kostnad føres automatisk på kundens ERP-prosjekt (intern referanse).
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Line editor -->
        <div class="card mt-3">
            <div class="card-header">
                <strong><?= $editLine ? 'Rediger linje' : 'Ny linje' ?></strong>
            </div>
            <div class="card-body">
                <?php if ($locked): ?>
                    <div class="alert alert-warning">
                        Fakturagrunnlaget er låst. Linjer kan ikke endres.
                    </div>
                <?php endif; ?>

                <form method="post" id="lineForm">
                    <input type="hidden" name="do" value="save_line">
                    <input type="hidden" name="line_id" value="<?= (int)$lineForm['line_id'] ?>">

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="line_type" id="line_type" <?= $locked ? 'disabled' : '' ?>>
                                <option value="PRODUCT" <?= $lineForm['line_type'] === 'PRODUCT' ? 'selected' : '' ?>>Vare (lager)</option>
                                <option value="SERVICE" <?= $lineForm['line_type'] === 'SERVICE' ? 'selected' : '' ?>>Konsulent (timer)</option>
                                <option value="MANUAL"  <?= $lineForm['line_type'] === 'MANUAL' ? 'selected' : '' ?>>Manuell</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">MVA %</label>
                            <input class="form-control" name="vat_rate" value="<?= h($lineForm['vat_rate']) ?>" <?= $locked ? 'disabled' : '' ?> placeholder="0 eller 25">
                        </div>
                    </div>

                    <!-- Fysisk lager velges her (Ny linje) -->
                    <div class="mt-2" id="line_location_wrap">
                        <label class="form-label">Fysisk lager (påkrevd for lagervare)</label>
                        <select class="form-select" name="withdrawal_location_id" id="line_location_id" <?= $locked ? 'disabled' : '' ?>>
                            <option value="0">Velg fysisk lager...</option>
                            <?php foreach ($locations as $l): ?>
                                <option value="<?= (int)$l['id'] ?>" <?= ((int)$l['id'] === (int)$lineForm['withdrawal_location_id']) ? 'selected' : '' ?>>
                                    <?= h($l['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted small mt-1">
                            Viktig: velg fysisk lager først, ellers blir tellingen feil. Batch vises etterpå.
                        </div>
                    </div>

                    <div class="mt-2" id="product_picker">
                        <label class="form-label">Vare</label>
                        <select class="form-select" name="product_id" id="product_id" <?= $locked ? 'disabled' : '' ?>>
                            <option value="0">Velg vare...</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === (int)$lineForm['product_id']) ? 'selected' : '' ?>>
                                    <?= h($p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="row g-2 mt-2">
                            <div class="col-md-6">
                                <label class="form-label">Batch</label>
                                <select class="form-select" name="batch_id" id="batch_id" <?= $locked ? 'disabled' : '' ?>>
                                    <option value="0">Velg batch...</option>
                                </select>
                                <div class="text-muted small mt-1">
                                    Batch vises først etter at fysisk lager er valgt.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Kost/enhet (intern)</label>
                                <input class="form-control" name="cost_unit" id="cost_unit" value="<?= h($lineForm['cost_unit']) ?>" <?= $locked ? 'disabled' : '' ?> readonly>
                                <div class="text-muted small mt-1">Settes automatisk fra batch og kan ikke endres.</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-2">
                        <label class="form-label">Beskrivelse</label>
                        <input class="form-control" name="description" id="description" value="<?= h($lineForm['description']) ?>" <?= $locked ? 'disabled' : '' ?> placeholder="Varenavn / tjeneste / manuell tekst">
                        <div class="text-muted small mt-1">
                            For lagervare: hvis du endrer beskrivelse, <strong>skriver den over</strong> standard varenavn på linjen.
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-md-4">
                            <label class="form-label">Enhet</label>
                            <input class="form-control" name="unit" value="<?= h($lineForm['unit']) ?>" <?= $locked ? 'disabled' : '' ?> placeholder="stk / timer">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Antall</label>
                            <input class="form-control" name="qty" id="qty" value="<?= h($lineForm['qty']) ?>" <?= $locked ? 'disabled' : '' ?> placeholder="1">
                            <div class="text-muted small mt-1">Antall er heltall.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Pris-modus</label>
                            <select class="form-select" name="pricing_mode" id="pricing_mode" <?= $locked ? 'disabled' : '' ?>>
                                <option value="pct" <?= $lineForm['pricing_mode'] === 'pct' ? 'selected' : '' ?>>% påslag</option>
                                <option value="manual" <?= $lineForm['pricing_mode'] === 'manual' ? 'selected' : '' ?>>Manuell utpris</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-md-6">
                            <label class="form-label">Påslag % (intern)</label>
                            <input class="form-control" name="markup_pct" id="markup_pct" value="<?= h($lineForm['markup_pct']) ?>" <?= $locked ? 'disabled' : '' ?> placeholder="f.eks. 25">
                            <div class="text-muted small mt-1">Kunden ser ikke påslag.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Utpris/enhet (kunde)</label>
                            <input class="form-control" name="sell_unit" id="sell_unit" value="<?= h($lineForm['sell_unit']) ?>" <?= $locked ? 'disabled' : '' ?> placeholder="f.eks. 125.00">
                            <div class="text-muted small mt-1">Pris kan ha 2 desimaler.</div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-primary" <?= $locked ? 'disabled' : '' ?>>
                            <i class="bi bi-check2"></i> <?= $editLine ? 'Lagre linje' : 'Legg til linje' ?>
                        </button>
                        <?php if ($editLine): ?>
                            <a class="btn btn-outline-secondary" href="/?page=billing_invoice_edit&id=<?= (int)$invoiceId ?>&header_saved=1">
                                Avbryt
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="text-muted small mt-2">
                        Tips: Velg <em>% påslag</em> for automatisk utpris basert på kost, eller <em>Manuell utpris</em> for å få beregnet påslag (lagres internt).
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Lines list -->
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <strong>Linjer</strong>
                <span class="text-muted small">
                    Kunden ser kun utpris og linjesum (ikke kost/påslag).
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Beskrivelse</th>
                        <th class="text-end">Antall</th>
                        <th>Enhet</th>
                        <th class="text-end">Utpris</th>
                        <th class="text-end">Linjesum</th>
                        <th class="text-end">MVA</th>
                        <th class="text-end">Handling</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$lines): ?>
                        <tr>
                            <td colspan="8" class="text-muted">Ingen linjer enda.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($lines as $ln): ?>
                            <tr>
                                <td class="text-muted"><?= (int)$ln['line_no'] ?></td>
                                <td>
                                    <div class="fw-semibold"><?= h($ln['description']) ?></div>
                                    <div class="text-muted small">
                                        <?= h($ln['line_type']) ?>
                                        <?php if (!empty($ln['product_name'])): ?>
                                            · Vare: <?= h($ln['product_name']) ?>
                                        <?php endif; ?>
                                        <?php if (!empty($ln['batch_id'])): ?>
                                            · Batch #<?= (int)$ln['batch_id'] ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-end"><?= qtyfmt((float)$ln['qty']) ?></td>
                                <td class="text-muted"><?= h($ln['unit']) ?></td>
                                <td class="text-end"><?= money((float)$ln['sell_unit']) ?></td>
                                <td class="text-end"><strong><?= money((float)$ln['sell_total']) ?></strong></td>
                                <td class="text-end text-muted"><?= money((float)$ln['vat_amount']) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="/?page=billing_invoice_edit&id=<?= (int)$invoiceId ?>&edit_line_id=<?= (int)$ln['id'] ?>&header_saved=1"
                                       <?= $locked ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                                        Rediger
                                    </a>
                                    <?php if (!$locked): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Slette linjen? Lager tilbakeføres for Vare (lager).');">
                                            <input type="hidden" name="do" value="delete_line">
                                            <input type="hidden" name="line_id" value="<?= (int)$ln['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger">Slett</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <td colspan="5" class="text-end text-muted">Subtotal</td>
                        <td class="text-end"><strong><?= money((float)$invoice['subtotal']) ?></strong></td>
                        <td class="text-end text-muted"><?= money((float)$invoice['vat_amount']) ?></td>
                        <td class="text-end"><strong><?= money((float)$invoice['total']) ?></strong></td>
                    </tr>
                    </tfoot>
                </table>
            </div>

            <div class="card-footer text-muted small">
                Status <strong><?= h($invoice['status']) ?></strong>. Ved låsing bør du normalt ikke endre linjer.
            </div>
        </div>
    </div>
</div>

<script>
(function(){
  function syncTypeUI() {
    var typeSel = document.getElementById('line_type');
    var picker  = document.getElementById('product_picker');
    var locWrap = document.getElementById('line_location_wrap');
    var locSel  = document.getElementById('line_location_id');
    var prodSel = document.getElementById('product_id');
    var batchSel= document.getElementById('batch_id');

    if (!typeSel) return;

    var isProd = (typeSel.value === 'PRODUCT');

    if (picker) picker.style.display = isProd ? 'block' : 'none';
    if (locWrap) locWrap.style.display = isProd ? 'block' : 'none';

    // Krev lokasjon før prod/batch (for PRODUCT)
    var locOk = true;
    if (isProd) locOk = !!(locSel && Number(locSel.value || 0) > 0);

    if (prodSel) prodSel.disabled = isProd ? !locOk : false;
    if (batchSel) batchSel.disabled = isProd ? !locOk : false;

    var cost = document.getElementById('cost_unit');
    if (cost) cost.readOnly = isProd;
  }

  function parseNum(v) {
    v = (v || '').toString().trim();
    if (!v) return null;
    v = v.replace(/\s+/g, '').replace(',', '.');
    v = v.replace(/[^0-9.\-]/g, '');
    if (v === '' || v === '-' || v === '.' || v === '-.') return null;
    var n = Number(v);
    return Number.isFinite(n) ? n : null;
  }

  function fmt2(n) {
    if (!Number.isFinite(n)) return '';
    return n.toFixed(2).replace('.', ',');
  }

  function setValue(el, val) {
    if (!el) return;
    el.value = val;
  }

  let isSyncing = false;

  function syncFromMarkup() {
    if (isSyncing) return;
    const costInp = document.getElementById('cost_unit');
    const pctInp  = document.getElementById('markup_pct');
    const sellInp = document.getElementById('sell_unit');
    const modeSel = document.getElementById('pricing_mode');

    const cost = parseNum(costInp ? costInp.value : '');
    const pct  = parseNum(pctInp ? pctInp.value : '');

    if (cost === null || cost <= 0 || pct === null) return;

    const sell = cost * (1 + pct/100);

    isSyncing = true;
    if (modeSel) modeSel.value = 'pct';
    setValue(sellInp, fmt2(sell));
    isSyncing = false;
  }

  function syncFromSell() {
    if (isSyncing) return;
    const costInp = document.getElementById('cost_unit');
    const pctInp  = document.getElementById('markup_pct');
    const sellInp = document.getElementById('sell_unit');
    const modeSel = document.getElementById('pricing_mode');

    const cost = parseNum(costInp ? costInp.value : '');
    const sell = parseNum(sellInp ? sellInp.value : '');

    if (cost === null || cost <= 0 || sell === null) return;

    const pct = ((sell / cost) - 1) * 100;

    isSyncing = true;
    if (modeSel) modeSel.value = 'manual';
    setValue(pctInp, (Number.isFinite(pct) ? pct.toFixed(2).replace('.', ',') : ''));
    isSyncing = false;
  }

  async function loadBatchesForProduct(productId, selectedBatchId) {
    var batchSel = document.getElementById('batch_id');
    var costInp  = document.getElementById('cost_unit');
    var locSel   = document.getElementById('line_location_id');

    if (!batchSel) return;

    const locationId = Number(locSel ? (locSel.value || 0) : 0);

    if (!locationId || locationId <= 0) {
      batchSel.innerHTML = '<option value="0">Velg fysisk lager først...</option>';
      if (costInp) costInp.value = '0,00';
      return;
    }

    batchSel.innerHTML = '<option value="0">Laster batcher...</option>';

    if (!productId || productId <= 0) {
      batchSel.innerHTML = '<option value="0">Velg batch...</option>';
      if (costInp) costInp.value = '0,00';
      return;
    }

    try {
      const url = new URL(window.location.origin + '/');
      url.searchParams.set('page', 'billing_invoice_edit');
      url.searchParams.set('id', String(<?= (int)$invoiceId ?>));
      url.searchParams.set('ajax', 'batches');
      url.searchParams.set('product_id', String(productId));
      url.searchParams.set('location_id', String(locationId));

      const res = await fetch(url.toString(), {
        headers: {'Accept': 'application/json'},
        credentials: 'same-origin',
        cache: 'no-store'
      });

      const text = await res.text();
      let js;
      try {
        js = JSON.parse(text);
      } catch (e) {
        console.error('Batch AJAX returnerte ikke JSON', { status: res.status, text: text.slice(0, 800) });
        throw new Error('Server returnerte ikke JSON (se console).');
      }

      if (!res.ok || !js || !js.ok) {
        const msg = (js && js.error) ? js.error : ('HTTP ' + res.status);
        throw new Error(msg);
      }

      const batches = js.batches || [];
      let html = '<option value="0">Velg batch...</option>';
      if (!batches.length) {
        html = '<option value="0">Ingen batcher med beholdning på valgt lager</option>';
      } else {
        for (const b of batches) {
          const sel = (selectedBatchId && Number(selectedBatchId) === Number(b.id)) ? ' selected' : '';
          html += `<option value="${b.id}" data-unit-price="${b.unit_price}" data-qty="${b.qty_remaining}"${sel}>${b.label}</option>`;
        }
      }
      batchSel.innerHTML = html;

      // Sett kost hvis batch er valgt
      if (selectedBatchId && Number(selectedBatchId) > 0) {
        const opt = batchSel.querySelector(`option[value="${selectedBatchId}"]`);
        if (opt && costInp) {
          const p = Number(opt.getAttribute('data-unit-price') || 0);
          costInp.value = fmt2(p);
        }
      } else {
        if (costInp) costInp.value = '0,00';
      }

      const modeSel = document.getElementById('pricing_mode');
      if (modeSel && modeSel.value === 'pct') syncFromMarkup();

    } catch (e) {
      console.error('Feil ved batch-hent', e);
      batchSel.innerHTML = `<option value="0">Feil: ${String(e && e.message ? e.message : e)}</option>`;
      if (costInp) costInp.value = '0,00';
    }
  }

  function onBatchChange() {
    var batchSel = document.getElementById('batch_id');
    var costInp  = document.getElementById('cost_unit');
    if (!batchSel || !costInp) return;

    var opt = batchSel.options[batchSel.selectedIndex];
    if (!opt) return;

    var p = Number(opt.getAttribute('data-unit-price') || 0);
    costInp.value = fmt2(p);

    const modeSel = document.getElementById('pricing_mode');
    if (modeSel && modeSel.value === 'pct') {
      syncFromMarkup();
    } else {
      syncFromSell();
    }

    var qtyInp = document.getElementById('qty');
    var maxQty = Number(opt.getAttribute('data-qty') || 0);
    if (qtyInp && maxQty > 0) {
      var q = Number((qtyInp.value || '0').toString().replace(',', '.'));
      if (q > maxQty) {
        qtyInp.setCustomValidity('Antall overstiger tilgjengelig på valgt batch (' + maxQty + ' stk).');
      } else {
        qtyInp.setCustomValidity('');
      }
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    var typeSel  = document.getElementById('line_type');
    var prodSel  = document.getElementById('product_id');
    var batchSel = document.getElementById('batch_id');
    var locSel   = document.getElementById('line_location_id');

    var pctInp   = document.getElementById('markup_pct');
    var sellInp  = document.getElementById('sell_unit');
    var modeSel  = document.getElementById('pricing_mode');

    syncTypeUI();
    if (typeSel) typeSel.addEventListener('change', function(){
      syncTypeUI();
      // hvis bytter til PRODUCT og det allerede er valgt lokasjon + produkt => last batch
      if (typeSel.value === 'PRODUCT' && prodSel) {
        loadBatchesForProduct(Number(prodSel.value || 0), 0);
      }
    });

    if (locSel) {
      locSel.addEventListener('change', function(){
        syncTypeUI();
        // Bytt lokasjon => reload batcher for valgt produkt
        if (prodSel) loadBatchesForProduct(Number(prodSel.value || 0), 0);
      });
    }

    if (pctInp) {
      pctInp.addEventListener('input', function(){
        if (isSyncing) return;
        if (modeSel) modeSel.value = 'pct';
        syncFromMarkup();
      });
    }

    if (sellInp) {
      sellInp.addEventListener('input', function(){
        if (isSyncing) return;
        if (modeSel) modeSel.value = 'manual';
        syncFromSell();
      });
    }

    if (modeSel) {
      modeSel.addEventListener('change', function(){
        if (modeSel.value === 'pct') syncFromMarkup();
        else syncFromSell();
      });
    }

    var initialProd  = Number(<?= (int)$lineForm['product_id'] ?>);
    var initialBatch = Number(<?= (int)$lineForm['batch_id'] ?>);

    if (prodSel) {
      prodSel.addEventListener('change', function(){
        loadBatchesForProduct(Number(prodSel.value || 0), 0);
      });
      // Initial last (vil vise "velg fysisk lager først" hvis ikke valgt)
      loadBatchesForProduct(initialProd, initialBatch);
    }

    if (batchSel) batchSel.addEventListener('change', onBatchChange);

    if (modeSel && modeSel.value === 'pct') syncFromMarkup();
    if (modeSel && modeSel.value === 'manual') syncFromSell();
  });
})();
</script>
