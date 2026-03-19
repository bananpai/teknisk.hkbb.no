<?php
namespace App\Auth;

use OTPHP\TOTP;

class TotpService {
    public function create(string $issuer, string $label): array {
        $totp = TOTP::create();
        $totp->setLabel($label);
        $totp->setIssuer($issuer);
        return ["secret"=>$totp->getSecret(), "uri"=>$totp->getProvisioningUri()];
    }
    public function verify(string $secret, string $code): bool {
        $totp = TOTP::create($secret);
        return $totp->verify($code, null, 1);
    }
}
