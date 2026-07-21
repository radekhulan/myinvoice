<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\SupplierLogoConverter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Per-supplier email branding:
 *   POST   /api/settings/email-branding/logo   — multipart upload (PNG / JPG / SVG)
 *   DELETE /api/settings/email-branding/logo   — odebrat logo soubor
 *   GET    /api/settings/email-branding/preview?locale=cs  — vyrenderovaný email layout
 *
 * Upload/delete mají navíc veřejné API aliasy POST/DELETE /api/settings/supplier/logo
 * (bearer allowlist, viz ApiScopeMiddleware) — stejné metody, jiná cesta. Preview
 * zůstává session-only (čte soubory z disku, viz guard níže).
 *
 * Toggle (`email_branding_enabled`), brand name (`display_name`), tagline
 * a accent barva (`email_accent_color`) se ukládají přes existující PUT /api/settings/supplier.
 */
final class EmailBrandingAction
{
    private const MAX_FILE_SIZE = 1_048_576; // 1 MiB

    public function __construct(
        private readonly Connection $db,
        private readonly SupplierLogoConverter $converter,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** POST /api/settings/email-branding/logo */
    public function uploadLogo(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí měnit branding.', 403);
        }

        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return Json::error($response, 'no_file', 'Žádný soubor nebyl odeslán (pole `file`).', 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_failed', 'Nahrání selhalo (kód ' . $file->getError() . ').', 400);
        }
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0) {
            return Json::error($response, 'empty_file', 'Soubor je prázdný.', 400);
        }
        if ($size > self::MAX_FILE_SIZE) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 1 MiB).', 413);
        }

        // Move do dočasné cesty pro zpracování
        $tmpDir = sys_get_temp_dir();
        if (!is_writable($tmpDir)) {
            $tmpDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('supplier-logos');
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir . '/.upload-' . bin2hex(random_bytes(8));
        try {
            $file->moveTo($tmpPath);
        } catch (\Throwable $e) {
            return Json::error($response, 'move_failed', 'Nepodařilo se přesunout soubor: ' . $e->getMessage(), 500);
        }

        try {
            $result = $this->converter->process($tmpPath, $sid);
        } catch (\RuntimeException $e) {
            @unlink($tmpPath);
            return Json::error($response, 'conversion_failed', $e->getMessage(), 400);
        } finally {
            @unlink($tmpPath);
        }

        // Update DB — `logo_path` (relativní k rootDir)
        $this->db->pdo()->prepare('UPDATE supplier SET logo_path = ? WHERE id = ?')
            ->execute([$result['logo_path'], $sid]);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('supplier.email_logo_uploaded', $userId, 'supplier', $sid, [
            'width'  => $result['width'],
            'height' => $result['height'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'logo_path' => $result['logo_path'],
            'width'     => $result['width'],
            'height'    => $result['height'],
        ]);
    }

    /** DELETE /api/settings/email-branding/logo */
    public function deleteLogo(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí měnit branding.', 403);
        }
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }

        $this->converter->delete($sid);
        $this->db->pdo()->prepare('UPDATE supplier SET logo_path = NULL WHERE id = ?')->execute([$sid]);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('supplier.email_logo_deleted', $userId, 'supplier', $sid, [], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * GET /api/settings/email-branding/preview?locale=cs
     *
     * Vrátí HTML rendered email layout se sample obsahem — pro live preview iframe v UI.
     * Používá AKTUÁLNÍ stav v DB (pokud admin chce preview rozpracované změny, musí nejdřív
     * Save → Refresh preview).
     */
    public function preview(Request $request, Response $response): Response
    {
        // Admin role guard — preview čte soubor z disku přes file_get_contents
        // a vrací bytes base64-encoded v inline `<img>`. Bez tohohle guardu by readonly
        // user mohl exfiltrovat libovolný soubor, který admin předem podstrčil přes
        // logo_path mass-assign (CWE-915 + CWE-538, security report @andrejtomci #2).
        // Mass-assign na logo_path je už uzavřený v SettingsAction, ale tohle je
        // druhá vrstva — preview je čistě admin funkce (live edit brandingu).
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí prohlížet branding.', 403);
        }
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }
        $query = $request->getQueryParams();
        $locale = ((string) ($query['locale'] ?? 'cs')) === 'en' ? 'en' : 'cs';
        $brandingProfileId = isset($query['branding_profile_id']) && $query['branding_profile_id'] !== ''
            ? (int) $query['branding_profile_id']
            : $this->defaultProfileId($sid);

        // Načti supplier kontext
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.company_name, s.display_name, s.tagline, s.street, s.city, s.zip,
                    s.email, s.phone, s.web,
                    s.email_branding_enabled, s.email_accent_color, s.logo_path,
                    co.name_cs AS country
               FROM supplier s
          LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);

        $supplier = [
            'company_name'           => (string) $row['company_name'],
            'display_name'           => $row['display_name'] ?: null,
            'tagline'                => $row['tagline'] ?: null,
            'street'                 => (string) $row['street'],
            'city'                   => (string) $row['city'],
            'zip'                    => (string) $row['zip'],
            'country'                => $row['country'] ?: null,
            'email'                  => $row['email'] ?: null,
            'phone'                  => $row['phone'] ?: null,
            'web'                    => $row['web'] ?: null,
            'email_branding_enabled' => (bool) $row['email_branding_enabled'],
            'email_accent_color'     => (string) ($row['email_accent_color'] ?: '#3B2D83'),
            'logo_path'              => $row['logo_path'] ?: null,
            'email_footer'           => null,
        ];

        if ($brandingProfileId !== null) {
            $profileStmt = $this->db->pdo()->prepare(
                'SELECT bp.display_name, bp.tagline, bp.email, bp.phone, bp.web, bp.email_footer, bp.logo_path,
                        bp.accent_color, bp.branding_enabled, bp.pdf_logo_show_name
                   FROM branding_profiles bp
                   JOIN supplier s ON s.id = bp.supplier_id AND s.branding_profiles_enabled = 1
                  WHERE bp.id = ? AND bp.supplier_id = ? AND bp.is_active = 1'
            );
            $profileStmt->execute([$brandingProfileId, $sid]);
            $profile = $profileStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$profile) {
                return Json::error($response, 'branding_profile_not_found', 'Brandingový profil nenalezen.', 404);
            }
            foreach (['display_name', 'tagline', 'email', 'phone', 'web', 'email_footer', 'logo_path'] as $field) {
                if ($profile[$field] !== null && $profile[$field] !== '') {
                    $supplier[$field] = $profile[$field];
                }
            }
            $supplier['email_branding_enabled'] = (bool) $profile['branding_enabled'];
            $supplier['email_accent_color'] = (string) $profile['accent_color'];
            $supplier['pdf_logo_show_name'] = (bool) $profile['pdf_logo_show_name'];
        }
        $supplier['accent_soft'] = \MyInvoice\Service\Branding\AccentColor::emailBackground(
            $supplier['email_branding_enabled'],
            $supplier['email_accent_color'],
        );

        // Pro preview embedneme logo jako data: URI (klient ho vidí přímo v iframe).
        // + spočítáme display rozměry pro HTML width/height (CSS max-height email
        // klienti často ignorují, attributy ne).
        $supplier['logo_inline_src']     = null;
        $supplier['logo_display_width']  = null;
        $supplier['logo_display_height'] = null;
        if ($supplier['logo_path']) {
            // SafeLogoPath: validuje že path je `storage/supplier-logos/sup-{sid}.{ext}`,
            // realpath neutekl mimo, extension v allowlistu (viz security report #2).
            $abs = \MyInvoice\Service\Mail\SafeLogoPath::resolve((string) $supplier['logo_path'], $sid);
            if ($abs !== null) {
                $bytes = (string) @file_get_contents($abs);
                if ($bytes !== '') {
                    $supplier['logo_inline_src'] = 'data:image/png;base64,' . base64_encode($bytes);
                }
                $info = @getimagesize($abs);
                if ($info !== false && (int) $info[1] > 0) {
                    $targetH = 48;
                    $supplier['logo_display_height'] = $targetH;
                    $supplier['logo_display_width']  = max(1, (int) round((int) $info[0] * $targetH / (int) $info[1]));
                }
            }
        }

        // Twig
        $loader = new FilesystemLoader(Bootstrap::rootDir() . '/api/templates/email');
        $twig = new Environment($loader, ['autoescape' => 'html', 'cache' => false, 'strict_variables' => false]);

        // Sample obsah
        $vars = [
            'locale'   => $locale,
            'subject'  => $locale === 'en' ? 'Invoice 2026005 — sample preview' : 'Faktura 2026005 — ukázka náhledu',
            'supplier' => $supplier,
        ];
        // Inline sample obsah dědící z _layout. Obsahuje částku + tlačítko obarvené
        // přes {{ accent }}, aby náhled reprezentativně ukázal branding barvu i v těle
        // (ne jen v hlavičce/patičce) — stejně jako reálný invoice_send.
        $sample = $locale === 'en'
            ? <<<'TWIG'
{% extends '_layout.html.twig' %}
{% block content %}
<p>Hello,</p>
<p>Please find attached invoice <strong>2026005</strong>.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;background:{{ accent_soft }};border:1px solid #E5E0F4;border-radius:8px;">
  <tr><td style="padding:16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
      <tr><td style="padding:4px 0;color:#7A748C;">Due date</td><td style="padding:4px 0;font-weight:600;text-align:right;">2026-05-21</td></tr>
      <tr><td style="padding:8px 0 0;color:#7A748C;border-top:1px solid #E5E0F4;">Amount due</td>
          <td style="padding:8px 0 0;border-top:1px solid #E5E0F4;font-size:18px;font-weight:700;color:{{ accent }};font-family:monospace;text-align:right;">1,000.00 CZK</td></tr>
    </table>
  </td></tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0;"><tr>
  <td style="border-radius:8px;background:{{ accent }};"><a href="#" style="display:inline-block;padding:12px 24px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">View invoice</a></td>
