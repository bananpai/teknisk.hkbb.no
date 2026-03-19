<?php
// public/lager/pages/glemt_passord.php
//
// 3-stegs "glemt passord" på SAMME side:
// 1) Bruker skriver ident (epost/brukernavn eller mobil) -> får midlertidig passord på SMS
// 2) Bruker skriver ident + midlertidig passord -> valideres
// 3) Bruker skriver nytt passord (to ganger) -> erstatter gammelt
//
// NB: Vi lekker ikke om bruker finnes i steg 1.
// NB: Midlertidig passord lagres i password-feltet (slik login.php bruker det).
// NB: must_change_password settes til 1 ved utsendelse og 0 etter vellykket bytte.

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

$pdo = get_pdo();

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $cols = table_columns($pdo, $table);
        return in_array($col, $cols, true);
    } catch (\Throwable $e) {
        return false;
    }
}

function ensure_must_change_password(PDO $pdo): void {
    try {
        if (!col_exists($pdo, 'lager_users', 'must_change_password')) {
            $pdo->exec("ALTER TABLE lager_users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (\Throwable $e) {
        // Ikke stopp flyten hvis ALTER feiler
    }
}

function normalize_phone(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^\d+]/', '', $s) ?? '';
    return $s;
}

function random_password(int $len = 10): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function send_sms_via_file(string $phone, string $message): void {
    // Krav: C:\SMS\send\SMS.txt med "<mobilnummer> <melding>"
    $dir  = 'C:\\SMS\\send';
    $file = $dir . '\\SMS.txt';

    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $message = str_replace(["\r", "\n"], ' ', $message);
    $line = trim($phone) . ' ' . trim($message);

    file_put_contents($file, $line, LOCK_EX);
}

function find_user_by_ident(PDO $pdo, string $ident): ?array {
    $ident = trim($ident);
    $identPhone = normalize_phone($ident);

    $stmt = $pdo->prepare("
        SELECT *
        FROM lager_users
        WHERE username = ?
           OR email = ?
           OR mobilnr = ?
        LIMIT 1
    ");
    $stmt->execute([$ident, $ident, $identPhone]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    return $u ?: null;
}

function ensure_csrf_token(): string {
    if (!isset($_SESSION['pwreset_csrf']) || !is_string($_SESSION['pwreset_csrf']) || $_SESSION['pwreset_csrf'] === '') {
        $_SESSION['pwreset_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['pwreset_csrf'];
}

function check_csrf(?string $token): bool {
    $sess = $_SESSION['pwreset_csrf'] ?? '';
    return is_string($token) && is_string($sess) && $token !== '' && hash_equals($sess, $token);
}

// -------------------------------------------------------------

ensure_must_change_password($pdo);

$errors  = [];
$ok      = '';
$step    = 'request'; // request | verify | setnew | done

// Hvis vi allerede har verifisert bruker i denne sesjonen:
if (!empty($_SESSION['pwreset_verified_uid'])) {
    $step = 'setnew';
}

$csrf = ensure_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // CSRF (gjelder alle POST her)
    if (!check_csrf((string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Ugyldig forespørsel. Prøv igjen.';
        $action = ''; // stopp videre behandling
    }

    // 1) Send midlertidig passord
    if ($action === 'send_tmp') {
        $ident = trim((string)($_POST['ident'] ?? ''));
        if ($ident === '') {
            $errors[] = 'Skriv inn e-post/brukernavn eller mobilnummer.';
            $step = 'request';
        } else {
            // Generisk OK-melding uansett (for ikke å lekke om bruker finnes)
            $ok = 'Hvis brukeren finnes og har mobil registrert, sendes SMS med midlertidig passord. Skriv deretter inn koden under for å velge nytt passord.';
            $step = 'verify';

            $u = find_user_by_ident($pdo, $ident);

            if ($u) {
                $isApproved = (int)($u['is_approved'] ?? 0) === 1;
                $isActive   = (int)($u['is_active'] ?? 1) === 1;
                $phone      = normalize_phone((string)($u['mobilnr'] ?? ''));

                if ($isApproved && $isActive && $phone !== '') {
                    $tmp  = random_password(10);
                    $hash = password_hash($tmp, PASSWORD_DEFAULT);

                    // Oppdater passord (mellomliggende) + must_change_password
                    try {
                        if (col_exists($pdo, 'lager_users', 'must_change_password')) {
                            $pdo->prepare("UPDATE lager_users SET password = ?, must_change_password = 1 WHERE id = ?")
                                ->execute([$hash, (int)$u['id']]);
                        } else {
                            $pdo->prepare("UPDATE lager_users SET password = ? WHERE id = ?")
                                ->execute([$hash, (int)$u['id']]);
                        }
                    } catch (\Throwable $e) {
                        // vis fortsatt generisk OK
                    }

                    // Send SMS
                    try {
                        $msg = "Midlertidig passord: {$tmp}. Bruk det for å velge nytt passord.";
                        send_sms_via_file($phone, $msg);
                    } catch (\Throwable $e) {
                        // vis fortsatt generisk OK
                    }
                }
            }

            // Forbedre UX: behold ident i neste steg
            $_POST['ident'] = $ident;
        }
    }

    // 2) Verifiser midlertidig passord
    if ($action === 'verify_tmp') {
        $ident = trim((string)($_POST['ident'] ?? ''));
        $tmpPw = (string)($_POST['tmp_password'] ?? '');

        if ($ident === '' || trim($tmpPw) === '') {
            $errors[] = 'Skriv inn ident og midlertidig passord.';
            $step = 'verify';
        } else {
            $u = find_user_by_ident($pdo, $ident);

            $isOk = false;
            if ($u) {
                $isApproved = (int)($u['is_approved'] ?? 0) === 1;
                $isActive   = (int)($u['is_active'] ?? 1) === 1;
                $hash       = (string)($u['password'] ?? '');

                if ($isApproved && $isActive && $hash !== '' && password_verify($tmpPw, $hash)) {
                    $isOk = true;
                }
            }

            if (!$isOk) {
                $errors[] = 'Ugyldig ident eller midlertidig passord.';
                $step = 'verify';
            } else {
                // Markér som verifisert i sesjonen (så kan vi sette nytt passord)
                $_SESSION['pwreset_verified_uid'] = (int)$u['id'];
                $_SESSION['pwreset_verified_ident'] = $ident;

                $ok = 'Bruker verifisert. Velg nytt passord under.';
                $step = 'setnew';
            }
        }
    }

    // 3) Sett nytt passord
    if ($action === 'set_new') {
        $uid = (int)($_SESSION['pwreset_verified_uid'] ?? 0);
        if ($uid <= 0) {
            $errors[] = 'Sesjonen er utløpt. Start prosessen på nytt.';
            $step = 'request';
        } else {
            $pw1 = (string)($_POST['new_password'] ?? '');
            $pw2 = (string)($_POST['new_password2'] ?? '');

            if (strlen($pw1) < 8) {
                $errors[] = 'Passordet må være minst 8 tegn.';
                $step = 'setnew';
            } elseif ($pw1 !== $pw2) {
                $errors[] = 'Passordene er ikke like.';
                $step = 'setnew';
            } else {
                $hash = password_hash($pw1, PASSWORD_DEFAULT);

                try {
                    if (col_exists($pdo, 'lager_users', 'must_change_password')) {
                        $pdo->prepare("UPDATE lager_users SET password = ?, must_change_password = 0 WHERE id = ?")
                            ->execute([$hash, $uid]);
                    } else {
                        $pdo->prepare("UPDATE lager_users SET password = ? WHERE id = ?")
                            ->execute([$hash, $uid]);
                    }

                    // Rydd sesjonsdata
                    unset($_SESSION['pwreset_verified_uid'], $_SESSION['pwreset_verified_ident']);
                    unset($_SESSION['pwreset_csrf']);

                    $ok = 'Passordet er oppdatert. Du kan nå logge inn med ditt nye passord.';
                    $step = 'done';
                } catch (\Throwable $e) {
                    $errors[] = 'Kunne ikke oppdatere passordet. Prøv igjen.';
                    $step = 'setnew';
                }
            }
        }
    }
}

// Hvis vi har OK-melding fra før og ikke er i done, behold step satt.
// Standard: request
?>

<style>
  body{font-family:system-ui;margin:0;background:#f6f7f9}
  .wrap{max-width:420px;margin:40px auto;padding:16px}
  .card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 6px rgba(0,0,0,.06)}
  label{display:block;margin-top:12px;font-weight:600}
  input{width:100%;padding:12px;border:1px solid #d0d5dd;border-radius:10px;font-size:16px}
  button{margin-top:14px;width:100%;padding:12px;border:0;border-radius:10px;font-weight:700;font-size:16px;cursor:pointer;background:#111827;color:#fff}
  .err{background:#fff0f0;border:1px solid #ffcccc;padding:10px;border-radius:10px;margin-bottom:12px}
  .ok{background:#ecfdf3;border:1px solid #abefc6;padding:10px;border-radius:10px;margin-bottom:12px}
  .muted{color:#667085;font-size:14px}
  .links{margin-top:14px;display:flex;flex-direction:column;gap:10px}
  .a-btn{display:block;text-align:center;padding:12px;border:1px solid #d0d5dd;border-radius:10px;text-decoration:none;color:#111827;font-weight:700;background:#fff}
  .a-btn:hover{background:#f9fafb}
  .sep{height:1px;background:#eef2f7;margin:16px 0}
</style>

<div class="wrap">
  <div class="card">
    <h2 style="margin:0 0 6px 0;">Glemt passord</h2>

    <?php if ($step === 'request'): ?>
      <div class="muted">Skriv inn e-post/brukernavn eller mobilnummer. Du får et midlertidig passord på SMS.</div>
    <?php elseif ($step === 'verify'): ?>
      <div class="muted">Skriv inn ident + midlertidig passord fra SMS for å verifisere deg.</div>
    <?php elseif ($step === 'setnew'): ?>
      <div class="muted">Velg et nytt passord som erstatter det gamle.</div>
    <?php else: ?>
      <div class="muted">Ferdig.</div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="err" style="margin-top:12px;">
        <?php foreach ($errors as $e): ?>
          <div><?= h($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($ok): ?>
      <div class="ok" style="margin-top:12px;">
        <?= h($ok) ?>
      </div>
    <?php endif; ?>

    <?php if ($step === 'request'): ?>
      <form method="post" autocomplete="off" style="margin-top:12px;">
        <input type="hidden" name="action" value="send_tmp">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <label for="ident">E-post / brukernavn eller mobilnummer</label>
        <input id="ident" name="ident" autofocus required value="<?= h((string)($_POST['ident'] ?? '')) ?>">

        <button type="submit">Send midlertidig passord</button>

        <div class="links">
          <a class="a-btn" href="/lager/login">Tilbake til login</a>
        </div>
      </form>

    <?php elseif ($step === 'verify'): ?>
      <form method="post" autocomplete="off" style="margin-top:12px;">
        <input type="hidden" name="action" value="verify_tmp">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <label for="ident">E-post / brukernavn eller mobilnummer</label>
        <input id="ident" name="ident" required value="<?= h((string)($_POST['ident'] ?? ($_SESSION['pwreset_verified_ident'] ?? ''))) ?>">

        <label for="tmp_password">Midlertidig passord (fra SMS)</label>
        <input id="tmp_password" name="tmp_password" required type="password" autocomplete="one-time-code">

        <button type="submit">Verifiser</button>

        <div class="sep"></div>

        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="send_tmp">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="ident" value="<?= h((string)($_POST['ident'] ?? '')) ?>">
          <button type="submit" style="background:#0f172a;">Send ny kode</button>
        </form>

        <div class="links">
          <a class="a-btn" href="/lager/login">Tilbake til login</a>
        </div>
      </form>

    <?php elseif ($step === 'setnew'): ?>
      <form method="post" autocomplete="off" style="margin-top:12px;">
        <input type="hidden" name="action" value="set_new">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <label for="new_password">Nytt passord (minst 8 tegn)</label>
        <input id="new_password" name="new_password" required type="password" autocomplete="new-password">

        <label for="new_password2">Gjenta nytt passord</label>
        <input id="new_password2" name="new_password2" required type="password" autocomplete="new-password">

        <button type="submit">Oppdater passord</button>

        <div class="links">
          <a class="a-btn" href="/lager/login">Tilbake til login</a>
        </div>
      </form>

    <?php else: /* done */ ?>
      <div class="links" style="margin-top:12px;">
        <a class="a-btn" href="/lager/login">Gå til login</a>
      </div>
    <?php endif; ?>

  </div>
</div>
