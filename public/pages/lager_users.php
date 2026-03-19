<?php
// public/pages/lager_users.php
//
// Admin-side: Lagerbrukere (lager_users)
// - Liste, søk, opprett, rediger, godkjenn/av-godkjenn, toggle admin, reset passord, 2FA av (tøm secret)
// - Krever rolle "admin" (samme modell som menu.php basert på user_roles)
//
// Forutsetter tabell:
//   lager_users (som du har definert)
//
// NB: Passord lagres som password_hash()

use App\Database;

$pdo = Database::getConnection();

$username = $_SESSION['username'] ?? '';
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

if (!function_exists('normalize_list')) {
    function normalize_list($v): array {
        if (is_array($v)) return array_values(array_filter(array_map('strval', $v)));
        if (is_string($v) && trim($v) !== '') {
            $parts = preg_split('/[,\s;]+/', $v);
            return array_values(array_filter(array_map('strval', $parts)));
        }
        return [];
    }
}
if (!function_exists('has_any')) {
    function has_any(array $needles, array $haystack): bool {
        $haystack = array_map('strtolower', $haystack);
        foreach ($needles as $n) {
            if (in_array(strtolower($n), $haystack, true)) return true;
        }
        return false;
    }
}
if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Hent roller fra session + DB (user_roles) slik som i menu.php
$roles = normalize_list($_SESSION['roles'] ?? null);

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $userId = 0;
    if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $userId = (int)($u['id'] ?? 0);
    }
    if ($userId > 0) {
        $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $dbRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $roles = array_merge($roles, normalize_list($dbRoles));
    }
} catch (\Throwable $e) {
    // ok
}

$roles = array_values(array_unique(array_map('strtolower', $roles)));
$isAdmin = has_any(['admin'], $roles);

if (!$isAdmin) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function random_password(int $len = 10): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789abcdefghijkmnpqrstuvwxyz';
    $out = '';
    for ($i=0; $i<$len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet)-1)];
    }
    return $out;
}

