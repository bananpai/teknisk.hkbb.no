<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\pages\bildekart.php
// Bildekart (desktop) – kjører via /?page=bildekart
//
// Oppdatert 2026-03-09:
// - Ingen egen nodelokasjon-innlogging/fallback
// - Krever kun vanlig innlogging via core/auth.php
// - Ingen ekstra lokal 403-rolleblokkering i denne filen
// - Ved høy zoom vises bildesymboler/grupper i stedet for thumbnail-grid over kartet
// - Klikk på symbol åpner popup med thumbnails i grid
// - Klikk på thumbnail åpner stor bildeviser med neste/forrige + piltaster
// - Viser nodenavn ved symbol hvis tilgjengelig
// - Thumbnail-klikk i popup håndteres med global event delegation for stabil åpning av modal
// - Bilder koblet til nodelokasjon grupperes i ett felles symbol per node
// - Mappede grupper vises som grønt hus, ikke-mappede grupper vises som kamera
// - Nytt: autosuggest i søkefelt (desktop + mobil) med automatisk zoom til treff/valg
// - Nytt: mapping/remapping av bildet direkte fra stor bildeviser (modal)
// - Fix 2026-03-09: mer robust node-søk i modal med API-fallback + lokal fallback
// - Fix 2026-03-09: stabil popup-åpning for grupper ved første klikk uten at popup forsvinner
// - Nytt 2026-03-09: sletteknapp i bildeviser for admin / Feltobjekter skriv
//
// Fix 2026-03-09:
// - Unngår "Cannot redeclare ..." mot public/index.php ved å bruke prefiksede lokale funksjonsnavn
// - Bildeviser lukkes ikke ved klikk utenfor eller ESC
// - Kartet kan flyttes mens bildeviser er åpen
// - Sletteknapp beholdt i bildeviser for brukere med rettigheter
// - Bootstrap CSS er fjernet her fordi den lastes fra wrapper/layout
// - Slettekall prøver flere API-actions og flere vanlige id-felter for bedre kompatibilitet

declare(strict_types=1);

function bildekart_esc($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bildekart_normalize_list($value): array
{
    if (is_array($value)) {
        return array_values(array_filter(
            array_map(static fn($v) => trim((string)$v), $value),
            static fn($v) => $v !== ''
        ));
    }

    if (is_string($value) && trim($value) !== '') {
        $parts = preg_split('/[,\r\n;|]+/', $value);
        if (!$parts) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn($v) => trim((string)$v), $parts),
            static fn($v) => $v !== ''
        ));
    }

    return [];
}

function bildekart_user_can_delete_images(): bool
{
    $adminFlags = [
        (bool)($_SESSION['is_admin'] ?? false),
        (bool)($_SESSION['admin'] ?? false),
        (bool)($_SESSION['isAdmin'] ?? false),
    ];

    foreach ($adminFlags as $flag) {
        if ($flag) {
            return true;
        }
    }

    $roleSources = [
        $_SESSION['roles'] ?? null,
        $_SESSION['user_roles'] ?? null,
        $_SESSION['permissions'] ?? null,
        $_SESSION['perms'] ?? null,
    ];

    $tokens = [];
    foreach ($roleSources as $src) {
        foreach (bildekart_normalize_list($src) as $item) {
            $tokens[] = mb_strtolower(trim($item), 'UTF-8');
        }
    }

    $tokenSet = array_fill_keys($tokens, true);

    $allowed = [
        'admin',
        'administrator',
        'feltobjekter skriv',
        'feltobjekter_skriv',
        'feltobjekter-write',
        'feltobjekter write',
        'nodelokasjon skriv',
        'nodelokasjon_skriv',
        'write_feltobjekter',
        'feltobjects write',
    ];

    foreach ($allowed as $key) {
        if (isset($tokenSet[$key])) {
            return true;
        }
    }

    return false;
}

