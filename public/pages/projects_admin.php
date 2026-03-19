<?php
// public/pages/projects_admin.php
//
// Admin-side for prosjekter og arbeidsordrer
// - work_orders må alltid referere til projects (project_id NOT NULL)
// - Uttak kan føres direkte på prosjekt (work_order_id valgfri)
//
// Prosjekttyper:
// - lager: "lagerprosjekt" (uttak tas fra disse)
// - arbeid: "arbeidsprosjekt" (varer settes på disse ved uttak)
//
// Forutsetter tabeller:
// - projects (id, project_no, name, owner, project_type, is_active)
// - work_orders (id, project_id, work_order_no, title, status)
//
// Roller:
// - is_admin ELLER user_roles: admin
// - warehouse_read (les)
// - warehouse_write (skriv)
// - (bakoverkompatibel) project_write

use App\Database;

$pdo = Database::getConnection();

// ---------------------------------------------------------
// Guard
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

$isAdmin = (bool)($_SESSION['is_admin'] ?? false);
if ($username === 'rsv') {
    $isAdmin = true;
}

$currentUserId = 0;
$currentRoles  = [];

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $currentUserId = (int)($stmt->fetchColumn() ?: 0);

    if ($currentUserId > 0) {
        $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $stmt->execute([':uid' => $currentUserId]);
        $currentRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
} catch (\Throwable $e) {
    $currentRoles = [];
}

if (!$isAdmin && in_array('admin', $currentRoles, true)) {
    $isAdmin = true;
}

// ---------------------------------------------------------
// Permissions (NEW):
// - warehouse_read => kan se siden
// - warehouse_write => kan endre/opprette
// - behold project_write som legacy write-rolle
// ---------------------------------------------------------
$canWarehouseRead  = $isAdmin
    || in_array('warehouse_read', $currentRoles, true)
    || in_array('warehouse_write', $currentRoles, true)
    || in_array('project_write', $currentRoles, true);

$canWarehouseWrite = $isAdmin
    || in_array('warehouse_write', $currentRoles, true)
    || in_array('project_write', $currentRoles, true);

if (!$canWarehouseRead) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til å se prosjekter/arbeidsordrer.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $cols[] = $r['Field'];
        } catch (\Throwable $e) {
            // ignore
        }
        return $cols;
    }
}

$errors  = [];
$success = null;

$projectTypes = [
    'lager'  => 'Lagerprosjekt',
    'arbeid' => 'Arbeidsprosjekt',
];

function normalize_project_type(string $t): string {
    $t = strtolower(trim($t));
    return in_array($t, ['lager','arbeid'], true) ? $t : 'arbeid';
}

