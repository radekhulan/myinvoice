<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BrandingProfileRepository;
use MyInvoice\Service\Branding\BrandingProfileValidation;
use MyInvoice\Service\Mail\SupplierLogoConverter;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Extension\SandboxExtension;
use Twig\Sandbox\SecurityPolicy;

final class BrandingProfilesAction
{
    private const MAX_FILE_SIZE = 1_048_576;

    public function __construct(
        private readonly BrandingProfileRepository $profiles,
        private readonly SupplierLogoConverter $logoConverter,
        private readonly InvoicePdfRenderer $invoicePdf,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->supplierId($request);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        return Json::ok($response, $this->profiles->listForSupplier($supplierId));
    }

    public function publicList(Request $request, Response $response): Response
    {
        $supplierId = $this->supplierId($request);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        $profiles = array_map(static function (array $profile): array {
            unset($profile['email_profile_id'], $profile['has_invoice_template']);
            return $profile;
        }, $this->profiles->listForSupplier($supplierId, true));
        return Json::ok($response, $profiles);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = $this->supplierId($request);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = BrandingProfileValidation::validate($body);
        if ($errors !== []) return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        try {
            $id = $this->profiles->create($supplierId, $body);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return Json::error($response, 'profile_conflict', 'Brandingový profil s tímto názvem už existuje.', 409);
            }
            throw $e;
        }
        return Json::ok($response, $this->profiles->findForSupplier($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->profiles->findForSupplier($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $current = $this->profiles->findForSupplier($id, $supplierId);
        if (($current['is_default'] ?? false) && array_key_exists('is_active', $body) && empty($body['is_active'])) {
            return Json::error($response, 'default_profile_required', 'Výchozí brandingový profil nelze deaktivovat. Nejprve nastav jiný jako výchozí.', 409);
        }
        $errors = BrandingProfileValidation::validate($body, true);
        if ($errors !== []) return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        try {
            $this->profiles->update($id, $supplierId, $body);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return Json::error($response, 'profile_conflict', 'Brandingový profil s tímto názvem už existuje.', 409);
            }
            throw $e;
        }
        if (($current['is_default'] ?? false)) {
            $this->profiles->mirrorLegacyBranding($supplierId, $body);
        }
        return Json::ok($response, $this->profiles->findForSupplier($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $profile = $this->profiles->findForSupplier($id, $supplierId);
        if (($profile['is_default'] ?? false)) {
            return Json::error($response, 'default_profile_required', 'Výchozí brandingový profil nelze smazat. Nejprve nastav jiný jako výchozí.', 409);
        }
        $deleted = $this->profiles->delete($id, $supplierId);
        if (!$deleted) return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        return Json::ok($response, ['deleted' => true]);
    }

    public function setDefault(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if (!$this->profiles->setDefault($id, $supplierId)) {
            return Json::error($response, 'not_found', 'Aktivní brandingový profil nenalezen.', 404);
        }
        $profile = $this->profiles->findForSupplier($id, $supplierId);
        if ($profile !== null) {
            $this->profiles->mirrorLegacyBranding($supplierId, $profile);
            $this->profiles->setSupplierLogoPath($supplierId, $profile['logo_path'] ?? null);
        }
        $this->invoicePdf->invalidateDraftsBySupplier($supplierId);
        return Json::ok($response, $profile);
    }

    public function uploadLogo(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->profiles->findForSupplier($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return Json::error($response, 'no_file', 'Žádný soubor nebyl odeslán (pole `file`).', 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_failed', 'Nahrání souboru selhalo.', 400);
        }
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            return Json::error($response, 'invalid_file_size', 'Logo musí mít 1 B až 1 MiB.', 413);
        }
        $tmpPath = sys_get_temp_dir() . '/.branding-upload-' . bin2hex(random_bytes(8));
        try {
            $file->moveTo($tmpPath);
            $result = $this->logoConverter->process($tmpPath, $supplierId, $id);
            $this->profiles->setLogoPath($id, $supplierId, $result['logo_path']);
            if (($this->profiles->findForSupplier($id, $supplierId)['is_default'] ?? false)) {
                $this->profiles->setSupplierLogoPath($supplierId, $result['logo_path']);
            }
        } catch (\RuntimeException $e) {
            return Json::error($response, 'conversion_failed', $e->getMessage(), 400);
        } finally {
            @unlink($tmpPath);
        }
        return Json::ok($response, $this->profiles->findForSupplier($id, $supplierId));
    }

    public function deleteLogo(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if (!$this->profiles->setLogoPath($id, $supplierId, null)) {
            return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        }
        if (($this->profiles->findForSupplier($id, $supplierId)['is_default'] ?? false)) {
            $this->profiles->setSupplierLogoPath($supplierId, null);
        }
        // Soubor záměrně nemažeme: vystavené faktury jej mohou mít ve snapshotu.
        return Json::ok($response, $this->profiles->findForSupplier($id, $supplierId));
    }

    public function getInvoiceTemplate(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $template = $this->profiles->invoiceTemplate((int) ($args['id'] ?? 0), $this->supplierId($request));
        if ($template === null) return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        $html = $template['html'];
        $css = $template['css'];
        return Json::ok($response, [
            'html' => $html ?: (string) file_get_contents(Bootstrap::rootDir() . '/api/templates/invoice/invoice.twig'),
            'css' => $css ?: (string) file_get_contents(Bootstrap::rootDir() . '/styles/invoice.css'),
            'has_override' => $html !== null,
        ]);
    }

    public function saveInvoiceTemplate(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->profiles->findForSupplier($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $html = (string) ($body['html'] ?? '');
        $css = (string) ($body['css'] ?? '');
        if ($html === '' || strlen($html) > 250_000 || strlen($css) > 100_000) {
            return Json::error($response, 'validation_failed', 'HTML je povinné; limit je 250 kB pro HTML a 100 kB pro CSS.', 400);
        }
        if (preg_match('~(?:src|file)\s*=\s*["\']?\s*(?:https?:|ftp:|file:|/|\\\\)~i', $html)
            || preg_match('~(?:url\s*\(|@import|file:)~i', $css)) {
            return Json::error(
                $response,
                'validation_failed',
                'Šablona nesmí načítat externí ani lokální soubory. Pro logo použij proměnnou logo_path.',
                400,
            );
        }
        try {
            $twig = new Environment(new ArrayLoader(), ['cache' => false]);
            $twig->addExtension(new SandboxExtension(new SecurityPolicy(
                ['if', 'for', 'set'],
                ['date', 'default', 'escape', 'e', 'filter', 'length', 'lower', 'nl2br', 'number_format', 'raw', 'replace', 'round', 'slice', 'trim', 'upper'],
                [], [], ['t'],
            ), true));
            $twig->createTemplate($html, 'branding-invoice.twig');
            $this->invoicePdf->previewTemplate($html, $css, $supplierId);
        } catch (\Throwable $e) {
            return Json::error($response, 'validation_failed', 'Šablonu nelze vyrenderovat: ' . $e->getMessage(), 400);
        }
        $this->profiles->saveInvoiceTemplate($id, $supplierId, $html, $css);
        $this->invoicePdf->invalidateDraftsBySupplier($supplierId);
        return Json::ok($response, ['saved' => true]);
    }

    public function previewInvoiceTemplate(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = $this->supplierId($request);
        if ($this->profiles->findForSupplier((int) ($args['id'] ?? 0), $supplierId) === null) {
            return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $html = (string) ($body['html'] ?? '');
        $css = (string) ($body['css'] ?? '');
        if ($html === '' || strlen($html) > 250_000 || strlen($css) > 100_000) {
            return Json::error($response, 'validation_failed', 'HTML je povinné; limit je 250 kB pro HTML a 100 kB pro CSS.', 400);
        }
        if (preg_match('~(?:src|file)\s*=\s*["\']?\s*(?:https?:|ftp:|file:|/|\\\\)~i', $html)
            || preg_match('~(?:url\s*\(|@import|file:)~i', $css)) {
            return Json::error($response, 'validation_failed', 'Šablona nesmí načítat externí ani lokální soubory.', 400);
        }
        try {
            $pdf = $this->invoicePdf->previewTemplate($html, $css, $supplierId);
        } catch (\Throwable $e) {
            return Json::error($response, 'validation_failed', 'Náhled nelze vyrenderovat: ' . $e->getMessage(), 400);
        }
        $response->getBody()->write($pdf);
        return $response->withHeader('Content-Type', 'application/pdf')->withHeader('Content-Disposition', 'inline; filename="branding-preview.pdf"');
    }

    public function resetInvoiceTemplate(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        if (!$this->profiles->resetInvoiceTemplate((int) ($args['id'] ?? 0), $this->supplierId($request))) {
            return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
        }
        $this->invoicePdf->invalidateDraftsBySupplier($this->supplierId($request));
        return Json::ok($response, ['deleted' => true]);
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function isAdmin(Request $request): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ($user['role'] ?? '') === 'admin';
    }
}
