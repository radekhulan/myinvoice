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
            'SELECT id, company_name, ic, is_vat_payer, taxpayer_type,
                    default_payment_due_days, default_payment_due_unit, default_prices_include_vat,
                    auto_send_reminders, payment_thanks_enabled, payment_thanks_default_checked
               FROM supplier ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($suppliers as &$s) {
            $s['id']                       = (int) $s['id'];
            $s['is_vat_payer']             = (bool) $s['is_vat_payer'];
            // 'fo' = OSVČ (fyzická osoba), 'po' = s.r.o. (právnická osoba), null = nenastaveno.
            $s['taxpayer_type']            = $s['taxpayer_type'] !== null ? (string) $s['taxpayer_type'] : null;
            $s['default_payment_due_days'] = (int) $s['default_payment_due_days'];
            $s['default_payment_due_unit'] = (string) ($s['default_payment_due_unit'] ?? 'days');
            // Výchozí režim cen u nových faktur (0 = bez DPH, 1 = ceny s DPH) — předvyplní editor.
            $s['default_prices_include_vat'] = (bool) ($s['default_prices_include_vat'] ?? false);
            // Per-faktura přepínač upomínek v editoru se skryje, když dodavatel auto-upomínky nemá.
            $s['auto_send_reminders']      = (bool) ($s['auto_send_reminders'] ?? true);
            // Děkovný e-mail (issue #57) — UI v mark-paid modalu podle nich zobrazí checkbox.
            $s['payment_thanks_enabled']         = (bool) ($s['payment_thanks_enabled'] ?? false);
            $s['payment_thanks_default_checked'] = (bool) ($s['payment_thanks_default_checked'] ?? false);
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
