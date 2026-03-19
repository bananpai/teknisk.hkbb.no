<?php
// public/pages/node_location_view.php
//
// Vis feltobjekt (node_locations)
// - Standard Bootstrap-formatering for dynamiske felt (bedre kontrast i mørke tema)
// - Tydelig gruppering: hver gruppe vises som egen card med badge (antall felt)
// - Rettigheter:
//    * Admin alltid
//    * feltobjekter_les -> kan se
//    * feltobjekter_skriv -> kan redigere (og alt les)
//   (Bakoverkompat: node_read/node_write støttes også)
//
// Forutsetter:
// - Session startet i public/index.php
// - App\Database tilgjengelig

use App\Database;

$pdo = Database::getConnection();
$username = $_SESSION['username'] ?? '';

/* ---------------- Helpers (guards) ---------------- */
if (!function_exists('normalize_list')) {
    function normalize_list($v): array {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $val) {
                // støtt assoc arrays: ['admin'=>true]
                if (is_string($k) && $k !== '' && !is_int($k)) {
                    if ($val) $out[] = $k;
                    continue;
                }
                if (is_scalar($val)) {
                    $s = trim((string)$val);
                    if ($s !== '') $out[] = $s;
                }
            }
            return array_values(array_filter(array_map('strval', $out)));
        }

        if (is_string($v) && trim($v) !== '') {
            $parts = preg_split('/[,\s;]+/', $v) ?: [];
            return array_values(array_filter(array_map('strval', $parts)));
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

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatDateOnly')) {
    function formatDateOnly(?string $dt): string {
        if (!$dt) return '';
        $d = substr((string)$dt, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : (string)$dt;
    }
}

/**
 * Samle session-roller fra flere mulige nøkler
 */
if (!function_exists('session_roles_collect')) {
    function session_roles_collect(): array {
        $keys = ['roles','permissions','groups','user_groups','ad_groups'];
        $all = [];
        foreach ($keys as $k) {
            if (!isset($_SESSION[$k])) continue;
            $all = array_merge($all, normalize_list($_SESSION[$k]));
        }
        return array_values(array_unique(array_filter(array_map('strtolower', $all))));
    }
}

/* ---------------- Storage helpers (best-effort EXIF in view) ---------------- */
if (!function_exists('storageBaseDir')) {
    function storageBaseDir(): string {
        $base = realpath(__DIR__ . '/../../storage/node_locations');
        if ($base) return $base;
        return __DIR__ . '/../../storage/node_locations';
    }
}

if (!function_exists('absPathFromStorageKey')) {
    function absPathFromStorageKey(string $key): string {
        $key = trim((string)$key);
        if ($key === '' || str_contains($key, '..') || $key[0] === '/' || str_contains($key, '\\')) return '';

        $base = storageBaseDir();
        $full = rtrim($base, '/\\') . '/' . $key;

        $rpBase = realpath($base);
        $rpFull = realpath($full);
        if (!$rpBase || !$rpFull) return '';

        if (strpos($rpFull, $rpBase) !== 0) return '';
        return $rpFull;
    }
}

if (!function_exists('exifTakenAt')) {
    function exifTakenAt(string $absPath): ?string {
        if (!is_file($absPath)) return null;
        if (!function_exists('exif_read_data')) return null;

        $exif = @exif_read_data($absPath, 'EXIF', true, false);
        if (!is_array($exif)) return null;

        $candidates = [
            $exif['EXIF']['DateTimeOriginal'] ?? null,
            $exif['EXIF']['DateTimeDigitized'] ?? null,
            $exif['IFD0']['DateTime'] ?? null,
        ];

        foreach ($candidates as $dt) {
            $dt = is_string($dt) ? trim($dt) : '';
            if ($dt === '') continue;
            $d = \DateTime::createFromFormat('Y:m:d H:i:s', $dt);
            if ($d instanceof \DateTime) return $d->format('Y-m-d H:i:s');
        }
        return null;
    }
}

/* ---------------- Attachment URL via API ---------------- */
if (!function_exists('attachmentUrl')) {
    function attachmentUrl(int $attachmentId, array $opts = []): string {
        $q = ['id' => $attachmentId];
        if (!empty($opts['download'])) $q['download'] = 1;
        if (!empty($opts['v'])) $q['v'] = (string)$opts['v'];
        return '/api/node_location_attachment_file.php?' . http_build_query($q);
    }
}

/* ---------------- Guard ---------------- */
if ($username === '') {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

// Roller: session + DB
$roles = session_roles_collect();

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $currentUserId = (int)($stmt->fetchColumn() ?: 0);

    if ($currentUserId > 0) {
        $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :uid');
        $stmt->execute([':uid' => $currentUserId]);
        $dbRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $roles = array_merge($roles, array_map('strtolower', normalize_list($dbRoles)));
    }
} catch (\Throwable $e) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang (DB-feil).</div>
    <?php
    return;
}

