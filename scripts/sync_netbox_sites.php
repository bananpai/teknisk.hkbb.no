<?php
// scripts/sync_netbox_sites.php
// Kjør via cron: php scripts/sync_netbox_sites.php
//
// Synker NetBox "sites" -> node_locations (name/slug + lat/lon hvis dere legger det i custom fields i NetBox)
// Tilpass mappingen slik dere ønsker.

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;

$pdo = Database::getConnection();

// KONFIG
$netboxBase = getenv('NETBOX_URL') ?: 'https://netbox.example.com';
$netboxToken = getenv('NETBOX_TOKEN') ?: '';
$templateId = (int)(getenv('NODELOC_TEMPLATE_ID') ?: 1); // hvilken mal node_locations skal bruke
$externalSource = 'netbox';

if ($netboxToken === '') {
    fwrite(STDERR, "Missing NETBOX_TOKEN env\n");
    exit(1);
}

function httpGetJson(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Token ' . $token,
        ],
    ]);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($out === false) throw new RuntimeException('curl error: ' . curl_error($ch));
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("NetBox HTTP $code: $out");
    }
    $j = json_decode($out, true);
    return is_array($j) ? $j : [];
}

function slugify(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('~[^\pL\pN]+~u', '-', $s);
    return trim($s, '-') ?: 'site';
}

// Paginering
$url = rtrim($netboxBase, '/') . '/api/dcim/sites/?limit=200';
$total = 0;

$pdo->beginTransaction();
try {
    while ($url) {
        $j = httpGetJson($url, $netboxToken);
        $results = $j['results'] ?? [];
        foreach ($results as $site) {
            $extId = (string)($site['id'] ?? '');
            $name  = (string)($site['name'] ?? '');
            if ($extId === '' || $name === '') continue;

            $slug = slugify((string)($site['slug'] ?? $name));

            // Eksempel: posisjon kunne ligge i NetBox custom_fields (tilpass)
            $cf = $site['custom_fields'] ?? [];
            $lat = $cf['lat'] ?? null;
            $lon = $cf['lon'] ?? null;

            // Finn eksisterende
            $st = $pdo->prepare("SELECT id FROM node_locations WHERE external_source=:s AND external_id=:e LIMIT 1");
            $st->execute([':s' => 'netbox', ':e' => $extId]);
            $existingId = (int)($st->fetchColumn() ?: 0);

            if ($existingId > 0) {
                $pdo->prepare("
                  UPDATE node_locations
                     SET name=:n, slug=:slug, lat=:lat, lon=:lon, last_synced_at=NOW(), updated_at=NOW()
                   WHERE id=:id
                ")->execute([
                    ':n' => $name,
                    ':slug' => $slug,
                    ':lat' => ($lat === null ? null : $lat),
                    ':lon' => ($lon === null ? null : $lon),
                    ':id' => $existingId,
                ]);
            } else {
                $pdo->prepare("
                  INSERT INTO node_locations
                    (template_id, name, slug, status, lat, lon, external_source, external_id, last_synced_at, created_by)
                  VALUES
                    (:tid, :n, :slug, 'active', :lat, :lon, :src, :eid, NOW(), 'sync:netbox')
                ")->execute([
                    ':tid' => $templateId,
                    ':n' => $name,
                    ':slug' => $slug,
                    ':lat' => ($lat === null ? null : $lat),
                    ':lon' => ($lon === null ? null : $lon),
                    ':src' => $externalSource,
                    ':eid' => $extId,
                ]);
            }

            $total++;
        }

        $url = $j['next'] ?? null;
    }

    $pdo->commit();
    echo "Synced $total sites\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(2);
}