/* ------------------ Auth ------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$authCandidate = __DIR__ . '/../../core/auth.php';
if (is_file($authCandidate)) {
    require_once $authCandidate;
    if (function_exists('require_login')) {
        require_login();
    }
}

if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['csrf_token'] = sha1(session_id() . '|' . microtime(true));
    }
}

function bildekart_current_display_name(): string
{
    $u = (string)($_SESSION['username'] ?? '');
    if ($u !== '') return $u;

    $n = (string)($_SESSION['name'] ?? '');
    if ($n !== '') return $n;

    $dn = (string)($_SESSION['display_name'] ?? '');
    if ($dn !== '') return $dn;

    return 'Innlogget';
}

/* ------------------ API endpoint ------------------ */
$API = '/api/bildekart_api.php';
$title = 'Bildekart';
$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
$canDeleteImages = bildekart_user_can_delete_images();
?><!doctype html>
<html lang="no">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= bildekart_esc($title) ?> – teknisk.hkbb.no</title>

  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">

  <style>
    html, body { height: 100%; margin: 0; }
    body { background: #f8fafc; }

    .leaflet-container img,
    .leaflet-container svg,
    .leaflet-container canvas {
      max-width: none !important;
      max-height: none !important;
    }

    #map {
      height: calc(100vh - 56px);
      background: #e5e7eb;
    }

    .viewer-wrap {
      position: relative;
      background: #0b1220;
      border-radius: 8px;
      overflow: hidden;
      min-height: 72vh;
    }

    .viewer-img {
      width: 100%;
      height: 72vh;
      object-fit: contain;
      background: #0b1220;
      display: block;
      border-radius: 8px;
    }

    .viewer-clickzone {
      position: absolute;
      inset: 0;
      display: grid;
      grid-template-columns: 1fr 1fr;
      z-index: 2;
      pointer-events: none;
    }

    .viewer-clickzone > div {
      cursor: pointer;
      pointer-events: auto;
    }

    .viewer-loading,
    .viewer-error {
      position: absolute;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 3;
      text-align: center;
      padding: 20px;
      color: #e2e8f0;
      background: rgba(11, 18, 32, .55);
    }

    .viewer-loading.show,
    .viewer-error.show {
      display: flex;
    }

    .viewer-error-box {
      max-width: 520px;
    }

    .small-mono {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 12px;
    }

    .top-search {
      width: min(520px, 52vw);
    }

    .top-search input,
    .mobile-search-wrap input,
    .map-node-wrap input {
      border-radius: 999px;
      padding-left: 14px;
      padding-right: 38px;
    }

    .top-search .clear-btn,
    .mobile-search-wrap .clear-btn,
    .map-node-wrap .clear-btn {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      border: 0;
      background: transparent;
      width: 26px;
      height: 26px;
      border-radius: 999px;
      line-height: 1;
      opacity: .75;
      z-index: 10;
    }

    .top-search .clear-btn:hover,
    .mobile-search-wrap .clear-btn:hover,
    .map-node-wrap .clear-btn:hover {
      opacity: 1;
      background: rgba(0,0,0,.06);
    }

    .suggest-box {
      position: absolute;
      left: 0;
      right: 0;
      top: calc(100% + 6px);
      z-index: 1200;
      background: #fff;
      border: 1px solid #dbe3ee;
      border-radius: 14px;
      box-shadow: 0 12px 36px rgba(0,0,0,.14);
      overflow: hidden;
      display: none;
    }

    .modal .suggest-box,
    #viewerMapSuggest {
      z-index: 2100;
    }

    .suggest-box.show { display: block; }

    .suggest-item {
      display: block;
      width: 100%;
      text-align: left;
      background: #fff;
      border: 0;
      border-bottom: 1px solid #eef2f7;
      padding: 10px 12px;
      cursor: pointer;
    }

    .suggest-item:last-child {
      border-bottom: 0;
    }

    .suggest-item:hover,
    .suggest-item.active {
      background: #f8fafc;
    }

    .suggest-item.disabled {
      cursor: default;
      color: #64748b;
      background: #fff;
    }

    .suggest-title {
      font-size: 13px;
      font-weight: 700;
      color: #0f172a;
      line-height: 1.2;
    }

    .suggest-sub {
      font-size: 12px;
      color: #64748b;
      margin-top: 2px;
      line-height: 1.2;
    }

    .marker-cluster { filter: drop-shadow(0 2px 6px rgba(0,0,0,.35)); }
    .marker-cluster div {
      border-radius: 999px !important;
      border: 3px solid rgba(255,255,255,.92);
      box-shadow: inset 0 0 0 2px rgba(0,0,0,.18);
      font-weight: 800;
    }

    .marker-cluster.cluster-mapped div {
      background: rgba(22, 163, 74, .92) !important;
      color: #fff !important;
      text-shadow: 0 1px 1px rgba(0,0,0,.28);
    }

    .marker-cluster.cluster-unassigned div {
      background: rgba(249, 115, 22, .92) !important;
      color: #fff !important;
      text-shadow: 0 1px 1px rgba(0,0,0,.28);
    }

    .marker-cluster-small div { font-size: 13px; }
    .marker-cluster-medium div { font-size: 14px; }
    .marker-cluster-large div { font-size: 15px; }

    .result-badge {
      font-variant-numeric: tabular-nums;
    }

    .photo-group-wrap {
      display: inline-flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
      transform: translateY(-6px);
    }

    .photo-group-icon {
      position: relative;
      width: 42px;
      height: 42px;
      border-radius: 999px;
      border: 2px solid #fff;
      box-shadow: 0 3px 12px rgba(0,0,0,.28);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      font-weight: 700;
      color: #fff;
      user-select: none;
    }

    .photo-group-icon.mapped {
      background: #16a34a;
    }

    .photo-group-icon.unassigned {
      background: #f97316;
    }

    .photo-group-glyph {
      font-size: 20px;
      line-height: 1;
      transform: translateY(-1px);
    }

    .photo-group-count {
      position: absolute;
      right: -6px;
      top: -6px;
      min-width: 22px;
      height: 22px;
      padding: 0 6px;
      border-radius: 999px;
      background: #0f172a;
      color: #fff;
      font-size: 11px;
      font-weight: 800;
      line-height: 22px;
      text-align: center;
      border: 2px solid #fff;
    }

    .photo-group-label {
      max-width: 180px;
      padding: 2px 8px;
      border-radius: 999px;
      background: rgba(255,255,255,.96);
      border: 1px solid rgba(15,23,42,.10);
      box-shadow: 0 2px 8px rgba(0,0,0,.14);
      color: #0f172a;
      font-size: 12px;
      line-height: 1.2;
      font-weight: 600;
      text-align: center;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .popup-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
      gap: 10px;
      min-width: min(72vw, 560px);
      max-width: min(72vw, 560px);
      max-height: 52vh;
      overflow: auto;
      padding-right: 2px;
    }

    .popup-thumb-btn {
      appearance: none;
      border: 1px solid #d1d5db;
      background: #fff;
      border-radius: 10px;
      padding: 6px;
      text-align: left;
      cursor: pointer;
      transition: transform .08s ease, box-shadow .08s ease, border-color .08s ease;
      width: 100%;
    }

    .popup-thumb-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(0,0,0,.12);
      border-color: #94a3b8;
    }

    .popup-thumb-btn img {
      width: 100%;
      aspect-ratio: 1 / 1;
      object-fit: cover;
      border-radius: 8px;
      display: block;
      background: #e5e7eb;
      pointer-events: none;
    }

    .popup-thumb-meta {
      margin-top: 6px;
      font-size: 11px;
      line-height: 1.25;
      color: #475569;
      pointer-events: none;
    }

    .popup-headline {
      font-size: 13px;
      font-weight: 700;
      color: #0f172a;
      margin-bottom: 8px;
    }

    .leaflet-popup-content {
      margin: 12px;
    }

    .leaflet-popup-content-wrapper {
      border-radius: 14px;
    }

    .popup-empty {
      min-width: 220px;
      color: #64748b;
      font-size: 13px;
    }

    .viewer-map-panel {
      border-top: 1px solid #e5e7eb;
      margin-top: 14px;
      padding-top: 14px;
    }

    .viewer-map-status {
      font-size: 12px;
      color: #475569;
      min-height: 18px;
    }

    .viewer-map-status.ok { color: #15803d; }
    .viewer-map-status.err { color: #b91c1c; }

    .viewer-current-node {
      font-size: 13px;
      color: #0f172a;
      margin-bottom: 8px;
    }

    .viewer-current-node .badge {
      vertical-align: middle;
    }

    #viewerModal {
      pointer-events: none;
      background: transparent !important;
    }

    #viewerModal .modal-dialog {
      pointer-events: auto;
      margin: 1rem auto;
    }

    #viewerModal .modal-content {
      box-shadow: 0 18px 50px rgba(0,0,0,.32);
      border: 1px solid rgba(15,23,42,.08);
    }

    .modal-backdrop.show {
      opacity: 0 !important;
      display: none !important;
    }

    body.modal-open {
      overflow: auto !important;
      padding-right: 0 !important;
    }
  </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom">
  <div class="container-fluid">
    <a class="navbar-brand" href="/">Teknisk</a>
    <span class="navbar-text ms-2"><?= bildekart_esc($title) ?></span>

    <div class="ms-3 position-relative top-search d-none d-md-block">
      <input id="searchInput" class="form-control form-control-sm" type="search"
             placeholder="Søk: nodenavn, by/poststed, partner, bruker, uten node…"
             autocomplete="off" />
      <button id="searchClear" class="clear-btn" type="button" title="Tøm">×</button>
      <div id="searchSuggest" class="suggest-box"></div>
    </div>

    <div class="ms-auto d-flex align-items-center gap-2">
      <span class="badge bg-secondary result-badge" id="resultBadge" title="Viste / total">0 / 0</span>
      <span class="text-muted small"><?= bildekart_esc(bildekart_current_display_name()) ?></span>
      <a class="btn btn-outline-secondary btn-sm" href="/?page=nodelokasjon">Til nodelokasjon</a>
    </div>
  </div>
</nav>

<div class="d-md-none border-bottom bg-light p-2">
  <div class="position-relative mobile-search-wrap">
    <input id="searchInputMobile" class="form-control form-control-sm" type="search"
           placeholder="Søk i bilder…"
           autocomplete="off" />
    <button id="searchClearMobile" class="clear-btn" type="button" title="Tøm">×</button>
    <div id="searchSuggestMobile" class="suggest-box"></div>
  </div>
</div>

<div id="map"></div>

