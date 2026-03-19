<?php
// public/lager/pages/login.php

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

$pdo = get_pdo();

$errors = [];
$step2fa = false;

// Hvis allerede innlogget:
if (lager_user()) {
    redirect('/lager');
}

function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $cols = table_columns($pdo, $table);
        return in_array($col, $cols, true);
    } catch (\Throwable $e) {
        return false;
    }
}

function must_change_password(PDO $pdo, array $u): bool {
    if (!isset($u['id'])) return false;
    if (!col_exists($pdo, 'lager_users', 'must_change_password')) return false;
    return (int)($u['must_change_password'] ?? 0) === 1;
}

// STEP 2: 2FA kode
if (($_SESSION['lager_pending_user_id'] ?? 0) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_code'])) {
    $step2fa = true;

    $pendingId = (int)$_SESSION['lager_pending_user_id'];
    $code = trim((string)($_POST['totp_code'] ?? ''));

    $stmt = $pdo->prepare("SELECT * FROM lager_users WHERE id = ? LIMIT 1");
    $stmt->execute([$pendingId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        unset($_SESSION['lager_pending_user_id']);
        $errors[] = 'Ugyldig sesjon. Prøv igjen.';
    } elseif ((int)($u['is_approved'] ?? 0) !== 1) {
        unset($_SESSION['lager_pending_user_id']);
        $errors[] = 'Brukeren er ikke godkjent ennå.';
    } elseif ((int)($u['is_active'] ?? 1) !== 1) {
        unset($_SESSION['lager_pending_user_id']);
        $errors[] = 'Brukeren er deaktivert.';
    } elseif ((int)($u['is_2fa_enabled'] ?? 0) !== 1 || empty($u['google_auth_secret'])) {
        unset($_SESSION['lager_pending_user_id']);
        $errors[] = '2FA er ikke korrekt satt opp for denne brukeren.';
    } elseif (!totp_verify((string)$u['google_auth_secret'], $code, 1)) {
        $errors[] = 'Feil 2FA-kode.';
    } else {
        // Innlogging OK
        unset($_SESSION['lager_pending_user_id']);
        $_SESSION['lager_user'] = [
            'id' => (int)$u['id'],
            'username' => (string)$u['username'],
            'fullname' => (string)$u['fullname'],
            'entreprenor' => (string)$u['entreprenor'],
            'mobilnr' => (string)$u['mobilnr'],
            'email' => (string)($u['email'] ?? ''),
            'is_admin' => (int)($u['is_admin'] ?? 0),
        ];

        // Oppdater last_login_time hvis feltet finnes
        try {
            $cols = table_columns($pdo, 'lager_users');
            if (in_array('last_login_time', $cols, true)) {
                $pdo->prepare("UPDATE lager_users SET last_login_time = NOW() WHERE id = ?")->execute([(int)$u['id']]);
            }
        } catch (\Throwable $e) { /* ignore */ }

        // Må bytte passord?
        if (must_change_password($pdo, $u)) {
            redirect('/lager/passord');
        }

        redirect('/lager');
    }
}

// STEP 1: brukernavn + passord
if (!($_SESSION['lager_pending_user_id'] ?? 0) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    $username = trim((string)$_POST['username']);
    $password = (string)$_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM lager_users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    // NB: Din gamle DB bruker kolonnen "password" (hash)
    if (!$u || !password_verify($password, (string)$u['password'])) {
        $errors[] = 'Feil brukernavn eller passord.';
    } elseif ((int)($u['is_approved'] ?? 0) !== 1) {
        $errors[] = 'Brukeren er ikke godkjent ennå.';
    } elseif ((int)($u['is_active'] ?? 1) !== 1) {
        $errors[] = 'Brukeren er deaktivert.';
    } else {
        $is2fa = (int)($u['is_2fa_enabled'] ?? 0) === 1 && !empty($u['google_auth_secret']);

        if ($is2fa) {
            $_SESSION['lager_pending_user_id'] = (int)$u['id'];
            $step2fa = true;
        } else {
            $_SESSION['lager_user'] = [
                'id' => (int)$u['id'],
                'username' => (string)$u['username'],
                'fullname' => (string)$u['fullname'],
                'entreprenor' => (string)$u['entreprenor'],
                'mobilnr' => (string)$u['mobilnr'],
                'email' => (string)($u['email'] ?? ''),
                'is_admin' => (int)($u['is_admin'] ?? 0),
            ];

            try {
                $cols = table_columns($pdo, 'lager_users');
                if (in_array('last_login_time', $cols, true)) {
                    $pdo->prepare("UPDATE lager_users SET last_login_time = NOW() WHERE id = ?")->execute([(int)$u['id']]);
                }
            } catch (\Throwable $e) { /* ignore */ }

            // Må bytte passord?
            if (must_change_password($pdo, $u)) {
                redirect('/lager/passord');
            }

            redirect('/lager');
        }
    }
}

if (($_SESSION['lager_pending_user_id'] ?? 0) && !$step2fa) {
    $step2fa = true;
}

?>
<style>
  body{font-family:system-ui;margin:0;background:#f6f7f9}
  .wrap{max-width:420px;margin:40px auto;padding:16px}
  .card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 6px rgba(0,0,0,.06)}
  label{display:block;margin-top:12px;font-weight:600}
  input{width:100%;padding:12px;border:1px solid #d0d5dd;border-radius:10px;font-size:16px}
  button{margin-top:14px;width:100%;padding:12px;border:0;border-radius:10px;font-weight:700;font-size:16px;cursor:pointer;background:#111827;color:#fff}
  .err{background:#fff0f0;border:1px solid #ffcccc;padding:10px;border-radius:10px;margin-bottom:12px}
  .muted{color:#667085;font-size:14px}
  .links{margin-top:14px;display:flex;flex-direction:column;gap:10px}
  .a-btn{display:block;text-align:center;padding:12px;border:1px solid #d0d5dd;border-radius:10px;text-decoration:none;color:#111827;font-weight:700;background:#fff}
  .a-btn:hover{background:#f9fafb}
</style>

<div class="wrap">
  <div class="card">
    <h2 style="margin:0 0 6px 0;">Lager</h2>
    <div class="muted">Logg inn for å registrere uttak.</div>

    <?php if ($errors): ?>
      <div class="err" style="margin-top:12px;">
        <?php foreach ($errors as $e): ?>
          <div><?= h($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($step2fa): ?>
      <form method="post" autocomplete="one-time-code">
        <label for="totp_code">2FA-kode (6 siffer)</label>
        <input id="totp_code" name="totp_code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autofocus required>
        <button type="submit">Bekreft</button>

        <div class="muted" style="margin-top:10px;">
          <a href="/lager/logout">Avbryt</a>
        </div>

        <div class="links">
          <a class="a-btn" href="/lager/glemt-passord">Reset passord</a>
          <a class="a-btn" href="/lager/opprett-bruker">Opprett ny bruker</a>
        </div>
      </form>
    <?php else: ?>
      <form method="post" autocomplete="on">
        <label for="username">Brukernavn</label>
        <input id="username" name="username" autocomplete="username" autofocus required>

        <label for="password">Passord</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button type="submit">Logg inn</button>

        <div class="links">
          <a class="a-btn" href="/lager/glemt-passord">Reset passord</a>
          <a class="a-btn" href="/lager/opprett-bruker">Opprett ny bruker</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
