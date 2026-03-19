<?php
// Path: /public/dashboard/map/index.php
//
// Public TV Dashboard: Kart (uten innlogging)
// - Viser polygoner rundt leveransepunkt (event_affected_addresses) for aktive/planlagte saker
// - Midt i polygonet: oppsummering av saken (tittel + status + berørte + Jira + planlagt dag/ETA)
// - Unngår at etiketter overlapper (kollisjon/auto-flytting)
// Sikkerhet: kun KEY i URL: ?key=...

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$root = dirname(__DIR__, 3); // /public/dashboard/map -> prosjektroot
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    echo "Mangler autoload.";
    exit;
}
require $autoload;

use App\Database;

/* ------------------------------------------------------------
   Helpers
------------------------------------------------------------ */
function esc(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function sha256(string $s): string { return hash('sha256', $s); }
function table_exists(PDO $pdo, string $table): bool {
    try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
    catch (\Throwable $e) { return false; }
}
function deny(string $msg): void {
    http_response_code(403);
    echo "<!doctype html><meta charset='utf-8'><title>Ikke tilgang</title>";
    echo "<div style='font-family:system-ui;padding:24px'>";
    echo "<h2>Ikke tilgang</h2>";
    echo "<p>" . esc($msg) . "</p>";
    echo "</div>";
    exit;
}
function fmt_dt(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if (!$ts) return '';
    return date('Y-m-d H:i', $ts);
}
function fmt_no_daydate(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if (!$ts) return '';
    $days = [
        'Monday'=>'Mandag','Tuesday'=>'Tirsdag','Wednesday'=>'Onsdag','Thursday'=>'Torsdag',
        'Friday'=>'Fredag','Saturday'=>'Lørdag','Sunday'=>'Søndag'
    ];
    $months = [
        'January'=>'januar','February'=>'februar','March'=>'mars','April'=>'april','May'=>'mai','June'=>'juni',
        'July'=>'juli','August'=>'august','September'=>'september','October'=>'oktober','November'=>'november','December'=>'desember'
    ];
    $enDay = date('l', $ts);
    $enMonth = date('F', $ts);
    $dayNum = date('j', $ts);
    return ($days[$enDay] ?? $enDay) . ' ' . $dayNum . ' ' . ($months[$enMonth] ?? $enMonth);
}
function format_tv_date(): string { return fmt_no_daydate(date('Y-m-d')); }
function status_no(string $status): string {
    return match (strtolower(trim($status))) {
        'draft'=>'Utkast','scheduled'=>'Planlagt','in_progress'=>'Pågår','monitoring'=>'Overvåkes',
        'resolved'=>'Utført','cancelled'=>'Avbrutt', default=>$status
    };
}
function type_no(string $type): string {
    return match (strtolower(trim($type))) { 'planned'=>'Endring','incident'=>'Hendelse', default=>$type };
}
function time_text(array $r): string {
    $type = (string)($r['type'] ?? '');
    if (strtolower($type) === 'planned') {
        $a = fmt_dt((string)($r['schedule_start'] ?? ''));
        $b = fmt_dt((string)($r['schedule_end'] ?? ''));
        if ($a !== '' && $b !== '') return $a . '–' . $b;
        return $a !== '' ? $a : ($b !== '' ? $b : '');
    }
    $as = fmt_dt((string)($r['actual_start'] ?? ''));
    return $as !== '' ? ('Siden ' . $as) : '';
}
function planned_day_text(array $r): string {
    if (strtolower(trim((string)($r['type'] ?? ''))) !== 'planned') return '';
    $start = (string)($r['schedule_start'] ?? '');
    return $start ? fmt_no_daydate($start) : '';
}
function eta_text(array $r): string {
    $status = strtolower(trim((string)($r['status'] ?? '')));
    if (!in_array($status, ['in_progress','monitoring'], true)) return '';
    $eta = (string)($r['next_update_eta'] ?? '');
    return $eta ? fmt_dt($eta) : '';
}

/* ------------------------------------------------------------
   DB
------------------------------------------------------------ */
try { $pdo = Database::getConnection(); }
catch (\Throwable $e) { http_response_code(500); echo "Databasefeil."; exit; }

/* ------------------------------------------------------------
   KEY
------------------------------------------------------------ */
$key = trim((string)($_GET['key'] ?? ''));
if ($key === '') deny("Mangler key. Bruk /dashboard/map/?key=...");

$keyTable = 'v4_public_dashboard_keys';
if (!table_exists($pdo, $keyTable)) deny("Nøkkeltabell mangler (`$keyTable`).");

$allowedDashboards = ['events_map', 'events_tv'];
$hash = sha256($key);

$okKey = false;
try {
    $in = "'" . implode("','", array_map(fn($x) => str_replace("'", "''", $x), $allowedDashboards)) . "'";
    $st = $pdo->prepare("
        SELECT id
          FROM v4_public_dashboard_keys
         WHERE dashboard IN ($in)
           AND revoked_at IS NULL
           AND key_hash = :h
         LIMIT 1
    ");
    $st->execute([':h' => $hash]);
    $okKey = (bool)$st->fetchColumn();
} catch (\Throwable $e) { $okKey = false; }

if (!$okKey) deny("Ugyldig key (eller revokert).");

/* ------------------------------------------------------------
   Data
------------------------------------------------------------ */
$errorData = null;
$eventsOut = [];

try {
    // Jira nøkkel: external_id -> meta_json.key/issueKey -> siste segment i url
    $sqlEvents = "
        SELECT
            e.id,
            e.`type`,
            e.`status`,
            e.title_public,
            e.summary_public,
            e.schedule_start,
            e.schedule_end,
            e.actual_start,
            e.updated_at,
            e.next_update_eta,
            COALESCE(e.affected_customers, 0) AS affected_customers,
            COALESCE(
              NULLIF(TRIM(j.external_id), ''),
              NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(j.meta_json,'$.key'))), ''),
              NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(j.meta_json,'$.issueKey'))), ''),
              NULLIF(TRIM(SUBSTRING_INDEX(j.external_url,'/',-1)), ''),
              ''
            ) AS jira_key,
            COALESCE(j.external_url, '') AS jira_url
        FROM events e
        LEFT JOIN event_integrations j
               ON j.event_id = e.id
              AND j.`system` = 'jira'
        WHERE
            e.`status` IN ('in_progress','scheduled','monitoring')
            OR (e.`status` IN ('resolved','cancelled') AND e.updated_at >= (NOW() - INTERVAL 12 HOUR))
        ORDER BY
            CASE e.`status`
                WHEN 'in_progress' THEN 1
                WHEN 'monitoring'  THEN 2
                WHEN 'scheduled'   THEN 3
                WHEN 'resolved'    THEN 4
                WHEN 'cancelled'   THEN 5
                ELSE 9
            END,
            COALESCE(e.schedule_start, e.actual_start, e.updated_at) ASC,
            e.id ASC
        LIMIT 80
    ";
    $st = $pdo->query($sqlEvents);
    $events = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    if (!empty($events)) {
        $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['id'], $events), fn($x) => $x > 0)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sqlPts = "
            SELECT
                event_id,
                leveransepunkt_id,
                lat,
                lng,
                street,
                house_number,
                house_letter,
                postal_code,
                city
            FROM event_affected_addresses
            WHERE event_id IN ($placeholders)
              AND lat IS NOT NULL
              AND lng IS NOT NULL
        ";
        $stp = $pdo->prepare($sqlPts);
        $stp->execute($ids);
        $pts = $stp->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ptsByEvent = [];
        foreach ($pts as $p) {
            $eid = (int)($p['event_id'] ?? 0);
            if ($eid <= 0) continue;

            $lat = (float)($p['lat'] ?? 0);
            $lng = (float)($p['lng'] ?? 0);
            if (!$lat || !$lng) continue;

            $addr = trim((string)($p['street'] ?? ''));
            $hn   = trim((string)($p['house_number'] ?? ''));
            $hl   = trim((string)($p['house_letter'] ?? ''));

            $pc   = trim((string)($p['postal_code'] ?? ''));
            $city = trim((string)($p['city'] ?? ''));

            $addrLine = trim($addr . ' ' . $hn . $hl);
            $addrLine2 = trim($pc . ' ' . $city);
            $label = trim($addrLine . ($addrLine2 ? (', ' . $addrLine2) : ''));

            $ptsByEvent[$eid][] = [
                'lat' => $lat,
                'lng' => $lng,
                'lp'  => (string)($p['leveransepunkt_id'] ?? ''),
                'label' => $label,
            ];
        }

        foreach ($events as $r) {
            $eid = (int)($r['id'] ?? 0);
            if ($eid <= 0) continue;

            $title = trim((string)($r['title_public'] ?? ''));
            if ($title === '') $title = 'Sak #' . $eid;

            $plannedDay = planned_day_text($r);
            $eta = eta_text($r);

            $eventsOut[$eid] = [
                'id' => $eid,
                'type' => (string)($r['type'] ?? ''),
                'type_no' => type_no((string)($r['type'] ?? '')),
                'status' => (string)($r['status'] ?? ''),
                'status_no' => status_no((string)($r['status'] ?? '')),
                'title' => $title,
                'summary' => trim((string)($r['summary_public'] ?? '')),
                'time' => time_text($r),
                'planned_day' => $plannedDay,
                'eta' => $eta,
                'updated_at' => fmt_dt((string)($r['updated_at'] ?? '')),
                'affected' => (int)($r['affected_customers'] ?? 0),
                'jira_key' => trim((string)($r['jira_key'] ?? '')),
                'jira_url' => trim((string)($r['jira_url'] ?? '')),
                'points' => $ptsByEvent[$eid] ?? [],
            ];
        }
    }
} catch (\Throwable $e) {
    $errorData = 'Kunne ikke hente kart-data.';
    $eventsOut = [];
}