<div class="modal fade" id="viewerModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="false" data-bs-keyboard="false">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div class="d-flex flex-column">
          <div class="fw-semibold" id="viewerTitle">Bilde</div>
          <div class="text-muted small" id="viewerSub"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Lukk"></button>
      </div>
      <div class="modal-body">
        <div class="viewer-wrap">
          <img id="viewerImg" class="viewer-img" alt="">
          <div id="viewerLoading" class="viewer-loading">
            <div>Laster bilde…</div>
          </div>
          <div id="viewerError" class="viewer-error">
            <div class="viewer-error-box">
              <div class="fw-semibold mb-2">Kunne ikke laste originalbildet.</div>
              <div class="small text-light-emphasis">Prøver fallback-visning når mulig. Du kan også bruke knappen «Åpne original».</div>
            </div>
          </div>
          <div class="viewer-clickzone" aria-hidden="true">
            <div id="viewerPrevZone" title="Forrige (←)"></div>
            <div id="viewerNextZone" title="Neste (→)"></div>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
          <div class="small-mono" id="viewerMeta"></div>
          <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary btn-sm" id="viewerOpenOrig" href="#" target="_blank" rel="noopener">Åpne original</a>
            <?php if ($canDeleteImages): ?>
              <button class="btn btn-outline-danger btn-sm" id="viewerDeleteBtn" type="button">Slett bilde</button>
            <?php endif; ?>
            <button class="btn btn-outline-primary btn-sm" id="viewerPrevBtn" type="button">← Forrige</button>
            <button class="btn btn-outline-primary btn-sm" id="viewerNextBtn" type="button">Neste →</button>
          </div>
        </div>

        <div class="viewer-map-panel">
          <div id="viewerCurrentNode" class="viewer-current-node"></div>

          <div class="position-relative map-node-wrap">
            <input id="viewerMapNodeInput" class="form-control form-control-sm" type="search"
                   placeholder="Søk nodelokasjon for å mappe dette bildet…"
                   autocomplete="off" />
            <button id="viewerMapNodeClear" class="clear-btn" type="button" title="Tøm">×</button>
            <div id="viewerMapSuggest" class="suggest-box"></div>
          </div>

          <div class="d-flex gap-2 flex-wrap mt-2">
            <button id="viewerMapBtn" class="btn btn-success btn-sm" type="button" disabled>Koble bildet til valgt nodelokasjon</button>
            <button id="viewerZoomNodeBtn" class="btn btn-outline-secondary btn-sm" type="button" disabled>Zoom til valgt nodelokasjon</button>
          </div>

          <div id="viewerMapStatus" class="viewer-map-status mt-2"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>

