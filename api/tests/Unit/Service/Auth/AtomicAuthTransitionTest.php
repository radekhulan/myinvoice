<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\LastMfaFactorException;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\MfaStepUpProofStore;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\PasskeyCounterAnomalyException;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasskeySessionTransitionService;
use MyInvoice\Service\Auth\PasskeyVerificationException;
use MyInvoice\Service\Auth\PsrSecurityClock;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\Auth\SessionLockService;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

#[Group('integration')]
#[AllowMockObjectsWithoutExpectations]
final class AtomicAuthTransitionTest extends TestCase
{
    private string $rootDir;
    private Connection $db;
    private Config $config;
    private AtomicAuthTestClock $clock;
    private PsrSecurityClock $securityClock;
    private SessionManager $sessions;
    private WebAuthnCeremonyStore $ceremonies;
    private MfaStepUpProofStore $proofs;
    private PasskeyCredentialRepository $credentials;
    private MfaStepUpService $stepUp;
    private MfaProtectedOperationService $protectedOperations;
    private int $userId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing — skip live-DB test.');
        }
        $this->rootDir = $rootDir;

        try {
            $loaded = Config::load($rootDir)->all();
            $loaded['redis']['enabled'] = false;
            $loaded['session']['lifetime_days'] = 30;
            $loaded['session']['lock_after_minutes'] = 15;
            $loaded['auth']['require_mfa'] = true;
            $loaded['auth']['allowed_mfa_methods'] = ['passkey', 'totp'];
            $this->config = new Config($loaded);
            $this->db = new Connection($this->config);
            if (!$this->db->hasTable('webauthn_credentials')
                || !$this->db->hasTable('webauthn_ceremonies')
                || !$this->db->hasTable('mfa_step_up_proofs')
                || !$this->db->hasColumn('users', 'session_lock_after_minutes')
            ) {
                $this->markTestSkipped('WebAuthn/session preference migrations are not applied.');
            }

            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO users (email, password_hash, name, role, locale)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                'atomic-auth-' . bin2hex(random_bytes(8)) . '@example.invalid',
                password_hash('Synthetic-test-password-42', PASSWORD_BCRYPT),
                'Synthetic Atomic Auth Test',
                'admin',
                'cs',
            ]);
            $this->userId = (int) $this->db->pdo()->lastInsertId();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        $this->clock = new AtomicAuthTestClock(
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        $this->securityClock = new PsrSecurityClock($this->clock);
        $redis = new RedisFactory($this->config);
        $this->sessions = new SessionManager(
            $this->db,
            $this->config,
            $this->securityClock,
        );
        $this->ceremonies = new WebAuthnCeremonyStore($this->db, $this->securityClock);
        $this->proofs = new MfaStepUpProofStore($this->db, $this->securityClock);
        $this->credentials = new PasskeyCredentialRepository($this->db);
        $policy = new MfaPolicyService($this->config);
        $this->stepUp = new MfaStepUpService($this->proofs, $policy, $this->credentials);
        $tokens = new ApiTokenService($this->db, $redis);
        $this->protectedOperations = new MfaProtectedOperationService(
            $this->db,
            $this->securityClock,
            $this->stepUp,
            $policy,
            $this->credentials,
            $tokens,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->userId > 0) {
            $this->db->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testPasskeyLoginCommitsCounterSessionAndCeremonyOnlyOnce(): void
    {
        [$credentialId, $record] = $this->createCredential(4, 'Login key');
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_LOGIN,
            $this->userId,
            null,
            null,
            random_bytes(32),
            ['challenge' => 'synthetic-login'],
            '127.0.0.1',
            'PHPUnit',
        );
        $transition = $this->transitionFor($record->publicKeyCredentialId);

        $completed = $transition->completeLogin(
            $flowToken,
            ['rawId' => 'synthetic'],
            '127.0.0.1',
            'PHPUnit',
            $this->userId,
        );

        $session = $this->sessions->load($completed['session']['token']);
        self::assertNotNull($session);
        self::assertSame('passkey', $session['auth_method']);
        self::assertSame('strong', $session['assurance_level']);
        self::assertSame($credentialId, $session['auth_credential_id']);
        self::assertSame(
            5,
            $this->credentials->findActiveForUserById($this->userId, $credentialId)?->record->counter,
        );
        $lastLogin = $this->db->pdo()->prepare('SELECT last_login_at FROM users WHERE id = ?');
        $lastLogin->execute([$this->userId]);
        self::assertNotNull($lastLogin->fetchColumn());

        $this->expectException(OneTimeTokenException::class);
        $transition->completeLogin(
            $flowToken,
            ['rawId' => 'synthetic'],
            '127.0.0.1',
            'PHPUnit',
            $this->userId,
        );
    }

    /**
     * last_login_at má sekundovou přesnost. Druhé přihlášení ve stejné sekundě ze
     * stejné IP a UA nezmění žádný sloupec, takže rowCount() (bez FOUND_ROWS vrací
     * změněné, ne nalezené řádky) je 0 — to nesmí být vyhodnoceno jako neaktivní účet.
     */
    public function testSecondPasskeyLoginWithinTheSameSecondStillSucceeds(): void
    {
        [, $record] = $this->createCredential(7, 'Same second key');
        $transition = $this->transitionFor($record->publicKeyCredentialId);

        $first = $transition->completeLogin(
            $this->createLoginFlow(),
            ['rawId' => 'synthetic'],
            '127.0.0.1',
            'PHPUnit',
            $this->userId,
        );
        $second = $transition->completeLogin(
            $this->createLoginFlow(),
            ['rawId' => 'synthetic'],
            '127.0.0.1',
            'PHPUnit',
            $this->userId,
        );

        self::assertNotSame($first['session']['token'], $second['session']['token']);
        self::assertNotNull($this->sessions->load($second['session']['token']));
    }

    private function createLoginFlow(): string
    {
        return $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_LOGIN,
            $this->userId,
            null,
            null,
            random_bytes(32),
            ['challenge' => 'synthetic-login'],
            '127.0.0.1',
            'PHPUnit',
        );
    }

    public function testDiscoverablePasskeyLoginResolvesCredentialAndCommitsStrongSession(): void
    {
        [$credentialId, $record] = $this->createCredential(2, 'Passwordless login key');
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_DISCOVERABLE_LOGIN,
            null,
            null,
            null,
            random_bytes(32),
            ['challenge' => 'synthetic-passwordless-login', 'allowCredentials' => []],
            '127.0.0.1',
            'PHPUnit',
        );
        $transition = $this->transitionFor($record->publicKeyCredentialId);
        $payload = ['rawId' => 'synthetic', 'response' => ['userHandle' => 'synthetic']];

        $candidateUserId = $transition->discoverableLoginUserId($payload);
        self::assertSame($this->userId, $candidateUserId);
        $completed = $transition->completeDiscoverableLogin(
            $flowToken,
            $payload,
            '127.0.0.1',
            'PHPUnit',
            $candidateUserId,
        );

        $session = $this->sessions->load($completed['session']['token']);
        self::assertNotNull($session);
        self::assertSame('passkey', $session['auth_method']);
        self::assertSame('strong', $session['assurance_level']);
        self::assertSame($credentialId, $session['auth_credential_id']);
        self::assertSame(
            3,
            $this->credentials->findActiveForUserById($this->userId, $credentialId)?->record->counter,
        );

        $this->expectException(OneTimeTokenException::class);
        $transition->completeDiscoverableLogin(
            $flowToken,
            $payload,
            '127.0.0.1',
            'PHPUnit',
            $candidateUserId,
        );
    }

    public function testUnlockCommitsCounterAndSessionRotationTogether(): void
    {
        [$credentialId, $record] = $this->createCredential(8, 'Unlock key');
        $created = $this->sessions->create(
            $this->userId,
            '127.0.0.1',
            'PHPUnit',
            SessionAuthContext::strong('passkey', $this->clock->now(), $credentialId),
        );
        $before = $this->sessions->load($created['token']);
        self::assertNotNull($before);
        $locks = new SessionLockService(
            $this->db,
            new SessionLockPolicy($this->config),
            $this->securityClock,
            $this->ceremonies,
        );
        self::assertTrue($locks->lockManually($created['token'])->locked);
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_UNLOCK,
            $this->userId,
            $created['token'],
            null,
            random_bytes(32),
            ['challenge' => 'synthetic-unlock'],
            '127.0.0.1',
            'PHPUnit',
        );

        $completed = $this->transitionFor($record->publicKeyCredentialId)->completeUnlock(
            $flowToken,
            ['rawId' => 'synthetic'],
            $this->userId,
            $created['token'],
        );
        $after = $this->sessions->load($completed['session']['token']);

        self::assertNull($this->sessions->load($created['token']));
        self::assertNotNull($after);
        self::assertSame($before['expires_at'], $after['expires_at']);
        self::assertSame(2, $after['generation']);
        self::assertNull($after['locked_at']);
        self::assertSame(
            9,
            $this->credentials->findActiveForUserById($this->userId, $credentialId)?->record->counter,
        );
    }

    public function testPasskeyStepUpCommitsCounterCeremonyAndProofTogether(): void
    {
        [$credentialId, $record] = $this->createCredential(2, 'Step-up key');
        $session = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit');
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_STEP_UP,
            $this->userId,
            $session['token'],
            MfaStepUpService::OPERATION_API_TOKEN_CREATE,
            random_bytes(32),
            ['challenge' => 'synthetic-step-up'],
            '127.0.0.1',
            'PHPUnit',
        );
        $transition = $this->transitionFor($record->publicKeyCredentialId);

        $completed = $transition->completeStepUp(
            $flowToken,
            ['rawId' => 'synthetic'],
            $this->userId,
            $session['token'],
            MfaStepUpService::OPERATION_API_TOKEN_CREATE,
        );

        self::assertSame($credentialId, $completed['credential']->id);
        self::assertSame(
            3,
            $this->credentials->findActiveForUserById($this->userId, $credentialId)?->record->counter,
        );
        $proof = $this->stepUp->consume(
            $completed['proof_token'],
            $this->userId,
            $session['token'],
            MfaStepUpService::OPERATION_API_TOKEN_CREATE,
        );
        self::assertSame($credentialId, $proof->authCredentialId);

        $this->expectException(OneTimeTokenException::class);
        $transition->completeStepUp(
            $flowToken,
            ['rawId' => 'synthetic'],
            $this->userId,
            $session['token'],
            MfaStepUpService::OPERATION_API_TOKEN_CREATE,
        );
    }

    public function testInactiveUserConsumesLoginCeremonyBeforeFailure(): void
    {
        [, $record] = $this->createCredential(0, 'Inactive user key');
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_LOGIN,
            $this->userId,
            null,
            null,
            random_bytes(32),
            ['challenge' => 'synthetic-inactive-login'],
            '127.0.0.1',
            'PHPUnit',
        );
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')
            ->execute([$this->userId]);

        try {
            $this->transitionFor($record->publicKeyCredentialId)->completeLogin(
                $flowToken,
                ['rawId' => 'synthetic'],
                '127.0.0.1',
                'PHPUnit',
                $this->userId,
            );
            self::fail('Neaktivní uživatel nesmí dokončit passkey login.');
        } catch (PasskeyVerificationException) {
        }

        $this->assertCeremonyUsed($flowToken);
    }

    public function testUnavailableLockedSessionConsumesUnlockCeremonyBeforeFailure(): void
    {
        [$credentialId, $record] = $this->createCredential(0, 'Unavailable unlock key');
        $session = $this->sessions->create(
            $this->userId,
            '127.0.0.1',
            'PHPUnit',
            SessionAuthContext::strong('passkey', $this->clock->now(), $credentialId),
        );
        $locks = new SessionLockService(
            $this->db,
            new SessionLockPolicy($this->config),
            $this->securityClock,
            $this->ceremonies,
        );
        self::assertTrue($locks->lockManually($session['token'])->locked);
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_UNLOCK,
            $this->userId,
            $session['token'],
            null,
            random_bytes(32),
            ['challenge' => 'synthetic-unavailable-unlock'],
            '127.0.0.1',
            'PHPUnit',
        );
        $this->sessions->destroy($session['token']);

        try {
            $this->transitionFor($record->publicKeyCredentialId)->completeUnlock(
                $flowToken,
                ['rawId' => 'synthetic'],
                $this->userId,
                $session['token'],
            );
            self::fail('Revokovaná session se nesmí odemknout.');
        } catch (\DomainException) {
        }

        $this->assertCeremonyUsed($flowToken);
    }

    public function testInvalidStepUpOperationConsumesCeremonyBeforeFailure(): void
    {
        [, $record] = $this->createCredential(0, 'Invalid operation key');
        $session = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit');
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_STEP_UP,
            $this->userId,
            $session['token'],
            MfaStepUpService::OPERATION_API_TOKEN_CREATE,
            random_bytes(32),
            ['challenge' => 'synthetic-invalid-operation'],
            '127.0.0.1',
            'PHPUnit',
        );

        try {
            $this->transitionFor($record->publicKeyCredentialId)->completeStepUp(
                $flowToken,
                ['rawId' => 'synthetic'],
                $this->userId,
                $session['token'],
                'admin.anything',
            );
            self::fail('Neplatná step-up operace musí selhat.');
        } catch (StepUpOperationException) {
        }

        $this->assertCeremonyUsed($flowToken);
    }

    public function testFailureAfterCounterMutationKeepsCeremonyUsedAndRollsBackCredential(): void
    {
        [$credentialId, $record] = $this->createCredential(4, 'Counter race key');
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_LOGIN,
            $this->userId,
            null,
            null,
            random_bytes(32),
            ['challenge' => 'synthetic-counter-race'],
            '127.0.0.1',
            'PHPUnit',
        );
        $transition = $this->transitionFor(
            $record->publicKeyCredentialId,
            function () use ($credentialId): void {
                $this->db->pdo()->prepare(
                    'UPDATE webauthn_credentials SET sign_count = sign_count + 100 WHERE id = ?'
                )->execute([$credentialId]);
            },
        );

        try {
            $transition->completeLogin(
                $flowToken,
                ['rawId' => 'synthetic'],
                '127.0.0.1',
                'PHPUnit',
                $this->userId,
            );
            self::fail('Souběžná změna counteru musí assertion odmítnout.');
        } catch (PasskeyCounterAnomalyException) {
        }

        self::assertSame(
            4,
            $this->credentials->findActiveForUserById($this->userId, $credentialId)?->record->counter,
        );
        $this->assertCeremonyUsed($flowToken);
    }

    public function testApiTokenFailureRollsBackProofAndSuccessfulRetryConsumesIt(): void
    {
        $session = $this->sessions->create($this->userId, '127.0.0.1', 'PHPUnit');
        $proof = $this->proofs->issue(
            $this->userId,
            $session['token'],
            MfaStepUpService::OPERATION_API_TOKEN_CREATE,
            'totp',
        );

        try {
            $this->protectedOperations->createApiToken(
                $this->userId,
                $session['token'],
                $proof,
                PHP_INT_MAX,
                'Synthetic failed token',
                'read',
                null,
            );
            self::fail('Neplatný supplier musí porušit FK.');
        } catch (\PDOException) {
        }

        $created = $this->protectedOperations->createApiToken(
            $this->userId,
            $session['token'],
            $proof,
            null,
            'Synthetic successful token',
            'read',
            null,
        );
        self::assertGreaterThan(0, $created['id']);
        self::assertStringStartsWith('mi_pat_', $created['plaintext']);

        $this->expectException(OneTimeTokenException::class);
        $this->protectedOperations->createApiToken(
            $this->userId,
            $session['token'],
            $proof,
            null,
            'Synthetic replay token',
            'read',
            null,
        );
    }

    public function testPasskeyRevokePreservesCurrentFamilyAndRevokesOtherSessions(): void
    {
        [$targetId] = $this->createCredential(0, 'Target key');
        $this->createCredential(0, 'Remaining key');
        $current = $this->sessions->create($this->userId, '127.0.0.1', 'Current');
        $other = $this->sessions->create($this->userId, '127.0.0.2', 'Other');
        $proof = $this->proofs->issue(
            $this->userId,
            $current['token'],
            'passkey.revoke:' . $targetId,
            'passkey',
            $targetId,
        );

        $revoked = $this->protectedOperations->revokePasskey(
            $this->userId,
            $current['token'],
            $targetId,
            $proof,
        );

        self::assertSame($targetId, $revoked->id);
        self::assertNull($this->credentials->findActiveForUserById($this->userId, $targetId));
        self::assertNotNull($this->sessions->load($current['token']));
        self::assertNull($this->sessions->load($other['token']));
    }

    public function testLastFactorRejectionDoesNotConsumeProof(): void
    {
        [$credentialId] = $this->createCredential(0, 'Last key');
        $current = $this->sessions->create($this->userId, '127.0.0.1', 'Current');
        $proof = $this->proofs->issue(
            $this->userId,
            $current['token'],
            'passkey.revoke:' . $credentialId,
            'passkey',
            $credentialId,
        );

        try {
            $this->protectedOperations->revokePasskey(
                $this->userId,
                $current['token'],
                $credentialId,
                $proof,
            );
            self::fail('Poslední povolený faktor nesmí být odebrán.');
        } catch (LastMfaFactorException) {
        }

        $this->db->pdo()->prepare('UPDATE users SET totp_enabled = 1 WHERE id = ?')
            ->execute([$this->userId]);
        $this->protectedOperations->revokePasskey(
            $this->userId,
            $current['token'],
            $credentialId,
            $proof,
        );
        self::assertNull($this->credentials->findActiveForUserById($this->userId, $credentialId));
    }

    public function testConcurrentSessionRotationCreatesOnlyOneNewGeneration(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is unavailable.');
        }

        [$credentialId] = $this->createCredential(0, 'Concurrent unlock key');
        $session = $this->sessions->create(
            $this->userId,
            '127.0.0.1',
            'PHPUnit',
            SessionAuthContext::strong('passkey', $this->clock->now(), $credentialId),
        );
        $locks = new SessionLockService(
            $this->db,
            new SessionLockPolicy($this->config),
            $this->securityClock,
            $this->ceremonies,
        );
        self::assertTrue($locks->lockManually($session['token'])->locked);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $lockUser = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lockUser->execute([$this->userId]);
        self::assertSame($this->userId, (int) $lockUser->fetchColumn());

        $workers = [];
        $barrierDir = $this->createBarrierDirectory();
        $barrier = $barrierDir . DIRECTORY_SEPARATOR . 'go';
        try {
            $arguments = [
                'rotate',
                $this->rootDir,
                $barrier,
                $session['token'],
                (string) $credentialId,
            ];
            $workers[] = $this->startConcurrencyWorker($arguments);
            $workers[] = $this->startConcurrencyWorker($arguments);
            $this->waitForWorkers($workers, $barrierDir);
            touch($barrier);
            usleep(100_000);
            $pdo->commit();
            $results = $this->collectWorkers($workers);
            $workers = [];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->terminateWorkers($workers);
            $this->cleanupBarrier($barrierDir, $barrier);
        }

        self::assertSame(1, count(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] === true,
        )), json_encode($results, JSON_THROW_ON_ERROR));
        $generations = $pdo->prepare(
            'SELECT generation, replaced_at, revoked_at
               FROM sessions
              WHERE session_family_id = (
                    SELECT session_family_id FROM sessions WHERE id = ?
              )
              ORDER BY generation'
        );
        $generations->execute([$session['token']]);
        $rows = $generations->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        self::assertSame(1, (int) $rows[0]['generation']);
        self::assertNotNull($rows[0]['replaced_at']);
        self::assertSame(2, (int) $rows[1]['generation']);
        self::assertNull($rows[1]['replaced_at']);
        self::assertNull($rows[1]['revoked_at']);
    }

    public function testConcurrentCeremonyConsumptionSucceedsOnlyOnce(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is unavailable.');
        }

        $sessionToken = 'synthetic-session';
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_REGISTER,
            $this->userId,
            $sessionToken,
            null,
            random_bytes(32),
            ['challenge' => 'synthetic-concurrency'],
            '127.0.0.1',
            'PHPUnit',
        );
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $lockFlow = $pdo->prepare(
            'SELECT flow_token_hash
               FROM webauthn_ceremonies
              WHERE flow_token_hash = ?
              FOR UPDATE'
        );
        $lockFlow->execute([hash('sha256', $flowToken, true)]);
        self::assertNotFalse($lockFlow->fetchColumn());

        $workers = [];
        $barrierDir = $this->createBarrierDirectory();
        $barrier = $barrierDir . DIRECTORY_SEPARATOR . 'go';
        try {
            $arguments = [
                'consume',
                $this->rootDir,
                $barrier,
                $flowToken,
                WebAuthnCeremonyStore::PURPOSE_REGISTER,
                (string) $this->userId,
                $sessionToken,
            ];
            $workers[] = $this->startConcurrencyWorker($arguments);
            $workers[] = $this->startConcurrencyWorker($arguments);
            $this->waitForWorkers($workers, $barrierDir);
            touch($barrier);
            usleep(100_000);
            $pdo->commit();
            $results = $this->collectWorkers($workers);
            $workers = [];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->terminateWorkers($workers);
            $this->cleanupBarrier($barrierDir, $barrier);
        }

        self::assertSame(1, count(array_filter(
            $results,
            static fn (array $result): bool => $result['ok'] === true,
        )), json_encode($results, JSON_THROW_ON_ERROR));
        $used = $pdo->prepare(
            'SELECT used_at FROM webauthn_ceremonies WHERE flow_token_hash = ?'
        );
        $used->execute([hash('sha256', $flowToken, true)]);
        self::assertNotNull($used->fetchColumn());
    }

    private function createBarrierDirectory(): string
    {
        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'myinvoice-auth-race-'
            . bin2hex(random_bytes(8));
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('Testovací barrier adresář se nepodařilo vytvořit.');
        }
        return $path;
    }

    /**
     * @param list<string> $arguments
     * @return array{process:resource,pipes:array<int,resource>,output:string}
     */
    private function startConcurrencyWorker(array $arguments): array
    {
        $worker = dirname(__DIR__, 3) . '/Support/AuthConcurrencyWorker.php';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $worker, ...$arguments],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Concurrency worker se nepodařilo spustit.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return [
            'process' => $process,
            'pipes' => $pipes,
            'output' => '',
        ];
    }

    /**
     * @param array<int,array{process:resource,pipes:array<int,resource>,output:string}> $workers
     */
    private function waitForWorkers(array &$workers, string $barrierDir): void
    {
        $expected = count($workers);
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            clearstatcache();
            if (count(glob($barrierDir . DIRECTORY_SEPARATOR . 'ready-*') ?: []) >= $expected) {
                return;
            }
            foreach ($workers as $worker) {
                $status = proc_get_status($worker['process']);
                if (!$status['running']) {
                    throw new \RuntimeException(
                        'Concurrency worker skončil dřív, než se připravil: '
                        . trim((string) stream_get_contents($worker['pipes'][2])),
                    );
                }
            }
            usleep(10_000);
        }
        throw new \RuntimeException('Concurrency workers se nepřipravily včas.');
    }

    private function cleanupBarrier(string $barrierDir, string $barrier): void
    {
        if (is_file($barrier)) {
            unlink($barrier);
        }
        foreach (glob($barrierDir . DIRECTORY_SEPARATOR . 'ready-*') ?: [] as $readyFile) {
            unlink($readyFile);
        }
        if (is_dir($barrierDir)) {
            rmdir($barrierDir);
        }
    }

    /**
     * @param array<int,array{process:resource,pipes:array<int,resource>,output:string}> $workers
     * @return list<array{ok:bool,error?:string,exit_code:int}>
     */
    private function collectWorkers(array $workers): array
    {
        $results = [];
        foreach ($workers as $worker) {
            stream_set_blocking($worker['pipes'][1], true);
            stream_set_blocking($worker['pipes'][2], true);
            $stdout = $worker['output'] . stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);
            $lines = array_values(array_filter(
                preg_split('/\R/', trim($stdout)) ?: [],
                static fn (string $line): bool => str_starts_with($line, '{'),
            ));
            $payload = $lines !== []
                ? json_decode($lines[array_key_last($lines)], true, flags: JSON_THROW_ON_ERROR)
                : null;
            if (!is_array($payload) || !isset($payload['ok'])) {
                throw new \RuntimeException(
                    'Concurrency worker nevrátil výsledek: ' . trim((string) $stderr),
                );
            }
            $payload['exit_code'] = $exitCode;
            $results[] = $payload;
        }
        return $results;
    }

    /**
     * @param array<int,array{process:resource,pipes:array<int,resource>,output:string}> $workers
     */
    private function terminateWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                proc_terminate($worker['process']);
            }
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($worker['process']);
        }
    }

    /**
     * @return array{int,CredentialRecord}
     */
    private function createCredential(int $counter, string $label): array
    {
        $handle = $this->credentials->userHandle($this->userId);
        $record = CredentialRecord::create(
            random_bytes(32),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromBinary(str_repeat("\0", 16)),
            random_bytes(77),
            $handle,
            $counter,
            null,
            true,
            false,
            true,
        );
        return [$this->credentials->save($this->userId, $record, $label), $record];
    }

    private function transitionFor(
        string $credentialId,
        ?\Closure $beforeCounterUpdate = null,
    ): PasskeySessionTransitionService
    {
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->method('credentialId')->willReturn($credentialId);
        $verify = static function (
            array $payload,
            array $options,
            CredentialRecord $stored,
        ) use ($beforeCounterUpdate): CredentialRecord {
            $beforeCounterUpdate?->__invoke();
            $verified = clone $stored;
            $verified->counter++;
            return $verified;
        };
        $passkeys->method('verifyAssertion')->willReturnCallback($verify);
        $passkeys->method('verifyDiscoverableAssertion')->willReturnCallback($verify);
        return new PasskeySessionTransitionService(
            $this->db,
            $this->securityClock,
            $passkeys,
            $this->credentials,
            $this->ceremonies,
            $this->sessions,
            $this->stepUp,
            $this->proofs,
        );
    }

    private function assertCeremonyUsed(string $flowToken): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT used_at FROM webauthn_ceremonies WHERE flow_token_hash = ?'
        );
        $stmt->execute([hash('sha256', $flowToken, true)]);
        self::assertNotNull($stmt->fetchColumn());
    }
}

final class AtomicAuthTestClock implements ClockInterface
{
    public function __construct(private readonly \DateTimeImmutable $current) {}

    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }
}
