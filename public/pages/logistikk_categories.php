<?php
// public/pages/logistikk_categories.php

use App\Database;

$pageTitle = 'Logistikk: Kategorier';

// Krev innlogging
$username = $_SESSION['username'] ?? '';
if (!$username) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du må være innlogget.</div>
    <?php
    return;
}

$pdo = Database::getConnection();

// ---------------------------------------------------------
// Rolle-sjekk (user_roles) + bakoverkompatibel admin-fallback
// ---------------------------------------------------------
$isAdmin = (bool)($_SESSION['is_admin'] ?? false);
if ($username === 'rsv') {
    $isAdmin = true;
}

// Hent current user_id + roller
$currentUserId = 0;
$currentRoles  = [];

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $currentUserId = (int)($stmt->fetchColumn() ?: 0);

    if ($currentUserId > 0) {
        $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $stmt->execute([':uid' => $currentUserId]);
        $currentRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
} catch (\Throwable $e) {
    $currentRoles = [];
}

// Admin via rolle
if (!$isAdmin && in_array('admin', $currentRoles, true)) {
    $isAdmin = true;
}

$canWarehouseRead  = $isAdmin || in_array('warehouse_read', $currentRoles, true) || in_array('warehouse_write', $currentRoles, true);
$canWarehouseWrite = $isAdmin || in_array('warehouse_write', $currentRoles, true);

// Krev minst lesetilgang
if (!$canWarehouseRead) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">
        Du har ikke tilgang til kategorier.
    </div>
    <?php
    return;
}

$errors = [];
$successMessage = null;

