<?php

declare(strict_types=1);

namespace App;

use PDO;

class Audit
{
    const CAT_AUTH     = 'auth';
    const CAT_USER     = 'user_mgmt';
    const CAT_SECURITY = 'security';
    const CAT_SYSTEM   = 'system';

    const SEV_INFO     = 'info';
    const SEV_WARNING  = 'warning';
    const SEV_CRITICAL = 'critical';

    private static bool $tableChecked = false;

    /**
     * Log an audit event.
     *
     * $context keys (all optional):
     *   actor        string  – overrides session username
     *   ip           string  – overrides REMOTE_ADDR
     *   provider     string  – auth provider (ad/entra)
     *   target_type  string  – e.g. 'user', 'ip_rule', 'setting'
     *   target_id    string  – e.g. user ID
     *   target_name  string  – human-readable target
     *   old_value    mixed   – serialised to JSON
     *   new_value    mixed   – serialised to JSON
     */
    public static function log(
        PDO $pdo,
        string $category,
        string $eventType,
        string $description,
        array $context = [],
        string $severity = self::SEV_INFO
    ): void {
        try {
            self::ensureTable($pdo);

            $actor    = (string)($context['actor']    ?? ($_SESSION['username']      ?? ''));
            $ip       = (string)($context['ip']       ?? self::clientIp());
            $provider = (string)($context['provider'] ?? ($_SESSION['auth_provider'] ?? ''));

            $stmt = $pdo->prepare(
                "INSERT INTO audit_log
                    (event_category, event_type, severity, actor_username, actor_ip, actor_provider,
                     target_type, target_id, target_name, description, old_value, new_value, created_at)
                 VALUES
                    (:cat, :type, :sev, :actor, :ip, :prov,
                     :ttype, :tid, :tname, :desc, :old, :new, NOW())"
            );

            $stmt->execute([
                ':cat'   => $category,
                ':type'  => $eventType,
                ':sev'   => $severity,
                ':actor' => $actor !== '' ? $actor : null,
                ':ip'    => $ip !== '' ? $ip : null,
                ':prov'  => $provider !== '' ? $provider : null,
                ':ttype' => $context['target_type'] ?? null,
                ':tid'   => isset($context['target_id'])   ? (string)$context['target_id']   : null,
                ':tname' => $context['target_name'] ?? null,
                ':desc'  => $description,
                ':old'   => isset($context['old_value'])
                    ? json_encode($context['old_value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                ':new'   => isset($context['new_value'])
                    ? json_encode($context['new_value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
            ]);

            if ($severity === self::SEV_CRITICAL || $severity === self::SEV_WARNING) {
                self::maybeSendAlert($pdo, $eventType, $description, $severity, $actor, $ip, $context);
            }
        } catch (\Throwable $e) {
            error_log('Audit::log feilet: ' . $e->getMessage());
        }
    }

    private static function ensureTable(PDO $pdo): void
    {
        if (self::$tableChecked) return;
        self::$tableChecked = true;

        try {
            $pdo->query("SELECT 1 FROM audit_log LIMIT 1");
        } catch (\Throwable $e) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `audit_log` (
                    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `event_category` VARCHAR(50)  NOT NULL,
                    `event_type`     VARCHAR(100) NOT NULL,
                    `severity`       ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
                    `actor_username` VARCHAR(255) DEFAULT NULL,
                    `actor_ip`       VARCHAR(45)  DEFAULT NULL,
                    `actor_provider` VARCHAR(32)  DEFAULT NULL,
                    `target_type`    VARCHAR(100) DEFAULT NULL,
                    `target_id`      VARCHAR(255) DEFAULT NULL,
                    `target_name`    VARCHAR(255) DEFAULT NULL,
                    `description`    TEXT         DEFAULT NULL,
                    `old_value`      JSON         DEFAULT NULL,
                    `new_value`      JSON         DEFAULT NULL,
                    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_created_at` (`created_at`),
                    INDEX `idx_category`   (`event_category`),
                    INDEX `idx_actor`      (`actor_username`),
                    INDEX `idx_severity`   (`severity`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    }

    private static function clientIp(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $xff    = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '' && ($remote === '127.0.0.1' || $remote === '::1')) {
            foreach (array_map('trim', explode(',', $xff)) as $p) {
                if (filter_var($p, FILTER_VALIDATE_IP)) return $p;
            }
        }
        return $remote;
    }

    private static function maybeSendAlert(
        PDO    $pdo,
        string $eventType,
        string $description,
        string $severity,
        string $actor,
        string $ip,
        array  $context
    ): void {
        try {
            $stmt = $pdo->query(
                "SELECT setting_key, setting_value FROM system_settings
                  WHERE setting_key IN ('audit_alert_email','audit_alert_severity')"
            );
            $cfg = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];

            $alertEmail = trim((string)($cfg['audit_alert_email'] ?? ''));
            $minSev     = (string)($cfg['audit_alert_severity'] ?? 'critical');

            if ($alertEmail === '') return;

            $sevRank = ['info' => 0, 'warning' => 1, 'critical' => 2];
            if (($sevRank[$severity] ?? 0) < ($sevRank[$minSev] ?? 2)) return;

            $sevLabel = strtoupper($severity);
            $subject  = "[Teknisk Audit] {$sevLabel}: {$eventType}";
            $target   = $context['target_name'] ?? '';
            $body     = "Hendelse:      {$eventType}\n"
                      . "Alvorlighet:   {$sevLabel}\n"
                      . "Beskrivelse:   {$description}\n"
                      . "Bruker:        {$actor}\n"
                      . "IP:            {$ip}\n"
                      . "Tidspunkt:     " . date('Y-m-d H:i:s') . "\n"
                      . ($target !== '' ? "Mål:           {$target}\n" : '')
                      . "\nDette er en automatisk varsling fra Teknisk audit-logg.\n";

            @mail($alertEmail, $subject, $body, implode("\r\n", [
                'From: no-reply@hkbb.no',
                'Content-Type: text/plain; charset=UTF-8',
                'X-Mailer: Teknisk Audit',
            ]));
        } catch (\Throwable $e) {
            error_log('Audit alert mail feilet: ' . $e->getMessage());
        }
    }
}
