<?php
// public/pages/logistikk_storage_admin.php
//
// Admin for Lagerdimensjoner:
//  - Logisk lager (prosjekter): project_no + name + owner + is_active
//  - Fysisk lagerlokasjoner: inv_locations
//
// Rettigheter:
//  - warehouse_read  => se
//  - warehouse_write => endre
//
// NB: projects-tabellen må ha kolonnene:
//  - project_no (varchar)
//  - owner (varchar)
//
// Bruk: /?page=logistikk_storage_admin

use App\Database;

$pageTitle = 'Logistikk: Lagerdimensjoner';

// ---------------------------------------------------------
// Krev innlogging
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';
if (!$username) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

$pdo = null;
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
// Rolle-sjekk (user_roles) + bakoverkompatibel admin-fallback
// ---------------------------------------------------------
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

$canWarehouseRead  = $isAdmin || in_array('warehouse_read', $currentRoles, true) || in_array('warehouse_write', $currentRoles, true);
$canWarehouseWrite = $isAdmin || in_array('warehouse_write', $currentRoles, true);

if (!$canWarehouseRead) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til lagerdimensjoner.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function g_str(string $key, string $default = ''): string {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function g_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}

function post_str(string $key): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}

function post_int(string $key): int {
    return isset($_POST[$key]) ? (int)$_POST[$key] : 0;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :t
    ");
    $stmt->execute([':t' => $table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = :t
          AND column_name = :c
    ");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

// ---------------------------------------------------------
// DB-krav / kapabiliteter
// ---------------------------------------------------------
$errors = [];
$success = null;

$hasProjects = tableExists($pdo, 'projects');
$hasWorkOrders = tableExists($pdo, 'work_orders');
$hasInvMovements = tableExists($pdo, 'inv_movements');
$hasInvLocations = tableExists($pdo, 'inv_locations');

$projHasNo    = $hasProjects && columnExists($pdo, 'projects', 'project_no');
$projHasOwner = $hasProjects && columnExists($pdo, 'projects', 'owner');
$projHasActive= $hasProjects && columnExists($pdo, 'projects', 'is_active');

// ---------------------------------------------------------
// Input (tabs + edit)
// ---------------------------------------------------------
$tab = g_str('tab', 'logical'); // logical|physical
if (!in_array($tab, ['logical','physical'], true)) $tab = 'logical';

$editProjectId  = g_int('edit_project_id', 0);
$editLocationId = g_int('edit_location_id', 0);

// ---------------------------------------------------------
// POST handling
// ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$canWarehouseWrite) {
        $errors[] = 'Du har ikke rettighet til å endre (krever warehouse_write eller admin).';
    } else {
        $action = post_str('action');

        try {
            // -------------------------
            // Prosjekter (logisk lager)
            // -------------------------
            if ($action === 'create_project') {
                if (!$hasProjects) throw new RuntimeException('Mangler tabell: projects.');
                if (!$projHasNo || !$projHasOwner) {
                    throw new RuntimeException('projects mangler kolonner (project_no/owner). Kjør SQL-migrering først.');
                }

                $projectNo = post_str('project_no');
                $name      = post_str('name');
                $owner     = post_str('owner');
                $isActive  = post_int('is_active') ? 1 : 0;

                if ($projectNo === '') throw new RuntimeException('Prosjektnummer mangler.');
                if ($name === '') throw new RuntimeException('Prosjektnavn mangler.');

                $stmt = $pdo->prepare("
                    INSERT INTO projects (project_no, name, owner, is_active)
                    VALUES (:no, :name, :owner, :active)
                ");
                $stmt->execute([
                    ':no' => $projectNo,
                    ':name' => $name,
                    ':owner' => ($owner !== '' ? $owner : null),
                    ':active' => $isActive,
                ]);

                $success = 'Prosjekt opprettet.';
                $tab = 'logical';
            }

            if ($action === 'update_project') {
                if (!$hasProjects) throw new RuntimeException('Mangler tabell: projects.');
                if (!$projHasNo || !$projHasOwner) {
                    throw new RuntimeException('projects mangler kolonner (project_no/owner). Kjør SQL-migrering først.');
                }

                $id        = post_int('id');
                $projectNo = post_str('project_no');
                $name      = post_str('name');
                $owner     = post_str('owner');
                $isActive  = post_int('is_active') ? 1 : 0;

                if ($id <= 0) throw new RuntimeException('Ugyldig prosjekt.');
                if ($projectNo === '') throw new RuntimeException('Prosjektnummer mangler.');
                if ($name === '') throw new RuntimeException('Prosjektnavn mangler.');

                $stmt = $pdo->prepare("
                    UPDATE projects
                       SET project_no = :no,
                           name = :name,
                           owner = :owner,
                           is_active = :active
                     WHERE id = :id
                     LIMIT 1
                ");
                $stmt->execute([
                    ':no' => $projectNo,
                    ':name' => $name,
                    ':owner' => ($owner !== '' ? $owner : null),
                    ':active' => $isActive,
                    ':id' => $id,
                ]);

                $success = 'Prosjekt oppdatert.';
                $tab = 'logical';
                $editProjectId = $id;
            }

            if ($action === 'delete_project') {
                if (!$hasProjects) throw new RuntimeException('Mangler tabell: projects.');
                $id = post_int('id');
                if ($id <= 0) throw new RuntimeException('Ugyldig prosjekt.');

                // Sikkerhet: ikke slett hvis prosjekt er i bruk (work_orders / inv_movements / invoice_lines)
                $inUse = 0;

                if ($hasWorkOrders && columnExists($pdo, 'work_orders', 'project_id')) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM work_orders WHERE project_id = :id");
                    $st->execute([':id' => $id]);
                    $inUse += (int)$st->fetchColumn();
                }
                if ($hasInvMovements && columnExists($pdo, 'inv_movements', 'project_id')) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM inv_movements WHERE project_id = :id");
                    $st->execute([':id' => $id]);
                    $inUse += (int)$st->fetchColumn();
                }
                if (tableExists($pdo, 'billing_invoice_lines') && columnExists($pdo, 'billing_invoice_lines', 'project_id')) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM billing_invoice_lines WHERE project_id = :id");
                    $st->execute([':id' => $id]);
                    $inUse += (int)$st->fetchColumn();
                }

                if ($inUse > 0) {
                    // Deaktiver i stedet
                    if ($projHasActive) {
                        $st = $pdo->prepare("UPDATE projects SET is_active = 0 WHERE id = :id LIMIT 1");
                        $st->execute([':id' => $id]);
                        $success = 'Prosjekt er i bruk og ble derfor deaktivert (ikke slettet).';
                    } else {
                        throw new RuntimeException('Prosjekt er i bruk og kan ikke slettes.');
                    }
                } else {
                    $st = $pdo->prepare("DELETE FROM projects WHERE id = :id LIMIT 1");
                    $st->execute([':id' => $id]);
                    $success = 'Prosjekt slettet.';
                }

                $tab = 'logical';
                $editProjectId = 0;
            }

            // -------------------------
            // Fysiske lokasjoner
            // -------------------------
            if ($action === 'create_location') {
                if (!$hasInvLocations) throw new RuntimeException('Mangler tabell: inv_locations. Opprett den først (SQL vises på siden).');

                $code     = post_str('code');
                $name     = post_str('name');
                $address  = post_str('address');
                $isActive = post_int('is_active') ? 1 : 0;

                if ($code === '') throw new RuntimeException('Kode mangler.');
                if ($name === '') throw new RuntimeException('Navn mangler.');

                $stmt = $pdo->prepare("
                    INSERT INTO inv_locations (code, name, address, is_active)
                    VALUES (:code, :name, :address, :active)
                ");
                $stmt->execute([
                    ':code' => $code,
                    ':name' => $name,
                    ':address' => ($address !== '' ? $address : null),
                    ':active' => $isActive,
                ]);

                $success = 'Lokasjon opprettet.';
                $tab = 'physical';
            }

            if ($action === 'update_location') {
                if (!$hasInvLocations) throw new RuntimeException('Mangler tabell: inv_locations. Opprett den først (SQL vises på siden).');

                $id       = post_int('id');
                $code     = post_str('code');
                $name     = post_str('name');
                $address  = post_str('address');
                $isActive = post_int('is_active') ? 1 : 0;

                if ($id <= 0) throw new RuntimeException('Ugyldig lokasjon.');
                if ($code === '') throw new RuntimeException('Kode mangler.');
                if ($name === '') throw new RuntimeException('Navn mangler.');

                $stmt = $pdo->prepare("
                    UPDATE inv_locations
                       SET code = :code,
                           name = :name,
                           address = :address,
                           is_active = :active
                     WHERE id = :id
                     LIMIT 1
                ");
                $stmt->execute([
                    ':code' => $code,
                    ':name' => $name,
                    ':address' => ($address !== '' ? $address : null),
                    ':active' => $isActive,
                    ':id' => $id,
                ]);

                $success = 'Lokasjon oppdatert.';
                $tab = 'physical';
                $editLocationId = $id;
            }

            if ($action === 'delete_location') {
                if (!$hasInvLocations) throw new RuntimeException('Mangler tabell: inv_locations.');

                $id = post_int('id');
                if ($id <= 0) throw new RuntimeException('Ugyldig lokasjon.');

                // Foreløpig: vi har ikke FK-er til lokasjon i dagens schema,
                // så vi tillater sletting direkte.
                $stmt = $pdo->prepare("DELETE FROM inv_locations WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $id]);

                $success = 'Lokasjon slettet.';
                $tab = 'physical';
                $editLocationId = 0;
            }

        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// ---------------------------------------------------------
// Fetch data for UI
// ---------------------------------------------------------
$projects = [];
$editProject = null;

if ($hasProjects) {
    try {
        $select = "id, name";
        if ($projHasNo) $select .= ", project_no"; else $select .= ", '' AS project_no";
        if ($projHasOwner) $select .= ", owner"; else $select .= ", '' AS owner";
        if ($projHasActive) $select .= ", is_active"; else $select .= ", 1 AS is_active";

        $stmt = $pdo->query("
            SELECT $select
              FROM projects
             ORDER BY is_active DESC, project_no ASC, name ASC
             LIMIT 2000
        ");
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($editProjectId > 0) {
            foreach ($projects as $p) {
                if ((int)$p['id'] === (int)$editProjectId) {
                    $editProject = $p;
                    break;
                }
            }
            if (!$editProject) {
                $editProjectId = 0;
            }
        }
    } catch (\Throwable $e) {
        $errors[] = 'Kunne ikke hente prosjekter.';
    }
}

$locations = [];
$editLocation = null;

if ($hasInvLocations) {
    try {
        $stmt = $pdo->query("
            SELECT id, code, name, address, is_active, created_at
              FROM inv_locations
             ORDER BY is_active DESC, code ASC, name ASC
             LIMIT 2000
        ");
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($editLocationId > 0) {
            foreach ($locations as $l) {
                if ((int)$l['id'] === (int)$editLocationId) {
                    $editLocation = $l;
                    break;
                }
            }
            if (!$editLocation) {
                $editLocationId = 0;
            }
        }
    } catch (\Throwable $e) {
        $errors[] = 'Kunne ikke hente lokasjoner.';
    }
}

// Form defaults
$projectForm = [
    'id' => $editProject ? (int)$editProject['id'] : 0,
    'project_no' => (string)($editProject['project_no'] ?? ''),
    'name' => (string)($editProject['name'] ?? ''),
    'owner' => (string)($editProject['owner'] ?? ''),
    'is_active' => (int)($editProject['is_active'] ?? 1),
];

$locationForm = [
    'id' => $editLocation ? (int)$editLocation['id'] : 0,
    'code' => (string)($editLocation['code'] ?? ''),
    'name' => (string)($editLocation['name'] ?? ''),
    'address' => (string)($editLocation['address'] ?? ''),
    'is_active' => (int)($editLocation['is_active'] ?? 1),
];

// ---------------------------------------------------------
// UI
// ---------------------------------------------------------
?>
<div class="container-fluid">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h3 class="mb-0">Lagerdimensjoner</h3>
            <div class="text-muted">Administrer logisk lager (prosjekt) og fysisk lagerlokasjon.</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk">
                <i class="bi bi-arrow-left"></i> Til oversikt
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1">Feil</div>
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= h($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!$canWarehouseWrite): ?>
        <div class="alert alert-info">
            Du har lesetilgang, men ikke skrivetilgang. Endringer krever <code>warehouse_write</code> eller <code>admin</code>.
        </div>
    <?php endif; ?>

    <?php if ($hasProjects && (!$projHasNo || !$projHasOwner)): ?>
        <div class="alert alert-warning">
            <div class="fw-semibold mb-1">Prosjekt-tabellen mangler kolonner</div>
            For å støtte logisk lager med prosjektnummer og eier, kjør:
            <pre class="mb-0 mt-2"><code>ALTER TABLE projects
  ADD COLUMN project_no VARCHAR(64) NOT NULL DEFAULT '' AFTER id,
  ADD COLUMN owner VARCHAR(255) DEFAULT NULL AFTER name;

CREATE INDEX idx_projects_project_no ON projects(project_no);</code></pre>
        </div>
    <?php endif; ?>

    <?php if (!$hasInvLocations): ?>
        <div class="alert alert-warning">
            <div class="fw-semibold mb-1">Mangler tabell for fysisk lager</div>
            Opprett <code>inv_locations</code> slik:
            <pre class="mb-0 mt-2"><code>CREATE TABLE inv_locations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(128) NOT NULL,
  address VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_invloc_code (code),
  KEY idx_invloc_active (is_active),
  KEY idx_invloc_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;</code></pre>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'logical' ? 'active' : '' ?>" href="/?page=logistikk_storage_admin&tab=logical">
                <i class="bi bi-diagram-3 me-1"></i> Logisk lager (prosjekt)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'physical' ? 'active' : '' ?>" href="/?page=logistikk_storage_admin&tab=physical">
                <i class="bi bi-geo-alt me-1"></i> Fysisk lagerlokasjon
            </a>
        </li>
    </ul>

    <?php if ($tab === 'logical'): ?>
        <div class="row g-3">
            <div class="col-12 col-xxl-4">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span class="fw-semibold"><?= $editProject ? 'Rediger prosjekt' : 'Nytt prosjekt' ?></span>
                        <?php if ($editProject): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_storage_admin&tab=logical">Avbryt</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!$canWarehouseWrite): ?>
                            <div class="alert alert-secondary mb-0">Opprett/rediger er deaktivert.</div>
                        <?php elseif (!$hasProjects || !$projHasNo || !$projHasOwner): ?>
                            <div class="alert alert-secondary mb-0">Kan ikke redigere før projects er utvidet med <code>project_no</code> og <code>owner</code>.</div>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="action" value="<?= $editProject ? 'update_project' : 'create_project' ?>">
                                <?php if ($editProject): ?>
                                    <input type="hidden" name="id" value="<?= (int)$projectForm['id'] ?>">
                                <?php endif; ?>

                                <div class="mb-2">
                                    <label class="form-label">Prosjektnummer</label>
                                    <input class="form-control" name="project_no" required
                                           value="<?= h($projectForm['project_no']) ?>"
                                           placeholder="f.eks. 2025-041 / P-12345">
                                </div>

                                <div class="mb-2">
                                    <label class="form-label">Prosjektnavn (logisk lager)</label>
                                    <input class="form-control" name="name" required
                                           value="<?= h($projectForm['name']) ?>"
                                           placeholder="f.eks. Utbygging Stord – Node A">
                                </div>

                                <div class="mb-2">
                                    <label class="form-label">Eier</label>
                                    <input class="form-control" name="owner"
                                           value="<?= h($projectForm['owner']) ?>"
                                           placeholder="f.eks. HKF / Avdeling / Navn">
                                </div>

                                <div class="mt-2">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="is_active">
                                        <option value="1" <?= ((int)$projectForm['is_active'] === 1) ? 'selected' : '' ?>>Aktiv</option>
                                        <option value="0" <?= ((int)$projectForm['is_active'] === 0) ? 'selected' : '' ?>>Inaktiv</option>
                                    </select>
                                    <div class="form-text">
                                        Inaktiv skjules i nye registreringer, men historikk beholdes.
                                    </div>
                                </div>

                                <div class="mt-3 d-flex gap-2">
                                    <button class="btn btn-primary">
                                        <i class="bi bi-save me-1"></i> <?= $editProject ? 'Lagre' : 'Opprett' ?>
                                    </button>
                                </div>
                            </form>

                            <?php if ($editProject): ?>
                                <hr>
                                <form method="post" onsubmit="return confirm('Slette prosjekt? Hvis prosjektet er i bruk, blir det deaktivert i stedet.');">
                                    <input type="hidden" name="action" value="delete_project">
                                    <input type="hidden" name="id" value="<?= (int)$projectForm['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash me-1"></i> Slett / deaktiver
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xxl-8">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span class="fw-semibold">Prosjekter (logiske lager)</span>
                        <span class="text-muted small"><?= (int)count($projects) ?> stk</span>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Prosjektnr</th>
                                        <th>Prosjektnavn</th>
                                        <th>Eier</th>
                                        <th>Status</th>
                                        <th class="text-end">Handling</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$projects): ?>
                                        <tr><td colspan="6" class="text-muted p-3">Ingen prosjekter.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($projects as $p): ?>
                                            <?php
                                            $pid = (int)$p['id'];
                                            $active = (int)($p['is_active'] ?? 1);
                                            ?>
                                            <tr>
                                                <td class="text-muted"><?= $pid ?></td>
                                                <td class="fw-semibold"><?= h((string)($p['project_no'] ?? '')) ?></td>
                                                <td><?= h((string)($p['name'] ?? '')) ?></td>
                                                <td class="text-muted"><?= h((string)($p['owner'] ?? '')) ?></td>
                                                <td>
                                                    <?php if ($active === 1): ?>
                                                        <span class="badge bg-success">Aktiv</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Inaktiv</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($canWarehouseWrite): ?>
                                                        <a class="btn btn-sm btn-outline-primary"
                                                           href="/?page=logistikk_storage_admin&tab=logical&edit_project_id=<?= $pid ?>">
                                                            <i class="bi bi-pencil"></i> Rediger
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Les</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card-footer small text-muted">
                        Logisk lager = regnskapsmessig tilhørighet (prosjekt). Bruk prosjektnummer som primærnøkkel for rapportering.
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-12 col-xxl-4">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span class="fw-semibold"><?= $editLocation ? 'Rediger lokasjon' : 'Ny lokasjon' ?></span>
                        <?php if ($editLocation): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_storage_admin&tab=physical">Avbryt</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!$canWarehouseWrite): ?>
                            <div class="alert alert-secondary mb-0">Opprett/rediger er deaktivert.</div>
                        <?php elseif (!$hasInvLocations): ?>
                            <div class="alert alert-secondary mb-0">Opprett tabellen <code>inv_locations</code> først (SQL vises over).</div>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="action" value="<?= $editLocation ? 'update_location' : 'create_location' ?>">
                                <?php if ($editLocation): ?>
                                    <input type="hidden" name="id" value="<?= (int)$locationForm['id'] ?>">
                                <?php endif; ?>

                                <div class="mb-2">
                                    <label class="form-label">Kode</label>
                                    <input class="form-control" name="code" required
                                           value="<?= h($locationForm['code']) ?>"
                                           placeholder="f.eks. HAU-01 / LAGER-A / HYLLE-3">
                                </div>

                                <div class="mb-2">
                                    <label class="form-label">Navn</label>
                                    <input class="form-control" name="name" required
                                           value="<?= h($locationForm['name']) ?>"
                                           placeholder="f.eks. Haugesund – hovedlager">
                                </div>

                                <div class="mb-2">
                                    <label class="form-label">Adresse / notat</label>
                                    <input class="form-control" name="address"
                                           value="<?= h($locationForm['address']) ?>"
                                           placeholder="Valgfritt">
                                </div>

                                <div class="mt-2">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="is_active">
                                        <option value="1" <?= ((int)$locationForm['is_active'] === 1) ? 'selected' : '' ?>>Aktiv</option>
                                        <option value="0" <?= ((int)$locationForm['is_active'] === 0) ? 'selected' : '' ?>>Inaktiv</option>
                                    </select>
                                </div>

                                <div class="mt-3 d-flex gap-2">
                                    <button class="btn btn-primary">
                                        <i class="bi bi-save me-1"></i> <?= $editLocation ? 'Lagre' : 'Opprett' ?>
                                    </button>
                                </div>
                            </form>

                            <?php if ($editLocation): ?>
                                <hr>
                                <form method="post" onsubmit="return confirm('Slette lokasjon?');">
                                    <input type="hidden" name="action" value="delete_location">
                                    <input type="hidden" name="id" value="<?= (int)$locationForm['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash me-1"></i> Slett
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xxl-8">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span class="fw-semibold">Fysiske lagerlokasjoner</span>
                        <span class="text-muted small"><?= (int)count($locations) ?> stk</span>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Kode</th>
                                        <th>Navn</th>
                                        <th>Adresse</th>
                                        <th>Status</th>
                                        <th class="text-end">Handling</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$hasInvLocations): ?>
                                        <tr><td colspan="6" class="text-muted p-3">Tabellen <code>inv_locations</code> finnes ikke enda.</td></tr>
                                    <?php elseif (!$locations): ?>
                                        <tr><td colspan="6" class="text-muted p-3">Ingen lokasjoner.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($locations as $l): ?>
                                            <?php
                                            $lid = (int)$l['id'];
                                            $active = (int)($l['is_active'] ?? 1);
                                            ?>
                                            <tr>
                                                <td class="text-muted"><?= $lid ?></td>
                                                <td class="fw-semibold"><?= h((string)$l['code']) ?></td>
                                                <td><?= h((string)$l['name']) ?></td>
                                                <td class="text-muted"><?= h((string)($l['address'] ?? '')) ?></td>
                                                <td>
                                                    <?php if ($active === 1): ?>
                                                        <span class="badge bg-success">Aktiv</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Inaktiv</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($canWarehouseWrite): ?>
                                                        <a class="btn btn-sm btn-outline-primary"
                                                           href="/?page=logistikk_storage_admin&tab=physical&edit_location_id=<?= $lid ?>">
                                                            <i class="bi bi-pencil"></i> Rediger
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Les</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card-footer small text-muted">
                        Fysisk lokasjon = hvor varen faktisk ligger (lager, hylle, rom, bil osv).
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>