// -----------------------------
// Helpers
// -----------------------------
function post_int(string $key): int
{
    return isset($_POST[$key]) ? (int)$_POST[$key] : 0;
}
function post_str(string $key): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :t
    ");
    $stmt->execute([':t' => $table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

$tablesOk = tableExists($pdo, 'inv_categories') && tableExists($pdo, 'inv_products');
if (!$tablesOk) {
    ?>
    <div class="alert alert-warning mt-3">
        <div class="fw-semibold mb-1">Mangler tabeller</div>
        Denne siden krever inv_categories og inv_products.
    </div>
    <?php
    return;
}

function fetchCategoriesFlat(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, parent_id, name
        FROM inv_categories
        ORDER BY parent_id ASC, name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildTree(array $rows): array
{
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['id']] = [
            'id' => (int)$r['id'],
            'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'name' => (string)$r['name'],
            'children' => [],
        ];
    }

    $root = [];
    foreach ($byId as $id => &$node) {
        $pid = $node['parent_id'];
        if ($pid && isset($byId[$pid])) {
            $byId[$pid]['children'][] = &$node;
        } else {
            $root[] = &$node;
        }
    }
    unset($node);

    return $root;
}

function flattenTreeForSelect(array $tree, int $depth = 0, array &$out = []): array
{
    foreach ($tree as $n) {
        $out[] = [
            'id' => (int)$n['id'],
            'label' => str_repeat('— ', $depth) . $n['name'],
        ];
        if (!empty($n['children'])) {
            flattenTreeForSelect($n['children'], $depth + 1, $out);
        }
    }
    return $out;
}

function categoryHasChildren(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_categories WHERE parent_id = :id");
    $stmt->execute([':id' => $id]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function categoryHasProducts(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_products WHERE category_id = :id");
    $stmt->execute([':id' => $id]);
    return ((int)$stmt->fetchColumn()) > 0;
}

/**
 * Antall produkttyper direkte i kategorien.
 * (Teller inv_products-rader som peker på category_id.)
 */
function fetchProductTypeCountByCategory(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT category_id, COUNT(*) AS cnt
        FROM inv_products
        WHERE category_id IS NOT NULL
        GROUP BY category_id
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['category_id']] = (int)$r['cnt'];
    }
    return $map;
}

// -----------------------------
// POST/GET-handling (vanlig)
// -----------------------------
$editId   = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$deleteId = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;

try {
    // Slett via GET (krever write)
    if ($deleteId > 0) {
        if (!$canWarehouseWrite) {
            throw new RuntimeException('Du har ikke rettighet til å slette kategorier (krever warehouse_write eller admin).');
        }

        if (categoryHasChildren($pdo, $deleteId)) {
            throw new RuntimeException('Kan ikke slette: kategorien har underkategorier.');
        }
        if (categoryHasProducts($pdo, $deleteId)) {
            throw new RuntimeException('Kan ikke slette: kategorien er i bruk av varer. Flytt varer først.');
        }

        $stmt = $pdo->prepare("DELETE FROM inv_categories WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $deleteId]);
        $successMessage = 'Kategori slettet.';
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // All endring krever write
        if (!$canWarehouseWrite) {
            throw new RuntimeException('Du har ikke rettighet til å endre kategorier (krever warehouse_write eller admin).');
        }

        $action = post_str('action');

        if ($action === 'create_category') {
            $name = post_str('name');
            $parentId = post_int('parent_id');

            if ($name === '') throw new RuntimeException('Kategorinavn mangler.');

            $stmt = $pdo->prepare("INSERT INTO inv_categories (parent_id, name) VALUES (:pid, :name)");
            $stmt->execute([
                ':pid' => $parentId > 0 ? $parentId : null,
                ':name' => $name,
            ]);

            $successMessage = 'Kategori opprettet.';
        }

        if ($action === 'update_category') {
            $id = post_int('id');
            $name = post_str('name');
            $parentId = post_int('parent_id');

            if ($id <= 0) throw new RuntimeException('Ugyldig kategori.');
            if ($name === '') throw new RuntimeException('Kategorinavn mangler.');
            if ($parentId === $id) throw new RuntimeException('En kategori kan ikke være sin egen overkategori.');

            // enkel sirkel-sjekk (walk ancestors)
            $newParentId = $parentId > 0 ? $parentId : null;
            if ($newParentId !== null) {
                $cur = $newParentId;
                $seen = 0;
                while ($cur > 0 && $seen < 500) {
                    if ($cur === $id) {
                        throw new RuntimeException('Ugyldig overkategori: skaper sirkel i treet.');
                    }
                    $stmt = $pdo->prepare("SELECT parent_id FROM inv_categories WHERE id = :id");
                    $stmt->execute([':id' => $cur]);
                    $p = $stmt->fetchColumn();
                    if ($p === false || $p === null) break;
                    $cur = (int)$p;
                    $seen++;
                }
            }

            $stmt = $pdo->prepare("
                UPDATE inv_categories
                SET parent_id = :pid, name = :name
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([
                ':pid' => $newParentId,
                ':name' => $name,
                ':id' => $id,
            ]);

            $successMessage = 'Kategori oppdatert.';
            $editId = $id;
        }
    }
} catch (\Throwable $e) {
    $errors[] = $e->getMessage();
}

// -----------------------------
// Data til visning
// -----------------------------
$flat           = fetchCategoriesFlat($pdo);
$tree           = buildTree($flat);
$selectOptions  = flattenTreeForSelect($tree);
$productTypeCnt = fetchProductTypeCountByCategory($pdo);

$editCategory = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT id, parent_id, name FROM inv_categories WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editId]);
    $editCategory = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editCategory) {
        $errors[] = 'Fant ikke kategorien du prøver å redigere.';
        $editId = 0;
    }
}

// -----------------------------
// Render: DnD tre
// -----------------------------
function renderTreeDnD(array $tree, array $productTypeCnt, bool $canWarehouseWrite): void
{
    echo '<ul class="cat-tree" id="catRoot">';
    foreach ($tree as $n) {
        renderNodeDnD($n, $productTypeCnt, $canWarehouseWrite);
    }
    echo '</ul>';
}

