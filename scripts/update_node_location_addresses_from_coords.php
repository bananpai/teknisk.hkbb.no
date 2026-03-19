<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\scripts\update_node_location_addresses_from_coords.php

declare(strict_types=1);

/**
 * Oppdaterer node_locations-adresser fra lat/lon via reverse geocoding.
 *
 * Kjøring:
 *   php scripts/update_node_location_addresses_from_coords.php
 *   php scripts/update_node_location_addresses_from_coords.php --dry-run
 *   php scripts/update_node_location_addresses_from_coords.php --limit=25
 *   php scripts/update_node_location_addresses_from_coords.php --id=123
 *   php scripts/update_node_location_addresses_from_coords.php --force
 *
 * Standard:
 * - Oppdaterer bare rader som har lat/lon
 * - Hopper over rader som allerede har adresse, med mindre --force brukes
 * - Leser DB-tilkobling fra prosjektets .env-fil
 * - Sover litt mellom oppslag for å være snill mot geokodingstjenesten
 *
 * VIKTIG:
 * - Sett GEOCODER_USER_AGENT og GEOCODER_CONTACT_EMAIL i .env hvis ønskelig
 * - For store batch-jobber bør du bruke egen geokoding-instans / annen leverandør
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

/* ------------------------------------------------------------
 | Konfigurasjon
 * ------------------------------------------------------------ */

const DEFAULT_GEOCODER_BASE_URL = 'https://nominatim.openstreetmap.org/reverse';
const DEFAULT_REQUEST_DELAY_US = 1100000;
const DEFAULT_HTTP_TIMEOUT_SECONDS = 20;
const DEFAULT_MAX_RETRIES = 3;
const DEFAULT_LOG_FILE = __DIR__ . '/update_node_location_addresses_from_coords.log';

/* ------------------------------------------------------------
 | CLI-opsjoner
 * ------------------------------------------------------------ */

$options = getopt('', [
    'dry-run',
    'force',
    'limit:',
    'id:',
]);

$dryRun = array_key_exists('dry-run', $options);
$force = array_key_exists('force', $options);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 0;
$singleId = isset($options['id']) ? max(1, (int)$options['id']) : 0;

/* ------------------------------------------------------------
 | Hjelpefunksjoner
 * ------------------------------------------------------------ */

function projectRootPath(): string
{
    return dirname(__DIR__);
}

function envFilePath(): string
{
    return projectRootPath() . DIRECTORY_SEPARATOR . '.env';
}

function parseEnvFile(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Fant ikke .env-filen: {$path}");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("Kunne ikke lese .env-filen: {$path}");
    }

    $env = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $pos = strpos($trimmed, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($trimmed, 0, $pos));
        $value = trim(substr($trimmed, $pos + 1));

        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

function appEnv(): array
{
    static $env = null;

    if (is_array($env)) {
        return $env;
    }

    $env = parseEnvFile(envFilePath());
    return $env;
}

function envValue(string $key, ?string $default = null): ?string
{
    $env = appEnv();
    return array_key_exists($key, $env) ? $env[$key] : $default;
}

function out(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    echo $line . PHP_EOL;
    file_put_contents(logFilePath(), $line . PHP_EOL, FILE_APPEND);
}

function logFilePath(): string
{
    $custom = envValue('GEOCODER_LOG_FILE');
    return ($custom !== null && trim($custom) !== '') ? $custom : DEFAULT_LOG_FILE;
}

function buildDsn(): string
{
    $host = envValue('DB_HOST', '127.0.0.1');
    $port = (int)(envValue('DB_PORT', '3306') ?? '3306');
    $name = envValue('DB_DATABASE');

    if ($name === null || $name === '') {
        throw new RuntimeException('DB_DATABASE mangler i .env');
    }

    return sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $name
    );
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $username = envValue('DB_USERNAME');
    $password = envValue('DB_PASSWORD', '');

    if ($username === null || $username === '') {
        throw new RuntimeException('DB_USERNAME mangler i .env');
    }

    $pdo = new PDO(
        buildDsn(),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $pdo;
}

function geocoderBaseUrl(): string
{
    return envValue('GEOCODER_BASE_URL', DEFAULT_GEOCODER_BASE_URL) ?: DEFAULT_GEOCODER_BASE_URL;
}

function geocoderUserAgent(): string
{
    $value = envValue('GEOCODER_USER_AGENT');
    if ($value !== null && trim($value) !== '') {
        return $value;
    }

    return 'teknisk.hkbb.no/1.0 (reverse-geocoding node_locations)';
}

