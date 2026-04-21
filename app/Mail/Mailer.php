<?php

namespace App\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * E-postleverandør for HKBB Teknisk.
 *
 * Støttede drivere (MAIL_DRIVER i .env):
 *   smtp  – ekstern SMTP-server med autentisering (standard)
 *   mail  – PHP sin innebygde mail()-funksjon (krever lokal MTA / php.ini-konfig)
 *
 * Konfigurasjon hentes fra .env (MAIL_SMTP_* / MAIL_FROM_*).
 */
class Mailer
{
    private string $driver;
    private string $host;
    private int    $port;
    private string $encryption;
    private string $username;
    private string $password;
    private string $fromAddress;
    private string $fromName;

    public function __construct()
    {
        // phpdotenv createImmutable skriver til $_ENV, ikke putenv() – sjekk $_ENV først
        $env = fn(string $k, string $default = ''): string =>
            (string)(($_ENV[$k] ?? null) ?: getenv($k) ?: $default);

        $this->driver      = $env('MAIL_DRIVER',          'smtp');
        $this->host        = $env('MAIL_SMTP_HOST',        'smtp.office365.com');
        $this->port        = (int)$env('MAIL_SMTP_PORT',   '587');
        $this->encryption  = $env('MAIL_SMTP_ENCRYPTION',  'tls');
        $this->username    = $env('MAIL_SMTP_USERNAME');
        $this->password    = $env('MAIL_SMTP_PASSWORD');
        $this->fromAddress = $env('MAIL_FROM_ADDRESS',     'noreply@hkbb.no');
        $this->fromName    = $env('MAIL_FROM_NAME',        'HKBB Teknisk');
    }

    /**
     * Send an email.
     *
     * @param array<string,string> $to        ['email@example.com' => 'Display Name', ...]
     * @param string               $subject
     * @param string               $htmlBody
     * @param string               $textBody  Plain-text fallback (auto-generated from HTML if empty)
     *
     * @throws MailException
     */
    public function send(array $to, string $subject, string $htmlBody, string $textBody = ''): void
    {
        $mail = new PHPMailer(true);

        if ($this->driver === 'mail') {
            $mail->isMail();
        } else {
            $mail->isSMTP();
            $mail->Host       = $this->host;
            $mail->Port       = $this->port;
            if ($this->encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->SMTPAuth   = true;
            } elseif ($this->encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->SMTPAuth   = true;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
                $mail->SMTPAuth   = false;
            }
            if ($mail->SMTPAuth) {
                $mail->Username = $this->username;
                $mail->Password = $this->password;
            }
        }

        $mail->XMailer  = ' ';
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'quoted-printable';

        $mail->setFrom($this->fromAddress, $this->fromName);

        foreach ($to as $email => $name) {
            $email = trim($email);
            $name  = trim((string)$name);
            if ($email === '') continue;
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($email, $name !== '' ? $name : $email);
            }
        }

        if (count($mail->getToAddresses()) === 0) {
            throw new MailException('Ingen gyldige e-postadresser angitt.');
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody !== ''
            ? $textBody
            : strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody));

        try {
            $mail->send();
        } catch (MailException $e) {
            throw new MailException($e->getMessage() . ' | ' . $mail->ErrorInfo);
        }
    }

    /**
     * Send a test email to a single address.
     */
    public function sendTest(string $toEmail, string $toName = ''): void
    {
        $driverInfo = $this->driver === 'mail'
            ? 'PHP mail()'
            : htmlspecialchars($this->host . ':' . $this->port, ENT_QUOTES, 'UTF-8');

        $html = '
            <p>Dette er en testepost fra <strong>HKBB Teknisk – Avtaler &amp; kontrakter</strong>.</p>
            <p>E-postkonfigurasjon fungerer korrekt.</p>
            <hr>
            <p style="color:#6c757d;font-size:0.85em;">
                Sendt fra: ' . htmlspecialchars($this->fromAddress, ENT_QUOTES, 'UTF-8') . '<br>
                Driver: ' . $driverInfo . '
            </p>
        ';
        $this->send(
            [$toEmail => $toName ?: $toEmail],
            'Testepost – HKBB Teknisk',
            $html
        );
    }
}
