<?php
// public/pages/projecthub.php
//
// Prosjektrom – Prosjektstyring (grunnsystem)
// - Oppretter tabeller automatisk (CREATE TABLE IF NOT EXISTS)
// - Prosjekter: opprett, list, endre status
// - Oppgaver: opprett, list (åpne), oppdater status/ansvarlig/frist
// - Aktivitet: logger endringer (prosjekt/opg)
// - Ikke super-avansert, men solid grunnlag for styring og kontroll
//
// Forutsetter at index.php håndterer innlogging + 2FA + tilgang til projecthub*.

declare(strict_types=1);

use App\Database;

$username = (string)($_SESSION['username'] ?? '');
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

function ph_table_exists(PDO $pdo, string $table): bool {
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function ph_log_activity(PDO $pdo, array $row): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO projecthub_activity
                (project_id, task_id, actor_username, action, message, created_at)
            VALUES
                (:project_id, :task_id, :actor_username, :action, :message, NOW())
        ");
        $stmt->execute([
            ':project_id'      => $row['project_id'] ?? null,
            ':task_id'         => $row['task_id'] ?? null,
            ':actor_username'  => (string)($row['actor_username'] ?? ''),
            ':action'          => (string)($row['action'] ?? ''),
            ':message'         => (string)($row['message'] ?? ''),
        ]);
    } catch (\Throwable $e) {
        // fail-silent
    }
}

