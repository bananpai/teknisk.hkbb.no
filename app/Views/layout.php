<?php
$themeName = $theme ?? ($_ENV["DEFAULT_THEME"] ?? "Yeti");
$themeCss = "https://cdn.jsdelivr.net/npm/bootswatch@5/dist/".strtolower($themeName)."/bootstrap.min.css";
?>
<!doctype html>
<html lang="no">
<head>
  <meta charset="utf-8">
  <title>Teknisk</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="<?= htmlspecialchars($themeCss) ?>" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-primary navbar-dark">
  <div class="container">
    <a class="navbar-brand" href="/">Teknisk</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav ms-auto">
        <?php if(isset($_SESSION["user_id"])): ?>
          <li class="nav-item"><a class="nav-link" href="/account/preferences">Min side</a></li>
          <li class="nav-item">
            <form method="post" action="/logout" class="d-inline">
              <button class="btn btn-link nav-link" type="submit">Logg ut</button>
            </form>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="/login">Logg inn</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<main class="container py-4">
  <?php require __DIR__ . "/" . ($view ?? "login") . ".php"; ?>
</main>
</body>
</html>
