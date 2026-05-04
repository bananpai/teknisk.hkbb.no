<?php
// public/pages/documentation.php – Teknisk systemdokumentasjon

declare(strict_types=1);

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Tilgang: admin eller rollen 'dokumentasjon'
$canDoc = $isAdmin
    || has_any(['dokumentasjon', 'documentation'], $roles)
    || has_any(['dokumentasjon', 'documentation'], $perms);

if (!$canDoc) {
    echo '<div class="alert alert-danger">Tilgang nektet.</div>';
    return;
}

$currentVersion = '2.x';
$lastUpdated    = '2026-05';
?>

<style>
.doc-nav { position: sticky; top: 80px; }
.doc-nav .nav-link { color: var(--bs-body-color); font-size: .85rem; padding: .2rem .5rem; }
.doc-nav .nav-link:hover { color: var(--bs-primary); }
.doc-nav .nav-link.active { color: var(--bs-primary); font-weight: 600; }
.doc-nav .nav-link-sub { padding-left: 1.2rem; font-size: .8rem; }
.doc-section { scroll-margin-top: 76px; }
.doc-badge { font-size: .72rem; vertical-align: middle; }
.role-table td, .role-table th { font-size: .85rem; }
pre.doc-code { background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color); border-radius: 6px; padding: .75rem 1rem; font-size: .82rem; overflow-x: auto; }
.doc-callout { border-left: 4px solid; padding: .65rem 1rem; border-radius: 0 6px 6px 0; margin-bottom: 1rem; font-size: .9rem; }
.doc-callout-warning { border-color: var(--bs-warning); background: rgba(var(--bs-warning-rgb),.08); }
.doc-callout-info    { border-color: var(--bs-info);    background: rgba(var(--bs-info-rgb),.08); }
.doc-callout-danger  { border-color: var(--bs-danger);  background: rgba(var(--bs-danger-rgb),.08); }
</style>

<div class="row g-4">

<!-- Sidemeny -->
<div class="col-lg-3 d-none d-lg-block">
<nav class="doc-nav">
    <div class="fw-semibold small text-muted mb-2 ps-2">INNHOLD</div>
    <nav class="nav flex-column" id="docNav">
        <a class="nav-link" href="#oversikt">1. Systemoversikt</a>
        <a class="nav-link" href="#plattform">2. Teknisk plattform</a>
        <a class="nav-link nav-link-sub" href="#plattform-stack">Stack</a>
        <a class="nav-link nav-link-sub" href="#plattform-infrastruktur">Infrastruktur</a>
        <a class="nav-link" href="#auth">3. Autentisering</a>
        <a class="nav-link nav-link-sub" href="#auth-ad">Active Directory</a>
        <a class="nav-link nav-link-sub" href="#auth-entra">Entra ID (Azure AD)</a>
        <a class="nav-link nav-link-sub" href="#auth-2fa">To-faktor (2FA)</a>
        <a class="nav-link nav-link-sub" href="#auth-sesjon">Sesjonsmodell</a>
        <a class="nav-link" href="#tilgang">4. Roller og tilgang</a>
        <a class="nav-link" href="#sikkerhet">5. Sikkerhet</a>
        <a class="nav-link nav-link-sub" href="#sikkerhet-kryptering">Datakryptering</a>
        <a class="nav-link nav-link-sub" href="#sikkerhet-ip">IP-filter</a>
        <a class="nav-link nav-link-sub" href="#sikkerhet-audit">Audit-logg</a>
        <a class="nav-link nav-link-sub" href="#sikkerhet-passord">Passordbytte</a>
        <a class="nav-link" href="#moduler">6. Moduler</a>
        <a class="nav-link nav-link-sub" href="#mod-dashboard">Dashboard</a>
        <a class="nav-link nav-link-sub" href="#mod-hendelser">Hendelser & Endringer</a>
        <a class="nav-link nav-link-sub" href="#mod-kontrakter">Avtaler & Kontrakter</a>
        <a class="nav-link nav-link-sub" href="#mod-kpi">Mål & KPI</a>
        <a class="nav-link nav-link-sub" href="#mod-nettverk">Nettverksdokumentasjon</a>
        <a class="nav-link nav-link-sub" href="#mod-feltobjekter">Feltobjekter</a>
        <a class="nav-link nav-link-sub" href="#mod-logistikk">Logistikk & Varelager</a>
        <a class="nav-link nav-link-sub" href="#mod-crm">CRM & Fakturering</a>
        <a class="nav-link" href="#api">7. API</a>
        <a class="nav-link" href="#integrasjoner">8. Integrasjoner</a>
        <a class="nav-link" href="#admin">9. Systemadministrasjon</a>
        <a class="nav-link" href="#databaser">10. Database</a>
    </nav>
</nav>
</div>

<!-- Innhold -->
<div class="col-lg-9">
<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-1">Teknisk systemdokumentasjon</h2>
        <div class="text-muted small">
            Versjon <?= h($currentVersion) ?> &nbsp;·&nbsp; Oppdatert <?= h($lastUpdated) ?>
            &nbsp;·&nbsp; Intern – ikke for ekstern distribusjon
        </div>
    </div>
</div>

