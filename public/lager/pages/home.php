<?php
// public/lager/pages/home.php

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

$u = require_lager_login();

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Basepath (brukes hvis dere kjører under annet enn /lager)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/lager/index.php';
$base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($base === '') $base = '/';

$href = function (string $route) use ($base): string {
    if ($route === '/') return $base . '/';
    return $base . $route;
};

$fullName = (string)($u['fullname'] ?? $u['full_name'] ?? $u['name'] ?? $u['username'] ?? '');
$username = (string)($u['username'] ?? $u['user'] ?? '');

$dn = trim($fullName);
if ($dn === '') $dn = $username;

$initials = '';
if ($dn !== '') {
    $parts = preg_split('/\s+/', $dn) ?: [];
    $first = $parts[0] ?? '';
    $last  = $parts[count($parts) - 1] ?? '';
    if (function_exists('mb_substr')) {
        $initials = strtoupper(mb_substr($first, 0, 1, 'UTF-8') . mb_substr($last, 0, 1, 'UTF-8'));
        $initials = trim($initials);
        if ($initials === '') $initials = strtoupper(mb_substr($dn, 0, 1, 'UTF-8'));
    } else {
        $initials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
        $initials = trim($initials);
        if ($initials === '') $initials = strtoupper(substr($dn, 0, 1));
    }
}
if ($initials === '') $initials = 'U';

// "Min side" route (her ligger også bytt passord bak)
$myPageRoute = '/profil'; // endre til '/min-side' hvis dere har det
?>
<style>
    .lager-home-topbar {
        border: 1px solid rgba(0,0,0,.06);
        border-radius: 14px;
        padding: 10px 12px;
        background: #fff;
    }
    .lager-avatar {
        width: 36px;
        height: 36px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        background: rgba(13,110,253,.10);
        border: 1px solid rgba(13,110,253,.16);
        color: #0d6efd;
        flex: 0 0 auto;
        line-height: 1;
        font-size: .95rem;
    }
    .lager-card-action {
        border-radius: 14px;
        border: 1px solid rgba(0,0,0,.06);
        transition: transform .08s ease, box-shadow .08s ease, border-color .08s ease;
        height: 100%;
        background: #fff;
    }
    .lager-card-action:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 20px rgba(0,0,0,.06);
        border-color: rgba(13,110,253,.18);
    }
    .lager-icon {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(0,0,0,.04);
        border: 1px solid rgba(0,0,0,.06);
        flex: 0 0 auto;
    }
    .lager-icon.primary { background: rgba(13,110,253,.10); border-color: rgba(13,110,253,.18); }
    .lager-icon.success { background: rgba(25,135,84,.10); border-color: rgba(25,135,84,.18); }
    .lager-icon.secondary { background: rgba(108,117,125,.10); border-color: rgba(108,117,125,.18); }
</style>

<div class="container-fluid px-0">

    <!-- Kompakt toppseksjon (ikke stor "HEI-boks") -->
    <div class="lager-home-topbar mt-2">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="lager-avatar" title="<?= h($dn) ?>"><?= h($initials) ?></div>
                <div>
                    <div class="fw-semibold mb-0">Hei, <?= h($dn ?: 'bruker') ?></div>
                   
                </div>
            </div>

            <div class="text-muted small">
                <span class="d-none d-md-inline">Innlogget som</span>
                <strong><?= h($dn ?: $username) ?></strong>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <!-- Registrer uttak -->
        <div class="col-12 col-md-6 col-lg-4">
            <a class="text-decoration-none text-body" href="<?= h($href('/uttak')) ?>">
                <div class="card lager-card-action">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3">
                            <div class="lager-icon primary">
                                <i class="bi bi-box-arrow-up-right fs-4"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">Registrer uttak</div>
                                <div class="text-muted small mt-1">
                                    Velg lokasjon og prosjekt, plukk utstyr og bekreft.
                                </div>
                            </div>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <!-- Mine uttak -->
        <div class="col-12 col-md-6 col-lg-4">
            <a class="text-decoration-none text-body" href="<?= h($href('/mine-uttak')) ?>">
                <div class="card lager-card-action">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3">
                            <div class="lager-icon success">
                                <i class="bi bi-clock-history fs-4"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">Mine uttak</div>
                                <div class="text-muted small mt-1">
                                    Se historikk og finn tidligere registreringer.
                                </div>
                            </div>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <!-- Min side -->
        <div class="col-12 col-md-6 col-lg-4">
            <a class="text-decoration-none text-body" href="<?= h($href($myPageRoute)) ?>">
                <div class="card lager-card-action">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3">
                            <div class="lager-icon secondary">
                                <i class="bi bi-person-circle fs-4"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">Min side</div>
                                <div class="text-muted small mt-1">
                                    Profil og bytt passord.
                                </div>
                            </div>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="mt-3 text-muted small">
        Tips: Lagre denne siden som favoritt på mobilen for rask tilgang.
    </div>

</div>