$refresh = (int)($_GET['refresh'] ?? 60);
if ($refresh < 10) $refresh = 10;
if ($refresh > 600) $refresh = 600;

$defaultLat = 59.413;
$defaultLng = 5.268;
$defaultZoom = 10;

?><!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <meta http-equiv="refresh" content="<?= (int)$refresh ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hendelser – Kart</title>

    <link rel="stylesheet" href="https://bootswatch.com/5/materia/bootstrap.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossorigin="anonymous">
</head>
<body class="bg-light">
<div class="container-fluid py-2">

    <div class="d-flex align-items-end justify-content-between gap-3 mb-2">
        <div>
            <div class="h4 mb-0">Hendelser / endringer – kart</div>
            <div class="text-muted">
                <?= esc(format_tv_date()) ?> • Oppdateres hvert <?= (int)$refresh ?>s
            </div>
        </div>
        <div class="text-muted text-end">
            Public map-view<br>
            Sikkerhet: key
        </div>
    </div>

    <?php if ($errorData): ?>
        <div class="alert alert-danger mb-2" role="alert">
            <?= esc($errorData) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($eventsOut)): ?>
        <div class="alert alert-light mb-2" role="alert">
            Ingen saker å vise på kart akkurat nå (eller ingen koordinater lagret).
        </div>
    <?php endif; ?>

    <div id="map" class="border rounded" style="height: calc(100vh - 120px);"></div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin="anonymous"></script>

