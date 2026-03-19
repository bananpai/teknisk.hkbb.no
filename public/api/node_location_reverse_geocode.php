<?php
// public/api/node_location_reverse_geocode.php

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// ---------------------------------------------------------
// Last inn autoload/bootstrap (slik at App\Database finnes)
// ---------------------------------------------------------
$bootstrapCandidates = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',

    __DIR__ . '/../inc/bootstrap.php',
    __DIR__ . '/../inc/init.php',
    __DIR__ . '/../inc/config.php',
    __DIR__ . '/../bootstrap.php',
    __DIR__ . '/../../bootstrap.php',
];

foreach ($bootstrapCandidates as $file) {
    if (is_file($file)) {
        require_once $file;
        break;
    }
}

function jsonOut(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('normalize_list')) {
    function normalize_list($v): array {
        if (is_array($v)) {
            $isAssoc = array_keys($v) !== range(0, count($v) - 1);
            if ($isAssoc) {
                $out = [];
                foreach ($v as $k => $val) {
                    if (is_string($k) && $k !== '' && $val) $out[] = $k;
                    elseif (is_string($val) && trim($val) !== '') $out[] = trim($val);
                }
                return array_values(array_filter(array_map('strval', $out)));
            }
            return array_values(array_filter(array_map('strval', $v)));
        }
        if (is_string($v) && trim($v) !== '') {
            $parts = preg_split('/[,\s;]+/', $v);
            return array_values(array_filter(array_map('strval', $parts)));
        }
        return [];
    }
}

if (!function_exists('has_any')) {
    function has_any(array $needles, array $haystack): bool {
        $haystack = array_map('strtolower', array_map('strval', $haystack));
        foreach ($needles as $n) {
            if (in_array(strtolower((string)$n), $haystack, true)) return true;
        }
        return false;
    }
}

if (!class_exists(\App\Database::class)) {
    jsonOut(500, [
        'ok' => false,
        'error' => 'Autoload/bootstrap er ikke lastet, App\\Database finnes ikke.'
    ]);
}

use App\Database;

try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    jsonOut(500, ['ok' => false, 'error' => 'Kunne ikke koble til DB: ' . $e->getMessage()]);
}

$username = $_SESSION['username'] ?? '';
if ($username === '') {
    jsonOut(403, ['ok' => false, 'error' => 'Du har ikke tilgang.']);
}

// Guard: admin eller node_write (samme mønster som edit-siden)
$roles = normalize_list($_SESSION['roles'] ?? null);

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $currentUserId = (int)($stmt->fetchColumn() ?: 0);

    if ($currentUserId > 0) {
        $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $stmt->execute([':uid' => $currentUserId]);
        $dbRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $roles = array_merge($roles, normalize_list($dbRoles));
    }
} catch (\Throwable $e) {
    jsonOut(403, ['ok' => false, 'error' => 'Du har ikke tilgang (DB-feil).']);
}

$roles = array_values(array_unique(array_map('strtolower', $roles)));
$isAdmin      = has_any(['admin'], $roles);
$canNodeWrite = $isAdmin || has_any(['node_write'], $roles);

if (!$canNodeWrite) {
    jsonOut(403, ['ok' => false, 'error' => 'Du har ikke tilgang.']);
}

// Input
$lat = $_POST['lat'] ?? $_GET['lat'] ?? null;
$lon = $_POST['lon'] ?? $_GET['lon'] ?? null;

$lat = is_string($lat) ? trim(str_replace(',', '.', $lat)) : $lat;
$lon = is_string($lon) ? trim(str_replace(',', '.', $lon)) : $lon;

if ($lat === null || $lon === null || $lat === '' || $lon === '') {
    jsonOut(400, ['ok' => false, 'error' => 'Mangler lat/lon.']);
}

$latF = (float)$lat;
$lonF = (float)$lon;

if (!is_finite($latF) || !is_finite($lonF) || $latF < -90 || $latF > 90 || $lonF < -180 || $lonF > 180) {
    jsonOut(400, ['ok' => false, 'error' => 'Ugyldig lat/lon.']);
}

// Reverse geocode via Nominatim
$url = 'https://nominatim.openstreetmap.org/reverse'
     . '?format=jsonv2'
     . '&addressdetails=1'
     . '&zoom=18'
     . '&lat=' . rawurlencode((string)$latF)
     . '&lon=' . rawurlencode((string)$lonF);

$headers = "User-Agent: TekniskSide/1.0 (teknisk.hkbb.no)\r\n"
         . "Accept: application/json\r\n"
         . "Accept-Language: nb-NO,nb;q=0.9,en;q=0.8\r\n";

$ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => $headers,
        'timeout' => 10,
    ],
]);

$raw = @file_get_contents($url, false, $ctx);
if ($raw === false || trim($raw) === '') {
    jsonOut(502, ['ok' => false, 'error' => 'Kunne ikke slå opp adresse (ingen svar fra geokoding).']);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    jsonOut(502, ['ok' => false, 'error' => 'Kunne ikke tolke svar fra geokoding.']);
}

$addr = $data['address'] ?? [];
if (!is_array($addr)) $addr = [];

// Adresse linje 1: vei + husnr
$road  = (string)($addr['road'] ?? ($addr['pedestrian'] ?? ($addr['path'] ?? ($addr['cycleway'] ?? ''))));
$house = (string)($addr['house_number'] ?? '');
$addressLine1 = trim($road . ($house !== '' ? ' ' . $house : ''));

if ($addressLine1 === '') {
    $name = (string)($data['name'] ?? '');
    $addressLine1 = trim($name);
}
if ($addressLine1 === '') {
    $dn = (string)($data['display_name'] ?? '');
    $first = trim((string)(explode(',', $dn)[0] ?? ''));
    $addressLine1 = $first;
}

$postalCode = (string)($addr['postcode'] ?? '');
$city = (string)(
    $addr['city'] ??
    $addr['town'] ??
    $addr['village'] ??
    $addr['municipality'] ??
    $addr['hamlet'] ??
    ''
);

// Region + land
$regionName  = (string)($addr['county'] ?? ($addr['state'] ?? ''));
$countryName = (string)($addr['country'] ?? '');

// ✅ VIKTIG: DB-feltet er VARCHAR(2) -> bruk ISO-landkode
$countryCode = (string)($addr['country_code'] ?? '');
$countryCode = strtoupper(trim($countryCode));

// Fallback hvis API ikke gir country_code (sjeldent): bruk NO hvis landet er Norge, ellers blank
if ($countryCode === '' && mb_strtolower($countryName, 'UTF-8') === 'norge') {
    $countryCode = 'NO';
}

// Tving maks 2 tegn uansett
if ($countryCode !== '') {
    $countryCode = substr($countryCode, 0, 2);
}

jsonOut(200, [
    'ok' => true,
    'address_line1' => $addressLine1,
    'postal_code'   => $postalCode,
    'city'          => $city,

    // skjulte felter i edit-siden:
    'region'        => $regionName,
    'country'       => $countryCode,   // <-- dette går inn i node_locations.country (VARCHAR(2))

    // ekstra info om du vil vise det i UI senere:
    'country_name'  => $countryName,
    'display_name'  => (string)($data['display_name'] ?? ''),
]);
