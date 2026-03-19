<?php
// public/lager/api/product_list.php

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

require_lager_login();
$pdo = get_pdo();

header('Content-Type: application/json; charset=utf-8');

function jerr(string $msg, int $code = 400, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function pick_required(array $cols, array $candidates, string $what): string {
    $c = pick_first($candidates, $cols);
    if ($c === null) {
        jerr("Mangler kolonne for $what i inv_movements. (Sjekk kolonnenavn i DB.)", 500, [
            'what' => $what,
            'candidates' => $candidates,
            'available_columns' => array_values($cols),
        ]);
    }
    return $c;
}

$fromProjectId  = (int)($_GET['from_project_id'] ?? 0);
$fromLocationId = (int)($_GET['from_location_id'] ?? 0);
$showZero       = (int)($_GET['show_zero'] ?? 0) === 1;
$limit          = (int)($_GET['limit'] ?? 300);

if ($fromProjectId <= 0 || $fromLocationId <= 0) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($limit <= 0) $limit = 300;
if ($limit > 1000) $limit = 1000;

try {
    $mCols = table_columns($pdo, 'inv_movements');

    // Viktig: ikke default til noe som kanskje ikke finnes.
    $mProd = pick_required($mCols, ['product_id','inv_product_id','item_id','inv_item_id'], 'product_id');
    $mProj = pick_required($mCols, ['project_id','from_project_id','inv_project_id','warehouse_project_id'], 'project_id');
    $mLoc  = pick_required($mCols, ['location_id','inv_location_id','from_location_id','loc_id','inv_loc_id','warehouse_location_id'], 'location_id');
    $mQty  = pick_required($mCols, ['qty','quantity','amount','delta','change','qty_delta'], 'qty');

    // Sjekk om inv_products finnes
    $hasProducts = true;
    try {
        table_columns($pdo, 'inv_products');
    } catch (\Throwable $e) {
        $hasProducts = false;
    }

    if ($hasProducts) {
        $pCols = table_columns($pdo, 'inv_products');
        $pId   = pick_first(['id'], $pCols) ?? 'id';
        $pName = pick_first(['name','product_name','title','description'], $pCols) ?? 'name';
        $pSku  = pick_first(['sku','product_sku','code','item_no','item_number'], $pCols);

        $selectLabel = $pSku
            ? "TRIM(CONCAT(COALESCE(p.`$pSku`,''), CASE WHEN COALESCE(p.`$pSku`,'')<>'' THEN ' – ' ELSE '' END, COALESCE(p.`$pName`,'')))"
            : "COALESCE(p.`$pName`, CONCAT('Produkt #', p.`$pId`))";

        $sql = "
            SELECT
                p.`$pId` AS id,
                $selectLabel AS label,
                COALESCE(SUM(m.`$mQty`), 0) AS stock_raw
            FROM inv_products p
            JOIN inv_movements m ON m.`$mProd` = p.`$pId`
            WHERE m.`$mProj` = :pid
              AND m.`$mLoc`  = :lid
            GROUP BY p.`$pId`, label
        ";

        if (!$showZero) {
            $sql .= " HAVING COALESCE(SUM(m.`$mQty`),0) > 0 ";
        }

        $sql .= " ORDER BY label ASC LIMIT " . (int)$limit;

        $st = $pdo->prepare($sql);
        $st->execute([':pid' => $fromProjectId, ':lid' => $fromLocationId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $r) {
            $stock = (int)round((float)($r['stock_raw'] ?? 0));
            $items[] = [
                'id'    => (int)$r['id'],
                'label' => (string)$r['label'],
                'stock' => $stock,
            ];
        }

        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Fallback: ingen inv_products -> list distinct product_id fra movements
    $sql = "
        SELECT
            m.`$mProd` AS id,
            COALESCE(SUM(m.`$mQty`),0) AS stock_raw
        FROM inv_movements m
        WHERE m.`$mProj` = :pid
          AND m.`$mLoc`  = :lid
        GROUP BY m.`$mProd`
    ";

    if (!$showZero) {
        $sql .= " HAVING COALESCE(SUM(m.`$mQty`),0) > 0 ";
    }

    $sql .= " ORDER BY m.`$mProd` ASC LIMIT " . (int)$limit;

    $st = $pdo->prepare($sql);
    $st->execute([':pid' => $fromProjectId, ':lid' => $fromLocationId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $stock = (int)round((float)($r['stock_raw'] ?? 0));
        $items[] = [
            'id'    => $id,
            'label' => 'Produkt #' . $id,
            'stock' => $stock,
        ];
    }

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    jerr('Kunne ikke hente vareliste: ' . $e->getMessage(), 500);
}
