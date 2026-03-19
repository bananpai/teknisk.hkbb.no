<?php /** @var array $user */ ?>
<h1 class="mb-4">Admin</h1>
<div class="alert alert-success">Velkommen, <strong><?= htmlspecialchars($user["display_name"] ?? $user["username"]) ?></strong>!</div>

<div class="row g-3">
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header">System</div>
      <div class="card-body">
        <ul class="mb-0">
          <li>Miljø: <?= htmlspecialchars($_ENV["APP_ENV"] ?? "prod") ?></li>
          <li>Tema: <?= htmlspecialchars($user["theme"] ?? "Yeti") ?></li>
        </ul>
      </div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card h-100">
      <div class="card-header">Hurtiglenker</div>
      <div class="card-body">
        <a class="btn btn-outline-primary me-2" href="/account/preferences">Min side</a>
      </div>
    </div>
  </div>
</div>