$roles = array_values(array_unique(array_filter(array_map('strtolower', $roles))));

// Rettigheter (nytt + bakoverkompat)
$isAdmin = has_any(['admin','administrator','superadmin'], $roles);

// Ny modul-roller:
$canReadNew  = has_any(['feltobjekter_les','feltobjekter_skriv'], $roles);
$canWriteNew = has_any(['feltobjekter_skriv'], $roles);

// Bakoverkompat:
$canReadOld  = has_any(['node_read','node_write'], $roles);
$canWriteOld = has_any(['node_write'], $roles);

$canNodeRead  = $isAdmin || $canReadNew || $canReadOld;
$canNodeWrite = $isAdmin || $canWriteNew || $canWriteOld;

if (!$canNodeRead) {
    http_response_code(403);
    ?>
    <div class="alert alert-danger mt-3">Du har ikke tilgang.</div>
    <?php
    return;
}

/* ---------------- Load node ---------------- */
$statusLabels = [
    'active'         => 'Aktiv',
    'inactive'       => 'Inaktiv',
    'planned'        => 'Planlagt',
    'decommissioned' => 'Avviklet',
];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    ?>
    <div class="alert alert-warning mt-3">Ugyldig ID.</div>
    <?php
    return;
}

$stmt = $pdo->prepare("
    SELECT nl.*, t.name AS template_name
      FROM node_locations nl
      LEFT JOIN node_location_templates t ON t.id = nl.template_id
     WHERE nl.id = :id
     LIMIT 1
");
$stmt->execute([':id' => $id]);
$node = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$node) {
    ?>
    <div class="alert alert-warning mt-3">Fant ikke feltobjekt.</div>
    <?php
    return;
}

/* ---------------- Sist oppdatert av ---------------- */
$lastUser = trim((string)($node['updated_by'] ?? ''));
if ($lastUser === '') {
    try {
        $st = $pdo->prepare("
            SELECT actor
              FROM object_change_log
             WHERE object_type = 'node_location'
               AND object_id = :id
             ORDER BY created_at DESC, id DESC
             LIMIT 1
        ");
        $st->execute([':id' => $id]);
        $lastUser = trim((string)($st->fetchColumn() ?: ''));
    } catch (\Throwable $e) {
        // ignorer
    }
}
if ($lastUser === '') $lastUser = trim((string)($node['created_by'] ?? ''));
$lastUserShown = $lastUser !== '' ? $lastUser : 'ukjent';
$lastDateShown = formatDateOnly((string)($node['updated_at'] ?? ''));

/* ---------------- Fields + values ---------------- */
if (!function_exists('loadFields')) {
    function loadFields(PDO $pdo, int $templateId): array {
        if ($templateId <= 0) return [];
        $sql = "
          SELECT f.*, g.name AS group_name, g.sort_order AS group_sort
            FROM node_location_custom_fields f
            LEFT JOIN node_location_field_groups g ON g.id = f.group_id
           WHERE f.template_id = :tid
           ORDER BY COALESCE(g.sort_order, 999999), COALESCE(g.name,''), f.sort_order, f.label
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':tid' => $templateId]);
        $fields = $st->fetchAll(PDO::FETCH_ASSOC);

        $fieldIdsNeedingOptions = [];
        foreach ($fields as $f) {
            if (in_array($f['field_type'], ['select','multiselect'], true)) {
                $fieldIdsNeedingOptions[] = (int)$f['id'];
            }
        }

        $optionsByField = [];
        if ($fieldIdsNeedingOptions) {
            $in = implode(',', array_fill(0, count($fieldIdsNeedingOptions), '?'));
            $st2 = $pdo->prepare("SELECT * FROM node_location_custom_field_options WHERE field_id IN ($in) ORDER BY sort_order, opt_label");
            $st2->execute($fieldIdsNeedingOptions);
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $optionsByField[(int)$o['field_id']][] = $o;
            }
        }

        foreach ($fields as &$f) {
            $f['options'] = $optionsByField[(int)$f['id']] ?? [];
        }
        unset($f);

        return $fields;
    }
}

