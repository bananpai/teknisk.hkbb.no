<?php
// Path: public/pages/node_location_template_edit.php
//
// TILGANG (NYTT):
// - Les:  feltobjekter_les  (eller feltobjekter_skriv / admin)
// - Skriv: feltobjekter_skriv (eller admin)
// - Bakoverkompatibilitet: node_write / node_locations_admin / node_location_templates_write/admin gir skriv

use App\Database;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = Database::getConnection();
$username = $_SESSION['username'] ?? '';

// ---------------------------------------------------------
// Helpers (med function_exists for å unngå redeclare)
// ---------------------------------------------------------
if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * CSRF helpers (enkle, robust)
 */
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['_csrf'];
    }
}
if (!function_exists('csrf_validate')) {
    function csrf_validate(?string $token): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $sess = (string)($_SESSION['_csrf'] ?? '');
        return $sess !== '' && is_string($token) && hash_equals($sess, $token);
    }
}

/**
 * Robust liste-leser:
 * - støtter array ["a","b"]
 * - støtter assoc ["admin"=>1,"node_write"=>true]
 * - støtter string "a,b,c" / "a b c" / "a;b"
 */
if (!function_exists('session_list')) {
    function session_list($v): array {
        if (is_array($v)) {
            $isAssoc = array_keys($v) !== range(0, count($v) - 1);
            if ($isAssoc) {
                $out = [];
                foreach ($v as $k => $val) {
                    if (is_string($k) && $k !== '' && $k !== '0') {
                        if ($val) $out[] = (string)$k;
                    } else {
                        if (is_string($val) && trim($val) !== '') $out[] = trim($val);
                    }
                }
                return array_values(array_filter(array_map('strval', $out)));
            }

            return array_values(array_filter(array_map(function ($x) {
                return is_string($x) ? trim($x) : (is_scalar($x) ? (string)$x : '');
            }, $v)));
        }

        if (is_string($v)) {
            $s = trim($v);
            if ($s === '') return [];
            $parts = preg_split('/[,\s;]+/', $s);
            return array_values(array_filter(array_map('trim', $parts)));
        }

        return [];
    }
}

if (!function_exists('has_any')) {
    function has_any(array $needles, array $haystack): bool {
        $hay = array_map('strtolower', array_map('strval', $haystack));
        foreach ($needles as $n) {
            if (in_array(strtolower((string)$n), $hay, true)) return true;
        }
        return false;
    }
}

/**
 * Hent roller fra DB på en robust måte (støtter user_roles(user_id, role) og user_roles(username, role))
 */
