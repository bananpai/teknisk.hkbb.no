<?php
// public/pages/edge_routers.php

use App\Database;

// ---------------------------------------------------------
// Tilgang: admin OR network (fra user_roles). Ingen hardkoding.
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';

if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til administrasjon av edge-rutere.
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
        Du har ikke tilgang til administrasjon av edge-rutere.
    </div>
    <?php
    return;
}

$errors         = [];
$successMessage = null;

$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$pdo    = $pdo ?? Database::getConnection();

// ---------------------------------------------------------
// Hent grossist-vendorer (brukes både i liste og i redigering)
// ---------------------------------------------------------
$vendors = [];
try {
    $stmt = $pdo->query(
        'SELECT id, name
           FROM grossist_vendors
          WHERE is_active = 1
          ORDER BY sort_order, name'
    );
    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $vendors = [];
}

// Bygg et map vendor_id => vendor_name for rask lookup
$vendorNameById = [];
foreach ($vendors as $v) {
    $vendorNameById[(int)$v['id']] = $v['name'];
}

// ---------------------------------------------------------
// POST-håndtering
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Legg til ny edge-router (kun global info)
    if ($action === 'add_edge_router') {
        $name      = trim($_POST['name'] ?? '');
        $mgmtIp    = trim($_POST['mgmt_ip'] ?? '');
        $edgeType  = trim($_POST['edge_type'] ?? '');

        if ($name === '') {
            $errors[] = 'Navn må fylles ut.';
        }
        if ($mgmtIp === '') {
            $errors[] = 'Management IP må fylles ut.';
        } elseif (!filter_var($mgmtIp, FILTER_VALIDATE_IP)) {
            $errors[] = 'Management IP er ikke gyldig.';
        }
        if ($edgeType === '') {
            $errors[] = 'Type edge-router må fylles ut.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO edge_routers (name, mgmt_ip, edge_type, is_active)
                     VALUES (:name, :mgmt_ip, :edge_type, 1)'
                );
                $stmt->execute([
                    ':name'      => $name,
                    ':mgmt_ip'   => $mgmtIp,
                    ':edge_type' => $edgeType,
                ]);

                $successMessage = 'Ny edge-router ble lagt til.';
                $_POST = [];
            } catch (\Throwable $e) {
                $errors[] = 'Klarte ikke å lagre edge-router i databasen.';
            }
        }
    }

    // Oppdater eksisterende edge-router + grossist-konfig
    if ($action === 'update_edge_router') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $mgmtIp   = trim($_POST['mgmt_ip'] ?? '');
        $edgeType = trim($_POST['edge_type'] ?? '');
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        // grossist-valg: vendor_has_config[<vendor_id>] = 1
        $vendorHasConfig = $_POST['vendor_has_config'] ?? [];

        if ($id <= 0) {
            $errors[] = 'Mangler ID for edge-router.';
        }
        if ($name === '') {
            $errors[] = 'Navn må fylles ut.';
        }
        if ($mgmtIp === '') {
            $errors[] = 'Management IP må fylles ut.';
        } elseif (!filter_var($mgmtIp, FILTER_VALIDATE_IP)) {
            $errors[] = 'Management IP er ikke gyldig.';
        }
        if ($edgeType === '') {
            $errors[] = 'Type edge-router må fylles ut.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // 1) Oppdater edge-router
                $stmt = $pdo->prepare(
                    'UPDATE edge_routers
                        SET name      = :name,
                            mgmt_ip   = :mgmt_ip,
                            edge_type = :edge_type,
                            is_active = :is_active
                      WHERE id = :id'
                );
                $stmt->execute([
                    ':name'      => $name,
                    ':mgmt_ip'   => $mgmtIp,
                    ':edge_type' => $edgeType,
                    ':is_active' => $isActive,
                    ':id'        => $id,
                ]);

                // 2) Oppdater koblingen mot grossister
                // Slett eksisterende rader for denne edge-routeren
                $del = $pdo->prepare(
                    'DELETE FROM edge_router_vendor_config
                      WHERE edge_router_id = :er'
                );
                $del->execute([':er' => $id]);

                // Sett inn rader for de vendorene som er markert som konfigurert
                if (!empty($vendors)) {
                    $ins = $pdo->prepare(
                        'INSERT INTO edge_router_vendor_config (edge_router_id, vendor_id, has_config, configured_at)
                         VALUES (:er, :vid, 1, NOW())'
                    );

                    foreach ($vendors as $v) {
                        $vid = (int)$v['id'];
                        if (!empty($vendorHasConfig[$vid])) {
                            $ins->execute([
                                ':er'  => $id,
                                ':vid' => $vid,
                            ]);
                        }
                    }
                }

                $pdo->commit();

                $successMessage = 'Edge-router og grossist-konfigurasjon ble oppdatert.';
                $editId         = $id;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Klarte ikke å oppdatere edge-router/grossist-konfigurasjon i databasen.';
            }
        }
    }
}

