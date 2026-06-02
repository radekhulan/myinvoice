<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing;

use MyInvoice\Service\Pdf\SigningConfig;

/**
 * Kompatibilní podpisový profil. V první iteraci obaluje současné per-supplier
 * nastavení; později může být hydratovaný z obecných signing_profiles tabulek.
 */
final readonly class SigningProfile
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $code,
        public string $ownerType,
        public ?int $ownerId,
        public string $backend,
        public ?SigningConfig $pdfConfig,
        public array $metadata = [],
    ) {}
}
