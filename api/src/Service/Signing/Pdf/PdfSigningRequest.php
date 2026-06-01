<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Pdf;

use MyInvoice\Service\Signing\SigningProfile;

final readonly class PdfSigningRequest
{
    public function __construct(
        public string $inputPath,
        public string $outputPath,
        public string $documentType,
        public int $documentId,
        public SigningProfile $profile,
        public PdfSignatureAppearance $appearance,
        public PdfSignaturePolicy $policy,
        public ?int $supplierId,
        public ?int $userId,
    ) {}
}
