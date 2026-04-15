<?php
// public/pages/start.php

use App\Database;

$fullname = $fullname ?? ($_SESSION['fullname'] ?? $_SESSION['username'] ?? '');
$username = $username ?? ($_SESSION['username'] ?? '');

$userLinks      = [];
$userId         = 0;
$lastSeenAt     = null;   // forrige besøk – brukes som aktivitetsvindu
$invMovements   = [];
$recentImages   = [];

try {
    $pdo = Database::getConnection();

    // Finn user_id
    if ($username) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $userId = (int)($stmt->fetchColumn() ?: 0);
    }

    if ($userId > 0) {
        // Hent Mine lenker (snarveier) – egen try/catch så feil her ikke stopper resten
        try {
            $stmt = $pdo->prepare('
                SELECT title, url, icon_class
                  FROM user_quick_links
                 WHERE user_id = :uid
                 ORDER BY sort_order ASC, id ASC
            ');
            $stmt->execute([':uid' => $userId]);
            $userLinks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $ignored) {}

        // last_seen_at – legg til kolonne ved behov, les, oppdater
        try {
            $pdo->exec('ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS last_seen_at datetime DEFAULT NULL');

            $stmt = $pdo->prepare('SELECT last_seen_at FROM user_settings WHERE user_id = :uid LIMIT 1');
            $stmt->execute([':uid' => $userId]);
            $lastSeenAt = $stmt->fetchColumn() ?: null;

            $pdo->prepare("
                INSERT INTO user_settings (user_id, last_seen_at)
                VALUES (:uid, NOW())
                ON DUPLICATE KEY UPDATE last_seen_at = NOW()
            ")->execute([':uid' => $userId]);
        } catch (\Throwable $ignored) {}
    }

    // -- Aktivitet: fallback til 7 dager hvis første besøk --
    $since = $lastSeenAt ?: date('Y-m-d H:i:s', strtotime('-7 days'));

    // Lagerbevegelser siden sist
    try {
        $stmt = $pdo->prepare("
            SELECT m.id, m.occurred_at, m.type, m.qty,
                   m.created_by, m.note,
                   p.name AS product_name, p.unit
              FROM inv_movements m
              LEFT JOIN inv_products p ON p.id = m.product_id
             WHERE m.occurred_at >= :since
             ORDER BY m.occurred_at DESC
             LIMIT 10
        ");
        $stmt->execute([':since' => $since]);
        $invMovements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $ignored) {}

    // Nye bilder siden sist
    try {
        $stmt = $pdo->prepare("
            SELECT a.id, a.created_at, a.created_by, a.file_path,
                   nl.name AS location_name
              FROM node_location_attachments a
              LEFT JOIN node_locations nl ON nl.id = a.node_location_id
             WHERE a.created_at >= :since
             ORDER BY a.created_at DESC
             LIMIT 10
        ");
        $stmt->execute([':since' => $since]);
        $recentImages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $ignored) {}

} catch (\Throwable $e) {
    // DB utilgjengelig – vis siden uten data
}

// Hjelpefunksjon: lesbar tidsstempel
function relTime(string $dt): string {
    $ts   = strtotime($dt);
    $diff = time() - $ts;
    if ($diff < 60)       return 'Akkurat nå';
    if ($diff < 3600)     return round($diff / 60) . ' min siden';
    if ($diff < 86400)    return round($diff / 3600) . ' t siden';
    if ($diff < 604800)   return round($diff / 86400) . ' d siden';
    return date('d.m.Y H:i', $ts);
}

// Bevegelses-type til merkelapp
function movTypeLabel(string $t): string {
    return match($t) {
        'IN'     => 'Inn',
        'OUT'    => 'Ut',
        'ADJUST' => 'Justering',
        default  => $t,
    };
}
function movTypeBadge(string $t): string {
    return match($t) {
        'IN'     => 'text-bg-success',
        'OUT'    => 'text-bg-warning',
        'ADJUST' => 'text-bg-info',
        default  => 'text-bg-secondary',
    };
}
?>

<style>
/* ── Snarvei-grid ── */
.shortcut-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.shortcut-tile {
    width: 88px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    gap: 7px;
    padding: 16px 6px 13px;
    border-radius: 14px;
    border: 1.5px solid var(--bs-border-color, #dee2e6);
    background: var(--bs-body-bg, #fff);
    text-decoration: none;
    text-align: center;
    color: inherit;
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
    cursor: pointer;
}
.shortcut-tile:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 18px rgba(0,0,0,.10);
    border-color: var(--bs-primary, #0d6efd);
    color: var(--bs-primary, #0d6efd);
}
.shortcut-icon {
    font-size: 1.8rem;
    line-height: 1;
    flex-shrink: 0;
}
.shortcut-label {
    font-size: 10.5px;
    font-weight: 600;
    line-height: 1.3;
    word-break: break-word;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    width: 100%;
}

/* ── Aktivitetsrad ── */
.activity-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid var(--bs-border-color-translucent, rgba(0,0,0,.06));
}
.activity-row:last-child { border-bottom: none; }
.activity-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: .9rem;
}
.activity-body { flex: 1; min-width: 0; }
.activity-title {
    font-size: 12.5px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.activity-meta {
    font-size: 11px;
    color: var(--bs-secondary-color, #6c757d);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.activity-time {
    font-size: 10.5px;
    color: var(--bs-secondary-color, #6c757d);
    white-space: nowrap;
    flex-shrink: 0;
}
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 28px 12px;
    color: var(--bs-secondary-color, #6c757d);
    text-align: center;
}
.empty-state i { font-size: 1.6rem; opacity: .4; }
.empty-state span { font-size: 12px; }
</style>

<!-- Side-header -->
<div class="mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h4 mb-1">Oversikt</h1>
            <p class="text-muted small mb-0">
                Velkommen, <?php echo htmlspecialchars($fullname ?: $username, ENT_QUOTES, 'UTF-8'); ?>.
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 small text-muted">
            <i class="bi bi-person-badge"></i>
            <span>Innlogget som <code><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></code></span>
        </div>
    </div>
</div>

<div class="row g-3">

    <!-- Snarveier -->
    <div class="col-12">
        <section class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 mb-0"><i class="bi bi-grid-3x3-gap me-1 opacity-50"></i> Snarveier</h2>
                    <a href="/?page=minside" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:11px;">
                        <i class="bi bi-pencil me-1"></i>Rediger
                    </a>
                </div>

                <?php if (!empty($userLinks)): ?>
                    <div class="shortcut-grid">
                        <?php foreach ($userLinks as $l): ?>
                            <?php
                            $title = htmlspecialchars((string)($l['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $url   = htmlspecialchars((string)($l['url'] ?? '#'), ENT_QUOTES, 'UTF-8');
                            $icon  = htmlspecialchars((string)($l['icon_class'] ?: 'bi bi-link-45deg'), ENT_QUOTES, 'UTF-8');
                            $ext   = !str_starts_with((string)($l['url'] ?? ''), '/') && (string)($l['url'] ?? '') !== '';
                            ?>
                            <a class="shortcut-tile"
                               href="<?php echo $url; ?>"
                               <?php echo $ext ? 'target="_blank" rel="noopener"' : ''; ?>
                               title="<?php echo $title; ?>"
                            >
                                <span class="shortcut-icon"><i class="<?php echo $icon; ?>"></i></span>
                                <span class="shortcut-label"><?php echo $title; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-grid-3x3-gap"></i>
                        <span>Ingen snarveier enda.<br>
                            <a href="/?page=minside">Legg til under Min side</a>.
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Aktivitet siden sist innlogging -->
    <div class="col-12">
        <div class="d-flex align-items-center gap-2 mb-2 px-1">
            <h2 class="h6 mb-0 text-muted">
                <i class="bi bi-clock-history me-1"></i>
                Aktivitet siden sist innlogging
            </h2>
            <?php if ($lastSeenAt): ?>
                <span class="badge text-bg-secondary-subtle fw-normal" style="font-size:10px;">
                    <?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($lastSeenAt)), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            <?php else: ?>
                <span class="badge text-bg-secondary-subtle fw-normal" style="font-size:10px;">Siste 7 dager</span>
            <?php endif; ?>
        </div>

        <div class="row g-3">
            <!-- Lagerbevegelser -->
            <div class="col-md-6">
                <section class="card shadow-sm h-100">
                    <div class="card-body p-3">
                        <h3 class="h6 mb-3 d-flex align-items-center gap-2">
                            <span class="activity-icon bg-warning-subtle text-warning">
                                <i class="bi bi-box-seam"></i>
                            </span>
                            Lagerbevegelser
                            <?php if (!empty($invMovements)): ?>
                                <span class="badge text-bg-warning ms-auto">
                                    <?php echo count($invMovements); ?>
                                </span>
                            <?php endif; ?>
                        </h3>

                        <?php if (!empty($invMovements)): ?>
                            <?php foreach ($invMovements as $m): ?>
                                <div class="activity-row">
                                    <div class="activity-icon bg-body-tertiary">
                                        <?php if ($m['type'] === 'IN'): ?>
                                            <i class="bi bi-arrow-down-circle text-success"></i>
                                        <?php elseif ($m['type'] === 'OUT'): ?>
                                            <i class="bi bi-arrow-up-circle text-warning"></i>
                                        <?php else: ?>
                                            <i class="bi bi-arrow-left-right text-info"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-body">
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars($m['product_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div class="activity-meta">
                                            <span class="badge <?php echo movTypeBadge($m['type']); ?> me-1" style="font-size:9px;">
                                                <?php echo movTypeLabel($m['type']); ?>
                                            </span>
                                            <?php echo htmlspecialchars(
                                                rtrim(number_format((float)($m['qty'] ?? 0), 0) . ' ' . ($m['unit'] ?? ''), ' '),
                                                ENT_QUOTES, 'UTF-8'
                                            ); ?>
                                            <?php if ($m['created_by']): ?>
                                                · <?php echo htmlspecialchars($m['created_by'], ENT_QUOTES, 'UTF-8'); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="activity-time">
                                        <?php echo relTime((string)$m['occurred_at']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="mt-2 text-end">
                                <a href="/?page=logistikk_movements" class="small text-muted">
                                    Se alle <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-box-seam"></i>
                                <span>Ingen lagerbevegelser i perioden.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <!-- Nye bilder -->
            <div class="col-md-6">
                <section class="card shadow-sm h-100">
                    <div class="card-body p-3">
                        <h3 class="h6 mb-3 d-flex align-items-center gap-2">
                            <span class="activity-icon bg-primary-subtle text-primary">
                                <i class="bi bi-images"></i>
                            </span>
                            Nye bilder
                            <?php if (!empty($recentImages)): ?>
                                <span class="badge text-bg-primary ms-auto">
                                    <?php echo count($recentImages); ?>
                                </span>
                            <?php endif; ?>
                        </h3>

                        <?php if (!empty($recentImages)): ?>
                            <?php foreach ($recentImages as $img): ?>
                                <div class="activity-row">
                                    <div class="activity-icon bg-primary-subtle">
                                        <i class="bi bi-image text-primary"></i>
                                    </div>
                                    <div class="activity-body">
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars($img['location_name'] ?? 'Ukjent lokasjon', ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div class="activity-meta">
                                            <?php echo htmlspecialchars(basename((string)($img['file_path'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if ($img['created_by']): ?>
                                                · <?php echo htmlspecialchars($img['created_by'], ENT_QUOTES, 'UTF-8'); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="activity-time">
                                        <?php echo relTime((string)$img['created_at']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="mt-2 text-end">
                                <a href="/?page=bildekart" class="small text-muted">
                                    Se alle <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-images"></i>
                                <span>Ingen nye bilder i perioden.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </div>

</div>
