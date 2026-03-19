<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\nodelokasjon\_auth.php
// Enkel AD/LDAP-pålogging for nodelokasjon-modulen (uten 2FA).
//
// Strategi (matcher fungerende kode):
// 1) Koble til en av AD_HOSTS
// 2) Bind direkte som bruker: username@AD_DOMAIN
// 3) Slå opp bruker for å hente displayName/cn
// 4) Lagre minimal session: nl_auth_user, nl_auth_name
//
// .env-verdier:
//   AD_HOSTS=BB-DC01.bbdrift.ad,BB-DC02.bbdrift.ad
//   AD_PORT=389 (LDAP) eller 636 (LDAPS)
//   AD_USE_TLS=false (LDAP) eller true (LDAPS)
//   AD_DOMAIN=bbdrift.ad
//   AD_BASE_DN=DC=bbdrift,DC=ad
//
// MERK:
// - Dette er en hjelpefil som skal inkluderes fra andre sider.
// - Direkte åpning returnerer 403 (for å unngå “hvit side”).

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ------------------------------------------------------------
   Blokker direkte tilgang
------------------------------------------------------------ */
$isDirectAccess = (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__);
if ($isDirectAccess) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "403 Forbidden\nDenne filen er en hjelpefil og skal inkluderes av modulen.\n";
  exit;
}

/* ------------------------------------------------------------
   Last Env (robust path)
------------------------------------------------------------ */
$envLoaded = false;
$envCandidates = [
  __DIR__ . '/../../app/Support/Env.php',
  dirname(__DIR__, 2) . '/app/Support/Env.php',
  dirname(__DIR__, 3) . '/app/Support/Env.php',
];

foreach ($envCandidates as $c) {
  if (is_file($c)) {
    require_once $c;
    $envLoaded = true;
    break;
  }
}

if (!$envLoaded || !class_exists(\App\Support\Env::class)) {
  // Ikke fatal her – vi gir klar feil ved login-forsøk
}

/* ------------------------------------------------------------
   Polyfill: ldap_escape
------------------------------------------------------------ */
if (!function_exists('ldap_escape')) {
  function ldap_escape(string $subject, string $ignore = '', int $flags = 0): string {
    $search  = ['\\', '*', '(', ')', "\x00"];
    $replace = ['\5c', '\2a', '\28', '\29', '\00'];
    return str_replace($search, $replace, $subject);
  }
}

/* ------------------------------------------------------------
   Session helpers
------------------------------------------------------------ */
function nl_is_logged_in(): bool {
  return !empty($_SESSION['nl_auth_user']);
}

function nl_current_user(): string {
  return (string)($_SESSION['nl_auth_user'] ?? '');
}

function nl_current_name(): string {
  return (string)($_SESSION['nl_auth_name'] ?? nl_current_user());
}

function nl_logout(): void {
  unset($_SESSION['nl_auth_user'], $_SESSION['nl_auth_name'], $_SESSION['nl_auth_dn']);
}

/* ------------------------------------------------------------
   Intern: bygg LDAP/LDAPS URI fra host + env
------------------------------------------------------------ */
function nl_build_ldap_uri(string $host, string $domain, int $port, bool $useTls): string {
  $host = trim($host);
  if ($host === '') return '';

  // Hvis host allerede er URI (ldap:// eller ldaps://) – bruk som den er
  if (preg_match('~^ldaps?://~i', $host)) {
    // Hvis bruker har skrevet inn uten port, kan vi la den stå (AD vil bruke default),
    // men om de har port satt i env og ikke i uri, legg på port.
    if (!preg_match('~:\d+~', $host) && $port > 0) {
      return rtrim($host, '/') . ':' . $port;
    }
    return $host;
  }

  // Hvis host ikke inneholder punktum og domain finnes, bruk host.domain
  if ($domain !== '' && !str_contains($host, '.')) {
    $host = $host . '.' . $domain;
  }

  // Viktig: 636 => ldaps:// (TLS), 389 => ldap://
  $scheme = $useTls ? 'ldaps' : 'ldap';

  return "{$scheme}://{$host}:" . $port;
}