// ---------------------------------------------------------
// Ensure DB supports work_order_no (Arbeidsordrenummer)
// ---------------------------------------------------------
$hasWoNo = false;
try {
    if (table_exists($pdo, 'work_orders')) {
        $woCols = table_columns($pdo, 'work_orders');

        if (!in_array('work_order_no', $woCols, true)) {
            // Best-effort migrering: legg til kolonne og backfill fra id
            try {
                $pdo->exec("ALTER TABLE work_orders ADD COLUMN work_order_no VARCHAR(50) NOT NULL DEFAULT '' AFTER project_id");
            } catch (\Throwable $e) {
                // ignore - vi sjekker under om kolonnen faktisk finnes
            }

            try {
                $pdo->exec("UPDATE work_orders SET work_order_no = CAST(id AS CHAR) WHERE work_order_no = '' OR work_order_no IS NULL");
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Re-read columns
        $woCols = table_columns($pdo, 'work_orders');
        $hasWoNo = in_array('work_order_no', $woCols, true);

        if (!$hasWoNo) {
            $errors[] = "DB mangler kolonnen work_orders.work_order_no (Arbeidsordrenummer). Legg den til manuelt (VARCHAR(50) NOT NULL) eller gi appen ALTER-rettigheter.";
        }
    } else {
        $errors[] = "DB mangler tabellen work_orders.";
    }
} catch (\Throwable $e) {
    $errors[] = $e->getMessage();
}

// ---------------------------------------------------------
// Input (filters)
// ---------------------------------------------------------
$selectedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$qProjects = trim((string)($_GET['q_projects'] ?? ''));
$qWos      = trim((string)($_GET['q_wos'] ?? ''));

// ---------------------------------------------------------
// POST handlers (WRITE-GUARD)
// ---------------------------------------------------------
$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWarehouseWrite) {
        http_response_code(403);
        ?>
        <div class="alert alert-danger mt-3">
            Du har ikke skrivetilgang (warehouse_write) til å endre prosjekter/arbeidsordrer.
        </div>
        <?php
        return;
    }

    try {
        if ($action === 'create_project') {
            $projectNo   = trim((string)($_POST['project_no'] ?? ''));
            $name        = trim((string)($_POST['name'] ?? ''));
            $owner       = trim((string)($_POST['owner'] ?? ''));
            $projectType = normalize_project_type((string)($_POST['project_type'] ?? 'arbeid'));
            $isActive    = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '') $errors[] = 'Prosjektnavn kan ikke være tomt.';

            if (!$errors) {
                $stmt = $pdo->prepare("
                    INSERT INTO projects (project_no, name, owner, project_type, is_active, created_at)
                    VALUES (:no, :name, :owner, :ptype, :active, NOW())
                ");
                $stmt->execute([
                    ':no'     => $projectNo,
                    ':name'   => $name,
                    ':owner'  => $owner !== '' ? $owner : null,
                    ':ptype'  => $projectType,
                    ':active' => $isActive,
                ]);
                $success = "Prosjekt opprettet.";
            }
        }

        if ($action === 'update_project') {
            $id          = (int)($_POST['id'] ?? 0);
            $projectNo   = trim((string)($_POST['project_no'] ?? ''));
            $name        = trim((string)($_POST['name'] ?? ''));
            $owner       = trim((string)($_POST['owner'] ?? ''));
            $projectType = normalize_project_type((string)($_POST['project_type'] ?? 'arbeid'));
            $isActive    = isset($_POST['is_active']) ? 1 : 0;

            if ($id <= 0) $errors[] = 'Ugyldig prosjekt.';
            if ($name === '') $errors[] = 'Prosjektnavn kan ikke være tomt.';

            if (!$errors) {
                $stmt = $pdo->prepare("
                    UPDATE projects
                    SET project_no = :no,
                        name = :name,
                        owner = :owner,
                        project_type = :ptype,
                        is_active = :active
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':no'     => $projectNo,
                    ':name'   => $name,
                    ':owner'  => $owner !== '' ? $owner : null,
                    ':ptype'  => $projectType,
                    ':active' => $isActive,
                    ':id'     => $id,
                ]);
                $success = "Prosjekt oppdatert.";
            }
        }

        if ($action === 'toggle_project_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) $errors[] = 'Ugyldig prosjekt.';

            if (!$errors) {
                $stmt = $pdo->prepare("UPDATE projects SET is_active = IF(is_active=1,0,1) WHERE id=:id LIMIT 1");
                $stmt->execute([':id' => $id]);
                $success = "Prosjektstatus endret.";
            }
        }

        if ($action === 'create_work_order') {
            if (!$hasWoNo) {
                $errors[] = "Kan ikke opprette arbeidsordre før work_orders.work_order_no finnes i databasen.";
            }

            $projectId   = (int)($_POST['project_id'] ?? 0);
            $workOrderNo = trim((string)($_POST['work_order_no'] ?? ''));
            $title       = trim((string)($_POST['title'] ?? ''));
            $status      = trim((string)($_POST['status'] ?? 'open'));

            if ($projectId <= 0) $errors[] = 'Velg prosjekt for arbeidsordre.';
            if ($workOrderNo === '') $errors[] = 'Arbeidsordrenummer kan ikke være tomt.';
            if ($title === '') $errors[] = 'Arbeidsordrenavn kan ikke være tomt.';
            if ($status === '') $status = 'open';

            // 🔒 Kun arbeidsprosjekt kan ha arbeidsordre
            if (!$errors) {
                $stmt = $pdo->prepare("SELECT project_type FROM projects WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $projectId]);
                $ptype = (string)($stmt->fetchColumn() ?: '');
                if ($ptype !== 'arbeid') {
                    $errors[] = 'Arbeidsordre kan kun opprettes på arbeidsprosjekt (ikke lagerprosjekt).';
                }
            }

            // Unik pr prosjekt (best effort): samme nummer skal ikke gjentas innen samme prosjekt
            if (!$errors) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE project_id = :pid AND work_order_no = :no");
                $stmt->execute([':pid' => $projectId, ':no' => $workOrderNo]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $errors[] = 'Arbeidsordrenummer finnes allerede på dette prosjektet.';
                }
            }

            if (!$errors) {
                $stmt = $pdo->prepare("
                    INSERT INTO work_orders (project_id, work_order_no, title, status, created_at)
                    VALUES (:pid, :no, :title, :status, NOW())
                ");
                $stmt->execute([
                    ':pid'    => $projectId,
                    ':no'     => $workOrderNo,
                    ':title'  => $title,
                    ':status' => $status,
                ]);
                $success = "Arbeidsordre opprettet.";
                $selectedProjectId = $projectId; // hold filter
            }
        }

        if ($action === 'update_work_order') {
            if (!$hasWoNo) {
                $errors[] = "Kan ikke oppdatere arbeidsordre før work_orders.work_order_no finnes i databasen.";
            }

            $id          = (int)($_POST['id'] ?? 0);
            $projectId   = (int)($_POST['project_id'] ?? 0);
            $workOrderNo = trim((string)($_POST['work_order_no'] ?? ''));
            $title       = trim((string)($_POST['title'] ?? ''));
            $status      = trim((string)($_POST['status'] ?? 'open'));

            if ($id <= 0) $errors[] = 'Ugyldig arbeidsordre.';
            if ($projectId <= 0) $errors[] = 'Arbeidsordre må ha prosjekt.';
            if ($workOrderNo === '') $errors[] = 'Arbeidsordrenummer kan ikke være tomt.';
            if ($title === '') $errors[] = 'Arbeidsordrenavn kan ikke være tomt.';
            if ($status === '') $status = 'open';

            // 🔒 Kun arbeidsprosjekt kan ha arbeidsordre
            if (!$errors) {
                $stmt = $pdo->prepare("SELECT project_type FROM projects WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $projectId]);
                $ptype = (string)($stmt->fetchColumn() ?: '');
                if ($ptype !== 'arbeid') {
                    $errors[] = 'Arbeidsordre kan kun flyttes til arbeidsprosjekt (ikke lagerprosjekt).';
                }
            }

            // Unik pr prosjekt (best effort)
            if (!$errors) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE project_id = :pid AND work_order_no = :no AND id <> :id");
                $stmt->execute([':pid' => $projectId, ':no' => $workOrderNo, ':id' => $id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $errors[] = 'Arbeidsordrenummer finnes allerede på dette prosjektet.';
                }
            }

            if (!$errors) {
                $stmt = $pdo->prepare("
                    UPDATE work_orders
                    SET project_id = :pid,
                        work_order_no = :no,
                        title = :title,
                        status = :status
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':pid'    => $projectId,
                    ':no'     => $workOrderNo,
                    ':title'  => $title,
                    ':status' => $status,
                    ':id'     => $id,
                ]);
                $success = "Arbeidsordre oppdatert.";
                $selectedProjectId = $projectId; // hold filter
            }
        }

    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// ---------------------------------------------------------
// Load projects list
// ---------------------------------------------------------
$projects = [];
try {
    $where = [];
    $params = [];

    if ($qProjects !== '') {
        $where[] = "(name LIKE :q OR project_no LIKE :q OR owner LIKE :q)";
        $params[':q'] = '%' . $qProjects . '%';
    }

    $sql = "SELECT id, project_no, name, owner, project_type, is_active, created_at
            FROM projects";
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY is_active DESC, name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $projects = [];
    $errors[] = $e->getMessage();
}

// map for dropdown
$projectNameById = [];
foreach ($projects as $p) {
    $projectNameById[(int)$p['id']] = (string)$p['name'];
}

// Kun arbeidsprosjekt i arbeidsordre-dropdowns
$workableProjects = array_values(array_filter($projects, function ($p) {
    return (string)($p['project_type'] ?? 'arbeid') === 'arbeid';
}));

// ---------------------------------------------------------
// Load work orders list (optionally filtered by project)
// ---------------------------------------------------------
$workOrders = [];
try {
    $where = [];
    $params = [];

    if ($selectedProjectId > 0) {
        $where[] = "wo.project_id = :pid";
        $params[':pid'] = $selectedProjectId;
    }

    if ($qWos !== '') {
        if ($hasWoNo) {
            $where[] = "(wo.work_order_no LIKE :qw OR wo.title LIKE :qw OR wo.status LIKE :qw OR CAST(wo.id AS CHAR) LIKE :qw)";
        } else {
            $where[] = "(wo.title LIKE :qw OR wo.status LIKE :qw OR CAST(wo.id AS CHAR) LIKE :qw)";
        }
        $params[':qw'] = '%' . $qWos . '%';
    }

    $selectWoNo = $hasWoNo ? "wo.work_order_no" : "'' AS work_order_no";

    $sql = "
        SELECT wo.id, wo.project_id, {$selectWoNo}, wo.title, wo.status, wo.created_at,
               p.name AS project_name, p.project_no AS project_no, p.project_type AS project_type
        FROM work_orders wo
        JOIN projects p ON p.id = wo.project_id
    ";
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY wo.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $workOrders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $workOrders = [];
    $errors[] = $e->getMessage();
}

// Edit modes (kun for write)
$editProjectId = $canWarehouseWrite && isset($_GET['edit_project_id']) ? (int)$_GET['edit_project_id'] : 0;
$editWoId      = $canWarehouseWrite && isset($_GET['edit_wo_id']) ? (int)$_GET['edit_wo_id'] : 0;

// status options
$woStatuses = ['open' => 'open', 'in_progress' => 'in_progress', 'closed' => 'closed', 'canceled' => 'canceled'];

?>
<div class="d-flex align-items-start justify-content-between mt-3">
    <div>
        <h3 class="mb-1">Prosjekter & Arbeidsordrer</h3>
        <div class="text-muted">
            Lagerprosjekt brukes som “lager”. Arbeidsprosjekt brukes til uttak/forbruk. Arbeidsordre kan kun ligge på arbeidsprosjekt.
        </div>

        <?php if (!$canWarehouseWrite): ?>
            <div class="alert alert-info mt-3 mb-0">
                Du har <strong>lesetilgang</strong> (warehouse_read). For å opprette/endre trenger du <strong>warehouse_write</strong>.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger mt-3">
        <strong>Feil:</strong><br>
        <?= nl2br(h(implode("\n", $errors))) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success mt-3">
        <?= h($success) ?>
    </div>
<?php endif; ?>

<div class="row g-3 mt-2">
    <!-- Projects -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h5 class="card-title mb-0">Prosjekter</h5>
                    <form class="d-flex gap-2" method="get">
                        <input type="hidden" name="page" value="projects_admin">
                        <input type="text" class="form-control form-control-sm" name="q_projects" value="<?= h($qProjects) ?>" placeholder="Søk…">
                        <button class="btn btn-sm btn-outline-primary">Søk</button>
                    </form>
                </div>

                <?php if ($canWarehouseWrite): ?>
                    <!-- Create project -->
                    <form method="post" class="border rounded p-2 mb-3">
                        <input type="hidden" name="action" value="create_project">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label small">Prosjektnr</label>
                                <input class="form-control form-control-sm" name="project_no" placeholder="f.eks 1001">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Navn</label>
                                <input class="form-control form-control-sm" name="name" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Eier</label>
                                <input class="form-control form-control-sm" name="owner" placeholder="valgfritt">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Type</label>
                                <select class="form-select form-select-sm" name="project_type">
                                    <option value="arbeid" selected>Arbeidsprosjekt</option>
                                    <option value="lager">Lagerprosjekt</option>
                                </select>
                            </div>

                            <div class="col-12 d-flex align-items-center justify-content-between">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="p_active_new" checked>
                                    <label class="form-check-label small" for="p_active_new">Aktiv</label>
                                </div>
                                <button class="btn btn-sm btn-success">Opprett prosjekt</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                        <tr>
                            <th style="width:70px;">ID</th>
                            <th style="width:120px;">Prosjektnr</th>
                            <th>Navn</th>
                            <th style="width:130px;">Eier</th>
                            <th style="width:120px;">Type</th>
                            <th style="width:90px;">Aktiv</th>
                            <th style="width:170px;" class="text-end">Handling</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$projects): ?>
                            <tr><td colspan="7" class="text-muted">Ingen prosjekter.</td></tr>
                        <?php else: ?>
                            <?php foreach ($projects as $p): ?>
                                <?php
                                $pid = (int)$p['id'];
                                $isEdit = ($editProjectId === $pid);
                                $ptype = (string)($p['project_type'] ?? 'arbeid');
                                ?>
                                <tr>
                                    <td><?= $pid ?></td>

                                    <?php if ($isEdit): ?>
                                        <td colspan="5">
                                            <form method="post" class="row g-2 align-items-end">
                                                <input type="hidden" name="action" value="update_project">
                                                <input type="hidden" name="id" value="<?= $pid ?>">

                                                <div class="col-md-3">
                                                    <label class="form-label small">Prosjektnr</label>
                                                    <input class="form-control form-control-sm" name="project_no" value="<?= h($p['project_no'] ?? '') ?>">
                                                </div>
                                                <div class="col-md-5">
                                                    <label class="form-label small">Navn</label>
                                                    <input class="form-control form-control-sm" name="name" required value="<?= h($p['name'] ?? '') ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small">Eier</label>
                                                    <input class="form-control form-control-sm" name="owner" value="<?= h($p['owner'] ?? '') ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small">Type</label>
                                                    <select class="form-select form-select-sm" name="project_type">
                                                        <option value="arbeid" <?= $ptype === 'arbeid' ? 'selected' : '' ?>>Arbeid</option>
                                                        <option value="lager" <?= $ptype === 'lager' ? 'selected' : '' ?>>Lager</option>
                                                    </select>
                                                </div>

                                                <div class="col-12 d-flex justify-content-end gap-2">
                                                    <div class="form-check me-auto">
                                                        <input class="form-check-input" type="checkbox" name="is_active" id="p_active_<?= $pid ?>" <?= ((int)$p['is_active'] === 1) ? 'checked' : '' ?>>
                                                        <label class="form-check-label small" for="p_active_<?= $pid ?>">Aktiv</label>
                                                    </div>

                                                    <button class="btn btn-sm btn-primary">Lagre</button>
                                                    <a class="btn btn-sm btn-outline-secondary"
                                                       href="?page=projects_admin&project_id=<?= (int)$selectedProjectId ?>&q_projects=<?= urlencode($qProjects) ?>&q_wos=<?= urlencode($qWos) ?>">
                                                        Avbryt
                                                    </a>
                                                </div>
                                            </form>
                                        </td>
                                        <td class="text-end"></td>
                                    <?php else: ?>
                                        <td><?= h($p['project_no'] ?? '') ?></td>
                                        <td>
                                            <div><strong><?= h($p['name'] ?? '') ?></strong></div>
                                            <a class="small text-muted" href="?page=projects_admin&project_id=<?= $pid ?>">Vis arbeidsordrer</a>
                                        </td>
                                        <td><?= h($p['owner'] ?? '') ?></td>
                                        <td>
                                            <?php if ($ptype === 'lager'): ?>
                                                <span class="badge bg-info text-dark">Lager</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary">Arbeid</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= ((int)$p['is_active'] === 1)
                                                ? '<span class="badge bg-success">Ja</span>'
                                                : '<span class="badge bg-secondary">Nei</span>'; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($canWarehouseWrite): ?>
                                                <a class="btn btn-sm btn-outline-primary"
                                                   href="?page=projects_admin&edit_project_id=<?= $pid ?>&project_id=<?= (int)$selectedProjectId ?>&q_projects=<?= urlencode($qProjects) ?>&q_wos=<?= urlencode($qWos) ?>">
                                                    Rediger
                                                </a>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_project_active">
                                                    <input type="hidden" name="id" value="<?= $pid ?>">
                                                    <button class="btn btn-sm btn-outline-secondary" type="submit">
                                                        <?= ((int)$p['is_active'] === 1) ? 'Deaktiver' : 'Aktiver' ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">Les</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="text-muted small">
                    Tips: Lagerprosjekt brukes som lager i logistikk. Arbeidsprosjekt er “forbruk/leveranse”.
                </div>
            </div>
        </div>
    </div>

    <!-- Work Orders -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h5 class="card-title mb-0">Arbeidsordrer</h5>
                    <form class="d-flex gap-2" method="get">
                        <input type="hidden" name="page" value="projects_admin">
                        <input type="hidden" name="q_projects" value="<?= h($qProjects) ?>">
                        <select class="form-select form-select-sm" name="project_id">
                            <option value="0">Alle prosjekter</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === (int)$selectedProjectId) ? 'selected' : '' ?>>
                                    <?= h(($p['project_no'] ?? '').' – '.$p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="form-control form-control-sm" name="q_wos" value="<?= h($qWos) ?>" placeholder="Søk… (nr/navn/status)">
                        <button class="btn btn-sm btn-outline-primary">Filtrer</button>
                    </form>
                </div>

                <?php if ($canWarehouseWrite): ?>
                    <!-- Create work order -->
                    <form method="post" class="border rounded p-2 mb-3">
                        <input type="hidden" name="action" value="create_work_order">
                        <div class="row g-2">
                            <div class="col-md-5">
                                <label class="form-label small">Prosjekt (arbeidsprosjekt)</label>
                                <select class="form-select form-select-sm" name="project_id" required>
                                    <option value="0">Velg prosjekt…</option>
                                    <?php foreach ($workableProjects as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === (int)$selectedProjectId) ? 'selected' : '' ?>>
                                            <?= h(($p['project_no'] ?? '').' – '.$p['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label small">Arbeidsordrenummer</label>
                                <input class="form-control form-control-sm" name="work_order_no" required placeholder="f.eks AO-10023">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small">Arbeidsordrenavn</label>
                                <input class="form-control form-control-sm" name="title" required placeholder="f.eks. Montasje / Utskifting CPE">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label small">Status</label>
                                <select class="form-select form-select-sm" name="status">
                                    <?php foreach ($woStatuses as $k => $label): ?>
                                        <option value="<?= h($k) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <button class="btn btn-sm btn-success" <?= $hasWoNo ? '' : 'disabled' ?>>Opprett arbeidsordre</button>
                                <?php if (!$hasWoNo): ?>
                                    <div class="small text-muted mt-1">
                                        Mangler work_orders.work_order_no i databasen.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                        <tr>
                            <th style="width:70px;">ID</th>
                            <th>Arbeidsordre</th>
                            <th style="width:120px;">Status</th>
                            <th style="width:150px;" class="text-end">Handling</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$workOrders): ?>
                            <tr><td colspan="4" class="text-muted">Ingen arbeidsordrer.</td></tr>
                        <?php else: ?>
                            <?php foreach ($workOrders as $wo): ?>
                                <?php
                                $woId = (int)$wo['id'];
                                $isEdit = ($editWoId === $woId);
                                $woNo = trim((string)($wo['work_order_no'] ?? ''));
                                if ($woNo === '') $woNo = (string)$woId; // fallback
                                ?>
                                <tr>
                                    <td>#<?= $woId ?></td>

                                    <?php if ($isEdit): ?>
                                        <td colspan="2">
                                            <form method="post" class="row g-2 align-items-end">
                                                <input type="hidden" name="action" value="update_work_order">
                                                <input type="hidden" name="id" value="<?= $woId ?>">

                                                <div class="col-md-4">
                                                    <label class="form-label small">Prosjekt (arbeidsprosjekt)</label>
                                                    <select class="form-select form-select-sm" name="project_id" required>
                                                        <?php foreach ($workableProjects as $p): ?>
                                                            <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === (int)$wo['project_id']) ? 'selected' : '' ?>>
                                                                <?= h(($p['project_no'] ?? '').' – '.$p['name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label small">Arbeidsordrenummer</label>
                                                    <input class="form-control form-control-sm" name="work_order_no" required value="<?= h($woNo) ?>" <?= $hasWoNo ? '' : 'disabled' ?>>
                                                </div>

                                                <div class="col-md-5">
                                                    <label class="form-label small">Arbeidsordrenavn</label>
                                                    <input class="form-control form-control-sm" name="title" required value="<?= h($wo['title'] ?? '') ?>">
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label small">Status</label>
                                                    <select class="form-select form-select-sm" name="status">
                                                        <?php foreach ($woStatuses as $k => $label): ?>
                                                            <option value="<?= h($k) ?>" <?= ((string)$wo['status'] === (string)$k) ? 'selected' : '' ?>>
                                                                <?= h($label) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="col-12 d-flex justify-content-end gap-2">
                                                    <button class="btn btn-sm btn-primary" <?= $hasWoNo ? '' : 'disabled' ?>>Lagre</button>
                                                    <a class="btn btn-sm btn-outline-secondary"
                                                       href="?page=projects_admin&project_id=<?= (int)$selectedProjectId ?>&q_projects=<?= urlencode($qProjects) ?>&q_wos=<?= urlencode($qWos) ?>">
                                                        Avbryt
                                                    </a>
                                                </div>
                                            </form>
                                        </td>
                                        <td class="text-end"></td>
                                    <?php else: ?>
                                        <td>
                                            <div>
                                                <strong><?= h($woNo) ?> – <?= h($wo['title'] ?? '') ?></strong>
                                            </div>
                                            <div class="text-muted small">
                                                Prosjekt: <?= h(($wo['project_no'] ?? '') . ' – ' . ($wo['project_name'] ?? '')) ?>
                                            </div>
                                        </td>
                                        <td><?= h($wo['status'] ?? '') ?></td>
                                        <td class="text-end">
                                            <?php if ($canWarehouseWrite): ?>
                                                <a class="btn btn-sm btn-outline-primary"
                                                   href="?page=projects_admin&edit_wo_id=<?= $woId ?>&project_id=<?= (int)$selectedProjectId ?>&q_projects=<?= urlencode($qProjects) ?>&q_wos=<?= urlencode($qWos) ?>">
                                                    Rediger
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">Les</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="text-muted small">
                    Uttak kan føres direkte på arbeidsprosjekt uten arbeidsordre. Arbeidsordre er valgfritt i checkout.
                </div>
            </div>
        </div>
    </div>
</div>