$fields = loadFields($pdo, (int)$node['template_id']);

// values
$valuesByFieldId = [];
$stmt = $pdo->prepare("
    SELECT field_id, value_text, value_number, value_date, value_datetime, value_bool, value_json
      FROM node_location_custom_field_values
     WHERE node_location_id = :id
");
$stmt->execute([':id' => $id]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
    $valuesByFieldId[(int)$v['field_id']] = $v;
}

// attachments
$stmt = $pdo->prepare("SELECT * FROM node_location_attachments WHERE node_location_id=:id ORDER BY created_at DESC");
$stmt->execute([':id' => $id]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// best-effort EXIF for visning hvis taken_at mangler
foreach ($attachments as &$a) {
    $mime = (string)($a['mime_type'] ?? '');
    if ($mime === 'image/jpeg' && empty($a['taken_at'])) {
        $abs = absPathFromStorageKey((string)($a['file_path'] ?? ''));
        if ($abs) {
            $ta = exifTakenAt($abs);
            if ($ta) $a['taken_at'] = $ta;
        }
    }
}
unset($a);

// group fields
$grouped = [];
foreach ($fields as $f) {
    $gname = $f['group_name'] ?: 'Generelt';
    $grouped[$gname][] = $f;
}

if (!function_exists('formatFieldValue')) {
    function formatFieldValue(array $field, array $valuesByFieldId): string {
        $fid  = (int)$field['id'];
        $type = (string)$field['field_type'];
        $v    = $valuesByFieldId[$fid] ?? null;
        if (!$v) return '<span class="text-secondary">–</span>';

        if ($type === 'bool') {
            return ((int)($v['value_bool'] ?? 0) === 1) ? 'Ja' : 'Nei';
        }
        if ($type === 'number') {
            $n = $v['value_number'];
            return ($n === null || $n === '') ? '<span class="text-secondary">–</span>' : h((string)$n);
        }
        if ($type === 'date') {
            return $v['value_date'] ? h((string)$v['value_date']) : '<span class="text-secondary">–</span>';
        }
        if ($type === 'datetime') {
            return $v['value_datetime'] ? h((string)$v['value_datetime']) : '<span class="text-secondary">–</span>';
        }
        if ($type === 'json') {
            if (!$v['value_json']) return '<span class="text-secondary">–</span>';
            $decoded = json_decode((string)$v['value_json'], true);
            $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return '<pre class="mb-0 small bg-body-tertiary border rounded p-2">' . h((string)$pretty) . '</pre>';
        }
        if ($type === 'multiselect') {
            $arr = $v['value_json'] ? json_decode((string)$v['value_json'], true) : [];
            if (!is_array($arr) || !$arr) return '<span class="text-secondary">–</span>';

            $map = [];
            foreach (($field['options'] ?? []) as $o) {
                $map[(string)$o['opt_value']] = (string)$o['opt_label'];
            }
            $labels = [];
            foreach ($arr as $vv) {
                $vv = (string)$vv;
                $labels[] = $map[$vv] ?? $vv;
            }
            return h(implode(', ', $labels));
        }
        if ($type === 'select') {
            $val = (string)($v['value_text'] ?? '');
            if ($val === '') return '<span class="text-secondary">–</span>';
            $map = [];
            foreach (($field['options'] ?? []) as $o) {
                $map[(string)$o['opt_value']] = (string)$o['opt_label'];
            }
            return h($map[$val] ?? $val);
        }

        $txt = (string)($v['value_text'] ?? '');
        return $txt === '' ? '<span class="text-secondary">–</span>' : nl2br(h($txt));
    }
}

/* ---------------- Map coords ---------------- */
$latVal = ($node['lat'] === '' || $node['lat'] === null) ? null : (float)$node['lat'];
$lonVal = ($node['lon'] === '' || $node['lon'] === null) ? null : (float)$node['lon'];

$defaultLat = 59.9139;
$defaultLon = 10.7522;

$saved = isset($_GET['saved']);

$attachmentCount = count($attachments);
$imageCount = 0;
foreach ($attachments as $a) {
    $mime = (string)($a['mime_type'] ?? '');
    if (strpos($mime, 'image/') === 0) $imageCount++;
}

// Grupperekkefølge (Generelt først)
$groupNames = array_keys($grouped);
usort($groupNames, function($a, $b) {
    if ($a === 'Generelt') return -1;
    if ($b === 'Generelt') return 1;
    return strcasecmp($a, $b);
});
$totalFields = count($fields);
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">

<style>
  #nlMapView { height: 300px; border-radius: .375rem; }

  /* Zoom/pan viewer */
  #nlViewerImgWrap {
    max-height: 72vh;
    overflow: auto;
    border-radius: .375rem;
  }

  #nlViewerImg {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 0 auto;
    cursor: zoom-in;
    user-select: none;
  }

  #nlViewerImg.zoomed {
    max-width: none;
    cursor: grab;
  }
  #nlViewerImg.zoomed:active {
    cursor: grabbing;
  }

  /* Standard Bootstrap-vennlig fargebruk for gruppene */
  .nl-group-card .card-header{
    background: var(--bs-tertiary-bg);
    color: var(--bs-body-color);
  }
  .nl-field-label{
    color: var(--bs-secondary-color);
  }
