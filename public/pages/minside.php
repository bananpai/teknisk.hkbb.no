<?php
// /public/pages/minside.php

use App\Database;

// Forutsetter at du har satt disse i session ved innlogging
$username = $_SESSION['username'] ?? null;
$fullname = $_SESSION['fullname'] ?? null;

if (!$username || !$fullname) {
    header('Location: /login/');
    exit;
}

$pdo = Database::getConnection();

// Finn user_id + 2FA-status
$stmt = $pdo->prepare('SELECT id, twofa_enabled FROM users WHERE username = :username');
$stmt->execute([':username' => $username]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    header('Location: /login/');
    exit;
}

$userId        = (int)$row['id'];
$twoFaEnabled  = (bool)($row['twofa_enabled'] ?? 0);          // Aktivert på kontoen?
$twoFaVerified = !empty($_SESSION['twofa_verified']);         // Verifisert i denne økten?

$twoFaStatusBadge = $twoFaEnabled ? 'text-bg-success' : 'text-bg-warning';
$twoFaStatusLabel = $twoFaEnabled ? '2FA aktivert' : '2FA ikke aktivert';

$twoFaResetMessage = null;

// ---------------------------------------------------------
// Hent roller for innlogget bruker
// ---------------------------------------------------------
$userRoles = [];
try {
    $st = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid ORDER BY role');
    $st->execute([':uid' => $userId]);
    $userRoles = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (\Throwable $e) {
    $userRoles = [];
}

$isAdminRole = in_array('admin', $userRoles, true);

// Valgfri "navn" på roller (fallback til selve key)
$roleLabels = [
    'admin'           => 'Administrator',
    'network'         => 'Nettverk',
    'support'         => 'Support',
    'report'          => 'Rapportleser',
    'warehouse_read'  => 'Varelager (les)',
    'warehouse_write' => 'Varelager (skriv)',
    'node_read'       => 'Nodelokasjoner (les)',
    'node_write'      => 'Nodelokasjoner (skriv)',
    'integration'     => 'Integrasjoner (API/import)',
];

// ---------------------------------------------------------
// Håndter sletting / reset av 2FA
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_2fa'])) {
    // Slå av 2FA på kontoen
    $stmt = $pdo->prepare('UPDATE users SET twofa_enabled = 0, twofa_secret = NULL WHERE id = :id');
    $stmt->execute([':id' => $userId]);

    // Nullstill session-flagg
    $_SESSION['twofa_verified'] = false;
    $_SESSION['twofa_secret']   = null;

    // Oppdater lokale variabler for visning
    $twoFaEnabled      = false;
    $twoFaVerified     = false;
    $twoFaStatusBadge  = 'text-bg-warning';
    $twoFaStatusLabel  = '2FA ikke aktivert';
    $twoFaResetMessage = '2-faktor er nå slått av. Neste gang 2FA kreves vil du bli bedt om å sette den opp på nytt.';
}

// ---------------------------------------------------------
// Tema / profilbilde
// ---------------------------------------------------------

/**
 * Normaliserer tema til bootswatch-mappenavn (lowercase).
 * Støtter bakoverkompatibilitet hvis DB inneholder "Yeti", "Slate", "Cosmo", osv.
 */
function normalize_theme(?string $t): string {
    $t = trim((string)$t);
    if ($t === '') return 'yeti';

    $lower = strtolower($t);

    // Hvis noen gamle verdier er lagret med store bokstaver:
    $legacyMap = [
        'yeti'  => 'yeti',
        'slate' => 'slate',
        'cosmo' => 'cosmo',
        'journal' => 'journal',
        'quartz' => 'quartz',
        'superhero' => 'superhero',
        'kitty' => 'kitty',
    ];

    return $legacyMap[$lower] ?? 'yeti';
}

