<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\MfaPolicyService;
use PHPUnit\Framework\TestCase;

final class MfaPolicyServiceTest extends TestCase
{
    public function testLegacyRequireTotpRemainsTotpOnly(): void
    {
        $policy = new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => null,
                'require_totp'       => true,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]));

        self::assertTrue($policy->isRequired());
        self::assertTrue($policy->usesLegacyTotpPolicy());
        self::assertSame(['totp'], $policy->allowedMethods());
        self::assertFalse($policy->isMethodAllowed('passkey'));
    }

    public function testExplicitMfaPolicyOverridesLegacyFlag(): void
    {
        $policy = new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => false,
                'require_totp'       => true,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]));

        self::assertFalse($policy->isRequired());
        self::assertFalse($policy->usesLegacyTotpPolicy());
        self::assertSame(['passkey', 'totp'], $policy->allowedMethods());
    }

    public function testMethodsAreNormalizedAndDeduplicated(): void
    {
        $policy = new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => true,
                'require_totp'       => false,
                'allowed_mfa_methods' => [' TOTP ', 'passkey', 'totp'],
            ],
        ]));

        self::assertSame(['totp', 'passkey'], $policy->allowedMethods());
        self::assertTrue($policy->satisfiesRequiredMfa('PASSKEY'));
        self::assertFalse($policy->satisfiesRequiredMfa('email_otp'));
    }

    public function testValidMethodsProduceNoConfigurationWarning(): void
    {
        $policy = new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => true,
                'require_totp'       => false,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]));

        self::assertNull($policy->configurationWarning());
    }

    public function testUnknownMethodFallsBackToSupportedSetWithWarning(): void
    {
        $policy = new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => true,
                'require_totp'       => false,
                'allowed_mfa_methods' => ['passkey', 'email_otp'],
            ],
        ]));

        self::assertSame(['passkey', 'totp'], $policy->allowedMethods());
        self::assertNotNull($policy->configurationWarning());
        self::assertStringContainsString('email_otp', (string) $policy->configurationWarning());
    }

    public function testEmptyMethodsFallBackToSupportedSetWithWarning(): void
    {
        $policy = new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => true,
                'require_totp'       => false,
                'allowed_mfa_methods' => [],
            ],
        ]));

        self::assertSame(['passkey', 'totp'], $policy->allowedMethods());
        self::assertNotNull($policy->configurationWarning());
    }

    public function testLegacyPolicyIgnoresBrokenMethodListWithoutWarning(): void
    {
        $policy = new MfaPolicyService(new Config([
            'auth' => [
                'require_mfa'        => null,
                'require_totp'       => true,
                'allowed_mfa_methods' => ['nonsense'],
            ],
        ]));

        self::assertSame(['totp'], $policy->allowedMethods());
        self::assertNull($policy->configurationWarning());
    }

    public function testStrictValidatorStillRejectsUnknownMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MfaPolicyService::validateMethods(['passkey', 'email_otp']);
    }

    public function testStrictValidatorStillRejectsEmptyList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MfaPolicyService::validateMethods([]);
    }

    public function testStrictValidatorAcceptsCsvString(): void
    {
        self::assertSame(['totp', 'passkey'], MfaPolicyService::validateMethods(' TOTP , passkey '));
    }
}