// ---------------------------------------------------------
// 1) Opprett tabeller (idempotent)
// ---------------------------------------------------------
try {
    // Prosjekter
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projecthub_projects (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            priority TINYINT UNSIGNED NOT NULL DEFAULT 3,
            start_date DATE NULL,
            due_date DATE NULL,
            owner_username VARCHAR(64) NULL,
            created_by VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_projecthub_projects_code (code),
            KEY idx_projecthub_projects_status (status),
            KEY idx_projecthub_projects_due (due_date),
            KEY idx_projecthub_projects_owner (owner_username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Oppgaver
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projecthub_tasks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            priority TINYINT UNSIGNED NOT NULL DEFAULT 3,
            assigned_to VARCHAR(64) NULL,
            due_date DATE NULL,
            completed_at DATETIME NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_projecthub_tasks_project (project_id),
            KEY idx_projecthub_tasks_status (status),
            KEY idx_projecthub_tasks_assigned (assigned_to),
            KEY idx_projecthub_tasks_due (due_date),
            CONSTRAINT fk_projecthub_tasks_project
                FOREIGN KEY (project_id) REFERENCES projecthub_projects(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Aktivitet
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projecthub_activity (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NULL,
            task_id BIGINT UNSIGNED NULL,
            actor_username VARCHAR(64) NOT NULL,
            action VARCHAR(64) NOT NULL,
            message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_projecthub_activity_project (project_id),
            KEY idx_projecthub_activity_task (task_id),
            KEY idx_projecthub_activity_created (created_at),
            CONSTRAINT fk_projecthub_activity_project
                FOREIGN KEY (project_id) REFERENCES projecthub_projects(id)
                ON DELETE SET NULL,
            CONSTRAINT fk_projecthub_activity_task
                FOREIGN KEY (task_id) REFERENCES projecthub_tasks(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Prosjektmedlemmer (valgfritt, men nyttig)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projecthub_project_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            username VARCHAR(64) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'member',
            added_by VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_projecthub_members (project_id, username),
            KEY idx_projecthub_members_user (username),
            CONSTRAINT fk_projecthub_members_project
                FOREIGN KEY (project_id) REFERENCES projecthub_projects(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (\Throwable $e) {
    ?>
    <div class="alert alert-danger">
        Klarte ikke å opprette tabeller for Prosjektrom. Feil: <?= h($e->getMessage()) ?>
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// 2) Handlers (POST)
// ---------------------------------------------------------
$flashOk = null;
$flashErr = null;

$allowedProjectStatus = ['active','on_hold','completed','cancelled'];
$allowedTaskStatus    = ['open','in_progress','blocked','done','cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create_project') {
            $code        = trim((string)($_POST['code'] ?? ''));
            $name        = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $status      = trim((string)($_POST['status'] ?? 'active'));
            $priority    = (int)($_POST['priority'] ?? 3);
            $startDate   = trim((string)($_POST['start_date'] ?? ''));
            $dueDate     = trim((string)($_POST['due_date'] ?? ''));
            $ownerUser   = trim((string)($_POST['owner_username'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Prosjektnavn kan ikke være tomt.');
            }
            if (!in_array($status, $allowedProjectStatus, true)) {
                $status = 'active';
            }
            if ($priority < 1) $priority = 1;
            if ($priority > 5) $priority = 5;

            $startDateDb = ($startDate !== '') ? $startDate : null;
            $dueDateDb   = ($dueDate !== '') ? $dueDate : null;

            $stmt = $pdo->prepare("
                INSERT INTO projecthub_projects
                    (code, name, description, status, priority, start_date, due_date, owner_username, created_by, created_at, updated_at)
                VALUES
                    (:code, :name, :description, :status, :priority, :start_date, :due_date, :owner_username, :created_by, NOW(), NOW())
            ");
            $stmt->execute([
                ':code'           => ($code !== '' ? $code : null),
                ':name'           => $name,
                ':description'    => ($description !== '' ? $description : null),
                ':status'         => $status,
                ':priority'       => $priority,
                ':start_date'     => $startDateDb,
                ':due_date'       => $dueDateDb,
                ':owner_username' => ($ownerUser !== '' ? $ownerUser : null),
                ':created_by'     => $username,
            ]);

            $projectId = (int)$pdo->lastInsertId();

            // legg gjerne eier som medlem automatisk
            if ($ownerUser !== '') {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO projecthub_project_members (project_id, username, role, added_by, created_at)
                    VALUES (:pid, :u, 'owner', :by, NOW())
                ");
                $stmt->execute([':pid' => $projectId, ':u' => $ownerUser, ':by' => $username]);
            }

            ph_log_activity($pdo, [
                'project_id'     => $projectId,
                'task_id'        => null,
                'actor_username' => $username,
                'action'         => 'project_created',
                'message'        => "Opprettet prosjekt: {$name}",
            ]);

            $flashOk = 'Prosjekt opprettet.';
        }

        if ($action === 'update_project_status') {
            $projectId = (int)($_POST['project_id'] ?? 0);
            $status    = trim((string)($_POST['status'] ?? ''));

            if ($projectId <= 0) throw new RuntimeException('Ugyldig prosjekt.');
            if (!in_array($status, $allowedProjectStatus, true)) throw new RuntimeException('Ugyldig status.');

            $stmt = $pdo->prepare("SELECT name, status FROM projecthub_projects WHERE id = :id");
            $stmt->execute([':id' => $projectId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new RuntimeException('Prosjekt finnes ikke.');

            $old = (string)$p['status'];
            $stmt = $pdo->prepare("UPDATE projecthub_projects SET status = :s, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':s' => $status, ':id' => $projectId]);

            ph_log_activity($pdo, [
                'project_id'     => $projectId,
                'task_id'        => null,
                'actor_username' => $username,
                'action'         => 'project_status',
                'message'        => "Endret prosjektstatus: {$p['name']} ({$old} → {$status})",
            ]);

            $flashOk = 'Prosjektstatus oppdatert.';
        }

        if ($action === 'create_task') {
            $projectId   = (int)($_POST['project_id'] ?? 0);
            $title       = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $assignedTo  = trim((string)($_POST['assigned_to'] ?? ''));
            $status      = trim((string)($_POST['status'] ?? 'open'));
            $priority    = (int)($_POST['priority'] ?? 3);
            $dueDate     = trim((string)($_POST['due_date'] ?? ''));

            if ($projectId <= 0) throw new RuntimeException('Velg et prosjekt.');
            if ($title === '') throw new RuntimeException('Oppgavetittel kan ikke være tom.');

            if (!in_array($status, $allowedTaskStatus, true)) $status = 'open';
            if ($priority < 1) $priority = 1;
            if ($priority > 5) $priority = 5;

            $dueDateDb = ($dueDate !== '') ? $dueDate : null;

            $stmt = $pdo->prepare("SELECT name FROM projecthub_projects WHERE id = :id");
            $stmt->execute([':id' => $projectId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new RuntimeException('Prosjekt finnes ikke.');

            $stmt = $pdo->prepare("
                INSERT INTO projecthub_tasks
                    (project_id, title, description, status, priority, assigned_to, due_date, created_by, created_at, updated_at)
                VALUES
                    (:pid, :title, :description, :status, :priority, :assigned_to, :due_date, :created_by, NOW(), NOW())
            ");
            $stmt->execute([
                ':pid'         => $projectId,
                ':title'       => $title,
                ':description' => ($description !== '' ? $description : null),
                ':status'      => $status,
                ':priority'    => $priority,
                ':assigned_to' => ($assignedTo !== '' ? $assignedTo : null),
                ':due_date'    => $dueDateDb,
                ':created_by'  => $username,
            ]);

            $taskId = (int)$pdo->lastInsertId();

            ph_log_activity($pdo, [
                'project_id'     => $projectId,
                'task_id'        => $taskId,
                'actor_username' => $username,
                'action'         => 'task_created',
                'message'        => "Ny oppgave i «{$p['name']}»: {$title}",
            ]);

            $flashOk = 'Oppgave opprettet.';
        }

        if ($action === 'update_task') {
            $taskId     = (int)($_POST['task_id'] ?? 0);
            $status     = trim((string)($_POST['status'] ?? ''));
            $assignedTo = trim((string)($_POST['assigned_to'] ?? ''));
            $dueDate    = trim((string)($_POST['due_date'] ?? ''));

            if ($taskId <= 0) throw new RuntimeException('Ugyldig oppgave.');
            if (!in_array($status, $allowedTaskStatus, true)) throw new RuntimeException('Ugyldig status.');

            $stmt = $pdo->prepare("
                SELECT t.id, t.title, t.status AS old_status, t.project_id, p.name AS project_name
                  FROM projecthub_tasks t
                  JOIN projecthub_projects p ON p.id = t.project_id
                 WHERE t.id = :id
                 LIMIT 1
            ");
            $stmt->execute([':id' => $taskId]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) throw new RuntimeException('Oppgave finnes ikke.');

            $dueDateDb = ($dueDate !== '') ? $dueDate : null;

            // Sett completed_at hvis done, ellers null
            $setCompleted = ($status === 'done') ? 'NOW()' : 'NULL';

            $stmt = $pdo->prepare("
                UPDATE projecthub_tasks
                   SET status = :status,
                       assigned_to = :assigned_to,
                       due_date = :due_date,
                       completed_at = {$setCompleted},
                       updated_at = NOW()
                 WHERE id = :id
            ");
            $stmt->execute([
                ':status'      => $status,
                ':assigned_to' => ($assignedTo !== '' ? $assignedTo : null),
                ':due_date'    => $dueDateDb,
                ':id'          => $taskId,
            ]);

            $old = (string)$t['old_status'];
            $msgParts = [];
            if ($old !== $status) $msgParts[] = "status {$old} → {$status}";
            $msgParts[] = "ansvarlig: " . ($assignedTo !== '' ? $assignedTo : '—');
            $msgParts[] = "frist: " . ($dueDateDb !== null ? $dueDateDb : '—');

            ph_log_activity($pdo, [
                'project_id'     => (int)$t['project_id'],
                'task_id'        => $taskId,
                'actor_username' => $username,
                'action'         => 'task_updated',
                'message'        => "Oppdatert «{$t['title']}» i «{$t['project_name']}»: " . implode(', ', $msgParts),
            ]);

            $flashOk = 'Oppgave oppdatert.';
        }
    } catch (\Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

// ---------------------------------------------------------
// 3) Hent data til oversikt
// ---------------------------------------------------------

$selectedProjectId = (int)($_GET['project_id'] ?? 0);
$showAllTasks = (int)($_GET['all_tasks'] ?? 0) === 1;

// Prosjekter (vis alle, sortert)
$stmt = $pdo->query("
    SELECT id, code, name, status, priority, start_date, due_date, owner_username, created_at, updated_at
      FROM projecthub_projects
     ORDER BY
        FIELD(status, 'active','on_hold','completed','cancelled'),
        (due_date IS NULL), due_date ASC,
        priority ASC,
        id DESC
");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// KPI
$kpi = [
    'projects_total' => 0,
    'projects_active' => 0,
    'tasks_open' => 0,
    'tasks_blocked' => 0,
    'tasks_due_soon' => 0,
];

$kpi['projects_total'] = count($projects);
foreach ($projects as $p) {
    if (($p['status'] ?? '') === 'active') $kpi['projects_active']++;
}

// Oppgaver: åpne (eller alle) for valgt prosjekt / alle prosjekter
$params = [];
$where = [];
if (!$showAllTasks) {
    $where[] = "t.status IN ('open','in_progress','blocked')";
}
if ($selectedProjectId > 0) {
    $where[] = "t.project_id = :pid";
    $params[':pid'] = $selectedProjectId;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sqlTasks = "
    SELECT
        t.id, t.project_id, t.title, t.status, t.priority, t.assigned_to, t.due_date, t.updated_at,
        p.name AS project_name
    FROM projecthub_tasks t
    JOIN projecthub_projects p ON p.id = t.project_id
    {$whereSql}
    ORDER BY
        FIELD(t.status, 'blocked','open','in_progress','done','cancelled'),
        (t.due_date IS NULL), t.due_date ASC,
        t.priority ASC,
        t.id DESC
    LIMIT 200
";
$stmt = $pdo->prepare($sqlTasks);
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($tasks as $t) {
    if (in_array(($t['status'] ?? ''), ['open','in_progress','blocked'], true)) $kpi['tasks_open']++;
    if (($t['status'] ?? '') === 'blocked') $kpi['tasks_blocked']++;

    // Due soon: innen 7 dager (kun hvis frist finnes)
    if (!empty($t['due_date'])) {
        $due = strtotime((string)$t['due_date']);
        if ($due !== false) {
            $days = (int)floor(($due - time()) / 86400);
            if ($days >= 0 && $days <= 7 && in_array(($t['status'] ?? ''), ['open','in_progress','blocked'], true)) {
                $kpi['tasks_due_soon']++;
            }
        }
    }
}

// Aktivitet (siste 30)
$params = [];
$where = [];
if ($selectedProjectId > 0) {
    $where[] = "a.project_id = :pid";
    $params[':pid'] = $selectedProjectId;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("
    SELECT a.id, a.project_id, a.task_id, a.actor_username, a.action, a.message, a.created_at
      FROM projecthub_activity a
      {$whereSql}
     ORDER BY a.id DESC
     LIMIT 30
");
$stmt->execute($params);
$activity = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ---------------------------------------------------------
// 4) UI
// ---------------------------------------------------------
?>
<section class="mb-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h1 class="h5 mb-1">Prosjektrom</h1>
            <div class="text-muted small">
                Prosjekter, oppgaver og aktivitet – samlet oversikt.
            </div>
        </div>

        <form method="get" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="page" value="projecthub">
            <select name="project_id" class="form-select form-select-sm" style="min-width:240px;">
                <option value="0">Alle prosjekter</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === $selectedProjectId) ? 'selected' : '' ?>>
                        <?= h(($p['code'] ? ($p['code'] . ' – ') : '') . $p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="allTasksSwitch"
                       name="all_tasks" value="1" <?= $showAllTasks ? 'checked' : '' ?>
                       onchange="this.form.submit()">
                <label class="form-check-label small" for="allTasksSwitch">Vis alle oppgaver</label>
            </div>

            <button class="btn btn-sm btn-outline-secondary" type="submit">
                <i class="bi bi-funnel me-1"></i> Filtrer
            </button>
        </form>
    </div>

    <?php if ($flashOk): ?>
        <div class="alert alert-success mt-3 mb-0"><?= h($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
        <div class="alert alert-danger mt-3 mb-0"><?= h($flashErr) ?></div>
    <?php endif; ?>
</section>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Prosjekter</div>
                <div class="h4 mb-0"><?= (int)$kpi['projects_total'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Aktive</div>
                <div class="h4 mb-0"><?= (int)$kpi['projects_active'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Åpne oppgaver</div>
                <div class="h4 mb-0"><?= (int)$kpi['tasks_open'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Frist &lt;= 7 dager</div>
                <div class="h4 mb-0"><?= (int)$kpi['tasks_due_soon'] ?></div>
                <div class="small text-muted">Blokkerte: <?= (int)$kpi['tasks_blocked'] ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Prosjekter -->
    <div class="col-12 col-xl-7">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div class="fw-semibold">Prosjekter</div>
                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newProject" aria-expanded="false">
                    <i class="bi bi-plus-circle me-1"></i> Nytt prosjekt
                </button>
            </div>

            <div class="collapse" id="newProject">
                <div class="card-body border-bottom">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="create_project">
                        <div class="col-12 col-md-4">
                            <label class="form-label small mb-1">Kode (valgfritt)</label>
                            <input name="code" class="form-control form-control-sm" placeholder="PRJ-1001">
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label small mb-1">Navn</label>
                            <input name="name" class="form-control form-control-sm" required placeholder="Prosjektnavn">
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1">Beskrivelse</label>
                            <textarea name="description" class="form-control form-control-sm" rows="2" placeholder="Kort beskrivelse..."></textarea>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="active">Aktiv</option>
                                <option value="on_hold">På vent</option>
                                <option value="completed">Ferdig</option>
                                <option value="cancelled">Avbrutt</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">Prioritet (1-5)</label>
                            <input name="priority" type="number" min="1" max="5" value="3" class="form-control form-control-sm">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">Start</label>
                            <input name="start_date" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">Frist</label>
                            <input name="due_date" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1">Eier/ansvarlig (brukernavn)</label>
                            <input name="owner_username" class="form-control form-control-sm" placeholder="f.eks. ola.nordmann">
                        </div>
                        <div class="col-12 col-md-6 d-flex align-items-end justify-content-end">
                            <button class="btn btn-sm btn-primary">
                                <i class="bi bi-check2 me-1"></i> Opprett
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th style="width:90px;">Kode</th>
                        <th>Prosjekt</th>
                        <th style="width:120px;">Status</th>
                        <th style="width:90px;">Prior.</th>
                        <th style="width:130px;">Frist</th>
                        <th style="width:170px;">Eier</th>
                        <th style="width:160px;"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$projects): ?>
                        <tr><td colspan="7" class="text-muted small p-3">Ingen prosjekter enda.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($projects as $p): ?>
                        <?php
                        $pid = (int)$p['id'];
                        $isSelected = ($selectedProjectId > 0 && $selectedProjectId === $pid);
                        ?>
                        <tr class="<?= $isSelected ? 'table-primary' : '' ?>">
                            <td class="text-muted small"><?= h((string)($p['code'] ?? '')) ?></td>
                            <td>
                                <div class="fw-semibold"><?= h((string)$p['name']) ?></div>
                                <div class="text-muted small">
                                    ID: <?= $pid ?>
                                    <?php if (!empty($p['start_date'])): ?> • Start: <?= h((string)$p['start_date']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <form method="post" class="d-flex gap-1 align-items-center">
                                    <input type="hidden" name="action" value="update_project_status">
                                    <input type="hidden" name="project_id" value="<?= $pid ?>">
                                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="active" <?= ($p['status'] === 'active') ? 'selected' : '' ?>>Aktiv</option>
                                        <option value="on_hold" <?= ($p['status'] === 'on_hold') ? 'selected' : '' ?>>På vent</option>
                                        <option value="completed" <?= ($p['status'] === 'completed') ? 'selected' : '' ?>>Ferdig</option>
                                        <option value="cancelled" <?= ($p['status'] === 'cancelled') ? 'selected' : '' ?>>Avbrutt</option>
                                    </select>
                                </form>
                            </td>
                            <td><?= (int)$p['priority'] ?></td>
                            <td class="small"><?= !empty($p['due_date']) ? h((string)$p['due_date']) : '<span class="text-muted">—</span>' ?></td>
                            <td class="small"><?= !empty($p['owner_username']) ? h((string)$p['owner_username']) : '<span class="text-muted">—</span>' ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="/?page=projecthub&project_id=<?= $pid ?>">
                                    <i class="bi bi-eye me-1"></i> Velg
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <!-- Oppgaver -->
    <div class="col-12 col-xl-5">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div class="fw-semibold">Oppgaver<?= $selectedProjectId > 0 ? ' (valgt prosjekt)' : '' ?></div>
                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newTask" aria-expanded="false">
                    <i class="bi bi-plus-circle me-1"></i> Ny oppgave
                </button>
            </div>

            <div class="collapse" id="newTask">
                <div class="card-body border-bottom">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="create_task">

                        <div class="col-12">
                            <label class="form-label small mb-1">Prosjekt</label>
                            <select name="project_id" class="form-select form-select-sm" required>
                                <option value="">Velg prosjekt...</option>
                                <?php foreach ($projects as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === $selectedProjectId) ? 'selected' : '' ?>>
                                        <?= h(($p['code'] ? ($p['code'] . ' – ') : '') . $p['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label small mb-1">Tittel</label>
                            <input name="title" class="form-control form-control-sm" required placeholder="Hva skal gjøres?">
                        </div>

                        <div class="col-12">
                            <label class="form-label small mb-1">Beskrivelse (valgfritt)</label>
                            <textarea name="description" class="form-control form-control-sm" rows="2"></textarea>
                        </div>

                        <div class="col-6">
                            <label class="form-label small mb-1">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="open">Åpen</option>
                                <option value="in_progress">Pågår</option>
                                <option value="blocked">Blokkert</option>
                                <option value="done">Ferdig</option>
                                <option value="cancelled">Avbrutt</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1">Prioritet (1-5)</label>
                            <input name="priority" type="number" min="1" max="5" value="3" class="form-control form-control-sm">
                        </div>

                        <div class="col-6">
                            <label class="form-label small mb-1">Ansvarlig (brukernavn)</label>
                            <input name="assigned_to" class="form-control form-control-sm" placeholder="f.eks. kari.hansen">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1">Frist</label>
                            <input name="due_date" type="date" class="form-control form-control-sm">
                        </div>

                        <div class="col-12 d-flex justify-content-end">
                            <button class="btn btn-sm btn-primary">
                                <i class="bi bi-check2 me-1"></i> Opprett
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Oppgave</th>
                        <th style="width:140px;">Status</th>
                        <th style="width:140px;">Ansvarlig</th>
                        <th style="width:140px;">Frist</th>
                        <th style="width:90px;"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$tasks): ?>
                        <tr><td colspan="5" class="text-muted small p-3">Ingen oppgaver i valgt visning.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($tasks as $t): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= h((string)$t['title']) ?></div>
                                <div class="text-muted small">
                                    <?= h((string)$t['project_name']) ?> • ID: <?= (int)$t['id'] ?> • Prioritet: <?= (int)$t['priority'] ?>
                                </div>
                            </td>

                            <td>
                                <form method="post" class="d-flex gap-1 align-items-center">
                                    <input type="hidden" name="action" value="update_task">
                                    <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">

                                    <select name="status" class="form-select form-select-sm">
                                        <option value="open" <?= ($t['status'] === 'open') ? 'selected' : '' ?>>Åpen</option>
                                        <option value="in_progress" <?= ($t['status'] === 'in_progress') ? 'selected' : '' ?>>Pågår</option>
                                        <option value="blocked" <?= ($t['status'] === 'blocked') ? 'selected' : '' ?>>Blokkert</option>
                                        <option value="done" <?= ($t['status'] === 'done') ? 'selected' : '' ?>>Ferdig</option>
                                        <option value="cancelled" <?= ($t['status'] === 'cancelled') ? 'selected' : '' ?>>Avbrutt</option>
                                    </select>
                            </td>

                            <td>
                                    <input name="assigned_to" class="form-control form-control-sm"
                                           value="<?= h((string)($t['assigned_to'] ?? '')) ?>" placeholder="—">
                            </td>

                            <td>
                                    <input name="due_date" type="date" class="form-control form-control-sm"
                                           value="<?= h((string)($t['due_date'] ?? '')) ?>">
                            </td>

                            <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" title="Lagre">
                                        <i class="bi bi-save"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <div class="card shadow-sm mt-3">
            <div class="card-header fw-semibold">Siste aktivitet</div>
            <div class="card-body p-0">
                <?php if (!$activity): ?>
                    <div class="p-3 text-muted small">Ingen aktivitet registrert enda.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($activity as $a): ?>
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between gap-2">
                                    <div style="min-width:0;">
                                        <div class="small">
                                            <span class="fw-semibold"><?= h((string)$a['actor_username']) ?></span>
                                            <span class="text-muted">• <?= h((string)$a['action']) ?></span>
                                        </div>
                                        <div class="small" style="opacity:.95;">
                                            <?= h((string)($a['message'] ?? '')) ?>
                                        </div>
                                    </div>
                                    <div class="text-muted small" style="white-space:nowrap;">
                                        <?= h((string)$a['created_at']) ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
