<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$targetUser = $argv[1] ?? '';
$newPassword = $argv[2] ?? '';
if ($targetUser === '' || $newPassword === '') {
    echo "Bruk: php test_ad_password_reset.php <brukernavn> <nyttpassord>\n";
    exit(1);
}

$hosts     = ['bb-dc01', 'bb-dc02'];
$domain    = 'bbdrift.ad';
$baseDn    = 'dc=bbdrift,dc=ad';
$adminUser = $_ENV['AD_ADMIN_USER'] ?? '';
$adminPass = $_ENV['AD_ADMIN_PASS'] ?? '';
$bindRdn   = "{$adminUser}@{$domain}";

echo "Admin:  {$bindRdn}\n";
echo "Mål:    {$targetUser}\n\n";

// Koble til LDAPS
$conn = null;
foreach ($hosts as $host) {
    $c = @ldap_connect("ldaps://{$host}.{$domain}", 636);
    if (!$c) continue;
    ldap_set_option($c, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($c, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($c, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
    $conn = $c;
    echo "Kobler til: ldaps://{$host}.{$domain}:636\n";
    break;
}
if (!$conn) { echo "FEIL: Kunne ikke koble til\n"; exit(1); }

// Bind
$bound = @ldap_bind($conn, $bindRdn, $adminPass);
echo "Bind:   " . ($bound ? "OK" : "FEILET – " . ldap_error($conn)) . "\n";
if (!$bound) { ldap_get_option($conn, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag); echo "Diag:   {$diag}\n"; exit(1); }

// Finn bruker-DN
$res = @ldap_search($conn, $baseDn, '(sAMAccountName=' . ldap_escape($targetUser, '', LDAP_ESCAPE_FILTER) . ')', ['dn']);
$entries = ldap_get_entries($conn, $res);
if ($entries['count'] === 0) { echo "FEIL: Bruker ikke funnet\n"; exit(1); }
$userDn = $entries[0]['dn'];
echo "DN:     {$userDn}\n";

// Sett passord
$encoded = iconv('UTF-8', 'UTF-16LE', '"' . $newPassword . '"');
$ok = @ldap_modify($conn, $userDn, ['unicodePwd' => [$encoded]]);
if ($ok) {
    echo "\nSUKSESS: Passordet er endret!\n";
} else {
    echo "\nFEIL: " . ldap_error($conn) . " (kode " . ldap_errno($conn) . ")\n";
    ldap_get_option($conn, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag);
    echo "Diag: {$diag}\n";
}
ldap_unbind($conn);
