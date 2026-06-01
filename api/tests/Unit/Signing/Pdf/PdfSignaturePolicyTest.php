<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Signing\Pdf;

use MyInvoice\Service\Signing\Pdf\PdfSignaturePolicy;
use PHPUnit\Framework\TestCase;

final class PdfSignaturePolicyTest extends TestCase
{
    public function testDefaultPolicyIsFallbackUnsigned(): void
    {
        $policy = new PdfSignaturePolicy();

        self::assertSame(PdfSignaturePolicy::FALLBACK_UNSIGNED, $policy->failurePolicy);
        self::assertFalse($policy->failClosed());
    }

    public function testFailClosedPolicy(): void
    {
        $policy = new PdfSignaturePolicy(PdfSignaturePolicy::FAIL_CLOSED);

        self::assertTrue($policy->failClosed());
    }
}
