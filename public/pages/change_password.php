<?php
// /public/pages/change_password.php
//
// Bytt passord i lokal AD (self-service) via LDAPS.
// - Krever innlogging (session username/fullname)
// - CSRF-token i session
// - Validerer nytt passord lokalt
// - Binder som brukeren med gammelt passord (UPN)
// - Finner user DN via LDAP-søk
// - Endrer passord i AD ved å gjøre:
//      unicodePwd: DELETE old + ADD new (samme request)
//   Dette er AD-kompatibel "change password" metode og fungerer der replace ofte gir 50/insufficient access.
//
// Forutsetter:
// - LDAPS fungerer (du har verifisert RootDSE over LDAPS OK)
// - CA-bundle er konfigurert (LDAPCONF/LDAPTLS_CACERT)

declare(strict_types=1);

$username = $_SESSION['username'] ?? null;
$fullname = $_SESSION['fullname'] ?? null;

if (!$username || !$fullname) {
    header('Location: /login/');
    exit;
}

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

$message = null;
$error   = null;

if (empty($_SESSION['csrf_change_password'])) {
    $_SESSION['csrf_change_password'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_change_password'];

// --- AD-konfig (samme som login) ---
$hosts  = ['bb-dc01.bbdrift.ad', 'bb-dc02.bbdrift.ad'];
$domain = 'bbdrift.ad';
$baseDn = 'DC=bbdrift,DC=ad';

function ad_connect(array $hosts) {
    foreach ($hosts as $h) {
        $h = trim((string)$h);
        if ($h === '') continue;

        $conn = @ldap_connect('ldaps://' . $h . ':636');
        if ($conn === false) continue;

        @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
        }
        return $conn;
    }
    return false;
}

function ldap_diag($conn): string {
    $diag = '';
    if ($conn && defined('LDAP_OPT_DIAGNOSTIC_MESSAGE')) {
        $tmp = null;
        if (@ldap_get_option($conn, LDAP_OPT_DIAGNOSTIC_MESSAGE, $tmp) && is_string($tmp)) {
            $diag = trim($tmp);
        }
    }
    return $diag;
}

function ad_find_user_dn($conn, string $baseDn, string $username, string $domain): ?string {
    $u = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
    $upn = ldap_escape($username . '@' . $domain, '', LDAP_ESCAPE_FILTER);

    $filter = "(|(sAMAccountName={$u})(userPrincipalName={$upn}))";
    $attrs  = ['distinguishedName', 'dn'];

    $sr = @ldap_search($conn, $baseDn, $filter, $attrs);
    if ($sr === false) return null;

    $entries = @ldap_get_entries($conn, $sr);
    if (!is_array($entries) || ($entries['count'] ?? 0) < 1) return null;

    $dn = $entries[0]['dn'] ?? null;
    if (is_string($dn) && $dn !== '') return $dn;

    if (isset($entries[0]['distinguishedname'][0]) && is_string($entries[0]['distinguishedname'][0])) {
        return $entries[0]['distinguishedname'][0];
    }
    return null;
}

function ad_unicode_pwd_bin(string $password): string {
    // AD krever: "password" i anførselstegn, så UTF-16LE bytes
    $quoted = '"' . $password . '"';
    return mb_convert_encoding($quoted, 'UTF-16LE', 'UTF-8');
}

function ad_change_password_unicodePwd_batch($conn, string $userDn, string $oldPass, string $newPass): bool {
    if (!function_exists('ldap_modify_batch')) {
        // Fallback: kan implementeres med ldap_mod_del + ldap_mod_add, men batch er tryggest (samme request).
        return false;
    }

    $oldBin = ad_unicode_pwd_bin($oldPass);
    $newBin = ad_unicode_pwd_bin($newPass);

    $mods = [
        [
            'attrib'  => 'unicodePwd',
            'modtype' => LDAP_MODIFY_BATCH_REMOVE,
            'values'  => [$oldBin],
        ],
        [
            'attrib'  => 'unicodePwd',
            'modtype' => LDAP_MODIFY_BATCH_ADD,
            'values'  => [$newBin],
        ],
    ];

    return @ldap_modify_batch($conn, $userDn, $mods);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
        $error = 'Ugyldig forespørsel (CSRF). Oppdater siden og prøv igjen.';
    } else {
        $old  = (string)($_POST['old_password'] ?? '');
        $new1 = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['new_password2'] ?? '');

        $minLen = 10;
        $hasUpper = (bool)preg_match('/[A-ZÆØÅ]/u', $new1);
        $hasLower = (bool)preg_match('/[a-zæøå]/u', $new1);
        $hasDigit = (bool)preg_match('/\d/u', $new1);

        if ($old === '') {
            $error = 'Du må skrive inn gammelt passord.';
        } elseif (mb_strlen($new1, 'UTF-8') < $minLen) {
            $error = 'Nytt passord er for kort. Minimum ' . $minLen . ' tegn.';
        } elseif ($new1 !== $new2) {
            $error = 'Nytt passord og bekreftelse er ikke like.';
        } elseif (!$hasUpper || !$hasLower || !$hasDigit) {
            $error = 'Nytt passord må inneholde minst én stor bokstav, én liten bokstav og ett tall.';
        } elseif ($new1 === $old) {
            $error = 'Nytt passord må være forskjellig fra gammelt passord.';
        } else {
            $conn = ad_connect($hosts);
            if ($conn === false) {
                $error = 'Kunne ikke koble til AD over LDAPS (ingen DC tilgjengelig).';
            } else {
                // Bind som bruker
                $bindUser = $username . '@' . $domain;
                $bindOk = @ldap_bind($conn, $bindUser, $old);

                if (!$bindOk) {
                    $errno = @ldap_errno($conn);
                    $err   = @ldap_error($conn);
                    $diag  = ldap_diag($conn);
                    $error = "Kunne ikke autentisere med gammelt passord. ($errno) $err" . ($diag ? " Diagnose: $diag" : "");
                } else {
                    $userDn = ad_find_user_dn($conn, $baseDn, $username, $domain);
                    if (!$userDn) {
                        $error = 'Fant ikke brukeren i AD (DN lookup feilet).';
                    } else {
                        $ok = ad_change_password_unicodePwd_batch($conn, $userDn, $old, $new1);

                        if ($ok) {
                            $_SESSION['csrf_change_password'] = bin2hex(random_bytes(32));
                            $csrf = (string)$_SESSION['csrf_change_password'];

                            $message = 'Passordet ble endret i AD. Neste innlogging må bruke nytt passord.';
                        } else {
                            $errno = @ldap_errno($conn);
                            $err   = @ldap_error($conn);
                            $diag  = ldap_diag($conn);

                            if (!function_exists('ldap_modify_batch')) {
                                $error = 'PHP LDAP mangler ldap_modify_batch(). Oppgrader/aktiver ldap-ext, eller si ifra så lager jeg fallback.';
                            } else {
                                $error = "Kunne ikke endre passord i AD. ($errno) $err" . ($diag ? " Diagnose: $diag" : "");
                            }
                        }
                    }
                }

                @ldap_unbind($conn);
            }
        }
    }
}
?>
<div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">Bytt passord</h1>
        <div class="text-muted small">
            Innlogget som <strong><?php echo h($fullname); ?></strong> (<code><?php echo h($username); ?></code>)
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="/?page=minside" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Tilbake til Min side
        </a>
        <a href="/?page=logout" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-box-arrow-right me-1"></i> Logg ut
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo h($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo h($error); ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="change_password" value="1">

                    <div class="mb-3">
                        <label class="form-label">Gammelt passord</label>
                        <input type="password" name="old_password" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nytt passord</label>
                        <input type="password" name="new_password" class="form-control" required>
                        <div class="form-text">
                            Minimum 10 tegn. Må inneholde minst én stor bokstav, én liten bokstav og ett tall.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Bekreft nytt passord</label>
                        <input type="password" name="new_password2" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2 me-1"></i> Endre passord i AD
                    </button>
                </form>

                <div class="small text-muted mt-3">
                    Teknisk: AD-passordendring gjøres som “delete old + add new” på <code>unicodePwd</code> over LDAPS.
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-2">Hvis det feiler</h2>
                <ul class="small mb-0">
                    <li><strong>Constraint violation</strong>: passordpolicy (kompleksitet/historikk/min alder).</li>
                    <li><strong>Insufficient access</strong>: “Change password” kan være fjernet via ACL.</li>
                    <li><strong>Unwilling to perform</strong>: ikke LDAPS eller feil format (UTF-16LE/anførselstegn).</li>
                </ul>
            </div>
        </div>
    </div>
</div>
