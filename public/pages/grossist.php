<?php
// public/pages/grossist.php

use App\Database;

// ---------------------------------------------------------
// Tilgang: admin OR network (fra user_roles). Ingen hardkoding.
// NB: Dere har IKKE users.is_admin. Kun user_roles.
// Robust: trim + case-insensitivt username-oppslag.
// ---------------------------------------------------------
$username = trim((string)($_SESSION['username'] ?? ''));

if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til grossistaksess.
    </div>
    <?php
    return;
}

try {
    $pdo = Database::getConnection();

    // Finn current user_id (case-insensitiv match på username)
    $stmt = $pdo->prepare('
        SELECT id
          FROM users
         WHERE LOWER(TRIM(username)) = LOWER(TRIM(:u))
         LIMIT 1
    ');
    $stmt->execute([':u' => $username]);
    $currentUserId = (int)($stmt->fetchColumn() ?: 0);

    if ($currentUserId <= 0) {
        http_response_code(403);
        ?>
        <div class="alert alert-danger mt-3">
            Du har ikke tilgang til grossistaksess.
        </div>
        <?php
        return;
    }

    // Rolle-sjekk: admin eller network/nettverk
    $stmt = $pdo->prepare("
        SELECT 1
          FROM user_roles
         WHERE user_id = :uid
           AND LOWER(TRIM(role)) IN ('admin','network','nettverk')
         LIMIT 1
    ");
    $stmt->execute([':uid' => $currentUserId]);
    $hasAccess = (bool)$stmt->fetchColumn();

    if (!$hasAccess) {
        http_response_code(403);
        ?>
        <div class="alert alert-danger mt-3">
            Du har ikke tilgang til grossistaksess.
        </div>
        <?php
        return;
    }

} catch (\Throwable $e) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til grossistaksess.
    </div>
    <?php
    return;
}

$vendorSlug   = $_GET['vendor'] ?? '';
$editVpId     = isset($_GET['edit_vp_id']) ? (int)$_GET['edit_vp_id'] : 0; // rediger port-mapping

$errors         = [];
$successMessage = null;

$pdo     = null;
$vendors = [];
$allServiceRouters = [];
$vendorPorts       = [];

// ---------------------------------------------------------
// DB-tilkobling
// ---------------------------------------------------------
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    $pdo = null;
    $errors[] = 'Kunne ikke koble til databasen for grossist-innstillinger.';
}

// ---------------------------------------------------------
// Hjelpefunksjon: lag slug fra tekst
// ---------------------------------------------------------
/**
 * @param string $str
 * @return string
 */
function make_slug(string $str): string
{
    $slug = mb_strtolower(trim($str), 'UTF-8');

    // Prøv å translitterere til ASCII hvis mulig
    if (function_exists('iconv')) {
        $trans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if ($trans !== false) {
            $slug = $trans;
        }
    }

    // Erstatt mellomrom med _
    $slug = preg_replace('~\s+~', '_', $slug);

    // Fjern alt som ikke er a-z0-9_
    $slug = preg_replace('~[^a-z0-9_]+~', '', $slug);

    // Trim _ fra start/slutt
    $slug = trim($slug, '_');

    if ($slug === '') {
        $slug = 'vendor_' . time();
    }

    return $slug;
}

