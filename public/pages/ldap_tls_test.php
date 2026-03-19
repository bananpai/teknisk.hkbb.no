<?php
// /public/pages/ldap_tls_test.php
//
// Debug-side for å teste TLS/CA i PHP-prosessen (IIS AppPool)
// - Viser relevante env-vars
// - Tester OpenSSL stream til LDAPS (for å se om CA bundle blir brukt)
// - Tester ldap_connect + tvinger en LDAP-operasjon (RootDSE read) for å trigge LDAPS-handshake
//
// NB: Husk å fjerne siden når alt virker.

declare(strict_types=1);

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

$dc   = (string)($_GET['dc'] ?? 'bb-dc02.bbdrift.ad');
$port = (int)($_GET['port'] ?? 636);

$envKeys = [
    'LDAPTLS_CACERT',
    'LDAPTLS_CACERTFILE',
    'LDAPTLS_REQCERT',
    'LDAPCONF',
    'LDAPRC',
];

$env = [];
foreach ($envKeys as $k) {
    $env[$k] = getenv($k) !== false ? (string)getenv($k) : '';
}

$opensslResult = null;
$opensslError  = null;

$caFile = $env['LDAPTLS_CACERT'] ?: ($env['LDAPTLS_CACERTFILE'] ?: '');

$ctxOptions = [
    'ssl' => [
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'peer_name'         => $dc,
        'allow_self_signed' => false,
    ]
];

if ($caFile !== '') {
    $ctxOptions['ssl']['cafile'] = $caFile;
}

$ctx = stream_context_create($ctxOptions);

$fp = @stream_socket_client(
    "ssl://{$dc}:{$port}",
    $errno,
    $errstr,
    5,
    STREAM_CLIENT_CONNECT,
    $ctx
);

if ($fp) {
    $meta   = stream_get_meta_data($fp);
    $params = stream_context_get_params($fp);

    $opensslResult = [
        'connected' => true,
        'meta'      => $meta,
        'crypto'    => $params['options']['ssl'] ?? null,
    ];
    fclose($fp);
} else {
    $opensslResult = ['connected' => false];
    $opensslError  = "OpenSSL connect feilet: ($errno) $errstr";
}

// LDAP test
$ldapInfo = [
    'available'   => function_exists('ldap_connect'),
    'connect_url' => null,
    'forced_op'   => null,
    'forced_data' => null,
    'bind_ok'     => null,
    'errno'       => null,
    'error'       => null,
    'diag'        => null,
];

if ($ldapInfo['available']) {
    $url = "ldaps://{$dc}:{$port}";
    $ldapInfo['connect_url'] = $url;

    $ldap = @ldap_connect($url);
    if ($ldap !== false) {
        @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            @ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);
        }

        // Valgfri bindtest hvis du sender ?u=...&p=...
        $u = (string)($_GET['u'] ?? '');
        $p = (string)($_GET['p'] ?? '');

        if ($u !== '' && $p !== '') {
            $ok = @ldap_bind($ldap, $u, $p);
            $ldapInfo['bind_ok'] = (bool)$ok;
        }

        // Tving en LDAP-operasjon (RootDSE read) for å trigge LDAPS-handshake/verify i OpenLDAP
        $sr = @ldap_read($ldap, '', '(objectClass=*)', ['defaultNamingContext', 'supportedLDAPVersion']);
        if ($sr === false) {
            $ldapInfo['forced_op'] = 'ldap_read(rootDSE) FAILED';
        } else {
            $entries = @ldap_get_entries($ldap, $sr);
            $ldapInfo['forced_op'] = 'ldap_read(rootDSE) OK';
            $ldapInfo['forced_data'] = $entries;
        }

        $ldapInfo['errno'] = @ldap_errno($ldap);
        $ldapInfo['error'] = @ldap_error($ldap);

        if (defined('LDAP_OPT_DIAGNOSTIC_MESSAGE')) {
            $diag = null;
            if (@ldap_get_option($ldap, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag) && is_string($diag) && trim($diag) !== '') {
                $ldapInfo['diag'] = trim($diag);
            }
        }

        @ldap_unbind($ldap);
    } else {
        $ldapInfo['forced_op'] = 'ldap_connect() returned false';
    }
}

?>
<div class="container" style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; max-width:980px; margin:20px auto;">
    <h1 style="margin:0 0 10px 0;">LDAP/TLS test</h1>

    <p style="margin:0 0 12px 0;">
        Tester fra PHP-prosessen mot <code><?= h($dc) ?></code>:<code><?= h((string)$port) ?></code>
    </p>

    <h2>Env</h2>
    <pre style="background:#f5f5f5; padding:12px; border-radius:10px; overflow:auto;"><?= h(json_encode($env, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>

    <h2>OpenSSL test (ssl://...)</h2>
    <?php if ($opensslError): ?>
        <div style="padding:10px; border:1px solid #fecaca; background:#fef2f2; border-radius:10px; color:#991b1b;">
            <?= h($opensslError) ?>
        </div>
    <?php endif; ?>
    <pre style="background:#f5f5f5; padding:12px; border-radius:10px; overflow:auto;"><?= h(json_encode($opensslResult, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>

    <h2>LDAP (tvinger RootDSE-read)</h2>
    <pre style="background:#f5f5f5; padding:12px; border-radius:10px; overflow:auto;"><?= h(json_encode($ldapInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>

    <p style="color:#666; font-size:13px;">
        Hvis RootDSE-read feiler med issuer-problem, så finner OpenLDAP fortsatt ikke riktig CA-bundle.
        Hvis RootDSE-read er OK, er TLS/CA-delen løst, og evt. videre feil i passordbytte blir AD-policy/LDAP-operasjon.
    </p>
</div>

<?php
var_dump(function_exists('ldap_exop_passwd'), function_exists('ldap_exop'));
?>