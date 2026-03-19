<?php $authDriver = $auth_driver ?? ($_ENV["AUTH_DRIVER"] ?? "ldap"); ?>
<div class="row justify-content-center">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header">Logg inn</div>
      <div class="card-body">
        <?php if(!empty($error)): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if(!empty($need_totp)): ?>
          <?php if(!empty($setup_uri)): ?>
            <p>Skann denne QR-koden i Google/Microsoft Authenticator:</p>
            <img alt="TOTP QR" src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($setup_uri) ?>">
          <?php endif; ?>
          <form method="post" action="/">
            <div class="mb-3">
              <label class="form-label">Engangskode</label>
              <input class="form-control" name="totp_code" autocomplete="one-time-code" required>
            </div>
            <button class="btn btn-primary">Fortsett</button>
          </form>
        <?php else: ?>
          <form method="post" action="/login">
            <div class="mb-3">
              <label class="form-label">Brukernavn</label>
              <input class="form-control" name="username" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Passord</label>
              <input type="password" class="form-control" name="password" required>
            </div>
            <button class="btn btn-primary">Logg inn</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
