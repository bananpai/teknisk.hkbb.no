<?php
// app/Network/MgmtIpPool.php

declare(strict_types=1);

namespace App\Network;

use PDO;

class MgmtIpPool
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Hent ledige IP-adresser (standard: maks 50 stk).
     *
     * @return array<int, array{id:int, ip_address:string}>
     */
    public function getFreeIps(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, ip_address
               FROM mgmnt_ip
              WHERE is_used = 0
              ORDER BY INET_ATON(ip_address)
              LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Merk en IP som brukt.
     */
    public function markUsed(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mgmnt_ip SET is_used = 1 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    /**
     * Merk en IP som ledig igjen.
     */
    public function markFree(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mgmnt_ip SET is_used = 0 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }
}