</style>

<div class="d-flex align-items-center justify-content-between mt-3">
  <div>
    <h3 class="mb-0">Vis feltobjekt</h3>
    <div class="text-muted small">
      ID: <?= (int)$id ?>
      <?php if ($lastDateShown !== ''): ?>
        · Sist oppdatert: <?= h($lastDateShown) ?> (<?= h($lastUserShown) ?>)
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="/?page=node_locations">Til feltobjekter</a>
    <?php if ($canNodeWrite): ?>
      <a class="btn btn-primary" href="/?page=node_location_edit&id=<?= (int)$id ?>">
        <i class="bi bi-pencil"></i> Rediger
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($saved): ?>
  <div class="alert alert-success mt-3">Lagret.</div>
<?php endif; ?>

<div class="card mt-3">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <div class="text-muted small">Navn</div>
        <div class="fw-semibold"><?= h((string)$node['name']) ?></div>
        <div class="text-muted small">Slug: <code><?= h((string)$node['slug']) ?></code></div>
      </div>

      <div class="col-md-3">
        <div class="text-muted small">Status</div>
        <div><?= h($statusLabels[(string)$node['status']] ?? (string)$node['status']) ?></div>
      </div>

      <div class="col-md-3">
        <div class="text-muted small">Objekttype</div>
        <div><?= h((string)($node['template_name'] ?? '–')) ?></div>
      </div>

      <?php if ((string)$node['description'] !== ''): ?>
        <div class="col-12">
          <div class="text-muted small">Beskrivelse</div>
          <div><?= nl2br(h((string)$node['description'])) ?></div>
        </div>
      <?php endif; ?>

      <div class="col-md-6">
        <div class="text-muted small">Adresse</div>
        <div>
          <?php if (trim((string)$node['address_line1']) !== ''): ?>
            <?= h((string)$node['address_line1']) ?>
            <?php if (trim((string)$node['address_line2']) !== ''): ?><br><?= h((string)$node['address_line2']) ?><?php endif; ?>
            <?php if (trim((string)$node['postal_code']) !== '' || trim((string)$node['city']) !== ''): ?>
              <br><?= h((string)$node['postal_code']) ?> <?= h((string)$node['city']) ?>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-secondary">–</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-md-6">
        <div class="text-muted small">Posisjon</div>
        <div>
          <?php if ($latVal !== null && $lonVal !== null): ?>
            <?= h((string)$node['lat']) ?>, <?= h((string)$node['lon']) ?>
          <?php else: ?>
            <span class="text-secondary">–</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-12">
        <div id="nlMapView" class="border"></div>
      </div>
    </div>
  </div>
