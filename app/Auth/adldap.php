<?php
// app/auth/adldap.php

namespace App\Auth;

class AdLdap
{
    /** @var string[] */
    private array $hosts;
    private string $domain;
    private string $baseDn;
    private string $requiredGroup;

    /**
     * @param string[] $hosts Liste over AD-servere, f.eks. ['bb-dc01', 'bb-dc02']
     */
    public function __construct(array $hosts, string $domain, string $baseDn, string $requiredGroup)
    {
        $this->hosts         = $hosts;
        $this->domain        = $domain;
        $this->baseDn        = $baseDn;
        $this->requiredGroup = $requiredGroup;
    }

    /**
     * Koble til første AD-host som svarer.
     *
     * @return resource|\LDAP\Connection
     */
    private function connect()
    {
        $lastError = null;

        foreach ($this->hosts as $host) {
            // Matcher gammel fungerende kode: ldap://bb-dc01.bbdrift.ad
            $conn = @ldap_connect("ldap://{$host}.{$this->domain}");
            if ($conn === false) {
                $lastError = "Kunne ikke koble til LDAP-server {$host}.{$this->domain}";
                continue;
            }

            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

            return $conn;
        }

        throw new \RuntimeException($lastError ?? 'Kunne ikke koble til noen AD-servere.');
    }

    /**
     * Logger inn OG verifiserer at brukeren er medlem av requiredGroup.
     *
     * Hvis ikke: kaster RuntimeException – DA SKAL IKKE BRUKEREN INN.
     *
     * @return array{
     *   username: string,
     *   fullname: string,
     *   userDn: string,
     *   groupDn: string,
     *   groups: string[]
     * }
     */
    public function authenticate(string $username, string $password): array
    {
        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException('Brukernavn og passord må fylles ut.');
        }

        $conn = $this->connect();

        // Samme bind som i den gamle, fungerende koden:
        $bindRdn = "{$username}@{$this->domain}";

        if (!@ldap_bind($conn, $bindRdn, $password)) {
            $error = ldap_error($conn);
            @ldap_unbind($conn);
            throw new \RuntimeException("Innlogging feilet: {$error}");
        }

        // 1) Finn brukers DN
        $userDn = $this->getDn($conn, $username, $this->baseDn);
        if ($userDn === '') {
            @ldap_unbind($conn);
            throw new \RuntimeException('Fant ikke brukeren i AD.');
        }

        // 2) Finn gruppens DN (basert på sAMAccountName til gruppa)
        $groupDn = $this->getDn($conn, $this->requiredGroup, $this->baseDn);
        if ($groupDn === '') {
            @ldap_unbind($conn);
            throw new \RuntimeException("Fant ikke gruppen {$this->requiredGroup} i AD.");
        }

        // 3) Hent direkte grupper (for evt. senere bruk i applikasjonen)
        $directGroups = $this->getDirectGroups($conn, $userDn);

        // 4) Sjekk gruppemedlemskap rekursivt – MÅ være true
        $inGroup = $this->checkGroupRecursive($conn, $userDn, $groupDn);

        if (!$inGroup) {
            @ldap_unbind($conn);
            // Denne linjen er selve “rettighets-sperra”
            throw new \RuntimeException(
                "Brukeren mangler medlemskap i påkrevd AD-gruppe {$this->requiredGroup}."
            );
        }

        $cn = $this->getCn($userDn);

        @ldap_unbind($conn);