// ---------------------------------------------------------
// Hent alle edge-rutere
// ---------------------------------------------------------
$stmt = $pdo->query(
    'SELECT id, name, mgmt_ip, edge_type, is_active, created_at
       FROM edge_routers
      ORDER BY name'
);
$edges = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------
// Hent grossist-konfig per edge-router (for listevisning)
// ---------------------------------------------------------
$configCounts  = [];          // edge_id => antall grossister konfigurert
$configVendors = [];          // edge_id => [vendor_id, vendor_id, ...]
$totalVendors  = count($vendors);

if (!empty($edges) && $totalVendors > 0) {
    $edgeIds       = array_column($edges, 'id');
    $placeholders  = implode(',', array_fill(0, count($edgeIds), '?'));

    // Hent alle config-rader for disse edge-routerne
    $stmt = $pdo->prepare(
        "SELECT edge_router_id, vendor_id
           FROM edge_router_vendor_config
          WHERE edge_router_id IN ($placeholders)
            AND has_config = 1"
    );
    $stmt->execute($edgeIds);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $erId = (int)$r['edge_router_id'];
        $vid  = (int)$r['vendor_id'];

        if (!isset($configVendors[$erId])) {
            $configVendors[$erId] = [];
        }
        $configVendors[$erId][] = $vid;
    }

    foreach ($configVendors as $erId => $vIds) {
        $configCounts[$erId] = count($vIds);
    }
}

// ---------------------------------------------------------
// Hent data for redigering (inkl grossist-config)
// ---------------------------------------------------------
$editEdge         = null;
$editVendorConfig = []; // vendor_id => has_config(1/0)