<!-- 1. Systemoversikt -->
<section id="oversikt" class="doc-section mb-5">
<h4 class="border-bottom pb-2">1. Systemoversikt</h4>
<p>
    <strong>Teknisk</strong> er et internt drifts- og administrasjonssystem for teknisk avdeling.
    Systemet samler funksjoner for hendelseshåndtering,
    nettverksdokumentasjon, kontraktsforvaltning, lager, KPI-rapportering og fakturering i én
    felles plattform tilgjengelig via nettleser på internt nett.
</p>
<p>
    Systemet er ikke et offentlig produkt. Det eksponerer ingen data eksternt utover
    definerte API-endepunkter med scoped tokens. All tilgang krever gyldig
    bedriftsinnlogging og 2FA.
</p>

<h6 class="mt-3">Driftsmiljø</h6>
<p>
    Systemet kjører på Haugaland Kraft Fibers egne VM-servere i selskapets egen infrastruktur.
    Plattformen er satt opp med VMware i en HA-konfigurasjon (High Availability), som sikrer
    automatisk failover ved svikt i enkeltservere uten manuell intervensjon. Lagring er løst med
    dedikert SAN (Storage Area Network), som gir høy I/O-ytelse, sentral administrasjon og
    mulighet for øyeblikksbilder og raske gjenopprettinger.
</p>
<p>
    Nettverksinfrastrukturen er bygget med full redundans på alle nivåer: redundante kjerneswitcher
    forhindrer nettverksbrudd ved komponentfeil, og redundante brannmurer sikrer kontinuerlig
    trafikkontroll og tilgangsbegrensning. Internettilkoblingen er løst med to uavhengige
    fiberaksesser der hver aksess går fra sin dedikerte Edge-ruter til sin dedikerte Cisco 920-ruter,
    slik at ingen enkelt komponent i aksessveien er felles feilkilde.
    Alle fysiske komponenter har doble strømforsyninger (PSU) med tilkobling til både UPS og
    aggregat, noe som sikrer kontinuerlig drift ved strømbrudd i kortere og lengre perioder.
    Samlet utgjør dette et robust og feiltolerант driftsmiljø med høy oppetid og minimal
    eksponering mot enkeltpunktsfeil.
</p>
<div class="doc-callout doc-callout-info">
    Systemet håndterer operasjonell informasjon (nettinfrastruktur, kundeavtaler, personaldata)
    og skal behandles i henhold til interne retningslinjer for informasjonssikkerhet.
</div>
</section>

<!-- 2. Teknisk plattform -->
<section id="plattform" class="doc-section mb-5">
<h4 class="border-bottom pb-2">2. Teknisk plattform</h4>

<h6 id="plattform-stack">Applikasjonsstack</h6>
<table class="table table-sm table-bordered mb-3">
    <tbody>
        <tr><th style="width:180px;">Backend</th><td>PHP 8.1+, PSR-4 autoloading (<code>App\</code> namespace)</td></tr>
        <tr><th>Database</th><td>MySQL 8.0 (InnoDB, UTF-8mb4)</td></tr>
        <tr><th>Webserver</th><td>IIS (Windows Server 2019), URL Rewrite → <code>public/index.php</code></td></tr>
        <tr><th>Frontend</th><td>Bootstrap 5, Bootstrap Icons, Chart.js 4</td></tr>
        <tr><th>Avhengigheter</th><td>Composer: phpdotenv, spomky-labs/otphp (TOTP), monolog, symfony/var-dumper</td></tr>
        <tr><th>Kryptering</th><td>AES-256-GCM (OpenSSL) for persondata i DB</td></tr>
    </tbody>
</table>

<h6 id="plattform-infrastruktur">Infrastruktur og konfigurasjon</h6>
<p>
    Konfigurasjon leses fra <code>.env</code> i rotkatalogen via phpdotenv.
    <code>.env</code> skal aldri versjoneres. Nøkkelvariabler:
</p>
<table class="table table-sm table-bordered mb-3">
    <thead class="table-light"><tr><th>Variabel</th><th>Formål</th></tr></thead>
    <tbody>
        <tr><td><code>DB_HOST / DB_NAME / DB_USER / DB_PASS</code></td><td>Databasetilkobling</td></tr>
        <tr><td><code>APP_KEY</code></td><td>AES-256-nøkkel (base64-prefiks) for kryptering av persondata</td></tr>
        <tr><td><code>MAIL_*</code></td><td>SMTP-konfigurasjon for e-postvarsler</td></tr>
        <tr><td><code>JIRA_*</code></td><td>Jira API-credentials for hendelsesintegrasjon</td></tr>
        <tr><td><code>NETBOX_*</code></td><td>NetBox API-nøkkel for nettverksdata</td></tr>
    </tbody>
</table>

<p>
    Entra ID-innstillinger (tenant-ID, client-ID, client secret) lagres kryptert i
    <code>system_settings</code>-tabellen og redigeres via grensesnittet. Client secret
    skal aldri vises i klartekst etter lagring.
</p>

<h6>Mappestruktur (forenklet)</h6>
<pre class="doc-code">
/
├── app/
│   ├── Auth/          – AdLdap, EntraAuth, TwoFaStorage, PasswordReset
│   ├── Support/       – Crypto (AES-256-GCM), env-hjelper
│   ├── Models/        – UserModel
│   ├── Audit.php      – Audit-logger
│   └── Database.php   – PDO singleton
├── public/
│   ├── index.php      – Front controller: routing, auth, 2FA
│   ├── login/         – AD-innlogging, Entra callback, glemt passord
│   ├── pages/         – Sidekontrollere (én fil per side)
│   ├── api/           – REST-endepunkter (token-beskyttet)
│   ├── inc/           – header.php, menu.php, footer.php
│   └── logout.php
├── vendor/            – Composer-avhengigheter
└── .env               – Hemmeligheter (ikke versjonert)
</pre>
</section>

