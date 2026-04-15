<?php
// app/Database.php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        // Last .env via phpdotenv hvis DB-konfigurasjon ikke allerede er i miljøet
        if (empty($_ENV['DB_PASSWORD']) && getenv('DB_PASSWORD') === false) {
            $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
            if (is_file($envFile) && class_exists('\Dotenv\Dotenv')) {
                $dotenv = \Dotenv\Dotenv::createImmutable(dirname($envFile));
                $dotenv->safeLoad();
            }
        }

        $host    = (string)($_ENV['DB_HOST']     ?? getenv('DB_HOST')     ?: 'localhost');
        $db      = (string)($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'teknisk');
        $user    = (string)($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: '');
        $pass    = (string)($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');
        $charset = 'utf8mb4';

        if ($user === '' || $pass === '') {
            throw new \RuntimeException('Database-konfigurasjon mangler. Sjekk at .env er korrekt satt opp (DB_USERNAME, DB_PASSWORD).');
        }

        $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new \RuntimeException('Kunne ikke koble til databasen. Prøv igjen senere.');
        }

        return self::$pdo;
    }
}
