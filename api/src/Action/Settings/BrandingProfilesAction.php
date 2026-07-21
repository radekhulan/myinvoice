<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

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
        if (!$this->profiles->isEnabled($supplierId)) return Json::ok($response, []);
        $profiles = array_map(static function (array $profile): array {
            unset($profile['email_profile_id']);
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
        return Json::ok($response, $this->profiles->findForSupplier($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
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
        // Soubor záměrně nemažeme: vystavené faktury jej mohou mít ve snapshotu.
        return Json::ok($response, $this->profiles->findForSupplier($id, $supplierId));
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