<script>
const EVENTS = <?= json_encode(array_values($eventsOut), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const map = L.map('map', { zoomControl: true }).setView([<?= (float)$defaultLat ?>, <?= (float)$defaultLng ?>], <?= (int)$defaultZoom ?>);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);

const allBounds = [];
function addBounds(latlngs) { if (!latlngs?.length) return; try { allBounds.push(...latlngs); } catch(e){} }

// geometry helpers
function hull(points) {
  if (!points || points.length < 3) return null;
  const uniq = [];
  const seen = new Set();
  for (const p of points) {
    const k = Number(p.lat).toFixed(7) + ',' + Number(p.lng).toFixed(7);
    if (seen.has(k)) continue;
    seen.add(k);
    uniq.push(p);
  }
  if (uniq.length < 3) return null;
  uniq.sort((a,b) => (a.lng === b.lng ? a.lat - b.lat : a.lng - b.lng));
  const cross = (o,a,b) => (a.lng - o.lng)*(b.lat - o.lat) - (a.lat - o.lat)*(b.lng - o.lng);
  const lower = [];
  for (const p of uniq) { while (lower.length >= 2 && cross(lower[lower.length-2], lower[lower.length-1], p) <= 0) lower.pop(); lower.push(p); }
  const upper = [];
  for (let i = uniq.length - 1; i >= 0; i--) { const p = uniq[i]; while (upper.length >= 2 && cross(upper[upper.length-2], upper[upper.length-1], p) <= 0) upper.pop(); upper.push(p); }
  upper.pop(); lower.pop();
  const h = lower.concat(upper);
  return h.length >= 3 ? h : null;
}
function centroid(latlngs) {
  if (!latlngs?.length) return null;
  if (latlngs.length === 1) return {lat: latlngs[0].lat, lng: latlngs[0].lng};
  if (latlngs.length === 2) return {lat:(latlngs[0].lat+latlngs[1].lat)/2, lng:(latlngs[0].lng+latlngs[1].lng)/2};
  let area=0,cx=0,cy=0;
  for (let i=0;i<latlngs.length;i++){
    const p1=latlngs[i], p2=latlngs[(i+1)%latlngs.length];
    const x1=p1.lng,y1=p1.lat,x2=p2.lng,y2=p2.lat;
    const f=x1*y2-x2*y1;
    area+=f; cx+=(x1+x2)*f; cy+=(y1+y2)*f;
  }
  area/=2;
  if (Math.abs(area) < 1e-12) {
    let sLat=0,sLng=0; for (const p of latlngs){ sLat+=p.lat; sLng+=p.lng; }
    return {lat:sLat/latlngs.length, lng:sLng/latlngs.length};
  }
  cx/=(6*area); cy/=(6*area);
  return {lat:cy, lng:cx};
}

