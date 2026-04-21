<?php
// app/Auth/EntraAuth.php

declare(strict_types=1);

namespace App\Auth;

use PDO;

class EntraAuth
{
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct(
        string $tenantId,
        string $clientId,
        string $clientSecret,
        string $redirectUri
    ) {
        $this->tenantId     = $tenantId;
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri  = $redirectUri;
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = http_build_query([
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'scope'         => 'openid profile email',
            'state'         => $state,
            'response_mode' => 'query',
        ]);

        return "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/authorize?{$params}";
    }

    /**
     * Exchange authorization code for tokens. Returns decoded claims from the ID token.
     * @return array{claims: array}
     * @throws \RuntimeException on failure
     */
    public function exchangeCode(string $code): array
    {
        $tokenUrl = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";

        $postData = http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $postData,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = file_get_contents($tokenUrl, false, $ctx);

        if ($response === false) {
            throw new \RuntimeException('Kunne ikke kontakte Microsoft token-endepunkt.');
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Ugyldig svar fra Microsoft token-endepunkt.');
        }

        if (isset($data['error'])) {
            $msg = $data['error_description'] ?? $data['error'];
            throw new \RuntimeException('Token-utveksling feilet: ' . $msg);
        }

        if (empty($data['id_token'])) {
            throw new \RuntimeException('Mangler id_token i svar fra Microsoft.');
        }

        return ['claims' => $this->decodeIdToken($data['id_token'])];
    }

    private function decodeIdToken(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new \RuntimeException('Ugyldig ID-token format.');
        }

        $payload = str_replace(['-', '_'], ['+', '/'], $parts[1]);
        $mod     = strlen($payload) % 4;
        if ($mod > 0) {
            $payload .= str_repeat('=', 4 - $mod);
        }

        $decoded = json_decode(base64_decode($payload), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Kunne ikke dekode ID-token payload.');
        }

        return $decoded;
    }

    /**
     * Load EntraAuth instance from system_settings DB table.
     * Returns null if Entra is disabled or not fully configured.
     */
    public static function loadFromDb(PDO $pdo): ?self
    {
        try {
            $stmt = $pdo->query(
                "SELECT setting_key, setting_value FROM system_settings
                 WHERE setting_key IN (
                     'entra_enabled', 'entra_tenant_id', 'entra_client_id',
                     'entra_client_secret', 'entra_redirect_uri'
                 )"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) {
            return null;
        }

        if (($rows['entra_enabled'] ?? '0') !== '1') {
            return null;
        }

        $tenantId     = trim($rows['entra_tenant_id']     ?? '');
        $clientId     = trim($rows['entra_client_id']     ?? '');
        $clientSecret = trim($rows['entra_client_secret'] ?? '');
        $redirectUri  = trim($rows['entra_redirect_uri']  ?? '');

        if ($tenantId === '' || $clientId === '' || $clientSecret === '' || $redirectUri === '') {
            return null;
        }

        return new self($tenantId, $clientId, $clientSecret, $redirectUri);
    }

    public static function isEnabled(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'entra_enabled'"
            );
            $stmt->execute();
            return $stmt->fetchColumn() === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
