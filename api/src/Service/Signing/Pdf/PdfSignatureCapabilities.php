<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Pdf;

final readonly class PdfSignatureCapabilities
{
    /**
     * @param list<string> $supportedCertificateTypes
     */
    public function __construct(
        public bool $supportsInvisible,
        public bool $supportsVisible,
        public bool $supportsAppendSignaturePage,
        public bool $supportsTimestamp,
        public bool $supportsPades,
        public bool $requiresExternalBinary,
        public array $supportedCertificateTypes,
    ) {}
}
