<?php
// public/lager/api/product_search.php

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

require_lager_login();
$pdo = get_pdo();

header('Content-Type: application/json; charset=utf-8');

function jerr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$fromProjectId  = (int)($_GET['from_project_id'] ?? 0);
$fromLocationId = (int)($_GET['from_location_id'] ?? 0);

if ($fromProjectId <= 0 || $fromLocationId <= 0) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($q, 'UTF-8') < 2) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $mCols = table_columns($pdo, 'inv_movements');
    $mProd = pick_first(['product_id','inv_product_id'], $mCols) ?? 'product_id';
    $mProj = pick_first(['project_id','from_project_id'], $mCols) ?? 'project_id';
    $mLoc  = pick_first(['location_id','inv_location_id'], $mCols) ?? 'location_id';
    $mQty  = pick_first(['qty','quantity','amount'], $mCols) ?? 'qty';

    $hasProducts = true;
    try {
        table_columns($pdo, 'inv_products');
    } catch (\Throwable $e) {
        $hasProducts = false;
    }

    if (!$hasProducts) {
        // Fallback: søk i product_id (numerisk) i movements
        if (!ctype_digit($q)) {
            echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pid = (int)$q;

        $st = $pdo->prepare("
            SELECT COALESCE(SUM(m.`$mQty`),0) AS stock_raw
            FROM inv_movements m
            WHERE m.`$mProj` = :proj
              AND m.`$mLoc`  = :loc
              AND m.`$mProd` = :prod
        ");
        $st->execute([':proj'=>$fromProjectId, ':loc'=>$fromLocationId, ':prod'=>$pid]);
        $stock = (int)round((float)($st->fetchColumn() ?: 0));

        echo json_encode([
            'ok' => true,
            'items' => [[ 'id'=>$pid, 'label'=>'Produkt #'.$pid, 'stock'=>$stock ]]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pCols = table_columns($pdo, 'inv_products');
    $pId   = pick_first(['id'], $pCols) ?? 'id';
    $pName = pick_first(['name','product_name','title','description'], $pCols) ?? 'name';
    $pSku  = pick_first(['sku','product_sku','code','item_no','item_number'], $pCols);

    $selectLabel = $pSku
        ? "TRIM(CONCAT(COALESCE(p.`$pSku`,''), CASE WHEN COALESCE(p.`$pSku`,'')<>'' THEN ' – ' ELSE '' END, COALESCE(p.`$pName`,'')))"
        : "COALESCE(p.`$pName`, CONCAT('Produkt #', p.`$pId`))";

    $like = '%' . $q . '%';

    $where = " (p.`$pName` LIKE :q) ";
    if ($pSku) {
        $where = " (p.`$pName` LIKE :q OR p.`$pSku` LIKE :q) ";
    }

    $sql = "
        SELECT
            p.`$pId` AS id,
            $selectLabel AS label,
            COALESCE(SUM(m.`$mQty`),0) AS stock_raw
        FROM inv_products p
        LEFT JOIN inv_movements m
               ON m.`$mProd` = p.`$pId`
              AND m.`$mProj` = :proj
              AND m.`$mLoc`  = :loc
        WHERE $where
        GROUP BY p.`$pId`, label
        ORDER BY label ASC
        LIMIT 30
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':proj' => $fromProjectId,
        ':loc'  => $fromLocationId,
        ':q'    => $like,
    ]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'    => (int)$r['id'],
            'label' => (string)$r['label'],
            'stock' => (int)round((float)($r['stock_raw'] ?? 0)),
        ];
    }

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    jerr('Kunne ikke søke produkter: ' . $e->getMessage(), 500);
}