// ---------------------------------------------------------
// POST-håndtering
// ---------------------------------------------------------
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1) Legg til ny tjenestetilbyder (grossist_vendors)
    if ($action === 'add_vendor') {
        $nameInput = trim($_POST['name'] ?? '');
        $slugInput = trim($_POST['slug'] ?? '');

        if ($nameInput === '') {
            $errors[] = 'Navn på tjenestetilbyder må fylles ut.';
        }

        if (empty($errors)) {
            $base = $slugInput !== '' ? $slugInput : $nameInput;
            $slug = make_slug($base);

            // Finn neste sort_order
            $sortOrder = 10;
            try {
                $stmt = $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 AS next_order FROM grossist_vendors');
                $sortOrder = (int)($stmt->fetchColumn() ?: 10);
            } catch (\Throwable $e) {
                $sortOrder = 10;
            }

            try {
                // Sørg for unik slug
                $uniqueSlug = $slug;
                $suffix     = 2;

                $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM grossist_vendors WHERE slug = :slug');

                while (true) {
                    $checkStmt->execute([':slug' => $uniqueSlug]);
                    $count = (int)$checkStmt->fetchColumn();

                    if ($count === 0) {
                        break;
                    }

                    $uniqueSlug = $slug . '_' . $suffix;
                    $suffix++;
                }

                $slug = $uniqueSlug;

                // Sett inn
                $ins = $pdo->prepare(
                    'INSERT INTO grossist_vendors (slug, name, is_active, sort_order)
                     VALUES (:slug, :name, 1, :sort_order)'
                );
                $ins->execute([
                    ':slug'       => $slug,
                    ':name'       => $nameInput,
                    ':sort_order' => $sortOrder,
                ]);

                $successMessage = 'Ny tjenestetilbyder ble lagt til.';
                $_POST['name']  = '';
                $_POST['slug']  = '';

            } catch (\Throwable $e) {
                $errors[] = 'Klarte ikke å lagre ny tjenestetilbyder i databasen.';
            }
        }
    }

    // 2) Legg til portmapping for vendor -> service-router
    if ($action === 'add_vendor_port') {
        $slugParam = trim($_POST['vendor_slug'] ?? $vendorSlug);
        $srId      = (int)($_POST['service_router_id'] ?? 0);
        $portName  = trim($_POST['port_name'] ?? '');

        if ($slugParam === '') {
            $errors[] = 'Mangler hvilken tjenestetilbyder portmappingen skal knyttes til.';
        }
        if ($srId <= 0) {
            $errors[] = 'Du må velge service-router.';
        }
        if ($portName === '') {
            $errors[] = 'Portnavn må fylles ut.';
        }

        if (empty($errors)) {
            try {
                // Slå opp vendor_id fra slug
                $stmt = $pdo->prepare('SELECT id FROM grossist_vendors WHERE slug = :slug AND is_active = 1');
                $stmt->execute([':slug' => $slugParam]);
                $vendorId = (int)$stmt->fetchColumn();

                if ($vendorId <= 0) {
                    $errors[] = 'Fant ikke valgt tjenestetilbyder i databasen.';
                } else {
                    // Sjekk at service-router finnes
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM grossist_service_routers WHERE id = :id');
                    $stmt->execute([':id' => $srId]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $errors[] = 'Fant ikke valgt service-router.';
                    }
                }

                // Evt. sjekk for duplikat mapping
                if (empty($errors)) {
                    $dup = $pdo->prepare(
                        'SELECT COUNT(*)
                           FROM grossist_service_router_vendor_ports
                          WHERE vendor_id = :vid
                            AND service_router_id = :sr'
                    );
                    $dup->execute([
                        ':vid' => $vendorId,
                        ':sr'  => $srId,
                    ]);
                    if ((int)$dup->fetchColumn() > 0) {
                        $errors[] = 'Denne tjenestetilbyderen er allerede mappet mot den valgte service-routeren.';
                    }
                }

                if (empty($errors)) {
                    $ins = $pdo->prepare(
                        'INSERT INTO grossist_service_router_vendor_ports (service_router_id, vendor_id, port_name, created_at)
                         VALUES (:sr, :vid, :port_name, NOW())'
                    );
                    $ins->execute([
                        ':sr'        => $srId,
                        ':vid'       => $vendorId,
                        ':port_name' => $portName,
                    ]);

                    $successMessage = 'Portmapping mot service-router ble lagt til.';
                    $_POST['service_router_id'] = '';
                    $_POST['port_name']         = '';
                }
            } catch (\Throwable $e) {
                $errors[] = 'Klarte ikke å lagre portmapping i databasen.';
            }
        }

        if ($slugParam !== '') {
            $vendorSlug = $slugParam;
        }
    }

    // 3) Oppdater portmapping for vendor -> service-router
    if ($action === 'update_vendor_port') {
        $slugParam = trim($_POST['vendor_slug'] ?? $vendorSlug);
        $vpId      = (int)($_POST['vp_id'] ?? 0);
        $srId      = (int)($_POST['service_router_id'] ?? 0);
        $portName  = trim($_POST['port_name'] ?? '');

        if ($vpId <= 0) {
            $errors[] = 'Mangler ID for portmapping som skal oppdateres.';
        }
        if ($slugParam === '') {
            $errors[] = 'Mangler hvilken tjenestetilbyder portmappingen tilhører.';
        }
        if ($srId <= 0) {
            $errors[] = 'Du må velge service-router.';
        }
        if ($portName === '') {
            $errors[] = 'Portnavn må fylles ut.';
        }

        $vendorId = 0;

        if (empty($errors)) {
            try {
                // Finn vendor_id via slug og verifiser at mappingen tilhører denne vendor
                $stmt = $pdo->prepare(
                    'SELECT vp.vendor_id
                       FROM grossist_service_router_vendor_ports vp
                       JOIN grossist_vendors v ON v.id = vp.vendor_id
                      WHERE vp.id = :vp_id
                        AND v.slug = :slug'
                );
                $stmt->execute([
                    ':vp_id' => $vpId,
                    ':slug'  => $slugParam,
                ]);
                $vendorId = (int)$stmt->fetchColumn();

                if ($vendorId <= 0) {
                    $errors[] = 'Fant ikke portmapping for valgt tjenestetilbyder.';
                } else {
                    // Sjekk at service-router finnes
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM grossist_service_routers WHERE id = :id');
                    $stmt->execute([':id' => $srId]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $errors[] = 'Fant ikke valgt service-router.';
                    }
                }

                if (empty($errors)) {
                    $upd = $pdo->prepare(
                        'UPDATE grossist_service_router_vendor_ports
                            SET service_router_id = :sr,
                                port_name        = :port_name
                          WHERE id = :vp_id'
                    );
                    $upd->execute([
                        ':sr'        => $srId,
                        ':port_name' => $portName,
                        ':vp_id'     => $vpId,
                    ]);

                    $successMessage = 'Portmapping ble oppdatert.';
                    $editVpId       = $vpId;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Klarte ikke å oppdatere portmapping i databasen.';
            }
        }

        if ($slugParam !== '') {
            $vendorSlug = $slugParam;
        }
    }

    // 4) Slett portmapping
    if ($action === 'delete_vendor_port') {
        $slugParam = trim($_POST['vendor_slug'] ?? $vendorSlug);
        $vpId      = (int)($_POST['vp_id'] ?? 0);

        if ($vpId <= 0) {
            $errors[] = 'Mangler ID for portmapping som skal slettes.';
        }
        if ($slugParam === '') {
            $errors[] = 'Mangler hvilken tjenestetilbyder portmappingen tilhører.';
        }

        if (empty($errors)) {
            try {
                $del = $pdo->prepare(
                    'DELETE vp
                       FROM grossist_service_router_vendor_ports vp
                       JOIN grossist_vendors v ON v.id = vp.vendor_id
                      WHERE vp.id = :vp_id
                        AND v.slug = :slug'
                );
                $del->execute([
                    ':vp_id' => $vpId,
                    ':slug'  => $slugParam,
                ]);

                if ($del->rowCount() > 0) {
                    $successMessage = 'Portmapping ble slettet.';
                } else {
                    $errors[] = 'Fant ikke portmapping å slette for valgt tjenestetilbyder.';
                }
            } catch (\Throwable $e) {
                $errors[] = 'Klarte ikke å slette portmapping i databasen.';
            }
        }

        if ($slugParam !== '') {
            $vendorSlug = $slugParam;
        }

        // Etter slett vil vi ikke ha en aktiv edit-id
        if (!empty($editVpId) && empty($errors)) {
            $editVpId = 0;
        }
    }
}

