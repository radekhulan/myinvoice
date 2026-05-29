<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Per-supplier podpisový certifikát (PAdES):
 *   POST   /api/settings/signing-cert  — multipart upload P12/PFX (pole `file` + `password`)
 *   DELETE /api/settings/signing-cert  — odebrat certifikát
 *   GET    /api/settings/signing-cert  — metadata certu (CN, vydavatel, platnost)
 *
 * Toggle (`pdf_signing_enabled`), TSA URL (`signing_tsa_url`) a důvod (`signing_reason`)
 * se ukládají přes PUT /api/settings/supplier. Heslo k P12 se ukládá šifrovaně
 * (SecretEncryption); P12 leží pod data-dir mimo web root (0600), nikdy se neservíruje.
 */
final class SigningCertAction
{
    private const MAX_FILE_SIZE = 64 * 1024; // P12 bývá < 10 KiB

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $secrets,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** POST /api/settings/signing-cert */
    public function upload(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí měnit podpisový certifikát.', 403);
        }
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return Json::error($response, 'no_file', 'Žádný soubor nebyl odeslán (pole `file`).', 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_failed', 'Nahrání selhalo (kód ' . $file->getError() . ').', 400);
        }
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            return Json::error($response, 'bad_size', 'Certifikát musí být 1 B–64 KiB.', 413);
        }

        $p12 = (string) $file->getStream();
        $password = (string) (($request->getParsedBody()['password'] ?? '') ?: '');

        // Validace: P12 jde otevřít heslem + cert není expirovaný
        $certs = [];
        if (!openssl_pkcs12_read($p12, $certs, $password)) {
            return Json::error($response, 'bad_cert', 'P12/PFX nelze otevřít zadaným heslem (nebo není platný PKCS#12).', 422);
        }
        $info = openssl_x509_parse($certs['cert'] ?? '');
        if (!is_array($info) || ($info['validTo_time_t'] ?? 0) < time()) {
            return Json::error($response, 'cert_expired', 'Certifikát je expirovaný nebo nečitelný.', 422);
        }

        // Ulož P12 pod data-dir (0600), mimo web root
        $dir = RuntimePaths::storage('certs');
        if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
        $path = $dir . "/supplier-{$sid}.p12";
        if (file_put_contents($path, $p12) === false) {
            return Json::error($response, 'store_failed', 'Certifikát se nepodařilo uložit.', 500);
        }
        @chmod($path, 0600);

        $this->db->pdo()->prepare(
            'UPDATE supplier SET signing_cert_path = ?, signing_cert_password_enc = ? WHERE id = ?'
        )->execute([$path, $this->secrets->encrypt($password), $sid]);

        $this->auditCert($request, 'signing.cert_uploaded', $sid, [
            'cn'          => $info['subject']['CN'] ?? '',
            'issuer'      => $info['issuer']['CN'] ?? '',
            'not_after'   => date('c', (int) $info['validTo_time_t']),
            'fingerprint' => openssl_x509_fingerprint($certs['cert'], 'sha256'),
        ]);

        return Json::ok($response, $this->meta($certs['cert']));
    }

    /** DELETE /api/settings/signing-cert */
    public function remove(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí měnit podpisový certifikát.', 403);
        }
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }
        $row = $this->certRow($sid);
        if ($row && $row['signing_cert_path'] && is_file($row['signing_cert_path'])) {
            @unlink($row['signing_cert_path']);
        }
        $this->db->pdo()->prepare(
            'UPDATE supplier SET signing_cert_path = NULL, signing_cert_password_enc = NULL,
                    pdf_signing_enabled = 0 WHERE id = ?'
        )->execute([$sid]);
        $this->auditCert($request, 'signing.cert_removed', $sid, []);
        return Json::ok($response, ['has_cert' => false]);
    }

    /** GET /api/settings/signing-cert */
    public function metadata(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $row = $sid > 0 ? $this->certRow($sid) : null;
        if (!$row || !$row['signing_cert_path'] || !is_file($row['signing_cert_path'])) {
            return Json::ok($response, ['has_cert' => false]);
        }
        $certs = [];
        @openssl_pkcs12_read(
            (string) file_get_contents($row['signing_cert_path']),
            $certs,
            $this->secrets->decrypt((string) $row['signing_cert_password_enc'])
        );
        return Json::ok($response, $this->meta($certs['cert'] ?? ''));
    }

    /** @return array{signing_cert_path:?string,signing_cert_password_enc:?string}|null */
    private function certRow(int $sid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT signing_cert_path, signing_cert_password_enc FROM supplier WHERE id = ?'
        );
        $stmt->execute([$sid]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @return array<string,mixed> */
    private function meta(string $certPem): array
    {
        if ($certPem === '') {
            return ['has_cert' => false];
        }
        $info = openssl_x509_parse($certPem);
        if (!is_array($info)) {
            return ['has_cert' => false];
        }
        return [
            'has_cert'    => true,
            'cn'          => $info['subject']['CN'] ?? '',
            'issuer'      => $info['issuer']['CN'] ?? '',
            'valid_from'  => date('c', (int) ($info['validFrom_time_t'] ?? 0)),
            'valid_to'    => date('c', (int) ($info['validTo_time_t'] ?? 0)),
            'expired'     => ($info['validTo_time_t'] ?? 0) < time(),
            'fingerprint' => openssl_x509_fingerprint($certPem, 'sha256'),
        ];
    }

    private function auditCert(Request $request, string $action, int $sid, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $user['id'] ?? null, 'supplier', $sid, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }

    private function isAdmin(Request $request): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['role']) && $user['role'] === 'admin';
    }
}
