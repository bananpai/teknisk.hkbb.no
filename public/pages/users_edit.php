<?php
// Path: /public/pages/users_edit.php
//
// Rettigheter (OPPDATERT):
// - Admin skal ALLTID ha tilgang til brukeradministrasjon.
// - Admin avgjøres av:
//   A) $_SESSION['is_admin'] == true, eller
//   B) session-grupper/roller inneholder "admin", eller
//   C) DB: user_roles.role='admin' for innlogget bruker
//
// - Støtter session-nøkler: permissions/roles/groups/user_groups/ad_groups.
// - Fortsetter å fungere selv om dere kun bruker DB-roller i user_roles.

use App\Database;

// Session er allerede startet i public/index.php

// ---------------------------------------------------------
// Hjelpere
// ---------------------------------------------------------
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

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

/**
 * Returnerer liste over "grupper/roller/permissions" fra session på tvers av mulige nøkler og formater.
 * Støtter både array og streng (komma/semicolon/space-separert).
 */
function users_session_list(): array {
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

function users_has_any(array $needles, array $haystack): bool {
    if (empty($needles)) return false;
    $set = array_flip($haystack);
    foreach ($needles as $n) {
        $n = mb_strtolower(trim((string)$n), 'UTF-8');
        if ($n !== '' && isset($set[$n])) return true;
    }
    return false;
}

/**
 * Admin = finnes rad i user_roles med role='admin' for brukeren (join på users).
 * Returnerer [isAdmin, resolvedUserId]
 */
function resolveAdminFromUserRoles(PDO $pdo, string $sessionUsername): array {
    $raw  = trim($sessionUsername);
    $norm = normalizeUsername($raw);

    // Finn user_id via username (case-insensitive) – prøv raw og norm
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
        return [false, 0];
    }

    // Sjekk admin-rolle
    $st2 = $pdo->prepare("
        SELECT 1
          FROM user_roles
         WHERE user_id = :uid
           AND role = 'admin'
         LIMIT 1
    ");
    $st2->execute([':uid' => $uid]);

    $isAdmin = (bool)$st2->fetchColumn();
    return [$isAdmin, $uid];
}

// ---------------------------------------------------------
// Admin-guard (admin skal alltid inn)
// ---------------------------------------------------------
$sessionUsername = trim((string)($_SESSION['username'] ?? ''));
if ($sessionUsername === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til brukeradministrasjon.
    </div>
    <?php
    return;
}

try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til brukeradministrasjon.
    </div>
    <?php
    return;
}

// Admin fra session-flag + session-grupper
$isAdminSessionFlag = (bool)($_SESSION['is_admin'] ?? false);
$sessionGroups      = users_session_list();
$isAdminFromSession = $isAdminSessionFlag || users_has_any(['admin'], $sessionGroups);

// Admin fra DB (user_roles)
[$isAdminFromDb, $currentUserId] = resolveAdminFromUserRoles($pdo, $sessionUsername);

// ✅ Admin skal alltid ha tilgang uansett hvor admin kommer fra
$isAdmin = ($isAdminFromSession || $isAdminFromDb);