// ---------------------------------------------------------
// Handle actions (POST)
// ---------------------------------------------------------
$errors = [];
$success = null;
$showTempPassword = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $new = [
                'username'    => trim((string)($_POST['username'] ?? '')),
                'email'       => trim((string)($_POST['email'] ?? '')),
                'fullname'    => trim((string)($_POST['fullname'] ?? '')),
                'entreprenor' => trim((string)($_POST['entreprenor'] ?? '')),
                'mobilnr'     => trim((string)($_POST['mobilnr'] ?? '')),
                'office'      => trim((string)($_POST['office'] ?? '')),
                'is_admin'    => (int)($_POST['is_admin'] ?? 0) ? 1 : 0,
                'is_approved' => (int)($_POST['is_approved'] ?? 0) ? 1 : 0,
            ];

            if ($new['username'] === '' || $new['fullname'] === '' || $new['entreprenor'] === '' || $new['mobilnr'] === '') {
                throw new RuntimeException('Brukernavn, navn, entreprenør og mobilnr er påkrevd.');
            }

            $pwd = trim((string)($_POST['password'] ?? ''));
            if ($pwd === '') {
                $pwd = random_password(10);
                $showTempPassword = $pwd;
            }

            $hash = password_hash($pwd, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO lager_users
                    (username, email, password, fullname, entreprenor, mobilnr, office,
                     google_auth_secret, is_2fa_enabled, is_admin, is_approved,
                     reset_kode, reset_kode_utloper, passord_maa_byttes)
                VALUES
                    (:username, :email, :password, :fullname, :entreprenor, :mobilnr, :office,
                     NULL, 0, :is_admin, :is_approved,
                     NULL, NULL, 1)
            ");
            $stmt->execute([
                ':username'    => $new['username'],
                ':email'       => ($new['email'] !== '' ? $new['email'] : null),
                ':password'    => $hash,
                ':fullname'    => $new['fullname'],
                ':entreprenor' => $new['entreprenor'],
                ':mobilnr'     => $new['mobilnr'],
                ':office'      => ($new['office'] !== '' ? $new['office'] : null),
                ':is_admin'    => $new['is_admin'],
                ':is_approved' => $new['is_approved'],
            ]);

            $success = 'Lagerbruker opprettet.' . ($showTempPassword ? ' Midlertidig passord vises under.' : '');
        }

        elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Ugyldig ID.');

            $upd = [
                'username'    => trim((string)($_POST['username'] ?? '')),
                'email'       => trim((string)($_POST['email'] ?? '')),
                'fullname'    => trim((string)($_POST['fullname'] ?? '')),
                'entreprenor' => trim((string)($_POST['entreprenor'] ?? '')),
                'mobilnr'     => trim((string)($_POST['mobilnr'] ?? '')),
                'office'      => trim((string)($_POST['office'] ?? '')),
                'is_admin'    => (int)($_POST['is_admin'] ?? 0) ? 1 : 0,
                'is_approved' => (int)($_POST['is_approved'] ?? 0) ? 1 : 0,
            ];

            if ($upd['username'] === '' || $upd['fullname'] === '' || $upd['entreprenor'] === '' || $upd['mobilnr'] === '') {
                throw new RuntimeException('Brukernavn, navn, entreprenør og mobilnr er påkrevd.');
            }

            $stmt = $pdo->prepare("
                UPDATE lager_users
                   SET username = :username,
                       email = :email,
                       fullname = :fullname,
                       entreprenor = :entreprenor,
                       mobilnr = :mobilnr,
                       office = :office,
                       is_admin = :is_admin,
                       is_approved = :is_approved
                 WHERE id = :id
            ");
            $stmt->execute([
                ':username'    => $upd['username'],
                ':email'       => ($upd['email'] !== '' ? $upd['email'] : null),
                ':fullname'    => $upd['fullname'],
                ':entreprenor' => $upd['entreprenor'],
                ':mobilnr'     => $upd['mobilnr'],
                ':office'      => ($upd['office'] !== '' ? $upd['office'] : null),
                ':is_admin'    => $upd['is_admin'],
                ':is_approved' => $upd['is_approved'],
                ':id'          => $id,
            ]);

            // Valgfritt: passord bytte hvis felt fylt
            $pwd = trim((string)($_POST['password'] ?? ''));
            if ($pwd !== '') {
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE lager_users SET password = ?, passord_maa_byttes = 1 WHERE id = ?")
                    ->execute([$hash, $id]);
                $success = 'Lagerbruker oppdatert (inkl. passord).';
            } else {
                $success = 'Lagerbruker oppdatert.';
            }
        }

        elseif ($action === 'toggle_approve') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Ugyldig ID.');
            $pdo->prepare("UPDATE lager_users SET is_approved = IF(is_approved=1,0,1) WHERE id = ?")->execute([$id]);
            $success = 'Godkjenning oppdatert.';
        }

        elseif ($action === 'toggle_admin') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Ugyldig ID.');
            $pdo->prepare("UPDATE lager_users SET is_admin = IF(is_admin=1,0,1) WHERE id = ?")->execute([$id]);
            $success = 'Admin-status oppdatert.';
        }

        elseif ($action === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Ugyldig ID.');

            $tmp = random_password(10);
            $hash = password_hash($tmp, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE lager_users SET password = ?, passord_maa_byttes = 1 WHERE id = ?")->execute([$hash, $id]);

            $showTempPassword = $tmp;
            $success = 'Passord resatt. Midlertidig passord vises under.';
        }

        elseif ($action === 'disable_2fa') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Ugyldig ID.');

            $pdo->prepare("UPDATE lager_users SET is_2fa_enabled = 0, google_auth_secret = NULL WHERE id = ?")
                ->execute([$id]);
            $success = '2FA deaktivert (secret fjernet).';
        }

        else {
            throw new RuntimeException('Ukjent handling.');
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// ---------------------------------------------------------
// Fetch list + optional edit
// ---------------------------------------------------------
$q = trim((string)($_GET['q'] ?? ''));
$editId = (int)($_GET['edit_id'] ?? 0);

// Viktig: bruk ?-placeholders (samme verdi flere ganger) for å unngå HY093 når emulate prepares er AV
$where = '1=1';
$params = [];
if ($q !== '') {
    $where = "(username LIKE ? OR fullname LIKE ? OR entreprenor LIKE ? OR mobilnr LIKE ? OR email LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like, $like];
}

$stmt = $pdo->prepare("
    SELECT id, username, email, fullname, entreprenor, mobilnr, office,
           is_admin, is_approved, is_2fa_enabled, last_login_time
      FROM lager_users
     WHERE $where
     ORDER BY is_approved DESC, is_admin DESC, fullname ASC
     LIMIT 500
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$editRow = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM lager_users WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

?>
<div class="d-flex align-items-center justify-content-between mt-3">
    <h3 class="mb-0">Lagerbrukere</h3>
</div>

<?php if ($success): ?>
    <div class="alert alert-success mt-3"><?= $success ?></div>
<?php endif; ?>

<?php if ($showTempPassword): ?>
    <div class="alert alert-warning mt-3">
        <strong>Midlertidig passord:</strong>
        <span style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;">
            <?= h($showTempPassword) ?>
        </span>
        <div class="small text-muted mt-1">Kopier og send til brukeren. (Vises kun nå.)</div>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger mt-3">
        <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card mt-3">
    <div class="card-body">
        <form class="row g-2" method="get">
            <input type="hidden" name="page" value="lager_users">
            <div class="col-12 col-md-6">
                <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Søk (navn, bruker, entreprenør, mobil, epost)">
            </div>
            <div class="col-12 col-md-auto">
                <button class="btn btn-primary">Søk</button>
                <a class="btn btn-outline-secondary" href="/?page=lager_users">Nullstill</a>
            </div>
        </form>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <strong><?= $editRow ? 'Rediger lagerbruker' : 'Ny lagerbruker' ?></strong>
        <?php if ($editRow): ?>
            <a class="btn btn-sm btn-outline-secondary" href="/?page=lager_users">Avbryt</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
            <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
            <?php endif; ?>

            <div class="col-12 col-md-4">
                <label class="form-label">Brukernavn *</label>
                <input class="form-control" name="username" required
                       value="<?= h($editRow['username'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label">Fullt navn *</label>
                <input class="form-control" name="fullname" required
                       value="<?= h($editRow['fullname'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label">Entreprenør *</label>
                <input class="form-control" name="entreprenor" required
                       value="<?= h($editRow['entreprenor'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label">Mobilnr *</label>
                <input class="form-control" name="mobilnr" required
                       value="<?= h($editRow['mobilnr'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label">E-post</label>
                <input class="form-control" name="email" type="email"
                       value="<?= h($editRow['email'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label">Kontor</label>
                <input class="form-control" name="office"
                       value="<?= h($editRow['office'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label"><?= $editRow ? 'Nytt passord (valgfritt)' : 'Passord (tom => auto)' ?></label>
                <input class="form-control" name="password" type="text" autocomplete="new-password"
                       placeholder="<?= $editRow ? 'La stå tomt for å beholde' : 'La stå tomt for autogenerert' ?>">
            </div>

            <div class="col-6 col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_approved" value="1"
                           id="is_approved" <?= (int)($editRow['is_approved'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_approved">Godkjent</label>
                </div>
            </div>

            <div class="col-6 col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_admin" value="1"
                           id="is_admin" <?= (int)($editRow['is_admin'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_admin">Admin</label>
                </div>
            </div>

            <div class="col-12 col-md-4 d-flex align-items-end">
                <button class="btn btn-success w-100"><?= $editRow ? 'Lagre endringer' : 'Opprett' ?></button>
            </div>

            <div class="col-12">
                <div class="text-muted small">
                    2FA settes opp av bruker i lager-appen senere (vi bygger det). Her kan du resette passord, godkjenne og deaktivere 2FA.
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><strong>Liste</strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Navn</th>
                <th>Bruker</th>
                <th>Entreprenør</th>
                <th>Mobil</th>
                <th>E-post</th>
                <th>Status</th>
                <th style="width:360px;">Handling</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= h($r['fullname'] ?? '') ?></strong></td>
                    <td><?= h($r['username'] ?? '') ?></td>
                    <td><?= h($r['entreprenor'] ?? '') ?></td>
                    <td><?= h($r['mobilnr'] ?? '') ?></td>
                    <td><?= h($r['email'] ?? '') ?></td>
                    <td>
                        <?php if ((int)($r['is_approved'] ?? 0) === 1): ?>
                            <span class="badge bg-success">Godkjent</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Ikke godkjent</span>
                        <?php endif; ?>

                        <?php if ((int)($r['is_admin'] ?? 0) === 1): ?>
                            <span class="badge bg-primary">Admin</span>
                        <?php endif; ?>

                        <?php if ((int)($r['is_2fa_enabled'] ?? 0) === 1): ?>
                            <span class="badge bg-warning text-dark">2FA</span>
                        <?php endif; ?>

                        <div class="small text-muted">
                            Sist innlogget: <?= h((string)($r['last_login_time'] ?? '')) ?>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <a class="btn btn-sm btn-outline-primary"
                               href="/?page=lager_users&edit_id=<?= (int)$r['id'] ?>">Rediger</a>

                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="toggle_approve">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-success" type="submit">
                                    <?= ((int)($r['is_approved'] ?? 0) === 1) ? 'Avgodkjenn' : 'Godkjenn' ?>
                                </button>
                            </form>

                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="toggle_admin">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-dark" type="submit">Toggle admin</button>
                            </form>

                            <form method="post" class="d-inline" onsubmit="return confirm('Resette passord?');">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-warning" type="submit">Reset passord</button>
                            </form>

                            <form method="post" class="d-inline" onsubmit="return confirm('Deaktivere 2FA og fjerne secret?');">
                                <input type="hidden" name="action" value="disable_2fa">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit">2FA av</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$rows): ?>
                <tr>
                    <td colspan="7" class="text-muted p-3">Ingen treff.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
