<?php
// scripts/encrypt_personal_data.php
// Krypterer eksisterende klartekst-persondata i databasen.
// Kjøres én gang fra kommandolinjen: php scripts/encrypt_personal_data.php
// Sikker å kjøre flere ganger – hopper over allerede krypterte verdier (enc:-prefiks).

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Crypto;
use App\Database;

$pdo = Database::getConnection();

$updated = 0;
$skipped = 0;
$errors  = 0;

echo "=== Kryptering av persondata ===\n\n";

// ── users: display_name + email ──────────────────────────────
$rows = $pdo->query('SELECT id, display_name, email FROM users')->fetchAll(PDO::FETCH_ASSOC);

echo "Behandler " . count($rows) . " rader i `users`...\n";

foreach ($rows as $row) {
    $id      = (int)$row['id'];
    $changes = [];

    foreach (['display_name', 'email'] as $col) {
        $val = $row[$col];
        if ($val === null || $val === '') continue;
        if (Crypto::isEncrypted($val)) { $skipped++; continue; }

        try {
            $changes[$col] = Crypto::encrypt($val);
        } catch (\Throwable $e) {
            echo "  FEIL  users.id={$id} {$col}: {$e->getMessage()}\n";
            $errors++;
        }
    }

    if (!empty($changes)) {
        $sets = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($changes)));
        $params = array_combine(
            array_map(fn($c) => ":{$c}", array_keys($changes)),
            array_values($changes)
        );
        $params[':id'] = $id;
        $pdo->prepare("UPDATE users SET {$sets} WHERE id = :id")->execute($params);
        $updated += count($changes);
        echo "  OK    users.id={$id}: " . implode(', ', array_keys($changes)) . "\n";
    }
}

// ── password_reset_tokens: email ─────────────────────────────
try {
    $rows = $pdo->query('SELECT id, email FROM password_reset_tokens')->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    echo "\n`password_reset_tokens` finnes ikke ennå – hoppes over.\n";
    $rows = [];
}

echo "\nBehandler " . count($rows) . " rader i `password_reset_tokens`...\n";

foreach ($rows as $row) {
    $id  = (int)$row['id'];
    $val = $row['email'];

    if ($val === null || $val === '') continue;
    if (Crypto::isEncrypted($val)) { $skipped++; continue; }

    try {
        $enc = Crypto::encrypt($val);
        $pdo->prepare('UPDATE password_reset_tokens SET email = :e WHERE id = :id')
            ->execute([':e' => $enc, ':id' => $id]);
        $updated++;
        echo "  OK    password_reset_tokens.id={$id}: email\n";
    } catch (\Throwable $e) {
        echo "  FEIL  password_reset_tokens.id={$id}: {$e->getMessage()}\n";
        $errors++;
    }
}

echo "\n=== Ferdig ===\n";
echo "Kryptert: {$updated} felt\n";
echo "Hoppet over (allerede kryptert): {$skipped} felt\n";
echo "Feil: {$errors}\n";
