<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\LoginAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\EmailOtpService;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\StoredPasskeyCredential;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Auth\TrustedDeviceService;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use MyInvoice\Service\Auth\WebAuthnConfigProvider;
use MyInvoice\Service\Captcha\TurnstileVerifier;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

#[AllowMockObjectsWithoutExpectations]
final class LoginActionPasskeyTest extends TestCase
{
    public function testPasswordLoginWithPasskeyReturnsBoundMfaFlowWithoutSession(): void
    {
        $db = $this->createMock(Connection::class);
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(['user@example.invalid'])->willReturn(true);
        $statement->expects(self::once())->method('fetch')->with(\PDO::FETCH_ASSOC)->willReturn([
            'id' => 17,
            'email' => 'user@example.invalid',
            'name' => 'Synthetic User',
            'role' => 'admin',
            'locale' => 'cs',
            'password_hash' => 'synthetic-hash',
            'is_active' => 1,
            'totp_secret' => 'encrypted-secret',
            'totp_enabled' => 1,
        ]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::once())->method('verify')->willReturn(true);
        $hasher->expects(self::once())->method('needsRehash')->willReturn(false);
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->expects(self::once())->method('check')->willReturn(BruteForceGuard::STATE_OK);
        $turnstile = $this->createMock(TurnstileVerifier::class);
        $turnstile->expects(self::once())->method('verify')->willReturn(true);
        $ipMatcher = $this->createMock(IpMatcher::class);
        $ipMatcher->expects(self::once())->method('clientIpFromRequest')->willReturn('127.0.0.1');

        $stored = $this->storedCredential();
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())->method('findAllForUser')->with(17)->willReturn([$stored]);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->method('isAvailable')->willReturn(true);
        $passkeys->expects(self::once())
            ->method('assertionOptions')
            ->willReturn(['challenge' => 'encoded', 'userVerification' => 'required']);
        $ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $ceremonies->expects(self::once())
            ->method('create')
            ->with(
                WebAuthnCeremonyStore::PURPOSE_LOGIN,
                17,
                null,
                null,
                self::callback(static fn (mixed $challenge): bool => is_string($challenge) && strlen($challenge) === 32),
                ['challenge' => 'encoded', 'userVerification' => 'required'],
                '127.0.0.1',
                'PHPUnit',
            )
            ->willReturn('opaque-flow-token');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::exactly(2))->method('isMethodAllowed')->willReturn(true);
        $issuer = $this->createMock(LoginSessionIssuer::class);
        $issuer->expects(self::never())->method('issue');

        $action = new LoginAction(
            $db,
            $hasher,
            $bruteForce,
            $turnstile,
            $this->createMock(ActivityLogger::class),
            $ipMatcher,
            new Config(['auth' => ['email_otp' => ['enabled' => false]]]),
            $this->createMock(TotpService::class),
            $this->createMock(SecretEncryption::class),
            $this->createMock(EmailOtpService::class),
            $this->createMock(TrustedDeviceService::class),
            $credentials,
            $passkeys,
            $ceremonies,
            $policy,
            $issuer,
            $this->createMock(ClockInterface::class),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/login')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody([
                'email' => 'user@example.invalid',
                'password' => 'Synthetic-password-42',
            ]);

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('mfa_required', $body['error']['code']);
        self::assertSame('opaque-flow-token', $body['error']['flow_token']);
        self::assertSame(['passkey', 'totp'], $body['error']['methods']);
        self::assertSame('required', $body['error']['public_key']['userVerification']);
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testInvalidWebAuthnConfigurationDoesNotBreakPasswordOnlyLogin(): void
    {
        $db = $this->createMock(Connection::class);
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(['user@example.invalid'])->willReturn(true);
        $statement->expects(self::once())->method('fetch')->with(\PDO::FETCH_ASSOC)->willReturn([
            'id' => 17,
            'email' => 'user@example.invalid',
            'name' => 'Synthetic User',
            'role' => 'admin',
            'locale' => 'cs',
            'password_hash' => 'synthetic-hash',
            'is_active' => 1,
            'totp_secret' => null,
            'totp_enabled' => 0,
        ]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::once())->method('verify')->willReturn(true);
        $hasher->expects(self::once())->method('needsRehash')->willReturn(false);
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->expects(self::once())->method('check')->willReturn(BruteForceGuard::STATE_OK);
        $turnstile = $this->createMock(TurnstileVerifier::class);
        $turnstile->expects(self::once())->method('verify')->willReturn(true);
        $ipMatcher = $this->createMock(IpMatcher::class);
        $ipMatcher->expects(self::once())->method('clientIpFromRequest')->willReturn('127.0.0.1');
        // Nepoužitelný WebAuthn ceremony vůbec nezakládá; ptáme se jen, jestli
        // uživatel nějakou passkey má — bez ní je heslový login v pořádku.
        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::never())->method('findAllForUser');
        $credentials->expects(self::once())->method('countActiveForUser')->with(17)->willReturn(0);
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::exactly(2))
            ->method('isMethodAllowed')
            ->willReturnCallback(static fn (string $method): bool => in_array($method, ['passkey', 'totp'], true));
        $policy->expects(self::once())->method('isRequired')->willReturn(false);
        $issuer = $this->createMock(LoginSessionIssuer::class);
        $issuer->expects(self::once())
            ->method('issue')
            ->willReturnCallback(static function (
                \Psr\Http\Message\ResponseInterface $response,
                array $user,
                string $ip,
                string $userAgent,
                \MyInvoice\Service\Auth\SessionAuthContext $context,
            ): \Psr\Http\Message\ResponseInterface {
                self::assertSame(17, $user['id']);
                self::assertSame('password', $context->authMethod);
                self::assertSame('basic', $context->assuranceLevel);
                return $response->withStatus(204);
            });
        $passkeys = new PasskeyService(new WebAuthnConfigProvider(new Config([
            'app' => ['url' => 'http://invoice.example.cz'],
        ])));

        $action = new LoginAction(
            $db,
            $hasher,
            $bruteForce,
            $turnstile,
            $this->createMock(ActivityLogger::class),
            $ipMatcher,
            new Config(['auth' => ['email_otp' => ['enabled' => false]]]),
            $this->createMock(TotpService::class),
            $this->createMock(SecretEncryption::class),
            $this->createMock(EmailOtpService::class),
            $this->createMock(TrustedDeviceService::class),
            $credentials,
            $passkeys,
            $this->createMock(WebAuthnCeremonyStore::class),
            $policy,
            $issuer,
            $this->createMock(ClockInterface::class),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/login')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody([
                'email' => 'user@example.invalid',
                'password' => 'Synthetic-password-42',
            ]);

        self::assertSame(204, $action(
            $request,
            (new ResponseFactory())->createResponse(),
        )->getStatusCode());
    }

    public function testPasswordlessOptionsCreateAnonymousDiscoverableCeremony(): void
    {
        $turnstile = $this->createMock(TurnstileVerifier::class);
        $turnstile->expects(self::once())
            ->method('verify')
            ->with('captcha-token', '127.0.0.1', 'login')
            ->willReturn(true);
        $ipMatcher = $this->createMock(IpMatcher::class);
        $ipMatcher->expects(self::once())
            ->method('clientIpFromRequest')
            ->willReturn('127.0.0.1');
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::once())->method('isAvailable')->willReturn(true);
        $passkeys->expects(self::once())
            ->method('discoverableAssertionOptions')
            ->with(self::callback(static fn (mixed $challenge): bool => is_string($challenge) && strlen($challenge) === 32))
            ->willReturn([
                'challenge' => 'encoded',
                'allowCredentials' => [],
                'userVerification' => 'required',
            ]);
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isMethodAllowed')->with('passkey')->willReturn(true);
        $ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $ceremonies->expects(self::once())
            ->method('create')
            ->with(
                WebAuthnCeremonyStore::PURPOSE_DISCOVERABLE_LOGIN,
                null,
                null,
                null,
                self::callback(static fn (mixed $challenge): bool => is_string($challenge) && strlen($challenge) === 32),
                [
                    'challenge' => 'encoded',
                    'allowCredentials' => [],
                    'userVerification' => 'required',
                ],
                '127.0.0.1',
                'PHPUnit',
            )
            ->willReturn('opaque-flow-token');

        $action = $this->optionsAction(
            new Config(['auth' => ['passwordless_login' => ['enabled' => true]]]),
            $turnstile,
            $ipMatcher,
            $passkeys,
            $ceremonies,
            $policy,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/webauthn/login/options')
            ->withHeader('User-Agent', 'PHPUnit')
            ->withParsedBody(['cf_turnstile_response' => 'captcha-token']);

        $response = $action->passkeyOptions(
            $request,
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('opaque-flow-token', $body['flow_token']);
        self::assertSame([], $body['public_key']['allowCredentials']);
        self::assertSame('required', $body['public_key']['userVerification']);
    }

    public function testPasswordlessOptionsRemainDisabledByDefault(): void
    {
        $turnstile = $this->createMock(TurnstileVerifier::class);
        $turnstile->expects(self::never())->method('verify');
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::never())->method('isAvailable');
        $ceremonies = $this->createMock(WebAuthnCeremonyStore::class);
        $ceremonies->expects(self::never())->method('create');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::never())->method('isMethodAllowed');

        $action = $this->optionsAction(
            new Config([]),
            $turnstile,
            $this->createMock(IpMatcher::class),
            $passkeys,
            $ceremonies,
            $policy,
        );
        $response = $action->passkeyOptions(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/auth/webauthn/login/options'),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('passwordless_login_disabled', $body['error']['code']);
    }

    private function optionsAction(
        Config $config,
        TurnstileVerifier $turnstile,
        IpMatcher $ipMatcher,
        PasskeyService $passkeys,
        WebAuthnCeremonyStore $ceremonies,
        MfaPolicyService $policy,
    ): LoginAction {
        return new LoginAction(
            $this->createMock(Connection::class),
            $this->createMock(PasswordHasher::class),
            $this->createMock(BruteForceGuard::class),
            $turnstile,
            $this->createMock(ActivityLogger::class),
            $ipMatcher,
            $config,
            $this->createMock(TotpService::class),
            $this->createMock(SecretEncryption::class),
            $this->createMock(EmailOtpService::class),
            $this->createMock(TrustedDeviceService::class),
            $this->createMock(PasskeyCredentialRepository::class),
            $passkeys,
            $ceremonies,
            $policy,
            $this->createMock(LoginSessionIssuer::class),
            $this->createMock(ClockInterface::class),
        );
    }

    private function storedCredential(): StoredPasskeyCredential
    {
        $record = CredentialRecord::create(
            random_bytes(32),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            random_bytes(77),
            random_bytes(32),
            0,
            null,
            true,
            false,
            true,
        );

        return new StoredPasskeyCredential(42, 17, 'Pixel 9', $record, null, null, null);
    }
}
