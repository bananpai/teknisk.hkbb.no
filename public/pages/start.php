<?php
// public/pages/start.php

use App\Database;

// Sørg for at vi har disse, i tilfelle de ikke er satt før
$fullname = $fullname ?? ($_SESSION['fullname'] ?? $_SESSION['username'] ?? '');
$username = $username ?? ($_SESSION['username'] ?? '');

$userLinks = [];
try {
    if ($username) {
        $pdo = Database::getConnection();

        // Finn user_id
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $userId = (int)($stmt->fetchColumn() ?: 0);

        if ($userId > 0) {
            // Hent private lenker
            $stmt = $pdo->prepare('
                SELECT title, url, icon_class
                  FROM user_quick_links
                 WHERE user_id = :uid
                 ORDER BY sort_order ASC, id ASC
            ');
            $stmt->execute([':uid' => $userId]);
            $userLinks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
} catch (\Throwable $e) {
    // Tabell/DB kan mangle – startsiden skal fortsatt fungere
    $userLinks = [];
}
?>

<!-- Side-header inne i innholdet -->
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
            <span>
                Innlogget som
                <code><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></code>
            </span>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Venstre kolonne: Snarveier -->
    <div class="col-lg-8">
        <section class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h5 mb-0">Snarveier</h2>
                    <span class="badge text-bg-secondary-subtle small">
                        Startside
                    </span>
                </div>

                <p class="small text-muted mb-3">
                    Dine private lenker (legg til / endre under <a href="/?page=minside">Min side</a>).
                </p>

                <?php if (!empty($userLinks)): ?>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php foreach ($userLinks as $l): ?>
                            <?php
                            $title = (string)($l['title'] ?? '');
                            $url   = (string)($l['url'] ?? '');
                            $icon  = (string)($l['icon_class'] ?? '');
                            $icon  = $icon !== '' ? $icon : 'bi bi-link-45deg';

                            // åpne eksterne i ny fane, interne i samme
                            $isInternal = str_starts_with($url, '/');
                            ?>
                            <a
                                class="btn btn-sm btn-outline-primary"
                                href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $isInternal ? '' : 'target="_blank" rel="noopener"'; ?>
                            >
                                <i class="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
                                <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-light border small mb-3">
                        Du har ingen private lenker enda.
                        <a href="/?page=minside">Gå til Min side</a> for å legge til.
                    </div>
                <?php endif; ?>

                <div class="mb-3 d-flex flex-wrap gap-1">
                    <span class="badge rounded-pill text-bg-primary-subtle">
                        <i class="bi bi-diagram-3 me-1"></i> AD-verktøy (planlagt)
                    </span>
                    <span class="badge rounded-pill text-bg-secondary-subtle">
                        <i class="bi bi-journal-text me-1"></i> Driftslogger
                    </span>
                    <span class="badge rounded-pill text-bg-info-subtle">
                        <i class="bi bi-graph-up-arrow me-1"></i> Rapporter
                    </span>
                </div>

                <div class="row g-2">
                    <div class="col-sm-6">
                        <div class="border rounded-3 bg-body-tertiary p-2 h-100 small">
                            <strong class="d-flex align-items-center mb-1">
                                <i class="bi bi-activity me-1"></i> Systemstatus
                            </strong>
                            <span>Her kan vi vise enkel status fra ulike systemer / overvåkning.</span>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="border rounded-3 bg-body-tertiary p-2 h-100 small">
                            <strong class="d-flex align-items-center mb-1">
                                <i class="bi bi-calendar-event me-1"></i> Planlagt arbeid
                            </strong>
                            <span>En liten liste med planlagte endringer eller vedlikehold.</span>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="border rounded-3 bg-body-tertiary p-2 h-100 small">
                            <strong class="d-flex align-items-center mb-1">
                                <i class="bi bi-link-45deg me-1"></i> Lenker
                            </strong>
                            <span>Direkte lenker til andre interne verktøy.</span>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="border rounded-3 bg-body-tertiary p-2 h-100 small">
                            <strong class="d-flex align-items-center mb-1">
                                <i class="bi bi-bell me-1"></i> Varsler
                            </strong>
                            <span>Senere kan vi hente inn varsler fra andre systemer.</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Høyre kolonne: Om denne løsningen -->
    <div class="col-lg-4">
        <aside class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-2">Om denne løsningen</h2>
                <p class="small text-muted mb-3">
                    Du er logget inn via lokal AD
                    (<code><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></code>).
                    Tilgangen styres av gruppemedlemskap i domenet.
                </p>

                <div class="mb-3 d-flex flex-wrap gap-1">
                    <span class="badge rounded-pill text-bg-dark">PHP 8</span>
                    <span class="badge rounded-pill text-bg-secondary">IIS</span>
                    <span class="badge rounded-pill text-bg-success">AD-integrasjon</span>
                </div>

                <div class="small text-muted">
                    Videre planer:
                    <ul class="mt-1 mb-0 ps-3">
                        <li>Legge til flere moduler/verktøy under menyen.</li>
                        <li>Evt. TOTP/MFA på toppen av AD.</li>
                        <li>Enkle admin-sider for tilgangsstyring.</li>
                    </ul>
                </div>
            </div>
        </aside>
    </div>
</div>
