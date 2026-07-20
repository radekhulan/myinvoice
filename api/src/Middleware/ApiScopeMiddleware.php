<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Enforce token scopes + path allowlist pro bearer-authed requesty.
 *
 * 1) Path allowlist — bearer token smí volat JEN dokumentovaný veřejný subset
 *    (`/api/v1/*`, po přepisu `ApiVersionRewriteMiddleware` = `/api/*`). Interní
 *    plocha (`/api/admin/*`, správa tokenů, login/totp/change-password, citlivá
 *    nastavení signing/email-branding/IMAP) je pro token nedostupná, i kdyby ho
 *    vytvořil admin. Tím se uniklý token nedostane mimo veřejné API.
 * 2) Scope:
 *      GET / HEAD                 → vyžaduje `read` (každý token splňuje)
 *      POST / PUT / PATCH / DELETE → vyžaduje `read_write`
 *
 * Session auth (browser SPA) tímto MW není dotčen — uživatel má plná práva své role.
 *
 * Běží AŽ PO AuthMiddleware (potřebuje načtený api_token attribute).
 */
final class ApiScopeMiddleware implements MiddlewareInterface
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Whitelist veřejných API cest dostupných přes bearer token. Zrcadlí
     * `openapi.yaml` na úrovni zdrojových skupin. Cokoliv, co sem nepasuje
     * (zejména `/api/admin/*`, `/api/auth/*` kromě `api-me`, a interní
     * `/api/settings/*` mimo číselníky), je pro token zakázané → 403.
     *
     * Pozn.: cesty jsou už přepsané z `/api/v1/...` na `/api/...` (viz
     * ApiVersionRewriteMiddleware, běží jako outermost).
     *
     * @var list<string>
     */
    private const BEARER_ALLOWED = [
        // Systémové + dokumentace + connection-test
        '#^/api/health$#',
        '#^/api/version$#',
        '#^/api/openapi\.yaml$#',
        '#^/api/docs$#',
        '#^/api/reference$#',
        '#^/api/scalar$#',
        '#^/api/auth/api-me$#',
        // Byznys zdroje
        '#^/api/clients(/|$)#',
        '#^/api/projects(/|$)#',
        '#^/api/invoices(/|$)#',
        '#^/api/purchase-invoices(/|$)#',
        '#^/api/recurring(/|$)#',
        '#^/api/bank-statements(/|$)#',
        '#^/api/bank-transactions(/|$)#',
        '#^/api/logbook(/|$)#',
        '#^/api/documents(/|$)#',
        '#^/api/document-folders(/|$)#',
        '#^/api/suppliers(/|$)#',
        // Dashboard / CRM / reporty (čtení + exporty)
        '#^/api/dashboard(/|$)#',
        '#^/api/crm(/|$)#',
        '#^/api/reports(/|$)#',
        '#^/api/tax(/|$)#',
        '#^/api/search$#',
        '#^/api/branding-profiles$#',
        // Číselníky
        '#^/api/codebooks(/|$)#',
        '#^/api/expense-categories(/|$)#',
        '#^/api/revenue-categories(/|$)#',
        '#^/api/vat-classifications(/|$)#',
        // Nastavení — JEN veřejný subset (supplier + číselníky), NE signing/
        // pdf-signing/email-branding/bank-email-notices.
        '#^/api/settings/supplier$#',
        '#^/api/settings/supplier/invoice-counter$#',
        '#^/api/settings/supplier/logo$#',
        '#^/api/settings/currencies(/|$)#',
        '#^/api/settings/vat-rates(/|$)#',
        '#^/api/settings/units(/|$)#',
        '#^/api/settings/countries(/|$)#',
    ];

    public function __construct(
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'bearer') {
            return $handler->handle($request);
        }

        $path   = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        // 1) Path allowlist — token mimo veřejný subset → 403 (vrací se 403, ne 404,
        //    aby integrátor dostal jasný signál; samotná existence interních cest
        //    není tajemství — jsou zdokumentované jako session-only).
        if (!$this->isBearerAllowed($path)) {
            $response = $this->responseFactory->createResponse(403);
            return Json::error(
                $response,
                'token_endpoint_forbidden',
                'API token má přístup pouze k veřejnému API (/api/v1). Tento endpoint je dostupný jen z webového rozhraní.',
                403,
            );
        }

        // 2) Scope
        if (in_array($method, self::READ_METHODS, true)) {
            return $handler->handle($request);
        }

        $apiToken = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
        $scope    = (string) ($apiToken['scope'] ?? '');

        if ($scope !== 'read_write') {
            $response = $this->responseFactory->createResponse(403);
            return Json::error(
                $response,
                'insufficient_scope',
                'API token nemá scope `read_write` pro tuto operaci.',
                403,
            );
        }

        return $handler->handle($request);
    }

    private function isBearerAllowed(string $path): bool
    {
        foreach (self::BEARER_ALLOWED as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }
}
