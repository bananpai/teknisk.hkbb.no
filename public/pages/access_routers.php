<?php
// public/pages/access_routers.php

use App\Database;

// ---------------------------------------------------------
// Tilgang: admin OR network (fra user_roles). Ingen hardkoding.
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';

if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til administrasjon av aksess-rutere.
    </div>
    <?php
    return;
}

$pdo = null;
$hasAccess = false;

try {
    $pdo = Database::getConnection();

    // Finn current user_id
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $currentUserId = (int)($stmt->fetchColumn() ?: 0);

    if ($currentUserId > 0) {
        // Sjekk roller i user_roles: admin eller network
        $stmt = $pdo->prepare("
            SELECT 1
              FROM user_roles
             WHERE user_id = :uid
               AND role IN ('admin','network')
             LIMIT 1
        ");
        $stmt->execute([':uid' => $currentUserId]);
        $hasAccess = (bool)$stmt->fetchColumn();
    }
} catch (\Throwable $e) {
    $hasAccess = false;
}

if (!$hasAccess) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til administrasjon av aksess-rutere.
    </div>
    <?php
    return;
}

$errors         = [];
$successMessage = null;

$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$pdo    = $pdo ?? Database::getConnection();

// ---------------------------------------------------------
// POST-håndtering
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Legg til ny aksess-router
    if ($action === 'add_access_router') {
        $name     = trim($_POST['name'] ?? '');
        $mgmtIp   = trim($_POST['mgmt_ip'] ?? '');
        $bundleId = trim($_POST['bundle_id'] ?? '');
        $uplink   = trim($_POST['uplink_port'] ?? '');
        $nodeType = trim($_POST['node_type'] ?? '');

        if ($name === '') {
            $errors[] = 'Navn må fylles ut.';
        }
        if ($mgmtIp === '') {
            $errors[] = 'Management IP må fylles ut.';
        } elseif (!filter_var($mgmtIp, FILTER_VALIDATE_IP)) {
            $errors[] = 'Management IP er ikke gyldig.';
        }
        if ($bundleId === '') {
            $errors[] = 'Bundle ID må fylles ut.';
        }
        if ($uplink === '') {
            $errors[] = 'Uplink-port må fylles ut.';
        }
        if ($nodeType === '') {
            $errors[] = 'Nodetype må fylles ut.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO access_routers (name, mgmt_ip, bundle_id, uplink_port, node_type, is_active)
                     VALUES (:name, :mgmt_ip, :bundle_id, :uplink_port, :node_type, 1)'
                );
                $stmt->execute([
                    ':name'        => $name,
                    ':mgmt_ip'     => $mgmtIp,
                    ':bundle_id'   => $bundleId,
                    ':uplink_port' => $uplink,
                    ':node_type'   => $nodeType,
                ]);

                $successMessage = 'Ny aksess-router ble lagt til.';
                $_POST          = [];
            } catch (\Throwable $e) {
                $errors[] = 'Klarte ikke å lagre aksess-router i databasen.';
            }
        }
    }

    // Oppdater eksisterende aksess-router
    if ($action === 'update_access_router') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $mgmtIp   = trim($_POST['mgmt_ip'] ?? '');
        $bundleId = trim($_POST['bundle_id'] ?? '');
        $uplink   = trim($_POST['uplink_port'] ?? '');
        $nodeType = trim($_POST['node_type'] ?? '');
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        if ($id <= 0) {
            $errors[] = 'Mangler ID for aksess-router.';
        }
        if ($name === '') {
            $errors[] = 'Navn må fylles ut.';
        }
        if ($mgmtIp === '') {
            $errors[] = 'Management IP må fylles ut.';
        } elseif (!filter_var($mgmtIp, FILTER_VALIDATE_IP)) {
            $errors[] = 'Management IP er ikke gyldig.';
        }
        if ($bundleId === '') {
            $errors[] = 'Bundle ID må fylles ut.';
        }
        if ($uplink === '') {
            $errors[] = 'Uplink-port må fylles ut.';
        }
        if ($nodeType === '') {
            $errors[] = 'Nodetype må fylles ut.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE access_routers
                        SET name        = :name,
                            mgmt_ip     = :mgmt_ip,
                            bundle_id   = :bundle_id,
                            uplink_port = :uplink_port,
                            node_type   = :node_type,
                            is_active   = :is_active
                      WHERE id = :id'
                );
                $stmt->execute([
                    ':name'        => $name,
                    ':mgmt_ip'     => $mgmtIp,
                    ':bundle_id'   => $bundleId,
                    ':uplink_port' => $uplink,
                    ':node_type'   => $nodeType,
                    ':is_active'   => $isActive,
                    ':id'          => $id,
                ]);

                $successMessage = 'Aksess-router ble oppdatert.';
                $editId         = $id;
            } catch (\Throwable $e) {
                $errors[] = 'Klarte ikke å oppdatere aksess-router i databasen.';
            }
        }
    }
}

