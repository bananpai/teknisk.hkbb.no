<?php
// public/pages/node_location_templates.php
use App\Database;

$pdo = Database::getConnection();

$username = $_SESSION['username'] ?? '';
$isAdmin  = (bool)($_SESSION['is_admin'] ?? false);
if ($username === 'rsv') { $isAdmin = true; }

if ($username === '' || !$isAdmin) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Kun dato (YYYY-MM-DD)
function formatDateOnly(?string $dt): string {
    if (!$dt) return '';
    $d = substr($dt, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : (string)$dt;
}

$rows = $pdo->query("SELECT * FROM node_location_templates ORDER BY is_active DESC, name")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex align-items-center justify-content-between mt-3">
  <h3 class="mb-0">Maler (feltobjekt)</h3>
  <a class="btn btn-primary" href="/?page=node_location_template_edit">Ny mal</a>
</div>

<div class="card mt-3">
  <div class="table-responsive">
    <table class="table table-striped mb-0 align-middle">
      <thead>
        <tr>
          <th>Navn</th>
          <th>Aktiv</th>
          <th class="text-nowrap">Sist oppdatert</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="3" class="text-muted">Ingen maler.</td></tr>
        <?php endif; ?>

        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="min-width:280px;">
              <a href="/?page=node_location_template_edit&id=<?= (int)$r['id'] ?>">
                <?= h($r['name']) ?>
              </a>
              <?php if (!empty($r['description'])): ?>
                <div class="text-muted small"><?= h($r['description']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= ((int)$r['is_active'] === 1) ? 'Ja' : 'Nei' ?></td>
            <td class="text-nowrap"><?= h(formatDateOnly((string)($r['updated_at'] ?? ''))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="mt-3">
  <a class="btn btn-outline-secondary" href="/?page=node_locations">Til feltobjekter</a>
</div>