<!-- 3. Autentisering -->
<section id="auth" class="doc-section mb-5">
<h4 class="border-bottom pb-2">3. Autentisering</h4>

<h6 id="auth-ad">3.1 Active Directory (primær)</h6>
<p>
    Primær innloggingsmetode. Brukernavn og passord valideres mot lokal AD
    (<code>bbdrift.ad</code>) via LDAP over TLS (LDAPS, port 636) mot domenekontrollerne
    <code>bb-dc01</code> og <code>bb-dc02</code>. Brukeren må være medlem av AD-gruppen
    <code>teknisk</code> for å få tilgang.
</p>
<ul>
    <li>Innlogging: <code>/login/index.php</code> (POST)</li>
    <li>Klasse: <code>App\Auth\AdLdap</code></li>
    <li>LDAP-binding skjer som brukeren selv (ikke service account) for å verifisere passord</li>
    <li>Gruppemedlemskap valideres mot konfigurerbar gruppe (<code>teknisk</code>)</li>
</ul>

<h6 id="auth-entra">3.2 Microsoft Entra ID / Azure AD (valgfri)</h6>
<p>
    OAuth 2.0 Authorization Code Flow mot Microsoft Entra ID. Aktiveres og konfigureres
    av administrator under <em>Administrasjon → Autentisering</em>. Kan skrus av uten at
    AD-innlogging påvirkes.
</p>
<ul>
    <li>Autorisasjons-URL bygges av <code>App\Auth\EntraAuth</code></li>
    <li>Callback: <code>/login/callback.php</code></li>
    <li>State-parameter (CSRF) valideres i callback</li>
    <li>Entra-brukere trenger ikke 2FA (Microsoft håndterer MFA på sin side)</li>
    <li>Bruker synkroniseres til lokal <code>users</code>-tabell ved første innlogging</li>
</ul>
<div class="doc-callout doc-callout-warning">
    Entra client secret skal roteres i Azure Portal ved kompromittering. Ny verdi lagres
    via autentiserings-innstillingene og skrives aldri til versjonskontroll.
</div>

<h6 id="auth-2fa">3.3 To-faktor-autentisering (2FA / TOTP)</h6>
<p>
    AD-brukere krever alltid TOTP-basert 2FA (RFC 6238, 30-sekunders vindu).
    Implementert med <code>spomky-labs/otphp</code>. Hemmeligheten lagres kryptert
    i <code>users</code>-tabellen.
</p>
<ul>
    <li>Første innlogging etter AD: bruker scanner QR-kode i autentiseringsapp</li>
    <li>Etter vellykket verifisering: <code>$_SESSION['twofa_verified'] = true</code></li>
    <li>Klokkedrift: ±1 tidsvindu akseptert</li>
    <li>Admin kan tilbakestille 2FA for bruker fra brukeradministrasjon (ny oppsett kreves ved neste innlogging)</li>
    <li>Entra-brukere: 2FA bypasses, Microsoft håndterer dette</li>
</ul>

<h6 id="auth-sesjon">3.4 Sesjonsmodell</h6>
<p>Følgende nøkler settes i PHP-sesjonen ved innlogging:</p>
<table class="table table-sm table-bordered mb-3">
    <thead class="table-light"><tr><th>Nøkkel</th><th>Innhold</th></tr></thead>
    <tbody>
        <tr><td><code>username</code></td><td>Brukernavn (lowercase)</td></tr>
        <tr><td><code>fullname</code></td><td>Visningsnavn</td></tr>
        <tr><td><code>auth_provider</code></td><td><code>ad</code> eller <code>entra</code></td></tr>
        <tr><td><code>twofa_verified</code></td><td>Bool – 2FA er fullført denne sesjonen</td></tr>
        <tr><td><code>teknisk</code></td><td><code>'Yes'</code> – bakoverkompatibel tilgangsmarkør</td></tr>
        <tr><td><code>ad_groups</code></td><td>Array med AD-gruppemedlemskap</td></tr>
    </tbody>
</table>
<p>
    <code>session_regenerate_id(true)</code> kalles ved innlogging for å forhindre session fixation.
    Ved utlogging tømmes <code>$_SESSION</code>, cookie invalideres og sesjonen destrueres.
</p>
</section>

<!-- 4. Roller og tilgang -->
<section id="tilgang" class="doc-section mb-5">
<h4 class="border-bottom pb-2">4. Roller og tilgangskontroll</h4>
<p>
    Tilgang styres av rollen-tabellen <code>user_roles</code> (bruker-ID → rolle-streng).
    Roller tildeles manuelt av administrator. En bruker kan ha flere roller.
    Roller er ikke hierarkiske – tilgang sjekkes på <em>har bruker denne rollen?</em>-basis.
</p>
<p>
    For at en konto skal ha tilgang må den i tillegg være aktivert (<code>users.is_active = 1</code>).
    Inaktive kontoer kan logge inn men får kun en «konto ikke aktivert»-melding.
</p>