if (!function_exists('load_db_roles_for_user')) {
    function load_db_roles_for_user(PDO $pdo, string $username, array $session): array {
        $roles = [];

        $userId = (int)($session['user_id'] ?? 0);
        if ($userId <= 0 && $username !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
                $st->execute([':u' => $username]);
                $userId = (int)($st->fetchColumn() ?: 0);
            } catch (\Throwable $e) {
                $userId = 0;
            }
        }

        if ($userId > 0) {
            try {
                $st = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :uid");
                $st->execute([':uid' => $userId]);
                $roles = array_merge($roles, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (\Throwable $e) {
            }
        }

        if ($username !== '') {
            try {
                $st = $pdo->prepare("SELECT role FROM user_roles WHERE username = :u");
                $st->execute([':u' => $username]);
                $roles = array_merge($roles, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (\Throwable $e) {
            }
        }

        if ($username !== '') {
            try {
                $st = $pdo->prepare("SELECT role_key FROM user_roles WHERE username = :u");
                $st->execute([':u' => $username]);
                $roles = array_merge($roles, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (\Throwable $e) {
            }
        }

        return array_values(array_filter(array_map('strval', $roles)));
    }
}

// ---------------------------------------------------------
// Guard (robust) – NY: feltobjekter_les / feltobjekter_skriv
// ---------------------------------------------------------
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

$sessionRoles = array_merge(
    session_list($_SESSION['roles'] ?? null),
    session_list($_SESSION['permissions'] ?? null),
    session_list($_SESSION['user_groups'] ?? null),
    session_list($_SESSION['ad_groups'] ?? null)
);

$dbRoles = load_db_roles_for_user($pdo, $username, $_SESSION);

$roles = array_values(array_unique(array_map('strtolower', array_merge($sessionRoles, $dbRoles))));

$isAdmin = (bool)($_SESSION['is_admin'] ?? false);
if (!$isAdmin) {
    $isAdmin = has_any(['admin', 'administrator', 'superadmin'], $roles);
}
if ($username === 'rsv') { $isAdmin = true; } // beholdt evt. bypass
if ($isAdmin) {
    $_SESSION['is_admin'] = true;
}

$canWrite = $isAdmin || has_any([
    'feltobjekter_skriv',
    // bakoverkompatibilitet:
    'node_location_templates_admin',
    'node_location_templates_write',
    'node_locations_admin',
    'node_locations_write',
    'node_write',
], $roles);

$canRead = $canWrite || $isAdmin || has_any([
    'feltobjekter_les',
    // bakoverkompatibilitet:
    'node_location_templates_read',
    'node_location_templates_view',
    'node_locations_read',
    'node_read',
], $roles);

if (!$canRead) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

/**
 * Generer key automatisk fra label:
 * - små bokstaver
 * - æøå -> ae/oe/aa
 * - alt som ikke er a-z0-9 -> underscore
 * - trim underscore
 * - maks lengde
 */
function make_field_key(string $label): string {
    $s = mb_strtolower(trim($label), 'UTF-8');
    $map = ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/u', '_', $s);
    $s = trim($s, '_');
    if ($s === '') $s = 'field';
    if (strlen($s) > 64) $s = substr($s, 0, 64);
    return $s;
}

/**
 * Sørg for at key er unik i malen (suffix _2, _3, ...)
 */
function ensure_unique_key(PDO $pdo, int $templateId, string $baseKey, int $excludeFieldId = 0): string {
    $key = $baseKey;
    $i = 1;

    while (true) {
        $sql = "SELECT COUNT(*) FROM node_location_custom_fields
                WHERE template_id = :tid AND field_key = :k";
        $params = [':tid' => $templateId, ':k' => $key];

        if ($excludeFieldId > 0) {
            $sql .= " AND id <> :fid";
            $params[':fid'] = $excludeFieldId;
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $exists = (int)$st->fetchColumn();

        if ($exists === 0) return $key;

        $i++;
        $key = $baseKey . '_' . $i;
        if (strlen($key) > 64) {
            $key = substr($baseKey, 0, max(1, 64 - (1 + strlen((string)$i)))) . '_' . $i;
        }
    }
}

/**
 * Helper: oppdater sort_order sekvensielt (0..n-1)
 */
function update_sort_orders(PDO $pdo, string $table, string $idCol, array $orderedIds, array $extraWhere = [], array $extraParams = []): void {
    $sql = "UPDATE {$table} SET sort_order = :so WHERE {$idCol} = :id";
    if ($extraWhere) {
        $sql .= " AND " . implode(" AND ", $extraWhere);
    }
    $st = $pdo->prepare($sql);
    $i = 0;
    foreach ($orderedIds as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $params = array_merge($extraParams, [
            ':so' => $i,
            ':id' => $id,
        ]);
        $st->execute($params);
        $i++;
    }
}

$id = (int)($_GET['id'] ?? 0);
$errors = [];
$success = null;

$template = [
  'id' => 0,
  'name' => '',
  'description' => '',
  'is_active' => 1,
];

if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM node_location_templates WHERE id=:id");
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) $errors[] = "Fant ikke mal.";
    else $template = array_merge($template, $row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canWrite) {
        $errors[] = "Du har ikke tilgang til å endre maler/felter (krever feltobjekter_skriv).";
    } else {
        $action = $_POST['action'] ?? 'save_template';

        // CSRF (for alle POST)
        if (!csrf_validate($_POST['_csrf'] ?? null)) {
            $errors[] = "Ugyldig CSRF-token.";
        } else {
            try {
                $pdo->beginTransaction();

                if ($action === 'save_template') {
                    $template['name'] = trim($_POST['name'] ?? '');
                    $template['description'] = trim($_POST['description'] ?? '');
                    $template['is_active'] = isset($_POST['is_active']) ? 1 : 0;

                    if ($template['name'] === '') throw new RuntimeException("Navn er påkrevd.");

                    if ($id > 0) {
                        $pdo->prepare("
                          UPDATE node_location_templates
                             SET name=:n, description=:d, is_active=:a
                           WHERE id=:id
                        ")->execute([
                            ':n' => $template['name'],
                            ':d' => $template['description'],
                            ':a' => (int)$template['is_active'],
                            ':id' => $id
                        ]);
                    } else {
                        $pdo->prepare("
                          INSERT INTO node_location_templates (name, description, is_active)
                          VALUES (:n, :d, :a)
                        ")->execute([
                            ':n' => $template['name'],
                            ':d' => $template['description'],
                            ':a' => (int)$template['is_active']
                        ]);
                        $id = (int)$pdo->lastInsertId();
                        $template['id'] = $id;
                    }
                    $success = "Mal lagret.";

                } elseif ($action === 'add_group') {
                    if ($id <= 0) throw new RuntimeException("Lagre malen først.");
                    $gname = trim($_POST['group_name'] ?? '');
                    if ($gname === '') throw new RuntimeException("Gruppenavn mangler.");

                    // legg nederst (maks sort_order + 1)
                    $max = 0;
                    try {
                        $st = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM node_location_field_groups WHERE template_id=:tid");
                        $st->execute([':tid' => $id]);
                        $max = (int)$st->fetchColumn();
                    } catch (\Throwable $e) { $max = 0; }

                    $pdo->prepare("
                      INSERT INTO node_location_field_groups (template_id, name, sort_order)
                      VALUES (:tid, :n, :so)
                    ")->execute([':tid' => $id, ':n' => $gname, ':so' => ($max + 1)]);
                    $success = "Gruppe lagt til.";

                } elseif ($action === 'add_field') {
                    if ($id <= 0) throw new RuntimeException("Lagre malen først.");

                    $label       = trim($_POST['label'] ?? '');
                    $field_type  = trim($_POST['field_type'] ?? 'text');
                    $group_id    = (int)($_POST['group_id'] ?? 0);
                    $is_required = isset($_POST['is_required']) ? 1 : 0;
                    $help_text   = trim($_POST['help_text'] ?? '');

                    if ($label === '') throw new RuntimeException("Label er påkrevd.");

                    $allowedTypes = ['text','number','date','datetime','bool','select','multiselect','url','json','textarea'];
                    if (!in_array($field_type, $allowedTypes, true)) throw new RuntimeException("Ugyldig felttype.");

                    $baseKey = make_field_key($label);
                    $field_key = ensure_unique_key($pdo, $id, $baseKey);

                    // legg nederst i valgt gruppe (maks sort_order + 1)
                    $max = 0;
                    try {
                        $st = $pdo->prepare("
                            SELECT COALESCE(MAX(sort_order),0)
                              FROM node_location_custom_fields
                             WHERE template_id=:tid AND " . ($group_id > 0 ? "group_id=:gid" : "group_id IS NULL") . "
                        ");
                        $params = [':tid' => $id];
                        if ($group_id > 0) $params[':gid'] = $group_id;
                        $st->execute($params);
                        $max = (int)$st->fetchColumn();
                    } catch (\Throwable $e) { $max = 0; }

                    $pdo->prepare("
                      INSERT INTO node_location_custom_fields
                        (template_id, group_id, field_key, label, field_type, is_required, sort_order, help_text)
                      VALUES
                        (:tid, :gid, :k, :l, :t, :r, :so, :h)
                    ")->execute([
                        ':tid' => $id,
                        ':gid' => ($group_id > 0 ? $group_id : null),
                        ':k'   => $field_key,
                        ':l'   => $label,
                        ':t'   => $field_type,
                        ':r'   => $is_required,
                        ':so'  => ($max + 1),
                        ':h'   => ($help_text === '' ? null : $help_text),
                    ]);
                    $success = "Felt lagt til. (key: {$field_key})";

                } elseif ($action === 'update_field') {
                    if ($id <= 0) throw new RuntimeException("Malen mangler.");
                    $field_id    = (int)($_POST['field_id'] ?? 0);
                    if ($field_id <= 0) throw new RuntimeException("Felt mangler.");

                    $label       = trim($_POST['label'] ?? '');
                    $field_type  = trim($_POST['field_type'] ?? 'text');
                    $group_id    = (int)($_POST['group_id'] ?? 0);
                    $is_required = isset($_POST['is_required']) ? 1 : 0;
                    $help_text   = trim($_POST['help_text'] ?? '');

                    if ($label === '') throw new RuntimeException("Label er påkrevd.");

                    $allowedTypes = ['text','number','date','datetime','bool','select','multiselect','url','json','textarea'];
                    if (!in_array($field_type, $allowedTypes, true)) throw new RuntimeException("Ugyldig felttype.");

                    $st = $pdo->prepare("SELECT field_key, label, group_id FROM node_location_custom_fields WHERE id=:fid AND template_id=:tid");
                    $st->execute([':fid' => $field_id, ':tid' => $id]);
                    $existing = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$existing) throw new RuntimeException("Fant ikke feltet.");

                    $newKey = $existing['field_key'];

                    $regen = isset($_POST['regen_key']) ? 1 : 0;
                    if ($regen || trim((string)$newKey) === '') {
                        $baseKey = make_field_key($label);
                        $newKey = ensure_unique_key($pdo, $id, $baseKey, $field_id);
                    }

                    $pdo->prepare("
                      UPDATE node_location_custom_fields
                         SET group_id=:gid,
                             field_key=:k,
                             label=:l,
                             field_type=:t,
                             is_required=:r,
                             help_text=:h
                       WHERE id=:fid AND template_id=:tid
                    ")->execute([
                        ':gid' => ($group_id > 0 ? $group_id : null),
                        ':k'   => $newKey,
                        ':l'   => $label,
                        ':t'   => $field_type,
                        ':r'   => $is_required,
                        ':h'   => ($help_text === '' ? null : $help_text),
                        ':fid' => $field_id,
                        ':tid' => $id,
                    ]);

                    $success = "Felt oppdatert." . ($regen ? " (ny key: {$newKey})" : "");

                } elseif ($action === 'add_option') {
                    $field_id = (int)($_POST['field_id'] ?? 0);
                    $ov = trim($_POST['opt_value'] ?? '');
                    $ol = trim($_POST['opt_label'] ?? '');
                    if ($field_id <= 0) throw new RuntimeException("Felt mangler.");
                    if ($ov === '' || $ol === '') throw new RuntimeException("Option value/label mangler.");

                    // legg nederst
                    $max = 0;
                    try {
                        $st = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM node_location_custom_field_options WHERE field_id=:fid");
                        $st->execute([':fid' => $field_id]);
                        $max = (int)$st->fetchColumn();
                    } catch (\Throwable $e) { $max = 0; }

                    $pdo->prepare("
                      INSERT INTO node_location_custom_field_options (field_id, opt_value, opt_label, sort_order)
                      VALUES (:fid, :v, :l, :so)
                    ")->execute([':fid' => $field_id, ':v' => $ov, ':l' => $ol, ':so' => ($max + 1)]);
                    $success = "Valg lagt til.";

                } elseif ($action === 'delete_field') {
                    $field_id = (int)($_POST['field_id'] ?? 0);
                    if ($field_id <= 0) throw new RuntimeException("Felt mangler.");
                    $pdo->prepare("DELETE FROM node_location_custom_fields WHERE id=:id")->execute([':id' => $field_id]);
                    $success = "Felt slettet.";

                } elseif ($action === 'reorder_groups') {
                    if ($id <= 0) throw new RuntimeException("Malen mangler.");
                    $json = (string)($_POST['group_order_json'] ?? '');
                    $arr = json_decode($json, true);
                    if (!is_array($arr)) throw new RuntimeException("Ugyldig sorteringsdata for grupper.");

                    $orderedIds = array_map('intval', $arr);
                    update_sort_orders(
                        $pdo,
                        'node_location_field_groups',
                        'id',
                        $orderedIds,
                        ['template_id = :tid'],
                        [':tid' => $id]
                    );
                    $success = "Rekkefølge på grupper lagret.";

                } elseif ($action === 'reorder_fields') {
                    if ($id <= 0) throw new RuntimeException("Malen mangler.");

                    $json = (string)($_POST['orders_json'] ?? '');
                    $orders = json_decode($json, true);
                    if (!is_array($orders)) throw new RuntimeException("Ugyldig sorteringsdata for felter.");

                    // orders: { "0": [fieldIds...], "12": [fieldIds...], ... }
                    foreach ($orders as $groupKey => $fieldIds) {
                        if (!is_array($fieldIds)) continue;
                        $groupId = (int)$groupKey;

                        // Oppdater group_id for alle felt i denne listen
                        $st = $pdo->prepare("
                            UPDATE node_location_custom_fields
                               SET group_id = :gid
                             WHERE id = :fid AND template_id = :tid
                        ");

                        foreach ($fieldIds as $fid) {
                            $fid = (int)$fid;
                            if ($fid <= 0) continue;
                            $st->execute([
                                ':gid' => ($groupId > 0 ? $groupId : null),
                                ':fid' => $fid,
                                ':tid' => $id,
                            ]);
                        }

                        // Oppdater sort_order i denne gruppen
                        $i = 0;
                        $st2 = $pdo->prepare("
                            UPDATE node_location_custom_fields
                               SET sort_order = :so
                             WHERE id = :fid AND template_id = :tid
                        ");
                        foreach ($fieldIds as $fid) {
                            $fid = (int)$fid;
                            if ($fid <= 0) continue;
                            $st2->execute([':so' => $i, ':fid' => $fid, ':tid' => $id]);
                            $i++;
                        }
                    }

                    $success = "Rekkefølge på felter lagret.";
                }

                $pdo->commit();

                // AJAX: returner JSON
                if (!empty($_POST['_ajax'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'message' => $success ?: 'OK']);
                    exit;
                }

                header("Location: /?page=node_location_template_edit&id=" . (int)$id . "&saved=1");
                exit;

            } catch (\Throwable $e) {
                $pdo->rollBack();

                if (!empty($_POST['_ajax'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
                    exit;
                }

                $errors[] = $e->getMessage();
            }
        }
    }
}

if (isset($_GET['saved'])) $success = $success ?: "OK.";

$groups = [];
$fields = [];

if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM node_location_field_groups WHERE template_id=:id ORDER BY sort_order, name");
    $st->execute([':id' => $id]);
    $groups = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
      SELECT f.*, g.name AS group_name, g.sort_order AS group_sort
        FROM node_location_custom_fields f
        LEFT JOIN node_location_field_groups g ON g.id = f.group_id
       WHERE f.template_id=:id
       ORDER BY
            COALESCE(g.sort_order, 999999),
            COALESCE(g.name,''),
            f.sort_order,
            f.label
    ");
    $st->execute([':id' => $id]);
    $fields = $st->fetchAll(PDO::FETCH_ASSOC);

    $fieldIds = array_map(fn($x) => (int)$x['id'], $fields);
    $optionsByField = [];
    if ($fieldIds) {
        $in = implode(',', array_fill(0, count($fieldIds), '?'));
        $st2 = $pdo->prepare("SELECT * FROM node_location_custom_field_options WHERE field_id IN ($in) ORDER BY sort_order, opt_label");
        $st2->execute($fieldIds);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $optionsByField[(int)$o['field_id']][] = $o;
        }
    }
    foreach ($fields as &$f) {
        $f['options'] = $optionsByField[(int)$f['id']] ?? [];
    }
    unset($f);
}

// Groupér felter for UI (inkl. "Ugruppert" = 0)
$fieldsByGroup = [];
foreach ($fields as $f) {
    $gid = (int)($f['group_id'] ?? 0);
    $fieldsByGroup[$gid][] = $f;
}
if (!isset($fieldsByGroup[0])) $fieldsByGroup[0] = [];

$csrf = csrf_token();
?>

<style>
/* Små UX-forbedringer for drag/drop */
.drag-handle {
  cursor: grab;
  user-select: none;
}
.dragging {
  opacity: .6;
}
.drop-hint {
  border: 2px dashed rgba(0,0,0,.15) !important;
}
.field-card-header {
  cursor: pointer;
}
.field-meta {
  font-size: .85rem;
}
</style>

<div class="d-flex align-items-center justify-content-between mt-3">
  <div>
    <h3 class="mb-0"><?= $id > 0 ? 'Rediger mal' : 'Ny mal' ?></h3>
    <?php if ($id > 0): ?><div class="text-muted small">ID: <?= (int)$id ?></div><?php endif; ?>
    <?php if (!$canWrite): ?>
      <div class="text-muted small">Du har kun lesetilgang (feltobjekter_les).</div>
    <?php endif; ?>
  </div>
  <a class="btn btn-outline-secondary" href="/?page=node_location_templates">Til maler</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger mt-3">
    <b>Feil</b>
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert alert-success mt-3"><?= h($success) ?></div>
<?php endif; ?>

<form class="card mt-3" method="post">
  <div class="card-body">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="save_template">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Navn</label>
        <input class="form-control" name="name" value="<?= h($template['name']) ?>" <?= $canWrite ? '' : 'disabled' ?>>
      </div>
      <div class="col-md-6">
        <label class="form-label">Aktiv</label>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= ((int)$template['is_active'] === 1 ? 'checked' : '') ?> <?= $canWrite ? '' : 'disabled' ?>>
          <label class="form-check-label">Ja</label>
        </div>
      </div>
      <div class="col-12">
        <label class="form-label">Beskrivelse</label>
        <textarea class="form-control" name="description" rows="2" <?= $canWrite ? '' : 'disabled' ?>><?= h($template['description']) ?></textarea>
      </div>
      <div class="col-12">
        <button class="btn btn-primary" type="submit" <?= $canWrite ? '' : 'disabled' ?>>Lagre mal</button>
      </div>
    </div>
  </div>
</form>

<?php if ($id > 0): ?>

  <div class="card mt-3">
    <div class="card-header d-flex align-items-center justify-content-between">
      <b>Grupper</b>
      <span class="text-muted small">Tips: Dra og slipp grupper for å endre rekkefølge.</span>
    </div>
    <div class="card-body">

      <form class="row g-2" method="post">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="add_group">
        <div class="col-md-6">
          <input class="form-control" name="group_name" placeholder="F.eks. Kjøling / Strøm / Fysisk" <?= $canWrite ? '' : 'disabled' ?>>
        </div>
        <div class="col-md-2">
          <button class="btn btn-secondary w-100" type="submit" <?= $canWrite ? '' : 'disabled' ?>>Legg Til</button>
        </div>
        <?php if (!$canWrite): ?>
          <div class="col-12"><div class="text-muted small mt-1">Krever feltobjekter_skriv for å legge til/sortere.</div></div>
        <?php endif; ?>
      </form>

      <?php if ($groups): ?>
        <div class="mt-3 text-muted small">
          Eksisterende grupper: <?= h(implode(', ', array_map(fn($g) => $g['name'], $groups))) ?>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <div class="card mt-3">
    <div class="card-header"><b>Legg Til Felt</b></div>
    <div class="card-body">
      <form class="row g-2" method="post">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="add_field">

        <div class="col-md-3">
          <label class="form-label">Label</label>
          <input class="form-control" name="label" placeholder="Type kjøling" <?= $canWrite ? '' : 'disabled' ?>>
          <div class="form-text">Key genereres automatisk fra label.</div>
        </div>

        <div class="col-md-2">
          <label class="form-label">Type</label>
          <select class="form-select" name="field_type" <?= $canWrite ? '' : 'disabled' ?>>
            <?php foreach (['text','textarea','number','date','datetime','bool','select','multiselect','url','json'] as $t): ?>
              <option value="<?=h($t)?>"><?=h($t)?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Gruppe</label>
          <select class="form-select" name="group_id" <?= $canWrite ? '' : 'disabled' ?>>
            <option value="0">– (Ugruppert)</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">Påkrevd</label>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="is_required" value="1" <?= $canWrite ? '' : 'disabled' ?>>
            <label class="form-check-label">Ja</label>
          </div>
        </div>

        <div class="col-md-10">
          <label class="form-label">Help Text</label>
          <input class="form-control" name="help_text" placeholder="Kort hjelpetekst..." <?= $canWrite ? '' : 'disabled' ?>>
        </div>

        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-secondary w-100" type="submit" <?= $canWrite ? '' : 'disabled' ?>>Legg Til</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-header d-flex align-items-center justify-content-between">
      <b>Felter (Gruppert)</b>
      <span class="text-muted small">Dra og slipp felt (og flytt mellom grupper) for å endre rekkefølge.</span>
    </div>
    <div class="card-body">

      <?php if (!$fields): ?>
        <div class="text-muted">Ingen felter.</div>
      <?php else: ?>

        <?php
          $uiGroups = [];
          foreach ($groups as $g) $uiGroups[] = $g;
          $uiGroups[] = ['id' => 0, 'name' => 'Ugruppert', 'sort_order' => 999999];
        ?>

        <div class="alert alert-info py-2 small mb-3">
          <b>Tips:</b> Dra i håndtaket (☰) for å sortere. Klikk på et felt for å felle det ut og redigere.
        </div>

        <div class="accordion" id="groupsAccordion">
          <div id="groupList" class="d-grid gap-2">
            <?php foreach ($uiGroups as $g): ?>
              <?php
                $gid = (int)$g['id'];
                $gName = (string)$g['name'];
                $groupCollapseId = "group_collapse_{$id}_{$gid}";
                $groupHeadingId  = "group_heading_{$id}_{$gid}";
                $count = isset($fieldsByGroup[$gid]) ? count($fieldsByGroup[$gid]) : 0;
              ?>
              <div class="accordion-item border rounded group-item"
                   data-group-id="<?= (int)$gid ?>"
                   <?= ($gid > 0 && $canWrite ? 'draggable="true"' : '') ?>
              >
                <h2 class="accordion-header" id="<?= h($groupHeadingId) ?>">
                  <button class="accordion-button <?= ($gid === 0 ? 'collapsed' : '') ?> d-flex align-items-center gap-2"
                          type="button"
                          data-bs-toggle="collapse"
                          data-bs-target="#<?= h($groupCollapseId) ?>"
                          aria-expanded="<?= ($gid === 0 ? 'false' : 'true') ?>"
                          aria-controls="<?= h($groupCollapseId) ?>">
                    <span class="drag-handle me-1" title="<?= $canWrite ? 'Dra for å sortere grupper' : 'Kun lesetilgang' ?>" <?= ($gid > 0 && $canWrite) ? '' : 'style="opacity:.4;"' ?>>☰</span>
                    <span class="fw-semibold"><?= h($gName) ?></span>
                    <span class="badge bg-secondary ms-2"><?= (int)$count ?></span>
                    <?php if ($gid > 0): ?>
                      <span class="text-muted ms-auto small">Gruppe-ID: <?= (int)$gid ?></span>
                    <?php else: ?>
                      <span class="text-muted ms-auto small">Felt uten gruppe</span>
                    <?php endif; ?>
                  </button>
                </h2>

                <div id="<?= h($groupCollapseId) ?>"
                     class="accordion-collapse collapse <?= ($gid === 0 ? '' : 'show') ?>"
                     aria-labelledby="<?= h($groupHeadingId) ?>"
                     data-bs-parent="#groupsAccordion"
                >
                  <div class="accordion-body">

                    <div class="row g-2 mb-2">
                      <div class="col-12">
                        <div class="small text-muted">
                          Dra feltene her for å sortere. Du kan også flytte felt til en annen gruppe ved å dra mellom grupper.
                        </div>
                      </div>
                    </div>

                    <div class="field-list d-grid gap-2"
                         data-group-id="<?= (int)$gid ?>"
                         id="fieldList_<?= (int)$gid ?>"
                    >
                      <?php foreach (($fieldsByGroup[$gid] ?? []) as $f): ?>
                        <?php
                          $fid = (int)$f['id'];
                          $fieldCollapseId = "field_collapse_{$id}_{$fid}";
                          $label = (string)$f['label'];
                          $type = (string)$f['field_type'];
                          $required = ((int)$f['is_required'] === 1);
                          $key = (string)$f['field_key'];
                          $help = (string)($f['help_text'] ?? '');
                        ?>

                        <div class="card bg-light field-card border"
                             <?= $canWrite ? 'draggable="true"' : '' ?>
                             data-field-id="<?= (int)$fid ?>"
                             data-current-group-id="<?= (int)$gid ?>"
                        >
                          <div class="card-header d-flex align-items-center justify-content-between field-card-header"
                               data-bs-toggle="collapse"
                               data-bs-target="#<?= h($fieldCollapseId) ?>"
                               aria-expanded="false"
                               aria-controls="<?= h($fieldCollapseId) ?>"
                          >
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                              <span class="drag-handle" title="<?= $canWrite ? 'Dra for å sortere' : 'Kun lesetilgang' ?>" <?= $canWrite ? '' : 'style="opacity:.4;"' ?>>☰</span>
                              <span class="fw-semibold"><?= h($label) ?></span>
                              <span class="badge bg-white text-dark border"><?= h($type) ?></span>
                              <?php if ($required): ?>
                                <span class="badge bg-danger">Påkrevd</span>
                              <?php else: ?>
                                <span class="badge bg-secondary">Valgfri</span>
                              <?php endif; ?>
                              <span class="text-muted field-meta ms-2">
                                Key: <span class="font-monospace"><?= h($key) ?></span>
                              </span>
                            </div>

                            <div class="d-flex align-items-center gap-2">
                              <span class="text-muted small"><?= $canWrite ? 'Klikk for å redigere' : 'Kun visning' ?></span>

                              <form method="post" onsubmit="return confirm('Slette feltet?')" class="ms-2">
                                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="delete_field">
                                <input type="hidden" name="field_id" value="<?= (int)$fid ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        onclick="event.stopPropagation();"
                                        <?= $canWrite ? '' : 'disabled' ?>>
                                  Slett
                                </button>
                              </form>
                            </div>
                          </div>

                          <div id="<?= h($fieldCollapseId) ?>" class="collapse">
                            <div class="card-body">

                              <div class="small text-muted mb-2">
                                Sist: type=<?= h($type) ?>,
                                gruppe=<?= h($f['group_name'] ?: ($gid === 0 ? 'Ugruppert' : '–')) ?>,
                                påkrevd=<?= ($required ? 'Ja' : 'Nei') ?>
                              </div>

                              <form class="row g-2" method="post">
                                <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="update_field">
                                <input type="hidden" name="field_id" value="<?= (int)$fid ?>">

                                <div class="col-md-4">
                                  <label class="form-label">Label</label>
                                  <input class="form-control" name="label" value="<?= h($label) ?>" <?= $canWrite ? '' : 'disabled' ?>>
                                </div>

                                <div class="col-md-3">
                                  <label class="form-label">Type</label>
                                  <select class="form-select" name="field_type" <?= $canWrite ? '' : 'disabled' ?>>
                                    <?php foreach (['text','textarea','number','date','datetime','bool','select','multiselect','url','json'] as $t): ?>
                                      <option value="<?=h($t)?>" <?= ($type === $t ? 'selected' : '') ?>><?=h($t)?></option>
                                    <?php endforeach; ?>
                                  </select>
                                </div>

                                <div class="col-md-3">
                                  <label class="form-label">Gruppe</label>
                                  <select class="form-select" name="group_id" <?= $canWrite ? '' : 'disabled' ?>>
                                    <option value="0" <?= ($gid === 0 ? 'selected' : '') ?>>– (Ugruppert)</option>
                                    <?php foreach ($groups as $gg): ?>
                                      <option value="<?= (int)$gg['id'] ?>" <?= ((int)$f['group_id'] === (int)$gg['id'] ? 'selected' : '') ?>>
                                        <?= h($gg['name']) ?>
                                      </option>
                                    <?php endforeach; ?>
                                  </select>
                                </div>

                                <div class="col-md-2">
                                  <label class="form-label">Påkrevd</label>
                                  <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_required" value="1" <?= ($required ? 'checked' : '') ?> <?= $canWrite ? '' : 'disabled' ?>>
                                    <label class="form-check-label">Ja</label>
                                  </div>
                                </div>

                                <div class="col-md-10">
                                  <label class="form-label">Help Text</label>
                                  <input class="form-control" name="help_text" value="<?= h($help) ?>" placeholder="Kort hjelpetekst..." <?= $canWrite ? '' : 'disabled' ?>>
                                </div>

                                <div class="col-md-6">
                                  <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="regen_<?= (int)$fid ?>" name="regen_key" value="1" <?= $canWrite ? '' : 'disabled' ?>>
                                    <label class="form-check-label" for="regen_<?= (int)$fid ?>">
                                      Regenerer key basert på label (beholder eksisterende hvis ikke huket av)
                                    </label>
                                  </div>
                                  <div class="form-text">
                                    NB: Endring av key kan påvirke API-integrasjoner som refererer til key.
                                  </div>
                                </div>

                                <div class="col-md-2 d-flex align-items-end">
                                  <button class="btn btn-sm btn-primary w-100" type="submit" <?= $canWrite ? '' : 'disabled' ?>>Oppdater</button>
                                </div>
                              </form>

                              <?php if (in_array($type, ['select','multiselect'], true)): ?>
                                <hr class="my-3">
                                <div class="small text-muted mb-2">Valg</div>

                                <?php if (!empty($f['options'])): ?>
                                  <div class="small mb-2">
                                    <?php foreach ($f['options'] as $o): ?>
                                      <span class="badge bg-white text-dark border me-1 mb-1">
                                        <?= h($o['opt_label']) ?> (<?= h($o['opt_value']) ?>)
                                      </span>
                                    <?php endforeach; ?>
                                  </div>
                                <?php else: ?>
                                  <div class="small text-muted mb-2">Ingen valg ennå.</div>
                                <?php endif; ?>

                                <form class="row g-2" method="post">
                                  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
                                  <input type="hidden" name="action" value="add_option">
                                  <input type="hidden" name="field_id" value="<?= (int)$fid ?>">
                                  <div class="col-md-4">
                                    <input class="form-control form-control-sm" name="opt_value" placeholder="value (AC)" <?= $canWrite ? '' : 'disabled' ?>>
                                  </div>
                                  <div class="col-md-6">
                                    <input class="form-control form-control-sm" name="opt_label" placeholder="label (Air Condition)" <?= $canWrite ? '' : 'disabled' ?>>
                                  </div>
                                  <div class="col-md-2">
                                    <button class="btn btn-sm btn-outline-secondary w-100" type="submit" <?= $canWrite ? '' : 'disabled' ?>>Legg Til</button>
                                  </div>
                                </form>
                              <?php endif; ?>

                            </div>
                          </div>
                        </div>

                      <?php endforeach; ?>

                      <?php if (empty($fieldsByGroup[$gid])): ?>
                        <div class="text-muted small">Ingen felter i denne gruppen.</div>
                      <?php endif; ?>
                    </div>

                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

      <?php endif; ?>

    </div>
  </div>

  <script>
  (function(){
    const csrf = <?= json_encode($csrf) ?>;
    const canWrite = <?= $canWrite ? 'true' : 'false' ?>;

    function postAjax(data) {
      return fetch(location.href, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: data
      }).then(async r => {
        const txt = await r.text();
        let json = null;
        try { json = JSON.parse(txt); } catch(e) {}
        if (!r.ok) {
          const msg = (json && json.message) ? json.message : (txt || 'Ukjent feil');
          throw new Error(msg);
        }
        return json || { ok:true };
      });
    }

    if (!canWrite) {
      // Ingen drag/drop lagring i lesemodus
      return;
    }

    // -------------------------
    // GROUP drag/drop (reorder)
    // -------------------------
    const groupList = document.getElementById('groupList');
    let draggingGroup = null;

    function groupsCollectOrder() {
      const ids = [];
      groupList.querySelectorAll('.group-item').forEach(el => {
        const gid = parseInt(el.getAttribute('data-group-id') || '0', 10);
        if (gid > 0) ids.push(gid);
      });
      return ids;
    }

    function saveGroupOrder() {
      const order = groupsCollectOrder();
      const fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('_ajax', '1');
      fd.append('action', 'reorder_groups');
      fd.append('group_order_json', JSON.stringify(order));
      return postAjax(fd);
    }

    groupList.querySelectorAll('.group-item[draggable="true"]').forEach(item => {
      item.addEventListener('dragstart', (e) => {
        draggingGroup = item;
        item.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
      });
      item.addEventListener('dragend', () => {
        item.classList.remove('dragging');
        draggingGroup = null;
        groupList.querySelectorAll('.group-item').forEach(x => x.classList.remove('drop-hint'));
      });
      item.addEventListener('dragover', (e) => {
        e.preventDefault();
        if (!draggingGroup || draggingGroup === item) return;
        item.classList.add('drop-hint');
      });
      item.addEventListener('dragleave', () => item.classList.remove('drop-hint'));
      item.addEventListener('drop', async (e) => {
        e.preventDefault();
        item.classList.remove('drop-hint');
        if (!draggingGroup || draggingGroup === item) return;

        const rect = item.getBoundingClientRect();
        const after = (e.clientY - rect.top) > (rect.height / 2);
        if (after) item.after(draggingGroup);
        else item.before(draggingGroup);

        try {
          await saveGroupOrder();
        } catch(err) {
          alert('Kunne ikke lagre grupperekkefølge: ' + err.message);
        }
      });
    });

    groupList.addEventListener('dragover', (e) => {
      if (draggingGroup) e.preventDefault();
    });

    // -------------------------
    // FIELD drag/drop (reorder + move between groups)
    // -------------------------
    const fieldLists = Array.from(document.querySelectorAll('.field-list'));
    let draggingField = null;

    function collectOrders() {
      const orders = {};
      fieldLists.forEach(list => {
        const gid = parseInt(list.getAttribute('data-group-id') || '0', 10);
        orders[String(gid)] = Array.from(list.querySelectorAll('.field-card'))
          .map(card => parseInt(card.getAttribute('data-field-id') || '0', 10))
          .filter(x => x > 0);
      });
      return orders;
    }

    function saveFieldOrders() {
      const orders = collectOrders();
      const fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('_ajax', '1');
      fd.append('action', 'reorder_fields');
      fd.append('orders_json', JSON.stringify(orders));
      return postAjax(fd);
    }

    function bindFieldCard(card) {
      card.addEventListener('dragstart', (e) => {
        draggingField = card;
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('dragging');
        draggingField = null;
        document.querySelectorAll('.field-card, .field-list').forEach(x => x.classList.remove('drop-hint'));
      });
    }

    document.querySelectorAll('.field-card').forEach(bindFieldCard);

    fieldLists.forEach(list => {
      list.addEventListener('dragover', (e) => {
        if (!draggingField) return;
        e.preventDefault();
        list.classList.add('drop-hint');
      });
      list.addEventListener('dragleave', () => list.classList.remove('drop-hint'));

      list.addEventListener('drop', async (e) => {
        if (!draggingField) return;
        e.preventDefault();
        list.classList.remove('drop-hint');

        const targetCard = e.target.closest('.field-card');
        if (targetCard && targetCard !== draggingField) {
          const rect = targetCard.getBoundingClientRect();
          const after = (e.clientY - rect.top) > (rect.height / 2);
          if (after) targetCard.after(draggingField);
          else targetCard.before(draggingField);
        } else {
          list.appendChild(draggingField);
        }

        try {
          await saveFieldOrders();
        } catch(err) {
          alert('Kunne ikke lagre feltsortering: ' + err.message);
        }
      });
    });

    // Unngå at klikk på knapper inni header triggere collapse utilsiktet
    document.querySelectorAll('.field-card-header button, .field-card-header form, .field-card-header a').forEach(el => {
      el.addEventListener('click', (e) => e.stopPropagation());
    });

  })();
  </script>

<?php endif; ?>
