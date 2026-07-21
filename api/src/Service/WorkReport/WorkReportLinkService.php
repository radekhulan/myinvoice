<?php

declare(strict_types=1);

namespace MyInvoice\Service\WorkReport;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\WorkReportLinkRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Branding\AccentColor;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\SafeLogoPath;
use Psr\Log\LoggerInterface;

/**
 * Logika veřejného sledovacího odkazu na výkaz práce.
 *
 * Tok:
 *   1. getOrCreate() — admin v detailu klienta/zakázky vytvoří (jednou) trvalý odkaz.
 *   2. Návštěvník otevře /work-report/{token}; pokud nemá platnou cookie-relaci,
 *      vyžádá si kód → issueCode() pošle 6místný kód na e-mail klienta/zakázky.
 *   3. verifyCode() ověří kód → založí relaci, vrátí session token (uloží se do cookie).
 *   4. validateSession() pak při dalších návštěvách pustí rovnou na náhled.
 *   5. buildPreview() živě složí aktuálně otevřené (draft) výkazy práce.
 *
 * Bezpečnost (vzor EmailOtpService + PublicApproval*):
 *   - kódy i session tokeny se ukládají jen jako sha256 hash,
 *   - jednorázový kód s TTL + per-kód attempt cap + resend cooldown,
 *   - povolené e-maily = e-maily klienta (+ u zakázky e-maily zakázky); proti
 *     enumeraci se na request-code vrací generická odpověď a v UI jen maskované adresy.
 */
