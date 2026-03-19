<?php
// public/pages/billing_invoice_print.php
//
// Print-/PDF-vennlig fakturagrunnlag.
// Viser salgspris og sum (NOK eks. mva.).
// Kontering nederst ved utskrift:
//  - ERP-prosjekt (kunde) fra crm_accounts.erp_project_ref
//  - Lagerprosjekt (kilde) for VARER (ikke timer) basert på batch->project
//  - Beløp (varer) NOK eks. mva. + fordeling pr lagerprosjekt
//
// Forutsetter tabeller:
// - billing_invoice_drafts
// - billing_invoice_lines
// - crm_accounts
// - inv_batches (for vare-kontering)
// - projects (for label)
// - inv_locations (valgfritt: uttakslokasjon)

use App\Database;

// ---------------------------------------------------------
// Krev innlogging
// ---------------------------------------------------------
$username = $_SESSION['username'] ?? '';
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Rolle/tilgang
// ---------------------------------------------------------
if (!function_exists('normalize_list')) {
    function normalize_list($v): array {
        if (is_array($v)) return array_values(array_filter(array_map('strval', $v)));
        if (is_string($v) && trim($v) !== '') {
            $parts = preg_split('/[,\s;]+/', $v);
            return array_values(array_filter(array_map('strval', $parts)));
        }
        return [];
    }
}
if (!function_exists('has_any')) {
    function has_any(array $needles, array $haystack): bool {
        $haystack = array_map('strtolower', $haystack);
        foreach ($needles as $n) {
            if (in_array(strtolower($n), $haystack, true)) return true;
        }
        return false;
    }
}

$roles = normalize_list($_SESSION['roles'] ?? null);
$perms = normalize_list($_SESSION['permissions'] ?? null);

$pdo = null;
try {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $userId = (int)($stmt->fetchColumn() ?: 0);

    if ($userId > 0) {
        $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $dbRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $roles = array_merge($roles, normalize_list($dbRoles));
    }
} catch (\Throwable $e) {
    $pdo = null; // fallback på session
}

$roles = array_values(array_unique(array_map('strtolower', $roles)));
$perms = array_values(array_unique(array_map('strtolower', $perms)));

$isAdmin = has_any(['admin'], $roles);

$canBilling = $isAdmin
    || has_any(['invoice','billing_read','billing_write','billing','faktura','crm','logistikk','lager','inventory','support'], $roles)
    || has_any(['invoice','billing_read','billing_write','billing','faktura','crm','logistikk','lager','inventory','support'], $perms);

if (!$canBilling) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang til fakturagrunnlag.</div>
    <?php
    return;
}

if (!$pdo) {
    try {
        $pdo = Database::getConnection();
    } catch (\Throwable $e) {
        http_response_code(500);
        ?>
        <div class="alert alert-danger mt-3">
            Klarte ikke koble til databasen.
            <?php if (!empty($_GET['debug'])): ?>
                <div class="small mt-2">DB error: <?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
        <?php
        return;
    }
}

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money(float $v): string { return number_format($v, 2, ',', ' '); }
function qtyfmt(float $v): string { return number_format((float)round($v, 0), 0, ',', ' '); }

function table_exists_local(PDO $pdo, string $table): bool {
    try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; } catch (\Throwable $e) { return false; }
}

// Formater prosjektnavn pent
function project_label(array $row): string {
    $id = (int)($row['id'] ?? 0);
    $no = trim((string)($row['project_no'] ?? ''));
    $nm = trim((string)($row['name'] ?? ''));
    if ($no !== '' && $nm !== '') return $no . ' – ' . $nm;
    if ($no !== '') return $no;
    if ($nm !== '') return '#' . $id . ' – ' . $nm;
    return $id > 0 ? ('#' . $id) : '—';
}

// ---------------------------------------------------------
// Input
// ---------------------------------------------------------
$invoiceId = (int)($_GET['id'] ?? $_GET['invoice_id'] ?? 0);
if ($invoiceId <= 0) {
    ?>
    <div class="alert alert-danger mt-3">Mangler fakturagrunnlag-ID.</div>
    <?php
    return;
}

// ---------------------------------------------------------
// Fetch invoice + account + lines + kontering (varer)
// ---------------------------------------------------------
$invoice = null;
$account = null;
$lines = [];
$errors = [];

$acctErpRef = '';
$erpProjectLabel = '';
$withdrawalLocationLabel = '';

