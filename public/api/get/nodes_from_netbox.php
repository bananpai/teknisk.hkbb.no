<?php
// Path: \public\api\get\nodes_from_netbox.php
//
// NetBox -> node_locations sync
// - Henter sites fra NetBox API: https://netbox.hkbb.no/api/dcim/sites/
// - Synkroniserer til MySQL-tabellen node_locations (upsert)
// - Unngår dubletter: "name" er unik (anbefalt: UNIQUE KEY uniq_name (name))
// - NYTT: kolonnen "partner" fylles med NetBox "Tenant" (tenant.name)
//
// Bruk:
// - Visning (kun henter og viser):  /api/get/nodes_from_netbox.php
// - Synk: ?sync=1  (evt. POST via knappen i UI)
//
// NB (SSL):
// - Hvis NetBox bruker self-signed sertifikat kan cURL feile.
// - Sett $insecureSkipTlsVerify=true for å ignorere TLS-verifisering (kun intern/test).

declare(strict_types=1);

/* -------------------------------
   Bootstrap
-------------------------------- */
$_autoload = realpath(__DIR__ . '/../../../vendor/autoload.php');
if ($_autoload && is_file($_autoload)) {
    require_once $_autoload;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Krev innlogget sesjon
if (empty($_SESSION['username'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ikke autentisert.';
    exit;
}

/* -------------------------------
   Optional: bruk appens PDO-helper hvis den finnes
-------------------------------- */
$pdo = null;
if (class_exists('\App\Database')) {
    try {
        $pdo = \App\Database::getConnection();
    } catch (Throwable $e) {
        $pdo = null;
    }
}

/* -------------------------------
   Konfig
-------------------------------- */
$apiUrlBase = 'https://netbox.hkbb.no/api/dcim/sites/';

// Token fra .env (NETBOX_TOKEN)
$token = (string)($_ENV['NETBOX_TOKEN'] ?? getenv('NETBOX_TOKEN') ?: '');
if ($token === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'NETBOX_TOKEN er ikke satt i .env';
    exit;
}

// TLS-verifisering – sett NETBOX_SKIP_TLS_VERIFY=true i .env kun for intern test
$insecureSkipTlsVerify = strtolower((string)($_ENV['NETBOX_SKIP_TLS_VERIFY'] ?? getenv('NETBOX_SKIP_TLS_VERIFY') ?: 'false')) === 'true';

// NetBox paging
$perPage = 200;

// node_locations.template_id (må finnes i node_location_templates)
$defaultTemplateId = max(1, (int)($_GET['template_id'] ?? 1));

// Trigger sync
$doSync = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sync'])) {
    $doSync = true;
}
if (isset($_GET['sync']) && (string)$_GET['sync'] === '1') {
    $doSync = true;
}

/* -------------------------------
   Helpers
-------------------------------- */
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function netbox_get_json(string $url, string $token, bool $insecureSkipTlsVerify, int &$httpCode, ?string &$curlErr): ?array {
    $ch = curl_init($url);
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Token ' . $token,
            'User-Agent: HKBB-NetBox-Nodes-Sync/1.0',
        ],
    ];

    if ($insecureSkipTlsVerify) {
        $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
        $curlOpts[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    curl_setopt_array($ch, $curlOpts);
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    return $data;
}

/**
 * Hent alle sites fra NetBox (pager automatisk)
 * Returnerer: [ 'count' => int, 'results' => array, 'pages' => int ]
 */
function netbox_fetch_all_sites(string $apiUrlBase, string $token, bool $insecureSkipTlsVerify, int $perPage, array &$meta): array {
    $all = [];
    $offset = 0;
    $pages = 0;
    $count = null;

    $meta = [
        'http_last' => 0,
        'curl_err'  => null,
        'errors'    => [],
        'raw_pages' => 0,
    ];

    while (true) {
        $url = $apiUrlBase . '?' . http_build_query([
            'limit'  => $perPage,
            'offset' => $offset,
        ]);

        $http = 0;
        $err = null;
        $data = netbox_get_json($url, $token, $insecureSkipTlsVerify, $http, $err);

        $meta['http_last'] = $http;
        $meta['curl_err']  = $err;
        $meta['raw_pages']++;

        if ($err) {
            $meta['errors'][] = "cURL-feil ved offset={$offset}: {$err}";
            break;
        }
        if ($http < 200 || $http >= 300) {
            $meta['errors'][] = "HTTP {$http} fra NetBox ved offset={$offset}";
            break;
        }
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            $meta['errors'][] = "Uventet responsformat ved offset={$offset}";
            break;
        }

        if ($count === null && isset($data['count']) && is_numeric($data['count'])) {
            $count = (int)$data['count'];
        }

        $batch = $data['results'];
        foreach ($batch as $row) {
            $all[] = $row;
        }

        $pages++;
        $offset += $perPage;

        // Stop hvis vi har hentet alt (basert på count), eller hvis batch mindre enn perPage
        if ($count !== null && count($all) >= $count) {
            break;
        }
        if (count($batch) < $perPage) {
            break;
        }

        // Safety: unngå evig loop ved rare API-svar
        if ($pages > 2000) {
            $meta['errors'][] = "Avbrøt: for mange sider (safety stop).";
            break;
        }
    }

    $meta['count'] = $count ?? count($all);
    $meta['pages'] = $pages;

    return $all;
}

/**
 * Opprett/oppdater node_locations basert på NetBox site.
 * Unikhet: name (forutsetter helst UNIQUE KEY uniq_name(name)).
 *
 * Returnerer: 'inserted'|'updated'|'skipped'
 */
function upsert_node_location(PDO $pdo, int $templateId, array $site, string $updatedBy = 'netbox_sync'): string {
    // NetBox felt
    $name        = trim((string)($site['name'] ?? ''));
    $slug        = trim((string)($site['slug'] ?? ''));
    $statusLabel = (string)($site['status']['value'] ?? $site['status']['label'] ?? $site['status'] ?? 'active');
    $description = $site['description'] ?? null;

    $region      = $site['region']['name'] ?? null;
    $tenantName  = $site['tenant']['name'] ?? null; // -> partner
    $externalId  = (string)($site['id'] ?? '');
    $externalUrl = (string)($site['url'] ?? '');

    // Adresser (NetBox har ofte physical_address / shipping_address som fritekst)
    $address1 = $site['physical_address'] ?? null;
    $address2 = $site['shipping_address'] ?? null;

    // Lat/lon
    $lat = isset($site['latitude']) && $site['latitude'] !== null ? (float)$site['latitude'] : null;
    $lon = isset($site['longitude']) && $site['longitude'] !== null ? (float)$site['longitude'] : null;

    if ($name === '') {
        return 'skipped';
    }

    // Normaliser status til din tabell (active|planned|...).
    // Hvis du vil mappe mer avansert senere, kan dette utvides.
    $status = 'active';
    $s = strtolower(trim((string)$statusLabel));
    if ($s !== '') {
        // behold netbox "value" om det er enkelt
        $status = $s;
    }

    // Finn eksisterende basert på name (unik)
    $stmt = $pdo->prepare("SELECT id FROM node_locations WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $upd = $pdo->prepare("
            UPDATE node_locations
               SET template_id     = ?,
                   slug            = ?,
                   status          = ?,
                   description     = ?,
                   address_line1   = ?,
                   address_line2   = ?,
                   region          = ?,
                   lat             = ?,
                   lon             = ?,
                   external_source = 'netbox',
                   external_id     = ?,
                   last_synced_at  = NOW(),
                   updated_by      = ?,
                   updated_at      = NOW(),
                   partner         = ?
             WHERE id = ?
            LIMIT 1
        ");
        $upd->execute([
            $templateId,
            $slug !== '' ? $slug : null,
            $status,
            $description,
            $address1,
            $address2,
            $region,
            $lat,
            $lon,
            $externalId !== '' ? $externalId : null,
            $updatedBy,
            $tenantName,
            $existingId
        ]);
        return 'updated';
    }

    // Insert ny
    $ins = $pdo->prepare("
        INSERT INTO node_locations
            (template_id, name, slug, status, description,
             address_line1, address_line2, region, lat, lon,
             external_source, external_id, last_synced_at, created_by, updated_by, partner)
        VALUES
            (?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?,
             'netbox', ?, NOW(), ?, ?, ?)
    ");
    $ins->execute([
        $templateId,
        $name,
        $slug !== '' ? $slug : $name,   // fallback
        $status,
        $description,
        $address1,
        $address2,
        $region,
        $lat,
        $lon,
        $externalId !== '' ? $externalId : null,
        $updatedBy,
        $updatedBy,
        $tenantName
    ]);
    return 'inserted';
}

/* -------------------------------
   Hent data fra NetBox
-------------------------------- */
$meta = [];
$sites = netbox_fetch_all_sites($apiUrlBase, $token, $insecureSkipTlsVerify, $perPage, $meta);

/* -------------------------------
   Sync til DB (valgfritt)
-------------------------------- */
$syncResult = [
    'ran'      => false,
    'inserted' => 0,
    'updated'  => 0,
    'skipped'  => 0,
    'errors'   => [],
];

if ($doSync) {
    $syncResult['ran'] = true;

    if (!$pdo instanceof PDO) {
        $syncResult['errors'][] = 'Fant ikke PDO-tilkobling (pdo()). Last core/bootstrap.php og sørg for at pdo() fungerer.';
    } else {
        try {
            $pdo->beginTransaction();
            foreach ($sites as $site) {
                $r = upsert_node_location($pdo, $defaultTemplateId, $site, 'netbox_sync');
                if ($r === 'inserted') $syncResult['inserted']++;
                elseif ($r === 'updated') $syncResult['updated']++;
                else $syncResult['skipped']++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $syncResult['errors'][] = 'DB-feil under sync: ' . $e->getMessage();
        }
    }
}

/* -------------------------------
   UI
-------------------------------- */
$count = (int)($meta['count'] ?? count($sites));
$pages = (int)($meta['pages'] ?? 0);
$httpLast = (int)($meta['http_last'] ?? 0);
$curlErr = $meta['curl_err'] ?? null;
$errors = $meta['errors'] ?? [];

?>
<!doctype html>
<html lang="no">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NetBox → node_locations</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; background:#f7f7f9; color:#111; }
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    .muted { color:#6b7280; }
    .bad { color:#b91c1c; font-weight:600; }
    .ok  { color:#065f46; font-weight:600; }
    .row { display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
    .pill { display:inline-block; padding:4px 10px; border-radius:999px; background:#eef2ff; border:1px solid #e0e7ff; }
    .warn { background:#fff7ed; border-color:#fed7aa; }
    .btn { display:inline-block; padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; background:#fff; color:#111; text-decoration:none; cursor:pointer; }
    .btn:hover { background:#f3f4f6; }
    table { width:100%; border-collapse: collapse; }
    th, td { padding:10px 8px; border-bottom:1px solid #eee; text-align:left; vertical-align:top; }
    th { background:#fafafa; font-weight:700; }
    details pre { white-space:pre-wrap; word-break:break-word; background:#0b1020; color:#e5e7eb; padding:12px; border-radius:8px; overflow:auto; }
    code { background:#f3f4f6; padding:2px 6px; border-radius:6px; }
    .grid { display:grid; grid-template-columns: 1fr; gap:12px; }
    @media (min-width: 900px) { .grid { grid-template-columns: 1.2fr .8fr; } }
  </style>
</head>
<body>

  <div class="card">
    <h1 style="margin:0 0 6px 0;">NetBox → node_locations</h1>
    <div class="muted">
      Leser fra <code><?=h($apiUrlBase)?></code> og (valgfritt) synker til <code>node_locations</code>.
      <br>
      Nytt felt: <code>partner</code> fylles med NetBox <code>tenant.name</code>.
      Unikhet: <code>name</code> (anbefalt: <code>UNIQUE KEY uniq_name(name)</code>).
    </div>

    <div class="row" style="margin-top:12px;">
      <span class="pill">Per page: <?= (int)$perPage ?></span>
      <span class="pill">Pages: <?= (int)$pages ?></span>
      <span class="pill">Count: <?= (int)$count ?></span>
      <span class="pill">HTTP(last): <?= (int)$httpLast ?></span>
      <span class="pill">template_id: <?= (int)$defaultTemplateId ?></span>

      <?php if ($insecureSkipTlsVerify): ?>
        <span class="pill warn">TLS verify: AV</span>
      <?php else: ?>
        <span class="pill">TLS verify: PÅ</span>
      <?php endif; ?>
    </div>

    <div class="row" style="margin-top:12px;">
      <form method="post" style="margin:0;">
        <button class="btn" type="submit" name="sync" value="1">Synkroniser nå</button>
      </form>
      <a class="btn" href="?sync=1&template_id=<?= (int)$defaultTemplateId ?>">Synk via ?sync=1</a>
      <a class="btn" href="?template_id=<?= (int)$defaultTemplateId ?>">Kun visning</a>
    </div>

    <?php if ($curlErr): ?>
      <div style="margin-top:12px;" class="bad">cURL-feil: <?= h((string)$curlErr) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div style="margin-top:12px;">
        <div class="bad">Feil ved henting:</div>
        <ul>
          <?php foreach ($errors as $e): ?>
            <li class="bad"><?= h((string)$e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($syncResult['ran']): ?>
      <div style="margin-top:12px;">
        <?php if (!empty($syncResult['errors'])): ?>
          <div class="bad">Sync feilet:</div>
          <ul>
            <?php foreach ($syncResult['errors'] as $e): ?>
              <li class="bad"><?= h((string)$e) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="ok">
            Sync OK — Inserted: <?= (int)$syncResult['inserted'] ?>,
            Updated: <?= (int)$syncResult['updated'] ?>,
            Skipped: <?= (int)$syncResult['skipped'] ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($insecureSkipTlsVerify): ?>
      <div class="muted" style="margin-top:10px;">
        <strong>Merk:</strong> TLS-verifisering er slått av for å omgå self-signed sertifikat (kun anbefalt internt/test).
      </div>
    <?php endif; ?>
  </div>

  <div class="grid">
    <div class="card">
      <h2 style="margin-top:0;">NetBox Sites (preview)</h2>
      <?php if (empty($sites)): ?>
        <div class="muted">Ingen data.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Navn</th>
              <th>Slug</th>
              <th>Status</th>
              <th>Region</th>
              <th>Tenant</th>
              <th>URL</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach (array_slice($sites, 0, 200) as $row): ?>
            <?php
              $id     = $row['id'] ?? '';
              $name   = $row['name'] ?? '';
              $slug   = $row['slug'] ?? '';
              $status = $row['status']['label'] ?? ($row['status']['value'] ?? $row['status'] ?? '');
              $region = $row['region']['name'] ?? '';
              $tenant = $row['tenant']['name'] ?? '';
              $url    = $row['url'] ?? '';
            ?>
            <tr>
              <td><?= h((string)$id) ?></td>
              <td><?= h((string)$name) ?></td>
              <td><?= h((string)$slug) ?></td>
              <td><?= h((string)$status) ?></td>
              <td><?= h((string)$region) ?></td>
              <td><?= h((string)$tenant) ?></td>
              <td>
                <?php if ($url): ?>
                  <a href="<?= h((string)$url) ?>" target="_blank" rel="noopener">Åpne</a>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (count($sites) > 200): ?>
          <div class="muted" style="margin-top:10px;">Viser kun de 200 første (av <?= (int)count($sites) ?>).</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin-top:0;">Rå JSON (kort)</h2>
      <details>
        <summary>Vis første side som JSON</summary>
        <pre><?= h(json_encode(array_slice($sites, 0, 20), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
      </details>

      <div class="muted" style="margin-top:12px;">
        <strong>DB-notat:</strong> For “ingen dubletter” bør du ha
        <code>UNIQUE KEY uniq_name(name)</code>.
        Sync bruker <code>name</code> som nøkkel for update/insert.
      </div>
    </div>
  </div>

</body>
</html>