function geocoderContactEmail(): string
{
    $value = envValue('GEOCODER_CONTACT_EMAIL');
    return $value !== null ? trim($value) : '';
}

function requestDelayUs(): int
{
    return max(0, (int)(envValue('GEOCODER_REQUEST_DELAY_US', (string)DEFAULT_REQUEST_DELAY_US) ?? DEFAULT_REQUEST_DELAY_US));
}

function httpTimeoutSeconds(): int
{
    return max(5, (int)(envValue('GEOCODER_HTTP_TIMEOUT', (string)DEFAULT_HTTP_TIMEOUT_SECONDS) ?? DEFAULT_HTTP_TIMEOUT_SECONDS));
}

function maxRetries(): int
{
    return max(1, (int)(envValue('GEOCODER_MAX_RETRIES', (string)DEFAULT_MAX_RETRIES) ?? DEFAULT_MAX_RETRIES));
}

/**
 * Returnerer kandidater som skal reverse-geokodes.
 */
function fetchCandidates(bool $force, int $limit, int $singleId): array
{
    $sql = "
        SELECT
            id,
            name,
            lat,
            lon,
            address_line1,
            postal_code,
            city,
            region,
            country
        FROM node_locations
        WHERE lat IS NOT NULL
          AND lon IS NOT NULL
    ";

    $params = [];

    if ($singleId > 0) {
        $sql .= " AND id = :id ";
        $params[':id'] = $singleId;
    }

    if (!$force) {
        $sql .= "
          AND (
                address_line1 IS NULL OR address_line1 = ''
             OR postal_code   IS NULL OR postal_code   = ''
             OR city          IS NULL OR city          = ''
             OR region        IS NULL OR region        = ''
             OR country       IS NULL OR country       = ''
          )
        ";
    }

    $sql .= " ORDER BY id ASC ";

    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Kaller reverse-geocoding API.
 */
function reverseGeocode(float $lat, float $lon): array
{
    $query = [
        'format' => 'jsonv2',
        'addressdetails' => 1,
        'zoom' => 18,
        'lat' => number_format($lat, 7, '.', ''),
        'lon' => number_format($lon, 7, '.', ''),
    ];

    $contactEmail = geocoderContactEmail();
    if ($contactEmail !== '') {
        $query['email'] = $contactEmail;
    }

    $url = geocoderBaseUrl() . '?' . http_build_query($query);

    $headers = [
        'User-Agent: ' . geocoderUserAgent(),
        'Accept: application/json',
    ];

    $attempt = 0;
    $lastError = null;
    $timeout = httpTimeoutSeconds();

    while ($attempt < maxRetries()) {
        $attempt++;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            $lastError = "cURL-feil: {$error}";
        } elseif ($httpCode < 200 || $httpCode >= 300) {
            $lastError = "HTTP {$httpCode} fra geokoder";
        } else {
            $json = json_decode((string)$body, true);

            if (!is_array($json)) {
                $lastError = 'Ugyldig JSON fra geokoder';
            } elseif (isset($json['error']) && $json['error'] !== '') {
                $lastError = 'API-feil: ' . (string)$json['error'];
            } else {
                return $json;
            }
        }

        if ($attempt < maxRetries()) {
            usleep(1500000);
        }
    }

    throw new RuntimeException($lastError ?? 'Ukjent feil ved reverse geocoding');
}

/**
 * Henter ut ønskede adressefelt fra geocoder-respons.
 */
function mapAddressFields(array $geo): array
{
    $address = isset($geo['address']) && is_array($geo['address']) ? $geo['address'] : [];

    $houseNumber = trim((string)($address['house_number'] ?? ''));
    $road = trim((string)($address['road'] ?? ''));
    $pedestrian = trim((string)($address['pedestrian'] ?? ''));
    $footway = trim((string)($address['footway'] ?? ''));
    $path = trim((string)($address['path'] ?? ''));
    $residential = trim((string)($address['residential'] ?? ''));

    $street = firstNonEmpty([
        $road,
        $pedestrian,
        $footway,
        $path,
        $residential,
    ]) ?? '';

    $addressLine1 = trim($street . ($houseNumber !== '' ? ' ' . $houseNumber : ''));

    $city = firstNonEmpty([
        $address['city'] ?? null,
        $address['town'] ?? null,
        $address['village'] ?? null,
        $address['hamlet'] ?? null,
        $address['municipality'] ?? null,
    ]);

    $region = firstNonEmpty([
        $address['county'] ?? null,
        $address['state_district'] ?? null,
        $address['state'] ?? null,
        $address['region'] ?? null,
    ]);

    $postalCode = trim((string)($address['postcode'] ?? ''));
    $countryCode = strtoupper(trim((string)($address['country_code'] ?? '')));

    return [
        'address_line1' => $addressLine1 !== '' ? $addressLine1 : null,
        'postal_code' => $postalCode !== '' ? $postalCode : null,
        'city' => $city,
        'region' => $region,
        'country' => $countryCode !== '' ? $countryCode : null,
        'display_name' => trim((string)($geo['display_name'] ?? '')),
    ];
}

