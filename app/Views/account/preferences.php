<?php /** @var array $themes */ ?>
<h2>Min side</h2>
<form method="post" action="/account/preferences">
  <div class="mb-3">
    <label class="form-label">Tema (Bootswatch)</label>
    <select name="theme" class="form-select">
      <?php foreach($themes as $t): ?>
        <option value="<?= htmlspecialchars($t) ?>" <?= (strtolower($t)===strtolower($theme))?"selected":""; ?>><?= htmlspecialchars($t) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-primary">Lagre</button>
</form>
