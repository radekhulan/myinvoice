<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Pdf;

final readonly class PdfSignaturePolicy
{
    public const FALLBACK_UNSIGNED = 'fallback_unsigned';
    public const FAIL_CLOSED = 'fail_closed';
    public const SKIP_WHEN_UNCONFIGURED = 'skip_when_unconfigured';

    public function __construct(
        public string $failurePolicy = self::FALLBACK_UNSIGNED,
    ) {}

    public function failClosed(): bool
    {
        return $this->failurePolicy === self::FAIL_CLOSED;
    }
}