if (!$isAdmin) {
    error_log(
        "users_edit.php DENY: sessionUsername='{$sessionUsername}', normalized='" . normalizeUsername($sessionUsername) .
        "', resolvedUserId={$currentUserId}, is_admin_session=" . ($isAdminFromSession ? '1' : '0') .
        ", is_admin_db=" . ($isAdminFromDb ? '1' : '0') .
        ", sessionGroups=" . json_encode($sessionGroups)
    );
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til brukeradministrasjon.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Definer roller + hva de gir tilgang til
// ---------------------------------------------------------
$availableRoles = [
    'admin'           => 'Administrator',
    'network'         => 'Nettverk',
    'support'         => 'Support',
    'report'          => 'Rapportleser',
    'warehouse_read'  => 'Varelager (les)',
    'warehouse_write' => 'Varelager (skriv)',

    // ✅ Faktura/CRM
    'invoice'         => 'Faktura',

    // ✅ Avtaler/Kontrakter
    'contracts_read'  => 'Avtaler & kontrakter (les)',
    'contracts_write' => 'Avtaler & kontrakter (skriv)',

    // Nodelokasjoner (dokumentasjon/drift)
    'node_read'       => 'Nodelokasjoner (les)',
    'node_write'      => 'Nodelokasjoner (skriv)',

    // ✅ Feltobjekter / Nodelokasjoner (NYTT)
    'feltobjekter_les'   => 'Feltobjekter (les)',
    'feltobjekter_skriv' => 'Feltobjekter (skriv)',

    // Integrasjoner
    'integration'     => 'Integrasjoner (API/import)',

    // ✅ Hendelser / planlagte jobber (NYTT)
    'events_read'     => 'Hendelser & jobber (les)',
    'events_write'    => 'Hendelser & jobber (skriv)',
    'events_publish'  => 'Hendelser & jobber (publiser)',

    // ✅ KPI / Mål (ny modul)
    'report_admin'    => 'KPI/Mål (admin)',
    'report_user'     => 'KPI/Mål (rapportør)',
];

$roleHelp = [
    'admin'           => 'Full tilgang til admin-sider (brukere, maler/felter, systemoppsett) + alt under.',
    'network'         => 'Nettverkssider og drift/konfig relatert funksjonalitet.',
    'support'         => 'Support-/kundesider for feilsøking og oppfølging (ikke admin).',
    'report'          => 'Kun lesetilgang til rapporter/oversikter (ingen endringer).',
    'warehouse_read'  => 'Se lager/beholdning/lister/historikk.',
    'warehouse_write' => 'Registrere bevegelser/uttak/flytt/varetelling + vedlegg.',

    'invoice'         => 'Tilgang til kunderegister (CRM) og fakturafunksjoner (fakturagrunnlag + fakturaarkiv).',

    'contracts_read'  => 'Lesetilgang til avtaler/kontrakter (oversikt + visning).',
    'contracts_write' => 'Opprette/redigere avtaler/kontrakter + administrere varsler/metadata.',

    'node_read'       => 'Se nodelokasjoner og detaljer (view-sider).',
    'node_write'      => 'Opprette/redigere nodelokasjoner og endre felter/metadata.',

    // ✅ Feltobjekter / Nodelokasjoner (NYTT)
    'feltobjekter_les'   => 'Se feltobjekter og maler (lesetilgang).',
    'feltobjekter_skriv' => 'Opprette/redigere/slette feltobjekter, maler, grupper og felter.',

    'integration'     => 'Integrasjonssider (API-klienter, import, nøkkelstyring).',

    // ✅ Hendelser / planlagte jobber (NYTT)
    'events_read'     => 'Se hendelser og planlagte jobber (liste + visning).',
    'events_write'    => 'Opprette/redigere hendelser, scope/targets og oppdateringer.',
    'events_publish'  => 'Kan publisere/avpublisere til dashboard/chatbot og sette is_public.',

    // ✅ KPI / Mål (ny modul)
    'report_admin'    => 'Administrere KPI-er, avdelinger og delegere ansvar (tilgang til KPI/Mål admin-siden).',
    'report_user'     => 'Fylle inn månedlige KPI-tall som er delegert til brukeren + se KPI/Mål dashboard.',
];

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($userId <= 0) {
    ?>
    <div class="alert alert-warning mt-3">
        Ugyldig bruker-ID.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Håndter POST (lagre roller)
// ---------------------------------------------------------
$saveMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_roles') {
    $roles = $_POST['roles'] ?? [];
    if (!is_array($roles)) {
        $roles = [];
    }

    $roles = array_values(array_intersect($roles, array_keys($availableRoles)));

    try {
        $pdo->beginTransaction();

        // Slett gamle roller
        $del = $pdo->prepare('DELETE FROM user_roles WHERE user_id = :id');
        $del->execute([':id' => $userId]);

        // Sett inn nye roller
        if (!empty($roles)) {
            $ins = $pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (:id, :role)');
            foreach ($roles as $role) {
                $ins->execute([
                    ':id'   => $userId,
                    ':role' => $role,
                ]);
            }
        }

        $pdo->commit();
        $saveMessage = 'Rettigheter er oppdatert.';
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $saveMessage = null;
        ?>
        <div class="alert alert-danger mt-3">
            Kunne ikke lagre rettigheter (DB-feil).
        </div>
        <?php
    }
}

// ---------------------------------------------------------
// Hent brukerinfo
// ---------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT id, username, display_name, email, is_active, last_login_at, twofa_enabled
       FROM users
      WHERE id = :id'
);
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    ?>
    <div class="alert alert-warning mt-3">
        Fant ikke brukeren.
    </div>
    <?php
    return;
}