// ---------------------------------------------------------
// Hent leverandører dynamisk (aktive)
// ---------------------------------------------------------
if ($pdo) {
    try {
        $stmt = $pdo->query(
            'SELECT id, slug, name
               FROM grossist_vendors
              WHERE is_active = 1
              ORDER BY sort_order, name'
        );
        $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // Fallback: faste leverandører hvis tabell mangler
        if (empty($vendors)) {
            $vendors = [
                ['id' => 0, 'slug' => 'eviny',         'name' => 'Eviny'],
                ['id' => 0, 'slug' => 'telia',         'name' => 'Telia'],
                ['id' => 0, 'slug' => 'globalconnect', 'name' => 'Global Connect'],
                ['id' => 0, 'slug' => 'hkf_iot',       'name' => 'HKF IoT'],
            ];
        }
    }
} else {
    // Ingen DB -> fallback
    if (empty($vendors)) {
        $vendors = [
            ['id' => 0, 'slug' => 'eviny',         'name' => 'Eviny'],
            ['id' => 0, 'slug' => 'telia',         'name' => 'Telia'],
            ['id' => 0, 'slug' => 'globalconnect', 'name' => 'Global Connect'],
            ['id' => 0, 'slug' => 'hkf_iot',       'name' => 'HKF IoT'],
        ];
    }
}

