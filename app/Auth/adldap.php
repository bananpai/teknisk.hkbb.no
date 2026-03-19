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