function firstNonEmpty(array $values): ?string
{
    foreach ($values as $value) {
        $v = trim((string)$value);
        if ($v !== '') {
            return $v;
        }
    }

    return null;
}

/**
 * Oppdater databasen.
 */
function updateNodeLocation(int $id, array $mapped, string $updatedBy = 'reverse-geocode-script'): void
{
    $sql = "
        UPDATE node_locations
        SET
            address_line1 = :address_line1,
            postal_code   = :postal_code,
            city          = :city,
            region        = :region,
            country       = :country,
            updated_by    = :updated_by,
            updated_at    = NOW()
        WHERE id = :id
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':address_line1' => $mapped['address_line1'],
        ':postal_code'   => $mapped['postal_code'],
        ':city'          => $mapped['city'],
        ':region'        => $mapped['region'],
        ':country'       => $mapped['country'],
        ':updated_by'    => $updatedBy,
        ':id'            => $id,
    ]);
}

/* ------------------------------------------------------------
 | Kjøring
 * ------------------------------------------------------------ */

try {
    $env = appEnv();

    out('Starter reverse geocoding av node_locations...');
    out('Miljøfil: ' . envFilePath());
    out('Database: ' . (envValue('DB_DATABASE', '') ?: '(mangler)'));
    out('Bruker: ' . (envValue('DB_USERNAME', '') ?: '(mangler)'));
    out('Dry-run: ' . ($dryRun ? 'JA' : 'NEI'));
    out('Force: ' . ($force ? 'JA' : 'NEI'));
    out('Limit: ' . ($limit > 0 ? (string)$limit : 'ingen'));
    out('ID-filter: ' . ($singleId > 0 ? (string)$singleId : 'ingen'));
    out('Geokoder: ' . geocoderBaseUrl());

    $rows = fetchCandidates($force, $limit, $singleId);
    out('Fant ' . count($rows) . ' kandidater.');

    if (!$rows) {
        out('Ingen rader å behandle.');
        exit(0);
    }

    $updated = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $name = (string)$row['name'];
        $lat = (float)$row['lat'];
        $lon = (float)$row['lon'];

        out("Behandler ID {$id} / {$name} ({$lat}, {$lon})");

        try {
            $geo = reverseGeocode($lat, $lon);
            $mapped = mapAddressFields($geo);

            $hasAnyAddress =
                !empty($mapped['address_line1']) ||
                !empty($mapped['postal_code']) ||
                !empty($mapped['city']) ||
                !empty($mapped['region']) ||
                !empty($mapped['country']);

            if (!$hasAnyAddress) {
                out('  Hopper over: ingen brukbare adressefelt returnert.');
                $skipped++;
            } else {
                out(
                    "  Treff: "
                    . "adresse='" . (string)($mapped['address_line1'] ?? '') . "', "
                    . "postnr='" . (string)($mapped['postal_code'] ?? '') . "', "
                    . "by='" . (string)($mapped['city'] ?? '') . "', "
                    . "region='" . (string)($mapped['region'] ?? '') . "', "
                    . "land='" . (string)($mapped['country'] ?? '') . "'"
                );

                if (!$dryRun) {
                    updateNodeLocation($id, $mapped);
                }

                $updated++;
            }
        } catch (Throwable $e) {
            out('  FEIL: ' . $e->getMessage());
            $failed++;
        }

        usleep(requestDelayUs());
    }

    out("Ferdig. Oppdatert: {$updated}, hoppet over: {$skipped}, feilet: {$failed}");
    exit(0);
} catch (Throwable $e) {
    out('Avbrutt med feil: ' . $e->getMessage());
    exit(1);
}