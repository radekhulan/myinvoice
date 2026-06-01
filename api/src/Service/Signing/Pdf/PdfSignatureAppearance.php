<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Pdf;

final readonly class PdfSignatureAppearance
{
    /**
     * @param array<string,mixed> $visibleConfig
     */
    public function __construct(
        public string $mode = 'invisible',
        public array $visibleConfig = [],
    ) {}

    public static function invisible(): self
    {
        return new self('invisible');
    }
}