</div>

<?php if ($fields): ?>
  <div class="card mt-3">
    <div class="card-header d-flex align-items-center justify-content-between">
      <b>Dynamiske felter</b>
      <span class="text-secondary small"><?= (int)$totalFields ?> felt</span>
    </div>

    <div class="card-body">
      <?php foreach ($groupNames as $gname): ?>
        <?php $gfields = $grouped[$gname] ?? []; ?>
        <div class="card mb-3 nl-group-card">
          <div class="card-header d-flex align-items-center justify-content-between">
            <div class="fw-semibold"><?= h($gname) ?></div>
            <span class="badge text-bg-secondary"><?= (int)count($gfields) ?></span>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <?php foreach ($gfields as $f): ?>
                <div class="col-md-6">
                  <div class="nl-field-label small"><?= h((string)$f['label']) ?></div>
                  <div class="mt-1"><?= formatFieldValue($f, $valuesByFieldId) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card mt-3" id="attachments">
  <div class="card-header">
    <b>Bilder / vedlegg</b>
    <span class="text-muted small"> (<?= (int)$imageCount ?> bilder, <?= (int)$attachmentCount ?> totalt)</span>
  </div>
  <div class="card-body">
    <?php if (!$attachments): ?>
      <div class="text-muted">Ingen vedlegg.</div>
    <?php else: ?>
      <div class="row g-2" id="nlThumbGrid">
        <?php foreach ($attachments as $a): ?>
          <?php
            $aid  = (int)($a['id'] ?? 0);
            $mime = (string)($a['mime_type'] ?? '');
            $isImg = (strpos($mime, 'image/') === 0);

            $desc = (string)($a['description'] ?? '');
            $createdAt = (string)($a['created_at'] ?? '');
            $takenAt = (string)($a['taken_at'] ?? '');

            $url = attachmentUrl($aid);
          ?>
          <div class="col-md-3">
            <div class="border rounded p-2 h-100">
              <div class="small text-muted text-truncate"><?= h((string)($a['original_filename'] ?? ('Vedlegg #' . $aid))) ?></div>

              <?php if ($isImg): ?>
                <a href="#" class="nl-attach-thumb d-block mt-2"
                   data-id="<?= $aid ?>"
                   data-src="<?= h($url) ?>"
                   data-filename="<?= h((string)($a['original_filename'] ?? '')) ?>"
                   data-desc="<?= h($desc) ?>"
                   data-created="<?= h($createdAt) ?>"
                   data-taken="<?= h($takenAt) ?>"
                   data-mime="<?= h($mime) ?>">
                  <img src="<?= h($url) ?>" style="max-width:100%; height:auto; border-radius:.25rem;" alt="">
                </a>
                <div class="small text-muted mt-2">
                  Opplastet: <?= h($createdAt ?: '–') ?><br>
                  Tatt: <?= h($takenAt ?: '–') ?>
                </div>
                <div class="mt-2 d-flex gap-2">
                  <a class="btn btn-sm btn-outline-secondary" href="<?= h($url) ?>" target="_blank" rel="noreferrer">Åpne</a>
                  <a class="btn btn-sm btn-outline-secondary" href="<?= h($url . '&download=1') ?>" rel="noreferrer">Last ned</a>
                </div>
              <?php else: ?>
                <div class="mt-2 d-flex gap-2">
                  <a class="btn btn-sm btn-outline-secondary" href="<?= h($url) ?>" target="_blank" rel="noreferrer">Åpne</a>
                  <a class="btn btn-sm btn-outline-secondary" href="<?= h($url . '&download=1') ?>" rel="noreferrer">Last ned</a>
                </div>
                <div class="small text-muted mt-2">Opplastet: <?= h($createdAt ?: '–') ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="form-text mt-2">
        Klikk på et bilde for visning. Piltaster blar.
        Klikk i bildet for å zoome, bruk musehjul og dra for å panorere.
        <?= $canNodeWrite ? ' Rotér og lagre bildetekst i viseren.' : '' ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal / Viewer -->
