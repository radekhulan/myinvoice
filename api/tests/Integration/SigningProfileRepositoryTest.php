<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SigningProfileRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class SigningProfileRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SigningProfileRepository $profiles;
    private int $supplierId;
    private ?int $userId = null;
    /** @var list<int> */
    private array $createdProfiles = [];
    /** @var list<array{usage:string,entity_type:string,entity_id:int}> */
    private array $createdDocumentOverrides = [];
    private ?array $originalSettings = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }

        try {
            $app = Bootstrap::buildApp();
            $container = $app->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->pdo = $container->get(Connection::class)->pdo();
            $this->profiles = $container->get(SigningProfileRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $this->supplierId = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierId <= 0) {
            $this->markTestSkipped('No supplier');
        }

        $uid = $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $this->userId = $uid !== false && $uid !== null ? (int) $uid : null;

        $stmt = $this->pdo->prepare('SELECT * FROM signing_settings WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->originalSettings = $row !== false ? $row : null;
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo) || !isset($this->supplierId) || $this->supplierId <= 0) {
            return;
        }

        foreach ($this->createdDocumentOverrides as $override) {
            $this->pdo->prepare(
                'DELETE FROM signature_document_overrides
                  WHERE supplier_id = ? AND `usage` = ? AND entity_type = ? AND entity_id = ?'
            )->execute([
                $this->supplierId,
                $override['usage'],
                $override['entity_type'],
                $override['entity_id'],
            ]);
        }

        if ($this->createdProfiles !== []) {
            $in = implode(',', array_fill(0, count($this->createdProfiles), '?'));
            $this->pdo->prepare("DELETE FROM pdf_signature_output_settings WHERE default_profile_id IN ($in)")
                ->execute($this->createdProfiles);
            $this->pdo->prepare("DELETE FROM signature_role_profiles WHERE profile_id IN ($in)")
                ->execute($this->createdProfiles);
            $this->pdo->prepare("DELETE FROM signature_user_profiles WHERE profile_id IN ($in)")
                ->execute($this->createdProfiles);
            $this->pdo->prepare("DELETE FROM signature_document_overrides WHERE admin_profile_id IN ($in)")
                ->execute($this->createdProfiles);
            $this->pdo->prepare("DELETE FROM signing_profiles WHERE id IN ($in)")
                ->execute($this->createdProfiles);
        }

        if ($this->originalSettings === null) {
            $this->pdo->prepare('DELETE FROM signing_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO signing_settings (supplier_id, accountant_profiles_enabled)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE accountant_profiles_enabled = VALUES(accountant_profiles_enabled)'
            )->execute([
                $this->supplierId,
                (int) $this->originalSettings['accountant_profiles_enabled'],
            ]);
        }
    }

    public function testSettingsDefaultAndUpsert(): void
    {
        if ($this->originalSettings !== null) {
            $this->pdo->prepare('DELETE FROM signing_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
        }

        $settings = $this->profiles->settings($this->supplierId);
        self::assertSame($this->supplierId, $settings['supplier_id']);
        self::assertFalse($settings['accountant_profiles_enabled']);

        $this->profiles->setAccountantProfilesEnabled($this->supplierId, true);
        self::assertTrue($this->profiles->settings($this->supplierId)['accountant_profiles_enabled']);
    }

    public function testProfileCredentialAndOutputSettingAreSupplierScoped(): void
    {
        if ($this->userId === null) {
            $this->markTestSkipped('No user');
        }

        $profileId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: $this->userId,
            name: 'Integration signing profile',
            code: 'itest_' . bin2hex(random_bytes(4)),
            allowedUsages: ['pdf', 'email_smime'],
            defaultBackend: 'native',
            createdBy: $this->userId,
        );
        $this->createdProfiles[] = $profileId;

        $profile = $this->profiles->findProfile($this->supplierId, $profileId);
        self::assertNotNull($profile);
        self::assertSame($this->supplierId, $profile['supplier_id']);
        self::assertSame($this->userId, $profile['owner_user_id']);
        self::assertSame(['pdf', 'email_smime'], $profile['allowed_usages']);

        self::assertNull($this->profiles->findProfile($this->supplierId + 200, $profileId));

        $owned = $this->profiles->listProfilesForOwner($this->supplierId, $this->userId);
        self::assertContains($profileId, array_map(static fn (array $row): int => (int) $row['id'], $owned));

        $this->profiles->upsertCredential($this->supplierId, $profileId, [
            'certificate_path' => 'signing/pdf/itest.p12',
            'certificate_fingerprint' => str_repeat('a', 64),
            'certificate_subject' => 'CN=Integration Test',
            'certificate_usage' => ['digital_signature' => true],
            'passphrase_policy' => 'passphrase_file',
            'passphrase_profile_id' => 'itest-profile',
            'encrypted_passphrase' => null,
        ], $this->userId);

        $credential = $this->profiles->credential($this->supplierId, $profileId);
        self::assertNotNull($credential);
        self::assertSame('passphrase_file', $credential['passphrase_policy']);
        self::assertSame('itest-profile', $credential['passphrase_profile_id']);
        self::assertSame(['digital_signature' => true], $credential['certificate_usage']);

        self::assertTrue($this->profiles->updateCredentialPassphrasePolicy(
            $this->supplierId,
            $profileId,
            'prompt_on_use',
            null,
            null,
        ));
        $credential = $this->profiles->credential($this->supplierId, $profileId);
        self::assertNotNull($credential);
        self::assertSame('prompt_on_use', $credential['passphrase_policy']);
        self::assertNull($credential['passphrase_profile_id']);
        self::assertNull($credential['encrypted_passphrase']);
        self::assertSame('signing/pdf/itest.p12', $credential['certificate_path']);

        self::assertTrue($this->profiles->updateCredentialPassphrasePolicy(
            $this->supplierId,
            $profileId,
            'encrypted_store',
            null,
            'enc:v1:rotated',
        ));
        $credential = $this->profiles->credential($this->supplierId, $profileId);
        self::assertNotNull($credential);
        self::assertSame('encrypted_store', $credential['passphrase_policy']);
        self::assertSame('enc:v1:rotated', $credential['encrypted_passphrase']);
        self::assertSame('signing/pdf/itest.p12', $credential['certificate_path']);

        self::assertTrue($this->profiles->softDeleteCredential($this->supplierId, $profileId));
        self::assertNull($this->profiles->credential($this->supplierId, $profileId));

        $this->profiles->upsertCredential($this->supplierId, $profileId, [
            'certificate_path' => 'signing/pdf/itest-restored.p12',
            'passphrase_policy' => 'encrypted_store',
            'encrypted_passphrase' => 'enc:v1:test',
        ], $this->userId);
        self::assertNotNull($this->profiles->credential($this->supplierId, $profileId));

        $adminProfileId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: null,
            name: 'Integration admin signing profile',
            code: 'itest_admin_' . bin2hex(random_bytes(4)),
            allowedUsages: ['pdf'],
            defaultBackend: 'native',
            createdBy: $this->userId,
            pdfTsaUrl: 'http://tsa.example.test',
            pdfTsaUsername: 'tsa-user',
            pdfTsaPasswordEnc: 'enc:v1:tsa',
            pdfReason: 'Integration test',
        );
        $this->createdProfiles[] = $adminProfileId;
        $adminProfile = $this->profiles->findProfile($this->supplierId, $adminProfileId);
        self::assertNotNull($adminProfile);
        self::assertNull($adminProfile['owner_user_id']);
        self::assertSame('http://tsa.example.test', $adminProfile['pdf_tsa_url']);
        self::assertSame('tsa-user', $adminProfile['pdf_tsa_username']);
        self::assertTrue($adminProfile['has_pdf_tsa_password']);
        self::assertSame('Integration test', $adminProfile['pdf_reason']);
        self::assertSame('enc:v1:tsa', $this->profiles->profilePdfTsaPasswordEnc($this->supplierId, $adminProfileId));

        $this->profiles->upsertOutputSetting($this->supplierId, 'invoice', [
            'enabled' => true,
            'selection_source' => 'admin_profile_settings',
            'default_profile_id' => $adminProfileId,
            'failure_policy' => 'fallback_unsigned',
            'signature_config' => ['appearance' => 'invisible'],
        ]);

        $output = $this->profiles->outputSetting($this->supplierId, 'invoice');
        self::assertSame('pdf', $output['usage']);
        self::assertSame($adminProfileId, $output['default_profile_id']);
        self::assertSame('admin_profile_settings', $output['selection_source']);
        self::assertSame(['appearance' => 'invisible'], $output['signature_config']);

        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->upsertOutputSetting($this->supplierId, 'invoice', [
            'selection_source' => 'admin_profile_settings',
            'default_profile_id' => $profileId,
        ]);
    }

    public function testEmailOutputSettingRequiresEmailSmimeProfile(): void
    {
        $emailProfileId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: null,
            name: 'Integration email signing profile',
            code: 'itest_email_' . bin2hex(random_bytes(4)),
            allowedUsages: ['email_smime'],
            defaultBackend: 'native',
            createdBy: $this->userId,
        );
        $this->createdProfiles[] = $emailProfileId;

        $this->profiles->upsertOutputSetting($this->supplierId, 'email_invoice_send', [
            'enabled' => true,
            'backend' => 'smime',
            'selection_source' => 'admin_profile_settings',
            'default_profile_id' => $emailProfileId,
            'failure_policy' => 'fail_closed',
        ], 'email_smime');

        $output = $this->profiles->outputSetting($this->supplierId, 'email_invoice_send', 'email_smime');
        self::assertSame('email_smime', $output['usage']);
        self::assertSame($emailProfileId, $output['default_profile_id']);
        self::assertSame('smime', $output['backend']);

        $pdfOnlyProfileId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: null,
            name: 'Integration PDF only profile',
            code: 'itest_pdf_only_' . bin2hex(random_bytes(4)),
            allowedUsages: ['pdf'],
            defaultBackend: 'native',
            createdBy: $this->userId,
        );
        $this->createdProfiles[] = $pdfOnlyProfileId;

        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->upsertOutputSetting($this->supplierId, 'email_invoice_send', [
            'selection_source' => 'admin_profile_settings',
            'default_profile_id' => $pdfOnlyProfileId,
        ], 'email_smime');
    }

    public function testSoftDeletedProfileCodeCanBeReused(): void
    {
        $code = 'itest_reuse_' . bin2hex(random_bytes(4));
        $firstId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: null,
            name: 'Deleted signing profile',
            code: $code,
            allowedUsages: ['pdf'],
            defaultBackend: 'native',
            createdBy: $this->userId,
        );
        $this->createdProfiles[] = $firstId;

        self::assertTrue($this->profiles->softDeleteProfile($this->supplierId, $firstId));
        $deleted = $this->profiles->findProfile($this->supplierId, $firstId, includeDeleted: true);
        self::assertNotNull($deleted);
        self::assertNotSame($code, $deleted['code']);

        $secondId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: null,
            name: 'Replacement signing profile',
            code: $code,
            allowedUsages: ['pdf'],
            defaultBackend: 'native',
            createdBy: $this->userId,
        );
        $this->createdProfiles[] = $secondId;

        $replacement = $this->profiles->findProfile($this->supplierId, $secondId);
        self::assertNotNull($replacement);
        self::assertSame($code, $replacement['code']);
    }

    public function testUserProfileDefaultsAreSupplierScoped(): void
    {
        if ($this->userId === null) {
            $this->markTestSkipped('No user');
        }

        $profileId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: $this->userId,
            name: 'Integration user default profile',
            code: 'itest_user_default_' . bin2hex(random_bytes(4)),
            allowedUsages: ['pdf'],
            defaultBackend: 'native',
            createdBy: $this->userId,
        );
        $this->createdProfiles[] = $profileId;

        if ($this->userId !== null) {
            $this->profiles->setUserProfileDefault($this->supplierId, 'pdf', 'invoice', $this->userId, $profileId);
            $userDefault = $this->profiles->userProfileDefault($this->supplierId, 'pdf', 'invoice', $this->userId);
            self::assertNotNull($userDefault);
            self::assertSame($profileId, $userDefault['profile_id']);

            $defaults = $this->profiles->listUserProfileDefaults($this->supplierId, 'pdf', $this->userId);
            self::assertContains($profileId, array_map(static fn (array $row): int => (int) $row['profile_id'], $defaults));

            self::assertTrue($this->profiles->deleteUserProfileDefault($this->supplierId, 'pdf', 'invoice', $this->userId));
            self::assertNull($this->profiles->userProfileDefault($this->supplierId, 'pdf', 'invoice', $this->userId));
        }

        $this->expectException(\RuntimeException::class);
        $this->profiles->upsertOutputSetting($this->supplierId + 200, 'invoice', [
            'selection_source' => 'admin_profile_settings',
            'default_profile_id' => $profileId,
        ]);
    }

    public function testDocumentOverrideStoresSelectionAndRejectsUserOwnedAdminProfile(): void
    {
        if ($this->userId === null) {
            $this->markTestSkipped('No user');
        }

        $adminProfileId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: null,
            name: 'Integration document admin profile',
            code: 'itest_doc_admin_' . bin2hex(random_bytes(4)),
            allowedUsages: ['pdf'],
            defaultBackend: 'native',
            createdBy: $this->userId,
        );
        $this->createdProfiles[] = $adminProfileId;

        $userProfileId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: $this->userId,
            name: 'Integration document user profile',
            code: 'itest_doc_user_' . bin2hex(random_bytes(4)),
            allowedUsages: ['pdf'],
            defaultBackend: 'native',
            createdBy: $this->userId,
        );
        $this->createdProfiles[] = $userProfileId;

        $entityId = random_int(900000, 999999);
        $this->createdDocumentOverrides[] = [
            'usage' => 'pdf',
            'entity_type' => 'invoice',
            'entity_id' => $entityId,
        ];

        $this->profiles->upsertDocumentOverride(
            supplierId: $this->supplierId,
            usage: 'pdf',
            entityType: 'invoice',
            entityId: $entityId,
            selectionSource: 'admin_profile_settings',
            adminProfileId: $adminProfileId,
            createdBy: $this->userId,
        );

        $override = $this->profiles->documentOverride($this->supplierId, 'pdf', 'invoice', $entityId);
        self::assertNotNull($override);
        self::assertSame('admin_profile_settings', $override['selection_source']);
        self::assertSame($adminProfileId, $override['admin_profile_id']);
        self::assertSame($this->userId, $override['created_by']);

        $this->profiles->upsertDocumentOverride(
            supplierId: $this->supplierId,
            usage: 'pdf',
            entityType: 'invoice',
            entityId: $entityId,
            selectionSource: 'logged_in_user',
            adminProfileId: $adminProfileId,
            createdBy: $this->userId,
        );

        $override = $this->profiles->documentOverride($this->supplierId, 'pdf', 'invoice', $entityId);
        self::assertNotNull($override);
        self::assertSame('logged_in_user', $override['selection_source']);
        self::assertNull($override['admin_profile_id']);

        $this->expectException(\InvalidArgumentException::class);
        $this->profiles->upsertDocumentOverride(
            supplierId: $this->supplierId,
            usage: 'pdf',
            entityType: 'invoice',
            entityId: $entityId + 1,
            selectionSource: 'admin_profile_settings',
            adminProfileId: $userProfileId,
            createdBy: $this->userId,
        );
    }

    public function testSoftDeletedProfileIsHiddenByDefault(): void
    {
        $profileId = $this->profiles->createProfile(
            $this->supplierId,
            null,
            'Integration deleted profile',
            'itest_deleted_' . bin2hex(random_bytes(4)),
        );
        $this->createdProfiles[] = $profileId;

        self::assertTrue($this->profiles->softDeleteProfile($this->supplierId, $profileId));
        self::assertNull($this->profiles->findProfile($this->supplierId, $profileId));
        self::assertNotNull($this->profiles->findProfile($this->supplierId, $profileId, true));
    }
}
