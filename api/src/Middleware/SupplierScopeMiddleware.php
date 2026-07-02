<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\UserSupplierAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Multi-supplier scope: čte hlavičku `X-Supplier-Id` (z Pinia stores na FE) a
 * vystaví ji jako request attribute. Akce čtou přes:
 *
 *   $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
 *
 * Pravidla:
 *   - Pokud header chybí nebo není v DB, fallback = MIN(supplier.id) (= "default supplier")
 *   - Pokud supplier tabulka prázdná (před setup) → 0 (akce by stejně měly být chráněné Authem)
 *   - Validace existence se cachuje v rámci request (jeden DB hit)
 *
 * Per-user omezení na dodavatele (user_supplier_access):
 *   - Omezený uživatel (má záznamy, není admin): header/query mimo povolený set → 403;
 *     chybějící header → fallback MIN(povolených) místo MIN(všech).
 *   - Na cestách, kde se scope dle spec ignoruje (SCOPE_IGNORED_PREFIXES — /auth/*,
 *     /codebooks/* …), se mimo-setová hodnota tiše koriguje na MIN(povolených),
 *     aby si FE přes /auth/me srovnal uložený výběr a uživatel se nezamknul.
 *   - API token vázaný na supplier-a mimo povolený set vlastníka → 403.
 */
final class SupplierScopeMiddleware implements MiddlewareInterface
{
    public const ATTR_CURRENT_ID = 'supplier.current_id';
    public const HEADER_NAME     = 'X-Supplier-Id';

    /** Cesty, kde se supplier scope dle source/04-api.md ignoruje (žádné 403, jen tichá korekce). */
    private const SCOPE_IGNORED_PREFIXES = [
        '/api/auth/',
        '/api/health',
        '/api/version',
        '/api/codebooks',
        '/api/public/',
        '/api/suppliers',
        '/api/csrf-token',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierAccess $access,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        // Ceremonie webauthn/mfa/session běží mimo supplier scope — bypass musí zůstat
        // PŘED vyhodnocením oprávnění, jinak by se do nich zatáhl DB dotaz i 403.
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/auth/webauthn/')
            || str_starts_with($path, '/api/auth/mfa/')
            || str_starts_with($path, '/api/auth/session/')
        ) {
            return $handler->handle($request);
        }

        // null = uživatel bez omezení (nepřihlášený kontext nebo admin) → chová se jako dosud.
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $allowed = $user === [] ? null : $this->access->allowedIdsForUser($user);

        // 0. Bearer (API token) — pokud je token bound na konkrétního supplier-a,
        //    forcuj ho a ignoruj header / query (token nesmí "skočit" do jiné firmy).
        $apiToken = $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN);
        if (is_array($apiToken) && ($apiToken['supplier_id'] ?? null) !== null) {
            $tokenSid = (int) $apiToken['supplier_id'];
            if ($allowed !== null && !in_array($tokenSid, $allowed, true)) {
                return $this->forbidden();
            }
            return $handler->handle(
                $request->withAttribute(self::ATTR_CURRENT_ID, $tokenSid),
            );
        }

        // 1. Header X-Supplier-Id (axios v SPA)
        $headerVal = trim($request->getHeaderLine(self::HEADER_NAME));
        $requested = ctype_digit($headerVal) ? (int) $headerVal : 0;

        // 2. Fallback: query param ?supplier_id=N (přímá navigace v prohlížeči — PDF download, ZIP export apod.)
        if ($requested === 0) {
            $q = $request->getQueryParams();
            $qVal = isset($q['supplier_id']) ? trim((string) $q['supplier_id']) : '';
            if (ctype_digit($qVal)) {
                $requested = (int) $qVal;
            }
        }

        // Omezený uživatel: povolený set je zdroj pravdy (FK garantuje existenci id).
        if ($allowed !== null) {
            if ($requested > 0 && !in_array($requested, $allowed, true)) {
                if (!$this->scopeIgnored($request->getUri()->getPath())) {
                    return $this->forbidden();
                }
                $requested = 0;
            }
            $resolved = $requested > 0 ? $requested : min($allowed);
            return $handler->handle(
                $request->withAttribute(self::ATTR_CURRENT_ID, $resolved),
            );
        }

        $resolved = $this->resolve($requested);

        return $handler->handle(
            $request->withAttribute(self::ATTR_CURRENT_ID, $resolved),
        );
    }

    /**
     * Vrátí platné supplier_id:
     *  - $requested pokud existuje v DB
     *  - jinak MIN(id)
     *  - jinak 0 (před setup)
     */
    private function resolve(int $requested): int
    {
        $pdo = $this->db->pdo();

        if ($requested > 0) {
            $stmt = $pdo->prepare('SELECT id FROM supplier WHERE id = ? LIMIT 1');
            $stmt->execute([$requested]);
            $id = (int) $stmt->fetchColumn();
            if ($id > 0) return $id;
        }

        return (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
    }

    private function scopeIgnored(string $path): bool
    {
        foreach (self::SCOPE_IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) return true;
        }
        return false;
    }

    private function forbidden(): Response
    {
        $response = $this->responseFactory->createResponse(403);
        return Json::error($response, 'supplier_forbidden', 'K tomuto dodavateli nemáš přístup.', 403);
    }
}
