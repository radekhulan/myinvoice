<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing;

interface SigningPassphraseProviderInterface
{
    /**
     * Vrátí passphrase ve formátu kompatibilním se SigningConfig::passwordEnc.
     *
     * @param array<string,mixed> $credential
     */
    public function encryptedPassphraseForCredential(array $credential): ?string;
}