<table class="table table-sm table-bordered role-table mb-3">
    <thead class="table-light">
        <tr><th>Rolle</th><th>Tilgang til</th></tr>
    </thead>
    <tbody>
        <tr><td><code>admin</code></td><td>Alt. Brukeradministrasjon, systemoppsett, alle moduler, audit-logg, IP-filter.</td></tr>
        <tr><td><code>network</code></td><td>Nettverksdokumentasjon (rutere, grossist, NNI-kunder, L2VPN).</td></tr>
        <tr><td><code>support</code></td><td>Kunderelaterte sider, hendelsesvisning.</td></tr>
        <tr><td><code>report</code></td><td>Lesetilgang til rapporter og oversikter.</td></tr>
        <tr><td><code>contracts_read</code></td><td>Se avtaler og kontrakter (liste + visning).</td></tr>
        <tr><td><code>contracts_write</code></td><td>Opprette og redigere avtaler, varsler og metadata.</td></tr>
        <tr><td><code>events_read</code></td><td>Se hendelser og planlagte jobber.</td></tr>
        <tr><td><code>events_write</code></td><td>Opprette og redigere hendelser, oppdateringer og scopes.</td></tr>
        <tr><td><code>events_publish</code></td><td>Publisere/avpublisere til dashboard, chatbot og API.</td></tr>
        <tr><td><code>node_read</code></td><td>Se feltobjekter og nodelokasjoner.</td></tr>
        <tr><td><code>node_write</code></td><td>Opprette og redigere feltobjekter, maler og felter.</td></tr>
        <tr><td><code>warehouse_read</code></td><td>Se lager, beholdning og bevegelseshistorikk.</td></tr>
        <tr><td><code>warehouse_write</code></td><td>Registrere uttak, mottak, flytt og varetelling.</td></tr>
        <tr><td><code>invoice</code></td><td>Kunderegister (CRM) og fakturafunksjoner.</td></tr>
        <tr><td><code>report_admin</code></td><td>KPI-administrasjon: opprette mål, delegere ansvar.</td></tr>
        <tr><td><code>report_user</code></td><td>Innrapportere KPI-tall og se dashboard.</td></tr>
        <tr><td><code>integration</code></td><td>API-administrasjon og importfunksjoner.</td></tr>
        <tr><td><code>dokumentasjon</code></td><td>Tilgang til denne dokumentasjonssiden.</td></tr>
    </tbody>
</table>

<div class="doc-callout doc-callout-info">
    Alle tilgangssjekker skjer server-side. Klientsiden viser kun menyvalg brukeren har
    tilgang til, men serveren returnerer 403 uavhengig av om lenken er synlig eller ikke.
</div>
</section>

<!-- 5. Sikkerhet -->
<section id="sikkerhet" class="doc-section mb-5">
<h4 class="border-bottom pb-2">5. Sikkerhet</h4>

<h6 id="sikkerhet-kryptering">5.1 Kryptering av persondata</h6>
<p>
    Feltene <code>display_name</code> og <code>email</code> i <code>users</code>-tabellen
    krypteres med AES-256-GCM via klassen <code>App\Support\Crypto</code>.
    Krypterte verdier er prefikset <code>enc:</code> og har formatet:
</p>
<pre class="doc-code">enc:&lt;base64(iv[12] bytes] + tag[16 bytes] + ciphertext)&gt;</pre>
<p>
    Nøkkelen leses fra <code>APP_KEY</code> i <code>.env</code>. Dersom legacy-verdier
    (uten prefiks) finnes, leses de i klartekst og krypteres neste gang de oppdateres.
    TOTP-hemmeligheter lagres separert i <code>users.twofa_secret</code> og krypteres
    på samme måte.
</p>

<h6 id="sikkerhet-ip">5.2 IP-filter (allowlist)</h6>
<p>
    Systemet støtter IP-basert aksessfilter administrert under
    <em>Administrasjon → Sikkerhet</em>. Filteret er av typen allowlist:
    kun eksplisitt opplistede IP-er eller CIDR-subnett slipper gjennom.
</p>
<ul>
    <li>Sjekkes tidlig i <code>public/index.php</code>, før autentisering</li>
    <li>Kan aktiveres/deaktiveres uten å slette regler</li>
    <li>Støtter IPv4 og IPv6, både enkelt-IP og CIDR-notasjon</li>
    <li>Blokkerte forespørsler logges i <code>security_ip_log</code> med IP, tidspunkt, side og user-agent</li>
    <li>GeoIP-oppslagskilde: ip-api.com (batch, opptil 100 IP-er per kall)</li>
    <li>Fail-open ved DB-feil: IP-filter deaktiveres midlertidig fremfor å blokkere hele appen</li>
</ul>
<div class="doc-callout doc-callout-danger">
    Aktiver IP-filter kun etter at egen IP er lagt til i allowlisten.
    En aktiv regel uten din IP vil låse deg ut øyeblikkelig.
</div>

<h6 id="sikkerhet-audit">5.3 Audit-logg</h6>
<p>
    Alle sikkerhetsrelevante hendelser logges i tabellen <code>audit_log</code> med
    tidspunkt, bruker, IP, hendelsestype, alvorlighetsgrad og valgfrie before/after-verdier.
    Loggen er kun tilgjengelig for administratorer under <em>Administrasjon → Audit-logg</em>.
