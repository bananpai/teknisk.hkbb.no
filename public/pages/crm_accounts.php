<?php
// public/pages/crm_accounts.php

use App\Database;

// ---------------------------------------------------------
// DB + innlogging
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang til kunderegister.</div>
    <?php
    return;
}

try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    http_response_code(500);
    ?>
    <div class="alert alert-danger mt-3">Klarte ikke koble til databasen.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Rolle-guard (user_roles + session perms/roles)
// ---------------------------------------------------------
if (!function_exists('normalize_list')) {
    function normalize_list($v): array {
        if (is_array($v)) return array_values(array_filter(array_map('strval', $v)));
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
            $u = end($parts) ?: $u;
        }
        if (strpos($u, '@') !== false) {
            $u = explode('@', $u)[0] ?: $u;
        }
        return trim($u);
    }
}

$roles = normalize_list($_SESSION['roles'] ?? null);
$perms = normalize_list($_SESSION['permissions'] ?? null);

$currentUserId = 0;

try {
    $raw  = trim((string)$username);
    $norm = normalizeUsername($raw);

    // Finn user_id via username (case-insensitive) – prøv raw og norm
    $st = $pdo->prepare("
        SELECT u.id
          FROM users u
         WHERE LOWER(u.username) = LOWER(:u)
         LIMIT 1
    ");

    $st->execute([':u' => $raw]);
    $currentUserId = (int)($st->fetchColumn() ?: 0);

    if ($currentUserId <= 0 && $norm !== '' && $norm !== $raw) {
        $st->execute([':u' => $norm]);
        $currentUserId = (int)($st->fetchColumn() ?: 0);
    }

    // Hent roller fra user_roles
    if ($currentUserId > 0) {
        $st2 = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $st2->execute([':uid' => $currentUserId]);
        $dbRoles = $st2->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $roles = array_merge($roles, normalize_list($dbRoles));
    }
} catch (\Throwable $e) {
    // fall back
}

$roles = array_values(array_unique(array_map('strtolower', $roles)));
$perms = array_values(array_unique(array_map('strtolower', $perms)));

// Admin via rolle (behold også session-fallback for bakoverkompatibilitet)
$isAdmin = has_any(['admin'], $roles) || (bool)($_SESSION['is_admin'] ?? false);

// ✅ CRM-tilgang: nå støtter vi også "invoice" (faktura-rolle)
$canCrmAccess = $isAdmin
    || has_any(
        [
            // Ny standard
            'invoice', 'faktura',

            // Eksisterende/legacy
            'support', 'crm', 'billing',

            // Hvis dere tidligere lot logistikk/lager gi CRM-tilgang,
            // beholdt for bakoverkompatibilitet (kan strammes inn senere)
            'logistikk', 'lager', 'inventory',
        ],
        $roles
    )
    || has_any(
        [
            'invoice', 'faktura',
            'support', 'crm', 'billing',
            'logistikk', 'lager', 'inventory',
        ],
        $perms
    );

if (!$canCrmAccess) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang til kunderegister.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('post_str')) {
    function post_str(string $key, int $maxLen = 255): string {
        $v = trim((string)($_POST[$key] ?? ''));
        if ($maxLen > 0 && mb_strlen($v, 'UTF-8') > $maxLen) {
            $v = mb_substr($v, 0, $maxLen, 'UTF-8');
        }
        return $v;
    }
}
if (!function_exists('post_int')) {
    function post_int(string $key, int $default = 0): int {
        $v = $_POST[$key] ?? null;
        if ($v === null || $v === '') return $default;
        return (int)$v;
    }
}
if (!function_exists('post_bool')) {
    function post_bool(string $key): int {
        return isset($_POST[$key]) ? 1 : 0;
    }
}
if (!function_exists('normalize_orgno')) {
    function normalize_orgno(string $orgno): string {
        $orgno = preg_replace('/\D+/', '', $orgno ?? '');
        return $orgno ?: '';
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
if (!function_exists('first_existing_col')) {
    function first_existing_col(PDO $pdo, string $table, array $candidates): ?string {
        foreach ($candidates as $c) {
            if (column_exists($pdo, $table, $c)) return $c;
        }
        return null;
    }
}

// ---------------------------------------------------------
// Finn "uttak"-kolonner + ERP-prosjektref i crm_accounts
// ---------------------------------------------------------
$withdrawProjectCol = first_existing_col($pdo, 'crm_accounts', [
    'withdrawal_project_id',
    'default_project_id',
    'invoice_project_id',
    'project_id',
]);
$withdrawWorkOrderCol = first_existing_col($pdo, 'crm_accounts', [
    'withdrawal_work_order_id',
    'default_work_order_id',
    'invoice_work_order_id',
    'work_order_id',
]);

// NY: ERP-prosjektref (intern)
$erpProjectRefCol = first_existing_col($pdo, 'crm_accounts', [
    'erp_project_ref',
    'erp_project_code',
    'erp_project',
    'erp_project_no',
    'erp_project_number',
]);

$showWithdrawFields = (bool)($withdrawProjectCol || $withdrawWorkOrderCol);
$showErpField = (bool)$erpProjectRefCol;

// ---------------------------------------------------------
// Input (GET)
// ---------------------------------------------------------
$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;

$q        = trim((string)($_GET['q'] ?? ''));
$type     = $_GET['type'] ?? 'all'; // all|customer|partner
$isActive = $_GET['active'] ?? '1'; // 1|0|all

// ---------------------------------------------------------
// Dropdown data (projects/work_orders) for uttaksfelter
// ---------------------------------------------------------
$projects = [];
$workOrders = [];

if ($showWithdrawFields) {
    try {
        if ($withdrawProjectCol) {
            $projects = $pdo->query("
                SELECT id, name
                FROM projects
                WHERE is_active = 1
                ORDER BY name ASC
                LIMIT 3000
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if ($withdrawWorkOrderCol) {
            $workOrders = $pdo->query("
                SELECT id, title, project_id
                FROM work_orders
                ORDER BY id DESC
                LIMIT 3000
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (\Throwable $e) {
        $projects = $projects ?: [];
        $workOrders = $workOrders ?: [];
    }
}

// ---------------------------------------------------------
// Handle POST actions (create/update/delete)
// ---------------------------------------------------------
$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'create' || $do === 'update') {
        $id   = (int)($_POST['id'] ?? 0);

        $accType = post_str('type', 16);
        if (!in_array($accType, ['customer', 'partner'], true)) $accType = 'customer';

        $name        = post_str('name', 255);
        $orgNo       = normalize_orgno(post_str('org_no', 32));
        $reference   = post_str('reference', 128);
        $email       = post_str('email', 255);
        $phone       = post_str('phone', 64);
        $address1    = post_str('address1', 255);
        $address2    = post_str('address2', 255);
        $postalCode  = post_str('postal_code', 16);
        $postalCity  = post_str('postal_city', 128);
        $country     = post_str('country', 64) ?: 'NO';
        $termsDays   = post_int('payment_terms_days', 14);
        $activeFlag  = post_bool('is_active');

        // Uttaksfelter
        $withdrawProjectId = $withdrawProjectCol ? post_int('withdraw_project_id', 0) : 0;
        $withdrawWorkOrderId = $withdrawWorkOrderCol ? post_int('withdraw_work_order_id', 0) : 0;

        // NY: ERP prosjektref (intern)
        $erpProjectRef = $showErpField ? post_str('erp_project_ref', 64) : '';

        // Server-side mapping: WO -> Prosjekt hvis prosjekt ikke er valgt
        if ($withdrawWorkOrderCol && $withdrawWorkOrderId > 0 && $withdrawProjectCol && $withdrawProjectId <= 0) {
            try {
                $st = $pdo->prepare("SELECT project_id FROM work_orders WHERE id = :id LIMIT 1");
                $st->execute([':id' => $withdrawWorkOrderId]);
                $withdrawProjectId = (int)($st->fetchColumn() ?: 0);
            } catch (\Throwable $e) {}
        }

        if ($name === '') $errors[] = 'Navn må fylles ut.';
        if ($termsDays < 0 || $termsDays > 365) $errors[] = 'Betalingsbetingelser må være mellom 0 og 365 dager.';

        if (!$errors) {
            try {
                if ($do === 'create') {
                    $cols = [
                        'type','name','org_no','reference','email','phone',
                        'address1','address2','postal_code','postal_city','country',
                        'payment_terms_days','is_active','created_at'
                    ];
                    $vals = [
                        ':type' => $accType,
                        ':name' => $name,
                        ':org_no' => $orgNo !== '' ? $orgNo : null,
                        ':reference' => $reference !== '' ? $reference : null,
                        ':email' => $email !== '' ? $email : null,
                        ':phone' => $phone !== '' ? $phone : null,
                        ':address1' => $address1 !== '' ? $address1 : null,
                        ':address2' => $address2 !== '' ? $address2 : null,
                        ':postal_code' => $postalCode !== '' ? $postalCode : null,
                        ':postal_city' => $postalCity !== '' ? $postalCity : null,
                        ':country' => $country !== '' ? $country : 'NO',
                        ':payment_terms_days' => $termsDays,
                        ':is_active' => $activeFlag,
                    ];

                    if ($withdrawProjectCol) {
                        $cols[] = $withdrawProjectCol;
                        $vals[':wproj'] = $withdrawProjectId > 0 ? $withdrawProjectId : null;
                    }
                    if ($withdrawWorkOrderCol) {
                        $cols[] = $withdrawWorkOrderCol;
                        $vals[':wwo'] = $withdrawWorkOrderId > 0 ? $withdrawWorkOrderId : null;
                    }
                    if ($erpProjectRefCol) {
                        $cols[] = $erpProjectRefCol;
                        $vals[':erp'] = $erpProjectRef !== '' ? $erpProjectRef : null;
                    }

                    $placeholders = [];
                    foreach ($cols as $c) {
                        if ($c === 'created_at') { $placeholders[] = 'NOW()'; continue; }
                        if ($c === 'type') { $placeholders[] = ':type'; continue; }
                        if ($c === 'name') { $placeholders[] = ':name'; continue; }
                        if ($c === 'org_no') { $placeholders[] = ':org_no'; continue; }
                        if ($c === 'reference') { $placeholders[] = ':reference'; continue; }
                        if ($c === 'email') { $placeholders[] = ':email'; continue; }
                        if ($c === 'phone') { $placeholders[] = ':phone'; continue; }
                        if ($c === 'address1') { $placeholders[] = ':address1'; continue; }
                        if ($c === 'address2') { $placeholders[] = ':address2'; continue; }
                        if ($c === 'postal_code') { $placeholders[] = ':postal_code'; continue; }
                        if ($c === 'postal_city') { $placeholders[] = ':postal_city'; continue; }
                        if ($c === 'country') { $placeholders[] = ':country'; continue; }
                        if ($c === 'payment_terms_days') { $placeholders[] = ':payment_terms_days'; continue; }
                        if ($c === 'is_active') { $placeholders[] = ':is_active'; continue; }

                        if ($withdrawProjectCol && $c === $withdrawProjectCol) { $placeholders[] = ':wproj'; continue; }
                        if ($withdrawWorkOrderCol && $c === $withdrawWorkOrderCol) { $placeholders[] = ':wwo'; continue; }
                        if ($erpProjectRefCol && $c === $erpProjectRefCol) { $placeholders[] = ':erp'; continue; }

                        $placeholders[] = 'NULL';
                    }

                    $sql = "
                        INSERT INTO crm_accounts (" . implode(',', array_map(fn($c)=>"`$c`", $cols)) . ")
                        VALUES (" . implode(',', $placeholders) . ")
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($vals);

                    $newId = (int)$pdo->lastInsertId();
                    header('Location: /?page=crm_accounts&edit_id=' . $newId . '&msg=ok');
                    exit;
                } else {
                    if ($id <= 0) throw new RuntimeException('Mangler ID ved oppdatering.');

                    $set = "
                        type = :type,
                        name = :name,
                        org_no = :org_no,
                        reference = :reference,
                        email = :email,
                        phone = :phone,
                        address1 = :address1,
                        address2 = :address2,
                        postal_code = :postal_code,
                        postal_city = :postal_city,
                        country = :country,
                        payment_terms_days = :payment_terms_days,
                        is_active = :is_active
                    ";

                    $vals = [
                        ':type' => $accType,
                        ':name' => $name,
                        ':org_no' => $orgNo !== '' ? $orgNo : null,
                        ':reference' => $reference !== '' ? $reference : null,
                        ':email' => $email !== '' ? $email : null,
                        ':phone' => $phone !== '' ? $phone : null,
                        ':address1' => $address1 !== '' ? $address1 : null,
                        ':address2' => $address2 !== '' ? $address2 : null,
                        ':postal_code' => $postalCode !== '' ? $postalCode : null,
                        ':postal_city' => $postalCity !== '' ? $postalCity : null,
                        ':country' => $country !== '' ? $country : 'NO',
                        ':payment_terms_days' => $termsDays,
                        ':is_active' => $activeFlag,
                        ':id' => $id,
                    ];

                    if ($withdrawProjectCol) {
                        $set .= ", `$withdrawProjectCol` = :wproj";
                        $vals[':wproj'] = $withdrawProjectId > 0 ? $withdrawProjectId : null;
                    }
                    if ($withdrawWorkOrderCol) {
                        $set .= ", `$withdrawWorkOrderCol` = :wwo";
                        $vals[':wwo'] = $withdrawWorkOrderId > 0 ? $withdrawWorkOrderId : null;
                    }
                    if ($erpProjectRefCol) {
                        $set .= ", `$erpProjectRefCol` = :erp";
                        $vals[':erp'] = $erpProjectRef !== '' ? $erpProjectRef : null;
                    }

                    $stmt = $pdo->prepare("
                        UPDATE crm_accounts
                           SET $set
                         WHERE id = :id
                         LIMIT 1
                    ");
                    $stmt->execute($vals);

                    header('Location: /?page=crm_accounts&edit_id=' . $id . '&msg=ok');
                    exit;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Databasefeil: ' . $e->getMessage();
            }
        }

    } elseif ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = 'Ugyldig ID.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE crm_accounts SET is_active = 0 WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $id]);
                header('Location: /?page=crm_accounts&msg=deleted');
                exit;
            } catch (\Throwable $e) {
                $errors[] = 'Databasefeil: ' . $e->getMessage();
            }
        }
    }
}

// msg fra redirect
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'ok') $successMessage = 'Lagret.';
    if ($_GET['msg'] === 'deleted') $successMessage = 'Kunde/partner ble deaktivert.';
}

// ---------------------------------------------------------
// Hent edit-rad hvis vi redigerer
// ---------------------------------------------------------
$editRow = null;
if ($editId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM crm_accounts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $editId]);
        $editRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$editRow) {
            $errors[] = 'Fant ikke kunde/partner med ID ' . $editId;
            $editId = 0;
        }
    } catch (\Throwable $e) {
        $errors[] = 'Databasefeil: ' . $e->getMessage();
        $editId = 0;
    }
}

