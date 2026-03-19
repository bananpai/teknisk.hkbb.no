<?php
// public/pages/users.php

use App\Database;

// Session er allerede startet i public/index.php

// ---------------------------------------------------------
// Admin-guard (bruker user_roles, ingen hardkoding)
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';

if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til brukeradministrasjon.
    </div>
    <?php
    return;
}

$isAdmin = false;

try {
    $pdo = Database::getConnection();

    // Finn user_id
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $userId = (int)($stmt->fetchColumn() ?: 0);

    if ($userId > 0) {
        // Sjekk om brukeren har admin-rolle i user_roles
        $stmt = $pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = :uid AND role = :role LIMIT 1');
        $stmt->execute([':uid' => $userId, ':role' => 'admin']);
        $isAdmin = (bool)$stmt->fetchColumn();
    }
} catch (\Throwable $e) {
    // Hvis DB feiler => deny by default
    $isAdmin = false;
}

if (!$isAdmin) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til brukeradministrasjon.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// DB-tilkobling (allerede åpnet over, men hold den tydelig)
// ---------------------------------------------------------
$pdo = $pdo ?? Database::getConnection();

// Definer hvilke roller som finnes i løsningen
$availableRoles = [
    'admin'   => 'Administrator',
    'support' => 'Support',
    'report'  => 'Rapportleser',
];

// ---------------------------------------------------------
// Håndter POST-aksjoner (aktiv / reset 2FA her i lista)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']   ?? '';
    $userId   = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $redirect = '/?page=users';

    if ($userId > 0) {
        if ($action === 'toggle_active') {
            $newActive = !empty($_POST['new_is_active']) ? 1 : 0;

            $stmt = $pdo->prepare('UPDATE users SET is_active = :a WHERE id = :id');
            $stmt->execute([
                ':a'  => $newActive,
                ':id' => $userId,
            ]);

        } elseif ($action === 'reset_2fa') {
            // Slå av 2FA for brukeren – neste innlogging vil trigge nytt oppsett
            $stmt = $pdo->prepare('UPDATE users SET twofa_enabled = 0, twofa_secret = NULL WHERE id = :id');
            $stmt->execute([':id' => $userId]);
        }
    }

    header('Location: ' . $redirect);
    exit;
}

// ---------------------------------------------------------
// Hent brukere
// ---------------------------------------------------------
$search = trim($_GET['q'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare(
        'SELECT id, username, display_name, email, is_active, last_login_at, twofa_enabled
           FROM users
          WHERE username LIKE :q
             OR display_name LIKE :q
             OR email LIKE :q
          ORDER BY username'
    );
    $stmt->execute([':q' => '%' . $search . '%']);
} else {
    $stmt = $pdo->query(
        'SELECT id, username, display_name, email, is_active, last_login_at, twofa_enabled
           FROM users
          ORDER BY username'
    );
}

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h4 mb-1">Brukeradministrasjon</h1>
            <p class="text-muted small mb-0">
                Nye brukere opprettes automatisk som <strong>inaktive</strong> ved første pålogging,
                og må aktiveres av en administrator før de kan bruke Teknisk.
            </p>
        </div>

        <form method="get" class="d-flex align-items-center gap-2">
            <input type="hidden" name="page" value="users">
            <input
                type="text"
                name="q"
                value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                class="form-control form-control-sm"
                placeholder="Søk på navn, brukernavn eller e-post"
                style="min-width:220px;"
            >
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-search"></i>
            </button>
        </form>
    </div>
</div>

<section class="card shadow-sm">
    <div class="card-body">
        <?php if (empty($users)): ?>
            <p class="text-muted mb-0 small">Ingen brukere funnet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Bruker</th>
                            <th>Navn</th>
                            <th>E-post</th>
                            <th>Aktiv</th>
                            <th>2FA</th>
                            <th>Sist innlogget</th>
                            <th style="width:1%; white-space:nowrap;">Handlinger</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php
                        $id          = (int)$u['id'];
                        $usernameRow = $u['username'];
                        $name        = $u['display_name'] ?: $usernameRow;
                        $email       = $u['email'] ?: '';
                        $isActive    = (bool)$u['is_active'];
                        $twofa       = (bool)$u['twofa_enabled'];
                        $lastLogin   = $u['last_login_at'];
                        ?>
                        <tr class="<?php echo $isActive ? '' : 'table-secondary'; ?>">
                            <td><?php echo $id; ?></td>
                            <td><code><?php echo htmlspecialchars($usernameRow, ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if ($email): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">–</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="user_id" value="<?php echo $id; ?>">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="new_is_active" value="<?php echo $isActive ? 0 : 1; ?>">
                                    <button
                                        type="submit"
                                        class="btn btn-xs btn-outline-<?php echo $isActive ? 'success' : 'secondary'; ?>"
                                    >
                                        <?php if ($isActive): ?>
                                            <i class="bi bi-check-circle me-1"></i> Aktiv
                                        <?php else: ?>
                                            <i class="bi bi-slash-circle me-1"></i> Inaktiv
                                        <?php endif; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <?php if ($twofa): ?>
                                    <span class="badge text-bg-success">På</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Av</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($lastLogin): ?>
                                    <span class="small">
                                        <?php echo htmlspecialchars($lastLogin, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">Aldri</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <a
                                    href="/?page=users_edit&user_id=<?php echo $id; ?>"
                                    class="btn btn-xs btn-outline-primary me-1"
                                >
                                    <i class="bi bi-sliders me-1"></i> Administrer
                                </a>

                                <form method="post" class="d-inline">
                                    <input type="hidden" name="user_id" value="<?php echo $id; ?>">
                                    <input type="hidden" name="action" value="reset_2fa">
                                    <button
                                        type="submit"
                                        class="btn btn-xs btn-outline-danger"
                                        onclick="return confirm('Reset 2-faktor for denne brukeren? Neste gang vedkommende logger inn må 2FA settes opp på nytt.');"
                                    >
                                        <i class="bi bi-shield-x me-1"></i> Reset 2FA
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="small text-muted mt-2 mb-0">
                Grupper fra AD vises ikke her. Rettigheter settes på detaljsiden for hver bruker.
            </p>
        <?php endif; ?>
    </div>
</section>