// styling
function typeStyle(type) {
  const t=(type||'').toLowerCase().trim();
  if (t==='planned') return { color:'#0d6efd', fillColor:'#0d6efd', fillOpacity:0.15, weight:2 };
  if (t==='incident') return { color:'#dc3545', fillColor:'#dc3545', fillOpacity:0.15, weight:2 };
  return { color:'#6c757d', fillColor:'#6c757d', fillOpacity:0.12, weight:2 };
}
function badgeClass(type){ const t=(type||'').toLowerCase().trim(); if(t==='planned')return'bg-primary'; if(t==='incident')return'bg-danger'; return'bg-secondary'; }
function escapeHtml(s){
  return (s ?? '').toString().replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'","&#039;");
}

// label collision avoidance
const labelNodes=[];
function rectsOverlap(a,b){ return !(a.x+a.w<=b.x||b.x+b.w<=a.x||a.y+a.h<=b.y||b.y+b.h<=a.y); }
function makeSpiralOffsets(maxRadius,step,pointsPerRing){
  const offsets=[{dx:0,dy:0}];
  for(let r=step;r<=maxRadius;r+=step){
    const n=Math.max(pointsPerRing,Math.floor((2*Math.PI*r)/step));
    for(let i=0;i<n;i++){
      const ang=(i/n)*Math.PI*2;
      offsets.push({dx:Math.round(Math.cos(ang)*r), dy:Math.round(Math.sin(ang)*r)});
    }
  }
  return offsets;
}
const OFFSETS = makeSpiralOffsets(220,18,10);

