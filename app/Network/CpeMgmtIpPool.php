<?php
// app/Network/CpeMgmtIpPool.php

declare(strict_types=1);

namespace App\Network;

use PDO;

class CpeMgmtIpPool
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Hent ledige IP-er (kun ikke-brukte).
     *
     * @return array<int, array{id:int, ip_address:string}>
     */
    public function getFreeIps(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, ip_address
               FROM cpe_mgmt_ip
              WHERE is_used = 0
              ORDER BY INET_ATON(ip_address)
              LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Merk IP som brukt og knytt den til en NNI-kunde.
     */
    public function assignToCustomer(int $ipId, int $customerId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cpe_mgmt_ip
                SET is_used = 1,
                    nni_customer_id = :cid
              WHERE id = :id'
        );
        $stmt->execute([
            ':cid' => $customerId,
            ':id'  => $ipId,
        ]);
    }

    /**
     * Frigjør IP og løs den fra kunden.
     */
    public function freeIp(int $ipId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cpe_mgmt_ip
                SET is_used = 0,
                    nni_customer_id = NULL
              WHERE id = :id'
        );
        $stmt->execute([':id' => $ipId]);
    }
}