function renderNodeDnD(array $n, array $productTypeCnt, bool $canWarehouseWrite): void
{
    $id         = (int)$n['id'];
    $name       = (string)$n['name'];
    $childCount = !empty($n['children']) ? count($n['children']) : 0;
    $hasKids    = $childCount > 0;

    $typesHere = $productTypeCnt[$id] ?? 0;

    ?>
    <li class="cat-li" data-id="<?php echo $id; ?>">
        <div class="cat-node" <?php echo $canWarehouseWrite ? 'draggable="true"' : ''; ?> data-id="<?php echo $id; ?>">
            <div class="cat-left">
                <span class="drag-handle" title="<?php echo $canWarehouseWrite ? 'Dra meg' : 'Kun lesetilgang'; ?>">
                    <i class="bi bi-grip-vertical"></i>
                </span>

                <div class="cat-title">
                    <div class="fw-semibold">
                        <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <div class="small text-muted d-flex flex-wrap align-items-center" style="gap:.5rem;">
                        <?php if ($hasKids): ?>
                            <span><?php echo $childCount; ?> underkategori<?php echo $childCount === 1 ? '' : 'er'; ?></span>
                            <span class="text-muted">·</span>
                        <?php endif; ?>

                        <span class="badge bg-light text-dark border">
                            <?php echo $typesHere; ?> produkttype<?php echo $typesHere === 1 ? '' : 'r'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="cat-actions">
                <?php if ($canWarehouseWrite): ?>
                    <a class="btn btn-sm btn-outline-primary"
                       href="/?page=logistikk_categories&edit_id=<?php echo $id; ?>"
                       title="Rediger">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <a class="btn btn-sm btn-outline-danger"
                       href="/?page=logistikk_categories&delete_id=<?php echo $id; ?>"
                       onclick="return confirm('Slette kategori? Kun mulig hvis den ikke har underkategorier eller varer.');"
                       title="Slett">
                        <i class="bi bi-trash"></i>
                    </a>
                <?php else: ?>
                    <span class="text-muted small">Les</span>
                <?php endif; ?>
            </div>
        </div>

        <ul class="cat-children" <?php echo $hasKids ? '' : 'style="display:none;"'; ?>>
            <?php if ($hasKids): ?>
                <?php foreach ($n['children'] as $c): ?>
                    <?php renderNodeDnD($c, $productTypeCnt, $canWarehouseWrite); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </li>
    <?php
}
?>

<style>
    .tree-dropzone {
        border: 2px dashed rgba(0,0,0,.2);
        padding: .75rem;
        background: rgba(0,0,0,.03);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        user-select: none;
    }
    .tree-dropzone.active {
        border-color: rgba(0,0,0,.45);
        background: rgba(0,0,0,.06);
    }
    .tree-dropzone.disabled {
        opacity: .6;
        filter: grayscale(40%);
        cursor: not-allowed;
    }

    .cat-tree, .cat-children { list-style:none; margin:0; padding-left:0; }

    .cat-li { position:relative; margin:.35rem 0; padding-left:1.2rem; }

    .cat-li::before {
        content:"";
        position:absolute;
        left:.55rem;
        top:-.35rem;
        width:1px;
        height:calc(100% + .7rem);
        background:rgba(0,0,0,.18);
    }
    .cat-li::after {
        content:"";
        position:absolute;
        left:.55rem;
        top:1.1rem;
        width:.65rem;
        height:1px;
        background:rgba(0,0,0,.18);
    }
    .cat-li:last-child::before { height:1.5rem; }

    .cat-node {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:1rem;
        padding:.6rem .75rem;
        border:1px solid rgba(0,0,0,.12);
        background:rgba(255,255,255,.9);
    }

    .cat-left { display:flex; align-items:center; gap:.6rem; min-width:0; }
    .drag-handle {
        opacity:.65;
        cursor:grab;
        padding:.15rem .25rem;
        border:1px solid rgba(0,0,0,.08);
        background:rgba(0,0,0,.02);
        flex:0 0 auto;
    }
    .cat-node:active .drag-handle { cursor:grabbing; }
    .cat-title { min-width:0; }
    .cat-actions .btn { border-radius:0 !important; }

    .cat-node.dragging { opacity:.45; }
    .cat-node.drop-target { outline:2px dashed rgba(0,0,0,.45); outline-offset:2px; }

    .cat-children { padding-left:1.35rem; margin-top:.25rem; }

    @media (max-width: 991.98px) {
        .cat-node { flex-direction:column; align-items:flex-start; }
        .cat-actions { width:100%; display:flex; gap:.5rem; }
        .cat-li { padding-left:.9rem; }
        .cat-children { padding-left:1.1rem; }
    }
</style>

