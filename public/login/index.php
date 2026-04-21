<?php
// public/login/index.php

declare(strict_types=1);

session_start();

require __DIR__ . '/../../vendor/autoload.php';

use App\Auth\AdLdap;
use App\Auth\EntraAuth;
use App\Auth\TwoFaStorage;
use App\Database;

// AD-konfig
$hosts  = ['bb-dc01', 'bb-dc02'];
$domain = 'bbdrift.ad';
$baseDn = 'dc=bbdrift,dc=ad';
$group  = 'teknisk';

$ad = new AdLdap($hosts, $domain, $baseDn, $group);

// Sjekk om Entra ID er aktivert
$entraEnabled = false;
$entraPdo     = null;
try {
    $entraPdo     = Database::getConnection();
    $entraEnabled = EntraAuth::isEnabled($entraPdo);
} catch (\Throwable $e) {
    // DB ikke tilgjengelig – ingen Entra-støtte
}

// --- Initiér Entra-innlogging (GET ?method=entra) ---
if (($_GET['method'] ?? '') === 'entra') {
    if (!$entraEnabled || $entraPdo === null) {
        header('Location: /login/?noaccess=1');
        exit;
    }

    $entra = EntraAuth::loadFromDb($entraPdo);
    if ($entra === null) {
        header('Location: /login/?noaccess=1');
        exit;
    }

    $state                    = bin2hex(random_bytes(16));
    $_SESSION['entra_state']  = $state;

    header('Location: ' . $entra->getAuthorizationUrl($state));
    exit;
}

// --- AD-innlogging (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    try {
        $authResult = $ad->authenticate($username, $password);

        if (isset($authResult['hasAccess']) && !$authResult['hasAccess']) {
            header('Location: /login/?noaccess=1');
            exit;
        }

        $twoFaEnabled = false;
        $twoFaSecret  = null;

        try {
            $pdo          = $entraPdo ?? Database::getConnection();
            $twoFaStorage = new TwoFaStorage($pdo);

            $userRow = $twoFaStorage->syncUserFromAd(
                $authResult['username'],
                $authResult['fullname'] ?? null,
                $authResult['groups'] ?? []
            );

            $twoFaEnabled = $userRow['twofa_enabled'];
            $twoFaSecret  = $userRow['twofa_secret'];
        } catch (\Throwable $dbEx) {
            error_log('Login DB-sync feilet for ' . ($authResult['username'] ?? '?') . ': ' . $dbEx->getMessage());
        }

        session_regenerate_id(true);

        $_SESSION['fullname']       = $authResult['fullname'] ?? $authResult['username'];
        $_SESSION['username']       = $authResult['username'];
        $_SESSION[$group]           = 'Yes';
        $_SESSION['ad_groups']      = $authResult['groups'] ?? [];
        $_SESSION['required_group'] = $group;
        $_SESSION['auth_provider']  = 'ad';

        $_SESSION['2fa_enabled']    = false;
        $_SESSION['2fa_configured'] = $twoFaEnabled && !empty($twoFaSecret);

        header('Location: /?page=start');
        exit;
    } catch (\Throwable $e) {
        error_log('Innlogging feilet: ' . $e->getMessage());
        header('Location: /login/?noaccess=1');
        exit;
    }
}