<script>
(() => {
  const API = <?= json_encode($API, JSON_UNESCAPED_SLASHES) ?>;
  const CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
  const CAN_DELETE_IMAGES = <?= $canDeleteImages ? 'true' : 'false' ?>;

  const map = L.map('map', { preferCanvas: true });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 20,
    attribution: '&copy; OpenStreetMap',
    updateWhenZooming: false,
    updateWhenIdle: true,
    keepBuffer: 4
  }).addTo(map);

  map.setView([59.41, 5.27], 10);

  const HIGH_ZOOM_GROUPS_FROM = 17;
  const highZoomLayer = L.layerGroup().addTo(map);

  function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
      '&':'&amp;',
      '<':'&lt;',
      '>':'&gt;',
      '"':'&quot;',
      "'":'&#039;'
    }[c]));
  }

  function normalizeStr(s) {
    return String(s ?? '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/\p{Diacritic}/gu, '')
      .trim();
  }

  function withNoCache(url) {
    const sep = String(url).includes('?') ? '&' : '?';
    return String(url) + sep + '_v=' + Date.now();
  }

  function isMappedToNode(d) {
    if ((d?.kind || '') === 'unassigned') return false;
    if (typeof d?.mapped === 'boolean') return d.mapped;
    if (d?.node_location_id && Number(d.node_location_id) > 0) return true;
    if ((d?.node_name || '').trim() !== '') return true;
    return true;
  }

  function clusterClassForChildren(markers) {
    for (const m of markers) {
      const d = m?.__data;
      if (d && !isMappedToNode(d)) return 'cluster-unassigned';
    }
    return 'cluster-mapped';
  }

  const cluster = L.markerClusterGroup({
    spiderfyOnMaxZoom: true,
    showCoverageOnHover: false,
    maxClusterRadius: 55,
    disableClusteringAtZoom: HIGH_ZOOM_GROUPS_FROM,
    iconCreateFunction: function (cl) {
      const count = cl.getChildCount();
      const children = cl.getAllChildMarkers();
      const statusClass = clusterClassForChildren(children);

      let sizeClass = 'marker-cluster-small';
      if (count >= 10 && count < 100) sizeClass = 'marker-cluster-medium';
      else if (count >= 100) sizeClass = 'marker-cluster-large';

      const className = `marker-cluster ${sizeClass} ${statusClass}`;
      return new L.DivIcon({
        html: '<div><span>' + count + '</span></div>',
        className: className,
        iconSize: new L.Point(40, 40)
      });
    }
  });

  cluster.on('clusterclick', (e) => {
    try {
      const z = map.getZoom();
      if (z >= 18) {
        e.layer.spiderfy();
        return;
      }
      const b = e.layer.getBounds();
      map.flyToBounds(b, { padding: [60, 60], maxZoom: 19, duration: 0.35 });
    } catch (err) {
      try { map.fitBounds(e.layer.getBounds(), { padding: [60, 60] }); } catch (_) {}
    }
  });

  const defaultIcon = L.icon({
    iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
    iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
    shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
    shadowSize: [41, 41]
  });

  function itemKey(d) {
    return d.kind + ':' + (d.kind === 'unassigned' ? d.u_id : d.att_id);
  }

  function thumbUrl(d, sizePx) {
    const base = d.thumb_base || '';
    const sep = base.includes('?') ? '&' : '?';
    return base ? (base + sep + 's=' + encodeURIComponent(String(sizePx))) : d.orig_url;
  }

  function bestOrigUrl(d) {
    if (d.full_url) return d.full_url;
    if (d.orig_url) return d.orig_url;
    return thumbUrl(d, 1600);
  }

  function formatTitle(d) {
    if (d.kind === 'unassigned') return 'Uten node';
    const nm = (d.node_name || 'Node');
    const city = d.city ? (' – ' + d.city) : '';
    return nm + city;
  }

  function formatSub(d) {
    const parts = [];
    if (d.partner) parts.push(d.partner);
    if (d.taken) parts.push(d.taken);
    if (d.created_by) parts.push(d.created_by);
    return parts.join(' • ');
  }

  function searchHaystack(d) {
    const parts = [];
    parts.push(d.kind === 'unassigned' ? 'uten node unassigned kamera camera' : 'node mappet hus house');
    parts.push(d.node_name);
    parts.push(d.city);
    parts.push(d.poststed);
    parts.push(d.postcode);
    parts.push(d.postnr);
    parts.push(d.partner);
    parts.push(d.created_by);
    parts.push(d.original_filename);
    parts.push(d.file_path);
    return normalizeStr(parts.filter(Boolean).join(' | '));
  }

  function nodeSearchHaystack(n) {
    const parts = [];
    parts.push(n.name);
    parts.push(n.city);
    parts.push(n.poststed);
    parts.push(n.postcode);
    parts.push(n.postnr);
    parts.push(n.partner);
    return normalizeStr(parts.filter(Boolean).join(' | '));
  }

  function photoGroupIcon(group) {
    const hasUnassigned = group.some(d => !isMappedToNode(d));
    const label = group.find(d => (d.node_name || '').trim() !== '')?.node_name || '';
    const statusClass = hasUnassigned ? 'unassigned' : 'mapped';
    const count = group.length;
    const glyph = hasUnassigned ? '📷' : '🏠';

    const html = `
      <div class="photo-group-wrap">
        <div class="photo-group-icon ${statusClass}" title="Åpne bilder">
          <span class="photo-group-glyph">${glyph}</span>
          <span class="photo-group-count">${count}</span>
        </div>
        ${label ? `<div class="photo-group-label">${escHtml(label)}</div>` : ''}
      </div>
    `;

    return L.divIcon({
      className: '',
      html,
      iconSize: [54, label ? 64 : 44],
      iconAnchor: [27, 22],
      popupAnchor: [0, -20]
    });
  }

  function popupHtmlForGroup(group) {
    if (!group.length) {
      return `<div class="popup-empty">Ingen bilder i denne gruppen.</div>`;
    }

    const title = (group.find(d => (d.node_name || '').trim() !== '')?.node_name || '')
      || (group.some(d => !isMappedToNode(d)) ? 'Bilder uten node' : 'Bilder');

    const first = group[0];
    const subtitleParts = [];
    if (first.city) subtitleParts.push(first.city);
    subtitleParts.push(`${group.length} bilde${group.length === 1 ? '' : 'r'}`);

    const groupKeys = group.map(itemKey).join('|');

    const thumbs = group.map((d, idx) => {
      const meta = [];
      if (d.taken) meta.push(escHtml(d.taken));
      if (d.created_by) meta.push(escHtml(d.created_by));

      return `
        <button
          type="button"
          class="popup-thumb-btn"
          data-view-key="${escHtml(itemKey(d))}"
          data-view-index="${idx}"
          data-view-list="${escHtml(groupKeys)}"
        >
          <img src="${escHtml(thumbUrl(d, 220))}" alt="" loading="lazy" decoding="async">
          <div class="popup-thumb-meta">${meta.join(' • ')}</div>
        </button>
      `;
    }).join('');

    return `
      <div class="popup-headline">${escHtml(title)} <span class="text-muted fw-normal">• ${escHtml(subtitleParts.join(' • '))}</span></div>
      <div class="popup-grid">${thumbs}</div>
    `;
  }

  function groupByPixels(items, zoom, radiusPx) {
    const used = new Set();
    const groups = [];
    const pts = items.map((d) => {
      const p = map.project([d.lat, d.lon], zoom);
      return { d, x: p.x, y: p.y };
    });

    for (let i = 0; i < pts.length; i++) {
      if (used.has(i)) continue;
      const g = [pts[i]];
      used.add(i);

      for (let j = i + 1; j < pts.length; j++) {
        if (used.has(j)) continue;
        const dx = pts[j].x - pts[i].x;
        const dy = pts[j].y - pts[i].y;
        if ((dx * dx + dy * dy) <= (radiusPx * radiusPx)) {
          g.push(pts[j]);
          used.add(j);
        }
      }
      groups.push(g.map(x => x.d));
    }

    return groups;
  }

  function groupMappedByNode(items) {
    const byNode = new Map();

    for (const d of items) {
      const nodeId = Number(d.node_location_id || d.node_id || 0);
      if (nodeId <= 0) continue;

      if (!byNode.has(nodeId)) {
        byNode.set(nodeId, []);
      }
      byNode.get(nodeId).push(d);
    }

    return Array.from(byNode.values());
  }

  function groupCenter(group) {
    let latSum = 0;
    let lonSum = 0;
    let count = 0;

    for (const d of group) {
      const lat = Number(d.lat);
      const lon = Number(d.lon);
      if (!Number.isFinite(lat) || !Number.isFinite(lon)) continue;
      latSum += lat;
      lonSum += lon;
      count++;
    }

    if (count <= 0) return null;
    return [latSum / count, lonSum / count];
  }

  function zoomToNode(node, preferredZoom = 18) {
    if (!node) return;
    const lat = Number(node.lat);
    const lon = Number(node.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
    map.flyTo([lat, lon], preferredZoom, { duration: 0.45 });
  }

  async function openStableMarkerPopup(marker) {
    if (!marker) return;

    try {
      const latlng = marker.getLatLng();
      const point = map.latLngToContainerPoint(latlng);
      const mapSize = map.getSize();

      const popupWidth = Math.min(620, Math.max(340, Math.floor(mapSize.x * 0.62)));
      const popupHeight = Math.min(Math.max(240, Math.floor(mapSize.y * 0.52)), 420);

      const leftPad = 24;
      const rightPad = 24;
      const bottomPad = 24;
      const topPad = 24;

      const popupLeft = point.x - (popupWidth / 2);
      const popupRight = point.x + (popupWidth / 2);
      const popupTop = point.y - popupHeight - 42;
      const popupBottom = point.y - 8;

      let dx = 0;
      let dy = 0;

      if (popupLeft < leftPad) {
        dx = popupLeft - leftPad;
      } else if (popupRight > (mapSize.x - rightPad)) {
        dx = popupRight - (mapSize.x - rightPad);
      }

      if (popupTop < topPad) {
        dy = popupTop - topPad;
      } else if (popupBottom > (mapSize.y - bottomPad)) {
        dy = popupBottom - (mapSize.y - bottomPad);
      }

      if (dx !== 0 || dy !== 0) {
        map.panBy([dx, dy], {
          animate: true,
          duration: 0.22,
          easeLinearity: 0.25
        });

        await new Promise(resolve => setTimeout(resolve, 240));
      }

      marker.openPopup();
    } catch (_) {
      try { marker.openPopup(); } catch (__){}
    }
  }

  const markers = [];
  const allDataByKey = new Map();
  const visibleMarkerSet = new Set();
  const localNodeIndex = new Map();

  const resultBadge = document.getElementById('resultBadge');

  function setBadge(visible, total) {
    resultBadge.textContent = `${visible} / ${total}`;
  }

  const viewerModalEl = document.getElementById('viewerModal');
  const viewerModal = new bootstrap.Modal(viewerModalEl, {
    keyboard: false,
    backdrop: false,
    focus: false
  });

  const viewerImg = document.getElementById('viewerImg');
  const viewerTitle = document.getElementById('viewerTitle');
  const viewerSub = document.getElementById('viewerSub');
  const viewerMeta = document.getElementById('viewerMeta');
  const viewerOpenOrig = document.getElementById('viewerOpenOrig');
  const viewerPrevBtn = document.getElementById('viewerPrevBtn');
  const viewerNextBtn = document.getElementById('viewerNextBtn');
  const viewerPrevZone = document.getElementById('viewerPrevZone');
  const viewerNextZone = document.getElementById('viewerNextZone');
  const viewerLoading = document.getElementById('viewerLoading');
  const viewerError = document.getElementById('viewerError');
  const viewerMapNodeInput = document.getElementById('viewerMapNodeInput');
  const viewerMapNodeClear = document.getElementById('viewerMapNodeClear');
  const viewerMapSuggest = document.getElementById('viewerMapSuggest');
  const viewerMapBtn = document.getElementById('viewerMapBtn');
  const viewerZoomNodeBtn = document.getElementById('viewerZoomNodeBtn');
  const viewerMapStatus = document.getElementById('viewerMapStatus');
  const viewerCurrentNode = document.getElementById('viewerCurrentNode');
  const viewerDeleteBtn = document.getElementById('viewerDeleteBtn');

  let currentList = [];
  let currentIndex = -1;
  let viewerLoadToken = 0;
  let selectedMapNode = null;
  let deleteInFlight = false;

  function setViewerLoading(state) {
    viewerLoading.classList.toggle('show', !!state);
  }

  function setViewerError(state) {
    viewerError.classList.toggle('show', !!state);
  }

  function setMapStatus(msg = '', kind = '') {
    viewerMapStatus.textContent = msg;
    viewerMapStatus.classList.remove('ok', 'err');
    if (kind === 'ok') viewerMapStatus.classList.add('ok');
    if (kind === 'err') viewerMapStatus.classList.add('err');
  }

  function getCurrentViewerItem() {
    if (currentIndex < 0 || currentIndex >= currentList.length) return null;
    const key = currentList[currentIndex];
    return allDataByKey.get(key) || null;
  }

  function updateViewerMapUI() {
    const d = getCurrentViewerItem();
    selectedMapNode = null;
    viewerMapNodeInput.value = '';
    viewerMapSuggest.classList.remove('show');
    viewerMapSuggest.innerHTML = '';
    viewerMapBtn.disabled = true;
    viewerZoomNodeBtn.disabled = true;

    if (viewerDeleteBtn) {
      viewerDeleteBtn.disabled = !d || deleteInFlight;
    }

    if (!d) {
      viewerCurrentNode.textContent = '';
      setMapStatus('');
      return;
    }

    if (isMappedToNode(d) && Number(d.node_location_id || 0) > 0) {
      viewerCurrentNode.innerHTML = `Koblet til: <span class="badge text-bg-success">Node</span> <strong>${escHtml(d.node_name || ('#' + d.node_location_id))}</strong>`;
    } else {
      viewerCurrentNode.innerHTML = `Koblet til: <span class="badge text-bg-warning">Ingen node</span>`;
    }

    setMapStatus('');
  }

  function loadViewerImage(d) {
    const token = ++viewerLoadToken;
    const orig = withNoCache(bestOrigUrl(d));
    const fallback = withNoCache(thumbUrl(d, 1600));

    setViewerLoading(true);
    setViewerError(false);
    viewerImg.removeAttribute('src');

    const test = new Image();

    test.onload = () => {
      if (token !== viewerLoadToken) return;
      viewerImg.src = orig;
      setViewerLoading(false);
      setViewerError(false);
    };

    test.onerror = () => {
      if (token !== viewerLoadToken) return;

      const fb = new Image();
      fb.onload = () => {
        if (token !== viewerLoadToken) return;
        viewerImg.src = fallback;
        setViewerLoading(false);
        setViewerError(true);
      };
      fb.onerror = () => {
        if (token !== viewerLoadToken) return;
        viewerImg.removeAttribute('src');
        setViewerLoading(false);
        setViewerError(true);
      };
      fb.src = fallback;
    };

    test.src = orig;
  }

  function showViewerAtIndex(idx) {
    if (idx < 0 || idx >= currentList.length) return;
    currentIndex = idx;

    const key = currentList[currentIndex];
    const d = allDataByKey.get(key) || null;
    if (!d) return;

    viewerTitle.textContent = formatTitle(d);
    viewerSub.textContent = formatSub(d);
    viewerMeta.textContent = `${d.kind} • ${key} • lat=${Number(d.lat).toFixed(6)} lon=${Number(d.lon).toFixed(6)}`;

    viewerOpenOrig.href = bestOrigUrl(d);

    viewerPrevBtn.disabled = (currentIndex <= 0);
    viewerNextBtn.disabled = (currentIndex >= currentList.length - 1);

    loadViewerImage(d);
    updateViewerMapUI();
  }

  function openViewerList(listKeys, startIndex) {
    currentList = Array.isArray(listKeys) ? listKeys.slice() : [];
    currentIndex = Math.max(0, Math.min(Number(startIndex || 0), currentList.length - 1));
    if (!currentList.length) return;

    viewerModal.show();
    document.body.classList.remove('modal-open');
    window.setTimeout(() => showViewerAtIndex(currentIndex), 30);
  }

  function openViewerByKey(key) {
    const d = allDataByKey.get(key);
    if (!d) return;
    openViewerList([key], 0);
  }

  function prevImage() { showViewerAtIndex(currentIndex - 1); }
  function nextImage() { showViewerAtIndex(currentIndex + 1); }

  viewerPrevBtn.addEventListener('click', prevImage);
  viewerNextBtn.addEventListener('click', nextImage);
  viewerPrevZone.addEventListener('click', prevImage);
  viewerNextZone.addEventListener('click', nextImage);

  document.addEventListener('keydown', (e) => {
    if (!viewerModalEl.classList.contains('show')) return;
    if (e.key === 'ArrowLeft') { e.preventDefault(); prevImage(); }
    if (e.key === 'ArrowRight') { e.preventDefault(); nextImage(); }
  });

  viewerModalEl.addEventListener('hidden.bs.modal', () => {
    viewerLoadToken++;
    viewerImg.removeAttribute('src');
    setViewerLoading(false);
    setViewerError(false);
    selectedMapNode = null;
    deleteInFlight = false;
    viewerMapSuggest.classList.remove('show');
    viewerMapSuggest.innerHTML = '';
    viewerMapNodeInput.value = '';
    viewerMapBtn.disabled = true;
    viewerZoomNodeBtn.disabled = true;
    if (viewerDeleteBtn) viewerDeleteBtn.disabled = false;
    setMapStatus('');
  });

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.popup-thumb-btn');
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    const key = btn.getAttribute('data-view-key') || '';
    const index = Number(btn.getAttribute('data-view-index') || '0');
    const rawList = btn.getAttribute('data-view-list') || '';
    const list = rawList ? rawList.split('|').filter(Boolean) : (key ? [key] : []);

    if (!list.length && key) {
      openViewerList([key], 0);
      return;
    }

    openViewerList(list, Number.isFinite(index) ? index : 0);
  }, true);

  function buildVisibleData() {
    const out = [];
    for (const { marker, data } of markers) {
      if (!visibleMarkerSet.has(marker)) continue;
      out.push(data);
    }
    return out;
  }

  function rebuildLocalNodeIndex(items) {
    localNodeIndex.clear();

    for (const d of items) {
      const nodeId = Number(d.node_location_id || d.node_id || 0);
      const nodeName = String(d.node_name || '').trim();
      if (nodeId <= 0 || nodeName === '') continue;

      const key = String(nodeId);
      if (localNodeIndex.has(key)) continue;

      const item = {
        id: nodeId,
        name: nodeName,
        city: String(d.city || '').trim(),
        poststed: String(d.poststed || '').trim(),
        postcode: String(d.postcode || d.postnr || '').trim(),
        postnr: String(d.postnr || d.postcode || '').trim(),
        partner: String(d.partner || '').trim(),
        lat: Number(d.lat),
        lon: Number(d.lon)
      };
      item.__hay = nodeSearchHaystack(item);
      localNodeIndex.set(key, item);
    }
  }

  function getLocalNodeSuggestions(query, limit = 12) {
    const q = normalizeStr(query);
    if (q.length < 2) return [];

    const out = [];
    for (const item of localNodeIndex.values()) {
      if ((item.__hay || '').includes(q)) {
        out.push(item);
      }
    }

    out.sort((a, b) => {
      const an = normalizeStr(a.name || '');
      const bn = normalizeStr(b.name || '');
      const aStarts = an.startsWith(q) ? 0 : 1;
      const bStarts = bn.startsWith(q) ? 0 : 1;
      if (aStarts !== bStarts) return aStarts - bStarts;
      return an.localeCompare(bn, 'no');
    });

    return out.slice(0, limit);
  }

  function dedupeNodeSuggestions(items) {
    const seen = new Set();
    const out = [];

    for (const raw of Array.isArray(items) ? items : []) {
      const item = {
        id: Number(raw?.id || raw?.node_location_id || raw?.node_id || 0),
        name: String(raw?.name || raw?.node_name || '').trim(),
        city: String(raw?.city || raw?.poststed || '').trim(),
        poststed: String(raw?.poststed || raw?.city || '').trim(),
        postcode: String(raw?.postcode || raw?.postnr || '').trim(),
        postnr: String(raw?.postnr || raw?.postcode || '').trim(),
        partner: String(raw?.partner || '').trim(),
        lat: Number(raw?.lat),
        lon: Number(raw?.lon)
      };

      if (!item.name) continue;

      const key = item.id > 0 ? ('id:' + item.id) : ('name:' + normalizeStr(item.name));
      if (seen.has(key)) continue;
      seen.add(key);

      out.push(item);
    }

    return out;
  }

  async function fetchJsonFromUrl(url) {
    const res = await fetch(url, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' }
    });

    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch (_) {
      return null;
    }
  }

  async function fetchNodeSuggestions(query) {
    const q = String(query || '').trim();
    if (q.length < 2) return [];

    const candidates = [
      `${API}?action=suggest_nodes&q=${encodeURIComponent(q)}&_=${Date.now()}`,
      `${API}?ajax=suggest_nodes&q=${encodeURIComponent(q)}&_=${Date.now()}`,
      `${API}?action=node_suggest&q=${encodeURIComponent(q)}&_=${Date.now()}`,
      `${API}?action=search_nodes&q=${encodeURIComponent(q)}&_=${Date.now()}`
    ];

    for (const url of candidates) {
      try {
        const json = await fetchJsonFromUrl(url);
        if (!json || !json.ok || !Array.isArray(json.items)) continue;

        const deduped = dedupeNodeSuggestions(json.items);
        if (deduped.length) return deduped;
      } catch (_) {}
    }

    return [];
  }

  async function fetchNodeSuggestionsWithFallback(query) {
    const remote = await fetchNodeSuggestions(query);
    const local = getLocalNodeSuggestions(query, 12);

    if (!remote.length) {
      return local;
    }

    return dedupeNodeSuggestions(remote.concat(local)).slice(0, 12);
  }

  async function postAndParseFlexible(url, payload, asJson = false) {
    const options = {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json, text/plain, */*'
      },
      body: payload
    };

    if (asJson) {
      options.headers['Content-Type'] = 'application/json';
    }

    const res = await fetch(url, options);
    const text = await res.text();

    let json = null;
    try {
      json = JSON.parse(text);
    } catch (_) {
      json = null;
    }

    if (json) {
      return {
        ok: !!json.ok || json.success === true || json.status === 'ok',
        raw: json,
        text
      };
    }

    const low = String(text || '').toLowerCase();
    const looksSuccessful =
      res.ok && (
        low.includes('"ok":true') ||
        low.includes('"success":true') ||
        low === 'ok' ||
        low.includes('deleted') ||
        low.includes('slettet')
      );

    return {
      ok: looksSuccessful,
      raw: null,
      text
    };
  }

  function buildDeleteFieldSets(d) {
    const kind = String(d.kind || '');
    const id = String(kind === 'unassigned' ? (d.u_id || 0) : (d.att_id || 0));
    const nodeAttachmentId = String(d.att_id || 0);
    const unassignedId = String(d.u_id || 0);
    const nodeLocationId = String(d.node_location_id || 0);

    return [
      {
        csrf_token: CSRF,
        kind,
        id
      },
      {
        csrf: CSRF,
        kind,
        id
      },
      {
        csrf_token: CSRF,
        image_kind: kind,
        image_id: id
      },
      {
        csrf_token: CSRF,
        source_kind: kind,
        image_id: id
      },
      {
        csrf_token: CSRF,
        kind,
        id,
        att_id: nodeAttachmentId,
        u_id: unassignedId
      },
      {
        csrf_token: CSRF,
        kind,
        id,
        attachment_id: nodeAttachmentId,
        unassigned_id: unassignedId
      },
      {
        csrf_token: CSRF,
        kind,
        node_location_attachment_id: nodeAttachmentId,
        node_location_unassigned_attachment_id: unassignedId
      },
      {
        csrf_token: CSRF,
        kind,
        image_id: id,
        node_location_id: nodeLocationId
      }
    ];
  }

  async function tryDeleteViaVariants(d) {
    const queryVariants = [
      'action=delete_image',
      'action=delete_attachment',
      'action=delete',
      'action=remove_image',
      'action=remove_attachment',
      'ajax=delete_image',
      'ajax=delete_attachment',
      'ajax=delete'
    ];

    const fieldSets = buildDeleteFieldSets(d);
    let lastError = 'delete_failed';

    for (const query of queryVariants) {
      const url = `${API}?${query}&_=${Date.now()}`;

      for (const fields of fieldSets) {
        try {
          const form = new FormData();
          Object.entries(fields).forEach(([k, v]) => form.append(k, String(v ?? '')));
          const result = await postAndParseFlexible(url, form, false);

          if (result.ok) {
            return { ok: true, detail: result };
          }

          if (result.raw && (result.raw.message || result.raw.error)) {
            lastError = result.raw.message || result.raw.error;
          } else if (result.text) {
            lastError = result.text.slice(0, 300);
          }
        } catch (err) {
          lastError = err?.message || String(err);
        }

        try {
          const params = new URLSearchParams();
          Object.entries(fields).forEach(([k, v]) => params.append(k, String(v ?? '')));
          const result = await postAndParseFlexible(url, params, false);

          if (result.ok) {
            return { ok: true, detail: result };
          }

          if (result.raw && (result.raw.message || result.raw.error)) {
            lastError = result.raw.message || result.raw.error;
          } else if (result.text) {
            lastError = result.text.slice(0, 300);
          }
        } catch (err) {
          lastError = err?.message || String(err);
        }

        try {
          const result = await postAndParseFlexible(url, JSON.stringify(fields), true);

          if (result.ok) {
            return { ok: true, detail: result };
          }

          if (result.raw && (result.raw.message || result.raw.error)) {
            lastError = result.raw.message || result.raw.error;
          } else if (result.text) {
            lastError = result.text.slice(0, 300);
          }
        } catch (err) {
          lastError = err?.message || String(err);
        }
      }
    }

    return { ok: false, error: lastError || 'delete_failed' };
  }

  async function deleteCurrentImage() {
    if (!CAN_DELETE_IMAGES || deleteInFlight) return;

    const d = getCurrentViewerItem();
    if (!d) return;

    const kindLabel = d.kind === 'unassigned' ? 'dette umappede bildet' : 'dette bildet';
    const ok = window.confirm(`Vil du slette ${kindLabel}? Dette kan ikke angres.`);
    if (!ok) return;

    deleteInFlight = true;
    if (viewerDeleteBtn) viewerDeleteBtn.disabled = true;
    setMapStatus('Sletter bildet…');

    const keyToRemove = itemKey(d);

    try {
      const deleteResult = await tryDeleteViaVariants(d);

      if (!deleteResult.ok) {
        throw new Error(deleteResult.error || 'delete_failed');
      }

      setMapStatus('Bildet ble slettet.', 'ok');

      currentList = currentList.filter(k => k !== keyToRemove);
      allDataByKey.delete(keyToRemove);

      if (currentList.length === 0) {
        viewerModal.hide();
        await reloadData();
        return;
      }

      if (currentIndex >= currentList.length) {
        currentIndex = currentList.length - 1;
      }

      await reloadData();

      const nextKey = currentList[currentIndex] || currentList[Math.max(0, currentIndex - 1)] || '';
      if (nextKey) {
        openViewerByKey(nextKey);
      } else {
        viewerModal.hide();
      }
    } catch (err) {
      console.error(err);
      setMapStatus('Klarte ikke å slette bildet: ' + (err?.message || err), 'err');
      if (viewerDeleteBtn) viewerDeleteBtn.disabled = false;
    } finally {
      deleteInFlight = false;
      updateViewerMapUI();
    }
  }

  if (viewerDeleteBtn) {
    viewerDeleteBtn.addEventListener('click', deleteCurrentImage);
  }

  function renderHighZoomGroups() {
    highZoomLayer.clearLayers();

    if (map.getZoom() < HIGH_ZOOM_GROUPS_FROM) {
      if (!map.hasLayer(cluster)) map.addLayer(cluster);
      return;
    }

    if (map.hasLayer(cluster)) {
      map.removeLayer(cluster);
    }

    const bounds = map.getBounds().pad(0.15);
    const visible = buildVisibleData().filter(d => bounds.contains([d.lat, d.lon]));
    if (!visible.length) return;

    const mapped = visible.filter(d => isMappedToNode(d));
    const unassigned = visible.filter(d => !isMappedToNode(d));

    const mappedGroups = groupMappedByNode(mapped);

    for (const group of mappedGroups) {
      const center = groupCenter(group);
      if (!center) continue;

      const keys = group.map(itemKey);

      const m = L.marker(center, {
        icon: photoGroupIcon(group),
        keyboard: true
      });

      m.bindPopup(popupHtmlForGroup(group), {
        maxWidth: 620,
        closeButton: true,
        autoPan: false,
        keepInView: false,
        autoClose: true,
        closeOnClick: false
      });

      m.on('click', async () => {
        if (keys.length === 1) {
          openViewerByKey(keys[0]);
        } else {
          await openStableMarkerPopup(m);
        }
      });

      m.on('popupopen', (ev) => {
        const popupRoot = ev.popup.getElement();
        if (popupRoot) {
          L.DomEvent.disableClickPropagation(popupRoot);
          L.DomEvent.disableScrollPropagation(popupRoot);
        }
      });

      highZoomLayer.addLayer(m);
    }

    if (unassigned.length) {
      const z = map.getZoom();
      const radiusPx = z >= 19 ? 44 : (z >= 18 ? 38 : 32);
      const unassignedGroups = groupByPixels(unassigned, z, radiusPx);

      for (const group of unassignedGroups) {
        const center = groupCenter(group);
        if (!center) continue;

        const keys = group.map(itemKey);

        const m = L.marker(center, {
          icon: photoGroupIcon(group),
          keyboard: true
        });

        m.bindPopup(popupHtmlForGroup(group), {
          maxWidth: 620,
          closeButton: true,
          autoPan: false,
          keepInView: false,
          autoClose: true,
          closeOnClick: false
        });

        m.on('click', async () => {
          if (keys.length === 1) {
            openViewerByKey(keys[0]);
          } else {
            await openStableMarkerPopup(m);
          }
        });

        m.on('popupopen', (ev) => {
          const popupRoot = ev.popup.getElement();
          if (popupRoot) {
            L.DomEvent.disableClickPropagation(popupRoot);
            L.DomEvent.disableScrollPropagation(popupRoot);
          }
        });

        highZoomLayer.addLayer(m);
      }
    }
  }

  let refreshTimer = null;
  function scheduleRefresh() {
    if (refreshTimer) clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
      refreshTimer = null;
      renderHighZoomGroups();
    }, 80);
  }

  map.on('zoomend', scheduleRefresh);
  map.on('moveend', scheduleRefresh);

  let lastQuery = '';
  function applyFilter(query) {
    const q = normalizeStr(query);
    if (q === lastQuery) return;
    lastQuery = q;

    cluster.clearLayers();
    highZoomLayer.clearLayers();
    visibleMarkerSet.clear();

    if (!q) {
      for (const { marker } of markers) {
        cluster.addLayer(marker);
        visibleMarkerSet.add(marker);
      }
      setBadge(markers.length, markers.length);
      if (map.getZoom() < HIGH_ZOOM_GROUPS_FROM) {
        if (!map.hasLayer(cluster)) map.addLayer(cluster);
      }
      scheduleRefresh();
      return;
    }

    let visibleCount = 0;
    for (const { marker, data } of markers) {
      const hay = data.__hay || '';
      if (hay.includes(q)) {
        cluster.addLayer(marker);
        visibleMarkerSet.add(marker);
        visibleCount++;
      }
    }

    setBadge(visibleCount, markers.length);

    if (map.getZoom() < HIGH_ZOOM_GROUPS_FROM) {
      if (!map.hasLayer(cluster)) map.addLayer(cluster);
    }
    scheduleRefresh();
  }

  function renderSuggestList(boxEl, items, activeIndex = -1, emptyText = 'Ingen treff') {
    if (!boxEl) return;

    if (!items.length) {
      boxEl.innerHTML = `
        <div class="suggest-item disabled" aria-disabled="true">
          <div class="suggest-title">${escHtml(emptyText)}</div>
          <div class="suggest-sub">Skriv minst 2 tegn eller prøv et annet søk.</div>
        </div>
      `;
      boxEl.classList.add('show');
      return;
    }

    boxEl.innerHTML = items.map((item, idx) => {
      const subParts = [];
      if (item.city) subParts.push(item.city);
      else if (item.poststed) subParts.push(item.poststed);
      if (item.partner) subParts.push(item.partner);
      if (Number.isFinite(Number(item.lat)) && Number.isFinite(Number(item.lon))) {
        subParts.push('kart');
      }

      return `
        <button type="button" class="suggest-item${idx === activeIndex ? ' active' : ''}" data-suggest-idx="${idx}">
          <div class="suggest-title">${escHtml(item.name || '')}</div>
          <div class="suggest-sub">${escHtml(subParts.join(' • '))}</div>
        </button>
      `;
    }).join('');
    boxEl.classList.add('show');
  }

  function createAutosuggestController(opts) {
    const inputEl = opts.inputEl;
    const boxEl = opts.boxEl;
    const clearEl = opts.clearEl || null;
    const onChosen = opts.onChosen;
    const onInputChange = opts.onInputChange || (() => {});
    const autoZoomSingle = !!opts.autoZoomSingle;
    const fetchSuggestions = opts.fetchSuggestions || fetchNodeSuggestionsWithFallback;
    const emptyText = opts.emptyText || 'Ingen treff';

    let items = [];
    let activeIndex = -1;
    let timer = null;
    let reqId = 0;
    let lastAutoZoomKey = '';

    function closeBox() {
      activeIndex = -1;
      items = [];
      boxEl.innerHTML = '';
      boxEl.classList.remove('show');
    }

    function choose(item) {
      if (!item) return;
      onChosen(item, inputEl);
      closeBox();
    }

    async function load() {
      const q = String(inputEl.value || '').trim();
      onInputChange(q);

      if (q.length < 2) {
        closeBox();
        return;
      }

      const myId = ++reqId;
      let result = [];
      try {
        result = await fetchSuggestions(q);
      } catch (_) {
        result = [];
      }

      if (myId !== reqId) return;

      items = Array.isArray(result) ? result : [];
      activeIndex = items.length ? 0 : -1;
      renderSuggestList(boxEl, items, activeIndex, emptyText);

      if (autoZoomSingle && items.length === 1) {
        const it = items[0];
        const key = `${it.id}|${normalizeStr(q)}`;
        if (lastAutoZoomKey !== key) {
          lastAutoZoomKey = key;
          onChosen(it, inputEl, true);
        }
      }
    }

    inputEl.addEventListener('input', () => {
      if (timer) clearTimeout(timer);
      timer = setTimeout(load, 140);
    });

    inputEl.addEventListener('focus', () => {
      const q = String(inputEl.value || '').trim();
      if (q.length >= 2) {
        if (timer) clearTimeout(timer);
        timer = setTimeout(load, 30);
      }
    });

    inputEl.addEventListener('keydown', (e) => {
      if (!items.length) {
        if (e.key === 'Escape') closeBox();
        return;
      }

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex = Math.min(activeIndex + 1, items.length - 1);
        renderSuggestList(boxEl, items, activeIndex, emptyText);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        renderSuggestList(boxEl, items, activeIndex, emptyText);
      } else if (e.key === 'Enter') {
        if (activeIndex >= 0 && items[activeIndex]) {
          e.preventDefault();
          choose(items[activeIndex]);
        }
      } else if (e.key === 'Escape') {
        closeBox();
      }
    });

    boxEl.addEventListener('click', (e) => {
      const btn = e.target.closest('.suggest-item');
      if (!btn || btn.classList.contains('disabled')) return;
      const idx = Number(btn.getAttribute('data-suggest-idx') || '-1');
      if (!Number.isFinite(idx) || idx < 0 || idx >= items.length) return;
      choose(items[idx]);
    });

    clearEl?.addEventListener('click', () => {
      inputEl.value = '';
      closeBox();
      onInputChange('');
      inputEl.focus();
    });

    document.addEventListener('click', (e) => {
      if (e.target === inputEl) return;
      if (boxEl.contains(e.target)) return;
      if (clearEl && e.target === clearEl) return;
      closeBox();
    });

    return {
      close: closeBox,
      async refreshNow() {
        await load();
      }
    };
  }

  const searchInput = document.getElementById('searchInput');
  const searchInputMobile = document.getElementById('searchInputMobile');
  const searchSuggest = document.getElementById('searchSuggest');
  const searchSuggestMobile = document.getElementById('searchSuggestMobile');
  const searchClear = document.getElementById('searchClear');
  const searchClearMobile = document.getElementById('searchClearMobile');

  createAutosuggestController({
    inputEl: searchInput,
    boxEl: searchSuggest,
    clearEl: searchClear,
    autoZoomSingle: true,
    fetchSuggestions: fetchNodeSuggestionsWithFallback,
    onInputChange: (q) => {
      if (searchInputMobile) searchInputMobile.value = q;
      applyFilter(q);
    },
    onChosen: (item, inputEl, fromAuto = false) => {
      inputEl.value = item.name || '';
      if (searchInputMobile) searchInputMobile.value = inputEl.value;
      applyFilter(inputEl.value);
      zoomToNode(item, fromAuto ? 17 : 18);
    }
  });

  createAutosuggestController({
    inputEl: searchInputMobile,
    boxEl: searchSuggestMobile,
    clearEl: searchClearMobile,
    autoZoomSingle: true,
    fetchSuggestions: fetchNodeSuggestionsWithFallback,
    onInputChange: (q) => {
      if (searchInput) searchInput.value = q;
      applyFilter(q);
    },
    onChosen: (item, inputEl, fromAuto = false) => {
      inputEl.value = item.name || '';
      if (searchInput) searchInput.value = inputEl.value;
      applyFilter(inputEl.value);
      zoomToNode(item, fromAuto ? 17 : 18);
    }
  });

  createAutosuggestController({
    inputEl: viewerMapNodeInput,
    boxEl: viewerMapSuggest,
    clearEl: viewerMapNodeClear,
    autoZoomSingle: false,
    fetchSuggestions: fetchNodeSuggestionsWithFallback,
    emptyText: 'Fant ingen nodelokasjoner',
    onInputChange: (q) => {
      selectedMapNode = null;
      viewerMapBtn.disabled = true;
      viewerZoomNodeBtn.disabled = true;
      if (q.length >= 2) {
        setMapStatus('Søker etter nodelokasjoner…');
      } else {
        setMapStatus('');
      }
    },
    onChosen: (item, inputEl) => {
      selectedMapNode = item;
      inputEl.value = item.name || '';
      viewerMapBtn.disabled = false;
      viewerZoomNodeBtn.disabled = false;
      setMapStatus(`Valgt nodelokasjon: ${item.name}${item.city ? ' – ' + item.city : (item.poststed ? ' – ' + item.poststed : '')}`);
      zoomToNode(item, 18);
    }
  });

  viewerZoomNodeBtn.addEventListener('click', () => {
    if (!selectedMapNode) return;
    zoomToNode(selectedMapNode, 18);
  });

  viewerMapBtn.addEventListener('click', async () => {
    const d = getCurrentViewerItem();
    if (!d || !selectedMapNode) return;

    viewerMapBtn.disabled = true;
    setMapStatus('Mapper bildet til valgt nodelokasjon…');

    const form = new FormData();
    form.append('csrf_token', CSRF);
    form.append('kind', d.kind || '');
    form.append('id', String(d.kind === 'unassigned' ? (d.u_id || 0) : (d.att_id || 0)));
    form.append('node_location_id', String(selectedMapNode.id || 0));

    try {
      const res = await fetch(API + '?action=map_to_node&_=' + Date.now(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        body: form
      });

      const json = await res.json();
      if (!json || !json.ok) {
        throw new Error(json?.message || json?.error || 'mapping_failed');
      }

      setMapStatus('Bildet ble koblet til nodelokasjonen.', 'ok');
      const newKey = json.item_key || '';

      await reloadData();

      if (newKey) {
        openViewerByKey(newKey);
      } else {
        const item = getCurrentViewerItem();
        if (item) openViewerByKey(itemKey(item));
      }
    } catch (err) {
      console.error(err);
      setMapStatus('Klarte ikke å mappe bildet: ' + (err?.message || err), 'err');
      viewerMapBtn.disabled = false;
    }
  });

  let reloadDataPromise = null;

  async function reloadData() {
    if (reloadDataPromise) return reloadDataPromise;

    reloadDataPromise = (async () => {
      cluster.clearLayers();
      highZoomLayer.clearLayers();

      markers.length = 0;
      allDataByKey.clear();
      visibleMarkerSet.clear();
      localNodeIndex.clear();
      setBadge(0, 0);

      const url = API + '?ajax=data&_=' + Date.now();
      const res = await fetch(url, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' }
      });

      const txt = await res.text();
      let json = null;
      try {
        json = JSON.parse(txt);
      } catch (e) {
        throw new Error('data_parse_failed\n' + txt.slice(0, 1200));
      }

      if (!json.ok) {
        throw new Error(json.error || json.message || 'data_failed');
      }

      const items = json.items || [];
      const boundsArr = [];

      rebuildLocalNodeIndex(items);

      for (const d of items) {
        const key = itemKey(d);
        d.__hay = searchHaystack(d);
        allDataByKey.set(key, d);

        const m = L.marker([d.lat, d.lon], { icon: defaultIcon, keyboard: true });
        m.__data = d;
        m.on('click', () => openViewerByKey(key));

        markers.push({ marker: m, data: d });
        cluster.addLayer(m);
        visibleMarkerSet.add(m);

        boundsArr.push([d.lat, d.lon]);
      }

      if (map.getZoom() < HIGH_ZOOM_GROUPS_FROM) {
        if (!map.hasLayer(cluster)) map.addLayer(cluster);
      }

      setBadge(markers.length, markers.length);

      if (boundsArr.length > 0) {
        map.fitBounds(boundsArr, { padding: [30, 30] });
      }

      setTimeout(() => {
        map.invalidateSize(true);
        scheduleRefresh();
      }, 60);

      const current = (searchInput?.value || searchInputMobile?.value || '').trim();
      if (current) {
        applyFilter(current);
      } else {
        scheduleRefresh();
      }
    })();

    try {
      await reloadDataPromise;
    } finally {
      reloadDataPromise = null;
    }
  }

  reloadData().catch(err => {
    console.error(err);
    alert('Kunne ikke laste bildedata: ' + (err?.message || err));
  });
})();
</script>

</body>
</html>