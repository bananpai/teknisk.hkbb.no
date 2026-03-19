<?php
// public/lager/api/commit_withdrawal.php

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

$u = require_lager_login();
$pdo = get_pdo();

header('Content-Type: application/json; charset=utf-8');

function jerr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    jerr('Ugyldig JSON i request body.');
}

$fromProjectId  = (int)($data['from_project_id'] ?? 0);
$fromLocationId = (int)($data['from_location_id'] ?? 0);
$toProjectId    = (int)($data['to_project_id'] ?? 0);
$workOrderId    = isset($data['work_order_id']) && $data['work_order_id'] !== null ? (int)$data['work_order_id'] : null;
$note           = isset($data['note']) && $data['note'] !== null ? trim((string)$data['note']) : null;
$lines          = $data['lines'] ?? null;

if ($fromProjectId <= 0 || $fromLocationId <= 0 || $toProjectId <= 0) {
    jerr('Mangler fra/til-prosjekt eller lokasjon.');
}
if (!is_array($lines) || count($lines) === 0) {
    jerr('Ingen varelinjer.');
}

try {
    $mCols = table_columns($pdo, 'inv_movements');

    $mProd = pick_first(['product_id','inv_product_id'], $mCols) ?? 'product_id';
    $mProj = pick_first(['project_id','from_project_id'], $mCols) ?? 'project_id';
    $mLoc  = pick_first(['location_id','inv_location_id'], $mCols) ?? 'location_id';
    $mQty  = pick_first(['qty','quantity','amount'], $mCols) ?? 'qty';

    // Valgfrie felt
    $mNote     = pick_first(['note','comment','description','remarks'], $mCols);
    $mToProj   = pick_first(['to_project_id','dest_project_id','target_project_id'], $mCols);
    $mWorkOrd  = pick_first(['work_order_id','workorder_id'], $mCols);
    $mType     = pick_first(['movement_type','type','action'], $mCols);
    $mCreated  = pick_first(['created_at','created','created_on','movement_date','date'], $mCols);
    $mUserId   = pick_first(['user_id','created_by_user_id'], $mCols);
    $mUsername = pick_first(['username','created_by','created_by_username'], $mCols);

    // For lageruttak: vi registrerer NEGATIV qty på lagerprosjekt/lokasjon.
    // (Mange systemer registrerer bare "ut" fra lager. Hvis dere også ønsker "inn" på arbeidsprosjekt
    // kan vi lage en ekstra +qty-linje senere.)
    $insertCols = [$mProd, $mProj, $mLoc, $mQty];
    $paramsBase = [];

    if ($mToProj)   $insertCols[] = $mToProj;
    if ($mWorkOrd)  $insertCols[] = $mWorkOrd;
    if ($mNote)     $insertCols[] = $mNote;
    if ($mType)     $insertCols[] = $mType;
    if ($mCreated)  $insertCols[] = $mCreated;
    if ($mUserId)   $insertCols[] = $mUserId;
    if ($mUsername) $insertCols[] = $mUsername;

    // Build SQL
    $colSql = implode(', ', array_map(static fn($c) => "`$c`", $insertCols));
    $valSql = implode(', ', array_fill(0, count($insertCols), '?'));

    $sqlIns = "INSERT INTO inv_movements ($colSql) VALUES ($valSql)";
    $stIns = $pdo->prepare($sqlIns);

    // For validering av beholdning (best effort)
    $sqlStock = "
        SELECT COALESCE(SUM(`$mQty`),0) AS stock_raw
        FROM inv_movements
        WHERE `$mProj` = :pid AND `$mLoc` = :lid AND `$mProd` = :prod
        LIMIT 1
    ";
    $stStock = $pdo->prepare($sqlStock);

    $pdo->beginTransaction();

    $count = 0;

    foreach ($lines as $ln) {
        if (!is_array($ln)) continue;

        $prodId = (int)($ln['product_id'] ?? 0);
        $qty    = (int)round((float)($ln['qty'] ?? 0)); // alltid heltall

        if ($prodId <= 0 || $qty <= 0) {
            $pdo->rollBack();
            jerr('Ugyldig linje (product_id/qty).');
        }

        // Sjekk beholdning (valgfritt, men fint)
        $stStock->execute([':pid'=>$fromProjectId, ':lid'=>$fromLocationId, ':prod'=>$prodId]);
        $stock = (int)round((float)($stStock->fetchColumn() ?: 0));
        if ($stock < $qty) {
            $pdo->rollBack();
            jerr("Ikke nok beholdning for produkt #$prodId. Beholdning: $stock, forespurt: $qty.");
        }

        $vals = [];
        foreach ($insertCols as $c) {
            if ($c === $mProd) $vals[] = $prodId;
            elseif ($c === $mProj) $vals[] = $fromProjectId;
            elseif ($c === $mLoc) $vals[] = $fromLocationId;
            elseif ($c === $mQty) $vals[] = -$qty; // uttak => negativt
            elseif ($mToProj && $c === $mToProj) $vals[] = $toProjectId;
            elseif ($mWorkOrd && $c === $mWorkOrd) $vals[] = $workOrderId;
            elseif ($mNote && $c === $mNote) $vals[] = $note;
            elseif ($mType && $c === $mType) $vals[] = 'uttak';
            elseif ($mCreated && $c === $mCreated) $vals[] = date('Y-m-d H:i:s');
            elseif ($mUserId && $c === $mUserId) $vals[] = (int)($u['id'] ?? 0);
            elseif ($mUsername && $c === $mUsername) $vals[] = (string)($u['username'] ?? '');
            else $vals[] = null;
        }

        $stIns->execute($vals);
        $count++;
    }

    $pdo->commit();

    echo json_encode(['ok' => true, 'inserted' => $count], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jerr('Kunne ikke registrere uttak: ' . $e->getMessage(), 500);
}
