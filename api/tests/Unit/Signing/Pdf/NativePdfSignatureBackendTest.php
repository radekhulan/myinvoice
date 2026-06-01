<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Signing\Pdf;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Pdf\PdfSigner;
use MyInvoice\Service\Signing\Pdf\NativePdfSignatureBackend;
use PHPUnit\Framework\TestCase;

final class NativePdfSignatureBackendTest extends TestCase
{
    public function testCapabilitiesDescribeNativeSigner(): void
    {
        $backend = new NativePdfSignatureBackend($this->unusedSigner());

        $capabilities = $backend->capabilities();

        self::assertSame('native', $backend->id());
        self::assertTrue($capabilities->supportsInvisible);
        self::assertFalse($capabilities->supportsVisible);
        self::assertFalse($capabilities->supportsAppendSignaturePage);
        self::assertTrue($capabilities->supportsTimestamp);
        self::assertTrue($capabilities->supportsPades);
        self::assertFalse($capabilities->requiresExternalBinary);
        self::assertSame(['p12', 'pfx'], $capabilities->supportedCertificateTypes);
    }

    public function testHealthCheckWithoutProfileOnlyChecksBackendAvailability(): void
    {
        $backend = new NativePdfSignatureBackend($this->unusedSigner());

        $health = $backend->healthCheck();

        self::assertSame('native', $health->backend);
        self::assertTrue($health->ok);
    }

    private function unusedSigner(): PdfSigner
    {
        $config = new Config([
            'app' => [
                'pepper' => 'unit-test-pepper',
            ],
        ]);

        return new PdfSigner(new SecretEncryption($config));
    }
}
