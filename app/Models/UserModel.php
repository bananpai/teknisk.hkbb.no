<?php
namespace App\Models;

use App\Config;
use PDO;

class UserModel {
    public function __construct(private Config $cfg){}

    public function findOrCreate(array $ad): array {
        $db = $this->cfg->db;
        $stmt = $db->prepare("SELECT u.*, s.theme FROM users u LEFT JOIN user_settings s ON s.user_id=u.id WHERE username = ?");
        $stmt->execute([$ad["username"]]);
        $user = $stmt->fetch();
        if($user) return $user;

        $ins = $db->prepare("INSERT INTO users (username, display_name, email) VALUES (?,?,?)");
        $ins->execute([$ad["username"], $ad["display_name"] ?? $ad["username"], $ad["email"] ?? null]);
        $id = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO user_settings (user_id, theme) VALUES (?, ?)")->execute([$id, $_ENV["DEFAULT_THEME"] ?? "Yeti"]);
        return $this->findById($id);
    }

    public function findById(int $id): ?array {
        $stmt = $this->cfg->db->prepare("SELECT u.*, s.theme FROM users u LEFT JOIN user_settings s ON s.user_id=u.id WHERE u.id=?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getTotp(int $userId): ?array {
        $stmt = $this->cfg->db->prepare("SELECT * FROM user_totp WHERE user_id=?");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public function setTotp(int $userId, string $secret): void {
        $sql = "INSERT INTO user_totp (user_id, secret, is_enabled)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE secret=VALUES(secret), is_enabled=1";
        $this->cfg->db->prepare($sql)->execute([$userId, $secret]);
    }
}