/* ------------------------------------------------------------
   Login
------------------------------------------------------------ */
function nl_login(string $username, string $password, ?string &$error = null): bool {
  $username = trim($username);
  if ($username === '' || $password === '') {
    $error = 'Mangler brukernavn eller passord.';
    return false;
  }

  // Fjern domene-prefiks hvis noen skriver "DOMENE\bruker"
  if (str_contains($username, '\\')) {
    $parts = explode('\\', $username, 2);
    $username = $parts[1] ?? $username;
  }

  if (!class_exists(\App\Support\Env::class)) {
    $error = 'Env-loader mangler. Finner ikke app/Support/Env.php (sjekk path i _auth.php).';
    return false;
  }

  if (!function_exists('ldap_connect')) {
    $error = 'PHP LDAP-utvidelse er ikke aktivert (ldap_connect finnes ikke).';
    return false;
  }

  $hostsRaw = (string)(\App\Support\Env::get('AD_HOSTS', '') ?? '');
  $hosts = array_values(array_filter(array_map('trim', explode(',', $hostsRaw))));
  if (!$hosts) {
    $error = 'AD_HOSTS mangler i .env';
    return false;
  }

  $domain = (string)(\App\Support\Env::get('AD_DOMAIN', '') ?? '');
  $baseDn = (string)(\App\Support\Env::get('AD_BASE_DN', '') ?? '');
  $port   = (int)(\App\Support\Env::get('AD_PORT', '389') ?? '389');
  $useTls = \App\Support\Env::bool('AD_USE_TLS', false);

  if ($domain === '') { $error = 'AD_DOMAIN mangler i .env'; return false; }
  if ($baseDn === '') { $error = 'AD_BASE_DN mangler i .env'; return false; }

  // Hvis noen har satt port 636 men glemt AD_USE_TLS=true, fiks automatisk
  if ($port === 636) $useTls = true;

  // Bind-format: username@domain
  $bindRdn = "{$username}@{$domain}";

  $lastErr = '';
  foreach ($hosts as $h) {
    $uri = nl_build_ldap_uri($h, $domain, $port, $useTls);
    if ($uri === '') continue;

    $conn = @ldap_connect($uri);
    if (!$conn) {
      $lastErr = "Kunne ikke koble til {$uri}";
      continue;
    }

    @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    // Litt raskere og mer robust ved nettverksproblemer
    @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

    // 1) bind som bruker
    if (!@ldap_bind($conn, $bindRdn, $password)) {
      $lastErr = "Innlogging feilet mot {$uri}: " . (string)@ldap_error($conn);
      @ldap_unbind($conn);
      continue;
    }

    // 2) slå opp bruker for DN + navn
    $safeUser = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
    $safeUpn  = ldap_escape($bindRdn, '', LDAP_ESCAPE_FILTER);
    $filter = "(|(sAMAccountName={$safeUser})(userPrincipalName={$safeUpn}))";
    $attrs  = ['dn', 'displayName', 'cn', 'name'];

    $sr = @ldap_search($conn, $baseDn, $filter, $attrs);
    if (!$sr) {
      $lastErr = "Søk feilet: " . (string)@ldap_error($conn);
      @ldap_unbind($conn);
      continue;
    }

    $entries = @ldap_get_entries($conn, $sr);
    if (!is_array($entries) || (int)($entries['count'] ?? 0) < 1) {
      $lastErr = "Fant ikke bruker i AD.";
      @ldap_unbind($conn);
      continue;
    }

    $userDn = (string)($entries[0]['dn'] ?? '');
    $displayName = '';
    if (!empty($entries[0]['displayname'][0])) $displayName = (string)$entries[0]['displayname'][0];
    elseif (!empty($entries[0]['name'][0])) $displayName = (string)$entries[0]['name'][0];
    elseif (!empty($entries[0]['cn'][0])) $displayName = (string)$entries[0]['cn'][0];

    // OK – lag session
    $_SESSION['nl_auth_user'] = $username;
    $_SESSION['nl_auth_name'] = $displayName ?: $username;
    $_SESSION['nl_auth_dn']   = $userDn;

    @ldap_unbind($conn);
    return true;
  }

  $error = $lastErr ?: 'Kunne ikke logge inn.';
  return false;
}