        return [
            'username'  => $username,
            'fullname'  => $cn,
            'userDn'    => $userDn,
            'groupDn'   => $groupDn,
            'groups'    => $directGroups,
        ];
    }

    /**
     * Samme funksjon som getDN() i den gamle koden din.
     */
    private function getDn($conn, string $samAccountName, string $baseDn): string
    {
        $filter = "(samaccountname={$samAccountName})";

        $result = @ldap_search($conn, $baseDn, $filter, ['dn']);
        if (!$result) {
            return '';
        }

        $entries = ldap_get_entries($conn, $result);
        if (!is_array($entries) || $entries['count'] <= 0) {
            return '';
        }

        return $entries[0]['dn'];
    }

    /**
     * Hent direkte medlemsskap (memberOf) for et gitt DN.
     *
     * @return string[] Liste av group-DNs
     */
    private function getDirectGroups($conn, string $dn): array
    {
        $result = @ldap_read($conn, $dn, '(objectclass=*)', ['memberof']);
        if (!$result) {
            return [];
        }

        $entries = ldap_get_entries($conn, $result);
        if (!is_array($entries) || $entries['count'] <= 0) {
            return [];
        }

        if (empty($entries[0]['memberof']) || !is_array($entries[0]['memberof'])) {
            return [];
        }

        $groups = [];
        for ($i = 0; $i < $entries[0]['memberof']['count']; $i++) {
            $groups[] = $entries[0]['memberof'][$i];
        }

        return $groups;
    }

    /**
     * Henter CN fra en DN (litt mer robust enn den gamle regexen).
     */
    private function getCn(string $dn): string
    {
        if (preg_match('/CN=([^,]+)/i', $dn, $matches)) {
            return $matches[1];
        }

        // fallback
        return $dn;
    }

    /**
     * Slår opp e-postadresse for en AD-bruker (mail-attributtet).
     * Bruker anonym/uautorisert bind – fungerer typisk på intern AD.
     */
    public function getUserEmail(string $username): ?string
    {
        $adminUser = (string)(($_ENV['AD_ADMIN_USER'] ?? null) ?: getenv('AD_ADMIN_USER') ?: '');
        $adminPass = (string)(($_ENV['AD_ADMIN_PASS'] ?? null) ?: getenv('AD_ADMIN_PASS') ?: '');

        $candidates = [
            [
                'dn'   => (string)(($_ENV['AD_BIND_DN']       ?? null) ?: getenv('AD_BIND_DN')       ?: ''),
                'pass' => (string)(($_ENV['AD_BIND_PASSWORD'] ?? null) ?: getenv('AD_BIND_PASSWORD') ?: ''),
            ],
            [
                'dn'   => $adminUser !== '' ? "{$adminUser}@{$this->domain}" : '',
                'pass' => $adminPass,
            ],
        ];

        $conn  = $this->connect();
        $bound = false;

        foreach ($candidates as $c) {
            if ($c['dn'] === '' || $c['pass'] === '') continue;
            if (@ldap_bind($conn, $c['dn'], $c['pass'])) { $bound = true; break; }
        }

        if (!$bound) {
            error_log('getUserEmail: alle LDAP-bind-forsøk feilet for oppslag av ' . $username);
            @ldap_unbind($conn);
            return null;
        }

        $filter  = '(sAMAccountName=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ')';
        $result  = @ldap_search($conn, $this->baseDn, $filter, ['mail', 'userPrincipalName']);

        if (!$result) {
            @ldap_unbind($conn);
            return null;
        }

        $entries = ldap_get_entries($conn, $result);
        @ldap_unbind($conn);

        if (!is_array($entries) || $entries['count'] <= 0) {
            return null;
        }

        // Foretrekk mail-attributtet, fall tilbake til userPrincipalName
        $mail = $entries[0]['mail'][0] ?? null;
        if ($mail && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            return strtolower($mail);
        }

        $upn = $entries[0]['userprincipalname'][0] ?? null;
        if ($upn && filter_var($upn, FILTER_VALIDATE_EMAIL)) {
            return strtolower($upn);
        }

        return null;
    }

    /**
     * Setter nytt AD-passord via LDAPS (port 636).
     *
     * Prioritet for autentisering:
     *   1. AD_ADMIN_USER + AD_ADMIN_PASS fra .env  (eksplisitt service-konto)
     *   2. Anonym bind                              (fungerer hvis IIS app pool kjører
     *                                                som en AD-konto med Reset Password-rettighet,
     *                                                da brukes Windows-identiteten automatisk)
     *
     * Anbefalt oppsett: sett IIS app pool identity til en dedikert AD-konto
     * med delegert «Reset Password»-rettighet på aktuell OU — da trengs ingen
     * passord i .env overhodet.
     *
     * @throws \RuntimeException
     */
    public function setPasswordAsAdmin(string $username, string $newPassword): void
    {
        $adminUser = (string)(($_ENV['AD_ADMIN_USER'] ?? null) ?: getenv('AD_ADMIN_USER') ?: '');
        $adminPass = (string)(($_ENV['AD_ADMIN_PASS'] ?? null) ?: getenv('AD_ADMIN_PASS') ?: '');

        // Passordendring krever LDAPS (port 636)
        $conn = null;
        foreach ($this->hosts as $host) {
            $c = @ldap_connect("ldaps://{$host}.{$this->domain}", 636);
            if ($c === false) continue;
            ldap_set_option($c, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($c, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($c, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
            $conn = $c;
            break;
        }

        if ($conn === null) {
            throw new \RuntimeException('Kunne ikke koble til AD over LDAPS (port 636).');
        }

        if ($adminUser !== '' && $adminPass !== '') {
            // Eksplisitt bind med service-konto fra .env
            $bindRdn = "{$adminUser}@{$this->domain}";
            if (!@ldap_bind($conn, $bindRdn, $adminPass)) {
                @ldap_unbind($conn);
                throw new \RuntimeException('Admin-bind mot AD feilet: ' . ldap_error($conn));
            }
        } else {
            // Forsøk bind med Windows-identiteten til prosessen (IIS app pool-konto)
            if (!@ldap_bind($conn)) {
                @ldap_unbind($conn);
                throw new \RuntimeException(
                    'LDAP-bind feilet. Konfigurer enten AD_ADMIN_USER/AD_ADMIN_PASS i .env, ' .
                    'eller sett IIS app pool identity til en AD-konto med Reset Password-rettighet.'
                );
            }
        }

        $userDn = $this->getDn($conn, $username, $this->baseDn);
        if ($userDn === '') {
            @ldap_unbind($conn);
            throw new \RuntimeException("Fant ikke brukeren {$username} i AD.");
        }

        // AD krever at passordet er UTF-16LE-kodet og omgitt av anførselstegn
        $encoded = iconv('UTF-8', 'UTF-16LE', '"' . $newPassword . '"');

        $result = @ldap_modify($conn, $userDn, ['unicodePwd' => [$encoded]]);
        @ldap_unbind($conn);

        if (!$result) {
            throw new \RuntimeException(
                'Passordendring i AD feilet. Sjekk at passordet møter AD-kompleksitetskravene.'
            );
        }
    }

    /**
     * Rekursiv gruppesjekk – tilsvarer gamle checkGroupEx().
     *
     * @param resource|\LDAP\Connection $conn
     */
    private function checkGroupRecursive($conn, string $dn, string $groupDn): bool
    {
        $result = @ldap_read($conn, $dn, '(objectclass=*)', ['memberof']);
        if (!$result) {
            return false;
        }

        $entries = ldap_get_entries($conn, $result);
        if (!is_array($entries) || $entries['count'] <= 0) {
            return false;
        }

        if (empty($entries[0]['memberof']) || !is_array($entries[0]['memberof'])) {
            return false;
        }

        for ($i = 0; $i < $entries[0]['memberof']['count']; $i++) {
            $current = $entries[0]['memberof'][$i];

            if ($current === $groupDn) {
                return true;
            }

            // Sjekk neste nivå (nested groups)
            if ($this->checkGroupRecursive($conn, $current, $groupDn)) {
                return true;
            }
        }

        return false;
    }
}
