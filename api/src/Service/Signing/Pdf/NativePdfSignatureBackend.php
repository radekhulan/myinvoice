<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Pdf;

use MyInvoice\Service\Pdf\PdfSigner;
use MyInvoice\Service\Signing\SigningProfile;

final class NativePdfSignatureBackend implements PdfSignatureBackendInterface
{
    public function __construct(private readonly PdfSigner $signer) {}

    public function id(): string
    {
        return 'native';
    }

    public function capabilities(): PdfSignatureCapabilities
    {
        return new PdfSignatureCapabilities(
            supportsInvisible: true,
            supportsVisible: false,
            supportsAppendSignaturePage: false,
            supportsTimestamp: true,
            supportsPades: true,
            requiresExternalBinary: false,
            supportedCertificateTypes: ['p12', 'pfx'],
        );
    }

    public function sign(PdfSigningRequest $request): PdfSigningResult
    {
        $cfg = $request->profile->pdfConfig;
        if ($cfg === null) {
            throw new \RuntimeException('Podpisový profil nemá PDF credential.');
        }
        if ($request->appearance->mode !== 'invisible') {
            throw new \RuntimeException('Nativní PDF signer podporuje pouze neviditelný podpis.');
        }

        $pdf = @file_get_contents($request->inputPath);
        if ($pdf === false) {
            throw new \RuntimeException('Nelze číst PDF: ' . $request->inputPath);
        }

        $signed = $this->signer->sign($pdf, $cfg);
        if (@file_put_contents($request->outputPath, $signed) === false) {
            throw new \RuntimeException('Nelze zapsat podepsané PDF: ' . $request->outputPath);
        }

        $timestamped = $this->signer->lastTimestamped();
        return new PdfSigningResult(
            outputPath: $request->outputPath,
            backend: $this->id(),
            level: $timestamped ? 'PAdES-T' : 'PAdES-B',
            timestamped: $timestamped,
            certificateFingerprint: null,
            metadata: [
                'certificate_cn' => $this->signer->lastCertificateCommonName(),
            ],
        );
    }

    public function healthCheck(?SigningProfile $profile = null): PdfSignerHealth
    {
        if ($profile === null || $profile->pdfConfig === null) {
            return new PdfSignerHealth($this->id(), true, 'Nativní signer je dostupný.');
        }

        $certPath = $profile->pdfConfig->certPath;
        if ($certPath === '' || !is_file($certPath)) {
            return new PdfSignerHealth($this->id(), false, 'Certifikát není dostupný.');
        }

        return new PdfSignerHealth($this->id(), true, 'Nativní signer a certifikát jsou dostupné.');
    }
}