</p>
<p>Loggede hendelseskategorier:</p>
<table class="table table-sm table-bordered mb-3">
    <thead class="table-light"><tr><th>Kategori</th><th>Hendelser</th></tr></thead>
    <tbody>
        <tr>
            <td><code>auth</code></td>
            <td>Innlogging (AD/Entra, success/failure), utlogging, 2FA verifisert/satt opp, passordbytte, blokkert inaktiv konto</td>
        </tr>
        <tr>
            <td><code>user_mgmt</code></td>
            <td>Bruker aktivert/deaktivert, slettet, 2FA tilbakestilt, roller endret (med old/new-verdi)</td>
        </tr>
        <tr>
            <td><code>security</code></td>
            <td>IP-filter aktivert/deaktivert, regler lagt til/slettet/toggled, IP tillatt fra blokklogg</td>
        </tr>
        <tr>
            <td><code>system</code></td>
            <td>Entra ID-konfigurasjonsendringer</td>
        </tr>
    </tbody>
</table>
<p>
    E-postvarsler kan konfigureres per alvorlighetsgrense (advarsel eller kritisk) fra
    audit-logg-siden. Varsler sendes via <code>mail()</code> med avsender <code>no-reply@hkbb.no</code>.
    Gamle loggposter kan tømmes manuelt med konfigurerbar aldergrense.
</p>

<h6 id="sikkerhet-passord">5.4 Passordbytte</h6>
<p>
    AD-brukere kan endre sitt eget passord under <em>Min side → Bytt passord</em>.
    Passordet endres direkte i AD via LDAPS med metoden
    <code>unicodePwd DELETE+ADD</code> (RFC-kompatibel, ikke <code>replace</code>
    som krever Domain Admin-rettigheter).
</p>
<ul>
    <li>CSRF-token brukes for å beskytte skjemaet</li>
    <li>Minimumslengde og kompleksitetskrav valideres lokalt</li>
    <li>Gammelt passord verifiseres via LDAP-binding</li>
    <li>Hendelsen logges i audit-loggen</li>
</ul>
<p>
    Glemt passord håndteres via <code>/login/forgot.php</code> og
    <code>App\Auth\PasswordReset</code> som sender en engangslenke på e-post.
</p>
</section>

<!-- 6. Moduler -->
<section id="moduler" class="doc-section mb-5">
<h4 class="border-bottom pb-2">6. Moduler</h4>

<h6 id="mod-dashboard">6.1 Dashboard (Oversikt)</h6>
<p>
    Startside etter innlogging. Viser aktive hendelser, kommende planlagte jobber og
    relevante varsler basert på brukerens roller. Ingen konfigurasjon kreves.
</p>

<h6 id="mod-hendelser" class="mt-4">6.2 Hendelser & Endringer</h6>
<p>
    Modul for registrering og oppfølging av driftshendelser, planlagte arbeider
    og endringer i nettverket. Erstatter manuell e-postkommunikasjon rundt nedetid
    og planlagte arbeider.
</p>
<p><strong>Nøkkelfunksjoner:</strong></p>
<ul>
    <li>Statuser: Utkast → Planlagt → Under arbeid → Overvåking → Utført / Avbrutt</li>
    <li>Automatisk statusovergang: scheduler-script kjøres periodisk og setter status basert på <code>schedule_start</code>/<code>schedule_end</code></li>
    <li>Berørte systemer: strukturert scope med type og ID (f.eks. leveransepunkt, lokasjon, kunde)</li>
    <li>Oppdateringer: tidsstempled logg av hendelsesforløp</li>
    <li>Hendelseskategorier: Planlagt arbeid, Alvorlig feil, Advarsel, Informasjon, Endring</li>
    <li>Publisering: flagg for distribusjon til ekstern dashboard, chatbot (Hkon) og kundesenter</li>
    <li>Kartvisning: hendelser med geografisk scope kan vises på kart</li>
</ul>
<p><strong>Jira-integrasjon:</strong></p>
<ul>
    <li>Hendelser kan opprettes som Jira-saker automatisk (scoped Atlassian API-token)</li>
    <li>Dato-felt, kommentarer og auto-tildeling synkroniseres</li>
    <li>Hendelsestype mappes til Jira-issue-type</li>
</ul>
<p><strong>Tilgang:</strong> <code>events_read</code> (les), <code>events_write</code> (skriv), <code>events_publish</code> (publiser)</p>

<h6 id="mod-kontrakter" class="mt-4">6.3 Avtaler & Kontrakter</h6>
<p>
    Forvaltning av leverandør- og kundeavtaler. Ingen avtaletekst lagres i systemet –
    kun metadata for oversikt, frister og varsling.
</p>
<ul>
    <li>Felt: avtalenavn, type, motpart, start/slutt-dato, kontaktperson, notater, status</li>
    <li>Automatiske e-postvarsler ved utløp: konfigureres per avtale med antall dager før utløp</li>
    <li>Varselscript (<code>scripts/contracts_send_alerts.php</code>) kjøres som planlagt jobb (cron/Task Scheduler)</li>
    <li>Aktivitetslogg per avtale</li>
    <li>Endringslogg med before/after for alle feltendringer</li>
</ul>
<p><strong>Tilgang:</strong> <code>contracts_read</code>, <code>contracts_write</code></p>

<h6 id="mod-kpi" class="mt-4">6.4 Mål & KPI</h6>
<p>
    Rapporteringsmodul for månedlige nøkkeltall. Støtter delegering av innrapportering
    til ansvarlige.
