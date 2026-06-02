<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Signing\Pdf;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Pdf\PdfSigner;
use MyInvoice\Service\Signing\Pdf\NativePdfSignatureBackend;
use MyInvoice\Service\Signing\Pdf\PdfSignaturePolicy;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PdfSigningServiceTest extends TestCase
{
    public function testOutputDisabledSkipsSigningAndLogsAuditEvent(): void
    {
        $tmpPath = $this->tempPdfPath('output-disabled');
        file_put_contents($tmpPath, 'not used');

        $service = $this->service(
            config: [
                'pdf_signing' => [
                    'enabled' => true,
                    'default_backend' => 'native',
                    'failure_policy' => PdfSignaturePolicy::FALLBACK_UNSIGNED,
                    'enabled_outputs' => [
                        'work_reports' => false,
                    ],
                ],
            ],
            activity: $this->activityLoggerExpecting(function (array $params, array $payload): void {
                self::assertSame(17, $params[0]);
                self::assertSame(5, $params[1]);
                self::assertSame('signing.skipped', $params[2]);
                self::assertSame('work_report', $params[3]);
                self::assertSame(123, $params[4]);
                self::assertSame('skipped', $payload['status']);
                self::assertSame('output_disabled', $payload['reason']);
                self::assertSame('native', $payload['backend']);
            }),
        );

        $result = $service->signSupplierPdfIfEnabled($tmpPath, $this->supplierRow(), 'work_report', 123, 5);

        self::assertSame($tmpPath, $result);
        self::assertFileExists($tmpPath);
    }

    public function testMissingProfileWithFailClosedThrowsAndLogsFailure(): void
    {
        $tmpPath = $this->tempPdfPath('missing-profile');
        file_put_contents($tmpPath, 'not used');

        $service = $this->service(
            config: [
                'pdf_signing' => [
                    'enabled' => true,
                    'default_backend' => 'native',
                    'failure_policy' => PdfSignaturePolicy::FAIL_CLOSED,
                    'enabled_outputs' => [
                        'invoices' => true,
                    ],
                ],
            ],
            activity: $this->activityLoggerExpecting(function (array $params, array $payload): void {
                self::assertSame('signing.failed', $params[2]);
                self::assertSame('invoice', $params[3]);
                self::assertSame(456, $params[4]);
                self::assertSame('failed', $payload['status']);
                self::assertSame('Podpisový profil není nakonfigurovaný.', $payload['error']);
                self::assertNull($payload['profile_code']);
                self::assertSame(PdfSignaturePolicy::FAIL_CLOSED, $payload['failure_policy']);
            }),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PDF podpis není nakonfigurovaný.');

        $service->signSupplierPdfIfEnabled(
            $tmpPath,
            $this->supplierRow(['signing_cert_path' => '']),
            'invoice',
            456,
            5,
        );
    }

    public function testLegacySupplierCertificateDoesNotCreateRuntimeProfile(): void
    {
        $tmpPath = $this->tempPdfPath('legacy-supplier-cert');
        file_put_contents($tmpPath, $this->minimalClassicPdf());

        $service = $this->service(
            config: [
                'pdf_signing' => [
                    'enabled' => true,
                    'default_backend' => 'native',
                    'failure_policy' => PdfSignaturePolicy::FALLBACK_UNSIGNED,
                    'enabled_outputs' => [
                        'invoices' => true,
                    ],
                ],
            ],
            activity: $this->activityLoggerExpecting(function (array $params, array $payload): void {
                self::assertSame('signing.skipped', $params[2]);
                self::assertSame('invoice', $params[3]);
                self::assertSame(321, $params[4]);
                self::assertSame('skipped', $payload['status']);
                self::assertSame('missing_profile', $payload['reason']);
                self::assertNull($payload['profile_code']);
            }),
        );

        $result = $service->signSupplierPdfIfEnabled(
            $tmpPath,
            $this->supplierRow(['signing_cert_path' => '/tmp/myinvoice-secret/legacy.p12']),
            'invoice',
            321,
            5,
        );

        self::assertSame($tmpPath, $result);
        self::assertFileExists($tmpPath);
        self::assertFileDoesNotExist($tmpPath . '.signed');
    }

    public function testPdfSigningTestReportsMissingProfileAndLogsSkipped(): void
    {
        $tmpPath = $this->tempPdfPath('signing-test-missing-profile');
        file_put_contents($tmpPath, $this->minimalClassicPdf());

        $service = $this->service(
            config: [
                'pdf_signing' => [
                    'enabled' => true,
                    'default_backend' => 'native',
                    'failure_policy' => PdfSignaturePolicy::FALLBACK_UNSIGNED,
                    'enabled_outputs' => [
                        'invoices' => true,
                    ],
                ],
            ],
            activity: $this->activityLoggerExpecting(function (array $params, array $payload): void {
                self::assertSame('signing.test_skipped', $params[2]);
                self::assertSame('supplier', $params[3]);
                self::assertSame(17, $params[4]);
                self::assertSame('invoice', $payload['output_type']);
                self::assertSame('skipped', $payload['status']);
                self::assertSame('missing_profile', $payload['reason']);
                self::assertNull($payload['profile_code']);
                self::assertNull($payload['certificate_cn']);
            }),
        );

        $result = $service->testSupplierPdfSigning(
            $tmpPath,
            $this->supplierRow(['signing_cert_path' => '']),
            'invoice',
            5,
        );

        self::assertSame('skipped', $result['status']);
        self::assertSame('missing_profile', $result['reason']);
        self::assertNull($result['certificate_cn']);
        self::assertFileExists($tmpPath);
    }

    public function testMissingProfileFallsBackToUnsignedPdf(): void
    {
        $tmpPath = $this->tempPdfPath('fallback');
        file_put_contents($tmpPath, $this->minimalClassicPdf());

        $service = $this->service(
            config: [
                'pdf_signing' => [
                    'enabled' => true,
                    'default_backend' => 'native',
                    'failure_policy' => PdfSignaturePolicy::FALLBACK_UNSIGNED,
                    'enabled_outputs' => [
                        'invoices' => true,
                    ],
                ],
            ],
            activity: $this->activityLoggerExpecting(function (array $params, array $payload): void {
                self::assertSame('signing.skipped', $params[2]);
                self::assertSame('invoice', $params[3]);
                self::assertSame('skipped', $payload['status']);
                self::assertSame('native', $payload['backend']);
                self::assertSame('missing_profile', $payload['reason']);
                self::assertNull($payload['profile_code']);
            }),
        );

        $result = $service->signSupplierPdfIfEnabled(
            $tmpPath,
            $this->supplierRow(['signing_cert_path' => '/tmp/myinvoice-secret/missing.p12']),
            'invoice',
            789,
            5,
        );

        self::assertSame($tmpPath, $result);
        self::assertFileExists($tmpPath);
        self::assertFileDoesNotExist($tmpPath . '.signed');
    }

    /**
     * @param array<string,mixed> $config
     */
    private function service(array $config, ActivityLogger $activity): PdfSigningService
    {
        $cfg = new Config(array_replace_recursive([
            'app' => [
                'pepper' => 'unit-test-pepper',
            ],
        ], $config));

        return new PdfSigningService(
            $cfg,
            $activity,
            new NativePdfSignatureBackend(new PdfSigner(new SecretEncryption($cfg))),
        );
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function supplierRow(array $overrides = []): array
    {
        return array_replace([
            'id' => 17,
            'pdf_signing_enabled' => 1,
            'signing_cert_path' => '/tmp/missing-cert.p12',
            'signing_cert_password_enc' => '',
            'signing_tsa_url' => null,
            'signing_tsa_username' => null,
            'signing_tsa_password_enc' => '',
            'signing_reason' => 'Faktura',
        ], $overrides);
    }

    /**
     * @param callable(array<int,mixed>, array<string,mixed>):void $assertPayload
     */
    private function activityLoggerExpecting(callable $assertPayload): ActivityLogger
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())
            ->method('execute')
            ->with(self::callback(function (array $params) use ($assertPayload): bool {
                $payload = json_decode((string) $params[5], true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($payload);
                $assertPayload($params, $payload);
                return true;
            }))
            ->willReturn(true);

        $pdo = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['prepare'])
            ->getMock();
        $pdo->expects(self::once())
            ->method('prepare')
            ->with(self::stringContains('INSERT INTO activity_log'))
            ->willReturn($stmt);

        $db = new Connection(new Config([
            'db' => [
                'host' => '127.0.0.1',
                'name' => 'unit_test',
                'user' => 'unit_test',
                'pass' => '',
            ],
        ]));
        $pdoProperty = new \ReflectionProperty(Connection::class, 'pdo');
        $pdoProperty->setValue($db, $pdo);

        return new ActivityLogger($db);
    }

    private function tempPdfPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'myinvoice-' . $prefix . '-');
        self::assertIsString($path);
        return $path;
    }

    private function minimalClassicPdf(): string
    {
        $body = "%PDF-1.4\n"
            . "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $xrefOffset = strlen($body);

        return $body
            . "xref\n"
            . "0 2\n"
            . "0000000000 65535 f \n"
            . "0000000009 00000 n \n"
            . "trailer\n<< /Size 2 /Root 1 0 R >>\n"
            . "startxref\n"
            . $xrefOffset . "\n"
            . "%%EOF\n";
    }
}