final class WorkReportLinkService
{
    /** Název cookie s ověřenou relací návštěvníka (scoped Path na konkrétní odkaz). */
    public const COOKIE_NAME = 'wrt_session';

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly Mailer $mailer,
        private readonly WorkReportRepository $workReports,
        private readonly WorkReportLinkRepository $links,
        private readonly LoggerInterface $logger,
    ) {}

    public function ttlMinutes(): int
    {
        return max(1, (int) $this->config->get('work_report_tracking.code_ttl_minutes', 15));
    }

    public function resendCooldownSeconds(): int
    {
        return max(0, (int) $this->config->get('work_report_tracking.resend_cooldown_seconds', 60));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) $this->config->get('work_report_tracking.max_attempts', 5));
    }

    public function sessionLifetimeDays(): int
    {
        return max(1, (int) $this->config->get('work_report_tracking.session_lifetime_days', 180));
    }

    /** Vrátí existující aktivní odkaz, jinak založí nový. */
    public function getOrCreate(int $supplierId, string $scope, int $clientId, ?int $projectId, ?int $userId): array
    {
        $existing = $this->links->findActiveByEntity($supplierId, $scope, $clientId, $projectId);
        if ($existing !== null) {
            return $existing;
        }
        return $this->links->create($supplierId, $scope, $clientId, $projectId, $userId);
    }

    /** Aktivní (nerevokovaný) odkaz dle tokenu, nebo null. */
    public function findActiveLink(string $token): ?array
    {
        return $this->links->findActiveByToken($token);
    }

    /** Aktivní odkaz pro entitu (bez vytvoření), nebo null. */
    public function findActiveForEntity(int $supplierId, string $scope, int $clientId, ?int $projectId): ?array
    {
        return $this->links->findActiveByEntity($supplierId, $scope, $clientId, $projectId);
    }

    /** Zneplatní odkaz (a všechny jeho ověřené relace). */
    public function revoke(int $linkId): void
    {
        $this->links->revoke($linkId);
    }

    /** Zaznamená odeslání odkazu e-mailem (last_sent_at). */
    public function touchSent(int $linkId): void
    {
        $this->links->touchSent($linkId);
    }

    /** Zaznamená zobrazení náhledu (last_viewed_at). */
    public function touchViewed(int $linkId): void
    {
        $this->links->touchViewed($linkId);
    }

    /** Plná veřejná URL odkazu (pro e-mail + UI „kopírovat"). */
    public function publicUrl(array $link): string
    {
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        return $appUrl . '/work-report/' . (string) $link['token'];
    }

    /**
     * Povolené e-maily pro autorizaci: hlavní e-mail klienta + aktivní kontakty
     * klienta; u project scope navíc fakturační e-maily zakázky. Vše lowercase,
     * validní a deduplikované.
     *
     * @return list<string>
     */
    public function resolveAllowedEmails(array $link): array
    {
        $pdo = $this->db->pdo();
        $clientId  = (int) $link['client_id'];
        $projectId = $link['project_id'] !== null ? (int) $link['project_id'] : null;

        $raw = [];

        $stmt = $pdo->prepare('SELECT main_email FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $raw[] = (string) ($stmt->fetchColumn() ?: '');

        $stmt = $pdo->prepare('SELECT email FROM client_email_contacts WHERE client_id = ? AND is_active = 1');
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $em) {
            $raw[] = (string) $em;
        }

        if ($projectId !== null) {
            $stmt = $pdo->prepare('SELECT email FROM project_billing_emails WHERE project_id = ?');
            $stmt->execute([$projectId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $em) {
                $raw[] = (string) $em;
            }
        }

        $out = [];
        foreach ($raw as $em) {
            $em = mb_strtolower(trim($em));
            if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $out[$em] = true;
        }
        return array_keys($out);
    }

    /**
     * Maskované povolené adresy pro UI (návštěvník pozná, kterou má použít),
     * bez prozrazení celé adresy.
     *
     * @return list<string>
     */
    public function maskedEmails(array $link): array
    {
        $masked = [];
        foreach ($this->resolveAllowedEmails($link) as $em) {
            $masked[self::maskEmail($em)] = true;
        }
        return array_keys($masked);
    }

    /**
     * Vyžádá (nebo přepošle) jednorázový kód. Když e-mail není mezi povolenými,
     * NIC neposílá (a vrátí sent=false) — volající endpoint ale odpoví genericky,
     * aby nešlo enumerovat adresy.
     *
     * @return array{sent:bool, cooldown_remaining:int, allowed:bool}
     */
    public function issueCode(array $link, string $email, string $ip, bool $force = false): array
    {
        $email = mb_strtolower(trim($email));
        $linkId = (int) $link['id'];

        if (!in_array($email, $this->resolveAllowedEmails($link), true)) {
            $this->logger->info('work_report_link.code_denied', ['link_id' => $linkId]);
            return ['sent' => false, 'cooldown_remaining' => 0, 'allowed' => false];
        }

        $active = $this->links->activeCode($linkId, $email);
        if ($active !== null) {
            $age = time() - (int) $active['created_ts'];
            $cooldownRemaining = max(0, $this->resendCooldownSeconds() - $age);
            if (!$force || $cooldownRemaining > 0) {
                return ['sent' => false, 'cooldown_remaining' => $cooldownRemaining, 'allowed' => true];
            }
        }

        $this->links->invalidateCodes($linkId, $email);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = (new \DateTimeImmutable('+' . $this->ttlMinutes() . ' minutes'))->format('Y-m-d H:i:s');
        $this->links->insertCode($linkId, $email, hash('sha256', $code), $expiresAt, @inet_pton($ip) ?: null);

        try {
            $this->mailer->sendTemplate(
                'work_report_access_code',
                $this->clientLocale($link),
                [$email],
                [
                    'code'      => $code,
                    'expiresIn' => $this->ttlMinutes() . ' min',
                    'supplier'  => $this->loadSupplierVars((int) $link['supplier_id']),
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error('work_report_link.code_mail_failed', [
                'link_id' => $linkId,
                'error'   => $e->getMessage(),
            ]);
        }

        return ['sent' => true, 'cooldown_remaining' => $this->resendCooldownSeconds(), 'allowed' => true];
    }

    /**
     * Ověří kód. Při úspěchu založí relaci a vrátí plaintext session token
     * (uloží se do cookie; v DB jen sha256 hash). Při neúspěchu null.
     */
    public function verifyCode(array $link, string $email, string $code, string $ip): ?string
    {
        $email = mb_strtolower(trim($email));
        $code  = trim($code);
        if ($code === '' || !ctype_digit($code)) {
            return null;
        }
        if (!in_array($email, $this->resolveAllowedEmails($link), true)) {
            return null;
        }

        $linkId = (int) $link['id'];
        $active = $this->links->activeCode($linkId, $email);
        if ($active === null) {
            return null;
        }
        if ((int) $active['attempts'] >= $this->maxAttempts()) {
            $this->links->markCodeUsed((int) $active['id']);
            return null;
        }

        if (hash_equals((string) $active['code_hash'], hash('sha256', $code))) {
            $this->links->markCodeUsed((int) $active['id']);
            $sessionToken = bin2hex(random_bytes(32)); // 64 hex znaků
            $this->links->createSession($linkId, $email, hash('sha256', $sessionToken), @inet_pton($ip) ?: null);
            return $sessionToken;
        }

        $newAttempts = (int) $active['attempts'] + 1;
        $this->links->bumpCodeAttempts((int) $active['id'], $newAttempts);
        if ($newAttempts >= $this->maxAttempts()) {
            $this->links->markCodeUsed((int) $active['id']);
        }
        return null;
    }

    /** True když cookie-token odpovídá platné relaci tohoto odkazu. */
    public function validateSession(array $link, string $sessionToken): bool
    {
        $sessionToken = trim($sessionToken);
        if ($sessionToken === '' || !preg_match('/^[a-f0-9]{64}$/', $sessionToken)) {
            return false;
        }
        $session = $this->links->findActiveSession((int) $link['id'], hash('sha256', $sessionToken));
        if ($session === null) {
            return false;
        }
        $this->links->touchSession((int) $session['id']);
        return true;
    }

    /**
     * Živě složí náhled: aktuálně otevřené (draft) faktury klienta/zakázky,
     * které mají výkaz práce, vč. položek a součtů.
     */
    public function buildPreview(array $link): array
    {
        $pdo = $this->db->pdo();
        $supplierId = (int) $link['supplier_id'];
        $clientId   = (int) $link['client_id'];
        $projectId  = $link['project_id'] !== null ? (int) $link['project_id'] : null;

        $sql =
            "SELECT i.id, i.varsymbol, i.issue_date, i.created_at, cur.code AS currency,
                    p.name AS project_name
               FROM invoices i
               JOIN work_reports wr ON wr.invoice_id = i.id
               JOIN currencies cur ON cur.id = i.currency_id
          LEFT JOIN projects p ON p.id = i.project_id
              WHERE i.supplier_id = ? AND i.client_id = ? AND i.status = 'draft'";
        $params = [$supplierId, $clientId];
        if ($projectId !== null) {
            $sql .= ' AND i.project_id = ?';
            $params[] = $projectId;
        }
        $sql .= ' ORDER BY COALESCE(i.issue_date, DATE(i.created_at)) DESC, i.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $reports = [];
        $totalHours = 0.0;
        $byCurrency = [];
        foreach ($rows as $row) {
            $wr = $this->workReports->findByInvoice((int) $row['id']);
            if ($wr === null) {
                continue;
            }
            $currency = (string) $row['currency'];
            $totalHours += (float) $wr['total_hours'];
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0.0)
                + (float) $wr['total_amount'] + (float) ($wr['material_total'] ?? 0);

            $reports[] = [
                'invoice_id'   => (int) $row['id'],
                'label'        => $row['varsymbol'] ?: null,
                'date'         => $row['issue_date'] ?: substr((string) $row['created_at'], 0, 10),
                'currency'     => $currency,
                'project_name' => $row['project_name'] ?: null,
                'title'          => (string) $wr['title'],
                'total_hours'    => (float) $wr['total_hours'],
                'total_amount'   => (float) $wr['total_amount'],
                'items'          => array_map(static fn (array $it) => [
                    'description'  => (string) $it['description'],
                    'work_date'    => $it['work_date'],
                    'hours'        => (float) $it['hours'],
                    'rate'         => (float) $it['rate'],
                    'total_amount' => (float) $it['total_amount'],
                ], $wr['items']),
                'material_title' => $wr['material_title'] ?? null,
                'material_total' => (float) ($wr['material_total'] ?? 0),
                'materials'      => array_map(static fn (array $m) => [
                    'description'  => (string) $m['description'],
                    'quantity'     => (float) $m['quantity'],
                    'unit'         => (string) $m['unit'],
                    'unit_price'   => (float) $m['unit_price'],
                    'total_amount' => (float) $m['total_amount'],
                ], $wr['materials'] ?? []),
            ];
        }

        $totalsByCurrency = [];
        foreach ($byCurrency as $code => $amount) {
            $totalsByCurrency[] = ['currency' => $code, 'total_amount' => $amount];
        }

        $client = $this->loadClientMeta($clientId);
        $projectName = null;
        if ($projectId !== null) {
            $stmt = $pdo->prepare('SELECT name FROM projects WHERE id = ?');
            $stmt->execute([$projectId]);
            $projectName = (string) ($stmt->fetchColumn() ?: '') ?: null;
        }

        $supplier = $this->loadSupplierVars($supplierId);

        return [
            'scope'                => (string) $link['scope'],
            'client_company_name'  => $client['company_name'],
            'project_name'         => $projectName,
            'language'             => $client['language'],
            'supplier_name'        => (string) ($supplier['display_name'] ?: ($supplier['company_name'] ?? '')),
            'supplier'             => $this->supplierBlock($supplier),
            'accent_color'         => !empty($supplier['email_branding_enabled']) ? ($supplier['email_accent_color'] ?? null) : null,
            'logo_src'             => $this->logoSrc($supplier),
            'reports'              => $reports,
            'total_hours'          => $totalHours,
            'totals_by_currency'   => $totalsByCurrency,
        ];
    }

    /**
     * Veřejně zobrazitelné kontaktní/identifikační údaje dodavatele pro hlavičku
     * náhledu. Žádná citlivá pole — jen to, co stejně je na fakturách/v patičce.
     *
     * @param array<string,mixed>|null $supplier Řádek z loadSupplierVars().
     * @return array<string,mixed>
     */
    private function supplierBlock(?array $supplier): array
    {
        $supplier ??= [];
        $name = (string) (($supplier['display_name'] ?? '') ?: ($supplier['company_name'] ?? ''));
        return [
            'name'         => $name,
            'company_name' => (string) ($supplier['company_name'] ?? ''),
            'tagline'      => ($supplier['tagline'] ?? '') ?: null,
            'street'       => ($supplier['street'] ?? '') ?: null,
            'city'         => ($supplier['city'] ?? '') ?: null,
            'zip'          => ($supplier['zip'] ?? '') ?: null,
            'country'      => ($supplier['country'] ?? '') ?: null,
            'ic'           => ($supplier['ic'] ?? '') ?: null,
            'dic'          => ($supplier['dic'] ?? '') ?: null,
            'is_vat_payer' => (bool) ($supplier['is_vat_payer'] ?? false),
            'email'        => ($supplier['email'] ?? '') ?: null,
            'phone'        => ($supplier['phone'] ?? '') ?: null,
            'web'          => ($supplier['web'] ?? '') ?: null,
        ];
    }

    /** @return array{company_name:string, language:string} */
    private function loadClientMeta(int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, language FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'company_name' => (string) ($row['company_name'] ?? ''),
            'language'     => in_array($row['language'] ?? 'cs', ['cs', 'en'], true) ? (string) $row['language'] : 'cs',
        ];
    }

    public function clientLocale(array $link): string
    {
        return $this->loadClientMeta((int) $link['client_id'])['language'];
    }

    /**
     * Supplier branding + footer pro e-maily a hlavičku náhledu. Stejné sloupce
     * jako Mailer::loadSupplierFooter, ať se logo/akcent/From chovají konzistentně.
     */
    public function loadSupplierVars(int $supplierId): ?array
    {
        if ($supplierId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.company_name, COALESCE(bp.display_name, s.display_name) AS display_name,
                    COALESCE(bp.tagline, s.tagline) AS tagline, s.street, s.city, s.zip,
                    COALESCE(bp.email, s.email) AS email, COALESCE(bp.phone, s.phone) AS phone,
                    COALESCE(bp.web, s.web) AS web, s.ic, s.dic, s.is_vat_payer,
                    COALESCE(bp.branding_enabled, s.email_branding_enabled) AS email_branding_enabled,
                    COALESCE(bp.accent_color, s.email_accent_color) AS email_accent_color,
                    COALESCE(bp.logo_path, s.logo_path) AS logo_path, bp.id AS branding_profile_id,
                    co.name_cs AS country
               FROM supplier s
          LEFT JOIN branding_profiles bp ON s.branding_profiles_enabled = 1 AND bp.id = s.default_branding_profile_id AND bp.supplier_id = s.id AND bp.is_active = 1
          LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['email_branding_enabled'] = (bool) ($row['email_branding_enabled'] ?? false);
        $row['email_accent_color']     = (string) ($row['email_accent_color'] ?: '#3B2D83');
        $row['accent_soft']            = AccentColor::emailBackground(
            (bool) $row['email_branding_enabled'],
            $row['email_accent_color'],
        );
        return $row;
    }

    /**
     * Logo dodavatele jako `data:` URI pro hlavičku veřejného náhledu — místo
     * MyInvoice loga (analogie e-mailové hlavičky `_layout.html.twig`).
     *
     * Vrací se jen když má dodavatel zapnutý branding (konzistentní s `accent_color`
     * i e-mailem) a logo soubor existuje; jinak null → frontend zobrazí MyInvoice.
     *
     * Web preferuje SVG sidecar (vektor = ostrý při libovolné velikosti, na rozdíl
     * od e-mailu, kde Outlook/Gmail SVG neumí a používá se PNG) s fallbackem na PNG.
     * Cesty validuje `SafeLogoPath` (defense-in-depth proti LFI, security report #2).
     *
     * @param array<string,mixed>|null $supplier Řádek z loadSupplierVars().
     */
    public function logoSrc(?array $supplier): ?string
    {
        if ($supplier === null
            || empty($supplier['email_branding_enabled'])
            || empty($supplier['logo_path'])
            || empty($supplier['id'])
        ) {
            return null;
        }
        $sid = (int) $supplier['id'];

        // SVG sidecar (preferovaný — náhled běží vždy ve světlém režimu).
        $svgRel = preg_replace('/\.png$/i', '.svg', (string) $supplier['logo_path']);
        if (is_string($svgRel)) {
            $svgAbs = SafeLogoPath::resolve($svgRel, $sid);
            if ($svgAbs !== null) {
                $bytes = (string) @file_get_contents($svgAbs);
                if ($bytes !== '') {
                    return 'data:image/svg+xml;base64,' . base64_encode($this->ensureSvgNamespace($bytes));
                }
            }
        }

        // Fallback PNG.
        $pngAbs = SafeLogoPath::resolve((string) $supplier['logo_path'], $sid);
        if ($pngAbs !== null) {
            $bytes = (string) @file_get_contents($pngAbs);
            if ($bytes !== '') {
                return 'data:image/png;base64,' . base64_encode($bytes);
            }
        }
        return null;
    }

    /**
     * Doplní povinný SVG namespace do kořenového `<svg>`, pokud chybí. Bez něj
     * prohlížeč standalone SVG (data: URI v `<img>`) odmítne vykreslit (parsuje
     * se jako přísné XML → naturalWidth=0, rozbitý obrázek); inline v HTML je
     * parser benevolentní, jako samostatný dokument ne. Některá uložená loga
     * (optimalizovaný export bez xmlns) ho nemají — mPDF to v PDF toleruje,
     * prohlížeč ne. Idempotentní.
     */
    private function ensureSvgNamespace(string $svg): string
    {
        if (!preg_match('/<svg\b[^>]*\bxmlns\s*=/i', $svg)) {
            $svg = (string) preg_replace(
                '/<svg\b/i',
                '<svg xmlns="http://www.w3.org/2000/svg"',
                $svg,
                1,
            );
        }
        if (preg_match('/\bxlink:/i', $svg) && !preg_match('/<svg\b[^>]*\bxmlns:xlink\s*=/i', $svg)) {
            $svg = (string) preg_replace(
                '/<svg\b/i',
                '<svg xmlns:xlink="http://www.w3.org/1999/xlink"',
                $svg,
                1,
            );
        }
        return $svg;
    }

    /** r***@hulan.cz — náznak adresy pro UI bez prozrazení celé adresy. */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at);
        $first  = mb_substr($local, 0, 1);
        return $first . str_repeat('*', max(1, mb_strlen($local) - 1)) . $domain;
    }
}