</p>
<ul>
    <li>KPI-er administreres av brukere med rollen <code>report_admin</code></li>
    <li>KPI-er kan delegeres til spesifikke brukere (<code>report_user</code>)</li>
    <li>Innrapportering per måned med fargekoding mot mål (grønn/gul/rød)</li>
    <li>Dashboard: trend-graf, måloppnåelse og historikk</li>
</ul>
<p><strong>Tilgang:</strong> <code>report_admin</code> (administrer), <code>report_user</code> (rapporter og les)</p>

<h6 id="mod-nettverk" class="mt-4">6.5 Nettverksdokumentasjon</h6>
<p>
    Strukturert oversikt over nettverkselementer fra NetBox (synkronisert) og
    lokalt registrerte elementer.
</p>
<ul>
    <li><strong>Aksess-rutere:</strong> kundetilknytningspunkter med konfigurasjon</li>
    <li><strong>Edge-rutere:</strong> kjernenettverks-elementer</li>
    <li><strong>Service-rutere:</strong> tjenesteelementer</li>
    <li><strong>NNI-kunder:</strong> nettverksnode-grensesnitt mot grossister og samkjøringspartnere</li>
    <li><strong>L2VPN-kretser:</strong> Layer 2-kretser per kunde</li>
    <li><strong>Grossistaksess:</strong> grossistleverandøroversikt</li>
    <li><strong>Kundekonfigurasjoner:</strong> per-kunde kretskonfigurasjon</li>
</ul>
<p><strong>NetBox-integrasjon:</strong> Data hentes periodisk fra NetBox via REST API med <code>NETBOX_TOKEN</code> fra <code>.env</code>.</p>
<p><strong>Tilgang:</strong> <code>network</code></p>

<h6 id="mod-feltobjekter" class="mt-4">6.6 Feltobjekter (Nodelokasjoner)</h6>
<p>
    Dokumentasjon av fysisk nettinfrastruktur: skap, kummer, trekkerør, kabler og
    annet utstyr i felt. Støtter maler med brukerdefinerte felt.
</p>
<ul>
    <li>Strukturert med <em>maler</em>: en mal definerer hvilke felt et objekt av en type har</li>
    <li>Felt-typer: tekst, tall, dato, nedtrekksliste, avkrysning, koordinater</li>
    <li>Vedlegg: bilder og dokumenter per objekt</li>
    <li>Kartvisning (bildekart) for geografisk plassering</li>
    <li>Reverse geocoding av koordinater</li>
    <li>Separat mobiloptimalisert app under <code>/app/nodelokasjon/</code> for feltarbeid</li>
    <li>API-endepunkt: <code>GET /api/field_objects/</code> (scope: <code>field_objects:read</code>)</li>
</ul>
<p><strong>Tilgang:</strong> <code>node_read</code>, <code>node_write</code>, <code>feltobjekter_les</code>, <code>feltobjekter_skriv</code></p>

<h6 id="mod-logistikk" class="mt-4">6.7 Logistikk & Varelager</h6>
<p>
    Lagerstyring for teknisk utstyr, reservedeler og forbruksmateriell. Støtter
    uttak, mottak, flytt og varetelling.
</p>
<ul>
    <li>Produktregisteret med kategorier og lagerlokasjoner</li>
    <li>Bevegelseslogg: alle inn/ut/flytt med tidspunkt og bruker</li>
    <li>Uttaksfunksjon: kobling mot arbeidsordre og prosjekt</li>
    <li>Uttaksbutikk: forenklet grensesnitt for egenregistrering</li>
    <li>Varetelling: syklisk telling med avviksrapport</li>
    <li>Rapporter: uttakshistorikk per bruker/prosjekt/periode</li>
    <li>Separat lagersystem med egen innlogging under <code>/lager/</code> for eksternt bruk</li>
    <li>Prosjekter og arbeidsordrer knyttet til lageruttak</li>
</ul>
<p><strong>Tilgang:</strong> <code>warehouse_read</code>, <code>warehouse_write</code></p>

<h6 id="mod-crm" class="mt-4">6.8 CRM & Fakturering</h6>
<p>
    Enkelt kunderegister og fakturagrunnlag for tekniske tjenester.
</p>
<ul>
    <li><strong>Kunder & partnere:</strong> kontaktregister med org.nr, adresse, kontaktpersoner og notater</li>
    <li><strong>Fakturagrunnlag:</strong> linjeposter, satser og utskrift til PDF-vennlig format</li>
    <li>Fakturaarkiv med status (utkast/sendt/betalt)</li>
</ul>
<p><strong>Tilgang:</strong> <code>invoice</code></p>
</section>

<!-- 7. API -->
<section id="api" class="doc-section mb-5">
<h4 class="border-bottom pb-2">7. API</h4>
<p>
    Systemet eksponerer et REST API for maskin-til-maskin-integrasjoner.
    Alle endepunkter krever et Bearer-token i <code>Authorization</code>-headeren.
    Tokens administreres av admin under <em>Administrasjon → API</em>.
</p>

<h6>Autentisering</h6>
<pre class="doc-code">Authorization: Bearer &lt;token&gt;</pre>
<p>
    Tokens lagres som SHA-256-hash i databasen. Klartekst-token vises kun én gang
    ved opprettelse. Tokens kan aktiveres/deaktiveres uten sletting.
</p>

