<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Pdf;

final readonly class PdfSigningResult
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $outputPath,
        public string $backend,
        public string $level,
        public bool $timestamped,
        public ?string $certificateFingerprint = null,
        public array $metadata = [],
    ) {}
}