function repositionLabels(){
  if(!labelNodes.length) return;
  const nodes=[...labelNodes].sort((a,b)=>(b.priority||0)-(a.priority||0));
  const placed=[];
  const zoom=map.getZoom();
  const size=map.getSize();
  const margin=8;

  for(const n of nodes){
    const el=n.marker.getElement && n.marker.getElement();
    if(!el) continue;
    const bb=el.getBoundingClientRect();
    const w=Math.max(140,Math.round(bb.width||0));
    const h=Math.max(60,Math.round(bb.height||0));
    const basePoint=map.project(n.baseLatLng, zoom);

    let chosen=null;
    for(const o of OFFSETS){
      const x=basePoint.x+o.dx-w/2;
      const y=basePoint.y+o.dy-h/2;
      const inView=x>=-margin && y>=-margin && x+w<=size.x+margin && y+h<=size.y+margin;
      const rect={x,y,w,h};
      let collide=false;
      for(const p of placed){ if(rectsOverlap(rect,p)){ collide=true; break; } }
      if(collide) continue;
      if(inView){ chosen={o,rect}; break; }
      if(!chosen) chosen={o,rect};
    }
    if(!chosen) continue;
    const targetPoint=L.point(basePoint.x+chosen.o.dx, basePoint.y+chosen.o.dy);
    n.marker.setLatLng(map.unproject(targetPoint, zoom));
    placed.push(chosen.rect);
  }
}
let _repositionTimer=null;
function scheduleReposition(){
  if(_repositionTimer) clearTimeout(_repositionTimer);
  _repositionTimer=setTimeout(()=>{ try{ repositionLabels(); }catch(e){} }, 60);
}