// ---------------------------------------------------------
// List + filter
// ---------------------------------------------------------
$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(name LIKE :q OR org_no LIKE :q OR email LIKE :q OR reference LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

if ($type !== 'all' && in_array($type, ['customer', 'partner'], true)) {
    $where[] = "type = :type";
    $params[':type'] = $type;
}

if ($isActive !== 'all') {
    $where[] = "is_active = :active";
    $params[':active'] = ($isActive === '0') ? 0 : 1;
}

$selectExtra = "";
if ($withdrawProjectCol) $selectExtra .= ", `$withdrawProjectCol` AS withdraw_project_id";
if ($withdrawWorkOrderCol) $selectExtra .= ", `$withdrawWorkOrderCol` AS withdraw_work_order_id";
if ($erpProjectRefCol) $selectExtra .= ", `$erpProjectRefCol` AS erp_project_ref";

$sqlList = "
    SELECT id, type, name, org_no, reference, email, phone,
           postal_city, is_active, payment_terms_days, created_at
           $selectExtra
      FROM crm_accounts
";
if ($where) $sqlList .= " WHERE " . implode(" AND ", $where);
$sqlList .= " ORDER BY is_active DESC, name ASC LIMIT 500";

$rows = [];
try {
    $stmt = $pdo->prepare($sqlList);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $errors[] = 'Databasefeil: ' . $e->getMessage();
    $rows = [];
}

// ---------------------------------------------------------
// Defaults for form
// ---------------------------------------------------------
$form = [
    'id' => $editRow['id'] ?? 0,
    'type' => $editRow['type'] ?? 'customer',
    'name' => $editRow['name'] ?? '',
    'org_no' => $editRow['org_no'] ?? '',
    'reference' => $editRow['reference'] ?? '',
    'email' => $editRow['email'] ?? '',
    'phone' => $editRow['phone'] ?? '',
    'address1' => $editRow['address1'] ?? '',
    'address2' => $editRow['address2'] ?? '',
    'postal_code' => $editRow['postal_code'] ?? '',
    'postal_city' => $editRow['postal_city'] ?? '',
    'country' => $editRow['country'] ?? 'NO',
    'payment_terms_days' => $editRow['payment_terms_days'] ?? 14,
    'is_active' => isset($editRow['is_active']) ? (int)$editRow['is_active'] : 1,
    'withdraw_project_id' => ($withdrawProjectCol && $editRow) ? (int)($editRow[$withdrawProjectCol] ?? 0) : 0,
    'withdraw_work_order_id' => ($withdrawWorkOrderCol && $editRow) ? (int)($editRow[$withdrawWorkOrderCol] ?? 0) : 0,
    'erp_project_ref' => ($erpProjectRefCol && $editRow) ? (string)($editRow[$erpProjectRefCol] ?? '') : '',
];

?>
<div class="d-flex align-items-start justify-content-between mt-3">
    <div>
        <h3 class="mb-1">Kunderegister</h3>
        <div class="text-muted">Kunder og partnere for fakturagrunnlag.</div>
        <?php if (!$isAdmin): ?>
            <div class="text-muted small mt-1">Tilgang via rolle/perms (f.eks. invoice/faktura/crm/billing/support).</div>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <?php if ($editId > 0): ?>
            <a class="btn btn-outline-secondary" href="/?page=crm_accounts">Ny</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success mt-3"><?= h($successMessage) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger mt-3">
        <strong>Feil:</strong><br>
        <?= nl2br(h(implode("\n", $errors))) ?>
    </div>
<?php endif; ?>

<div class="row g-3 mt-1">
    <!-- FORM -->
    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header">
                <strong><?= $editId > 0 ? 'Rediger kunde/partner' : 'Ny kunde/partner' ?></strong>
            </div>
            <div class="card-body">
                <form method="post" id="accForm">
                    <input type="hidden" name="do" value="<?= $editId > 0 ? 'update' : 'create' ?>">
                    <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

                    <div class="mb-2">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type">
                            <option value="customer" <?= $form['type'] === 'customer' ? 'selected' : '' ?>>Kunde</option>
                            <option value="partner"  <?= $form['type'] === 'partner' ? 'selected' : '' ?>>Partner</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Navn *</label>
                        <input class="form-control" name="name" value="<?= h($form['name']) ?>" required>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Org.nr</label>
                            <input class="form-control" name="org_no" value="<?= h($form['org_no']) ?>" placeholder="9 siffer">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Betalingsbetingelser (dager)</label>
                            <input class="form-control" type="number" min="0" max="365" name="payment_terms_days" value="<?= (int)$form['payment_terms_days'] ?>">
                        </div>
                    </div>

                    <div class="mb-2 mt-2">
                        <label class="form-label">Kundereferanse</label>
                        <input class="form-control" name="reference" value="<?= h($form['reference']) ?>" placeholder="F.eks. bestiller / PO">
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">E-post</label>
                            <input class="form-control" name="email" value="<?= h($form['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefon</label>
                            <input class="form-control" name="phone" value="<?= h($form['phone']) ?>">
                        </div>
                    </div>

                    <?php if ($showErpField): ?>
                        <hr class="my-3">
                        <div class="mb-2">
                            <label class="form-label">ERP-prosjekt (intern referanse)</label>
                            <input class="form-control" name="erp_project_ref" value="<?= h($form['erp_project_ref']) ?>" placeholder="F.eks. ERP-12345 / PROSJEKT-KODE">
                            <div class="text-muted small mt-1">Brukes ved føring/eksport i interne systemer.</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($showWithdrawFields): ?>
                        <hr class="my-3">
                        <div class="alert alert-info py-2">
                            <div class="fw-semibold">Uttaksprosjekt / uttaks-arbeidsordre</div>
                            <div class="small text-muted">
                                Brukes for føring i interne systemer når varer/timer faktureres.
                            </div>
                        </div>

                        <div class="row g-2">
                            <?php if ($withdrawProjectCol): ?>
                                <div class="col-md-6">
                                    <label class="form-label">Uttaksprosjekt</label>
                                    <select class="form-select" name="withdraw_project_id" id="withdraw_project_id">
                                        <option value="0">—</option>
                                        <?php foreach ($projects as $pr): ?>
                                            <option value="<?= (int)$pr['id'] ?>" <?= ((int)$pr['id'] === (int)$form['withdraw_project_id']) ? 'selected' : '' ?>>
                                                <?= h($pr['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <?php if ($withdrawWorkOrderCol): ?>
                                <div class="col-md-6">
                                    <label class="form-label">Uttaks-arbeidsordre</label>
                                    <select class="form-select" name="withdraw_work_order_id" id="withdraw_work_order_id">
                                        <option value="0">—</option>
                                        <?php foreach ($workOrders as $wo): ?>
                                            <option value="<?= (int)$wo['id'] ?>"
                                                    data-project-id="<?= (int)$wo['project_id'] ?>"
                                                <?= ((int)$wo['id'] === (int)$form['withdraw_work_order_id']) ? 'selected' : '' ?>>
                                                #<?= (int)$wo['id'] ?> – <?= h($wo['title']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="text-muted small mt-1">Hvis prosjekt ikke er valgt, settes det automatisk fra arbeidsordren.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <hr class="my-3">

                    <div class="mb-2">
                        <label class="form-label">Adresse</label>
                        <input class="form-control" name="address1" value="<?= h($form['address1']) ?>" placeholder="Adresse linje 1">
                    </div>

                    <div class="mb-2">
                        <input class="form-control" name="address2" value="<?= h($form['address2']) ?>" placeholder="Adresse linje 2">
                    </div>

                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Postnr</label>
                            <input class="form-control" name="postal_code" value="<?= h($form['postal_code']) ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Poststed</label>
                            <input class="form-control" name="postal_city" value="<?= h($form['postal_city']) ?>">
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-md-4">
                            <label class="form-label">Land</label>
                            <input class="form-control" name="country" value="<?= h($form['country']) ?>">
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= ((int)$form['is_active'] === 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">Aktiv</label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-primary">
                            <?= $editId > 0 ? 'Lagre endringer' : 'Opprett' ?>
                        </button>

                        <?php if ($editId > 0): ?>
                            <a class="btn btn-outline-secondary" href="/?page=crm_accounts">Avbryt</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($editId > 0): ?>
                    <hr class="my-3">
                    <form method="post" onsubmit="return confirm('Deaktivere denne kunden/partneren?');">
                        <input type="hidden" name="do" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Deaktiver</button>
                        <div class="text-muted small mt-1">(Deaktivering setter is_active=0 – ingen hard delete)</div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- LIST -->
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <strong>Liste</strong>
                <form class="d-flex gap-2" method="get">
                    <input type="hidden" name="page" value="crm_accounts">
                    <input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Søk (navn, orgnr, epost...)">
                    <select class="form-select form-select-sm" name="type">
                        <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>Alle</option>
                        <option value="customer" <?= $type === 'customer' ? 'selected' : '' ?>>Kunder</option>
                        <option value="partner" <?= $type === 'partner' ? 'selected' : '' ?>>Partnere</option>
                    </select>
                    <select class="form-select form-select-sm" name="active">
                        <option value="1" <?= $isActive === '1' ? 'selected' : '' ?>>Aktive</option>
                        <option value="0" <?= $isActive === '0' ? 'selected' : '' ?>>Inaktive</option>
                        <option value="all" <?= $isActive === 'all' ? 'selected' : '' ?>>Alle</option>
                    </select>
                    <button class="btn btn-sm btn-primary">Søk</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Navn</th>
                            <th>Type</th>
                            <th>Org.nr</th>
                            <th>Kontakt</th>
                            <th>Sted</th>
                            <th class="text-end">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="text-muted">Ingen treff.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>
                                    <a href="/?page=crm_accounts&edit_id=<?= (int)$r['id'] ?>">
                                        <?= h($r['name']) ?>
                                    </a>

                                    <?php if (!empty($r['reference'])): ?>
                                        <div class="text-muted small">Ref: <?= h($r['reference']) ?></div>
                                    <?php endif; ?>

                                    <?php if ($showErpField && !empty($r['erp_project_ref'])): ?>
                                        <div class="text-muted small">ERP: <?= h((string)$r['erp_project_ref']) ?></div>
                                    <?php endif; ?>

                                    <?php if ($showWithdrawFields): ?>
                                        <?php
                                          $wp = (int)($r['withdraw_project_id'] ?? 0);
                                          $ww = (int)($r['withdraw_work_order_id'] ?? 0);
                                        ?>
                                        <?php if ($wp || $ww): ?>
                                            <div class="text-muted small">
                                                Uttak:
                                                <?= $wp ? 'Prosjekt #' . $wp : '' ?>
                                                <?= ($wp && $ww) ? ' · ' : '' ?>
                                                <?= $ww ? 'WO #' . $ww : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= ($r['type'] === 'partner') ? 'bg-info' : 'bg-secondary' ?>">
                                        <?= ($r['type'] === 'partner') ? 'Partner' : 'Kunde' ?>
                                    </span>
                                </td>
                                <td class="text-muted"><?= h($r['org_no'] ?? '') ?></td>
                                <td class="text-muted small">
                                    <?php if (!empty($r['email'])): ?>
                                        <div><i class="bi bi-envelope"></i> <?= h($r['email']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($r['phone'])): ?>
                                        <div><i class="bi bi-telephone"></i> <?= h($r['phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?= h($r['postal_city'] ?? '') ?></td>
                                <td class="text-end">
                                    <?php if ((int)$r['is_active'] === 1): ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-footer text-muted small">Viser opptil 500 rader.</div>
        </div>
    </div>
</div>

<?php if ($showWithdrawFields): ?>
<script>
(function(){
  // UI mapping: WO -> Prosjekt hvis prosjekt ikke er valgt
  document.addEventListener('DOMContentLoaded', function(){
    var woSel = document.getElementById('withdraw_work_order_id');
    var prSel = document.getElementById('withdraw_project_id');
    if (!woSel || !prSel) return;

    function sync() {
      var opt = woSel.options[woSel.selectedIndex];
      var pid = opt ? Number(opt.getAttribute('data-project-id') || 0) : 0;
      if (pid > 0 && Number(prSel.value || 0) === 0) {
        prSel.value = String(pid);
      }
    }
    woSel.addEventListener('change', sync);
    sync();
  });
})();
</script>
<?php endif; ?>
