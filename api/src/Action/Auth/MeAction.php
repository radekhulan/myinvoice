<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MeAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
        $currentSupplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        $suppliers = $this->db->pdo()->query(
            'SELECT id, company_name, ic, is_vat_payer,
                    default_payment_due_days, default_payment_due_unit
               FROM supplier ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($suppliers as &$s) {
            $s['id']                       = (int) $s['id'];
            $s['is_vat_payer']             = (bool) $s['is_vat_payer'];
            $s['default_payment_due_days'] = (int) $s['default_payment_due_days'];
            $s['default_payment_due_unit'] = (string) ($s['default_payment_due_unit'] ?? 'days');
        }

        $totpEnabled  = (bool) ($user['totp_enabled'] ?? false);
        $requireTotp  = (bool) $this->config->get('auth.require_totp', false);
        $mustSetupTotp = $requireTotp && !$totpEnabled;

        return Json::ok($response, [
            'user' => [
                'id'              => (int) ($user['id'] ?? 0),
                'email'           => $user['email'] ?? '',
                'name'            => $user['name'] ?? '',
                'role'            => $user['role'] ?? 'readonly',
                'locale'          => $user['locale'] ?? 'cs',
                'totp_enabled'    => $totpEnabled,
                'must_setup_totp' => $mustSetupTotp,
            ],
            'csrf_token'          => $session['csrf_token'] ?? '',
            'current_supplier_id' => $currentSupplierId,
            'suppliers'           => $suppliers,
            'require_totp'        => $requireTotp,
        ]);
    }
}
