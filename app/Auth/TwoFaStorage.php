<?php
// app/Auth/TwoFaStorage.php

declare(strict_types=1);

namespace App\Auth;

use PDO;

class TwoFaStorage
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Sync AD-bruker til databasen (oppretter hvis mangler, oppdaterer navn + grupper + last_login).
     *
     * Returnerer:
     * [
     *   'username'      => string,
     *   'full_name'     => ?string,
     *   'ad_groups'     => string[],
     *   'twofa_enabled' => bool,
     *   'twofa_secret'  => ?string,
     * ]
     */
    public function syncUserFromAd(string $username, ?string $fullName, array $adGroups): array
    {
        $this->pdo->beginTransaction();

        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :u FOR UPDATE');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();

        $groupsJson = json_encode($adGroups, JSON_UNESCAPED_UNICODE);

        if ($row === false) {
            // Ny bruker -> opprettes som INAKTIV (må aktiveres av admin i Teknisk)
            $insert = $this->pdo->prepare(
                'INSERT INTO users (username, display_name, ad_groups, last_login_at, is_active)
                 VALUES (:u, :n, :g, NOW(), 0)'
            );
            $insert->execute([
                ':u' => $username,
                ':n' => $fullName,
                ':g' => $groupsJson,
            ]);

            $id  = (int)$this->pdo->lastInsertId();
            $row = [
                'id'            => $id,
                'username'      => $username,
                'display_name'  => $fullName,
                'ad_groups'     => $groupsJson,
                'created_at'    => date('Y-m-d H:i:s'),
                'last_login_at' => date('Y-m-d H:i:s'),
                'is_active'     => 0,
                'twofa_enabled' => 0,
                'twofa_secret'  => null,
            ];
        } else {
            // Eksisterende bruker -> oppdater navn, grupper og sist innlogget
            $update = $this->pdo->prepare(
                'UPDATE users
                 SET display_name  = :n,
                     ad_groups     = :g,
                     last_login_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                ':n'  => $fullName,
                ':g'  => $groupsJson,
                ':id' => $row['id'],
            ]);

            $row['display_name']  = $fullName;
            $row['ad_groups']     = $groupsJson;
            $row['last_login_at'] = date('Y-m-d H:i:s');
        }

        $this->pdo->commit();

        return [
            'username'      => $row['username'],
            'full_name'     => $row['display_name'],
            'ad_groups'     => $adGroups,
            'twofa_enabled' => (bool)($row['twofa_enabled'] ?? 0),
            'twofa_secret'  => $row['twofa_secret'] ?? null,
        ];
    }

    public function saveTwoFa(string $username, bool $enabled, ?string $secret): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET twofa_enabled = :e,
                 twofa_secret  = :s
             WHERE username   = :u'
        );
        $stmt->execute([
            ':e' => $enabled ? 1 : 0,
            ':s' => $secret,
            ':u' => $username,
        ]);
    }

    /**
     * @return array{enabled:bool,secret:?string}
     */
    public function loadTwoFa(string $username): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT twofa_enabled, twofa_secret
               FROM users
              WHERE username = :u'
        );
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();

        if ($row === false) {
            return ['enabled' => false, 'secret' => null];
        }

        return [
            'enabled' => (bool)$row['twofa_enabled'],
            'secret'  => $row['twofa_secret'],
        ];
    }
}
