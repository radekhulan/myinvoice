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
 * Role-based access control (RBAC).
 *
 * Hierarchie: admin > accountant > readonly. Authorization model:
 *
 *   - readonly:  GET kdekoliv + vlastní účet (logout, change-password, totp/*)
 *   - accountant: vše co readonly + mutace na business datech (clients, projects,
 *                 invoices, work-reports, bank-statements/transactions, ARES/VIES lookup)
 *   - admin:     vše + admin endpointy (users, settings, codebooks, email-templates,
 *                activity-log, invoices-zip, bank/scan)
 *
 * AuthMiddleware už zajistil, že je uživatel přihlášen (jinak public path).
 * Tento middleware běží PO Auth a kontroluje minimální roli pro danou kombinaci
 * method+path.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    /** Cesty, kde RBAC neaplikujeme (public + self-service). */
    private const PUBLIC_OR_SELF = [
        '/api/health',
        '/api/version',
        '/api/openapi.yaml',
        '/api/docs',
        '/api/reference',
        '/api/auth/setup-status',
        '/api/auth/setup',
        '/api/auth/setup-ares-lookup',
        '/api/auth/setup-crpdph-lookup',
        '/api/auth/setup-sample',
        '/api/auth/login',
        '/api/auth/logout',
        '/api/auth/me',
        '/api/auth/forgot',
        '/api/auth/reset',
        '/api/auth/change-password',
        '/api/auth/totp/status',
        '/api/auth/totp/setup',
        '/api/auth/totp/enable',
        '/api/csrf-token',
    ];

    /**
     * Endpointy, které vyžadují roli 'accountant' nebo vyšší (povolují i admin).
     * Pokud není match, fallback je 'admin'.
     *
     * Formát: ['METHOD path-regex']
     * Method může být '*' pro libovolnou.
     */
    private const ACCOUNTANT_RULES = [
        // Klienti, zakázky, faktury, výkazy, banka — plná CRUD
        '* #^/api/clients(/|$)#',
        '* #^/api/projects(/|$)#',
        '* #^/api/invoices(/|$)#',
        '* #^/api/work-reports(/|$)#',
        '* #^/api/bank-statements(/|$)#',
        '* #^/api/bank-transactions(/|$)#',
        // Dokumenty — účetní smí zakládat/upravovat/mazat (do koše) + spravovat složky
        '* #^/api/documents(/|$)#',
        '* #^/api/document-folders(/|$)#',
        // Codebooks read-only přes API (admin endpointy mají zvláštní cestu /api/admin/codebooks)
        'GET #^/api/codebooks(/|$)#',
        // Vlastní podpisové profily účetních; Action vrstva hlídá feature flag i owner_user_id.
        '* #^/api/settings/signing/profiles(/|$)#',
        '* #^/api/settings/pdf-signing/user-defaults(/|$)#',
        // ZIP export může i účetní (read of mass PDF)
        'GET #^/api/admin/invoices-zip$#',
    ];

    /**
     * Endpointy povolené i pro 'readonly' (GET data + self-service).
     * Pokud match, povoleno všem rolím (tj. i readonly).
     */
    private const READONLY_RULES = [
        'GET *', // všechny GETy: čtení dat je dovolené
    ];

    public function __construct(
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        // OPTIONS / HEAD pouštíme dál (CORS preflight, monitoring)
        if ($method === 'OPTIONS' || $method === 'HEAD') {
            return $handler->handle($request);
        }

        // Self-service / public — Auth už dovnitř pustí jen oprávněné, role nás nezajímá
        if (in_array($path, self::PUBLIC_OR_SELF, true)
            || str_starts_with($path, '/api/public/')
        ) {
            return $handler->handle($request);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $role = (string) ($user['role'] ?? '');

        // Bez role = bez přístupu (Auth měl už 401, ale defensive)
        if ($role === '') {
            $response = $this->responseFactory->createResponse(401);
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        // admin smí všechno
        if ($role === 'admin') {
            return $handler->handle($request);
        }

        // accountant: matchuj ACCOUNTANT_RULES + READONLY_RULES
        if ($role === 'accountant') {
            if ($this->matchesAny($method, $path, self::ACCOUNTANT_RULES)) {
                return $handler->handle($request);
            }
            if ($this->matchesAny($method, $path, self::READONLY_RULES)) {
                return $handler->handle($request);
            }
        }

        // readonly: jen READONLY_RULES
        if ($role === 'readonly') {
            if ($this->matchesAny($method, $path, self::READONLY_RULES)) {
                return $handler->handle($request);
            }
        }

        // Cokoliv jiného (např. admin endpointy pro non-admin role) → 403
        $response = $this->responseFactory->createResponse(403);
        return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
    }

    /**
     * @param list<string> $rules
     */
    private function matchesAny(string $method, string $path, array $rules): bool
    {
        foreach ($rules as $rule) {
            [$ruleMethod, $rulePattern] = explode(' ', $rule, 2);
            if ($ruleMethod !== '*' && $ruleMethod !== $method) continue;
            if ($rulePattern === '*') return true;
            if (preg_match($rulePattern, $path) === 1) return true;
        }
        return false;
    }
}