// render
for(const ev of EVENTS){
  const pts=(ev.points||[]).map(p=>({lat:Number(p.lat), lng:Number(p.lng), lp:p.lp, label:p.label}));
  if(!pts.length) continue;

  const h=hull(pts);

  if(h){
    const shape=h.map(p=>[p.lat,p.lng]);
    const poly=L.polygon(shape, typeStyle(ev.type)).addTo(map);

    const jiraKey = (ev.jira_key && ev.jira_key.trim()) ? ev.jira_key.trim() : '—';
    const jiraUrl = (ev.jira_url && ev.jira_url.trim()) ? ev.jira_url.trim() : '';

    poly.bindPopup(`
      <div class="small">
        <div class="fw-semibold">${escapeHtml(ev.title)}</div>
        <div class="text-muted">${escapeHtml(ev.type_no)} • ${escapeHtml(ev.status_no)}</div>
        ${ev.planned_day ? `<div class="mt-1"><span class="text-muted">Dag:</span> ${escapeHtml(ev.planned_day)}</div>` : ''}
        ${ev.eta ? `<div class="mt-1"><span class="text-muted">ETA:</span> ${escapeHtml(ev.eta)}</div>` : ''}
        ${ev.time ? `<div class="mt-1"><span class="text-muted">Tid:</span> ${escapeHtml(ev.time)}</div>` : ''}
        ${ev.summary ? `<div class="mt-1">${escapeHtml(ev.summary)}</div>` : ''}
        <div class="mt-1"><span class="text-muted">Berørte:</span> ${escapeHtml(ev.affected)}</div>
        ${jiraUrl
          ? `<div class="mt-1"><span class="text-muted">JIRA:</span> <a href="${escapeHtml(jiraUrl)}" target="_blank" rel="noopener"><strong>${escapeHtml(jiraKey)}</strong></a></div>`
          : `<div class="mt-1"><span class="text-muted">JIRA:</span> <strong>${escapeHtml(jiraKey)}</strong></div>`
        }
      </div>
    `);
    addBounds(shape.map(x=>({lat:x[0], lng:x[1]})));
  } else {
    const shape=pts.map(p=>[p.lat,p.lng]);
    const layer=(pts.length===1)
      ? L.circleMarker(shape[0], { radius:6, ...typeStyle(ev.type), fillOpacity:0.6 }).addTo(map)
      : L.polyline(shape, typeStyle(ev.type)).addTo(map);
    layer.bindPopup(`<div class="small"><div class="fw-semibold">${escapeHtml(ev.title)}</div></div>`);
    addBounds(shape.map(x=>({lat:x[0], lng:x[1]})));
  }

  const base=centroid(h ? h : pts);
  if(!base) continue;

  const jiraKey=(ev.jira_key && ev.jira_key.trim()) ? ev.jira_key.trim() : '—';
  const jiraUrl=(ev.jira_url && ev.jira_url.trim()) ? ev.jira_url.trim() : '';

  const extraLine = ev.eta
    ? `<div class="small text-muted lh-1 mt-1">ETA: ${escapeHtml(ev.eta)}</div>`
    : (ev.planned_day ? `<div class="small text-muted lh-1 mt-1">Dag: ${escapeHtml(ev.planned_day)}</div>` : '');

  const labelHtml=`
    <div class="card shadow-sm" style="max-width: 340px;">
      <div class="card-body p-2">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div class="min-w-0">
            <div class="small text-muted lh-1">${escapeHtml(ev.type_no)}</div>
            <div class="fw-semibold text-truncate" style="max-width: 260px;">${escapeHtml(ev.title)}</div>
          </div>
          <span class="badge ${badgeClass(ev.type)}">${escapeHtml(ev.status_no)}</span>
        </div>
        <div class="small mt-1 lh-sm">
          <span class="text-muted">Berørte:</span> ${escapeHtml(ev.affected)} •
          <span class="text-muted">JIRA:</span>
          ${jiraUrl
            ? `<a href="${escapeHtml(jiraUrl)}" target="_blank" rel="noopener"><strong>${escapeHtml(jiraKey)}</strong></a>`
            : `<strong>${escapeHtml(jiraKey)}</strong>`
          }
        </div>
        ${extraLine}
      </div>
    </div>
  `;

  const marker=L.marker([base.lat, base.lng], {
    icon: L.divIcon({ className:'leaflet-div-icon', html: labelHtml, iconSize:null }),
    interactive:true
  }).addTo(map);

  marker.bindPopup(`
    <div class="small">
      <div class="fw-semibold">${escapeHtml(ev.title)}</div>
      <div class="text-muted">${escapeHtml(ev.type_no)} • ${escapeHtml(ev.status_no)}</div>
      ${ev.planned_day ? `<div class="mt-1"><span class="text-muted">Dag:</span> ${escapeHtml(ev.planned_day)}</div>` : ''}
      ${ev.eta ? `<div class="mt-1"><span class="text-muted">ETA:</span> ${escapeHtml(ev.eta)}</div>` : ''}
      ${ev.time ? `<div class="mt-1"><span class="text-muted">Tid:</span> ${escapeHtml(ev.time)}</div>` : ''}
      ${ev.summary ? `<div class="mt-1">${escapeHtml(ev.summary)}</div>` : ''}
      <div class="mt-1"><span class="text-muted">Berørte:</span> ${escapeHtml(ev.affected)}</div>
      <div class="mt-1"><span class="text-muted">Oppdatert:</span> ${escapeHtml(ev.updated_at || '—')}</div>
      ${jiraUrl
        ? `<div class="mt-1"><span class="text-muted">JIRA:</span> <a href="${escapeHtml(jiraUrl)}" target="_blank" rel="noopener"><strong>${escapeHtml(jiraKey)}</strong></a></div>`
        : `<div class="mt-1"><span class="text-muted">JIRA:</span> <strong>${escapeHtml(jiraKey)}</strong></div>`
      }
    </div>
  `);

  labelNodes.push({ marker, baseLatLng: L.latLng(base.lat, base.lng), priority: Number(ev.affected || 0) });

  for(const p of pts){
    const pm=L.circleMarker([p.lat,p.lng], { radius:3, color:typeStyle(ev.type).color, weight:1, fillColor:typeStyle(ev.type).fillColor, fillOpacity:0.8 }).addTo(map);
    pm.bindTooltip(escapeHtml(p.label || p.lp || ''), { direction:'top' });
  }
}

if(allBounds.length >= 1){
  const b=L.latLngBounds(allBounds.map(p=>[p.lat,p.lng]));
  map.fitBounds(b.pad(0.15));
}

setTimeout(()=>scheduleReposition(), 120);
map.on('zoomend', scheduleReposition);
map.on('moveend', scheduleReposition);
</script>
</body>
</html>