</tr></table>
<p>Thank you,<br>{{ supplier.display_name|default(supplier.company_name) }}</p>
{% endblock %}
TWIG
            : <<<'TWIG'
{% extends '_layout.html.twig' %}
{% block content %}
<p>Dobrý den,</p>
<p>v příloze posíláme fakturu <strong>2026005</strong>.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;background:{{ accent_soft }};border:1px solid #E5E0F4;border-radius:8px;">
  <tr><td style="padding:16px 20px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
      <tr><td style="padding:4px 0;color:#7A748C;">Splatnost</td><td style="padding:4px 0;font-weight:600;text-align:right;">21.05.2026</td></tr>
      <tr><td style="padding:8px 0 0;color:#7A748C;border-top:1px solid #E5E0F4;">K úhradě</td>
          <td style="padding:8px 0 0;border-top:1px solid #E5E0F4;font-size:18px;font-weight:700;color:{{ accent }};font-family:monospace;text-align:right;">1 000,00 Kč</td></tr>
    </table>
  </td></tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0;"><tr>
  <td style="border-radius:8px;background:{{ accent }};"><a href="#" style="display:inline-block;padding:12px 24px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">Zobrazit fakturu</a></td>
</tr></table>
<p>Děkujeme,<br>{{ supplier.display_name|default(supplier.company_name) }}</p>
{% endblock %}
TWIG;

        $html = $twig->createTemplate($sample)->render($vars);

        $response->getBody()->write($html);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function isAdmin(Request $request): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['role']) && $user['role'] === 'admin';
    }

    private function defaultProfileId(int $supplierId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT CASE WHEN branding_profiles_enabled = 1 THEN default_branding_profile_id ELSE NULL END
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $id = $stmt->fetchColumn();
        return $id !== false && $id !== null ? (int) $id : null;
    }
}