<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h3 class="mb-0">Kategorier</h3>
            <div class="text-muted">
                Dra og slipp for å flytte en kategori under en annen (uendelig dybde).
            </div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk">
                <i class="bi bi-arrow-left"></i> Til oversikt
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <div class="fw-semibold mb-1">Feil</div>
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                    <li><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if (!$canWarehouseWrite): ?>
        <div class="alert alert-info">
            Du har lesetilgang til varelager, men ikke skrivetilgang.
            Endringer krever rollen <code>warehouse_write</code> (eller <code>admin</code>).
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- Opprett / Rediger -->
        <div class="col-12 col-xxl-4">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-semibold"><?php echo $editCategory ? 'Rediger kategori' : 'Ny kategori'; ?></span>
                    <?php if ($editCategory): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="/?page=logistikk_categories">Avbryt</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$canWarehouseWrite): ?>
                        <div class="alert alert-secondary mb-0">
                            Opprett/rediger er deaktivert (krever <code>warehouse_write</code> eller <code>admin</code>).
                        </div>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="action" value="<?php echo $editCategory ? 'update_category' : 'create_category'; ?>">
                            <?php if ($editCategory): ?>
                                <input type="hidden" name="id" value="<?php echo (int)$editCategory['id']; ?>">
                            <?php endif; ?>

                            <div class="mb-2">
                                <label class="form-label">Navn</label>
                                <input class="form-control" name="name" required
                                       value="<?php echo htmlspecialchars($editCategory['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Overkategori (valgfritt)</label>
                                <select class="form-select" name="parent_id">
                                    <option value="0">— Ingen (toppnivå)</option>
                                    <?php foreach ($selectOptions as $opt): ?>
                                        <?php
                                        $selected = false;
                                        if ($editCategory) {
                                            $selected = ((int)($editCategory['parent_id'] ?? 0) === (int)$opt['id']);
                                        }
                                        if ($editCategory && (int)$editCategory['id'] === (int)$opt['id']) continue;
                                        ?>
                                        <option value="<?php echo (int)$opt['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Du kan også flytte kategorier visuelt med dra-og-slipp i treet.
                                </div>
                            </div>

                            <div class="mt-3">
                                <button class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i>
                                    <?php echo $editCategory ? 'Lagre' : 'Opprett'; ?>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="alert alert-info mt-3 mb-0">
                <div class="fw-semibold mb-1">Dra og slipp</div>
                <?php if ($canWarehouseWrite): ?>
                    Dra en kategori og slipp den <b>på</b> en annen kategori for å gjøre den til underkategori.
                    Slipp på “Toppnivå” for å flytte til toppnivå.
                <?php else: ?>
                    Drag&drop er deaktivert uten skrivetilgang.
                <?php endif; ?>
            </div>
        </div>

        <!-- Trevisning -->
        <div class="col-12 col-xxl-8">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-semibold">Kategori-tre</span>
                    <span class="text-muted small"><?php echo count($flat); ?> stk</span>
                </div>
                <div class="card-body">
                    <div id="rootDropzone"
                         class="tree-dropzone mb-3 <?php echo !$canWarehouseWrite ? 'disabled' : ''; ?>"
                         data-id="0"
                         <?php echo !$canWarehouseWrite ? 'title="Krever warehouse_write eller admin"' : ''; ?>>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-arrow-up-square"></i>
                            <span class="fw-semibold">Toppnivå</span>
                            <span class="text-muted small">Slipp her for å gjøre en kategori til toppnivå</span>
                        </div>
                        <span class="badge bg-light text-dark border">Drop</span>
                    </div>

                    <?php if (empty($tree)): ?>
                        <div class="text-muted">Ingen kategorier enda. Opprett en hovedkategori til venstre.</div>
                    <?php else: ?>
                        <?php renderTreeDnD($tree, $productTypeCnt, $canWarehouseWrite); ?>
                    <?php endif; ?>
                </div>
                <div class="card-footer small text-muted">
                    Drag&drop flytter kun parent_id. Sortering innen nivå er fortsatt alfabetisk.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const CAN_WRITE = <?php echo $canWarehouseWrite ? 'true' : 'false'; ?>;
    const MOVE_URL = '/ajax/logistikk_categories_move.php';

    if (!CAN_WRITE) {
        // Lesetilgang: ingen DnD
        return;
    }

    function formEncode(obj) {
        return Object.keys(obj)
            .map(k => encodeURIComponent(k) + '=' + encodeURIComponent(obj[k] == null ? '' : obj[k]))
            .join('&');
    }

    async function apiMoveCategory(id, newParentId) {
        const body = formEncode({
            action: 'move_category',
            id: id,
            new_parent_id: newParentId
        });

        const res = await fetch(MOVE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body,
            credentials: 'same-origin'
        });

        const text = await res.text();
        let data = null;
        try { data = JSON.parse(text); } catch (_) {}

        if (!res.ok) {
            const msg = (data && data.error) ? data.error : ('HTTP ' + res.status + ': ' + text.slice(0, 250));
            throw new Error(msg);
        }

        if (!data || data.ok !== true) {
            const msg = (data && data.error) ? data.error : ('Ugyldig svar: ' + text.slice(0, 250));
            throw new Error(msg);
        }

        return data;
    }

    let draggingId = null;

    function setDropTarget(el, on) {
        if (!el) return;
        el.classList.toggle('drop-target', !!on);
    }

    function ensureChildrenUl(li) {
        let ul = li.querySelector('ul.cat-children');
        if (!ul) {
            ul = document.createElement('ul');
            ul.className = 'cat-children';
            li.appendChild(ul);
        }
        ul.style.display = '';
        return ul;
    }

    function attachNodeDnD(node) {
        node.addEventListener('dragstart', function (e) {
            const id = node.getAttribute('data-id');
            draggingId = id;
            node.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', id); } catch (_) {}
        });

        node.addEventListener('dragend', function () {
            node.classList.remove('dragging');
            draggingId = null;
            document.querySelectorAll('.cat-node.drop-target').forEach(el => el.classList.remove('drop-target'));
        });

        node.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (!draggingId) return;

            const targetId = node.getAttribute('data-id');
            if (targetId === draggingId) return;

            setDropTarget(node, true);
        });

        node.addEventListener('dragleave', function () {
            setDropTarget(node, false);
        });

        node.addEventListener('drop', async function (e) {
            e.preventDefault();
            setDropTarget(node, false);

            const targetId = node.getAttribute('data-id');
            const id = draggingId || (function () {
                try { return e.dataTransfer.getData('text/plain'); } catch (_) { return null; }
            })();

            if (!id || !targetId) return;
            if (id === targetId) return;

            try {
                await apiMoveCategory(id, targetId);

                // Flytt i DOM (id -> inn under targetId)
                const root = document.getElementById('catRoot');
                const targetLi = document.querySelector('li.cat-li[data-id="' + targetId + '"]');
                const movingLi = document.querySelector('li.cat-li[data-id="' + id + '"]');
                if (!movingLi) return;

                if (targetLi) {
                    const ul = ensureChildrenUl(targetLi);
                    ul.appendChild(movingLi);
                } else if (root) {
                    root.appendChild(movingLi);
                }
            } catch (err) {
                alert(err && err.message ? err.message : String(err));
            }
        });
    }

    // Root dropzone (toppnivå)
    const rootDrop = document.getElementById('rootDropzone');
    if (rootDrop) {
        rootDrop.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (!draggingId) return;
            rootDrop.classList.add('active');
            e.dataTransfer.dropEffect = 'move';
        });
        rootDrop.addEventListener('dragleave', function () {
            rootDrop.classList.remove('active');
        });
        rootDrop.addEventListener('drop', async function (e) {
            e.preventDefault();
            rootDrop.classList.remove('active');

            const id = draggingId || (function () {
                try { return e.dataTransfer.getData('text/plain'); } catch (_) { return null; }
            })();

            if (!id) return;

            try {
                await apiMoveCategory(id, 0);

                const root = document.getElementById('catRoot');
                const movingLi = document.querySelector('li.cat-li[data-id="' + id + '"]');
                if (root && movingLi) {
                    root.appendChild(movingLi);
                }
            } catch (err) {
                alert(err && err.message ? err.message : String(err));
            }
        });
    }

    document.querySelectorAll('.cat-node[draggable="true"]').forEach(attachNodeDnD);
})();
</script>
