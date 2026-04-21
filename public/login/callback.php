<?php
// public/login/callback.php – OAuth2-callback fra Microsoft Entra ID

declare(strict_types=1);

session_start();

require __DIR__ . '/../../vendor/autoload.php';

use App\Auth\EntraAuth;
use App\Auth\TwoFaStorage;
use App\Database;

function entra_error(string $msg): never
{
    $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    http_response_code(400);
    echo "<!doctype html><html lang='no'><head><meta charset='utf-8'><title>Pålogging feilet</title></head>"
       . "<body style='font-family:system-ui;padding:2rem'>"
       . "<h2>Pålogging feilet</h2><p>{$safe}</p>"
       . "<a href='/login/'>Prøv igjen</a></body></html>";
    exit;
}

// Microsoft kan sende error-parameter ved avbrutt eller feilet pålogging
if (isset($_GET['error'])) {
    $desc = $_GET['error_description'] ?? $_GET['error'];
    error_log('Entra callback feil: ' . $desc);
    header('Location: /login/?noaccess=1');
    exit;
}

// Valider state (CSRF)
$state        = $_GET['state'] ?? '';
$sessionState = $_SESSION['entra_state'] ?? '';

if ($state === '' || $sessionState === '' || !hash_equals($sessionState, $state)) {
    error_log('Entra callback: ugyldig state-parameter.');
    entra_error('Ugyldig forespørsel (state-mismatch). Vennligst prøv igjen.');
}

unset($_SESSION['entra_state']);

$code = $_GET['code'] ?? '';
if ($code === '') {
    entra_error('Mangler autorisasjonskode fra Microsoft.');
}

// Hent Entra-konfig fra DB
try {
    $pdo   = Database::getConnection();
    $entra = EntraAuth::loadFromDb($pdo);
} catch (\Throwable $e) {
    error_log('Entra callback: DB-tilkobling feilet: ' . $e->getMessage());
    entra_error('Intern feil – kontakt administrator.');
}

if ($entra === null) {
    entra_error('Microsoft-innlogging er ikke aktivert eller konfigurert.');
}

// Veksle kode mot token og hent claims
try {
    $result = $entra->exchangeCode($code);
} catch (\Throwable $e) {
    error_log('Entra token-utveksling feilet: ' . $e->getMessage());
    header('Location: /login/?noaccess=1');
    exit;
}

$claims = $result['claims'];

// Hent brukernavn fra claims (UPN foretrekkes, fallback til email eller sub)
$username    = $claims['preferred_username'] ?? $claims['email'] ?? $claims['sub'] ?? '';
$displayName = $claims['name'] ?? $username;
$email       = $claims['email'] ?? ($claims['preferred_username'] ?? null);

if ($username === '') {
    error_log('Entra callback: mangler brukernavn i claims.');
    entra_error('Kunne ikke identifisere bruker fra Microsoft-pålogging.');
}

// Normaliser brukernavn til lowercase
$username = strtolower($username);

// Synk bruker til DB
$twoFaEnabled = false;
$twoFaSecret  = null;

try {
    $twoFaStorage = new TwoFaStorage($pdo);
    $userRow      = $twoFaStorage->syncUserFromEntra($username, $displayName, $email);

    $twoFaEnabled = $userRow['twofa_enabled'];
    $twoFaSecret  = $userRow['twofa_secret'];
} catch (\Throwable $dbEx) {
    error_log('Entra: DB-synk feilet for ' . $username . ': ' . $dbEx->getMessage());
    // Fortsett pålogging selv om DB-synk feiler
}

// Opprett sesjon
session_regenerate_id(true);

$_SESSION['username']       = $username;
$_SESSION['fullname']       = $displayName;
$_SESSION['ad_groups']      = [];
$_SESSION['required_group'] = 'teknisk';
$_SESSION['teknisk']        = 'Yes'; // Kompatibilitet med eksisterende session-sjekk
$_SESSION['auth_provider']  = 'entra';

$_SESSION['2fa_enabled']    = false;
$_SESSION['2fa_configured'] = $twoFaEnabled && !empty($twoFaSecret);

header('Location: /?page=start');
exit;