$usernameRow = $user['username'];
$displayName = $user['display_name'] ?: $usernameRow;
$email       = $user['email'] ?: null;
$isActive    = (bool)$user['is_active'];
$twofa       = (bool)$user['twofa_enabled'];
$lastLogin   = $user['last_login_at'];

// ---------------------------------------------------------
// Hent eksisterende roller for brukeren
// ---------------------------------------------------------
$currentRoles = [];
try {
    $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :id');
    $stmt->execute([':id' => $userId]);
    $currentRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (\Throwable $e) {
    $currentRoles = [];
}
?>

<div class="mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-1">Rettigheter for bruker</h1>
            <p class="text-muted small mb-0">
                Administrer roller og se status for brukeren.
            </p>
        </div>

        <a href="/?page=users" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Tilbake til liste
        </a>
    </div>
</div>

<section class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h5 mb-2">Brukerinfo</h2>

        <dl class="row small mb-0">
            <dt class="col-sm-3">Navn</dt>
            <dd class="col-sm-9"><?= h($displayName) ?></dd>

            <dt class="col-sm-3">Brukernavn</dt>
            <dd class="col-sm-9"><code><?= h($usernameRow) ?></code></dd>

            <dt class="col-sm-3">E-post</dt>
            <dd class="col-sm-9">
                <?php if ($email): ?>
                    <a href="mailto:<?= h($email) ?>"><?= h($email) ?></a>
                <?php else: ?>
                    <span class="text-muted">–</span>
                <?php endif; ?>
            </dd>

            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <?php if ($isActive): ?>
                    <span class="badge text-bg-success">Aktiv</span>
                <?php else: ?>
                    <span class="badge text-bg-secondary">Inaktiv</span>
                <?php endif; ?>
                &nbsp;
                2FA:
                <?php if ($twofa): ?>
                    <span class="badge text-bg-success">På</span>
                <?php else: ?>
                    <span class="badge text-bg-secondary">Av</span>
                <?php endif; ?>
            </dd>

            <dt class="col-sm-3">Sist innlogget</dt>
            <dd class="col-sm-9">
                <?php if ($lastLogin): ?>
                    <?= h($lastLogin) ?>
                <?php else: ?>
                    <span class="text-muted">Aldri</span>
                <?php endif; ?>
            </dd>
        </dl>
    </div>
</section>

<section class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-2">Rettigheter / roller</h2>

        <?php if ($saveMessage): ?>
            <div class="alert alert-success py-1 small"><?= h($saveMessage) ?></div>
        <?php endif; ?>

        <p class="small text-muted">
            Hak av hvilke roller brukeren skal ha i Teknisk. En bruker kan tilhøre flere roller.
        </p>

        <form method="post" class="mt-2" style="max-width: 520px;">
            <input type="hidden" name="action" value="save_roles">

            <?php foreach ($availableRoles as $roleKey => $roleLabel): ?>
                <?php $checked = in_array($roleKey, $currentRoles, true); ?>
                <div class="form-check mb-1">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="roles[]"
                        value="<?= h($roleKey) ?>"
                        id="role_<?= h($roleKey) ?>"
                        <?= $checked ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="role_<?= h($roleKey) ?>">
                        <?= h($roleLabel) ?>
                    </label>

                    <?php if (!empty($roleHelp[$roleKey])): ?>
                        <div class="form-text" style="margin-left: 1.6rem;">
                            <?= h($roleHelp[$roleKey]) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="btn btn-sm btn-primary mt-3">
                <i class="bi bi-check2 me-1"></i> Lagre rettigheter
            </button>
        </form>

        <p class="small text-muted mt-3 mb-0">
            Disse rollene brukes kun i Teknisk-løsningen, og påvirker ikke rettigheter i AD.
        </p>
    </div>
</section>