<h6>Tilgjengelige endepunkter</h6>
<table class="table table-sm table-bordered mb-3">
    <thead class="table-light">
        <tr><th>Metode</th><th>Sti</th><th>Scope</th><th>Beskrivelse</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><span class="badge bg-success">GET</span></td>
            <td><code>/api/events</code></td>
            <td><code>events:read</code></td>
            <td>Hendelser (aktive, planlagte, nylige). Støtter <code>mode</code>, <code>limit</code>, <code>id</code>-parameter.</td>
        </tr>
        <tr>
            <td><span class="badge bg-success">GET</span></td>
            <td><code>/api/events/public</code></td>
            <td><code>events:read</code></td>
            <td>Offentlige hendelser filtrert på målgruppe (leveransepunkt, postnummer, gate).</td>
        </tr>
        <tr>
            <td><span class="badge bg-success">GET</span></td>
            <td><code>/api/events?mode=address_lookup</code></td>
            <td><code>events:read</code></td>
            <td>Adressebasert hendelseoppslag for chatbot-integrasjon.</td>
        </tr>
        <tr>
            <td><span class="badge bg-success">GET</span></td>
            <td><code>/api/field_objects/</code></td>
            <td><code>field_objects:read</code></td>
            <td>Feltobjekter og nodelokasjoner med feltdata.</td>
        </tr>
        <tr>
            <td><span class="badge bg-success">GET</span></td>
            <td><code>/api/node_locations</code></td>
            <td>Token</td>
            <td>Nodelokasjoner (legacy-format).</td>
        </tr>
    </tbody>
</table>
<p>
    API-svar er JSON med UTF-8-koding. Feilsvar følger strukturen
    <code>{"error": "...", "error_description": "..."}</code> med passende HTTP-statuskode.
</p>
</section>

<!-- 8. Integrasjoner -->
<section id="integrasjoner" class="doc-section mb-5">
<h4 class="border-bottom pb-2">8. Integrasjoner</h4>

<h6>Jira (Atlassian)</h6>
<p>
    Hendelser kan opprettes og oppdateres som Jira-saker via Atlassian REST API.
    Tilkoblingsinformasjon (<code>JIRA_URL</code>, <code>JIRA_TOKEN</code>, <code>JIRA_PROJECT</code>)
    lagres i <code>.env</code>. Synkroniseringen er hendelsesdrevet (ikke periodisk polling).
</p>
<ul>
    <li>Dato-felt: <code>actual_start</code>/<code>actual_end</code> synkroniseres til custom Jira-felt</li>
    <li>Kommentarer legges til automatisk ved statusendring</li>
    <li>Issue type velges basert på hendelseskategori</li>
    <li>Auto-tildeling: konfigurerbar</li>
    <li>Atlassian API-gateway URL brukes med scoped token (ikke legacy basic auth)</li>
</ul>

<h6 class="mt-3">NetBox</h6>
<p>
    Nettverksdata hentes fra NetBox via REST API med token fra <code>.env</code>.
    Synkroniseringen hentes ved visning og caches ikke lokalt.
    Brukes primært for site-data og ruteroppføringer.
</p>

<h6 class="mt-3">GeoIP (ip-api.com)</h6>
<p>
    IP-filter-modulen bruker ip-api.com for batch-oppslag av land for blokkerte IP-er.
    Brukes kun for informasjonsvisning, ikke for tilgangskontroll.
    Maks 100 IP-er per kall, ingen API-nøkkel kreves (gratis tier).
</p>

<h6 class="mt-3">Hkon (chatbot)</h6>
<p>
    Kundesenter-chatboten Hkon integrerer mot Events API for å hente aktive driftsmeldinger
    relevante for en kundes adresse. Chatboten bruker scope <code>events:read</code>
    med et dedikert API-token.
</p>

<h6 class="mt-3">Eksternt dashboard</h6>
<p>
    Hendelser merket som <em>publiser til dashboard</em> eksponeres via det offentlige
    Events API-endepunktet. Dashboardet leser fra dette endepunktet.
</p>
</section>

<!-- 9. Systemadministrasjon -->
<section id="admin" class="doc-section mb-5">
<h4 class="border-bottom pb-2">9. Systemadministrasjon</h4>

<table class="table table-sm table-bordered mb-3">
    <thead class="table-light"><tr><th>Side</th><th>Funksjon</th><th>Tilgang</th></tr></thead>
    <tbody>
        <tr>
            <td>Administrasjon → Brukere</td>
            <td>Oversikt over alle brukere. Aktiver/deaktiver konto, tilbakestill 2FA, slett bruker.</td>
            <td><code>admin</code></td>
        </tr>
        <tr>
            <td>Administrasjon → Rediger bruker</td>
            <td>Tildel og fjern roller. Koble til Jira-konto.</td>
            <td><code>admin</code></td>
        </tr>
        <tr>
            <td>Administrasjon → Sikkerhet</td>
            <td>IP-filter: aktiver/deaktiver, administrer allowlist-regler, se blokklogg med GeoIP.</td>
            <td><code>admin</code></td>
        </tr>
        <tr>
            <td>Administrasjon → IIS IP-filter</td>
            <td>Visning av IIS-baserte IP-regler (separat fra applikasjonsfilteret).</td>
            <td><code>admin</code></td>
        </tr>
        <tr>
            <td>Administrasjon → API</td>
            <td>Opprette, deaktivere og slette API-tokens. Tildel scopes per token. Visning av API-dokumentasjon.</td>
            <td><code>admin</code></td>
        </tr>
        <tr>
            <td>Administrasjon → Autentisering</td>
            <td>Konfigurer Entra ID (tenant, client ID, redirect URI, client secret). AD er alltid aktiv.</td>
            <td><code>admin</code></td>
        </tr>
        <tr>
            <td>Administrasjon → Audit-logg</td>
            <td>Filtrerbar logg over alle sikkerhets- og systemhendelser. Varslingskonfig. CSV-eksport.</td>
            <td><code>admin</code></td>
        </tr>
        <tr>
            <td>Min side</td>
            <td>Tema-valg, kontoinfo, bytt passord (AD-brukere).</td>
            <td>Alle innloggede</td>
        </tr>
    </tbody>
