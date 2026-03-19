<?php
// public/lager/api/workorders.php

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

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $cols = table_columns($pdo, 'work_orders');

    $idCol   = pick_first(['id'], $cols) ?? 'id';
    $pCol    = pick_first(['project_id'], $cols) ?? 'project_id';
    $noCol   = pick_first(['work_order_no','workorder_no','number','code'], $cols);
    $nameCol = pick_first(['name','title','description'], $cols) ?? 'name';

    $select = ["`$idCol` AS id", "`$nameCol` AS name"];
    if ($noCol) $select[] = "`$noCol` AS wno";

    $sql = "SELECT " . implode(', ', $select) . " FROM work_orders WHERE `$pCol` = :pid";
    if (in_array('is_active', $cols, true)) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY `$idCol` DESC LIMIT 500";

    $st = $pdo->prepare($sql);
    $st->execute([':pid' => $projectId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        $name = (string)($r['name'] ?? '');
        $no   = (string)($r['wno'] ?? '');
        $label = $no !== '' ? ($no . ' – ' . $name) : $name;
        $items[] = [
            'id' => (int)$r['id'],
            'label' => trim($label) !== '' ? $label : ('Arbeidsordre #' . (int)$r['id']),
        ];
    }

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    jerr('Kunne ikke hente arbeidsordre: ' . $e->getMessage(), 500);
}
