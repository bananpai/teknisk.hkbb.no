<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\app\Support\Env.php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

final class Env
{
    private static bool $loaded = false;

    /**
     * Last .env én gang (best effort).
     * Støtter linjer: KEY=VALUE, KEY="VALUE", # kommentarer.
     */
    public static function load(?string $envFile = null): void
    {
        if (self::$loaded) return;

        $root = self::rootPath();
        $file = $envFile ?: ($root . DIRECTORY_SEPARATOR . '.env');

        if (is_file($file) && is_readable($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim((string)$line);
                    if ($line === '' || str_starts_with($line, '#')) continue;

                    // KEY=VALUE
                    $pos = strpos($line, '=');
                    if ($pos === false) continue;

                    $key = trim(substr($line, 0, $pos));
                    $val = trim(substr($line, $pos + 1));

                    if ($key === '') continue;

                    // Fjern evt. "..." eller '...'
                    if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                        (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                        $val = substr($val, 1, -1);
                    }

                    // Unescape vanlige sekvenser
                    $val = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $val);

                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                    @putenv($key . '=' . $val);
                }
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null || $v === '') return $default;
        return (string)$v;
    }

    /**
     * Hent bool fra miljøet.
     * Godtar typiske sann/usann-varianter:
     *  - true:  1, true, yes, on, y
     *  - false: 0, false, no, off, n
     */
    public static function bool(string $key, bool $default = false): bool
    {
        self::load();
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($v === false || $v === null) return $default;

        $s = strtolower(trim((string)$v));
        if ($s === '') return $default;

        if (in_array($s, ['1', 'true', 'yes', 'on', 'y'], true)) return true;
        if (in_array($s, ['0', 'false', 'no', 'off', 'n'], true)) return false;

        return $default;
    }

    public static function rootPath(): string
    {
        // ...\app\Support -> ...\app -> ...\ (prosjektroot ved siden av public/)
        $p = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..');
        return $p ?: (dirname(__DIR__, 2));
    }
}

/**
 * Hent PDO fra miljøet.
 *
 * Foretrukket:
 * - Bruk App\Database::getConnection() hvis klassen finnes og metoden finnes.
 *
 * Fallback (PDO):
 * - DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_CHARSET
 * - eller DATABASE_URL (mysql://user:pass@host:port/dbname)
 */
function pdo_from_env(): PDO
{
    Env::load();

    // 1) Bruk eksisterende Database helper hvis den finnes
    $dbFile = Env::rootPath() . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'database.php';
    if (is_file($dbFile)) {
        require_once $dbFile;
    }

    if (class_exists('\\App\\Database') && method_exists('\\App\\Database', 'getConnection')) {
        /** @var PDO $pdo */
        $pdo = \App\Database::getConnection();
        // Sikre sane defaults
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    // 2) Fallback: bygg PDO direkte
    $url = Env::get('DATABASE_URL');
    $charset = Env::get('DB_CHARSET', 'utf8mb4') ?: 'utf8mb4';

    if ($url) {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') === '') {
            throw new RuntimeException('Ugyldig DATABASE_URL');
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'mysql') {
            throw new RuntimeException('DATABASE_URL støtter kun mysql:// i denne løsningen');
        }

        $host = (string)($parts['host'] ?? '127.0.0.1');
        $port = (int)($parts['port'] ?? 3306);
        $user = (string)($parts['user'] ?? '');
        $pass = (string)($parts['pass'] ?? '');
        $db   = ltrim((string)($parts['path'] ?? ''), '/');

        if ($db === '' || $user === '') {
            throw new RuntimeException('DATABASE_URL mangler dbnavn eller bruker');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }

    $host = Env::get('DB_HOST', '127.0.0.1') ?: '127.0.0.1';
    $port = (int)(Env::get('DB_PORT', '3306') ?: '3306');
    $name = Env::get('DB_NAME');
    $user = Env::get('DB_USER');
    $pass = Env::get('DB_PASS', '');

    if (!$name || !$user) {
        throw new RuntimeException('Mangler DB_NAME og/eller DB_USER i .env');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, (string)$pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}