</table>

<h6>Planlagte jobber (bakgrunnsoppgaver)</h6>
<table class="table table-sm table-bordered mb-3">
    <thead class="table-light"><tr><th>Script</th><th>Funksjon</th><th>Anbefalt frekvens</th></tr></thead>
    <tbody>
        <tr>
            <td><code>scripts/contracts_send_alerts.php</code></td>
            <td>Sender e-postvarsler for kontrakter som nærmer seg utløp.</td>
            <td>Daglig (natt)</td>
        </tr>
        <tr>
            <td>Hendelse-autostatusoppdatering</td>
            <td>Setter hendelsers status til <em>Under arbeid</em> eller <em>Utført</em> basert på tidsskjema.
                Kjøres via AJAX i frontend av brukere med <code>events_write</code>-tilgang.</td>
            <td>Kontinuerlig (polling)</td>
        </tr>
    </tbody>
</table>
</section>

<!-- 10. Database -->
<section id="databaser" class="doc-section mb-5">
<h4 class="border-bottom pb-2">10. Database</h4>
<p>
    MySQL 8.0, database <code>teknisk</code>. Alle tabeller bruker InnoDB og UTF-8mb4.
    Schema vedlikeholdes via <code>database.sql</code> (mysqldump-format) i versjonskontroll.
    Migrations kjøres som inline <code>CREATE TABLE IF NOT EXISTS</code> / <code>ALTER TABLE</code>
    i relevante sidekontrollere ved første kjøring.
</p>
<p>Hoveddtabeller:</p>
<table class="table table-sm table-bordered mb-3">
    <thead class="table-light"><tr><th>Tabell</th><th>Innhold</th></tr></thead>
    <tbody>
        <tr><td><code>users</code></td><td>Brukere (kryptert display_name og email, 2FA-felt)</td></tr>
        <tr><td><code>user_roles</code></td><td>Mange-til-mange: bruker → rolle</td></tr>
        <tr><td><code>audit_log</code></td><td>Audit-hendelser (kategori, type, alvorlighet, actor, IP, target, old/new-verdi)</td></tr>
        <tr><td><code>system_settings</code></td><td>Nøkkel/verdi-konfigurasjon (Entra ID, audit-varsler)</td></tr>
        <tr><td><code>api_tokens</code></td><td>API-tokens (SHA-256 hash, scopes, aktiv/inaktiv)</td></tr>
        <tr><td><code>events</code></td><td>Hendelser og endringer</td></tr>
        <tr><td><code>event_updates</code></td><td>Oppdateringslogg per hendelse</td></tr>
        <tr><td><code>event_scopes</code></td><td>Berørte systemer per hendelse</td></tr>
        <tr><td><code>event_integrations</code></td><td>Jira-koblinger per hendelse</td></tr>
        <tr><td><code>contracts</code></td><td>Avtaler og kontrakter</td></tr>
        <tr><td><code>contracts_alert_log</code></td><td>Logg over sendte kontraktvarsler</td></tr>
        <tr><td><code>node_locations</code></td><td>Feltobjekter</td></tr>
        <tr><td><code>node_location_templates</code></td><td>Maler for feltobjekter</td></tr>
        <tr><td><code>security_ip_settings</code></td><td>IP-filter aktivert/deaktivert</td></tr>
        <tr><td><code>security_ip_allowlist</code></td><td>Tillatte IP-er og CIDR-subnett</td></tr>
        <tr><td><code>security_ip_log</code></td><td>Logg over blokkerte forespørsler</td></tr>
        <tr><td><code>kpi_*</code></td><td>KPI-mål, avdelinger, perioder og innrapporterte tall</td></tr>
    </tbody>
</table>

<div class="doc-callout doc-callout-warning">
    <code>database.sql</code> inneholder ikke Entra client secret (fjernet fra historikken).
    Persondata (display_name, email) i dumps er kryptert og kan ikke leses uten <code>APP_KEY</code>.
</div>
</section>

<div class="text-muted small border-top pt-3 mt-2">
    Teknisk systemdokumentasjon · HKBB intern · Versjon <?= h($currentVersion) ?> · <?= h($lastUpdated) ?>
</div>

</div><!-- /col -->
</div><!-- /row -->

<script>
// Aktiver scrollspy for sidemeny
document.addEventListener('DOMContentLoaded', function () {
    var sections = document.querySelectorAll('.doc-section, [id]');
    var links    = document.querySelectorAll('#docNav .nav-link');

    function onScroll() {
        var scrollY = window.scrollY + 100;
        var current = '';
        sections.forEach(function (s) {
            if (s.id && s.offsetTop <= scrollY) current = s.id;
        });
        links.forEach(function (l) {
            var href = l.getAttribute('href');
            l.classList.toggle('active', href === '#' + current);
        });
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
});
</script>
