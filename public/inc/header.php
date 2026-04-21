<?php
// public/inc/header.php
// Robust header med lokal CSS (Bootswatch + Bootstrap Icons) + app.css,
// og print-mode (print=1) som dropper app-shell for å unngå heng i print preview.

declare(strict_types=1);

use App\Database;

// ---------------------------------------------------------
// Tema
// ---------------------------------------------------------
$defaultTheme    = 'standard';
$bootswatchTheme = $defaultTheme;

// 1) Hvis siden selv har satt $currentTheme, bruk den
if (isset($currentTheme) && is_string($currentTheme) && $currentTheme !== '') {
    $bootswatchTheme = strtolower($currentTheme);
} else {
    // 2) Hent fra user_settings hvis mulig
    $username = $_SESSION['username'] ?? null;
    if ($username) {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('
                SELECT us.theme
                FROM users u
                LEFT JOIN user_settings us ON us.user_id = u.id
                WHERE u.username = :username
                LIMIT 1
            ');
            $stmt->execute([':username' => $username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['theme'])) {
                $bootswatchTheme = strtolower((string)$row['theme']);
            }
        } catch (\Throwable $e) {
            // Ignorer DB-feil her – fallback til default
        }
    }
}

// Rydd tema-navn: kun bokstaver
$bootswatchTheme = preg_replace('~[^a-z]~', '', $bootswatchTheme) ?: $defaultTheme;

// ---------------------------------------------------------
// Egendefinerte temaer i /assets/themes/ (Standard, Mørk, Kitty)
// Faller tilbake til bootswatch-temaer for bakoverkompatibilitet
// ---------------------------------------------------------
$assetsBase     = __DIR__ . '/../assets';
$customThemeDir = "{$assetsBase}/themes/{$bootswatchTheme}";
$useCustomTheme = is_file("{$customThemeDir}/bootstrap.min.css");

if ($useCustomTheme) {
    $bsVer          = (string)filemtime("{$customThemeDir}/bootstrap.min.css");
    $themeBootstrapHref = "/assets/themes/{$bootswatchTheme}/bootstrap.min.css?v={$bsVer}";

    $hasThemeOverlay = is_file("{$customThemeDir}/theme.css");
    $themeOverlayHref = $hasThemeOverlay
        ? '/assets/themes/' . $bootswatchTheme . '/theme.css?v=' . filemtime("{$customThemeDir}/theme.css")
        : null;
} else {
    // Fallback: bootswatch
    $bootswatchFs = "{$assetsBase}/bootswatch/{$bootswatchTheme}/bootstrap.min.css";
    if (!is_file($bootswatchFs)) {
        $bootswatchTheme = $defaultTheme;
        $customThemeDir  = "{$assetsBase}/themes/{$bootswatchTheme}";
        if (is_file("{$customThemeDir}/bootstrap.min.css")) {
            $useCustomTheme = true;
            $bsVer = (string)filemtime("{$customThemeDir}/bootstrap.min.css");
            $themeBootstrapHref = "/assets/themes/{$bootswatchTheme}/bootstrap.min.css?v={$bsVer}";
            $hasThemeOverlay    = is_file("{$customThemeDir}/theme.css");
            $themeOverlayHref   = $hasThemeOverlay
                ? '/assets/themes/' . $bootswatchTheme . '/theme.css?v=' . filemtime("{$customThemeDir}/theme.css")
                : null;
            $bootswatchFs = '';
        } else {
            $bootswatchFs = "{$assetsBase}/bootswatch/{$bootswatchTheme}/bootstrap.min.css";
        }
    }
    if (!$useCustomTheme) {
        $bsVer = is_file($bootswatchFs) ? (string)filemtime($bootswatchFs) : (string)time();
        $themeBootstrapHref = "/assets/bootswatch/{$bootswatchTheme}/bootstrap.min.css?v={$bsVer}";
        $themeOverlayHref   = null;
        $hasThemeOverlay    = false;
    }
}

// ---------------------------------------------------------
// Print-mode: ?print=1 gir "flat" layout uten app-shell/sidebar/topbar
// (hindrer Chromium print preview som låser seg)
// ---------------------------------------------------------
$printMode = (($_GET['print'] ?? '') === '1');

// ---------------------------------------------------------
// 2FA-variabler (kan være satt av index.php)
// ---------------------------------------------------------
$twoFaScreen     = $twoFaScreen     ?? 'none';
$twoFaError      = $twoFaError      ?? null;
$twoFaSecret     = $twoFaSecret     ?? null;
$twoFaOtpauthUri = $twoFaOtpauthUri ?? null;

// Sidebar-tilstand (kan brukes i layout)
$sidebarExpanded = ($_COOKIE['sidebar_expanded'] ?? '1') === '1';

