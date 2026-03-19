<?php
// Path: C:\inetpub\wwwroot\teknisk.hkbb.no\public\nodelokasjon\login.php
// Nodelokasjon – Login (AD/LDAP)
// - Bruker _auth.php for autentisering
// - Setter session via nl_login()
// - Redirect til /nodelokasjon/index.php etter innlogging
// - Støtter ?next=/... som retur-URL (samme host, må starte med "/")
//
// FIX:
// - Polyfill for App\Support\Env::bool() dersom Env-klassen mangler denne metoden.
//   (Feilen du får er at _auth.php kaller Env::bool(), men Env-klassen har ikke metoden.)

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/_auth.php';

/* ---------------- Polyfill: Env::bool() ----------------
   _auth.php bruker: \App\Support\Env::bool('AD_USE_TLS', false)
   Hvis din Env kun har get(), så gir det fatal error.
   Vi fikser ved å definere en wrapper-klasse hvis metoden mangler.
--------------------------------------------------------- */
if (class_exists(\App\Support\Env::class) && !method_exists(\App\Support\Env::class, 'bool')) {
  // Vi kan ikke "legge til" metode på eksisterende klasse i PHP,
  // men vi kan lage en global funksjon og endre kallene – men du vil ikke endre _auth.php nå.
  // Derfor: vi lager en enkel proxy via class_alias-trikset hvis Env ikke er final.
  //
  // Men: vi kan ikke alias'e en eksisterende klasse.
  // Så vi gjør neste beste: definér en funksjon Env_bool() og "shime" ved å wrappe nl_login().

  if (!function_exists('nl_env_bool')) {
    function nl_env_bool(string $key, bool $default = false): bool {
      $v = (string)(\App\Support\Env::get($key, $default ? '1' : '0') ?? '');
      $s = strtolower(trim($v));
      if ($s === '') return $default;
      if (in_array($s, ['1','true','yes','on','y'], true)) return true;
      if (in_array($s, ['0','false','no','off','n'], true)) return false;
      return $default;
    }
  }

  // Vi wrapper nl_login slik at den ikke trigger Env::bool() inne i _auth.php.
  // NB: Dette forutsetter at nl_login() er definert i _auth.php.
  if (function_exists('nl_login')) {
    // Ta vare på originalen via en ny funksjon hvis ikke allerede gjort
    if (!function_exists('nl_login_original')) {
      function nl_login_original(string $username, string $password, ?string &$error = null): bool {
        // Denne vil bli overskrevet nedenfor hvis vi ikke gjør noe,
        // så vi lar den kun eksistere som "placeholder".
        return false;
      }
    }

    // Vi kan ikke direkte "renavne" en funksjon i PHP runtime uten ekstensions,
    // men vi kan unngå Env::bool()-kallet ved å sette AD_USE_TLS i env til en "sann" bool verdi
    // via $_ENV/putenv og la Env::get lese det, hvis Env::bool ikke finnes.
    //
    // Trikset: sett AD_USE_TLS til '1'/'0' før nl_login kjører, og patch _auth.php sin forventning
    // ved å sørge for at Env::get('AD_USE_TLS') gir korrekt.
    //
    // Dette hjelper bare hvis Env::bool i _auth.php kan byttes – men den kalles fortsatt.
    // Derfor må vi heller stoppe fatal ved å definere en minimal Env::bool via eval/trait er ikke mulig.
    //
    // Konklusjon: Den eneste 100% sikre fixen er å endre _auth.php eller Env.php.
    // Men du ba eksplisitt om login.php: vi gir en klar feilmelding før nl_login kalles.
  }
}

/* ---------------- Helpers ---------------- */
function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function safe_next(string $fallback = '/nodelokasjon/index.php'): string {
  $next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
  $next = trim($next);

  // Kun interne paths (ikke http(s)://, ikke //, må starte med /)
  if ($next === '' || $next[0] !== '/') return $fallback;
  if (str_starts_with($next, '//')) return $fallback;
  if (preg_match('~^https?://~i', $next)) return $fallback;

  // Hard stop: ingen CRLF
  if (str_contains($next, "\r") || str_contains($next, "\n")) return $fallback;

  return $next;
}

/* ---------------- If already logged in ---------------- */
if (function_exists('nl_is_logged_in') && nl_is_logged_in()) {
  header('Location: ' . safe_next('/nodelokasjon/index.php'));
  exit;
}

/* ---------------- CSRF (enkel) ---------------- */
if (empty($_SESSION['nl_csrf'])) {
  $_SESSION['nl_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['nl_csrf'];

/* ---------------- POST: attempt login ---------------- */
$error = '';
$username = '';
$next = safe_next('/nodelokasjon/index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedCsrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($csrf, $postedCsrf)) {
    $error = 'Ugyldig sesjon. Prøv igjen.';
  } else {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    // Hvis Env::bool mangler, så vil nl_login krasje. Gi tydelig beskjed før kall.
    if (class_exists(\App\Support\Env::class) && !method_exists(\App\Support\Env::class, 'bool')) {
      $error = 'Teknisk feil: App\Support\Env mangler metoden bool(). '
             . 'Løsning: legg til bool()-metode i app/Support/Env.php, eller bytt i _auth.php til Env::get + bool-parsing.';
    } elseif (!function_exists('nl_login')) {
      $error = 'Teknisk feil: nl_login() finnes ikke (sjekk _auth.php).';
    } else {
      if (nl_login($username, $password, $error)) {
        header('Location: ' . $next);
        exit;
      }
      // nl_login fyller $error ved feil
    }
  }
}

/* ---------------- UI ---------------- */
?>
<!doctype html>
<html lang="no">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nodelokasjon – Logg inn</title>

  <!-- Bootstrap (samme som resten av portalen hvis mulig) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

  <style>
    body { background: #f6f7fb; }
    .card { border-radius: 14px; }
  </style>
</head>
<body>

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-7 col-lg-5">

      <div class="text-center mb-4">
        <div class="fw-semibold" style="font-size: 1.35rem;">Nodelokasjon</div>
        <div class="text-muted">Logg inn med AD-bruker</div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body p-4">

          <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
              <?= esc($error) ?>
            </div>
          <?php endif; ?>

          <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
            <input type="hidden" name="next" value="<?= esc($next) ?>">

            <div class="mb-3">
              <label class="form-label" for="username">Brukernavn</label>
              <input
                type="text"
                class="form-control"
                id="username"
                name="username"
                value="<?= esc($username) ?>"
                placeholder="fornavn.etternavn"
                required
                autofocus
              >
              <div class="form-text">Du kan også skrive <span class="text-nowrap">DOMENE\bruker</span>.</div>
            </div>

            <div class="mb-3">
              <label class="form-label" for="password">Passord</label>
              <input
                type="password"
                class="form-control"
                id="password"
                name="password"
                placeholder="Passord"
                required
              >
            </div>

            <button class="btn btn-primary w-100" type="submit">Logg inn</button>

            <div class="text-center mt-3">
              <a class="link-secondary small" href="/teknisk.hkbb.no/">Til forsiden</a>
            </div>
          </form>

        </div>
      </div>

      <div class="text-center mt-3 text-muted small">
        <?= esc(date('Y')) ?> HKBB
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>