<div class="modal fade" id="nlViewer" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div class="w-100">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="fw-semibold" id="nlViewerTitle">Bilde</div>
              <div class="small text-muted" id="nlViewerMeta">–</div>
            </div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary" id="nlPrevBtn" title="Forrige (←)">←</button>
              <button type="button" class="btn btn-outline-secondary" id="nlNextBtn" title="Neste (→)">→</button>

              <?php if ($canNodeWrite): ?>
                <button type="button" class="btn btn-outline-secondary" id="nlRotateCCW" title="Roter 90° mot klokka">⟲ 90°</button>
                <button type="button" class="btn btn-outline-secondary" id="nlRotateCW" title="Roter 90° med klokka">⟳ 90°</button>
              <?php endif; ?>

              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Lukk</button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-body">
        <div id="nlViewerImgWrap">
          <img id="nlViewerImg" src="" alt="">
        </div>

        <div class="mt-3">
          <label class="form-label">Bildetekst</label>
          <textarea class="form-control" id="nlViewerDesc" rows="2" placeholder="Kort beskrivelse..." <?= $canNodeWrite ? '' : 'readonly' ?>></textarea>

          <?php if ($canNodeWrite): ?>
            <div class="d-flex justify-content-end gap-2 mt-2">
              <button type="button" class="btn btn-outline-primary" id="nlSaveDescBtn">Lagre bildetekst</button>
            </div>
          <?php endif; ?>

          <div class="small text-muted mt-2" id="nlViewerStatus"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // -------------------------
  // Map
  // -------------------------
  var hasPos = <?= ($latVal !== null && $lonVal !== null) ? 'true' : 'false' ?>;
  var lat = <?= ($latVal !== null ? json_encode($latVal) : json_encode($defaultLat)) ?>;
  var lon = <?= ($lonVal !== null ? json_encode($lonVal) : json_encode($defaultLon)) ?>;

  var map = L.map('nlMapView', { scrollWheelZoom: true, dragging: true }).setView([lat, lon], hasPos ? 14 : 6);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
  }).addTo(map);

  if (hasPos) {
    L.marker([lat, lon], { draggable: false }).addTo(map);
  }

  setTimeout(function () { map.invalidateSize(); }, 50);

  // -------------------------
  // Viewer
  // -------------------------
  var thumbs = Array.from(document.querySelectorAll('.nl-attach-thumb'));
  if (!thumbs.length) return;

  var modalEl = document.getElementById('nlViewer');
  var modal = new bootstrap.Modal(modalEl);
  var imgEl = document.getElementById('nlViewerImg');
  var wrapEl = document.getElementById('nlViewerImgWrap');
  var titleEl = document.getElementById('nlViewerTitle');
  var metaEl = document.getElementById('nlViewerMeta');
  var descEl = document.getElementById('nlViewerDesc');
  var statusEl = document.getElementById('nlViewerStatus');

  var prevBtn = document.getElementById('nlPrevBtn');
  var nextBtn = document.getElementById('nlNextBtn');
  var rotCCW = document.getElementById('nlRotateCCW');
  var rotCW = document.getElementById('nlRotateCW');
  var saveDescBtn = document.getElementById('nlSaveDescBtn');

  var currentIndex = 0;

  // ---- Zoom/pan state ----
  var zoom = 1;
  var Z_MIN = 1;
  var Z_MAX = 5;
  var Z_STEP = 0.25;

  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

  function applyZoom(resetScroll) {
    if (!wrapEl) return;

    if (zoom <= 1) {
      zoom = 1;
      imgEl.classList.remove('zoomed');
      imgEl.style.width = '';
      imgEl.style.maxWidth = '100%';
      imgEl.style.maxHeight = '72vh';
      imgEl.style.cursor = 'zoom-in';

      if (resetScroll) {
        wrapEl.scrollTop = 0;
        wrapEl.scrollLeft = 0;
      }
    } else {
      imgEl.classList.add('zoomed');
      imgEl.style.maxWidth = 'none';
      imgEl.style.maxHeight = 'none';
      imgEl.style.width = (zoom * 100) + '%';
      imgEl.style.cursor = 'grab';
    }
  }

  function zoomToPoint(ratioX, ratioY) {
    if (!wrapEl) return;
    setTimeout(function () {
      var targetLeft = (wrapEl.scrollWidth * ratioX) - (wrapEl.clientWidth / 2);
      var targetTop  = (wrapEl.scrollHeight * ratioY) - (wrapEl.clientHeight / 2);
      wrapEl.scrollLeft = clamp(targetLeft, 0, wrapEl.scrollWidth - wrapEl.clientWidth);
      wrapEl.scrollTop  = clamp(targetTop,  0, wrapEl.scrollHeight - wrapEl.clientHeight);
    }, 0);
  }

  imgEl.addEventListener('click', function (e) {
    if (!wrapEl) return;

    var rect = imgEl.getBoundingClientRect();
    var rx = (e.clientX - rect.left) / rect.width;
    var ry = (e.clientY - rect.top) / rect.height;

    zoom = (zoom === 1) ? 2 : 1;
    applyZoom(false);

    if (zoom > 1) zoomToPoint(rx, ry);
  });

  if (wrapEl) {
    wrapEl.addEventListener('wheel', function (e) {
      if (zoom <= 1) return;

      e.preventDefault();

      var rect = imgEl.getBoundingClientRect();
      var rx = (e.clientX - rect.left) / rect.width;
      var ry = (e.clientY - rect.top) / rect.height;

      var delta = (e.deltaY < 0) ? Z_STEP : -Z_STEP;
      zoom = clamp(zoom + delta, Z_MIN, Z_MAX);

      applyZoom(false);
      if (zoom > 1) zoomToPoint(rx, ry);
      else applyZoom(true);
    }, { passive: false });
  }

  var isPanning = false;
  var panStartX = 0, panStartY = 0;
  var panScrollLeft = 0, panScrollTop = 0;

  imgEl.addEventListener('mousedown', function (e) {
    if (!wrapEl) return;
    if (zoom <= 1) return;

    isPanning = true;
    panStartX = e.clientX;
    panStartY = e.clientY;
    panScrollLeft = wrapEl.scrollLeft;
    panScrollTop  = wrapEl.scrollTop;
    e.preventDefault();
  });

  document.addEventListener('mousemove', function (e) {
    if (!isPanning || !wrapEl) return;
    var dx = e.clientX - panStartX;
    var dy = e.clientY - panStartY;
    wrapEl.scrollLeft = panScrollLeft - dx;
    wrapEl.scrollTop  = panScrollTop  - dy;
  });

  document.addEventListener('mouseup', function () {
    isPanning = false;
  });

  function getItem(i) {
    var a = thumbs[i];
    return {
      el: a,
      id: a.getAttribute('data-id'),
      src: a.getAttribute('data-src'),
      filename: a.getAttribute('data-filename') || '',
      desc: a.getAttribute('data-desc') || '',
      created: a.getAttribute('data-created') || '',
      taken: a.getAttribute('data-taken') || '',
      mime: a.getAttribute('data-mime') || ''
    };
  }

  function setStatus(msg, isError) {
    statusEl.textContent = msg || '';
    statusEl.className = 'small ' + (isError ? 'text-danger' : 'text-muted') + ' mt-2';
  }

  function showIndex(i) {
    if (i < 0) i = thumbs.length - 1;
    if (i >= thumbs.length) i = 0;
    currentIndex = i;

    var it = getItem(currentIndex);

    zoom = 1;
    applyZoom(true);

    var bust = Date.now();
    imgEl.src = it.src + (it.src.includes('?') ? '&' : '?') + 'v=' + bust;

    titleEl.textContent = it.filename || ('Bilde #' + it.id);

    var createdTxt = it.created ? ('Opplastet: ' + it.created) : 'Opplastet: –';
    var takenTxt = it.taken ? ('Tatt: ' + it.taken) : 'Tatt: –';
    metaEl.textContent = createdTxt + '   |   ' + takenTxt;

    descEl.value = it.desc || '';
    setStatus('');
  }

  function openFromThumb(aEl) {
    var idx = thumbs.indexOf(aEl);
    if (idx < 0) idx = 0;
    showIndex(idx);
    modal.show();
  }

  thumbs.forEach(function(a) {
    a.addEventListener('click', function(e) {
      e.preventDefault();
      openFromThumb(a);
    });
  });

  function keyHandler(e) {
    var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
    if (tag === 'textarea' || tag === 'input') return;

    if (e.key === 'ArrowLeft') { e.preventDefault(); showIndex(currentIndex - 1); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); showIndex(currentIndex + 1); }
  }

  modalEl.addEventListener('shown.bs.modal', function() { document.addEventListener('keydown', keyHandler); });
  modalEl.addEventListener('hidden.bs.modal', function() {
    document.removeEventListener('keydown', keyHandler);
    zoom = 1;
    applyZoom(true);
  });

  prevBtn.addEventListener('click', function() { showIndex(currentIndex - 1); });
  nextBtn.addEventListener('click', function() { showIndex(currentIndex + 1); });

  function postAjax(action, extra) {
    var it = getItem(currentIndex);
    var fd = new FormData();
    fd.append('node_id', '<?= (int)$id ?>');
    fd.append('attachment_id', it.id);
    fd.append('action', action);
    if (extra && typeof extra === 'object') {
      Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); });
    }

    return fetch('/api/node_location_attachments.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    }).then(function(r){
      return r.text().then(function(txt){
        try { return JSON.parse(txt); }
        catch(e){ throw new Error('Server svarte ikke med JSON: ' + txt.slice(0, 250)); }
      });
    });
  }

  function updateThumb(id, newDesc) {
    thumbs.forEach(function(a){
      if (a.getAttribute('data-id') === String(id)) {
        if (typeof newDesc === 'string') a.setAttribute('data-desc', newDesc);
        var img = a.querySelector('img');
        if (img) {
          img.src = a.getAttribute('data-src') + (a.getAttribute('data-src').includes('?') ? '&' : '?') + 'v=' + Date.now();
        }
      }
    });
  }

  if (rotCW) {
    rotCW.addEventListener('click', function() {
      setStatus('Roterer…');
      postAjax('rotate_cw', {}).then(function(res){
        if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Ukjent feil');
        setStatus(res.message || 'OK');
        imgEl.src = (res.url || getItem(currentIndex).src) + ((String(res.url || getItem(currentIndex).src).includes('?')) ? '&' : '?') + 'v=' + Date.now();
        updateThumb(getItem(currentIndex).id, null);
      }).catch(function(err){
        setStatus(err.message || 'Feil', true);
      });
    });
  }

  if (rotCCW) {
    rotCCW.addEventListener('click', function() {
      setStatus('Roterer…');
      postAjax('rotate_ccw', {}).then(function(res){
        if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Ukjent feil');
        setStatus(res.message || 'OK');
        imgEl.src = (res.url || getItem(currentIndex).src) + ((String(res.url || getItem(currentIndex).src).includes('?')) ? '&' : '?') + 'v=' + Date.now();
        updateThumb(getItem(currentIndex).id, null);
      }).catch(function(err){
        setStatus(err.message || 'Feil', true);
      });
    });
  }

  if (saveDescBtn) {
    saveDescBtn.addEventListener('click', function() {
      var desc = (descEl.value || '').trim();
      setStatus('Lagrer…');
      postAjax('save_desc', { description: desc }).then(function(res){
        if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Ukjent feil');
        var newDesc = (res.description !== undefined) ? res.description : desc;
        setStatus(res.message || 'Lagret');
        thumbs[currentIndex].setAttribute('data-desc', newDesc);
        updateThumb(getItem(currentIndex).id, newDesc);
      }).catch(function(err){
        setStatus(err.message || 'Feil', true);
      });
    });
  }
});
</script>
