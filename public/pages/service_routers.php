<?php
// public/pages/service_routers.php

use App\Database;

// ---------------------------------------------------------
// Tilgang: admin OR network (fra user_roles). Ingen hardkoding.
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';

if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til administrasjon av service-rutere.
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
        Du har ikke tilgang til administrasjon av service-rutere.
    </div>
    <?php
    return;
}

$errors         = [];
$successMessage = null;

$editId       = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$pdo          = $pdo ?? Database::getConnection();
$showAddForm  = false; // styrer om "Legg til ny service-router"-boksen skal være synlig

// ---------------------------------------------------------
// Hent grossist-vendorer (brukes både i liste og redigering)
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

    // Legg til ny service-router (kun generell info)
    if ($action === 'add_service_router') {
        $srName       = trim($_POST['sr_name'] ?? '');
        $srIp         = trim($_POST['sr_ip'] ?? '');
        $locationName = trim($_POST['location_name'] ?? '');

        if ($srName === '') {
            $errors[] = 'Navn på service-router må fylles ut.';
        }
        if ($srIp === '') {
            $errors[] = 'IP-adresse på service-router må fylles ut.';
        } elseif (!filter_var($srIp, FILTER_VALIDATE_IP)) {
            $errors[] = 'Service-router IP er ikke gyldig.';
        }
        if ($locationName === '') {
            $errors[] = 'Lokasjon må fylles ut.';
        }

        // Vi har FK mot grossist_vendors.vendor_id, så vi må ha en gyldig vendor
        if (empty($vendors)) {
            $errors[] = 'Ingen grossist-leverandører er definert; legg til minst én før du legger til service-router.';
        }

        // Hvis det er feil: vis add-boksen
        if (!empty($errors)) {
            $showAddForm = true;
        }

        if (empty($errors)) {
            try {
                // Bruk første aktive vendor som "default vendor"
                $defaultVendorId = (int)$vendors[0]['id'];

                $stmt = $pdo->prepare(
                    'INSERT INTO grossist_service_routers (vendor_id, sr_name, sr_ip, location_name, is_active)
                     VALUES (:vendor_id, :sr_name, :sr_ip, :location_name, 1)'
                );
                $stmt->execute([
                    ':vendor_id'     => $defaultVendorId,
                    ':sr_name'       => $srName,
                    ':sr_ip'         => $srIp,
                    ':location_name' => $locationName,
                ]);

                $successMessage = 'Ny service-router ble lagt til.';
                $_POST          = [];
            } catch (\Throwable $e) {
                $errors[]    = 'Klarte ikke å lagre service-router i databasen.';
                $showAddForm = true;
                // Debug ved behov:
                // $errors[] = $e->getMessage();
            }
        }
    }

    // Oppdater eksisterende service-router + vendor-port-mapping
    if ($action === 'update_service_router') {
        $id           = (int)($_POST['id'] ?? 0);
        $srName       = trim($_POST['sr_name'] ?? '');
        $srIp         = trim($_POST['sr_ip'] ?? '');
        $locationName = trim($_POST['location_name'] ?? '');
        $isActive     = !empty($_POST['is_active']) ? 1 : 0;

        // vendor_ports[<vendor_id>] = 'xe-0/0/0', 'Gi0/0/1' etc
        $vendorPorts = $_POST['vendor_ports'] ?? [];

        if ($id <= 0) {
            $errors[] = 'Mangler ID for service-router.';
        }
        if ($srName === '') {
            $errors[] = 'Navn på service-router må fylles ut.';
        }
        if ($srIp === '') {
            $errors[] = 'IP-adresse på service-router må fylles ut.';
        } elseif (!filter_var($srIp, FILTER_VALIDATE_IP)) {
            $errors[] = 'Service-router IP er ikke gyldig.';
        }
        if ($locationName === '') {
            $errors[] = 'Lokasjon må fylles ut.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // 1) Oppdater generell info på service-router
                $stmt = $pdo->prepare(
                    'UPDATE grossist_service_routers
                        SET sr_name       = :sr_name,
                            sr_ip         = :sr_ip,
                            location_name = :location_name,
                            is_active     = :is_active
                      WHERE id = :id'
                );
                $stmt->execute([
                    ':sr_name'       => $srName,
                    ':sr_ip'         => $srIp,
                    ':location_name' => $locationName,
                    ':is_active'     => $isActive,
                    ':id'            => $id,
                ]);

                // 2) Oppdater koblingen mot grossister og porter
                // Slett eksisterende rader for denne service-routeren
                $del = $pdo->prepare(
                    'DELETE FROM grossist_service_router_vendor_ports
                      WHERE service_router_id = :sr'
                );
                $del->execute([':sr' => $id]);

                // Sett inn rader for de vendorene som har port navngitt
                if (!empty($vendors)) {
                    $ins = $pdo->prepare(
                        'INSERT INTO grossist_service_router_vendor_ports (service_router_id, vendor_id, port_name, created_at)
                         VALUES (:sr, :vid, :port_name, NOW())'
                    );

                    foreach ($vendors as $v) {
                        $vid  = (int)$v['id'];
                        $port = trim($vendorPorts[$vid] ?? '');

                        if ($port !== '') {
                            $ins->execute([
                                ':sr'        => $id,
                                ':vid'       => $vid,
                                ':port_name' => $port,
                            ]);
                        }
                    }
                }

                $pdo->commit();

                $successMessage = 'Service-router og portmapping mot grossister ble oppdatert.';
                $editId         = $id;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Klarte ikke å oppdatere service-router/grossist-porter i databasen.';
                // Debug ved behov:
                // $errors[] = $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------
// Hent alle service-rutere
// ---------------------------------------------------------
$stmt = $pdo->query(
    'SELECT id, vendor_id, sr_name, sr_ip, location_name, is_active, created_at
       FROM grossist_service_routers
      ORDER BY sr_name'
);
$serviceRouters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------
// Hent portmapping per service-router (for listevisning)
// ---------------------------------------------------------
$portsByServiceRouter = []; // sr_id => [vendor_id => port_name]

if (!empty($serviceRouters)) {
    $srIds = array_column($serviceRouters, 'id');
    $placeholders = implode(',', array_fill(0, count($srIds), '?'));

    $stmt = $pdo->prepare(
        "SELECT service_router_id, vendor_id, port_name
           FROM grossist_service_router_vendor_ports
          WHERE service_router_id IN ($placeholders)"
    );
    $stmt->execute($srIds);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $srId = (int)$r['service_router_id'];
        $vid  = (int)$r['vendor_id'];

        if (!isset($portsByServiceRouter[$srId])) {
            $portsByServiceRouter[$srId] = [];
        }
        $portsByServiceRouter[$srId][$vid] = $r['port_name'];
    }
}

// ---------------------------------------------------------
// Hent data for redigering (inkl vendor-portmapping)
// ---------------------------------------------------------
$editRouter      = null;
$editVendorPorts = []; // vendor_id => port_name

if ($editId > 0 && !empty($serviceRouters)) {
    foreach ($serviceRouters as $sr) {
        if ((int)$sr['id'] === $editId) {
            $editRouter = $sr;
            break;
        }
    }

    if ($editRouter) {
        $stmt = $pdo->prepare(
            'SELECT vendor_id, port_name
               FROM grossist_service_router_vendor_ports
              WHERE service_router_id = :sr'
        );
        $stmt->execute([':sr' => $editId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $editVendorPorts[(int)$r['vendor_id']] = $r['port_name'];
        }
    }
}
?>

<div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">Service-rutere</h1>
        <p class="text-muted small mb-0">
            Register over Service Routere i nettet. Selve service-routerne er generelle (navn, IP, lokasjon),
            mens porter mot hver grossist konfigureres per service-router.
        </p>
    </div>

    <!-- Knapp for å vise/skjule "Legg til ny"-boksen -->
    <button type="button" class="btn btn-sm btn-primary" id="toggleAddSr">
        <i class="bi bi-plus-circle me-1"></i> Legg til ny Service Router
    </button>
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
            <h2 class="h6 mb-0">Eksisterende service-rutere</h2>

            <!-- Live-søk: filtrerer mens man skriver -->
            <div class="ms-auto" style="max-width:260px;">
                <input
                    type="text"
                    id="srSearch"
                    class="form-control form-control-sm"
                    placeholder="Søk i navn, IP, lokasjon, grossist..."
                >
            </div>
        </div>

        <?php if (empty($serviceRouters)): ?>
            <p class="text-muted small mb-0">Ingen service-rutere er registrert ennå.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="srTable">
                    <thead>
                        <tr>
                            <th>Navn</th>
                            <th>SR IP</th>
                            <th>Lokasjon</th>
                            <th>Grossist-porter</th>
                            <th>Status</th>
                            <th>Opprettet</th>
                            <th style="width:1%; white-space:nowrap;">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($serviceRouters as $sr): ?>
                        <?php
                        $id      = (int)$sr['id'];
                        $ports   = $portsByServiceRouter[$id] ?? [];
                        ?>
                        <tr class="sr-row">
                            <td><?php echo htmlspecialchars($sr['sr_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><code><?php echo htmlspecialchars($sr['sr_ip'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars($sr['location_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if (!empty($ports)): ?>
                                    <div class="small">
                                        <?php foreach ($ports as $vid => $portName): ?>
                                            <?php if (!isset($vendorNameById[$vid])) continue; ?>
                                            <span class="badge text-bg-success me-1 mb-1">
                                                <?php
                                                echo htmlspecialchars($vendorNameById[$vid], ENT_QUOTES, 'UTF-8')
                                                    . ': '
                                                    . htmlspecialchars($portName, ENT_QUOTES, 'UTF-8');
                                                ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">Ingen porter definert</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($sr['is_active'])): ?>
                                    <span class="badge text-bg-success">Aktiv</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inaktiv</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?php echo htmlspecialchars($sr['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td class="text-nowrap">
                                <a
                                    href="/?page=service_routers&edit_id=<?php echo $id; ?>#edit-service-router"
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

<section class="card shadow-sm mb-3 <?php echo $showAddForm ? '' : 'd-none'; ?>" id="add-service-router-card">
    <div class="card-body">
        <h2 class="h6 mb-2">Legg til ny service-router</h2>
        <p class="small text-muted mb-2">
            Legg inn en ny Service Router. Porter mot grossister konfigureres på redigeringssiden.
        </p>

        <form method="post" class="row g-3" style="max-width: 520px;">
            <input type="hidden" name="action" value="add_service_router">

            <div class="col-12">
                <label for="sr_name" class="form-label form-label-sm">Navn</label>
                <input
                    type="text"
                    id="sr_name"
                    name="sr_name"
                    class="form-control form-control-sm"
                    required
                    value="<?php echo htmlspecialchars($_POST['sr_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="F.eks. SR-Oslo-01"
                >
            </div>

            <div class="col-12">
                <label for="sr_ip" class="form-label form-label-sm">Service-router IP</label>
                <input
                    type="text"
                    id="sr_ip"
                    name="sr_ip"
                    class="form-control form-control-sm"
                    required
                    value="<?php echo htmlspecialchars($_POST['sr_ip'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="F.eks. 10.10.0.1"
                >
            </div>

            <div class="col-12">
                <label for="sr_location" class="form-label form-label-sm">Lokasjon</label>
                <input
                    type="text"
                    id="sr_location"
                    name="location_name"
                    class="form-control form-control-sm"
                    required
                    value="<?php echo htmlspecialchars($_POST['location_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="F.eks. Oslo DC1"
                >
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> Legg til service-router
                </button>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm mb-3" id="edit-service-router">
    <div class="card-body">
        <h2 class="h6 mb-2">Rediger service-router</h2>
        <p class="small text-muted mb-2">
            Velg ✏-ikonet i listen over for å laste inn en service-router her.
            Under finner du portmapping per grossist.
        </p>

        <?php if ($editRouter): ?>
            <form method="post" class="row g-3" style="max-width: 760px;">
                <input type="hidden" name="action" value="update_service_router">
                <input type="hidden" name="id" value="<?php echo (int)$editRouter['id']; ?>">

                <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm" for="edit_sr_name">Navn</label>
                    <input
                        type="text"
                        id="edit_sr_name"
                        name="sr_name"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['sr_name'] ?? $editRouter['sr_name'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm" for="edit_sr_ip">Service-router IP</label>
                    <input
                        type="text"
                        id="edit_sr_ip"
                        name="sr_ip"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['sr_ip'] ?? $editRouter['sr_ip'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm" for="edit_sr_location">Lokasjon</label>
                    <input
                        type="text"
                        id="edit_sr_location"
                        name="location_name"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['location_name'] ?? $editRouter['location_name'], ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="col-12 col-md-6">
                    <div class="form-check mt-4">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="edit_sr_active"
                            name="is_active"
                            value="1"
                            <?php
                            $checked = !empty($_POST)
                                ? !empty($_POST['is_active'])
                                : !empty($editRouter['is_active']);
                            echo $checked ? 'checked' : '';
                            ?>
                        >
                        <label class="form-check-label small" for="edit_sr_active">
                            Service-router er aktiv
                        </label>
                    </div>
                </div>

                <div class="col-12">
                    <hr class="my-2">
                    <h3 class="h6 mb-2">Portmapping per grossist</h3>
                    <?php if (empty($vendors)): ?>
                        <p class="small text-muted mb-0">
                            Ingen grossister er definert ennå. Gå til Grossistaksess og legg til leverandører først.
                        </p>
                    <?php else: ?>
                        <p class="small text-muted mb-2">
                            For hver grossist kan du angi hvilken port på denne service-routeren den er koblet til
                            (f.eks. <code>xe-0/0/0</code>, <code>Gi0/0/1</code> osv.). Tomme felt betyr at grossisten
                            ikke er mappet mot denne service-routeren.
                        </p>

                        <div class="row g-2">
                            <?php foreach ($vendors as $v): ?>
                                <?php
                                $vid      = (int)$v['id'];
                                $portVal  = !empty($_POST)
                                    ? trim($_POST['vendor_ports'][$vid] ?? '')
                                    : ($editVendorPorts[$vid] ?? '');
                                ?>
                                <div class="col-12 col-md-6">
                                    <label class="form-label form-label-sm">
                                        <?php echo htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control form-control-sm"
                                        name="vendor_ports[<?php echo $vid; ?>]"
                                        value="<?php echo htmlspecialchars($portVal, ENT_QUOTES, 'UTF-8'); ?>"
                                        placeholder="Portnavn, f.eks. xe-0/0/0"
                                    >
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-12 d-flex gap-2 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i> Lagre endringer
                    </button>
                    <a href="/?page=service_routers" class="btn btn-outline-secondary btn-sm">
                        Avbryt
                    </a>
                </div>
            </form>
        <?php else: ?>
            <p class="small text-muted mb-0">
                Ingen service-router valgt for redigering.
            </p>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Søkefilter
    var input = document.getElementById('srSearch');
    var table = document.getElementById('srTable');
    if (input && table) {
        var rows = table.querySelectorAll('tbody tr.sr-row');

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
    }

    // Toggle "Legg til ny service-router"-kortet
    var toggleBtn = document.getElementById('toggleAddSr');
    var addCard   = document.getElementById('add-service-router-card');

    if (toggleBtn && addCard) {
        toggleBtn.addEventListener('click', function () {
            var isHidden = addCard.classList.contains('d-none');
            addCard.classList.toggle('d-none');

            if (isHidden) {
                addCard.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        });
    }
});
</script>
