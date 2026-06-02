<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Signing;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Signing\SigningPassphraseProvider;
use PHPUnit\Framework\TestCase;

final class SigningPassphraseProviderTest extends TestCase
{
    public function testEncryptedStoreReturnsStoredEncryptedPassphrase(): void
    {
        $secrets = $this->secrets();
        $encrypted = $secrets->encrypt('cert-secret');
        $provider = new SigningPassphraseProvider($this->config(), $secrets);

        $resolved = $provider->encryptedPassphraseForCredential([
            'passphrase_policy' => 'encrypted_store',
            'encrypted_passphrase' => $encrypted,
        ]);

        self::assertSame($encrypted, $resolved);
        self::assertSame('cert-secret', $secrets->decrypt((string) $resolved));
    }

    public function testPassphraseFileReadsMatchingIniProfileOnly(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'myinvoice-passphrases-');
        self::assertIsString($file);
        file_put_contents($file, "[first]\npassphrase=one\n\n[second]\npassphrase=two\n");

        $secrets = $this->secrets();
        $provider = new SigningPassphraseProvider($this->config(['signing' => ['passphrase_file' => $file]]), $secrets);

        $resolved = $provider->encryptedPassphraseForCredential([
            'passphrase_policy' => 'passphrase_file',
            'passphrase_profile_id' => 'second',
        ]);

        @unlink($file);

        self::assertNotNull($resolved);
        self::assertSame('two', $secrets->decrypt($resolved));
    }

    public function testPromptOnUseReturnsNullForBackgroundRuntime(): void
    {
        $provider = new SigningPassphraseProvider($this->config(), $this->secrets());

        self::assertNull($provider->encryptedPassphraseForCredential([
            'passphrase_policy' => 'prompt_on_use',
        ]));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function config(array $overrides = []): Config
    {
        return new Config(array_replace_recursive([
            'app' => [
                'pepper' => 'unit-test-pepper',
            ],
        ], $overrides));
    }

    private function secrets(): SecretEncryption
    {
        return new SecretEncryption($this->config());
    }
}
