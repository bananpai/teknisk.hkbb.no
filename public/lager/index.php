<?php
// public/lager/index.php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Finn basepath dynamisk (typisk /lager)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/lager/index.php'; // f.eks. /lager/index.php
$base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/'); // => /lager
if ($base === '') $base = '/';

$path = rtrim($uriPath, '/');
if ($path === '') $path = '/';

// Plukk subpath relativt til base
if ($base !== '/' && str_starts_with($path, $base)) {
    $sub = substr($path, strlen($base));
} else {
    // fallback: prøv /lager eksplisitt (for sikkerhet)
    if (!str_starts_with($path, '/lager')) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    $sub = substr($path, strlen('/lager'));
}
$sub = $sub === '' ? '/' : $sub;

/**
 * ---------------------------------------------------------
 * BACKWARD COMPAT: støtte for /lager/?page=...
 * ---------------------------------------------------------
 * Eksempel: /lager/?page=uttak_shop  -> /uttak-shop
 */
$page = (string)($_GET['page'] ?? '');
$page = trim($page);

// whitelist-map (kun det vi tillater)
$pageToRoute = [
    'home'            => '/',
    'uttak'           => '/uttak',
    'uttak2'          => '/uttak2',
    'uttak_shop'      => '/uttak-shop',
    'uttak_checkout'  => '/uttak-checkout',

    'mine_uttak'      => '/mine-uttak',
    'profil'          => '/profil',
    'passord'         => '/passord', // behold for kompat, men ikke i navbar (ligger "bak min side")

    'login'           => '/login',
    'logout'          => '/logout',
    'glemt_passord'   => '/glemt-passord',
    'opprett_bruker'  => '/opprett-bruker',
];

// Hvis page= er satt og er kjent -> overstyr $sub
if ($page !== '' && isset($pageToRoute[$page])) {
    $sub = $pageToRoute[$page];
}

// Route map
$routes = [
    '/'               => __DIR__ . '/pages/home.php',

    '/login'          => __DIR__ . '/pages/login.php',
    '/logout'         => __DIR__ . '/pages/logout.php',

    '/glemt-passord'  => __DIR__ . '/pages/glemt_passord.php',
    '/opprett-bruker' => __DIR__ . '/pages/opprett_bruker.php',

    // Uttak (wizard)
    '/uttak'            => __DIR__ . '/pages/uttak.php',              // steg 1
    '/uttak2'           => __DIR__ . '/pages/uttak2.php',             // test
    '/uttak-shop'       => __DIR__ . '/pages/uttak_shop.php',         // steg 2
    '/uttak-checkout'   => __DIR__ . '/pages/uttak_checkout.php',     // steg 3

    // Alias (underscore)
    '/uttak_shop'       => __DIR__ . '/pages/uttak_shop.php',
    '/uttak_checkout'   => __DIR__ . '/pages/uttak_checkout.php',

    '/mine-uttak'     => __DIR__ . '/pages/mine_uttak.php',
    '/profil'         => __DIR__ . '/pages/profil.php',
    '/passord'        => __DIR__ . '/pages/passord.php',

    // API
    '/api/product_search'     => __DIR__ . '/api/product_search.php',
    '/api/workorders'         => __DIR__ . '/api/workorders.php',
    '/api/commit_withdrawal'  => __DIR__ . '/api/commit_withdrawal.php',
];

$file = $routes[$sub] ?? null;

if (!$file || !file_exists($file)) {
    http_response_code(404);
    echo '<div style="font-family:system-ui;margin:20px">404 – Ikke funnet</div>';
    exit;
}

// ---------------------------------------------------------
// API-ruter skal ikke wrappes i HTML-layout
// ---------------------------------------------------------
if (str_starts_with($sub, '/api/')) {
    require $file;
    exit;
}

// ---------------------------------------------------------
// Auth-guard: alt utenom login/glemt/opprett krever innlogging
// ---------------------------------------------------------
$isLoggedIn = false;
if (!empty($_SESSION['lager_user_id'])) $isLoggedIn = true;
if (isset($_SESSION['lager_user']) && is_array($_SESSION['lager_user']) && !empty($_SESSION['lager_user']['id'])) $isLoggedIn = true;

$publicRoutes = ['/login', '/glemt-passord', '/opprett-bruker'];

if (!$isLoggedIn && !in_array($sub, $publicRoutes, true)) {
    header('Location: ' . $base . '/login');
    exit;
}