// Temaene du ønsket (matcher mappenavn under /public/assets/bootswatch/)
$availableThemes = [
    'cosmo'     => 'Cosmo',
    'journal'   => 'Journal',
    'quartz'    => 'Quartz',
    'slate'     => 'Slate (mørk)',
    'superhero' => 'Superhero (mørk)',
    'yeti'      => 'Yeti (standard)',
    'kitty'     => 'Kitty',
];

// Hent gjeldende settings
$stmt = $pdo->prepare('SELECT theme, avatar_path FROM user_settings WHERE user_id = :user_id');
$stmt->execute([':user_id' => $userId]);
$currentSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'theme'       => 'yeti',
    'avatar_path' => null,
];

$currentTheme  = normalize_theme($currentSettings['theme'] ?? 'yeti');
$currentAvatar = $currentSettings['avatar_path'] ?? null;

// Håndter lagring av utseende
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_appearance'])) {
    $theme = normalize_theme($_POST['theme'] ?? 'yeti');
    if (!array_key_exists($theme, $availableThemes)) {
        $theme = 'yeti';
    }

    $avatarPath = $currentAvatar;

    // Enkel bildeopplasting
    if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['avatar']['tmp_name'];

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmpName);

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];

        if (isset($allowed[$mime])) {
            $ext = $allowed[$mime];

            $uploadDir = __DIR__ . '/../uploads/avatars';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            $fileName = 'user-' . $userId . '.' . $ext;
            $target   = $uploadDir . '/' . $fileName;

            if (move_uploaded_file($tmpName, $target)) {
                // URL som kan brukes i <img src="">
                $avatarPath = '/uploads/avatars/' . $fileName;
            }
        }
    }

    // Oppdater / insert settings
    $stmt = $pdo->prepare("
        INSERT INTO user_settings (user_id, theme, avatar_path)
        VALUES (:user_id, :theme, :avatar_path)
        ON DUPLICATE KEY UPDATE
            theme = VALUES(theme),
            avatar_path = VALUES(avatar_path)
    ");
    $stmt->execute([
        ':user_id'     => $userId,
        ':theme'       => $theme,
        ':avatar_path' => $avatarPath,
    ]);

    // Oppdater lokale variabler for visning
    $currentTheme  = $theme;
    $currentAvatar = $avatarPath;
}

// ---------------------------------------------------------
// Ikonvalg for snarveier (10 presets)
// ---------------------------------------------------------
$iconPresets = [
    'bi bi-link-45deg'     => 'Generell lenke',
    'bi bi-house-door'     => 'Hjem / Portal',
    'bi bi-life-preserver' => 'Support / Helpdesk',
    'bi bi-graph-up'       => 'Rapport / Analyse',
    'bi bi-shield-lock'    => 'Sikkerhet / Admin',
    'bi bi-router'         => 'Nettverk / Router',
    'bi bi-hdd-network'    => 'Infrastruktur / Nettverk',
    'bi bi-cloud'          => 'Sky / Tjenester',
    'bi bi-server'         => 'Server / Drift',
    'bi bi-gear'           => 'Innstillinger / Verktøy',
];

// ---------------------------------------------------------
// Private snarveier (linksamling)
// ---------------------------------------------------------
$linksMessage = null;
$linksError   = null;
$editLinkId   = 0;

