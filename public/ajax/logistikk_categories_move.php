<?php
// public/ajax/logistikk_categories_move.php

declare(strict_types=1);

// Prøv å laste autoloader (tilpass hvis dere har annen bootstrap)
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

use App\Database;

header('Content-Type: application/json; charset=utf-8');

function post_int(string $key): int
{
    return isset($_POST[$key]) ? (int)$_POST[$key] : 0;
}
function post_str(string $key): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}

try {
    // Krev innlogging
    $username = $_SESSION['username'] ?? '';
    if (!$username) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ikke innlogget.']);
        exit;
    }

    // Admin-sjekk
    $isAdmin = $_SESSION['is_admin'] ?? false;
    if ($username === 'rsv') {
        $isAdmin = true;
    }
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ingen tilgang.']);
        exit;
    }

    // Kun forventet action
    $action = post_str('action');
    if ($action !== 'move_category') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ugyldig action.']);
        exit;
    }

    $pdo = Database::getConnection();

    // ---- Helpers ----
    $tableExists = function (PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :t
        ");
        $stmt->execute([':t' => $table]);
        return ((int)$stmt->fetchColumn()) > 0;
    };

    if (!$tableExists($pdo, 'inv_categories')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Mangler tabell inv_categories.']);
        exit;
    }

    $categoryExists = function (PDO $pdo, int $id): bool {
        $stmt = $pdo->prepare("SELECT 1 FROM inv_categories WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return (bool)$stmt->fetchColumn();
    };

    $wouldCreateCycle = function (PDO $pdo, int $movingId, ?int $newParentId): bool {
        if (!$newParentId || $newParentId <= 0) return false;
        if ($movingId === $newParentId) return true;

        $cur = $newParentId;
        $seen = 0;

        while ($cur > 0 && $seen < 500) {
            if ($cur === $movingId) return true;

            $stmt = $pdo->prepare("SELECT parent_id FROM inv_categories WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $cur]);
            $p = $stmt->fetchColumn();
            if ($p === false || $p === null) break;

            $cur = (int)$p;
            $seen++;
        }
        return false;
    };

    // ---- Input ----
    $movingId = post_int('id');
    $newParent = post_int('new_parent_id'); // 0 = toppnivå
    $newParentId = ($newParent > 0) ? $newParent : null;

    if ($movingId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ugyldig kategori-id.']);
        exit;
    }
    if (!$categoryExists($pdo, $movingId)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Kategorien finnes ikke.']);
        exit;
    }
    if ($newParentId !== null && !$categoryExists($pdo, $newParentId)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Mål-kategorien finnes ikke.']);
        exit;
    }
    if ($wouldCreateCycle($pdo, $movingId, $newParentId)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Kan ikke flytte: dette vil skape en sirkel i treet.']);
        exit;
    }

    // ---- Update ----
    $stmt = $pdo->prepare("
        UPDATE inv_categories
        SET parent_id = :pid
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':pid' => $newParentId,
        ':id'  => $movingId,
    ]);

    echo json_encode(['ok' => true]);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
