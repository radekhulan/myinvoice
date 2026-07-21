<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailProfileRepository;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\Branding\AccentColor;
use MyInvoice\Service\Signing\Email\EmailSigningService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\Auth\CramMd5Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\Auth\PlainAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Wrapper nad Symfony Mailer + Twig pro renderování šablon.
 *
 * Použití:
 *   $mailer->sendTemplate('password_reset', 'cs', ['user@example.com'], ['name' => 'Jan Novák', 'resetLink' => '...']);
 *
 * Šablony jsou v api/templates/email/<code>.<lang>.{html,txt}.twig.
 */
final class Mailer
{
    private ?SymfonyMailer $mailer = null;
    private mixed $transport = null;
    /** @var array<string,TransportInterface> */
    private array $profileTransports = [];
    private ?Environment $twig = null;
    private ?array $supplierFooter = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly Connection $db,
        private readonly EmailTemplateRepository $templates,
        private readonly ?EmailSigningService $emailSigning = null,
        private readonly ?EmailProfileRepository $emailProfiles = null,
        private readonly ?SentMailAppenderInterface $sentMailImap = null,
    ) {}

    /**
     * @param string[]      $to
     * @param array<string,mixed> $vars
     * @param string[]      $cc
     * @param string[]      $bcc
     * @param array<int,array{path:string,name:string,contentType:string}> $attachments
     * @param ?int          $userId Přihlášený uživatel pro výběr user podpisového profilu.
     * @param array<string,mixed>|null $emailProfileOverride Explicitní profil pro test konfigurace.
     * @return string Krátký SMTP server response z poslední odpovědi (např.
     *               „250 2.0.0 Ok: queued as ABCDEF"). Plný transcript jde
     *               do log/myinvoice-*.log na úrovni info.
     */
    public function sendTemplate(
        string $code,
        string $locale,
        array $to,
        array $vars,
        ?string $subjectOverride = null,
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
        ?int $userId = null,
        ?array $emailProfileOverride = null,
    ): string {
        try {
            return $this->sendTemplateDetailed(
                $code,
                $locale,
                $to,
                $vars,
                $subjectOverride,
                $cc,
                $bcc,
                $attachments,
                $userId,
                $emailProfileOverride,
            )['smtp_response'];
        } catch (MailDeliveredArchiveException $e) {
            return $e->smtpResponse();
        }
    }

    /**
     * @param string[]      $to
     * @param array<string,mixed> $vars
     * @param string[]      $cc
     * @param string[]      $bcc
     * @param array<int,array{path:string,name:string,contentType:string}> $attachments
     * @param ?int          $userId Přihlášený uživatel pro výběr user podpisového profilu.
     * @param array<string,mixed>|null $emailProfileOverride Explicitní profil pro test konfigurace.
     * @return array{
     *   smtp_response:string,
     *   imap_append:array{status:'skipped'|'saved'|'failed',folder:?string,error:?string}
     * }
     */
    public function sendTemplateDetailed(
        string $code,
        string $locale,
        array $to,
        array $vars,
        ?string $subjectOverride = null,
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
        ?int $userId = null,
        ?array $emailProfileOverride = null,
    ): array {
        $twig = $this->twig();

        $vars['locale'] = $locale;
        if (!isset($vars['supplier'])) {
            $vars['supplier'] = $this->loadSupplierFooter();
        }
        // Pre-compute display dimensions pro logo (HTML width/height attributy
        // — email klienti respektují líp než CSS max-height, viz Outlook).
        if (is_array($vars['supplier'] ?? null)) {
            $vars['supplier'] = $this->addLogoDisplaySize($vars['supplier']);
        }

        // QR platba: generátor vrací `data:image/png;base64,…` URI. Gmail, Outlook
        // a další klienti ale blokují `data:` URI v `<img src>` (issue #51 — QR
        // se na faktuře v PDF/webu zobrazí, v emailu ne). Řešením je inline CID
        // attachment — stejně jako supplier logo. Dekódujeme bytes, přepíšeme var
        // na `cid:qr_payment` (šablony používají `<img src="{{ qr_data_uri }}">`)
        // a vlastní embed proběhne po vytvoření $email níže.
        $qrEmbed = null;
        if (!empty($vars['qr_data_uri']) && is_string($vars['qr_data_uri'])) {
            $qrEmbed = $this->decodeDataUri($vars['qr_data_uri']);
            if ($qrEmbed !== null) {
                $vars['qr_data_uri'] = 'cid:qr_payment';
            }
        }

        // Pokud je v DB override, vyrenderuj přímo ze stringu (vyšší priorita než file).
        $dbTpl = $this->templates->find($code, $locale)
              ?? $this->templates->find($code, 'cs');

        if ($dbTpl !== null) {
            // DB šablona je editovatelná adminem — sandboxujeme proti SSTI
            $sandbox = $this->sandboxedTwig();
            $subjectTemplate = $subjectOverride ?? $dbTpl['subject'];
            $vars['subject'] = $sandbox->createTemplate($subjectTemplate)->render($vars);
            $html = $sandbox->createTemplate($dbTpl['body_html'])->render($vars);
            $text = $sandbox->createTemplate($dbTpl['body_text'])->render($vars);
        } else {
            $htmlTemplate = "{$code}.{$locale}.html.twig";
            $textTemplate = "{$code}.{$locale}.txt.twig";
            if (!$twig->getLoader()->exists($htmlTemplate)) {
                $htmlTemplate = "{$code}.cs.html.twig";
                $textTemplate = "{$code}.cs.txt.twig";
            }
            if (!isset($vars['subject'])) {
                $vars['subject'] = $subjectOverride ?? $this->defaultSubject($code, $locale);
            }
            $html = $twig->render($htmlTemplate, $vars);
            $text = $twig->render($textTemplate, $vars);
        }

        // From: per-supplier override (vars['supplier'].email + display_name) > globální cfg
        $globalFromEmail = (string) $this->config->get('smtp.from_email');
        $globalFromName  = (string) $this->config->get('smtp.from_name');
        $supplier = is_array($vars['supplier'] ?? null) ? $vars['supplier'] : null;
        $emailProfile = $emailProfileOverride ?? $this->defaultEmailProfile($supplier);
        $fromName = $globalFromName;
        if ($supplier !== null) {
            $supName = (string) ($supplier['display_name'] ?? $supplier['company_name'] ?? '');
            if ($supName !== '') $fromName = $supName;
        }
        $fromEmail = $globalFromEmail;
        if ($emailProfile !== null) {
            $fromEmail = (string) $emailProfile['from_email'];
            $profileFromName = trim((string) ($emailProfile['from_name'] ?? ''));
            if ($profileFromName !== '') {
                $fromName = $profileFromName;
            }
        }

        $email = (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->subject((string) $vars['subject'])
            ->html($html)
            ->text($text);

        // Per-supplier branding logo jako CID inline image — je-li `email_branding_enabled`
        // a logo soubor existuje. Twig používá `cid:supplier_logo` jako image src.
        if ($supplier !== null
            && !empty($supplier['email_branding_enabled'])
            && !empty($supplier['logo_path'])
            && !empty($supplier['id'])
        ) {
            // SafeLogoPath: defense-in-depth proti LFI přes podstrčený logo_path
            // (security report @andrejtomci #2). Resolve vrátí null pokud cesta
            // neukazuje do storage/supplier-logos/sup-{id}.{png|svg|...}.
            $logoAbs = SafeLogoPath::resolve((string) $supplier['logo_path'], (int) $supplier['id']);
            if ($logoAbs !== null) {
                $email->embedFromPath($logoAbs, 'supplier_logo', 'image/png');
            }
        }

        // QR platba jako inline CID image (viz výše, issue #51).
        if ($qrEmbed !== null) {
            $email->embed($qrEmbed['bytes'], 'qr_payment', $qrEmbed['contentType']);
        }

        foreach ($to as $addr)  $email->addTo($addr);
        foreach ($cc as $addr)  $email->addCc($addr);
        foreach ($bcc as $addr) $email->addBcc($addr);

        // Reply-To: email profile controls its own fallback. Without profile:
        // supplier.email > cfg.smtp.reply_to_email.
        $replyEmail = '';
        $replyName  = '';
        if ($emailProfile !== null) {
            if (($emailProfile['reply_to_enabled'] ?? false)
                && !empty($emailProfile['reply_to_email'])
                && filter_var($emailProfile['reply_to_email'], FILTER_VALIDATE_EMAIL)
            ) {
                $replyEmail = (string) $emailProfile['reply_to_email'];
                $replyName = (string) ($emailProfile['reply_to_name'] ?? '');
            }
        } elseif ($supplier !== null
            && !empty($supplier['reply_to'])
            && filter_var($supplier['reply_to'], FILTER_VALIDATE_EMAIL)
        ) {
            $replyEmail = (string) $supplier['reply_to'];
            $replyName = (string) ($supplier['display_name'] ?? $supplier['company_name'] ?? '');
        } elseif ($supplier !== null && !empty($supplier['email']) && filter_var($supplier['email'], FILTER_VALIDATE_EMAIL)) {
            $replyEmail = (string) $supplier['email'];
            $replyName  = (string) ($supplier['display_name'] ?? $supplier['company_name'] ?? '');
        } else {
            $replyEmail = (string) $this->config->get('smtp.reply_to_email', '');
            $replyName  = (string) $this->config->get('smtp.reply_to_name', '');
        }
        if ($replyEmail !== '') {
            $email->replyTo(new Address($replyEmail, $replyName));
        }

        foreach ($attachments as $att) {
            $email->attachFromPath($att['path'], $att['name'], $att['contentType']);
        }

        // POZOR (Bcc + DKIM/S/MIME): obálku (MAIL FROM + RCPT TO) musíme zachytit
        // z PŮVODNÍHO $email TEĎ, dokud má ještě Bcc hlavičku. Symfony `DkimSigner`
        // i finální send totiž obálku jinak odvozují z `getPreparedHeaders()`, které
        // Bcc záměrně odstraňují (RFC — Bcc nesmí být ve viditelných hlavičkách).
        // Bez explicitní obálky se Bcc příjemci tiše ztratí z RCPT TO (potvrzeno
        // logem hMailServeru: BCC self-kopie nikdy nedorazila). `Envelope::create`
        // čte To+Cc+Bcc z `getHeaders()` — sender+recipients snapshotneme eagerně do
        // konkrétní obálky, protože podpis níže $email nahradí novou instancí.
        $snapshot = Envelope::create($email);
        $envelope = new Envelope($snapshot->getSender(), $snapshot->getRecipients());

        if ($this->emailSigning !== null) {
            $email = $this->emailSigning->signIfEnabled(
                $email,
                $code,
                $supplier,
                $userId,
                $emailProfile !== null ? ($emailProfile['signing_profile_id'] ?? null) : null,
            );
        }

        // DKIM signer
        if ($this->config->get('smtp.dkim.enabled', false)) {
            $keyPath = (string) $this->config->get('smtp.dkim.private_key_path', '');
            $globalDkimDomain = (string) $this->config->get('smtp.dkim.domain');
            $globalDkimSelector = (string) $this->config->get('smtp.dkim.selector');

            $profileDkimDomain = $emailProfile !== null ? (string) ($emailProfile['dkim_domain'] ?? '') : '';
            $profileDkimSelector = $emailProfile !== null ? (string) ($emailProfile['dkim_selector'] ?? '') : '';
            $profileDkimEnabled = $emailProfile !== null
                && ($emailProfile['dkim_enabled'] ?? false)
                && $profileDkimDomain !== ''
                && $profileDkimSelector !== '';

            if ($profileDkimEnabled) {
                // Profil má vlastní DKIM identitu → použij ji.
                $dkimDomain = $profileDkimDomain;
                $dkimSelector = $profileDkimSelector;
                $dkimEnabled = true;
            } else {
                // Profil bez vlastního DKIM (nebo žádný profil) → globální DKIM, ale
                // jen když doména From odpovídá globální DKIM doméně (jinak by podpis
                // neseděl). Tím profil vytvořený jen kvůli custom From na STEJNÉ doméně
                // nepřijde o DKIM (jinak SPF/DMARC fail → spam/odmítnutí).
                $dkimDomain = $globalDkimDomain;
                $dkimSelector = $globalDkimSelector;
                $fromDomain = $this->fromDomain($email);
                $dkimEnabled = $dkimDomain !== '' && $dkimSelector !== ''
                    && ($emailProfile === null
                        || ($fromDomain !== null && strcasecmp($fromDomain, $dkimDomain) === 0));
            }

            if ($dkimEnabled && is_file($keyPath)) {
                $signer = new DkimSigner(
                    'file://' . $keyPath,
                    $dkimDomain,
                    $dkimSelector,
                    [],
                    (string) $this->config->get('smtp.dkim.passphrase', ''),
                );
                $email = $signer->sign($email);
            } elseif ($dkimEnabled) {
                $this->logger->warning('DKIM enabled, ale private key neexistuje: ' . $keyPath);
            }
        }

        // POZOR: high-level `Symfony\Component\Mailer\Mailer::send()` vrací void
        // (od 5.x). Pro získání SentMessage s debug transcriptem musíme volat
        // transport->send() napřímo. Stejný transport instance jako $this->mailer().
        $transport = $this->transport($emailProfile);
        try {
            $sent = $transport->send($email, $envelope);
        } finally {
            if (!$this->keepaliveEnabled($emailProfile) && method_exists($transport, 'stop')) {
                $transport->stop();
            }
        }
        $debug = $sent !== null ? $sent->getDebug() : '';
        $smtpResponse = $this->extractLastServerResponse($debug);
        $imapAppend = $this->sentMailImap !== null
            ? $this->sentMailImap->appendIfEnabled($emailProfile, $this->rawMessageForImap($sent, $email))
            : ['status' => 'skipped', 'folder' => null, 'error' => null];

        if ($imapAppend['status'] === 'failed') {
            $this->logger->warning('mail.imap_sent_append_failed', [
                'template' => $code,
                'email_profile' => $emailProfile !== null ? ($emailProfile['code'] ?? null) : null,
                'folder' => $imapAppend['folder'],
                'error' => $imapAppend['error'],
            ]);
        }

        $this->logger->info('mail.sent', [
            'template'      => $code,
            'locale'        => $locale,
            'email_profile' => $emailProfile !== null ? ($emailProfile['code'] ?? null) : null,
            'to'            => $to,
            'cc'            => $cc,
            'bcc'           => $bcc,
            'attachments'   => count($attachments),
            'smtp_response' => $smtpResponse,
            'imap_append_status' => $imapAppend['status'],
            'imap_append_folder' => $imapAppend['folder'],
            'imap_append_error' => $imapAppend['error'],
        ]);

        // Plný SMTP transcript obsahuje i `AUTH …` kredence (base64 = triviálně
        // reverzibilní heslo) → jen na DEBUG úrovni, ne v běžném info logu.
        if ($debug !== '') {
            $this->logger->debug('mail.smtp_transcript', ['template' => $code, 'smtp_debug' => $debug]);
        }

        if ($imapAppend['status'] === 'failed' && $this->imapFailurePolicy($emailProfile) === 'fail_send') {
            // E-mail UŽ byl doručen (transport->send() proběhl); selhalo jen uložení
            // kopie do IMAP. Logujeme error a vyhazujeme DEDIKOVANÝ typ výjimky, aby
            // caller/fronta odeslání NEretryoval (jinak by příjemce dostal e-mail 2×).
            $this->logger->error('mail.imap_sent_append_failed_fail_send', [
                'template'      => $code,
                'email_profile' => $emailProfile !== null ? ($emailProfile['code'] ?? null) : null,
                'folder'        => $imapAppend['folder'],
                'error'         => $imapAppend['error'],
            ]);
            throw new MailDeliveredArchiveException(
                'E-mail byl transportem přijat, ale uložení do IMAP složky selhalo: '
                . (string) ($imapAppend['error'] ?? 'neznámá chyba'),
                $smtpResponse,
                $imapAppend,
            );
        }

        return [
            'smtp_response' => $smtpResponse,
            'imap_append' => $imapAppend,
        ];
    }

    /**
     * @param array<string,mixed>|null $supplier
     * @return array<string,mixed>|null
     */
    private function defaultEmailProfile(?array $supplier): ?array
    {
        if ($this->emailProfiles === null || $supplier === null || empty($supplier['id'])) {
            return null;
        }

        try {
            if (!empty($supplier['email_profile_id'])) {
                $selected = $this->emailProfiles->findProfile(
                    (int) $supplier['id'],
                    (int) $supplier['email_profile_id'],
                    false,
                    true,
                );
                if ($selected !== null && !empty($selected['is_active'])) return $selected;
            }
            return $this->emailProfiles->defaultProfile((int) $supplier['id'], true);
        } catch (\Throwable $e) {
            $this->logger->warning('mail.email_profile_lookup_failed', [
                'supplier_id' => (int) $supplier['id'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Vytáhne z SMTP transcriptu poslední řádek odpovědi serveru (`<<< 250 …`).
     * Používá se pro logování do activity_log payload — uživatel vidí, co
     * SMTP server poslední řekl, a pozná, jestli zpráva byla přijata
     * (`2xx`), odmítnuta (`5xx`) nebo dočasně failnula (`4xx`).
     */
    private function extractLastServerResponse(string $debug): string
    {
        if ($debug === '') return '';
        // Symfony Mailer 8.x prefixuje řádky timestampem `[YYYY-MM-DDTHH:MM:SS] < …`.
        // Server odpovědi používají `< ` (s mezerou) nebo `<<<` (starší verze).
        // Najdeme poslední match přes celý transcript.
        $lines = preg_split('/\r?\n/', $debug) ?: [];
        $last = '';
        foreach ($lines as $line) {
            // Strip timestamp prefix `[2026-05-07T11:43:39.349662+02:00] `
            $stripped = (string) preg_replace('/^\[[^\]]+\]\s*/', '', $line);
            $stripped = trim($stripped);
            if ($stripped === '') continue;
            if (str_starts_with($stripped, '< ') || str_starts_with($stripped, '<<<')) {
                $last = $stripped;
            }
        }
        $last = (string) preg_replace('/^(?:<<<\s*|<\s+)/', '', $last);
        return $last !== '' ? $last : '(no SMTP debug — possibly non-SMTP transport)';
    }

    private function mailer(): SymfonyMailer
    {
        if ($this->mailer === null) {
            $this->mailer = new SymfonyMailer($this->transport());
        }
        return $this->mailer;
    }

    /**
     * @param array<string,mixed>|null $emailProfile
     */
    private function transport(?array $emailProfile = null): TransportInterface
    {
        if ($this->usesProfileTransport($emailProfile)) {
            if ($this->keepaliveEnabled($emailProfile)) {
                $key = $this->profileTransportCacheKey($emailProfile);
                if (!isset($this->profileTransports[$key])) {
                    $this->profileTransports[$key] = $this->buildTransport($emailProfile);
                }

                return $this->profileTransports[$key];
            }

            return $this->buildTransport($emailProfile);
        }

        if ($this->transport === null) {
            $this->transport = $this->buildTransport();
        }
        return $this->transport;
    }

    /**
     * @param array<string,mixed>|null $emailProfile
     */
    private function usesProfileTransport(?array $emailProfile): bool
    {
        return $emailProfile !== null
            && in_array((string) ($emailProfile['transport_type'] ?? 'global'), ['smtp', 'sendmail'], true);
    }

    /**
     * @param array<string,mixed>|null $emailProfile
     */
    private function buildTransport(?array $emailProfile = null): TransportInterface
    {
        if ($emailProfile !== null && ($emailProfile['transport_type'] ?? 'global') === 'smtp') {
            return $this->smtpTransport(
                (string) ($emailProfile['smtp_host'] ?? ''),
                (int) ($emailProfile['smtp_port'] ?? 587),
                (bool) ($emailProfile['smtp_auth_enabled'] ?? false),
                (string) ($emailProfile['smtp_auth_type'] ?? 'PLAIN'),
                (string) ($emailProfile['smtp_username'] ?? ''),
                (string) ($emailProfile['smtp_password'] ?? ''),
                (string) ($emailProfile['smtp_encryption'] ?? 'tls'),
                (bool) ($emailProfile['smtp_verify_peer'] ?? true),
                (bool) ($emailProfile['smtp_verify_peer_name'] ?? true),
                (bool) ($emailProfile['smtp_allow_self_signed'] ?? false),
                isset($emailProfile['smtp_timeout']) ? (int) $emailProfile['smtp_timeout'] : 30,
            );
        }

        if ($emailProfile !== null && ($emailProfile['transport_type'] ?? 'global') === 'sendmail') {
            $command = trim((string) ($emailProfile['sendmail_command'] ?? ''));
            return Transport::fromDsn($this->sendmailDsn($command));
        }

        // Bez profilu (nebo profil s transport_type='global'): použij PŮVODNÍ globální
        // transport přes Transport::fromDsn(buildDsn()) — bit-za-bit shodný s masterem.
        // Ruční EsmtpTransport (smtpTransport) se tak dotkne JEN profilů s vlastním SMTP;
        // instalace, které si SMTP v profilu vědomě nenastaví, mají zaručeně 0 regresí.
        return Transport::fromDsn($this->buildDsn());
    }

    /**
     * Původní globální SMTP DSN (shodné s chováním před zavedením odesílacích profilů).
     * Symfony `smtp://` schéma → plná negociace authenticatorů; STARTTLS auto dle portu.
     */
    private function buildDsn(): string
    {
        $host = (string) $this->config->get('smtp.host');
        $port = (int) $this->config->get('smtp.port', 25);
        $authEnabled = (bool) $this->config->get('smtp.auth_enabled', false);
        $user = (string) $this->config->get('smtp.user', '');
        $pass = (string) $this->config->get('smtp.pass', '');
        $encryption = (string) $this->config->get('smtp.encryption', '');
        $verifyPeer = (bool) $this->config->get('smtp.verify_peer', true);

        $userPart = '';
        if ($authEnabled && $user !== '') {
            $userPart = rawurlencode($user) . ':' . rawurlencode($pass) . '@';
        }

        $params = [];
        // encryption: ssl (port 465 implicit TLS), tls (STARTTLS), '' = plain
        if ($encryption === '') {
            // Plain — disable peer verify implicitly
            $verifyPeer = false;
        }
        if (!$verifyPeer) {
            $params[] = 'verify_peer=0';
        }

        $query = $params ? '?' . implode('&', $params) : '';

        return sprintf('smtp://%s%s:%d%s', $userPart, $host, $port, $query);
    }

    private function smtpTransport(
        string $host,
        int $port,
        bool $authEnabled,
        string $authType,
        string $user,
        string $pass,
        string $encryption,
        bool $verifyPeer,
        bool $verifyPeerName,
        bool $allowSelfSigned,
        int $timeout,
    ): EsmtpTransport {
        $tls = match ($encryption) {
            'ssl' => true,
            '', 'none' => false,
            default => null,
        };

        $transport = new EsmtpTransport(
            $host,
            $port,
            $tls,
            null,
            $this->logger,
            null,
            $authEnabled ? $this->smtpAuthenticators($authType) : [],
        );
        $transport->setAutoTls($encryption !== '' && $encryption !== 'none');
        $transport->setRequireTls($encryption === 'tls');

        if ($authEnabled && $user !== '') {
            $transport->setUsername($user);
            $transport->setPassword($pass);
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(max(1, min(300, $timeout)));
            $streamOptions = $stream->getStreamOptions();
            if ($encryption !== '' && $encryption !== 'none') {
                $streamOptions['ssl']['verify_peer'] = $verifyPeer;
                $streamOptions['ssl']['verify_peer_name'] = $verifyPeer && $verifyPeerName;
                $streamOptions['ssl']['allow_self_signed'] = $allowSelfSigned;
            }
            $stream->setStreamOptions($streamOptions);
        }

        return $transport;
    }

    /**
     * Konkrétní authenticator dle `auth_type`, nebo `null` = předej EsmtpTransportu
     * jeho plnou vestavěnou sadu (LOGIN/PLAIN/CRAM-MD5/XOAUTH2) s negociací dle
     * nabídky serveru. Prázdné/neznámé `auth_type` ⇒ null (zpětně kompatibilní).
     *
     * @return list<object>|null
     */
    private function smtpAuthenticators(string $authType): ?array
    {
        return match (strtoupper(trim($authType))) {
            'LOGIN' => [new LoginAuthenticator()],
            'PLAIN' => [new PlainAuthenticator()],
            'CRAM-MD5' => [new CramMd5Authenticator()],
            'XOAUTH2' => [new XOAuth2Authenticator()],
            default => null,
        };
    }

    /**
     * Doména z hlavičky From (první adresa), lowercase; null když chybí/neplatná.
     */
    private function fromDomain(Email $email): ?string
    {
        $from = $email->getFrom();
        if ($from === []) {
            return null;
        }
        $address = $from[0]->getAddress();
        $at = strrpos($address, '@');
        if ($at === false || $at === strlen($address) - 1) {
            return null;
        }
        $domain = strtolower(substr($address, $at + 1));
        return $domain !== '' ? $domain : null;
    }

    private function sendmailDsn(string $command): string
    {
        if ($command === '') {
            return 'sendmail://default';
        }

        return 'sendmail://default?command=' . rawurlencode($command);
    }

    /**
     * @param array<string,mixed>|null $emailProfile
     */
    private function keepaliveEnabled(?array $emailProfile): bool
    {
        if ($emailProfile !== null && ($emailProfile['transport_type'] ?? 'global') === 'smtp') {
            return (bool) ($emailProfile['smtp_keepalive'] ?? false);
        }

        return (bool) $this->config->get('smtp.keepalive', false);
    }

    /**
     * @param array<string,mixed>|null $emailProfile
     */
    private function imapFailurePolicy(?array $emailProfile): string
    {
        return $emailProfile !== null && ($emailProfile['imap_on_failure'] ?? 'log_only') === 'fail_send'
            ? 'fail_send'
            : 'log_only';
    }

    private function rawMessageForImap(?SentMessage $sent, RawMessage $email): string
    {
        return $sent !== null ? $sent->toString() : $email->toString();
    }

    /**
     * @param array<string,mixed> $emailProfile
     */
    private function profileTransportCacheKey(array $emailProfile): string
    {
        $identity = [
            'id' => $emailProfile['id'] ?? null,
            'transport_type' => $emailProfile['transport_type'] ?? 'global',
            'smtp_host' => $emailProfile['smtp_host'] ?? null,
            'smtp_port' => $emailProfile['smtp_port'] ?? null,
            'smtp_encryption' => $emailProfile['smtp_encryption'] ?? null,
            'smtp_auth_enabled' => $emailProfile['smtp_auth_enabled'] ?? null,
            'smtp_auth_type' => $emailProfile['smtp_auth_type'] ?? null,
            'smtp_username' => $emailProfile['smtp_username'] ?? null,
            'smtp_password' => $emailProfile['smtp_password'] ?? null,
            'smtp_verify_peer' => $emailProfile['smtp_verify_peer'] ?? null,
            'smtp_verify_peer_name' => $emailProfile['smtp_verify_peer_name'] ?? null,
            'smtp_allow_self_signed' => $emailProfile['smtp_allow_self_signed'] ?? null,
            'smtp_timeout' => $emailProfile['smtp_timeout'] ?? null,
        ];

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email');
            $this->twig = new Environment($loader, [
                'autoescape' => 'html',
                'cache'      => false,
                'strict_variables' => false,
            ]);
        }
        return $this->twig;
    }

    private ?Environment $sandboxTwig = null;

    /**
     * Validuje user-editovanou šablonu proti sandbox policy (stejnou jakou
     * používá `sendTemplate` pro DB override). Vrací `null` pokud šablona
     * projde, jinak human-readable chybovou hlášku v češtině pro UI toast.
     *
     * Volá se z `EmailTemplateAction::put` před `$repo->save()` — zachytíme
     * neplatné tagy/filtry/syntax dřív, než user pošle email a uvidí ošklivý
     * runtime crash. Issue #25 follow-up.
     *
     * @return array{field:string,message:string}|null
     */
    public function validateUserTemplate(string $bodyHtml, string $bodyText): ?array
    {
        $sandbox = $this->sandboxedTwig();
        foreach (['body_html' => $bodyHtml, 'body_text' => $bodyText] as $field => $body) {
            if ($body === '') continue;
            try {
                // Trial render s prázdnými vars stačí — SecurityNotAllowed* errors
                // sandbox hlásí už při kompilaci/první návštěvě AST node.
                // strict_variables=false → undefined refs neselžou.
                $sandbox->createTemplate($body)->render([]);
            } catch (\Twig\Sandbox\SecurityNotAllowedTagError $e) {
                return ['field' => $field, 'message' => sprintf(
                    'Tag „%s" není v šabloně povolený. Sandbox povoluje pouze: if/for/set/spaceless/extends/block/use.',
                    $e->getTagName()
                )];
            } catch (\Twig\Sandbox\SecurityNotAllowedFilterError $e) {
                return ['field' => $field, 'message' => sprintf(
                    'Filtr „|%s" není povolený. Povolené filtry: escape, default, date, number_format, replace, upper, lower, trim, length, first, last, join, split, nl2br, abs, round, format aj.',
                    $e->getFilterName()
                )];
            } catch (\Twig\Sandbox\SecurityNotAllowedFunctionError $e) {
                return ['field' => $field, 'message' => sprintf(
                    'Funkce „%s()" není povolená. Povolené: date(), min(), max().',
                    $e->getFunctionName()
                )];
            } catch (\Twig\Sandbox\SecurityNotAllowedMethodError $e) {
                return ['field' => $field, 'message' => 'Volání metod na objektech není povolené.'];
            } catch (\Twig\Sandbox\SecurityNotAllowedPropertyError $e) {
                return ['field' => $field, 'message' => 'Přístup k property není povolený — použij array notaci `{{ var.klic }}`.'];
            } catch (\Twig\Error\SyntaxError $e) {
                // Strip filename z message (je to interní `__string_template__…`).
                $msg = (string) preg_replace('/ in ".*?"/', '', $e->getRawMessage());
                return ['field' => $field, 'message' => sprintf('Chyba syntaxe (řádek %d): %s', $e->getTemplateLine(), $msg)];
            } catch (\Twig\Error\RuntimeError $e) {
                // Runtime chyby (undefined property atd.) ignorujeme — závisí na reálných
                // datech, které tady nemáme. Reálný render má všechny vars naplněné.
                continue;
            } catch (\Throwable $e) {
                return ['field' => $field, 'message' => 'Neočekávaná chyba při validaci šablony: ' . $e->getMessage()];
            }
        }
        return null;
    }

    /**
     * Sandboxovaný Twig pro renderování DB šablon — chrání proti SSTI:
     * povoleny jen základní tagy, filtry a accessory na safe variables.
     * Bez funkcí (range, dump, attribute) a bez method calls mimo allow-list.
     */
    private function sandboxedTwig(): Environment
    {
        if ($this->sandboxTwig === null) {
            // `extends`/`block`/`use` musí být povoleny — uložená DB šablona dědí
            // z `_layout.html.twig` (viz EmailTemplateAction::loadDefaults, který
            // vrátí celé tělo včetně `{% extends %}{% block content %}`).
            // Tyto tagy jsou čistě strukturální (nespouští PHP) a FilesystemLoader
            // je rooted v `templates/email/`, takže nelze přes ně načíst soubor mimo.
            // Issue #25 — bez `block` selže render po každé editaci šablony.
            $allowedTags = ['if', 'for', 'set', 'spaceless', 'extends', 'block', 'use'];
            $allowedFilters = [
                'escape', 'e', 'raw', 'default', 'date', 'number_format',
                'upper', 'lower', 'capitalize', 'title', 'trim', 'replace',
                'length', 'first', 'last', 'join', 'split', 'nl2br',
                'abs', 'round', 'format',
            ];
            $allowedFunctions = ['date', 'min', 'max'];
            $allowedMethods = []; // žádné method calls na objektech
            $allowedProperties = []; // všechny array klíče OK, jen property accesy zakázané
            $policy = new SecurityPolicy($allowedTags, $allowedFilters, $allowedMethods, $allowedProperties, $allowedFunctions);

            $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email');
            $this->sandboxTwig = new Environment($loader, [
                'autoescape' => 'html',
                'cache'      => false,
                'strict_variables' => false,
            ]);
            $this->sandboxTwig->addExtension(new SandboxExtension($policy, true)); // sandboxed=true
        }
        return $this->sandboxTwig;
    }

    /**
     * Načte data pro patičku emailu — fallback pro non-invoice templates (password_reset apod).
     * Použije MIN(id) supplier — primární / „system default" branding.
     *
     * Pro invoice/reminder emaily caller (InvoiceEmailVarsBuilder) předává
     * `vars['supplier']` z konkrétní faktury (přes invoice.supplier_id) — Mailer pak nevolá tuto metodu.
     * Cached na instance lifetime.
     */
    private function loadSupplierFooter(): ?array
    {
        if ($this->supplierFooter !== null) {
            return $this->supplierFooter ?: null;
        }

        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT s.id, s.company_name, s.display_name, s.tagline, s.street, s.city, s.zip,
                        s.email, s.phone, s.web,
                        s.email_branding_enabled, s.email_accent_color, s.logo_path,
                        bp.id AS branding_profile_id, bp.display_name AS bp_display_name,
                        bp.tagline AS bp_tagline, bp.email AS bp_email, bp.phone AS bp_phone,
                        bp.web AS bp_web, bp.email_footer AS bp_email_footer, bp.logo_path AS bp_logo_path,
                        bp.accent_color AS bp_accent_color, bp.branding_enabled AS bp_branding_enabled,
                        bp.email_profile_id AS bp_email_profile_id,
                        co.name_cs AS country
                   FROM supplier s
              LEFT JOIN branding_profiles bp ON s.branding_profiles_enabled = 1 AND bp.id = s.default_branding_profile_id AND bp.supplier_id = s.id AND bp.is_active = 1
              LEFT JOIN countries co ON co.id = s.country_id
                  WHERE s.id = (SELECT MIN(id) FROM supplier)'
            );
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false) {
                foreach (['display_name', 'tagline', 'email', 'phone', 'web', 'email_footer', 'logo_path'] as $field) {
                    $profileKey = 'bp_' . $field;
                    if (array_key_exists($profileKey, $row) && $row[$profileKey] !== null && $row[$profileKey] !== '') {
                        $row[$field] = $row[$profileKey];
                    }
                }
                if (!empty($row['branding_profile_id'])) {
                    $row['email_branding_enabled'] = (bool) $row['bp_branding_enabled'];
                    $row['email_accent_color'] = (string) ($row['bp_accent_color'] ?: '#3B2D83');
                    $row['email_profile_id'] = $row['bp_email_profile_id'] !== null ? (int) $row['bp_email_profile_id'] : null;
                }
                $row['email_branding_enabled'] = (bool) ($row['email_branding_enabled'] ?? false);
                $row['email_accent_color']     = (string) ($row['email_accent_color'] ?: '#3B2D83');
                $row['accent_soft']            = AccentColor::emailBackground(
                    (bool) $row['email_branding_enabled'],
                    $row['email_accent_color'],
                );
            }
            $this->supplierFooter = $row !== false ? $row : [];
            return $this->supplierFooter ?: null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load supplier footer: ' . $e->getMessage());
            $this->supplierFooter = [];
            return null;
        }
    }

    /**
     * Spočítá display rozměry loga pro 48px display height (HTML width/height
     * atributy v `<img>` tagu — respektovány všemi email klienty na rozdíl
     * od CSS max-height, které Outlook a další ignorují).
     *
     * Doplní do $supplier klíče `logo_display_width`, `logo_display_height`.
     * Pokud logo neexistuje nebo branding je vypnutý, klíče zůstanou null.
     */
    private function addLogoDisplaySize(array $supplier): array
    {
        $supplier['logo_display_width']  = null;
        $supplier['logo_display_height'] = null;

        if (empty($supplier['email_branding_enabled']) || empty($supplier['logo_path']) || empty($supplier['id'])) {
            return $supplier;
        }
        // SafeLogoPath: viz security report @andrejtomci #2
        $abs = SafeLogoPath::resolve((string) $supplier['logo_path'], (int) $supplier['id']);
        if ($abs === null) return $supplier;

        $info = @getimagesize($abs);
        if ($info === false || (int) $info[1] === 0) return $supplier;

        $targetH = 48;
        $w = (int) $info[0];
        $h = (int) $info[1];
        $supplier['logo_display_height'] = $targetH;
        $supplier['logo_display_width']  = max(1, (int) round($w * $targetH / $h));
        return $supplier;
    }

    /**
     * Rozparsuje `data:<mime>;base64,<data>` URI na raw bytes + content type
     * pro inline CID embed. Vrací null pokud URI není base64 data URI nebo
     * dekódování selže (pak se var ponechá beze změny a `<img>` se nezobrazí —
     * stejné jako kdyby QR nebylo vygenerováno).
     *
     * @return array{bytes:string,contentType:string}|null
     */
    private function decodeDataUri(string $uri): ?array
    {
        if (!preg_match('#^data:([^;,]+);base64,(.+)$#s', $uri, $m)) {
            return null;
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        return ['bytes' => $bytes, 'contentType' => $m[1]];
    }

    private function defaultSubject(string $code, string $locale): string
    {
        $subjects = [
            'cs' => [
                'password_reset'    => 'Obnova hesla — MyInvoice.cz',
                'login_otp'         => 'Ověřovací kód pro přihlášení — MyInvoice.cz',
                'email_profile_test'=> 'Test odesílacího profilu — MyInvoice.cz',
                'invoice_send'      => 'Faktura — MyInvoice.cz',
                'invoice_payment_thanks' => 'Poděkování za úhradu — MyInvoice.cz',
                'invoice_reminder'  => 'Upomínka — MyInvoice.cz',
                'proforma_reminder' => 'Připomínka zálohy — MyInvoice.cz',
                'recurring_draft_reminder' => 'Koncept pravidelné faktury se brzy vystaví — MyInvoice.cz',
                'work_report_link'  => 'Náhled na výkaz práce — MyInvoice.cz',
                'work_report_access_code' => 'Ověřovací kód pro náhled výkazu práce — MyInvoice.cz',
            ],
            'en' => [
                'password_reset'    => 'Password reset — MyInvoice.cz',
                'login_otp'         => 'Sign-in verification code — MyInvoice.cz',
                'email_profile_test'=> 'Sending profile test — MyInvoice.cz',
                'invoice_send'      => 'Invoice — MyInvoice.cz',
                'invoice_payment_thanks' => 'Thank you for your payment — MyInvoice.cz',
                'invoice_reminder'  => 'Reminder — MyInvoice.cz',
                'proforma_reminder' => 'Advance payment reminder — MyInvoice.cz',
                'recurring_draft_reminder' => 'Recurring invoice draft will be issued soon — MyInvoice.cz',
                'work_report_link'  => 'Work report preview — MyInvoice.cz',
                'work_report_access_code' => 'Verification code for work report preview — MyInvoice.cz',
            ],
        ];
        return $subjects[$locale][$code] ?? ($subjects['cs'][$code] ?? 'MyInvoice.cz');
    }
}
