<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BrandingProfileRepository;
use MyInvoice\Service\Branding\BrandingProfileValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BrandingProfilesAction
{
    public function __construct(private readonly BrandingProfileRepository $profiles) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->supplierId($request);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        return Json::ok($response, $this->profiles->listForSupplier($supplierId));
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
        $deleted = $this->profiles->delete((int) ($args['id'] ?? 0), $this->supplierId($request));
        if (!$deleted) return Json::error($response, 'not_found', 'Brandingový profil nenalezen.', 404);
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