// Kontering for varer (uten MVA)
$goodsAmount = 0.0; // sum sell_total (varer)
$goodsByWarehouse = []; // [ [project_id, label, amount], ... ]

try {
    // Invoice + customer (inkl erp_project_ref)
    $stmt = $pdo->prepare("
        SELECT d.*,
               a.name AS account_name,
               a.type AS account_type,
               a.org_no,
               a.reference AS account_reference,
               a.email,
               a.phone,
               a.address1,
               a.address2,
               a.postal_code,
               a.postal_city,
               a.country,
               a.erp_project_ref
          FROM billing_invoice_drafts d
          JOIN crm_accounts a ON a.id = d.account_id
         WHERE d.id = :id
         LIMIT 1
    ");
    $stmt->execute([':id' => $invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$invoice) {
        $errors[] = 'Fant ikke fakturagrunnlag.';
    } else {
        $account = $invoice;

        // Linjer (print)
        $st2 = $pdo->prepare("
            SELECT id, line_no, line_type,
                   product_id, batch_id,
                   description, unit, qty,
                   sell_unit, sell_total
              FROM billing_invoice_lines
             WHERE invoice_id = :id
             ORDER BY line_no ASC, id ASC
        ");
        $st2->execute([':id' => $invoiceId]);
        $lines = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // -------------------------------------------------
        // ERP-prosjekt (kunde)
        // -------------------------------------------------
        $acctErpRef = trim((string)($invoice['erp_project_ref'] ?? ''));
        $erpProjectLabel = $acctErpRef;

        if ($acctErpRef !== '' && table_exists_local($pdo, 'projects')) {
            try {
                $p = null;
                $isNumeric = ctype_digit($acctErpRef);
                if ($isNumeric) {
                    // prøv id først, så project_no
                    $stp = $pdo->prepare("SELECT id, project_no, name FROM projects WHERE id = :id LIMIT 1");
                    $stp->execute([':id' => (int)$acctErpRef]);
                    $p = $stp->fetch(PDO::FETCH_ASSOC) ?: null;

                    if (!$p) {
                        $stp = $pdo->prepare("SELECT id, project_no, name FROM projects WHERE project_no = :no LIMIT 1");
                        $stp->execute([':no' => $acctErpRef]);
                        $p = $stp->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                } else {
                    $stp = $pdo->prepare("SELECT id, project_no, name FROM projects WHERE project_no = :no LIMIT 1");
                    $stp->execute([':no' => $acctErpRef]);
                    $p = $stp->fetch(PDO::FETCH_ASSOC) ?: null;
                }

                if ($p) $erpProjectLabel = project_label($p);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // -------------------------------------------------
        // Uttakslokasjon (valgfritt)
        // -------------------------------------------------
        $withdrawLocId = (int)($invoice['withdrawal_location_id'] ?? 0);
        if ($withdrawLocId > 0 && table_exists_local($pdo, 'inv_locations')) {
            try {
                $stl = $pdo->prepare("SELECT id, code, name FROM inv_locations WHERE id = :id LIMIT 1");
                $stl->execute([':id' => $withdrawLocId]);
                $loc = $stl->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($loc) {
                    $code = trim((string)($loc['code'] ?? ''));
                    $name = trim((string)($loc['name'] ?? ''));
                    $withdrawalLocationLabel = trim(($code !== '' ? $code : ('#'.(int)$loc['id'])) . ($name !== '' ? ' – '.$name : ''));
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // -------------------------------------------------
        // Kontering for VARER (ikke timer), uten MVA
        // -------------------------------------------------
        $goodsByWarehouse = [];
        $goodsAmount = 0.0;

        if (table_exists_local($pdo, 'inv_batches') && table_exists_local($pdo, 'projects')) {
            try {
                $stg = $pdo->prepare("
                    SELECT
                        p.id, p.project_no, p.name,
                        SUM(l.sell_total) AS amount_ex
                    FROM billing_invoice_lines l
                    JOIN inv_batches b ON b.id = l.batch_id
                    JOIN projects p ON p.id = b.project_id
                    WHERE l.invoice_id = :iid
                      AND l.batch_id IS NOT NULL
                      AND b.project_id IS NOT NULL
                    GROUP BY p.id, p.project_no, p.name
                    ORDER BY p.project_no, p.name, p.id
                ");
                $stg->execute([':iid' => $invoiceId]);
                $rows = $stg->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($rows as $r) {
                    $ex = (float)($r['amount_ex'] ?? 0);

                    $goodsByWarehouse[] = [
                        'project_id' => (int)($r['id'] ?? 0),
                        'label'      => project_label($r),
                        'amount'     => $ex,
                    ];

                    $goodsAmount += $ex;
                }
            } catch (\Throwable $e) {
                if (!empty($_GET['debug'])) $errors[] = 'Kontering query feilet: ' . $e->getMessage();
            }
        } else {
            if (!empty($_GET['debug'])) $errors[] = 'Mangler inv_batches/projects for vare-kontering.';
        }
    }
} catch (\Throwable $e) {
    $errors[] = 'Databasefeil: ' . $e->getMessage();
}

if ($errors) {
    ?>
    <div class="alert alert-danger mt-3">
        <strong>Feil:</strong><br>
        <?= nl2br(h(implode("\n", $errors))) ?>
        <?php if (empty($_GET['debug'])): ?>
            <div class="mt-2 small">Tips: åpne med <code>&amp;debug=1</code> for mer detaljer.</div>
        <?php endif; ?>
    </div>
    <?php
    return;
}

// ---------------------------------------------------------
// Firmainfo
// ---------------------------------------------------------
$companyName  = 'Haugaland Kraft Fiber AS';
$companyOrg   = 'Org.nr: 915635881';
$companyAddr  = 'Haukelivegen 25, 5529 HAUGESUND';
$companyEmail = 'teknisk@hkraft.no';
$companyPhone = '+47 987 05 270';

$title     = $invoice['title'] ?: 'Fakturagrunnlag';
$currency  = 'NOK'; // Vis alltid NOK i dette dokumentet
$amountNote = 'Alle beløp er i NOK eks. mva.';
$issueDate = $invoice['issue_date'] ?: '';
$dueDate   = $invoice['due_date'] ?: '';
$status    = (string)($invoice['status'] ?? '');

$erpLabel = ($erpProjectLabel !== '') ? $erpProjectLabel : '— (mangler crm_accounts.erp_project_ref)';
$hasGoods = (count($goodsByWarehouse) > 0);

// Total (uten MVA)
$sumTotal = (float)($invoice['subtotal'] ?? 0.0);
?>
<!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> #<?= (int)$invoiceId ?></title>

    <style>
        :root { --ink:#111; --muted:#666; --line:#e5e5e5; }

        html, body { padding:0; margin:0; color:var(--ink); font-family: Arial, Helvetica, sans-serif; }
        body { font-size: 12px; line-height: 1.22; }

        .page { max-width: 920px; margin: 14px auto; padding: 0 14px; }
        .row { display:flex; gap: 10px; }
        .col { flex: 1 1 auto; min-width:0; }
        .right { text-align:right; }
        .muted { color:var(--muted); }
        .small { font-size: 11px; }

        .h1 { font-size: 17px; margin:0 0 3px 0; }
        .h2 { font-size: 13px; margin:0 0 3px 0; }

        .box { border:1px solid var(--line); padding:8px 10px; border-radius: 6px; }
        .box.compact { padding:6px 8px; }
        .divider { height: 1px; background: var(--line); margin: 8px 0; }
        .divider.tight { margin: 6px 0; }

        table { width:100%; border-collapse: collapse; }
        th, td { padding: 5px 5px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { text-align:left; font-size: 11px; color: var(--muted); font-weight: 700; }
        td { font-size: 12px; }

        .num { text-align:right; white-space: nowrap; }
        .totals { width: 240px; margin-left:auto; }
        .totals td { border-bottom: 0; padding: 2px 0; }
        .totals .label { color: var(--muted); }
        .totalrow td { border-top: 2px solid var(--line); border-bottom: 0; }

        .badge { display:inline-block; padding: 2px 7px; border:1px solid var(--line); border-radius: 999px; font-size: 11px; color: var(--muted); }
        .nowrap { white-space: nowrap; }

        .kv { margin-top: 4px; }
        .kv .k { display:inline-block; min-width: 170px; color:var(--muted); font-size: 11px; }
        .kv .v { font-weight: 600; font-size: 12px; }

        .list { margin: 6px 0 0 0; padding-left: 18px; }
        .list li { margin: 2px 0; }

        .content { }
        .kontering-section { margin-top: 10px; }

        @page { size: A4; margin: 10mm 12mm 18mm 12mm; }

        @media print {
            .no-print { display:none !important; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

            .page{
                margin: 0;
                max-width: none;
                padding: 0;
                display: flex;
                flex-direction: column;
                min-height: 269mm;
            }

            .content{ flex: 1 1 auto; }

            .kontering-section{
                margin-top: auto;
                padding-top: 6mm;
                break-inside: avoid;
                page-break-inside: avoid;
            }

            table { page-break-inside: auto; }
            thead { display: table-header-group; }

            th, td { padding: 4px 4px; }
            .divider { margin: 6px 0; }
            .box { padding: 7px 9px; }
            .box.compact { padding: 6px 8px; }
            .row { gap: 8px; }
        }
    </style>
</head>
<body>
<div class="page">

    <div class="no-print" style="text-align:right; margin-bottom: 10px;">
        <button type="button" class="btn btn-outline-primary no-print" id="printBtn">Skriv ut</button>

<script>
(function () {
  var btn = document.getElementById('printBtn');
  if (!btn) return;

  var printing = false;

  btn.addEventListener('click', function () {
    if (printing) return;
    printing = true;

    btn.disabled = true;

    setTimeout(function () { window.print(); }, 50);

    setTimeout(function () {
      printing = false;
      btn.disabled = false;
    }, 2500);
  });

  window.addEventListener('afterprint', function () {
    printing = false;
    if (btn) btn.disabled = false;
  });
})();
</script>

    </div>

    <div class="content">

        <!-- Header -->
        <div class="row">
            <div class="col">
                <div class="h1"><?= h($companyName) ?></div>
                <div class="small muted"><?= h($companyOrg) ?></div>
                <div class="small muted"><?= h($companyAddr) ?></div>
                <div class="small muted"><?= h($companyEmail) ?> · <?= h($companyPhone) ?></div>
            </div>

            <div class="col right">
                <div class="h1"><?= h($title) ?></div>
                <div class="badge">#<?= (int)$invoiceId ?></div>
                <div class="divider tight"></div>
                <div class="small"><span class="muted">Dato:</span> <span class="nowrap"><?= h($issueDate) ?></span></div>
                <div class="small"><span class="muted">Forfall:</span> <span class="nowrap"><?= h($dueDate) ?></span></div>
                <?php if ($status !== ''): ?>
                    <div class="small"><span class="muted">Status:</span> <?= h($status) ?></div>
                <?php endif; ?>
                <?php if (!empty($invoice['their_ref'])): ?>
                    <div class="small"><span class="muted">Deres ref:</span> <?= h($invoice['their_ref']) ?></div>
                <?php endif; ?>
                <?php if (!empty($invoice['your_ref'])): ?>
                    <div class="small"><span class="muted">Vår ref:</span> <?= h($invoice['your_ref']) ?></div>
                <?php endif; ?>
                <div class="small"><span class="muted">Valuta:</span> <?= h($currency) ?> <span class="muted">(eks. mva.)</span></div>
                <div class="small muted"><?= h($amountNote) ?></div>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Customer + Summary -->
        <div class="row">
            <div class="col box">
                <div class="h2">Kunde / partner</div>
                <div><strong><?= h($account['account_name']) ?></strong></div>
                <?php if (!empty($account['org_no'])): ?>
                    <div class="small muted">Org.nr: <?= h($account['org_no']) ?></div>
                <?php endif; ?>
                <?php if (!empty($account['account_reference'])): ?>
                    <div class="small muted">Kundereferanse: <?= h($account['account_reference']) ?></div>
                <?php endif; ?>

                <div style="margin-top:8px;">
                    <?php if (!empty($account['address1'])): ?>
                        <div class="small"><?= h($account['address1']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($account['address2'])): ?>
                        <div class="small"><?= h($account['address2']) ?></div>
                    <?php endif; ?>
                    <?php $cityLine = trim(($account['postal_code'] ?? '') . ' ' . ($account['postal_city'] ?? '')); ?>
                    <?php if ($cityLine !== ''): ?>
                        <div class="small"><?= h($cityLine) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($account['country'])): ?>
                        <div class="small"><?= h($account['country']) ?></div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($account['email']) || !empty($account['phone'])): ?>
                    <div class="divider tight"></div>
                    <?php if (!empty($account['email'])): ?>
                        <div class="small"><span class="muted">E-post:</span> <?= h($account['email']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($account['phone'])): ?>
                        <div class="small"><span class="muted">Telefon:</span> <?= h($account['phone']) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- kompakt oppsummering -->
            <div class="col box compact">
                <div class="h2">Oppsummering <span class="muted">(NOK eks. mva.)</span></div>
                <table class="totals">
                    <tr class="totalrow">
                        <td class="label"><strong>Sum (eks. mva.)</strong></td>
                        <td class="num"><strong><?= money($sumTotal) ?> <?= h($currency) ?></strong></td>
                    </tr>
                </table>

                <?php if (!empty($invoice['notes'])): ?>
                    <div class="divider tight"></div>
                    <div class="small muted">Notat</div>
                    <div class="small"><?= nl2br(h($invoice['notes'])) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Lines -->
        <table>
            <thead>
            <tr>
                <th style="width:42px;">#</th>
                <th>Beskrivelse</th>
                <th style="width:110px;" class="num">Antall</th>
                <th style="width:80px;">Enhet</th>
                <th style="width:120px;" class="num">Pris (eks. mva.)</th>
                <th style="width:140px;" class="num">Sum (eks. mva.)</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$lines): ?>
                <tr>
                    <td colspan="6" class="muted">Ingen linjer.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($lines as $ln): ?>
                    <tr>
                        <td class="muted"><?= (int)$ln['line_no'] ?></td>
                        <td><?= h($ln['description']) ?></td>
                        <td class="num"><?= qtyfmt((float)$ln['qty']) ?></td>
                        <td class="muted"><?= h($ln['unit']) ?></td>
                        <td class="num"><?= money((float)$ln['sell_unit']) ?></td>
                        <td class="num"><strong><?= money((float)$ln['sell_total']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="divider"></div>

        <!-- Bottom totals (NOK eks. mva.) -->
        <table class="totals">
            <tr class="totalrow">
                <td class="label"><strong>Sum (eks. mva.)</strong></td>
                <td class="num"><strong><?= money($sumTotal) ?> <?= h($currency) ?></strong></td>
            </tr>
        </table>

    </div><!-- /.content -->

    <!-- KONTERING VARELAGER -->
    <div class="kontering-section">
        <div class="divider"></div>
        <div class="box">
            <div class="h2">Kontering varelager <span class="muted">(NOK eks. mva.)</span></div>

            <div class="kv">
                <span class="k">ERP-prosjekt (kunde):</span>
                <span class="v"><?= h($erpLabel) ?></span>
            </div>

            <?php if (count($goodsByWarehouse) > 0): ?>
                <div class="kv">
                    <span class="k">Beløp varer (eks. mva.):</span>
                    <span class="v"><?= money($goodsAmount) ?> <?= h($currency) ?></span>
                </div>

                <div class="divider tight"></div>

                <div class="kv">
                    <span class="k">Lagerprosjekt (kilde) – varer:</span>
                    <span class="v"><?= count($goodsByWarehouse) === 1 ? h($goodsByWarehouse[0]['label']) : 'Fordeling' ?></span>
                </div>

                <?php if (count($goodsByWarehouse) > 1): ?>
                    <ul class="list">
                        <?php foreach ($goodsByWarehouse as $row): ?>
                            <li>
                                <strong><?= h($row['label']) ?></strong>
                                <span class="muted">—</span>
                                <?= money((float)$row['amount']) ?> <?= h($currency) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="kv">
                        <span class="k">Beløp på lagerprosjekt (eks. mva.):</span>
                        <span class="v"><?= money((float)$goodsByWarehouse[0]['amount']) ?> <?= h($currency) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($withdrawalLocationLabel !== ''): ?>
                    <div class="kv">
                        <span class="k">Uttakslokasjon:</span>
                        <span class="v"><?= h($withdrawalLocationLabel) ?></span>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="kv">
                    <span class="k">Varer fra lager:</span>
                    <span class="v">— (ingen varelinjer med batch på dette fakturagrunnlaget)</span>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['debug'])): ?>
                <div class="divider"></div>
                <div class="small muted">
                    Debug: erp_project_ref=<?= h($acctErpRef ?: '—') ?>,
                    goods_projects=<?= (int)count($goodsByWarehouse) ?>,
                    goods_amount=<?= money($goodsAmount) ?> <?= h($currency) ?>
                </div>
            <?php endif; ?>
        </div>

        <div style="margin-top: 10px;" class="small muted">
            <?= h($amountNote) ?> · Dette dokumentet er et fakturagrunnlag. Ved spørsmål, kontakt <?= h($companyEmail) ?>.
        </div>
    </div>

</div>
</body>
</html>
