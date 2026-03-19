<?php
// public/lager/pages/admin_users.php

declare(strict_types=1);

$pdo = get_pdo();

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ensure_lager_users_table')) {
    function ensure_lager_users_table(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS lager_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(190) NOT NULL UNIQUE,
                full_name VARCHAR(190) NULL,
                phone VARCHAR(30) NULL,
                password_hash VARCHAR(255) NOT NULL,
                is_admin TINYINT(1) NOT NULL DEFAULT 0,
                is_approved TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                must_change_password TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                approved_at DATETIME NULL,
                last_login_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

ensure_lager_users_table($pdo);

// Guard: må være admin
if (empty($_SESSION['lager_is_admin'])) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

$ok = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        $err = 'Ugyldig bruker.';
    } else {
        try {
            if ($action === 'approve') {
                $pdo->prepare("UPDATE lager_users SET is_approved = 1, approved_at = NOW() WHERE id = ?")->execute([$id]);
                $ok = 'Bruker godkjent.';
            } elseif ($action === 'toggle_active') {
                $pdo->prepare("UPDATE lager_users SET is_active = IF(is_active=1,0,1) WHERE id = ?")->execute([$id]);
                $ok = 'Oppdatert aktiv-status.';
            } elseif ($action === 'toggle_admin') {
                $pdo->prepare("UPDATE lager_users SET is_admin = IF(is_admin=1,0,1) WHERE id = ?")->execute([$id]);
                $ok = 'Oppdatert admin-status.';
            } else {
                $err = 'Ukjent handling.';
            }
        } catch (Throwable $e) {
            $err = 'Kunne ikke oppdatere: ' . $e->getMessage();
        }
    }
}

$pending = $pdo->query("SELECT * FROM lager_users WHERE is_approved = 0 ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$users   = $pdo->query("SELECT * FROM lager_users ORDER BY is_approved ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="d-flex align-items-center justify-content-between mt-2">
  <h3 class="mb-0">Brukere</h3>
</div>

<?php if ($ok): ?>
  <div class="alert alert-success mt-3"><?= h($ok) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="alert alert-danger mt-3"><?= h($err) ?></div>
<?php endif; ?>

<div class="card mt-3">
  <div class="card-body">
    <h5 class="mb-3">Venter på godkjenning</h5>

    <?php if (!$pending): ?>
      <div class="text-muted">Ingen ventende brukere.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>E-post</th>
              <th>Navn</th>
              <th>Mobil</th>
              <th>Opprettet</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pending as $u): ?>
              <tr>
                <td><?= h((string)$u['username']) ?></td>
                <td><?= h((string)($u['full_name'] ?? '')) ?></td>
                <td><?= h((string)($u['phone'] ?? '')) ?></td>
                <td><?= h((string)$u['created_at']) ?></td>
                <td class="text-end">
                  <form method="post" class="d-inline">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button class="btn btn-success btn-sm">Godkjenn</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-3">
  <div class="card-body">
    <h5 class="mb-3">Alle brukere</h5>

    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>E-post</th>
            <th>Navn</th>
            <th>Mobil</th>
            <th>Godkjent</th>
            <th>Aktiv</th>
            <th>Admin</th>
            <th>Sist innlogget</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= h((string)$u['username']) ?></td>
              <td><?= h((string)($u['full_name'] ?? '')) ?></td>
              <td><?= h((string)($u['phone'] ?? '')) ?></td>
              <td><?= ((int)$u['is_approved'] === 1) ? 'Ja' : 'Nei' ?></td>
              <td><?= ((int)$u['is_active'] === 1) ? 'Ja' : 'Nei' ?></td>
              <td><?= ((int)$u['is_admin'] === 1) ? 'Ja' : 'Nei' ?></td>
              <td><?= h((string)($u['last_login_at'] ?? '')) ?></td>
              <td class="text-end">
                <form method="post" class="d-inline">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="action" value="toggle_active">
                  <button class="btn btn-outline-secondary btn-sm">Aktiv av/på</button>
                </form>
                <form method="post" class="d-inline">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="action" value="toggle_admin">
                  <button class="btn btn-outline-secondary btn-sm">Admin av/på</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
