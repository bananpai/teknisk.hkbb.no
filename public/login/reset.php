<?php
// public/login/reset.php – Steg 2: sett nytt passord

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../vendor/autoload.php';

$_dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$_dotenv->safeLoad();

use App\Auth\AdLdap;
use App\Auth\PasswordReset;
use App\Database;

$hosts  = ['bb-dc01', 'bb-dc02'];
$domain = 'bbdrift.ad';
$baseDn = 'dc=bbdrift,dc=ad';
$group  = 'teknisk';

$rawToken = trim($_GET['token'] ?? '');
$error    = null;
$success  = false;
$userData = null;

try {
    $pdo   = Database::getConnection();
    $reset = new PasswordReset($pdo);
} catch (\Throwable $e) {
    $error = 'Intern feil – kontakt drift@hkbb.no.';
}

// Valider token
if (!$error && $rawToken !== '') {
    $userData = $reset->validateToken($rawToken);
    if ($userData === null) {
        $error = 'Lenken er ugyldig eller har utløpt. Be om en ny tilbakestillingslenke.';
    }
} elseif (!$error) {
    $error = 'Mangler token i URL-en.';
}

// Håndter POST (nytt passord)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && $userData !== null) {
    $password1 = $_POST['password1'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($password1 === '') {
        $error = 'Passord kan ikke være tomt.';
    } elseif ($password1 !== $password2) {
        $error = 'Passordene er ikke like.';
    } elseif (strlen($password1) < 8) {
        $error = 'Passordet må være minst 8 tegn.';
    } elseif (!preg_match('/[A-Z]/', $password1) ||
              !preg_match('/[a-z]/', $password1) ||
              !preg_match('/[0-9\W_]/', $password1)) {
        $error = 'Passordet må inneholde store og små bokstaver samt minst ett tall eller spesialtegn.';
    } else {
        try {
            $ad = new AdLdap($hosts, $domain, $baseDn, $group);
            $ad->setPasswordAsAdmin($userData['username'], $password1);
            $reset->consumeToken($rawToken);
            $success = true;
        } catch (\Throwable $e) {
            error_log('reset.php passordbytte feilet: ' . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <title>Nytt passord – Teknisk</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #dbeafe;
            --accent: #2563eb;
            --card-bg: #ffffff;
            --border: #e5e7eb;
            --text: #0f172a;
            --muted: #6b7280;
            --error-bg: #fef2f2;
            --error-border: #fecaca;
            --error-text: #b91c1c;
            --success-bg: #f0fdf4;
            --success-border: #bbf7d0;
            --success-text: #166534;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top left, #bfdbfe 0, transparent 55%),
                        radial-gradient(circle at bottom right, #93c5fd 0, transparent 55%),
                        var(--bg);
            display: flex; align-items: center; justify-content: center; padding: 24px;
            color: var(--text);
        }
        .shell {
            width: 100%; max-width: 420px;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-radius: 22px; padding: 2px;
            box-shadow: 0 20px 48px rgba(15,23,42,0.18);
        }
        .card { background: var(--card-bg); border-radius: 20px; padding: 36px 32px 32px; }
        h1 { margin: 0 0 6px; font-size: 22px; letter-spacing: -0.02em; }
        .sub { color: var(--muted); font-size: 14px; margin: 0 0 24px; }
        .alert {
            padding: 10px 14px; border-radius: 10px;
            font-size: 13px; margin-bottom: 16px; line-height: 1.5;
        }
        .alert-error   { background:var(--error-bg); border:1px solid var(--error-border); color:var(--error-text); }
        .alert-success { background:var(--success-bg); border:1px solid var(--success-border); color:var(--success-text); }
        .field { margin-bottom: 16px; }
        label { display:block; font-size:13px; font-weight:500; margin-bottom:6px; }
        input[type=password] {
            width:100%; border:1px solid var(--border); border-radius:10px;
            padding:10px 12px; font-size:14px; outline:none; background:#f9fafb;
            transition: border-color .15s, box-shadow .15s;
        }
        input:focus { border-color:#93c5fd; background:#fff; box-shadow:0 0 0 1px #bfdbfe; }
        .reqs { font-size:11px; color:var(--muted); margin: 6px 0 0; line-height:1.6; }
        button {
            width:100%; border:none; border-radius:999px; padding:11px; margin-top:8px;
            font-size:14px; font-weight:600; cursor:pointer;
            background: linear-gradient(135deg, var(--accent), #3b82f6);
            color:#fff; box-shadow: 0 8px 20px rgba(37,99,235,0.35);
            transition: transform .08s, box-shadow .08s;
        }
        button:hover { transform:translateY(-1px); box-shadow:0 12px 28px rgba(37,99,235,0.4); }
        .back { display:inline-flex; align-items:center; gap:6px; font-size:13px;
                color:var(--muted); text-decoration:none; margin-bottom:20px; }
        .back:hover { color: var(--accent); }
    </style>
</head>
<body>
<div class="shell">
    <div class="card">

        <?php if ($success): ?>
            <h1>Passord oppdatert!</h1>
            <p class="sub">Passordet ditt er nå endret i AD. Du kan logge inn med det nye passordet.</p>
            <div class="alert alert-success">
                Passordet er endret for <strong><?= htmlspecialchars($userData['username'], ENT_QUOTES, 'UTF-8') ?></strong>.
            </div>
            <a href="/login/" style="display:block;text-align:center;margin-top:8px;font-size:14px;color:var(--accent);font-weight:500;">
                Gå til innlogging →
            </a>

        <?php elseif ($userData === null || $error && $userData === null): ?>
            <a href="/login/forgot.php" class="back">← Be om ny lenke</a>
            <h1>Lenke ugyldig</h1>
            <div class="alert alert-error">
                <?= htmlspecialchars($error ?? 'Ugyldig lenke.', ENT_QUOTES, 'UTF-8') ?>
            </div>

        <?php else: ?>
            <a href="/login/" class="back">← Tilbake til innlogging</a>
            <h1>Sett nytt passord</h1>
            <p class="sub">
                Konto: <strong><?= htmlspecialchars($userData['username'], ENT_QUOTES, 'UTF-8') ?></strong>
            </p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="/login/reset.php?token=<?= urlencode($rawToken) ?>">
                <div class="field">
                    <label for="pw1">Nytt passord</label>
                    <input type="password" id="pw1" name="password1" required autofocus>
                    <p class="reqs">
                        Minst 8 tegn &middot; Store og små bokstaver &middot; Tall eller spesialtegn
                    </p>
                </div>
                <div class="field">
                    <label for="pw2">Bekreft nytt passord</label>
                    <input type="password" id="pw2" name="password2" required>
                </div>
                <button type="submit">Sett nytt passord</button>
            </form>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
