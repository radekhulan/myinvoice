<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth\Tokens;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/auth/tokens — vytvoří nový API token.
 *
 * Body: { name, supplier_id?, scope: 'read'|'read_write', expires_at?, totp_code? }
 *
 * Vyžaduje:
 *   - aktivní session (NE bearer — token by si nemohl vytvořit další token)
 *   - pokud user má `totp_enabled=1`, vyžaduje `totp_code` (step-up auth)
 *
 * Response: plaintext token JEN v této response, nikdy už znovu.
 */
final class CreateTokenAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ApiTokenService $tokens,
        private readonly TotpService $totp,
        private readonly SecretEncryption $crypto,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly BruteForceGuard $bruteForce,
        private readonly MfaProtectedOperationService $protectedOperations,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // Bearer auth nesmí vytvářet další tokeny (escalation guard)
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error($response, 'forbidden_via_token', 'API tokeny lze spravovat jen z webového rozhraní.', 403);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        $supplierId = isset($body['supplier_id']) && $body['supplier_id'] !== ''
            ? (int) $body['supplier_id']
            : null;
        // Default least-privilege: bez explicitního scope je token jen pro čtení.
        $scope = (string) ($body['scope'] ?? 'read');
        $expiresRaw = trim((string) ($body['expires_at'] ?? ''));
        $totpCode = trim((string) ($body['totp_code'] ?? ''));
        $stepUpToken = trim((string) ($body['step_up_token'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            return Json::error($response, 'validation_failed', 'Název tokenu musí mít 1–100 znaků.', 400);
        }
        if (!in_array($scope, ['read', 'read_write'], true)) {
            return Json::error($response, 'validation_failed', 'Neplatný scope.', 400);
        }

        // Validace supplier_id musí předcházet spotřebě jednorázového proofu.
        if ($supplierId !== null) {
            $check = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id = ?');
            $check->execute([$supplierId]);
            if ($check->fetchColumn() === false) {
                return Json::error($response, 'validation_failed', 'Supplier nenalezen.', 400);
            }
        }

        // Parse expires_at — akceptuj ISO-8601 nebo YYYY-MM-DD.
        $expiresAt = null;
        if ($expiresRaw !== '') {
            try {
                $expiresAt = new \DateTimeImmutable($expiresRaw);
            } catch (\Exception) {
                return Json::error($response, 'validation_failed', 'Neplatný formát expires_at.', 400);
            }
            if ($expiresAt <= new \DateTimeImmutable()) {
                return Json::error($response, 'validation_failed', 'expires_at musí být v budoucnu.', 400);
            }
        }

        // Účelový step-up: jednorázový passkey/TOTP proof, nebo kompatibilní
        // přímé TOTP ověření v tomto requestu.
        $stmt = $this->db->pdo()->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        // Zaregistrovaný TOTP je step-up požadavek bez ohledu na allowed_mfa_methods.
        // Jinak by zúžení seznamu na ['passkey'] u uživatele bez passkey step-up
        // úplně zrušilo a token by vznikl jen z heslové session.
        $totpAvailable = (int) ($u['totp_enabled'] ?? 0) === 1
            && !empty($u['totp_secret']);
        $passkeyAvailable = $this->mfaPolicy->isMethodAllowed('passkey')
            && $this->credentials->countActiveForUser($userId) > 0;

        $out = null;
        if ($stepUpToken !== '') {
            try {
                $out = $this->protectedOperations->createApiToken(
                    $userId,
                    (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                    $stepUpToken,
                    $supplierId,
                    $name,
                    $scope,
                    $expiresAt,
                );
            } catch (OneTimeTokenException|StepUpOperationException) {
                return Json::error(
                    $response,
                    'step_up_proof_invalid',
                    'Step-up ověření je neplatné nebo již bylo použito.',
                    403,
                );
            } catch (\DomainException) {
                return Json::error($response, 'session_expired', 'Session vypršela.', 401);
            }
        } elseif ($totpAvailable) {
            if ($totpCode === '') {
                return Json::error(
                    $response,
                    $passkeyAvailable ? 'step_up_required' : 'totp_required',
                    'Pro vytvoření tokenu je vyžadováno nové ověření.',
                    401,
                    ['methods' => $passkeyAvailable ? ['passkey', 'totp'] : ['totp']],
                );
            }
            if ($this->bruteForce->isTotpLocked($userId)) {
                return Json::error(
                    $response,
                    'too_many_attempts',
                    'Příliš mnoho TOTP pokusů. Zkus to později.',
                    429,
                );
            }
            try {
                $secret = $this->crypto->decrypt((string) $u['totp_secret']);
            } catch (\RuntimeException) {
                return Json::error($response, 'server_error', 'Chyba konfigurace serveru.', 500);
            }
            if (!$this->totp->verify($secret, $totpCode)) {
                $this->bruteForce->recordTotpFailure($userId);
                return Json::error($response, 'invalid_code', 'Neplatný TOTP kód.', 401);
            }
            $this->bruteForce->recordTotpSuccess($userId);
        } elseif ($passkeyAvailable) {
            return Json::error(
                $response,
                'step_up_required',
                'Pro vytvoření tokenu je vyžadováno nové ověření.',
                401,
                ['methods' => ['passkey']],
            );
        }

        $out ??= $this->tokens->generate($userId, $supplierId, $name, $scope, $expiresAt);

        $ip = $this->ipMatcher->clientIp(
            $request->getServerParams(),
            [],
            'X-Forwarded-For',
        );
        $this->activity->log(
            'api_token.created',
            $userId,
            'api_token',
            $out['id'],
            ['name' => $name, 'scope' => $scope, 'supplier_id' => $supplierId, 'prefix' => $out['prefix']],
            $ip,
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, [
            'token' => $out['plaintext'],
            'id'    => $out['id'],
            'prefix' => $out['prefix'],
            'warning' => 'Plain-text token se zobrazí pouze jednou. Ulož si ho — později už ho znovu neuvidíš.',
        ], 201);
    }
}
