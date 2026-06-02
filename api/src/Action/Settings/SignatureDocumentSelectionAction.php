<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\SigningProfileRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Per-dokumentový výběr podpisového profilu.
 *
 * Entity `work_report` používá ID faktury, ke které výkaz patří.
 */
final class SignatureDocumentSelectionAction
{
    private const ENTITY_TYPES = ['invoice', 'work_report'];
    private const SELECTION_SOURCES = ['logged_in_user', 'admin_profile_settings'];

    public function __construct(
        private readonly SigningProfileRepository $profiles,
        private readonly InvoiceRepository $invoices,
        private readonly ActivityLogger $logger,
        private readonly InvoicePdfRenderer $pdf,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $entityType = $this->entityType((string) ($args['entity_type'] ?? ''));
        if ($entityType === null) {
            return Json::error($response, 'unsupported_entity_type', 'Typ dokumentu není podporovaný.', 404);
        }

        $entityId = (int) ($args['id'] ?? 0);
        if (!$this->ownsEntity($request, $entityType, $entityId)) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }

        return Json::ok($response, $this->selectionPayload($request, $entityType, $entityId));
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->canWrite($request)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        $entityType = $this->entityType((string) ($args['entity_type'] ?? ''));
        if ($entityType === null) {
            return Json::error($response, 'unsupported_entity_type', 'Typ dokumentu není podporovaný.', 404);
        }

        $entityId = (int) ($args['id'] ?? 0);
        if (!$this->ownsEntity($request, $entityType, $entityId)) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }

        $supplierId = $this->supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $selectionSource = (string) ($body['selection_source'] ?? 'inherit');
        if ($selectionSource === 'inherit' || $selectionSource === '') {
            $this->profiles->deleteDocumentOverride($supplierId, 'pdf', $entityType, $entityId);
            $this->afterSelectionChanged($entityType, $entityId);
            $this->audit($request, $entityType, $entityId, 'inherit', null);

            return Json::ok($response, $this->selectionPayload($request, $entityType, $entityId));
        }
        if (!in_array($selectionSource, self::SELECTION_SOURCES, true)) {
            return Json::error($response, 'validation_failed', 'Nepodporovaný způsob výběru podpisového profilu.', 400);
        }

        $adminProfileId = $this->nullableInt($body['admin_profile_id'] ?? null);
        if ($adminProfileId !== null && !$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Konkrétní admin profil může nastavit pouze admin.', 403);
        }

        try {
            $this->profiles->upsertDocumentOverride(
                supplierId: $supplierId,
                usage: 'pdf',
                entityType: $entityType,
                entityId: $entityId,
                selectionSource: $selectionSource,
                adminProfileId: $adminProfileId,
                createdBy: $this->userId($request),
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\Throwable) {
            return Json::error($response, 'update_failed', 'Nastavení podpisu dokumentu se nepodařilo uložit.', 500);
        }

        $this->afterSelectionChanged($entityType, $entityId);
        $this->audit($request, $entityType, $entityId, $selectionSource, $adminProfileId);

        return Json::ok($response, $this->selectionPayload($request, $entityType, $entityId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->canWrite($request)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        $entityType = $this->entityType((string) ($args['entity_type'] ?? ''));
        if ($entityType === null) {
            return Json::error($response, 'unsupported_entity_type', 'Typ dokumentu není podporovaný.', 404);
        }

        $entityId = (int) ($args['id'] ?? 0);
        if (!$this->ownsEntity($request, $entityType, $entityId)) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }

        $this->profiles->deleteDocumentOverride($this->supplierId($request), 'pdf', $entityType, $entityId);
        $this->afterSelectionChanged($entityType, $entityId);
        $this->audit($request, $entityType, $entityId, 'inherit', null);

        return Json::ok($response, $this->selectionPayload($request, $entityType, $entityId));
    }

    /**
     * @return array<string,mixed>
     */
    private function selectionPayload(Request $request, string $entityType, int $entityId): array
    {
        $supplierId = $this->supplierId($request);
        $override = $this->profiles->documentOverride($supplierId, 'pdf', $entityType, $entityId);
        $outputSetting = $this->profiles->outputSetting($supplierId, $entityType);
        $selectionSource = (string) ($override['selection_source'] ?? 'inherit');
        $effectiveSelectionSource = $selectionSource === 'inherit'
            ? (string) $outputSetting['selection_source']
            : $selectionSource;
        $effectiveAdminProfileId = $effectiveSelectionSource === 'admin_profile_settings'
            ? ($override['admin_profile_id'] ?? $outputSetting['default_profile_id'])
            : null;

        return [
            'usage' => 'pdf',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'selection_source' => $selectionSource,
            'admin_profile_id' => $override['admin_profile_id'] ?? null,
            'inherited_selection_source' => $outputSetting['selection_source'],
            'inherited_admin_profile_id' => $outputSetting['default_profile_id'],
            'effective_selection_source' => $effectiveSelectionSource,
            'effective_admin_profile_id' => $effectiveAdminProfileId,
            'has_override' => $override !== null,
        ];
    }

    private function ownsEntity(Request $request, string $entityType, int $entityId): bool
    {
        if ($entityId <= 0) {
            return false;
        }

        $invoice = $this->invoices->find($entityId);
        if (!is_array($invoice)) {
            return false;
        }

        return (int) ($invoice['supplier_id'] ?? 0) === $this->supplierId($request);
    }

    private function afterSelectionChanged(string $entityType, int $entityId): void
    {
        if ($entityType === 'invoice') {
            $this->pdf->invalidate($entityId, 'invalidate_signature_selection');
        }
    }

    private function audit(Request $request, string $entityType, int $entityId, string $selectionSource, ?int $adminProfileId): void
    {
        $this->logger->log('signing.document_selection_updated', $this->userId($request), $entityType, $entityId, [
            'usage' => 'pdf',
            'selection_source' => $selectionSource,
            'admin_profile_id' => $adminProfileId,
        ], null, null, $this->supplierId($request));
    }

    private function entityType(string $entityType): ?string
    {
        return in_array($entityType, self::ENTITY_TYPES, true) ? $entityType : null;
    }

    /**
     * @param mixed $value
     */
    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function canWrite(Request $request): bool
    {
        $role = $this->role($request);
        return $role === 'admin' || $role === 'accountant';
    }

    private function isAdmin(Request $request): bool
    {
        return $this->role($request) === 'admin';
    }

    private function role(Request $request): string
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (string) ($user['role'] ?? '');
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }
}