// ---------------------------------------------------------
// Hent alle aksess-rutere
// ---------------------------------------------------------
$stmt = $pdo->query(
    'SELECT id, name, mgmt_ip, bundle_id, uplink_port, node_type, is_active, created_at
       FROM access_routers
      ORDER BY name'
);
$routers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------
// Hent data for redigering
// ---------------------------------------------------------
$editRouter = null;
if ($editId > 0) {
    foreach ($routers as $r) {
        if ((int)$r['id'] === $editId) {
            $editRouter = $r;
            break;
        }
    }
}
?>

<div class="mb-3">
    <h1 class="h4 mb-1">Aksess-rutere</h1>
    <p class="text-muted small mb-0">
        Register over Aksess Routere i nettet. Disse brukes senere når systemet genererer konfigurasjon
        for nye kunder (aksessdelen).
    </p>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger small">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success small">
        <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<section class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
            <h2 class="h6 mb-0">Eksisterende aksess-rutere</h2>

            <!-- Live-søk: filtrerer mens man skriver -->
            <div class="ms-auto" style="max-width:260px;">
                <input
                    type="text"
                    id="accessSearch"
                    class="form-control form-control-sm"
                    placeholder="Søk i navn, IP, bundle, uplink, nodetype..."
                >
            </div>
        </div>

        <?php if (empty($routers)): ?>
            <p class="text-muted small mb-0">Ingen aksess-rutere er registrert ennå.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="accessTable">
                    <thead>
                        <tr>
                            <th>Navn</th>
                            <th>Mgmt IP</th>
                            <th>Bundle ID</th>
                            <th>Uplink-port</th>
                            <th>Nodetype</th>
                            <th>Status</th>
                            <th>Opprettet</th>
                            <th style="width:1%; white-space:nowrap;">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($routers as $r): ?>
                        <tr class="access-row">
                            <td><?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><code><?php echo htmlspecialchars($r['mgmt_ip'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars($r['bundle_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['uplink_port'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($r['node_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if (!empty($r['is_active'])): ?>
                                    <span class="badge text-bg-success">Aktiv</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inaktiv</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?php echo htmlspecialchars($r['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td class="text-nowrap">
                                <a
                                    href="/?page=access_routers&edit_id=<?php echo (int)$r['id']; ?>#edit-access-router"
                                    class="btn btn-sm btn-outline-secondary py-0 px-2"
                                    title="Rediger"
                                >
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Legg til ny aksess-router</h2>
        <p class="small text-muted mb-2">
            Disse oppføringene brukes som grunnlag når det genereres konfigurasjon for kundens aksesslag.
        </p>

        <form method="post" class="row g-3" style="max-width: 520px;">
            <input type="hidden" name="action" value="add_access_router">

            <div class="col-12">
                <label for="ar_name" class="form-label form-label-sm">Navn</label>
                <input
                    type="text"
                    id="ar_name"
                    name="name"
                    class="form-control form-control-sm"
                    required
                    value="<?php echo htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="F.eks. AR-Bergen-01"
                >
            </div>

            <div class="col-12">
                <label for="ar_mgmt_ip" class="form-label form-label-sm">Management IP</label>
                <input
                    type="text"
                    id="ar_mgmt_ip"
                    name="mgmt_ip"
                    class="form-control form-control-sm"
                    required
                    value="<?php echo htmlspecialchars($_POST['mgmt_ip'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="F.eks. 10.0.0.10"
                >
            </div>

            <div class="col-12">
                <label for="ar_bundle_id" class="form-label form-label-sm">Bundle ID</label>
                <input
                    type="text"
                    id="ar_bundle_id"
                    name="bundle_id"
                    class="form-control form-control-sm"
                    required
                    value="<?php echo htmlspecialchars($_POST['bundle_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="F.eks. BE100, Port-Channel10"
                >
            </div>

            <div class="col-12">
                <label for="ar_uplink" class="form-label form-label-sm">Uplink-port</label>
                <input
                    type="text"
                    id="ar_uplink"
                    name="uplink_port"
                    class="form-control form-control-sm"
                    required
                    value="<?php echo htmlspecialchars($_POST['uplink_port'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="F.eks. TenGigE0/0/0/1"
                >
            </div>

            <div class="col-12">
                <label for="ar_node_type" class="form-label form-label-sm">Nodetype</label>
                <input
                    type="text"
                    id="ar_node_type"
                    name="node_type"
                    class="form-control form-control-sm"
                    required
                    value="<?php echo htmlspecialchars($_POST['node_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="F.eks. ASR920, NCS540"
                >
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> Legg til aksess-router
                </button>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm mb-3" id="edit-access-router">
    <div class="card-body">
        <h2 class="h6 mb-2">Rediger aksess-router</h2>
        <p class="small text-muted mb-2">
            Velg ✏-ikonet i listen over for å laste inn en aksess-router her.
        </p>

        <?php if ($editRouter): ?>
            <form method="post" class="row g-3" style="max-width: 520px;">
                <input type="hidden" name="action" value="update_access_router">
                <input type="hidden" name="id" value="<?php echo (int)$editRouter['id']; ?>">

                <div class="col-12">
                    <label class="form-label form-label-sm" for="edit_ar_name">Navn</label>
                    <input
                        type="text"
                        id="edit_ar_name"
                        name="name"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['name'] ?? $editRouter['name'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="col-12">
                    <label class="form-label form-label-sm" for="edit_ar_mgmt_ip">Management IP</label>
                    <input
                        type="text"
                        id="edit_ar_mgmt_ip"
                        name="mgmt_ip"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['mgmt_ip'] ?? $editRouter['mgmt_ip'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="col-12">
                    <label class="form-label form-label-sm" for="edit_ar_bundle_id">Bundle ID</label>
                    <input
                        type="text"
                        id="edit_ar_bundle_id"
                        name="bundle_id"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['bundle_id'] ?? $editRouter['bundle_id'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="col-12">
                    <label class="form-label form-label-sm" for="edit_ar_uplink">Uplink-port</label>
                    <input
                        type="text"
                        id="edit_ar_uplink"
                        name="uplink_port"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['uplink_port'] ?? $editRouter['uplink_port'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="col-12">
                    <label class="form-label form-label-sm" for="edit_ar_node_type">Nodetype</label>
                    <input
                        type="text"
                        id="edit_ar_node_type"
                        name="node_type"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['node_type'] ?? $editRouter['node_type'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="edit_ar_active"
                            name="is_active"
                            value="1"
                            <?php echo (!empty($_POST) ? !empty($_POST['is_active']) : !empty($editRouter['is_active'])) ? 'checked' : ''; ?>
                        >
                        <label class="form-check-label small" for="edit_ar_active">
                            Aksess-router er aktiv
                        </label>
                    </div>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i> Lagre endringer
                    </button>
                    <a href="/?page=access_routers" class="btn btn-outline-secondary btn-sm">
                        Avbryt
                    </a>
                </div>
            </form>
        <?php else: ?>
            <p class="small text-muted mb-0">
                Ingen aksess-router valgt for redigering.
            </p>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('accessSearch');
    var table = document.getElementById('accessTable');
    if (!input || !table) return;

    var rows = table.querySelectorAll('tbody tr.access-row');

    input.addEventListener('input', function () {
        var q = input.value.toLowerCase();

        rows.forEach(function (row) {
            var text = row.textContent.toLowerCase();
            if (!q || text.indexOf(q) !== -1) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
});
</script>
