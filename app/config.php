<?php
namespace App;

use Dotenv\Dotenv;
use PDO;

class Config {
    public array $env;
    public PDO $db;

    public function __construct(string $envPath) {
        $dotenv = Dotenv::createImmutable(dirname($envPath));
        $dotenv->load();
        $this->env = $_ENV;

        $host = $_ENV["DB_HOST"] ?? "127.0.0.1";
        $port = (int)($_ENV["DB_PORT"] ?? 3306);
        $db   = $_ENV["DB_DATABASE"] ?? "teknisk";
        $user = $_ENV["DB_USERNAME"] ?? "";
        $pass = $_ENV["DB_PASSWORD"] ?? "";

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $this->db = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function env(string $key, $default=null) {
        return $this->env[$key] ?? $default;
    }
}
