<?php
// public/pages/auth_settings.php – Admin: autentiseringsinnstillinger (Entra ID)

declare(strict_types=1);

use App\Database;

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Kun admin
if (!$isAdmin) {
    echo '<div class="alert alert-danger">Tilgang nektet.</div>';
    return;
}

$pdo = Database::getConnection();

// --- Auto-migrering: opprett system_settings-tabell og auth_provider-kolonne ---
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key`   VARCHAR(100) NOT NULL,
            `setting_value` TEXT         DEFAULT NULL,
            `updated_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Standard-verdier hvis de ikke finnes
    $defaults = [
        'entra_enabled'       => '0',
        'entra_tenant_id'     => '',
        'entra_client_id'     => '',
        'entra_client_secret' => '',
        'entra_redirect_uri'  => rtrim($_SERVER['REQUEST_SCHEME'] ?? 'https', '/') . '://' . ($_SERVER['HTTP_HOST'] ?? 'teknisk.hkbb.no') . '/login/callback.php',
    ];

    $ins = $pdo->prepare(
        "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (:k, :v)"
    );
    foreach ($defaults as $k => $v) {
        $ins->execute([':k' => $k, ':v' => $v]);
    }

    // Legg til auth_provider-kolonne i users hvis den mangler
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_provider'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN `auth_provider` VARCHAR(32) NOT NULL DEFAULT 'ad'
             AFTER `username`"
        );
    }
} catch (\Throwable $e) {
    error_log('auth_settings: migrering feilet: ' . $e->getMessage());
}

// Hent nåværende innstillinger
$stmt = $pdo->query(
    "SELECT setting_key, setting_value FROM system_settings
     WHERE setting_key LIKE 'entra_%'"
);
$settings = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

$saved   = false;
$saveErr = null;

// --- Lagre innstillinger ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_entra'])) {
    try {
        $fields = [
            'entra_enabled'       => isset($_POST['entra_enabled']) ? '1' : '0',
            'entra_tenant_id'     => trim($_POST['entra_tenant_id']     ?? ''),
            'entra_client_id'     => trim($_POST['entra_client_id']     ?? ''),
            'entra_redirect_uri'  => trim($_POST['entra_redirect_uri']  ?? ''),
        ];

        // Klienthemmelighet: oppdater kun hvis fylt inn
        $newSecret = trim($_POST['entra_client_secret'] ?? '');
        if ($newSecret !== '') {
            $fields['entra_client_secret'] = $newSecret;
        }

        $upsert = $pdo->prepare(
            "INSERT INTO system_settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        foreach ($fields as $k => $v) {
            $upsert->execute([':k' => $k, ':v' => $v]);
        }

        // Oppdater lokal $settings for visning
        foreach ($fields as $k => $v) {
            $settings[$k] = $v;
        }

        $saved = true;
    } catch (\Throwable $e) {
        $saveErr = $e->getMessage();
        error_log('auth_settings lagring feilet: ' . $e->getMessage());
    }
}

