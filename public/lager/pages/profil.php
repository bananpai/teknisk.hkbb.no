<?php
// public/lager/pages/profil.php

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

$u   = require_lager_login();
$pdo = get_pdo();

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

$errors  = [];
$success = null;

// Hent fersk brukerdata fra DB (ikke stol blindt på session)
$stmt = $pdo->prepare("
    SELECT id, username, email, fullname, entreprenor, mobilnr, office
    FROM lager_users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([(int)($u['id'] ?? 0)]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Ugyldig bruker.</div>
    <?php
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $fullname    = trim((string)($_POST['fullname'] ?? ''));
        $entreprenor = trim((string)($_POST['entreprenor'] ?? ''));
        $mobilnr     = trim((string)($_POST['mobilnr'] ?? ''));
        $email       = trim((string)($_POST['email'] ?? ''));
        $office      = trim((string)($_POST['office'] ?? ''));

        if ($fullname === '' || $entreprenor === '' || $mobilnr === '') {
            throw new RuntimeException('Fullt navn, entreprenør og mobilnr er påkrevd.');
        }

        $stmt = $pdo->prepare("
            UPDATE lager_users
               SET fullname    = ?,
                   entreprenor = ?,
                   mobilnr     = ?,
                   email       = ?,
                   office      = ?
             WHERE id = ?
             LIMIT 1
        ");
        $stmt->execute([
            $fullname,
            $entreprenor,
            $mobilnr,
            ($email !== '' ? $email : null),
            ($office !== '' ? $office : null),
            (int)$row['id'],
        ]);

        // Oppdater session slik at toppmeny/header viser riktig
        if (!isset($_SESSION['lager_user']) || !is_array($_SESSION['lager_user'])) {
            $_SESSION['lager_user'] = [];
        }
        $_SESSION['lager_user']['fullname']    = $fullname;
        $_SESSION['lager_user']['entreprenor'] = $entreprenor;
        $_SESSION['lager_user']['mobilnr']     = $mobilnr;
        $_SESSION['lager_user']['email']       = $email;
        $_SESSION['lager_user']['office']      = $office;

        $success = 'Profil oppdatert.';

        // Reload row for visning
        $stmt = $pdo->prepare("
            SELECT id, username, email, fullname, entreprenor, mobilnr, office
            FROM lager_users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$row['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;

    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

?>

<div class="d-flex align-items-start justify-content-between mt-3 flex-wrap gap-2">
    <div>
        <h3 class="mb-1">Min profil</h3>
        <div class="text-muted">
            Oppdater kontaktinfo. Brukernavn kan ikke endres.
        </div>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success mt-3"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger mt-3">
        <strong>Feil:</strong><br>
        <?= nl2br(h(implode("\n", $errors))) ?>
    </div>
<?php endif; ?>

<div class="card mt-3">
    <div class="card-body">
        <form method="post" class="row g-3" autocomplete="off">
            <div class="col-12">
                <label class="form-label">Brukernavn</label>
                <input class="form-control" value="<?= h((string)$row['username']) ?>" disabled>
            </div>

            <div class="col-12">
                <label class="form-label" for="fullname">Fullt navn *</label>
                <input class="form-control" id="fullname" name="fullname"
                       value="<?= h((string)($row['fullname'] ?? '')) ?>" required>
            </div>

            <div class="col-12">
                <label class="form-label" for="entreprenor">Entreprenør *</label>
                <input class="form-control" id="entreprenor" name="entreprenor"
                       value="<?= h((string)($row['entreprenor'] ?? '')) ?>" required>
            </div>

            <div class="col-12">
                <label class="form-label" for="mobilnr">Mobilnr *</label>
                <input class="form-control" id="mobilnr" name="mobilnr"
                       value="<?= h((string)($row['mobilnr'] ?? '')) ?>" required>
            </div>

            <div class="col-12">
                <label class="form-label" for="email">E-post</label>
                <input class="form-control" id="email" name="email" type="email"
                       value="<?= h((string)($row['email'] ?? '')) ?>">
            </div>

            <div class="col-12">
                <label class="form-label" for="office">Kontor</label>
                <input class="form-control" id="office" name="office"
                       value="<?= h((string)($row['office'] ?? '')) ?>">
            </div>

            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Lagre</button>
                <a class="btn btn-outline-secondary" href="/lager/">Tilbake</a>
            </div>

            <div class="col-12">
                <div class="text-muted small">Felter merket * er påkrevd.</div>
            </div>
        </form>
    </div>
</div>