// Lag map slug => vendor-data for enkel lookup
$vendorMap = [];
foreach ($vendors as $v) {
    $vendorMap[$v['slug']] = $v;
}

// Bestem tittel / gjeldende leverandør
$currentVendorId   = 0;
$currentVendorName = 'Oversikt';

if ($vendorSlug && isset($vendorMap[$vendorSlug])) {
    $currentVendorId   = (int)$vendorMap[$vendorSlug]['id'];
    $currentVendorName = $vendorMap[$vendorSlug]['name'];
} else {
    // Ukjent eller tom vendor -> oversikt
    $vendorSlug        = '';
    $currentVendorId   = 0;
    $currentVendorName = 'Oversikt';
}

// ---------------------------------------------------------
// Hent globale Service Routers og portmapping for valgt leverandør
// ---------------------------------------------------------
$editVpSrId      = 0;
$editVpPortName  = '';

if ($pdo) {
    // Globalt register over service-rutere (generelle)
    try {
        $stmt = $pdo->query(
            'SELECT id, sr_name, sr_ip, location_name, is_active, created_at
               FROM grossist_service_routers
              ORDER BY sr_name'
        );
        $allServiceRouters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $allServiceRouters = [];
        $errors[] = 'Klarte ikke å hente liste over Service Routers.';
    }

    // Portmapping for valgt vendor
    if ($currentVendorId > 0) {
        try {
            $stmt = $pdo->prepare(
                'SELECT vp.id,
                        vp.port_name,
                        vp.service_router_id,
                        sr.sr_name,
                        sr.sr_ip,
                        sr.location_name,
                        sr.is_active,
                        sr.created_at
                   FROM grossist_service_router_vendor_ports vp
                   JOIN grossist_service_routers sr ON sr.id = vp.service_router_id
                  WHERE vp.vendor_id = :vid
                  ORDER BY sr.sr_name'
            );
            $stmt->execute([':vid' => $currentVendorId]);
            $vendorPorts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $vendorPorts = [];
            $errors[] = 'Klarte ikke å hente portmapping for denne tjenestetilbyderen.';
        }

        // Verdier til redigeringsskjema for portmapping
        if ($editVpId > 0) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT vp.service_router_id, vp.port_name
                       FROM grossist_service_router_vendor_ports vp
                       JOIN grossist_vendors v ON v.id = vp.vendor_id
                      WHERE vp.id = :vp_id
                        AND v.id = :vid'
                );
                $stmt->execute([
                    ':vp_id' => $editVpId,
                    ':vid'   => $currentVendorId,
                ]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $editVpSrId     = (int)$row['service_router_id'];
                    $editVpPortName = $row['port_name'];
                } else {
                    $editVpId = 0;
                }
            } catch (\Throwable $e) {
                $editVpId = 0;
            }
        }
    }
}
?>