if ($editId > 0) {
    foreach ($edges as $e) {
        if ((int)$e['id'] === $editId) {
            $editEdge = $e;
            break;
        }
    }

    if ($editEdge) {
        $stmt = $pdo->prepare(
            'SELECT vendor_id, has_config
               FROM edge_router_vendor_config
              WHERE edge_router_id = :er'
        );
        $stmt->execute([':er' => $editId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $editVendorConfig[(int)$r['vendor_id']] = (int)$r['has_config'];
        }
    }
}
?>

<div class="mb-3">
    <h1 class="h4 mb-1">Edge-rutere</h1>
    <p class="text-muted small mb-0">
        Register over Edge Routere i nettet. For hver edge-router kan du markere hvilke grossister den
        er konfigurert for. Dette brukes senere når vi skal generere konfigurasjon per kunde.
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
            <h2 class="h6 mb-0">Eksisterende edge-rutere</h2>

            <!-- Live-søk: filtrerer mens man skriver -->
            <div class="ms-auto" style="max-width:260px;">
                <input
                    type="text"
                    id="edgeSearch"
                    class="form-control form-control-sm"
                    placeholder="Søk i navn, IP, type, grossist..."
                >
            </div>
        </div>

        <?php if (empty($edges)): ?>
            <p class="text-muted small mb-0">Ingen edge-rutere er registrert ennå.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="edgeTable">
                    <thead>
                        <tr>
                            <th>Navn</th>
                            <th>Mgmt IP</th>
                            <th>Type</th>
                            <th>Grossist-konfig</th>
                            <th>Status</th>
                            <th>Opprettet</th>
                            <th style="width:1%; white-space:nowrap;">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($edges as $e): ?>
                        <?php
                        $id       = (int)$e['id'];
                        $cfgCount = $configCounts[$id] ?? 0;
                        $cfgVids  = $configVendors[$id] ?? [];
                        ?>
                        <tr class="edge-row">
                            <td><?php echo htmlspecialchars($e['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><code><?php echo htmlspecialchars($e['mgmt_ip'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars($e['edge_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if ($totalVendors > 0): ?>
                                    <span class="badge text-bg-secondary">
                                        <?php echo $cfgCount . ' / ' . $totalVendors; ?>
                                    </span>

                                    <?php if (!empty($cfgVids)): ?>
                                        <div class="mt-1 small">
                                            <?php foreach ($cfgVids as $vid): ?>
                                                <?php if (!isset($vendorNameById[$vid])) continue; ?>
                                                <span class="badge text-bg-success me-1 mb-1">
                                                    <?php echo htmlspecialchars($vendorNameById[$vid], ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">Ingen grossister definert</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($e['is_active'])): ?>
                                    <span class="badge text-bg-success">Aktiv</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inaktiv</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?php echo htmlspecialchars($e['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td class="text-nowrap">
                                <a
                                    href="/?page=edge_routers&edit_id=<?php echo $id; ?>#edit-edge-router"
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
        <h2 class="h6 mb-2">Legg til ny edge-router</h2>
        <p class="small text-muted mb-2">
            Edge-ruteren kobler aksess-laget mot Service Router. Grossist-konfig settes på
            redigeringssiden per edge-router.
        </p>

        <form method="post" class="row g-3" style="max-width: 520px;">
            <input type="hidden" name="action" value="add_edge_router">

            <div class="col-12">
                <label for="er_name" class="form-label form-label-sm">Navn</label>
                <input
                    type="text"
                    id="er_name"
                    name="name"
                    class="form-control form-control-sm"
                    required
                    value="<?php echo htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="F.eks. ER-Bergen-01"
                >
            </div>

            <div class="col-12">
                <label for="er_mgmt_ip" class="form-label form-label-sm">Management IP</label>
                <input
                    type="text"
                    id="er_mgmt_ip"
                    name="mgmt_ip"
                    class="form-control form-control-sm"
                    required
                    value="<?php echo htmlspecialchars($_POST['mgmt_ip'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="F.eks. 10.0.1.10"
                >
            </div>

            <div class="col-12">
                <label for="er_type" class="form-label form-label-sm">Type edge-router</label>
                <input
                    type="text"
                    id="er_type"
                    name="edge_type"
                    class="form-control form-control-sm"
                    required
                    value="<?php echo htmlspecialchars($_POST['edge_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="F.eks. Cisco ASR9k, Juniper MX"
                >
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> Legg til edge-router
                </button>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm mb-3" id="edit-edge-router">
    <div class="card-body">
        <h2 class="h6 mb-2">Rediger edge-router</h2>
        <p class="small text-muted mb-2">
            Velg ✏-ikonet i listen over for å laste inn en edge-router her.
            Her kan du også markere hvilke grossister den er konfigurert for.
        </p>

        <?php if ($editEdge): ?>
            <form method="post" class="row g-3" style="max-width: 640px;">
                <input type="hidden" name="action" value="update_edge_router">
                <input type="hidden" name="id" value="<?php echo (int)$editEdge['id']; ?>">

                <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm" for="edit_er_name">Navn</label>
                    <input
                        type="text"
                        id="edit_er_name"
                        name="name"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['name'] ?? $editEdge['name'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm" for="edit_er_mgmt_ip">Management IP</label>
                    <input
                        type="text"
                        id="edit_er_mgmt_ip"
                        name="mgmt_ip"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['mgmt_ip'] ?? $editEdge['mgmt_ip'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm" for="edit_er_type">Type edge-router</label>
                    <input
                        type="text"
                        id="edit_er_type"
                        name="edge_type"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['edge_type'] ?? $editEdge['edge_type'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="col-12 col-md-6">
                    <div class="form-check mt-4">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="edit_er_active"
                            name="is_active"
                            value="1"
                            <?php echo (!empty($_POST) ? !empty($_POST['is_active']) : !empty($editEdge['is_active'])) ? 'checked' : ''; ?>
                        >
                        <label class="form-check-label small" for="edit_er_active">
                            Edge-router er aktiv
                        </label>
                    </div>
                </div>

                <div class="col-12">
                    <hr class="my-2">
                    <h3 class="h6 mb-2">Grossist-konfigurasjon</h3>
                    <?php if (empty($vendors)): ?>
                        <p class="small text-muted mb-0">
                            Ingen grossister er definert ennå. Gå til Grossistaksess og legg til leverandører først.
                        </p>
                    <?php else: ?>
                        <p class="small text-muted mb-2">
                            Huk av hvilke grossister denne edge-routeren allerede er konfigurert for.
                            Dette gjør at systemet senere kan hoppe over grunnkonfig for disse grossistene.
                        </p>

                        <div class="row g-2">
                            <?php foreach ($vendors as $v): ?>
                                <?php
                                $vid       = (int)$v['id'];
                                $isChecked = (!empty($_POST))
                                    ? !empty($_POST['vendor_has_config'][$vid])
                                    : (!empty($editVendorConfig[$vid]));
                                ?>
                                <div class="col-12 col-md-6">
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            id="vendor_cfg_<?php echo $vid; ?>"
                                            name="vendor_has_config[<?php echo $vid; ?>]"
                                            value="1"
                                            <?php echo $isChecked ? 'checked' : ''; ?>
                                        >
                                        <label class="form-check-label small" for="vendor_cfg_<?php echo $vid; ?>">
                                            <?php echo htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-12 d-flex gap-2 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i> Lagre endringer
                    </button>
                    <a href="/?page=edge_routers" class="btn btn-outline-secondary btn-sm">
                        Avbryt
                    </a>
                </div>
            </form>
        <?php else: ?>
            <p class="small text-muted mb-0">
                Ingen edge-router valgt for redigering.
            </p>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('edgeSearch');
    var table = document.getElementById('edgeTable');
    if (!input || !table) return;

    var rows = table.querySelectorAll('tbody tr.edge-row');

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
