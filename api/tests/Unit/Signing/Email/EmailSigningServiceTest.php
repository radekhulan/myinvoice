<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Signing\Email;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\SigningProfileRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Signing\Email\EmailSigningService;
use MyInvoice\Service\Signing\SigningPassphraseProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

final class EmailSigningServiceTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    public function testSignsSupplierEmailWithSmimeProfile(): void
    {
        $fixture = $this->p12Fixture('secret');
        $service = $this->service($fixture['path'], 'secret', 'secret', 'fail_closed', $this->once());

        $email = (new Email())
            ->from('sender@example.test')
            ->to('recipient@example.test')
            ->subject('Invoice')
            ->text('Invoice body');

        $signed = $service->signIfEnabled($email, 'invoice_send', ['id' => 1], 10);

        self::assertNotSame($email, $signed);
        self::assertStringContainsString('multipart/signed', $signed->toString());
        self::assertStringContainsString('application/x-pkcs7-signature', $signed->toString());
    }

    public function testFailClosedThrowsWhenSmimeSigningFails(): void
    {
        $fixture = $this->p12Fixture('secret');
        $service = $this->service($fixture['path'], 'secret', 'wrong', 'fail_closed', $this->once());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S/MIME podpis e-mailu selhal.');

        $service->signIfEnabled(
            (new Email())->from('sender@example.test')->to('recipient@example.test')->subject('Invoice')->text('Invoice body'),
            'invoice_send',
            ['id' => 1],
            10,
        );
    }

    public function testFallbackUnsignedReturnsOriginalMessageWhenSigningFails(): void
    {
        $fixture = $this->p12Fixture('secret');
        $service = $this->service($fixture['path'], 'secret', 'wrong', 'fallback_unsigned', $this->once());
        $email = (new Email())
            ->from('sender@example.test')
            ->to('recipient@example.test')
            ->subject('Invoice')
            ->text('Invoice body');

        $result = $service->signIfEnabled($email, 'invoice_send', ['id' => 1], 10);

        self::assertSame($email, $result);
    }

    public function testPasswordResetIsNotSigned(): void
    {
        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects($this->never())->method('log');
        $profiles = $this->createMock(SigningProfileRepository::class);
        $profiles->expects($this->never())->method('outputSetting');

        $service = new EmailSigningService(
            new Config(['email_signing' => ['enabled' => true], 'app' => ['pepper' => 'test-pepper']]),
            $activity,
            $profiles,
            $this->createStub(SigningPassphraseProviderInterface::class),
            new SecretEncryption(new Config(['app' => ['pepper' => 'test-pepper']])),
        );
        $email = (new Email())->from('sender@example.test')->to('recipient@example.test')->subject('Reset')->text('Reset body');

        self::assertSame($email, $service->signIfEnabled($email, 'password_reset', ['id' => 1], 10));
    }

    private function service(
        string $p12Path,
        string $p12Password,
        string $runtimePassword,
        string $failurePolicy,
        \PHPUnit\Framework\MockObject\Rule\InvocationOrder $activityLogRule,
    ): EmailSigningService {
        $config = new Config(['email_signing' => ['enabled' => true], 'app' => ['pepper' => 'test-pepper']]);
        $secrets = new SecretEncryption($config);
        $encrypted = $secrets->encrypt($runtimePassword);

        $profiles = $this->createMock(SigningProfileRepository::class);
        $profiles->expects($this->once())
            ->method('outputSetting')
            ->with(1, 'email_invoice_send', 'email_smime')
            ->willReturn([
                'supplier_id' => 1,
                'usage' => 'email_smime',
                'output_type' => 'email_invoice_send',
                'enabled' => true,
                'backend' => 'smime',
                'selection_source' => 'admin_profile_settings',
                'user_profile_fallback' => 'fallback_unsigned',
                'default_profile_id' => 20,
                'failure_policy' => $failurePolicy,
                'signature_config' => [],
            ]);
        $profiles->expects($this->once())
            ->method('findProfile')
            ->with(1, 20)
            ->willReturn([
                'id' => 20,
                'supplier_id' => 1,
                'owner_user_id' => null,
                'name' => 'S/MIME',
                'code' => 'smime',
                'allowed_usages' => ['email_smime'],
                'default_backend' => 'native',
                'is_active' => true,
            ]);
        $profiles->expects($this->once())
            ->method('credential')
            ->with(1, 20)
            ->willReturn([
                'profile_id' => 20,
                'certificate_path' => $p12Path,
                'certificate_subject' => 'CN=Unit Test',
                'certificate_email' => 'signer@example.test',
                'certificate_fingerprint' => hash('sha256', $p12Path . $p12Password),
                'certificate_valid_to' => date('Y-m-d H:i:s', strtotime('+1 day')),
                'passphrase_policy' => 'encrypted_store',
                'encrypted_passphrase' => $encrypted,
                'is_active' => true,
            ]);

        $passphrases = $this->createStub(SigningPassphraseProviderInterface::class);
        $passphrases->method('encryptedPassphraseForCredential')->willReturn($encrypted);

        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects($activityLogRule)->method('log');

        return new EmailSigningService($config, $activity, $profiles, $passphrases, $secrets);
    }

    /**
     * @return array{path:string}
     */
    private function p12Fixture(string $password): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        self::assertNotFalse($key);

        $dn = [
            'commonName' => 'Unit Test',
            'emailAddress' => 'signer@example.test',
            'countryName' => 'CZ',
        ];
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert);

        $p12 = '';
        self::assertTrue(openssl_pkcs12_export($cert, $p12, $key, $password));

        $path = tempnam(sys_get_temp_dir(), 'mi-smime-');
        self::assertIsString($path);
        file_put_contents($path, $p12);
        $this->tempFiles[] = $path;

        return ['path' => $path];
    }
}
