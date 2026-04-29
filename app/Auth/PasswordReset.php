<?php
// app/Auth/PasswordReset.php

declare(strict_types=1);

namespace App\Auth;

use App\Mail\Mailer;
use App\Support\Crypto;
use PDO;

class PasswordReset
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
                `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `token_hash`    CHAR(64)         NOT NULL,
                `username`      VARCHAR(128)     NOT NULL,
                `email`         VARCHAR(255)     NOT NULL,
                `expires_at`    DATETIME         NOT NULL,
                `used_at`       DATETIME         DEFAULT NULL,
                `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_token_hash` (`token_hash`),
                KEY `idx_username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Opretter reset-token og sender e-post.
     * Returnerer ingenting – kaster RuntimeException ved feil.
     */
    public function requestReset(string $username, string $email, string $baseUrl): void
    {
        // Slett gamle tokens for brukeren
        $this->pdo->prepare(
            "DELETE FROM password_reset_tokens WHERE username = :u"
        )->execute([':u' => $username]);

        $rawToken  = bin2hex(random_bytes(32)); // 64 hex-tegn
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 time

        $this->pdo->prepare("
            INSERT INTO password_reset_tokens (token_hash, username, email, expires_at)
            VALUES (:hash, :user, :email, :exp)
        ")->execute([
            ':hash'  => $tokenHash,
            ':user'  => $username,
            ':email' => Crypto::encrypt($email),
            ':exp'   => $expiresAt,
        ]);

        $resetUrl = rtrim($baseUrl, '/') . '/login/reset.php?token=' . urlencode($rawToken);

        $mailer = new Mailer();
        $mailer->send(
            [$email => $username],
            'Tilbakestill AD-passord – HKBB Teknisk',
            $this->buildEmailHtml($username, $resetUrl),
        );
    }

    /**
     * Validerer token. Returnerer ['username', 'email'] eller null ved ugyldig/utløpt token.
     */
    public function validateToken(string $rawToken): ?array
    {
        $tokenHash = hash('sha256', $rawToken);

        $stmt = $this->pdo->prepare("
            SELECT username, email, expires_at, used_at
            FROM password_reset_tokens
            WHERE token_hash = :hash
            LIMIT 1
        ");
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;
        if ($row['used_at'] !== null) return null;
        if (strtotime($row['expires_at']) < time()) return null;

        return [
            'username' => $row['username'],
            'email'    => Crypto::decryptOrNull($row['email']),
        ];
    }

    /**
     * Marker token som brukt etter vellykket passordbytte.
     */
    public function consumeToken(string $rawToken): void
    {
        $tokenHash = hash('sha256', $rawToken);
        $this->pdo->prepare("
            UPDATE password_reset_tokens SET used_at = NOW() WHERE token_hash = :hash
        ")->execute([':hash' => $tokenHash]);
    }

    private function buildEmailHtml(string $username, string $resetUrl): string
    {
        $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safeUrl  = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        return "
        <div style='font-family:system-ui,-apple-system,Arial,sans-serif;max-width:520px;margin:0 auto;padding:32px 24px;'>
            <div style='background:#1d4ed8;border-radius:12px 12px 0 0;padding:24px 28px;'>
                <h1 style='color:#fff;margin:0;font-size:20px;letter-spacing:-0.02em;'>HKBB Teknisk</h1>
                <p style='color:rgba(255,255,255,0.8);margin:4px 0 0;font-size:13px;'>Tilbakestilling av AD-passord</p>
            </div>
            <div style='background:#ffffff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px;padding:28px;'>
                <p style='color:#111827;font-size:15px;margin:0 0 16px;'>Hei <strong>{$safeUser}</strong>,</p>
                <p style='color:#374151;font-size:14px;line-height:1.6;margin:0 0 24px;'>
                    Vi mottok en forespørsel om å tilbakestille passordet til din AD-konto.
                    Klikk på knappen nedenfor for å sette nytt passord.
                    Lenken er gyldig i <strong>1 time</strong>.
                </p>
                <div style='text-align:center;margin:0 0 24px;'>
                    <a href='{$safeUrl}'
                       style='display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;
                              padding:12px 28px;border-radius:999px;font-size:14px;font-weight:600;
                              letter-spacing:0.01em;'>
                        Tilbakestill passord
                    </a>
                </div>
                <p style='color:#6b7280;font-size:12px;line-height:1.5;margin:0;border-top:1px solid #f3f4f6;padding-top:16px;'>
                    Hvis du ikke ba om dette, kan du ignorere denne e-posten – passordet ditt forblir uendret.<br>
                    Lenken utløper automatisk etter 1 time.
                </p>
            </div>
        </div>
        ";
    }
}
