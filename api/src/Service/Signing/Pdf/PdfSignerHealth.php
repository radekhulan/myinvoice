<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Pdf;

final readonly class PdfSignerHealth
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $backend,
        public bool $ok,
        public string $message = '',
        public array $metadata = [],
    ) {}
}