$iconsHref = "/assets/bootstrap-icons/bootstrap-icons.css";
?>
<!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($pageTitle ?? 'Teknisk', ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap / tema-base -->
    <link id="themeBootstrap" rel="stylesheet" href="<?= htmlspecialchars($themeBootstrapHref, ENT_QUOTES, 'UTF-8') ?>">

    <?php if ($themeOverlayHref): ?>
    <!-- Tema-overrides -->
    <link id="themeOverlay" rel="stylesheet" href="<?= htmlspecialchars($themeOverlayHref, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <!-- Lokale Bootstrap Icons -->
    <link rel="stylesheet" href="<?= htmlspecialchars($iconsHref, ENT_QUOTES, 'UTF-8') ?>">

    <!-- App CSS (samlet) - droppes i printMode for å unngå layout-loop -->
    <?php if (!$printMode): ?>
        <link rel="stylesheet" href="/assets/app/app.css?v=3">
    <?php endif; ?>

    <!-- Global print overrides (kun print) -->
    <link rel="stylesheet" href="/assets/app/print.css?v=2" media="print">

    <?php if ($printMode): ?>
        <!-- Litt "trygg" base i printMode -->
        <style>
            body { background:#fff; }
            .no-print { display:none !important; }
        </style>
    <?php endif; ?>
</head>

<body class="<?= $printMode ? 'print-mode' : 'bg-body' ?>">

<?php if ($twoFaScreen !== 'none'): ?>
    <div class="twofa-backdrop d-flex align-items-center justify-content-center">
        <div class="card shadow-lg border-0" style="max-width: 720px; width: 100%;">
            <div class="card-body">
                <?php if ($twoFaScreen === 'setup'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h2 class="h5 mb-0">Aktiver tofaktor-autentisering</h2>
                        <span class="badge rounded-pill text-bg-danger-subtle border border-danger-subtle small">
                            Obligatorisk
                        </span>
                    </div>
                    <p class="small text-muted mb-3">
                        Før du kan bruke systemet må du sette opp 2FA på denne kontoen.
                    </p>

                    <div class="row g-3 align-items-start">
                        <div class="col-sm-5">
                            <?php if (!empty($twoFaOtpauthUri)): ?>
                                <img
                                    src="/qrcode.php?data=<?= urlencode((string)$twoFaOtpauthUri) ?>"
                                    alt="QR-kode for autentiserings-app"
                                    class="img-fluid rounded border bg-white p-1"
                                >
                            <?php else: ?>
                                <div class="alert alert-danger small mb-0">
                                    Klarte ikke å generere QR-kode. Last siden på nytt, eller bruk nøkkelen manuelt.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-sm-7">
                            <p class="small fw-semibold mb-1">Eller legg inn nøkkelen manuelt:</p>
                            <div class="small mb-2">
                                <?php if (!empty($twoFaSecret)): ?>
                                    <?php $grouped = trim(chunk_split((string)$twoFaSecret, 4, ' ')); ?>
                                    <code class="d-inline-block"><?= htmlspecialchars($grouped, ENT_QUOTES, 'UTF-8') ?></code>
                                <?php else: ?>
                                    <code>Mangler nøkkel</code>
                                <?php endif; ?>
                            </div>

                            <form method="post" class="mt-2">
                                <label for="totp_code_setup" class="form-label small">
                                    Skriv inn 6-sifret kode fra autentiserings-appen:
                                </label>
                                <input
                                    id="totp_code_setup"
                                    type="text"
                                    name="totp_code"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    pattern="\d{6}"
                                    maxlength="6"
                                    required
                                    autofocus
                                    class="form-control text-center fs-4"
                                    style="letter-spacing: 0.5em;"
                                >
                                <?php if (!empty($twoFaError)): ?>
                                    <div class="text-danger small mt-2">
                                        <?= htmlspecialchars((string)$twoFaError, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>

                                <button type="submit" class="btn btn-primary btn-sm mt-3">
                                    Aktiver 2FA
                                </button>
                            </form>

                            <div class="d-flex gap-3 align-items-center mt-2 small">
                                <a class="link-secondary" href="/?page=logout">Avbryt innlogging</a>
                            </div>

                            <p class="text-muted small mt-2 mb-0">
                                Etter at koden er verifisert, låses systemet opp for denne økten.
                            </p>
                        </div>
                    </div>

                <?php elseif ($twoFaScreen === 'code'): ?>
                    <h2 class="h5 mb-1">Tofaktor-kode</h2>
                    <p class="small text-muted mb-3">
                        Skriv inn 6-sifret kode fra autentiserings-appen.
                    </p>

                    <form method="post">
                        <label for="totp_code_simple" class="form-label small">Kode:</label>
                        <input
                            id="totp_code_simple"
                            type="text"
                            name="totp_code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            pattern="\d{6}"
                            maxlength="6"
                            required
                            autofocus
                            class="form-control text-center fs-4"
                            style="max-width: 260px; letter-spacing: 0.5em;"
                        >
                        <?php if (!empty($twoFaError)): ?>
                            <div class="text-danger small mt-2">
                                <?= htmlspecialchars((string)$twoFaError, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary btn-sm mt-3">
                            Verifiser
                        </button>
                    </form>

                    <div class="d-flex gap-3 align-items-center mt-3 small">
                        <a class="link-secondary" href="/?page=logout">Avbryt innlogging</a>
                    </div>

                    <p class="text-muted small mt-2 mb-0">
                        Har du problemer med koden, sjekk at klokkeslettet på mobilen stemmer.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($twoFaScreen !== 'none'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('totp_code_setup') || document.getElementById('totp_code_simple');
    if (!el) return;
    setTimeout(function () {
        try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
        try { el.select(); } catch (e) {}
    }, 50);
});
</script>
<?php endif; ?>

<?php if (!$printMode): ?>
    <div class="app-shell d-flex<?= $sidebarExpanded ? ' sidebar-expanded' : '' ?>">
<?php else: ?>
    <!-- printMode: ingen app-shell her (sidebar/topbar droppes av footer også) -->
    <main class="container-fluid py-3">
<?php endif; ?>