function is_valid_link_url(string $url): bool {
    $url = trim($url);
    if ($url === '') return false;

    // Tillat interne relative lenker ("/?page=...")
    if (str_starts_with($url, '/')) return true;

    // Ellers krev http/https
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

    $scheme = parse_url($url, PHP_URL_SCHEME);
    return in_array(strtolower((string)$scheme), ['http', 'https'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Start edit
    if (isset($_POST['start_edit_link'])) {
        $editLinkId = (int)($_POST['link_id'] ?? 0);
    }

    // Avbryt edit
    if (isset($_POST['cancel_edit_link'])) {
        $editLinkId = 0;
    }

    // Legg til link
    if (isset($_POST['add_quick_link'])) {
        $title = trim((string)($_POST['title'] ?? ''));
        $url   = trim((string)($_POST['url'] ?? ''));
        $icon  = trim((string)($_POST['icon_class'] ?? ''));

        if ($title === '' || mb_strlen($title, 'UTF-8') > 120) {
            $linksError = 'Tittel må fylles ut (maks 120 tegn).';
        } elseif (!is_valid_link_url($url)) {
            $linksError = 'Ugyldig URL. Bruk https://... eller en intern lenke som starter med /.';
        } elseif ($icon !== '' && mb_strlen($icon, 'UTF-8') > 80) {
            $linksError = 'Ikon-klasse er for lang (maks 80 tegn).';
        } else {
            try {
                // finn neste sort_order
                $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) AS m FROM user_quick_links WHERE user_id = :uid');
                $stmt->execute([':uid' => $userId]);
                $max = (int)($stmt->fetchColumn() ?: 0);
                $nextSort = $max + 10;

                $stmt = $pdo->prepare('
                    INSERT INTO user_quick_links (user_id, title, url, icon_class, sort_order)
                    VALUES (:uid, :title, :url, :icon, :sort)
                ');
                $stmt->execute([
                    ':uid'   => $userId,
                    ':title' => $title,
                    ':url'   => $url,
                    ':icon'  => ($icon === '' ? null : $icon),
                    ':sort'  => $nextSort,
                ]);

                $linksMessage = 'Lenken ble lagt til.';
            } catch (\Throwable $e) {
                $linksError = 'Kunne ikke lagre lenke (mangler tabell eller DB-feil).';
            }
        }
    }

    // Lagre endringer på eksisterende link
    if (isset($_POST['save_quick_link'])) {
        $linkId = (int)($_POST['link_id'] ?? 0);
        $title  = trim((string)($_POST['title'] ?? ''));
        $url    = trim((string)($_POST['url'] ?? ''));
        $icon   = trim((string)($_POST['icon_class'] ?? ''));

        if ($linkId <= 0) {
            $linksError = 'Ugyldig lenke-ID.';
        } elseif ($title === '' || mb_strlen($title, 'UTF-8') > 120) {
            $linksError = 'Tittel må fylles ut (maks 120 tegn).';
        } elseif (!is_valid_link_url($url)) {
            $linksError = 'Ugyldig URL. Bruk https://... eller en intern lenke som starter med /.';
        } elseif ($icon !== '' && mb_strlen($icon, 'UTF-8') > 80) {
            $linksError = 'Ikon-klasse er for lang (maks 80 tegn).';
        } else {
            try {
                // Sikkerhet: kun egne lenker
                $stmt = $pdo->prepare('
                    UPDATE user_quick_links
                       SET title = :title, url = :url, icon_class = :icon
                     WHERE id = :id AND user_id = :uid
                     LIMIT 1
                ');
                $stmt->execute([
                    ':title' => $title,
                    ':url'   => $url,
                    ':icon'  => ($icon === '' ? null : $icon),
                    ':id'    => $linkId,
                    ':uid'   => $userId,
                ]);

                $linksMessage = 'Lenken ble oppdatert.';
                $editLinkId = 0;
            } catch (\Throwable $e) {
                $linksError = 'Kunne ikke oppdatere lenke (mangler tabell eller DB-feil).';
            }
        }
    }

    // Slett link
    if (isset($_POST['delete_quick_link'])) {
        $linkId = (int)($_POST['link_id'] ?? 0);
        if ($linkId > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM user_quick_links WHERE id = :id AND user_id = :uid LIMIT 1');
                $stmt->execute([':id' => $linkId, ':uid' => $userId]);
                $linksMessage = 'Lenken ble slettet.';
                if ($editLinkId === $linkId) $editLinkId = 0;
            } catch (\Throwable $e) {
                $linksError = 'Kunne ikke slette lenke (mangler tabell eller DB-feil).';
            }
        }
    }

    // Flytt opp/ned
    if (isset($_POST['move_quick_link'])) {
        $linkId = (int)($_POST['link_id'] ?? 0);
        $dir    = (string)($_POST['dir'] ?? '');
        if ($linkId > 0 && in_array($dir, ['up', 'down'], true)) {
            try {
                // Finn aktuell
                $stmt = $pdo->prepare('SELECT id, sort_order FROM user_quick_links WHERE id = :id AND user_id = :uid LIMIT 1');
                $stmt->execute([':id' => $linkId, ':uid' => $userId]);
                $cur = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($cur) {
                    $curSort = (int)$cur['sort_order'];

                    if ($dir === 'up') {
                        $stmt = $pdo->prepare('
                            SELECT id, sort_order
                              FROM user_quick_links
                             WHERE user_id = :uid AND sort_order < :s
                             ORDER BY sort_order DESC, id DESC
                             LIMIT 1
                        ');
                    } else {
                        $stmt = $pdo->prepare('
                            SELECT id, sort_order
                              FROM user_quick_links
                             WHERE user_id = :uid AND sort_order > :s
                             ORDER BY sort_order ASC, id ASC
                             LIMIT 1
                        ');
                    }

                    $stmt->execute([':uid' => $userId, ':s' => $curSort]);
                    $nbr = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($nbr) {
                        $nbrId   = (int)$nbr['id'];
                        $nbrSort = (int)$nbr['sort_order'];

                        // swap
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare('UPDATE user_quick_links SET sort_order = :s WHERE id = :id AND user_id = :uid');
                        $stmt->execute([':s' => $nbrSort, ':id' => $linkId, ':uid' => $userId]);
                        $stmt->execute([':s' => $curSort, ':id' => $nbrId,  ':uid' => $userId]);
                        $pdo->commit();

                        $linksMessage = 'Rekkefølge oppdatert.';
                    }
                }
            } catch (\Throwable $e) {
                if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
                $linksError = 'Kunne ikke endre rekkefølge (mangler tabell eller DB-feil).';
            }
        }
    }
}

// Hent lenker for visning
$userLinks = [];
try {
    $stmt = $pdo->prepare('
        SELECT id, title, url, icon_class, sort_order
          FROM user_quick_links
         WHERE user_id = :uid
         ORDER BY sort_order ASC, id ASC
    ');
    $stmt->execute([':uid' => $userId]);
    $userLinks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $userLinks = [];
}

// Initialer til fallback-avatar
$initials = mb_strtoupper(mb_substr($fullname, 0, 1), 'UTF-8');
?>

<!-- Live theme preview (bytter bootswatch-css i <head>) -->
<script>
(function () {
    function findThemeLink() {
        // 1) Hvis header allerede har en link med id:
        var byId = document.getElementById('bootswatchTheme');
        if (byId) return byId;

        // 2) Ellers: finn første stylesheet som peker på /assets/bootswatch/
        var links = document.querySelectorAll('link[rel="stylesheet"]');
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href') || '';
            if (href.indexOf('/assets/bootswatch/') !== -1) return links[i];
        }

        // 3) Opprett en hvis ingen finnes
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.id = 'bootswatchTheme';
        document.head.appendChild(l);
        return l;
    }

    function setTheme(themeKey) {
        var link = findThemeLink();
        link.id = 'bootswatchTheme';
        link.href = '/assets/bootswatch/' + themeKey + '/bootstrap.min.css';
        link.setAttribute('data-theme', themeKey);
    }

    window.__setBootswatchTheme = setTheme;
})();
</script>

<!-- Side-header inne i innholdet -->
<div class="mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-1">Min side</h1>
            <p class="text-muted small mb-0">
                Brukerprofil for <?php echo htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8'); ?>.
            </p>
        </div>

        <div class="d-flex align-items-center gap-2">
            <div
                class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center overflow-hidden"
                style="width:40px;height:40px;"
            >
                <?php if ($currentAvatar): ?>
                    <img
                        src="<?php echo htmlspecialchars($currentAvatar, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="Profilbilde"
                        style="width:100%;height:100%;object-fit:cover;"
                    >
                <?php else: ?>
                    <span class="fw-semibold">
                        <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-column small">
                <span class="fw-semibold">
                    <?php echo htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <span class="text-muted">
                    <code class="small mb-0"><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></code>
                </span>
            </div>
            <a href="/?page=logout" class="btn btn-sm btn-outline-danger ms-2">
                <i class="bi bi-box-arrow-right me-1"></i> Logg ut
            </a>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Sikkerhet -->
    <div class="col-lg-8">
        <section class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h5 mb-0">Sikkerhet</h2>
                    <span class="badge <?php echo $twoFaStatusBadge; ?>">
                        <?php echo htmlspecialchars($twoFaStatusLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>

                <?php if ($twoFaResetMessage): ?>
                    <div class="alert alert-info py-1 small mb-3">
                        <?php echo htmlspecialchars($twoFaResetMessage, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <p class="mb-2">
                    Tofaktor-autentisering:
                    <strong>
                        <?php
                        if (!$twoFaEnabled) {
                            echo 'Ikke aktivert (må settes opp)';
                        } elseif ($twoFaEnabled && $twoFaVerified) {
                            echo 'Aktivert og verifisert i denne økten';
                        } else {
                            echo 'Aktivert, men ikke verifisert i denne økten';
                        }
                        ?>
                    </strong>
                </p>

                <p class="small text-muted mb-3">
                    2FA-popupen kommer automatisk opp etter innlogging dersom den ikke er aktivert.
                    Når 2FA er satt opp, beskyttes innloggingen din med både AD-passord og engangskode.
                </p>

                <!-- ✅ NYTT: Lenke til passordbytte -->
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <a href="/?page=change_password" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-key me-1"></i> Bytt passord
                    </a>
                    <span class="small text-muted align-self-center">
                        (Gjelder passord i Teknisk. Hvis dere bruker AD-passord, må det byttes i AD.)
                    </span>
                </div>

                <?php if ($twoFaEnabled): ?>
                    <form method="post" class="mt-2">
                        <input type="hidden" name="reset_2fa" value="1">
                        <button
                            type="submit"
                            class="btn btn-sm btn-outline-danger"
                            onclick="return confirm('Er du sikker på at du vil slå av 2-faktor og sette den opp på nytt?');"
                        >
                            <i class="bi bi-shield-x me-1"></i>
                            Slå av 2-faktor / sett opp på nytt
                        </button>
                        <p class="small text-muted mt-2 mb-0">
                            Etter at du har slått av 2-faktor vil du bli bedt om å sette opp en ny kode neste gang 2FA kreves.
                        </p>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Profilinformasjon -->
    <div class="col-lg-4">
        <aside class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-2">Profilinformasjon</h2>
                <p class="mb-2 small">
                    Navn: <strong><?php echo htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8'); ?></strong><br>
                    Bruker: <code><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></code>
                </p>

                <!-- ✅ Roller -->
                <div class="mt-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="small text-muted">Roller</div>
                        <?php if ($isAdminRole): ?>
                            <span class="badge text-bg-primary">Admin</span>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($userRoles)): ?>
                        <div class="text-muted small">Ingen roller.</div>
                    <?php else: ?>
                        <div class="mt-2 d-flex flex-wrap gap-1">
                            <?php foreach ($userRoles as $rk): ?>
                                <?php $label = $roleLabels[$rk] ?? $rk; ?>
                                <span class="badge text-bg-secondary">
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">
                            (Internt: <?php echo htmlspecialchars(implode(', ', $userRoles), ENT_QUOTES, 'UTF-8'); ?>)
                        </div>
                    <?php endif; ?>
                </div>

                <p class="small text-muted mt-3 mb-0">
                    Profilinformasjon hentes fra AD og kan ikke endres her.
                    Ta kontakt med IT hvis noe ikke stemmer.
                </p>
            </div>
        </aside>
    </div>
</div>

<!-- Utseende / tema / avatar -->
<section class="card shadow-sm mt-3">
    <div class="card-body">
        <h2 class="h5 mb-2">Utseende</h2>
        <p class="small text-muted mb-3">
            Her kan du endre tema og profilbilde for Teknisk. Endringene påvirker kun denne løsningen.
        </p>

        <form method="post" enctype="multipart/form-data" class="mt-2" style="max-width: 480px;">
            <div class="mb-3">
                <label for="theme" class="form-label">Tema</label>
                <select
                    id="theme"
                    name="theme"
                    class="form-select form-select-sm"
                    onchange="if(window.__setBootswatchTheme){window.__setBootswatchTheme(this.value);}"
                >
                    <?php foreach ($availableThemes as $value => $label): ?>
                        <option
                            value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $value === $currentTheme ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">
                    Du kan forhåndsvise temaet umiddelbart ved å bytte i listen.
                    For å lagre permanent må du trykke “Lagre endringer”.
                </div>
            </div>

            <div class="mb-3">
                <label for="avatar" class="form-label">Profilbilde</label>
                <input
                    type="file"
                    id="avatar"
                    name="avatar"
                    accept="image/*"
                    class="form-control form-control-sm"
                >
                <div class="form-text">
                    Valgfritt. Støtter JPG, PNG, GIF og WebP.
                </div>

                <?php if ($currentAvatar): ?>
                    <div class="d-flex align-items-center gap-2 mt-2 small">
                        <span class="text-muted">Gjeldende bilde:</span>
                        <img
                            src="<?php echo htmlspecialchars($currentAvatar, ENT_QUOTES, 'UTF-8'); ?>"
                            alt="Gjeldende profilbilde"
                            class="rounded-circle border"
                            style="width:36px;height:36px;object-fit:cover;"
                        >
                    </div>
                <?php endif; ?>
            </div>

            <button type="submit" name="save_appearance" class="btn btn-primary btn-sm">
                <i class="bi bi-check2 me-1"></i> Lagre endringer
            </button>
        </form>
    </div>
</section>

<!-- Mine lenker (privat snarveissamling) -->
<section class="card shadow-sm mt-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h5 mb-0">Mine lenker</h2>
            <span class="badge text-bg-secondary-subtle small">Privat</span>
        </div>

        <p class="small text-muted mb-3">
            Disse lenkene er kun synlige for deg, og vises på startsiden din.
        </p>

        <?php if ($linksMessage): ?>
            <div class="alert alert-success py-1 small mb-3">
                <?php echo htmlspecialchars($linksMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($linksError): ?>
            <div class="alert alert-danger py-1 small mb-3">
                <?php echo htmlspecialchars($linksError, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="post" class="row g-2 align-items-end mb-3">
            <div class="col-md-4">
                <label class="form-label form-label-sm mb-1">Tittel</label>
                <input type="text" name="title" class="form-control form-control-sm" maxlength="120" required>
            </div>
            <div class="col-md-5">
                <label class="form-label form-label-sm mb-1">URL</label>
                <input type="text" name="url" class="form-control form-control-sm" placeholder="https://... eller /?page=..." required>
            </div>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-1">Ikon</label>
                <select name="icon_class" class="form-select form-select-sm">
                    <option value="">Standard (lenke)</option>
                    <?php foreach ($iconPresets as $cls => $label): ?>
                        <option value="<?php echo htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($label . ' — ' . $cls, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <button type="submit" name="add_quick_link" value="1" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1"></i> Legg til lenke
                </button>
            </div>
        </form>

        <?php if (empty($userLinks)): ?>
            <div class="text-muted small">
                Ingen lenker enda. Legg til en over.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 42px;"></th>
                            <th>Tittel</th>
                            <th>URL</th>
                            <th style="width: 220px;" class="text-end">Handlinger</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($userLinks as $l): ?>
                        <?php
                        $lid   = (int)$l['id'];
                        $title = (string)$l['title'];
                        $url   = (string)$l['url'];
                        $icon  = (string)($l['icon_class'] ?? '');
                        $isEditing = ($editLinkId === $lid);

                        $iconToShow = $icon !== '' ? $icon : 'bi bi-link-45deg';
                        ?>
                        <tr>
                            <td class="text-muted">
                                <i class="<?php echo htmlspecialchars($iconToShow, ENT_QUOTES, 'UTF-8'); ?>"></i>
                            </td>

                            <?php if ($isEditing): ?>
                                <td colspan="2">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="link_id" value="<?php echo $lid; ?>">

                                        <div class="col-md-4">
                                            <input type="text" name="title" class="form-control form-control-sm" maxlength="120"
                                                   value="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" required>
                                        </div>

                                        <div class="col-md-5">
                                            <input type="text" name="url" class="form-control form-control-sm"
                                                   value="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" required>
                                        </div>

                                        <div class="col-md-3">
                                            <select name="icon_class" class="form-select form-select-sm">
                                                <option value="" <?php echo $icon === '' ? 'selected' : ''; ?>>
                                                    Standard (lenke)
                                                </option>

                                                <?php
                                                $isPreset = ($icon !== '' && array_key_exists($icon, $iconPresets));
                                                if ($icon !== '' && !$isPreset):
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>" selected>
                                                        Egendefinert — <?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endif; ?>

                                                <?php foreach ($iconPresets as $cls => $label): ?>
                                                    <option
                                                        value="<?php echo htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'); ?>"
                                                        <?php echo $cls === $icon ? 'selected' : ''; ?>
                                                    >
                                                        <?php echo htmlspecialchars($label . ' — ' . $cls, ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text">Velg ikon for lenken.</div>
                                        </div>

                                        <div class="col-12 d-flex gap-2">
                                            <button type="submit" name="save_quick_link" value="1" class="btn btn-sm btn-success">
                                                <i class="bi bi-check2 me-1"></i> Lagre
                                            </button>
                                            <button type="submit" name="cancel_edit_link" value="1" class="btn btn-sm btn-outline-secondary">
                                                Avbryt
                                            </button>
                                        </div>
                                    </form>
                                </td>

                                <td class="text-end">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="link_id" value="<?php echo $lid; ?>">
                                        <button type="submit" name="delete_quick_link" value="1"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Slette denne lenken?');">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            <?php else: ?>
                                <td>
                                    <div class="fw-semibold small">
                                        <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </td>
                                <td class="small text-truncate" style="max-width: 520px;">
                                    <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                        <?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="link_id" value="<?php echo $lid; ?>">
                                            <input type="hidden" name="dir" value="up">
                                            <button type="submit" name="move_quick_link" value="1" class="btn btn-sm btn-outline-secondary" title="Flytt opp">
                                                <i class="bi bi-arrow-up"></i>
                                            </button>
                                        </form>

                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="link_id" value="<?php echo $lid; ?>">
                                            <input type="hidden" name="dir" value="down">
                                            <button type="submit" name="move_quick_link" value="1" class="btn btn-sm btn-outline-secondary" title="Flytt ned">
                                                <i class="bi bi-arrow-down"></i>
                                            </button>
                                        </form>

                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="link_id" value="<?php echo $lid; ?>">
                                            <button type="submit" name="start_edit_link" value="1" class="btn btn-sm btn-outline-primary" title="Rediger">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </form>

                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="link_id" value="<?php echo $lid; ?>">
                                            <button type="submit" name="delete_quick_link" value="1"
                                                    class="btn btn-sm btn-outline-danger" title="Slett"
                                                    onclick="return confirm('Slette denne lenken?');">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
