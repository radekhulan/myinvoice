<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Self-service TOTP (2FA) endpointy pro přihlášeného uživatele:
 *   POST /api/auth/totp/setup   — vygeneruje secret + QR (pending — uloží do DB ale enabled=0)
 *   POST /api/auth/totp/enable  — ověří kód a flipne totp_enabled=1
 *   GET  /api/auth/totp/status  — vrátí { enabled: bool }
 *
 * Disable: CLI `php api/bin/reset-2fa.php <email>` (fallback ručně v DB).
 */
final class TotpAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly TotpService $totp,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SecretEncryption $crypto,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if ($user === null) return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);

        return Json::ok($response, [
            'enabled' => (bool) $user['totp_enabled'],
        ]);
    }

    public function setup(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if ($user === null) return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);

        // Nový secret pokaždé — pokud už totp_enabled=1, vrať 409 (reset přes CLI)
        if ((int) $user['totp_enabled'] === 1) {
            return Json::error($response, 'already_enabled', 'TOTP už je aktivní. Pro reset použij: php api/bin/reset-2fa.php <email>.', 409);
        }

        $secret = TotpService::generateSecret();
        try {
            $encrypted = $this->crypto->encrypt($secret);
        } catch (\RuntimeException) {
            return Json::error($response, 'server_error', 'Chyba konfigurace serveru.', 500);
        }
        // Šifrované AES-256-GCM v DB; do response zasíláme plain (jednorázově pro setup)
        $this->db->pdo()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 0 WHERE id = ?')
            ->execute([$encrypted, (int) $user['id']]);

        $issuer = parse_url((string) $this->config->get('app.url', 'MyInvoice'), PHP_URL_HOST) ?: 'MyInvoice';
        $uri = $this->totp->provisioningUri($secret, (string) $user['email'], $issuer);

        // QR kód jako data URI (PNG base64) — frontend vloží do <img src>
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel'        => EccLevel::M,
            'scale'           => 6,
            'imageBase64'     => true,
            'quietzoneSize'   => 2,
        ]);
        $qrDataUri = (new QRCode($options))->render($uri);

        return Json::ok($response, [
            'secret'      => $secret,        // pro manuální vložení do app
            'uri'         => $uri,
            'qr_data_uri' => $qrDataUri,
            'issuer'      => $issuer,
        ]);
    }

    public function enable(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if ($user === null) return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);

        $body = (array) ($request->getParsedBody() ?? []);
        $code = trim((string) ($body['code'] ?? ''));
        if ($code === '') {
            return Json::error($response, 'validation_failed', 'Chybí kód.', 400);
        }
        if (empty($user['totp_secret'])) {
            return Json::error($response, 'no_secret', 'Nejdřív zavolej /setup pro vygenerování secretu.', 400);
        }
        try {
            $secret = $this->crypto->decrypt((string) $user['totp_secret']);
        } catch (\RuntimeException) {
            return Json::error($response, 'server_error', 'Chyba konfigurace serveru.', 500);
        }
        if (!$this->totp->verify($secret, $code)) {
            return Json::error($response, 'invalid_code', 'Neplatný TOTP kód.', 400);
        }

        $this->db->pdo()->prepare('UPDATE users SET totp_enabled = 1 WHERE id = ?')
            ->execute([(int) $user['id']]);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('auth.totp_enabled', (int) $user['id'], 'user', (int) $user['id'], null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['enabled' => true]);
    }

    private function user(Request $request): ?array
    {
        $u = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (empty($u)) return null;
        // Načti čerstvý záznam (auth middleware nedává totp_*)
        $stmt = $this->db->pdo()->prepare('SELECT id, email, totp_secret, totp_enabled FROM users WHERE id = ?');
        $stmt->execute([(int) $u['id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