<div class="mb-3">
    <h1 class="h4 mb-1">Grossistaksess – <?php echo htmlspecialchars($currentVendorName, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small mb-0">
        Her kan du administrere aksess mot ulike grossister og tjenestetilbydere.
        Service-rutere registreres globalt, og her mapper du hver leverandør mot en port på valgt service-router.
    </p>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger small">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success small">
        <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($vendorSlug === ''): ?>

    <!-- OVERSIKT + mulighet for å legge til nye tjenestetilbydere -->
    <section class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5 mb-2">Oversikt</h2>
            <p class="small text-muted">
                Sammendragsvisning for alle grossister / tjenestetilbydere. Klikk på en leverandør for å konfigurere
                hvilke service-rutere og porter den bruker. Nederst kan du legge til nye tjenestetilbydere i systemet.
            </p>

            <?php if (empty($vendors)): ?>
                <p class="text-muted small mb-3">
                    Ingen grossist-leverandører er definert ennå.
                </p>
            <?php else: ?>
                <div class="row g-3 mb-3">
                    <?php foreach ($vendors as $v): ?>
                        <div class="col-md-3 col-sm-6">
                            <a href="/?page=grossist&vendor=<?php echo urlencode($v['slug']); ?>"
                               class="text-decoration-none">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h3 class="h6 mb-1">
                                            <?php echo htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </h3>
                                        <p class="small text-muted mb-0">
                                            Klikk for å se og administrere portmapping mot service-rutere.
                                        </p>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <hr>

            <h3 class="h6 mb-2">Legg til ny tjenestetilbyder</h3>
            <p class="small text-muted mb-2">
                Registrer nye grossister / tjenestetilbydere som skal kunne brukes i systemet.
                Navnet vises i menyer og oversikter. Slug brukes som teknisk nøkkel i URL og integrasjoner.
            </p>

            <form method="post" class="row g-3" style="max-width: 520px;">
                <input type="hidden" name="action" value="add_vendor">

                <div class="col-12">
                    <label for="vendor_name" class="form-label form-label-sm">Navn på tjenestetilbyder</label>
                    <input
                        type="text"
                        id="vendor_name"
                        name="name"
                        class="form-control form-control-sm"
                        required
                        value="<?php echo htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="F.eks. Eviny, Telia, Global Connect, HKF IoT"
                    >
                </div>

                <div class="col-12">
                    <label for="vendor_slug" class="form-label form-label-sm">
                        Slug (valgfritt)
                    </label>
                    <input
                        type="text"
                        id="vendor_slug"
                        name="slug"
                        class="form-control form-control-sm"
                        value="<?php echo htmlspecialchars($_POST['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="F.eks. eviny, telia, globalconnect (la stå tomt for automatisk)"
                    >
                    <div class="form-text small">
                        Hvis du lar dette stå tomt, genereres en teknisk nøkkel automatisk fra navnet.
                    </div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle me-1"></i> Legg til tjenestetilbyder
                    </button>
                </div>
            </form>
        </div>
    </section>

<?php else: ?>

    <!-- DETALJVISNING FOR ÉN LEVERANDØR -->
    <section class="card shadow-sm mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="h5 mb-1">
                    <?php echo htmlspecialchars($currentVendorName, ENT_QUOTES, 'UTF-8'); ?>
                </h2>
                <p class="small text-muted mb-0">
                    Konfigurer hvilke service-rutere denne tjenestetilbyderen bruker, og hvilken port på hver router.
                </p>
            </div>
            <div>
                <a href="/?page=service_routers" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-diagram-3 me-1"></i> Administrer service-rutere
                </a>
            </div>
        </div>
    </section>

    <!-- Liste over portmappinger + redigering -->
    <section class="card shadow-sm mb-3">
        <div class="card-body">
            <h3 class="h6 mb-2">
                Portmapping mot Service Routere for
                <?php echo htmlspecialchars($currentVendorName, ENT_QUOTES, 'UTF-8'); ?>
            </h3>

            <?php if (empty($vendorPorts)): ?>
                <p class="text-muted small mb-2">
                    Ingen portmappinger er registrert for denne tjenestetilbyderen ennå.
                </p>
            <?php else: ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Service-router</th>
                                <th>IP</th>
                                <th>Lokasjon</th>
                                <th>Portnavn</th>
                                <th>Status</th>
                                <th>Opprettet</th>
                                <th style="width:1%; white-space:nowrap;">Handlinger</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($vendorPorts as $vp): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($vp['sr_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><code><?php echo htmlspecialchars($vp['sr_ip'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><?php echo htmlspecialchars($vp['location_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><code><?php echo htmlspecialchars($vp['port_name'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td>
                                    <?php if (!empty($vp['is_active'])): ?>
                                        <span class="badge text-bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted">
                                    <?php echo htmlspecialchars($vp['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td class="text-nowrap">
                                    <a
                                        href="/?page=grossist&vendor=<?php echo urlencode($vendorSlug); ?>&edit_vp_id=<?php echo (int)$vp['id']; ?>#vp-edit"
                                        class="btn btn-xs btn-outline-secondary mb-1"
                                    >
                                        <i class="bi bi-pencil-square me-1"></i> Rediger
                                    </a>

                                    <form
                                        method="post"
                                        class="d-inline"
                                        onsubmit="return confirm('Er du sikker på at du vil slette denne portmappingen?');"
                                    >
                                        <input type="hidden" name="action" value="delete_vendor_port">
                                        <input type="hidden" name="vendor_slug" value="<?php echo htmlspecialchars($vendorSlug, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="vp_id" value="<?php echo (int)$vp['id']; ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <hr>

            <!-- Legg til ny portmapping -->
            <h4 class="h6 mb-2">Legg til portmapping mot Service Router</h4>
            <p class="small text-muted mb-2">
                Velg en eksisterende Service Router og oppgi hvilken port denne leverandøren er terminert på.
            </p>

            <?php if (empty($allServiceRouters)): ?>
                <p class="small text-muted">
                    Det finnes ingen registrerte service-rutere ennå. Gå til
                    <a href="/?page=service_routers">Service-rutere</a> for å legge dem inn først.
                </p>
            <?php else: ?>
                <form method="post" class="row g-3 mb-4" style="max-width: 520px;">
                    <input type="hidden" name="action" value="add_vendor_port">
                    <input type="hidden" name="vendor_slug" value="<?php echo htmlspecialchars($vendorSlug, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="col-12">
                        <label for="vp_sr" class="form-label form-label-sm">Service-router</label>
                        <select
                            id="vp_sr"
                            name="service_router_id"
                            class="form-select form-select-sm"
                            required
                        >
                            <option value="">Velg service-router...</option>
                            <?php
                            $postedSrId = (int)($_POST['service_router_id'] ?? 0);
                            foreach ($allServiceRouters as $sr):
                                $srId = (int)$sr['id'];
                                $label = $sr['sr_name'] . ' (' . $sr['sr_ip'] . ', ' . $sr['location_name'] . ')';
                            ?>
                                <option
                                    value="<?php echo $srId; ?>"
                                    <?php echo $postedSrId === $srId ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label for="vp_port_name" class="form-label form-label-sm">Portnavn</label>
                        <input
                            type="text"
                            id="vp_port_name"
                            name="port_name"
                            class="form-control form-control-sm"
                            required
                            value="<?php echo htmlspecialchars($_POST['port_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="F.eks. xe-0/0/0, Gi0/0/1"
                        >
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus-circle me-1"></i> Legg til portmapping
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <!-- Rediger eksisterende portmapping -->
            <h4 id="vp-edit" class="h6 mb-2">Rediger portmapping</h4>
            <p class="small text-muted mb-2">
                Velg «Rediger» i tabellen over for å laste inn en portmapping her.
            </p>

            <?php if ($editVpId > 0 && $editVpPortName !== ''): ?>
                <form method="post" class="row g-3" style="max-width: 520px;">
                    <input type="hidden" name="action" value="update_vendor_port">
                    <input type="hidden" name="vp_id" value="<?php echo (int)$editVpId; ?>">
                    <input type="hidden" name="vendor_slug" value="<?php echo htmlspecialchars($vendorSlug, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="col-12">
                        <label for="vp_edit_sr" class="form-label form-label-sm">Service-router</label>
                        <select
                            id="vp_edit_sr"
                            name="service_router_id"
                            class="form-select form-select-sm"
                            required
                        >
                            <option value="">Velg service-router...</option>
                            <?php
                            $postedEditSrId = !empty($_POST) ? (int)($_POST['service_router_id'] ?? 0) : $editVpSrId;
                            foreach ($allServiceRouters as $sr):
                                $srId = (int)$sr['id'];
                                $label = $sr['sr_name'] . ' (' . $sr['sr_ip'] . ', ' . $sr['location_name'] . ')';
                            ?>
                                <option
                                    value="<?php echo $srId; ?>"
                                    <?php echo $postedEditSrId === $srId ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label for="vp_edit_port" class="form-label form-label-sm">Portnavn</label>
                        <input
                            type="text"
                            id="vp_edit_port"
                            name="port_name"
                            class="form-control form-control-sm"
                            required
                            value="<?php echo htmlspecialchars($_POST['port_name'] ?? $editVpPortName, ENT_QUOTES, 'UTF-8'); ?>"
                        >
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-save me-1"></i> Lagre endringer
                        </button>
                        <a
                            href="/?page=grossist&vendor=<?php echo urlencode($vendorSlug); ?>"
                            class="btn btn-outline-secondary btn-sm"
                        >
                            Avbryt
                        </a>
                    </div>
                </form>
            <?php else: ?>
                <p class="small text-muted mb-0">
                    Ingen portmapping valgt for redigering ennå.
                </p>
            <?php endif; ?>
        </div>
    </section>

<?php endif; ?>
