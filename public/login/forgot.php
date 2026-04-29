<?php
// public/login/forgot.php – Steg 1: be om passordtilbakestilling

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../vendor/autoload.php';

// Last .env slik at AD_ADMIN_USER/PASS er tilgjengelig for LDAP-oppslag
$_dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$_dotenv->safeLoad();

use App\Auth\AdLdap;
use App\Auth\PasswordReset;
use App\Database;

$hosts  = ['bb-dc01', 'bb-dc02'];
$domain = 'bbdrift.ad';
$baseDn = 'dc=bbdrift,dc=ad';
$group  = 'teknisk';

$error   = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');

    if ($username === '') {
        $error = 'Skriv inn brukernavn.';
    } else {
        try {
            $ad    = new AdLdap($hosts, $domain, $baseDn, $group);
            $email = $ad->getUserEmail($username);

            if ($email === null) {
                // Gi ikke hint om at brukeren ikke finnes (security)
                $success = true;
            } else {
                $pdo      = Database::getConnection();
                $reset    = new PasswordReset($pdo);
                $baseUrl  = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                $reset->requestReset($username, $email, $baseUrl);
                $success = true;
            }
        } catch (\Throwable $e) {
            error_log('forgot.php: ' . $e->getMessage());
            $error = 'En feil oppstod. Prøv igjen eller kontakt drift@hkbb.no.';
        }
    }
}
?>
<!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <title>Glemt passord – Teknisk</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #dbeafe;
            --accent: #2563eb;
            --accent-hover: #1d4ed8;
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
            --radius: 14px;
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
        .card {
            background: var(--card-bg); border-radius: 20px;
            padding: 36px 32px 32px;
        }
        .back { display:inline-flex; align-items:center; gap:6px; font-size:13px;
                color:var(--muted); text-decoration:none; margin-bottom:20px; }
        .back:hover { color: var(--accent); }
        h1 { margin: 0 0 6px; font-size: 22px; letter-spacing: -0.02em; }
        .sub { color: var(--muted); font-size: 14px; margin: 0 0 24px; }
        .alert {
            padding: 10px 14px; border-radius: 10px;
            font-size: 13px; margin-bottom: 16px; line-height: 1.5;
        }
        .alert-error { background:var(--error-bg); border:1px solid var(--error-border); color:var(--error-text); }
        .alert-success { background:var(--success-bg); border:1px solid var(--success-border); color:var(--success-text); }
        label { display:block; font-size:13px; font-weight:500; margin-bottom:6px; }
        input[type=text] {
            width:100%; border:1px solid var(--border); border-radius:10px;
            padding:10px 12px; font-size:14px; outline:none; background:#f9fafb;
            transition: border-color .15s, box-shadow .15s;
        }
        input[type=text]:focus { border-color:#93c5fd; background:#fff; box-shadow:0 0 0 1px #bfdbfe; }
        .hint { font-size:12px; color:var(--muted); margin: 6px 0 20px; }
        button {
            width:100%; border:none; border-radius:999px; padding:11px;
            font-size:14px; font-weight:600; cursor:pointer;
            background: linear-gradient(135deg, var(--accent), #3b82f6);
            color:#fff; box-shadow: 0 8px 20px rgba(37,99,235,0.35);
            transition: transform .08s, box-shadow .08s;
        }
        button:hover { transform:translateY(-1px); box-shadow:0 12px 28px rgba(37,99,235,0.4); }
        button:active { transform:none; }
    </style>
</head>
<body>
<div class="shell">
    <div class="card">
        <a href="/login/" class="back">← Tilbake til innlogging</a>

        <h1>Glemt passord?</h1>
        <p class="sub">Skriv inn ditt AD-brukernavn så sender vi en tilbakestillingslenke til e-postadressen registrert i AD.</p>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>Sjekk e-posten din.</strong><br>
                Hvis brukeren finnes i AD, har vi sendt en lenke for å tilbakestille passordet.
                Lenken er gyldig i 1 time.
            </div>
            <a href="/login/" style="display:block;text-align:center;margin-top:8px;font-size:13px;color:var(--accent);">Tilbake til innlogging</a>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="/login/forgot.php">
                <div>
                    <label for="username">AD-brukernavn</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="f.eks. ola.nordmann"
                        autocomplete="username"
                        value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        required
                        autofocus
                    >
                    <p class="hint">Domenebrukernavn uten @bbdrift.ad</p>
                </div>
                <button type="submit">Send tilbakestillingslenke</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
