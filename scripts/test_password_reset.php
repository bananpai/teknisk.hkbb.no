<?php
// scripts/test_password_reset.php
// Kjøres fra kommandolinjen: php scripts/test_password_reset.php <brukernavn>

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Last .env eksplisitt (CLI har ikke IIS-konteksten)
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

use App\Auth\AdLdap;
use App\Mail\Mailer;

$username = $argv[1] ?? '';
if ($username === '') {
    echo "Bruk: php scripts/test_password_reset.php <ad-brukernavn>\n";
    exit(1);
}

$hosts  = ['bb-dc01', 'bb-dc02'];
$domain = 'bbdrift.ad';
$baseDn = 'dc=bbdrift,dc=ad';
$group  = 'teknisk';

// ── 1) LDAP-oppslag ─────────────────────────────────────────
echo "=== Steg 1: LDAP e-postoppslag ===\n";
echo "Bruker:    {$username}\n";
echo "BindDN:    " . ($_ENV['AD_BIND_DN'] ?? getenv('AD_BIND_DN') ?: '(ikke satt)') . "\n";

try {
    $ad    = new AdLdap($hosts, $domain, $baseDn, $group);
    $email = $ad->getUserEmail($username);

    if ($email === null) {
        echo "RESULTAT:  null – e-post ikke funnet i AD\n";
        echo "           Sjekk at 'mail'-attributtet er satt på brukeren i AD:\n";
        echo "           Get-ADUser {$username} -Properties mail | Select mail\n";
        exit(1);
    }

    echo "RESULTAT:  {$email}\n";
} catch (\Throwable $e) {
    echo "FEIL:      " . $e->getMessage() . "\n";
    exit(1);
}

// ── 2) SMTP-test ────────────────────────────────────────────
echo "\n=== Steg 2: Send testepost til {$email} ===\n";
echo "Host:      " . ($_ENV['MAIL_SMTP_HOST'] ?? getenv('MAIL_SMTP_HOST') ?: '?') . "\n";
echo "Port:      " . ($_ENV['MAIL_SMTP_PORT'] ?? getenv('MAIL_SMTP_PORT') ?: '?') . "\n";
echo "Fra:       " . ($_ENV['MAIL_FROM_ADDRESS'] ?? getenv('MAIL_FROM_ADDRESS') ?: '?') . "\n";

try {
    $mailer = new Mailer();
    $mailer->send(
        [$email => $username],
        'Test passord-reset – HKBB Teknisk',
        '<p>Dette er en test av passord-reset e-post fra HKBB Teknisk.</p>'
    );
    echo "RESULTAT:  E-post sendt OK\n";
} catch (\Throwable $e) {
    echo "FEIL:      " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nAlt OK – sjekk innboksen (og søppelpost) til {$email}\n";