// ---------------------------------------------------------
// Layout-wrapper
// ---------------------------------------------------------
$navActive = function (string $route) use ($sub): string {
    if ($sub === $route) return 'active';
    if ($route === '/uttak' && str_starts_with($sub, '/uttak')) return 'active';
    return '';
};

$href = function (string $route) use ($base): string {
    if ($route === '/') return $base . '/';
    return $base . $route;
};

// Render sideinnhold i buffer
ob_start();
require $file;
$content = ob_get_clean();

// Tittel
$titleMap = [
    '/'               => 'Meny',
    '/login'          => 'Logg inn',
    '/glemt-passord'  => 'Glemt passord',
    '/opprett-bruker' => 'Opprett ny bruker',

    '/uttak'          => 'Uttak',
    '/uttak-shop'     => 'Uttak – Plukk',
    '/uttak-checkout' => 'Uttak – Bekreft',
    '/uttak_shop'     => 'Uttak – Plukk',
    '/uttak_checkout' => 'Uttak – Bekreft',

    '/mine-uttak'     => 'Mine uttak',
    '/profil'         => 'Min side',
    '/passord'        => 'Bytt passord',
];
$pageTitle = $titleMap[$sub] ?? 'Lager';

$showNav = $isLoggedIn;

// Brukerinfo i navbar
$u = $_SESSION['lager_user'] ?? [];
$navName = (string)($u['fullname'] ?? $u['full_name'] ?? $u['name'] ?? $u['username'] ?? '');
if ($navName === '') $navName = 'Bruker';

?><!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> – Lager</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.3/dist/zephyr/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <style>
        :root { --lager-nav-h: <?= $showNav ? '64px' : '0px' ?>; }

        body {
            padding-top: var(--lager-nav-h);
            background: #fff;
        }

        .navbar {
            border-bottom: 1px solid rgba(0,0,0,.06);
        }
        .navbar .nav-link {
            border-radius: 10px;
            padding: .5rem .7rem;
        }
        .navbar .nav-link.active {
            background: rgba(13,110,253,.10);
            color: #0d6efd !important;
            font-weight: 600;
        }

        .brand-mark {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(13,110,253,.10);
            border: 1px solid rgba(13,110,253,.18);
            color: #0d6efd;
        }

        /* NØKKEL: maks bredde + “sorg-kanter” fjernes */
        .lager-wrap {
            max-width: 1600px;   /* bredere enn før */
            margin: 0 auto;
        }
        /* Stram padding: lite luft på sidene */
        .lager-main {
            padding-left: .5rem;
            padding-right: .5rem;
        }
        @media (min-width: 992px) {
            .lager-main {
                padding-left: .75rem;
                padding-right: .75rem;
            }
        }
    </style>
</head>
<body>

<?php if ($showNav): ?>
<nav class="navbar navbar-expand-lg bg-white fixed-top">
    <div class="container-fluid px-2">
        <div class="lager-wrap w-100 d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center gap-2 mb-0" href="<?= htmlspecialchars($href('/'), ENT_QUOTES, 'UTF-8') ?>">
                <span class="brand-mark"><i class="bi bi-box-seam"></i></span>
                <span class="fw-semibold">Lager</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#lagerNav"
                    aria-controls="lagerNav" aria-expanded="false" aria-label="Meny">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>

        <div class="collapse navbar-collapse" id="lagerNav">
            <div class="lager-wrap w-100 d-lg-flex align-items-center justify-content-between">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-1">
                    <li class="nav-item">
                        <a class="nav-link <?= $navActive('/') ?>" href="<?= htmlspecialchars($href('/'), ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-grid me-1"></i>Meny
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $navActive('/uttak') ?>" href="<?= htmlspecialchars($href('/uttak'), ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Uttak
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $navActive('/mine-uttak') ?>" href="<?= htmlspecialchars($href('/mine-uttak'), ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-clock-history me-1"></i>Mine uttak
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $navActive('/profil') ?>" href="<?= htmlspecialchars($href('/profil'), ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-person-circle me-1"></i>Min side
                        </a>
                    </li>
                </ul>

                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small d-none d-lg-inline">
                        <i class="bi bi-shield-lock me-1"></i><?= htmlspecialchars($navName, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($href('/logout'), ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bi bi-box-arrow-right me-1"></i>Logg ut
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>
<?php endif; ?>

<!-- NØKKEL: container-fluid + minimal padding + stor max-width -->
<main class="container-fluid lager-main py-2 py-lg-3">
    <div class="lager-wrap">
        <?= $content ?>
    </div>
</main>

</body>
</html>
