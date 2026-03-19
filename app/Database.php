<?php
// app/Database.php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    private const DEFAULT_DB_HOST    = 'localhost';
    private const DEFAULT_DB_NAME    = 'teknisk';
    private const DEFAULT_DB_USER    = 'web_user';
    private const DEFAULT_DB_PASS    = 'x8JV1F=KlVwR(h!6~R9?';
    private const DEFAULT_DB_CHARSET = 'utf8mb4';

    public static function getConnection(): PDO
    {
        if (!defined('DB_HOST')) {
            define('DB_HOST', self::DEFAULT_DB_HOST);
        }
        if (!defined('DB_NAME')) {
            define('DB_NAME', self::DEFAULT_DB_NAME);
        }
        if (!defined('DB_USER')) {
            define('DB_USER', self::DEFAULT_DB_USER);
        }
        if (!defined('DB_PASS')) {
            define('DB_PASS', self::DEFAULT_DB_PASS);
        }
        if (!defined('DB_CHARSET')) {
            define('DB_CHARSET', self::DEFAULT_DB_CHARSET);
        }

        if (self::$pdo === null) {
            $host    = DB_HOST;
            $db      = DB_NAME;
            $user    = DB_USER;
            $pass    = DB_PASS;
            $charset = DB_CHARSET;

            $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$pdo = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                die('DB-tilkobling feilet: ' . $e->getMessage());
            }
        }

        return self::$pdo;
    }
}