?>
<!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <title>Teknisk – Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --bg: #dbeafe;
            --bg-dark: #1e293b;
            --panel-blue: #1d4ed8;
            --panel-blue-soft: #2563eb;
            --panel-blue-light: #60a5fa;
            --card-bg: #ffffff;
            --border-subtle: #e5e7eb;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --accent: #2563eb;
            --accent-hover: #1d4ed8;
            --error-bg: #fef2f2;
            --error-border: #fecaca;
            --error-text: #b91c1c;
            --radius-lg: 18px;
            --radius-md: 12px;
            --radius-full: 999px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, #bfdbfe 0, transparent 55%),
                radial-gradient(circle at bottom right, #93c5fd 0, transparent 55%),
                var(--bg);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: var(--text-main);
        }

        .page-shell {
            width: 100%;
            max-width: 980px;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-radius: 32px;
            padding: 2px;
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.20);
        }

        .card {
            border-radius: 30px;
            background: rgba(248, 250, 252, 0.98);
            display: flex;
            overflow: hidden;
            min-height: 420px;
        }

        .side-info {
            flex: 0 0 40%;
            background:
                radial-gradient(circle at top left, rgba(248, 250, 252, 0.2), transparent 55%),
                linear-gradient(135deg, var(--panel-blue), var(--panel-blue-light));
            color: #f9fafb;
            padding: 32px 32px 36px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .brand { display: flex; align-items: center; gap: 12px; }

        .brand-logo {
            width: 40px; height: 40px;
            border-radius: 14px;
            background: linear-gradient(145deg, #eff6ff, #93c5fd);
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.35);
            font-weight: 700;
            color: var(--panel-blue);
            font-size: 18px;
        }

        .brand h2 { margin: 0; font-size: 22px; letter-spacing: 0.02em; }

        .side-footer { font-size: 11px; opacity: 0.9; }

        .form-pane {
            flex: 1;
            padding: 34px 34px 32px;
            background: var(--card-bg);
            border-left: 1px solid rgba(226, 232, 240, 0.9);
            display: flex;
            flex-direction: column;
        }

        .form-header { margin-bottom: 22px; }
        .form-header h1 { margin: 0 0 4px; font-size: 24px; }
        .form-header p { margin: 0; font-size: 14px; color: var(--text-muted); }

        .alert {
            border-radius: var(--radius-md);
            padding: 10px 12px;
            font-size: 13px;
            margin-bottom: 14px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .alert-error {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error-text);
        }

        .alert strong { display: block; margin-bottom: 2px; }
        .alert-icon { font-size: 16px; line-height: 1; margin-top: 1px; }

        form { display: flex; flex-direction: column; gap: 16px; }

        .field { display: flex; flex-direction: column; gap: 6px; }
        .field label { font-size: 13px; color: var(--text-main); font-weight: 500; }

        .field input {
            width: 100%;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-subtle);
            padding: 10px 12px;
            font-size: 14px;
            outline: none;
            background: #f9fafb;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
        }

        .field input::placeholder { color: #9ca3af; }

        .field input:focus {
            border-color: #93c5fd;
            background: #ffffff;
            box-shadow: 0 0 0 1px #bfdbfe;
        }

        .password-wrap { position: relative; }

        .password-toggle {
            position: absolute; right: 8px; top: 50%;
            transform: translateY(-50%);
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            padding: 3px 8px;
            background: #f9fafb;
            font-size: 11px;
            cursor: pointer;
            color: #4b5563;
        }

        .password-toggle:hover { background: #e5e7eb; }

        .submit-row { margin-top: 4px; }

        button[type="submit"] {
            width: 100%;
            border: none;
            border-radius: var(--radius-full);
            padding: 11px 16px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            background: linear-gradient(135deg, var(--accent), var(--panel-blue-soft));
            color: #f9fafb;
            box-shadow: 0 12px 25px rgba(37, 99, 235, 0.4);
            transition: transform 0.08s ease-out, box-shadow 0.08s ease-out, filter 0.08s ease-out;
        }

        button[type="submit"]:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 32px rgba(37, 99, 235, 0.45);
            filter: brightness(1.03);
        }

        button[type="submit"]:active {
            transform: translateY(0);
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.4);
            filter: brightness(0.99);
        }

        /* Skille mellom innloggingsmetoder */
        .auth-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0 16px;
            color: var(--text-muted);
            font-size: 12px;
        }

        .auth-divider::before,
        .auth-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border-subtle);
        }

        /* Microsoft-knapp */
        .btn-microsoft {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-full);
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            background: #ffffff;
            color: var(--text-main);
            text-decoration: none;
            transition: background 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }

        .btn-microsoft:hover {
            background: #f8faff;
            border-color: #bfdbfe;
            box-shadow: 0 4px 12px rgba(37,99,235,0.12);
        }

        .btn-microsoft svg { flex-shrink: 0; }

        .form-footer {
            margin-top: 12px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .form-footer a { color: var(--accent); text-decoration: none; }
        .form-footer a:hover { text-decoration: underline; }

        @media (max-width: 840px) {
            .card { flex-direction: column; }
            .side-info { flex-basis: auto; padding: 24px 22px 18px; }
            .form-pane { padding: 22px 22px 24px; }
            .page-shell { border-radius: 22px; }
        }

        @media (max-width: 540px) {
            body { padding: 12px; }
            .side-info { display: none; }
            .card { border-radius: 18px; }
            .form-pane { padding: 22px 18px 20px; }
            .form-header h1 { font-size: 20px; }
        }
    </style>
</head>
<body>
<div class="page-shell">
    <div class="card">
        <div class="side-info">
            <div>
                <div class="brand">
                    <div class="brand-logo">Tk</div>
                    <div>
                        <h2>Teknisk</h2>
                        <span style="font-size:12px; opacity:0.9;">Intern verktøykasse</span>
                    </div>
                </div>
            </div>

            <div class="side-footer">
                Teknisk Drift
            </div>
        </div>

        <div class="form-pane">
            <div class="form-header">
                <h1>Velkommen!</h1>
                <p>Logg inn med domenebrukernavn<?= $entraEnabled ? ' eller Microsoft-konto' : '' ?>.</p>
            </div>

            <?php if (isset($_GET['noaccess'])): ?>
                <div class="alert alert-error" role="alert">
                    <div class="alert-icon">!</div>
                    <div>
                        <strong>Pålogging feilet</strong>
                        <span>Kontroller brukernavn, passord og at du har nødvendige rettigheter.</span>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="/login/">
                <div class="field">
                    <label for="username">Brukernavn (BBDRIFT)</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="f.eks. ola.nordmann"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="field">
                    <label for="password">Passord</label>
                    <div class="password-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Ditt Windows-passord"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="password-toggle" id="togglePassword">Vis</button>
                    </div>
                </div>

                <div class="submit-row">
                    <button type="submit">Logg inn med AD</button>
                </div>
            </form>

            <?php if ($entraEnabled): ?>
                <div class="auth-divider">eller</div>

                <a href="/login/?method=entra" class="btn-microsoft">
                    <!-- Microsoft «M»-logo (fireruter) -->
                    <svg width="20" height="20" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <rect x="1"  y="1"  width="9" height="9" fill="#F25022"/>
                        <rect x="11" y="1"  width="9" height="9" fill="#7FBA00"/>
                        <rect x="1"  y="11" width="9" height="9" fill="#00A4EF"/>
                        <rect x="11" y="11" width="9" height="9" fill="#FFB900"/>
                    </svg>
                    Logg inn med Microsoft (Entra ID)
                </a>
            <?php endif; ?>

            <div class="form-footer">
                Problemer med pålogging? Ta kontakt med
                <a href="mailto:drift@hkbb.no">drift@hkbb.no</a>.
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        var toggle = document.getElementById('togglePassword');
        var input  = document.getElementById('password');

        if (toggle && input) {
            toggle.addEventListener('click', function () {
                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                toggle.textContent = isPassword ? 'Skjul' : 'Vis';
            });
        }
    })();
</script>
</body>
</html>
