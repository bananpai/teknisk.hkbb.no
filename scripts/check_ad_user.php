<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$username = $argv[1] ?? '';
if ($username === '') { echo "Bruk: php check_ad_user.php <brukernavn>\n"; exit(1); }

$hosts    = ['bb-dc01', 'bb-dc02'];
$domain   = 'bbdrift.ad';
$baseDn   = 'dc=bbdrift,dc=ad';
$candidates = [
    ['dn' => $_ENV['AD_BIND_DN'] ?? '',   'pass' => $_ENV['AD_BIND_PASSWORD'] ?? ''],
    ['dn' => ($_ENV['AD_ADMIN_USER'] ?? '') . "@{$domain}", 'pass' => $_ENV['AD_ADMIN_PASS'] ?? ''],
];

$conn = null;
foreach ($hosts as $host) {
    $c = @ldap_connect("ldap://{$host}.{$domain}");
    if ($c) { ldap_set_option($c, LDAP_OPT_PROTOCOL_VERSION, 3); ldap_set_option($c, LDAP_OPT_REFERRALS, 0); $conn = $c; break; }
}
if (!$conn) { echo "Kunne ikke koble til AD\n"; exit(1); }

$bound = false;
foreach ($candidates as $c) {
    if ($c['dn'] === '' || $c['pass'] === '') continue;
    echo "Prøver bind: {$c['dn']}\n";
    $bound = @ldap_bind($conn, $c['dn'], $c['pass']);
    if ($bound) { echo "Bind: OK\n\n"; break; }
    echo "Bind: FEILET – " . ldap_error($conn) . "\n";
}

if (!$bound) { echo "\nAlle bind-forsøk feilet.\n"; exit(1); }

$filter  = '(sAMAccountName=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ')';
$result  = @ldap_search($conn, $baseDn, $filter, ['mail', 'userPrincipalName', 'displayName', 'cn', 'sAMAccountName']);
$entries = ldap_get_entries($conn, $result);

if ($entries['count'] === 0) { echo "Bruker ikke funnet i AD\n"; exit(1); }

$attrs = ['cn', 'samaccountname', 'displayname', 'mail', 'userprincipalname'];
echo "Attributter for '{$username}':\n";
foreach ($attrs as $attr) {
    $val = $entries[0][$attr][0] ?? '(ikke satt)';
    echo "  {$attr}: {$val}\n";
}
