<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Pdf;

use MyInvoice\Service\Signing\SigningProfile;

interface PdfSignatureBackendInterface
{
    public function id(): string;

    public function capabilities(): PdfSignatureCapabilities;

    public function sign(PdfSigningRequest $request): PdfSigningResult;

    public function healthCheck(?SigningProfile $profile = null): PdfSignerHealth;
}