$entraEnabled      = ($settings['entra_enabled']       ?? '0') === '1';
$entraTenantId     = $settings['entra_tenant_id']      ?? '';
$entraClientId     = $settings['entra_client_id']      ?? '';
$entraClientSecret = $settings['entra_client_secret']  ?? '';
$entraRedirectUri  = $settings['entra_redirect_uri']   ?? '';
$secretIsSet       = $entraClientSecret !== '';
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-shield-lock fs-4 text-primary"></i>
        <h4 class="mb-0">Autentiseringsinnstillinger</h4>
    </div>

    <?php if ($saved): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>Innstillingene ble lagret.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($saveErr !== null): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>Lagring feilet: <?= h($saveErr) ?>
        </div>
    <?php endif; ?>

    <!-- AD-informasjon (skrivebeskyttet) -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-header bg-white border-bottom">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-server text-secondary"></i>
                <strong>Lokal AD (BBDRIFT) – alltid aktiv</strong>
                <span class="badge bg-success ms-auto">Aktiv</span>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Lokal Active Directory-innlogging er alltid tilgjengelig og kan ikke deaktiveres herfra.
                Konfigurasjon styres via <code>.env</code>-filen på serveren.
            </p>
            <div class="row g-2 small text-muted">
                <div class="col-sm-6">
                    <span class="fw-semibold">Domene:</span> bbdrift.ad
                </div>
                <div class="col-sm-6">
                    <span class="fw-semibold">Krevd gruppe:</span> teknisk
                </div>
                <div class="col-sm-6">
                    <span class="fw-semibold">Servere:</span> bb-dc01, bb-dc02
                </div>
                <div class="col-sm-6">
                    <span class="fw-semibold">Protokoll:</span> LDAPS (port 636)
                </div>
            </div>
        </div>
    </div>

    <!-- Entra ID-innstillinger -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom">
            <div class="d-flex align-items-center gap-2">
                <!-- Microsoft-logo -->
                <svg width="18" height="18" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="1"  y="1"  width="9" height="9" fill="#F25022"/>
                    <rect x="11" y="1"  width="9" height="9" fill="#7FBA00"/>
                    <rect x="1"  y="11" width="9" height="9" fill="#00A4EF"/>
                    <rect x="11" y="11" width="9" height="9" fill="#FFB900"/>
                </svg>
                <strong>Microsoft Entra ID (Azure AD)</strong>
                <span class="badge ms-auto <?= $entraEnabled ? 'bg-success' : 'bg-secondary' ?>">
                    <?= $entraEnabled ? 'Aktiv' : 'Inaktiv' ?>
                </span>
            </div>
        </div>

        <div class="card-body">
            <p class="text-muted small mb-3">
                Konfigurer OAuth2/OIDC-innlogging via Microsoft Entra ID. Brukere som logger inn via
                Entra ID vil opprettes som <strong>inaktive</strong> og må aktiveres av en administrator
                under <a href="/?page=users">Systembrukere</a>.
            </p>

            <form method="post" action="/?page=auth_settings">
                <input type="hidden" name="save_entra" value="1">

                <!-- Aktiver/deaktiver -->
                <div class="mb-4">
                    <div class="form-check form-switch">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="entraEnabled"
                            name="entra_enabled"
                            <?= $entraEnabled ? 'checked' : '' ?>
                        >
                        <label class="form-check-label fw-semibold" for="entraEnabled">
                            Aktiver Entra ID-innlogging
                        </label>
                    </div>
                    <div class="form-text">
                        Når aktivert vises en «Logg inn med Microsoft»-knapp på innloggingssiden.
                        Krever at alle feltene nedenfor er fylt inn korrekt.
                    </div>
                </div>

                <hr class="my-3">

                <div class="row g-3">
                    <!-- Tenant ID -->
                    <div class="col-md-6">
                        <label for="tenantId" class="form-label fw-semibold">
                            Tenant ID (Directory ID)
                            <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            id="tenantId"
                            name="entra_tenant_id"
                            value="<?= h($entraTenantId) ?>"
                            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                            spellcheck="false"
                        >
                        <div class="form-text">
                            Finnes under <em>Azure-portal → Entra ID → Oversikt</em>.
                        </div>
                    </div>

                    <!-- Client ID -->
                    <div class="col-md-6">
                        <label for="clientId" class="form-label fw-semibold">
                            Client ID (Application ID)
                            <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            id="clientId"
                            name="entra_client_id"
                            value="<?= h($entraClientId) ?>"
                            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                            spellcheck="false"
                        >
                        <div class="form-text">
                            Finnes under <em>App registrations → din app → Oversikt</em>.
                        </div>
                    </div>

                    <!-- Client Secret -->
                    <div class="col-md-6">
                        <label for="clientSecret" class="form-label fw-semibold">
                            Client Secret
                            <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input
                                type="password"
                                class="form-control"
                                id="clientSecret"
                                name="entra_client_secret"
                                placeholder="<?= $secretIsSet ? '(uendret – fyll inn for å endre)' : 'Lim inn klienthemmelighet' ?>"
                                autocomplete="new-password"
                            >
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleSecret">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <?php if ($secretIsSet): ?>
                            <div class="form-text text-success">
                                <i class="bi bi-check-circle"></i> Hemmelighet er lagret.
                                La feltet stå tomt for å beholde gjeldende verdi.
                            </div>
                        <?php else: ?>
                            <div class="form-text">
                                Opprett under <em>App registrations → Certificates &amp; secrets → New client secret</em>.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Redirect URI -->
                    <div class="col-md-6">
                        <label for="redirectUri" class="form-label fw-semibold">
                            Redirect URI
                            <span class="text-danger">*</span>
                        </label>
                        <input
                            type="url"
                            class="form-control"
                            id="redirectUri"
                            name="entra_redirect_uri"
                            value="<?= h($entraRedirectUri) ?>"
                            placeholder="https://teknisk.hkbb.no/login/callback.php"
                            spellcheck="false"
                        >
                        <div class="form-text">
                            Denne URLen <strong>må</strong> legges til som tillatt redirect URI i Entra ID-appregistreringen.
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Veiledning -->
                <div class="alert alert-info small" role="note">
                    <h6 class="alert-heading"><i class="bi bi-info-circle me-1"></i>Slik setter du opp Entra ID-appregistrering</h6>
                    <ol class="mb-0 ps-3">
                        <li>Gå til <strong>Azure-portal → Microsoft Entra ID → App registrations → New registration</strong></li>
                        <li>Gi appen et navn (f.eks. <em>Teknisk HKBB</em>), velg riktig tenant</li>
                        <li>Under <em>Redirect URIs</em>: legg til URI-en ovenfor (type: <strong>Web</strong>)</li>
                        <li>Under <em>Certificates &amp; secrets</em>: opprett en ny klienthemmelighet og kopier verdien hit</li>
                        <li>Under <em>API permissions</em>: sørg for at <code>openid</code>, <code>profile</code> og <code>email</code> er tilstede (standard)</li>
                    </ol>
                </div>

                <div class="d-flex gap-2 justify-content-end mt-3">
                    <?php if ($entraEnabled && $entraClientId !== '' && $entraTenantId !== ''): ?>
                        <a href="/login/?method=entra" target="_blank" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Test innlogging
                        </a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Lagre innstillinger
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var btn   = document.getElementById('toggleSecret');
    var input = document.getElementById('clientSecret');
    if (btn && input) {
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show
                ? '<i class="bi bi-eye-slash"></i>'
                : '<i class="bi bi-eye"></i>';
        });
    }
})();
</script>
