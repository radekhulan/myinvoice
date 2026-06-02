<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Per-supplier podpisové profily a výstupní nastavení.
 *
 * Repository záměrně neřeší HTTP RBAC ani audit; ty patří do Action vrstvy.
 * Všechny čtecí i zapisovací metody berou `supplier_id`, aby se později
 * nepřenesl profil mezi tenanty omylem.
 */
final class SigningProfileRepository
{
    private const USAGES = ['pdf', 'email_smime'];
    private const PASSPHRASE_POLICIES = ['encrypted_store', 'passphrase_file', 'prompt_on_use'];
    private const SELECTION_SOURCES = ['logged_in_user', 'admin_profile_settings'];
    private const USER_PROFILE_FALLBACKS = ['admin_profile_settings', 'fail_closed', 'fallback_unsigned'];
    private const FAILURE_POLICIES = ['fallback_unsigned', 'fail_closed', 'skip_when_unconfigured'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{supplier_id:int,accountant_profiles_enabled:bool}
     */
    public function settings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, accountant_profiles_enabled
               FROM signing_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'supplier_id' => $supplierId,
                'accountant_profiles_enabled' => false,
            ];
        }

        return [
            'supplier_id' => (int) $row['supplier_id'],
            'accountant_profiles_enabled' => (int) $row['accountant_profiles_enabled'] === 1,
        ];
    }

    public function setAccountantProfilesEnabled(int $supplierId, bool $enabled): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO signing_settings (supplier_id, accountant_profiles_enabled)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE accountant_profiles_enabled = VALUES(accountant_profiles_enabled)'
        );
        $stmt->execute([$supplierId, $enabled ? 1 : 0]);
    }

    /**
     * @param list<string> $allowedUsages
     */
    public function createProfile(
        int $supplierId,
        ?int $ownerUserId,
        string $name,
        string $code,
        array $allowedUsages = ['pdf'],
        string $defaultBackend = 'native',
        ?int $createdBy = null,
        bool $isActive = true,
        ?string $pdfTsaUrl = null,
        ?string $pdfTsaUsername = null,
        ?string $pdfTsaPasswordEnc = null,
        ?string $pdfReason = null,
    ): int {
        $name = $this->nonEmpty($name, 'name', 120);
        $code = $this->code($code);
        $defaultBackend = $this->nonEmpty($defaultBackend, 'default_backend', 40);
        $allowedUsages = $this->normalizeList($allowedUsages, self::USAGES, 'allowed_usages');
        $this->releaseDeletedCodeConflicts($supplierId, $code);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO signing_profiles
                (supplier_id, owner_user_id, name, code, allowed_usages_json, default_backend,
                 pdf_tsa_url, pdf_tsa_username, pdf_tsa_password_enc, pdf_reason, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $ownerUserId,
            $name,
            $code,
            $this->encodeJson($allowedUsages),
            $defaultBackend,
            $this->nullableString($pdfTsaUrl, 'pdf_tsa_url', 255),
            $this->nullableString($pdfTsaUsername, 'pdf_tsa_username', 190),
            $this->nullableString($pdfTsaPasswordEnc, 'pdf_tsa_password_enc', 255),
            $this->nullableString($pdfReason, 'pdf_reason', 120),
            $isActive ? 1 : 0,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findProfile(int $supplierId, int $profileId, bool $includeDeleted = false): ?array
    {
        $sql = 'SELECT * FROM signing_profiles WHERE supplier_id = ? AND id = ?'
             . ($includeDeleted ? '' : ' AND deleted_at IS NULL');
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateProfile($row) : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listProfiles(int $supplierId, bool $includeDeleted = false): array
    {
        $sql = 'SELECT * FROM signing_profiles WHERE supplier_id = ?'
             . ($includeDeleted ? '' : ' AND deleted_at IS NULL')
             . ' ORDER BY owner_user_id IS NOT NULL, name, id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);

        return array_map([$this, 'hydrateProfile'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listProfilesForOwner(int $supplierId, ?int $ownerUserId, bool $includeDeleted = false): array
    {
        $sql = 'SELECT * FROM signing_profiles
                 WHERE supplier_id = ? AND '
             . ($ownerUserId === null ? 'owner_user_id IS NULL' : 'owner_user_id = ?')
             . ($includeDeleted ? '' : ' AND deleted_at IS NULL')
             . ' ORDER BY name, id';
        $params = $ownerUserId === null ? [$supplierId] : [$supplierId, $ownerUserId];
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'hydrateProfile'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array{name?:string,code?:string,allowed_usages?:list<string>,default_backend?:string,is_active?:bool,pdf_tsa_url?:?string,pdf_tsa_username?:?string,pdf_tsa_password_enc?:?string,pdf_reason?:?string} $changes
     */
    public function updateProfile(int $supplierId, int $profileId, array $changes): bool
    {
        $sets = [];
        $params = [];

        if (array_key_exists('name', $changes)) {
            $sets[] = 'name = ?';
            $params[] = $this->nonEmpty((string) $changes['name'], 'name', 120);
        }
        if (array_key_exists('code', $changes)) {
            $code = $this->code((string) $changes['code']);
            $this->releaseDeletedCodeConflicts($supplierId, $code, $profileId);
            $sets[] = 'code = ?';
            $params[] = $code;
        }
        if (array_key_exists('allowed_usages', $changes)) {
            $sets[] = 'allowed_usages_json = ?';
            $params[] = $this->encodeJson($this->normalizeList($changes['allowed_usages'], self::USAGES, 'allowed_usages'));
        }
        if (array_key_exists('default_backend', $changes)) {
            $sets[] = 'default_backend = ?';
            $params[] = $this->nonEmpty((string) $changes['default_backend'], 'default_backend', 40);
        }
        if (array_key_exists('pdf_tsa_url', $changes)) {
            $sets[] = 'pdf_tsa_url = ?';
            $params[] = $this->nullableString($changes['pdf_tsa_url'], 'pdf_tsa_url', 255);
        }
        if (array_key_exists('pdf_tsa_username', $changes)) {
            $sets[] = 'pdf_tsa_username = ?';
            $params[] = $this->nullableString($changes['pdf_tsa_username'], 'pdf_tsa_username', 190);
        }
        if (array_key_exists('pdf_tsa_password_enc', $changes)) {
            $sets[] = 'pdf_tsa_password_enc = ?';
            $params[] = $this->nullableString($changes['pdf_tsa_password_enc'], 'pdf_tsa_password_enc', 255);
        }
        if (array_key_exists('pdf_reason', $changes)) {
            $sets[] = 'pdf_reason = ?';
            $params[] = $this->nullableString($changes['pdf_reason'], 'pdf_reason', 120);
        }
        if (array_key_exists('is_active', $changes)) {
            $sets[] = 'is_active = ?';
            $params[] = $changes['is_active'] ? 1 : 0;
        }

        if ($sets === []) {
            return false;
        }

        $params[] = $supplierId;
        $params[] = $profileId;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE signing_profiles SET ' . implode(', ', $sets) . '
              WHERE supplier_id = ? AND id = ? AND deleted_at IS NULL'
        );
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function softDeleteProfile(int $supplierId, int $profileId): bool
    {
        $code = $this->deletedProfileCode($supplierId, $profileId);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE signing_profiles
                SET code = ?, deleted_at = CURRENT_TIMESTAMP, is_active = 0
              WHERE supplier_id = ? AND id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$code, $supplierId, $profileId]);

        return $stmt->rowCount() > 0;
    }

    public function profilePdfTsaPasswordEnc(int $supplierId, int $profileId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pdf_tsa_password_enc
               FROM signing_profiles
              WHERE supplier_id = ? AND id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$supplierId, $profileId]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    /**
     * @param array{
     *   certificate_path:string,
     *   certificate_fingerprint?:?string,
     *   certificate_subject?:?string,
     *   certificate_email?:?string,
     *   certificate_valid_from?:?string,
     *   certificate_valid_to?:?string,
     *   certificate_usage?:?array<string,mixed>,
     *   passphrase_policy?:string,
     *   passphrase_profile_id?:?string,
     *   encrypted_passphrase?:?string,
     *   is_active?:bool
     * } $data
     */
    public function upsertCredential(
        int $supplierId,
        int $profileId,
        array $data,
        ?int $createdBy = null,
    ): void {
        $this->assertProfileExists($supplierId, $profileId);
        $path = $this->nonEmpty((string) ($data['certificate_path'] ?? ''), 'certificate_path', 255);
        $passphrasePolicy = $this->oneOf(
            (string) ($data['passphrase_policy'] ?? 'encrypted_store'),
            self::PASSPHRASE_POLICIES,
            'passphrase_policy',
        );

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO signing_credentials
                (profile_id, certificate_path, certificate_fingerprint, certificate_subject,
                 certificate_email, certificate_valid_from, certificate_valid_to, certificate_usage_json,
                 passphrase_policy, passphrase_profile_id, encrypted_passphrase, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 certificate_path = VALUES(certificate_path),
                 certificate_fingerprint = VALUES(certificate_fingerprint),
                 certificate_subject = VALUES(certificate_subject),
                 certificate_email = VALUES(certificate_email),
                 certificate_valid_from = VALUES(certificate_valid_from),
                 certificate_valid_to = VALUES(certificate_valid_to),
                 certificate_usage_json = VALUES(certificate_usage_json),
                 passphrase_policy = VALUES(passphrase_policy),
                 passphrase_profile_id = VALUES(passphrase_profile_id),
                 encrypted_passphrase = VALUES(encrypted_passphrase),
                 is_active = VALUES(is_active),
                 deleted_at = NULL'
        );
        $stmt->execute([
            $profileId,
            $path,
            $data['certificate_fingerprint'] ?? null,
            $data['certificate_subject'] ?? null,
            $data['certificate_email'] ?? null,
            $data['certificate_valid_from'] ?? null,
            $data['certificate_valid_to'] ?? null,
            isset($data['certificate_usage']) && is_array($data['certificate_usage'])
                ? $this->encodeJson($data['certificate_usage'])
                : null,
            $passphrasePolicy,
            $data['passphrase_profile_id'] ?? null,
            $data['encrypted_passphrase'] ?? null,
            ($data['is_active'] ?? true) ? 1 : 0,
            $createdBy,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function credential(int $supplierId, int $profileId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*
               FROM signing_credentials c
               JOIN signing_profiles p ON p.id = c.profile_id
              WHERE p.supplier_id = ? AND c.profile_id = ?
                AND c.deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateCredential($row) : null;
    }

    public function updateCredentialPassphrasePolicy(
        int $supplierId,
        int $profileId,
        string $passphrasePolicy,
        ?string $passphraseProfileId,
        ?string $encryptedPassphrase,
    ): bool {
        $this->assertProfileExists($supplierId, $profileId);
        $passphrasePolicy = $this->oneOf($passphrasePolicy, self::PASSPHRASE_POLICIES, 'passphrase_policy');
        $credential = $this->credential($supplierId, $profileId);
        if ($credential === null) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE signing_credentials c
               JOIN signing_profiles p ON p.id = c.profile_id
                SET c.passphrase_policy = ?,
                    c.passphrase_profile_id = ?,
                    c.encrypted_passphrase = ?
              WHERE p.supplier_id = ? AND c.id = ?
                AND c.deleted_at IS NULL'
        );
        $stmt->execute([
            $passphrasePolicy,
            $passphrasePolicy === 'passphrase_file' ? $passphraseProfileId : null,
            $passphrasePolicy === 'encrypted_store' ? $encryptedPassphrase : null,
            $supplierId,
            (int) $credential['id'],
        ]);

        return $stmt->rowCount() > 0;
    }

    public function softDeleteCredential(int $supplierId, int $profileId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE signing_credentials c
               JOIN signing_profiles p ON p.id = c.profile_id
                SET c.deleted_at = CURRENT_TIMESTAMP, c.is_active = 0
              WHERE p.supplier_id = ? AND c.profile_id = ?
                AND c.deleted_at IS NULL'
        );
        $stmt->execute([$supplierId, $profileId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array{
     *   enabled?:bool,
     *   backend?:string,
     *   selection_source?:string,
     *   user_profile_fallback?:string,
     *   default_profile_id?:?int,
     *   failure_policy?:string,
     *   signature_config?:?array<string,mixed>
     * } $data
     */
    public function upsertOutputSetting(int $supplierId, string $outputType, array $data, string $usage = 'pdf'): void
    {
        $usage = $this->oneOf($usage, self::USAGES, 'usage');
        $outputType = $this->nonEmpty($outputType, 'output_type', 40);
        $selectionSource = $this->oneOf((string) ($data['selection_source'] ?? 'admin_profile_settings'), self::SELECTION_SOURCES, 'selection_source');
        $userProfileFallback = $this->oneOf((string) ($data['user_profile_fallback'] ?? 'fallback_unsigned'), self::USER_PROFILE_FALLBACKS, 'user_profile_fallback');
        $defaultProfileId = $data['default_profile_id'] ?? null;
        $usesAdminProfile = $selectionSource === 'admin_profile_settings'
            || ($selectionSource === 'logged_in_user' && $userProfileFallback === 'admin_profile_settings');
        if (!$usesAdminProfile) {
            $defaultProfileId = null;
        }
        if ($defaultProfileId !== null) {
            $this->assertAdminProfileForUsage($supplierId, (int) $defaultProfileId, $usage);
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO pdf_signature_output_settings
                (supplier_id, `usage`, output_type, enabled, backend, selection_source,
                 user_profile_fallback, default_profile_id, failure_policy, signature_config_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 `usage` = VALUES(`usage`),
                 enabled = VALUES(enabled),
                 backend = VALUES(backend),
                 selection_source = VALUES(selection_source),
                 user_profile_fallback = VALUES(user_profile_fallback),
                 default_profile_id = VALUES(default_profile_id),
                 failure_policy = VALUES(failure_policy),
                 signature_config_json = VALUES(signature_config_json)'
        );
        $stmt->execute([
            $supplierId,
            $usage,
            $outputType,
            ($data['enabled'] ?? true) ? 1 : 0,
            $this->nonEmpty((string) ($data['backend'] ?? ($usage === 'email_smime' ? 'smime' : 'native')), 'backend', 40),
            $selectionSource,
            $userProfileFallback,
            $defaultProfileId,
            $this->oneOf((string) ($data['failure_policy'] ?? 'fallback_unsigned'), self::FAILURE_POLICIES, 'failure_policy'),
            isset($data['signature_config']) && is_array($data['signature_config'])
                ? $this->encodeJson($data['signature_config'])
                : null,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function outputSetting(int $supplierId, string $outputType, string $usage = 'pdf'): array
    {
        $usage = $this->oneOf($usage, self::USAGES, 'usage');
        $outputType = $this->nonEmpty($outputType, 'output_type', 40);
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM pdf_signature_output_settings
              WHERE supplier_id = ? AND output_type = ?'
        );
        $stmt->execute([$supplierId, $outputType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'supplier_id' => $supplierId,
                'usage' => $usage,
                'output_type' => $outputType,
                'enabled' => $usage === 'pdf',
                'backend' => $usage === 'email_smime' ? 'smime' : 'native',
                'selection_source' => 'admin_profile_settings',
                'user_profile_fallback' => 'fallback_unsigned',
                'default_profile_id' => null,
                'failure_policy' => 'fallback_unsigned',
                'signature_config' => [],
            ];
        }

        return $this->hydrateOutputSetting($row);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listUserProfileDefaults(int $supplierId, string $usage, int $userId): array
    {
        $usage = $this->oneOf($usage, self::USAGES, 'usage');
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM signature_user_profiles
              WHERE supplier_id = ? AND `usage` = ? AND user_id = ?
              ORDER BY output_type'
        );
        $stmt->execute([$supplierId, $usage, $userId]);

        return array_map([$this, 'hydrateUserProfileDefault'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function userProfileDefault(int $supplierId, string $usage, string $outputType, int $userId): ?array
    {
        $usage = $this->oneOf($usage, self::USAGES, 'usage');
        $outputType = $this->nonEmpty($outputType, 'output_type', 40);
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM signature_user_profiles
              WHERE supplier_id = ? AND `usage` = ? AND output_type = ? AND user_id = ?'
        );
        $stmt->execute([$supplierId, $usage, $outputType, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateUserProfileDefault($row) : null;
    }

    public function setUserProfileDefault(
        int $supplierId,
        string $usage,
        string $outputType,
        int $userId,
        int $profileId,
    ): void {
        $usage = $this->oneOf($usage, self::USAGES, 'usage');
        $outputType = $this->nonEmpty($outputType, 'output_type', 40);
        $profile = $this->findProfile($supplierId, $profileId);
        if ($profile === null) {
            throw new \RuntimeException('Podpisový profil neexistuje v aktuálním supplier scope.');
        }
        if (!($profile['is_active'] ?? false) || !in_array($usage, $profile['allowed_usages'] ?? [], true)) {
            throw new \InvalidArgumentException('Podpisový profil není aktivní nebo nepodporuje dané použití.');
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO signature_user_profiles
                (supplier_id, `usage`, output_type, user_id, profile_id)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE profile_id = VALUES(profile_id)'
        );
        $stmt->execute([$supplierId, $usage, $outputType, $userId, $profileId]);
    }

    public function deleteUserProfileDefault(int $supplierId, string $usage, string $outputType, int $userId): bool
    {
        $usage = $this->oneOf($usage, self::USAGES, 'usage');
        $outputType = $this->nonEmpty($outputType, 'output_type', 40);
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM signature_user_profiles
              WHERE supplier_id = ? AND `usage` = ? AND output_type = ? AND user_id = ?'
        );
        $stmt->execute([$supplierId, $usage, $outputType, $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function documentOverride(int $supplierId, string $usage, string $entityType, int $entityId): ?array
    {
        $usage = $this->oneOf($usage, self::USAGES, 'usage');
        $entityType = $this->nonEmpty($entityType, 'entity_type', 40);
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM signature_document_overrides
              WHERE supplier_id = ? AND `usage` = ? AND entity_type = ? AND entity_id = ?'
        );
        $stmt->execute([$supplierId, $usage, $entityType, $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateDocumentOverride($row) : null;
    }

    public function upsertDocumentOverride(
        int $supplierId,
        string $usage,
        string $entityType,
        int $entityId,
        string $selectionSource,
        ?int $adminProfileId,
        ?int $createdBy = null,
    ): void {
        $usage = $this->oneOf($usage, self::USAGES, 'usage');
        $entityType = $this->nonEmpty($entityType, 'entity_type', 40);
        $selectionSource = $this->oneOf($selectionSource, self::SELECTION_SOURCES, 'selection_source');
        if ($selectionSource !== 'admin_profile_settings') {
            $adminProfileId = null;
        }
        if ($adminProfileId !== null) {
            $this->assertAdminProfileForUsage($supplierId, $adminProfileId, $usage);
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO signature_document_overrides
                (supplier_id, `usage`, entity_type, entity_id, selection_source, admin_profile_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                selection_source = VALUES(selection_source),
                admin_profile_id = VALUES(admin_profile_id),
                created_by = VALUES(created_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$supplierId, $usage, $entityType, $entityId, $selectionSource, $adminProfileId, $createdBy]);
    }

    public function deleteDocumentOverride(int $supplierId, string $usage, string $entityType, int $entityId): bool
    {
        $usage = $this->oneOf($usage, self::USAGES, 'usage');
        $entityType = $this->nonEmpty($entityType, 'entity_type', 40);
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM signature_document_overrides
              WHERE supplier_id = ? AND `usage` = ? AND entity_type = ? AND entity_id = ?'
        );
        $stmt->execute([$supplierId, $usage, $entityType, $entityId]);

        return $stmt->rowCount() > 0;
    }

    private function assertProfileExists(int $supplierId, int $profileId): void
    {
        if ($this->findProfile($supplierId, $profileId) === null) {
            throw new \RuntimeException('Podpisový profil neexistuje v aktuálním supplier scope.');
        }
    }

    private function assertAdminProfileForUsage(int $supplierId, int $profileId, string $usage): void
    {
        $usage = $this->oneOf($usage, self::USAGES, 'usage');
        $profile = $this->findProfile($supplierId, $profileId);
        if ($profile === null) {
            throw new \RuntimeException('Podpisový profil neexistuje v aktuálním supplier scope.');
        }
        if (($profile['owner_user_id'] ?? null) !== null) {
            throw new \InvalidArgumentException('Profil dodavatele nesmí být uživatelský podpisový profil.');
        }
        if (!($profile['is_active'] ?? false) || !in_array($usage, $profile['allowed_usages'] ?? [], true)) {
            throw new \InvalidArgumentException('Profil dodavatele není aktivní nebo nepodporuje dané použití.');
        }
    }


    private function releaseDeletedCodeConflicts(int $supplierId, string $code, ?int $exceptProfileId = null): void
    {
        $sql = 'SELECT id
                  FROM signing_profiles
                 WHERE supplier_id = ? AND code = ? AND deleted_at IS NOT NULL';
        $params = [$supplierId, $code];
        if ($exceptProfileId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptProfileId;
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $ids = array_map(static fn ($id): int => (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        foreach ($ids as $profileId) {
            $newCode = $this->deletedProfileCode($supplierId, $profileId);
            $update = $this->db->pdo()->prepare(
                'UPDATE signing_profiles
                    SET code = ?
                  WHERE supplier_id = ? AND id = ? AND deleted_at IS NOT NULL'
            );
            $update->execute([$newCode, $supplierId, $profileId]);
        }
    }

    private function deletedProfileCode(int $supplierId, int $profileId): string
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $suffix = $attempt === 0 ? '' : '_' . $attempt;
            $candidate = 'deleted_' . $profileId . $suffix;
            if (!$this->codeExists($supplierId, $candidate, $profileId)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Nelze uvolnit kód smazaného podpisového profilu.');
    }

    private function codeExists(int $supplierId, string $code, int $exceptProfileId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM signing_profiles
              WHERE supplier_id = ? AND code = ? AND id <> ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $code, $exceptProfileId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateProfile(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'owner_user_id' => $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null,
            'name' => (string) $row['name'],
            'code' => (string) $row['code'],
            'allowed_usages' => $this->decodeJsonList($row['allowed_usages_json'] ?? '[]'),
            'default_backend' => (string) $row['default_backend'],
            'pdf_tsa_url' => ($row['pdf_tsa_url'] ?? null) !== null ? (string) $row['pdf_tsa_url'] : null,
            'pdf_tsa_username' => ($row['pdf_tsa_username'] ?? null) !== null ? (string) $row['pdf_tsa_username'] : null,
            'has_pdf_tsa_password' => ($row['pdf_tsa_password_enc'] ?? null) !== null && (string) $row['pdf_tsa_password_enc'] !== '',
            'pdf_reason' => ($row['pdf_reason'] ?? null) !== null ? (string) $row['pdf_reason'] : null,
            'is_active' => (int) $row['is_active'] === 1,
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'deleted_at' => $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateCredential(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'profile_id' => (int) $row['profile_id'],
            'certificate_path' => (string) $row['certificate_path'],
            'certificate_fingerprint' => $row['certificate_fingerprint'] !== null ? (string) $row['certificate_fingerprint'] : null,
            'certificate_subject' => $row['certificate_subject'] !== null ? (string) $row['certificate_subject'] : null,
            'certificate_email' => $row['certificate_email'] !== null ? (string) $row['certificate_email'] : null,
            'certificate_valid_from' => $row['certificate_valid_from'] !== null ? (string) $row['certificate_valid_from'] : null,
            'certificate_valid_to' => $row['certificate_valid_to'] !== null ? (string) $row['certificate_valid_to'] : null,
            'certificate_usage' => $this->decodeJsonObject($row['certificate_usage_json'] ?? null),
            'passphrase_policy' => (string) $row['passphrase_policy'],
            'passphrase_profile_id' => $row['passphrase_profile_id'] !== null ? (string) $row['passphrase_profile_id'] : null,
            'encrypted_passphrase' => $row['encrypted_passphrase'] !== null ? (string) $row['encrypted_passphrase'] : null,
            'is_active' => (int) $row['is_active'] === 1,
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'deleted_at' => $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateOutputSetting(array $row): array
    {
        return [
            'supplier_id' => (int) $row['supplier_id'],
            'usage' => (string) ($row['usage'] ?? 'pdf'),
            'output_type' => (string) $row['output_type'],
            'enabled' => (int) $row['enabled'] === 1,
            'backend' => (string) $row['backend'],
            'selection_source' => (string) $row['selection_source'],
            'user_profile_fallback' => (string) $row['user_profile_fallback'],
            'default_profile_id' => $row['default_profile_id'] !== null ? (int) $row['default_profile_id'] : null,
            'failure_policy' => (string) $row['failure_policy'],
            'signature_config' => $this->decodeJsonObject($row['signature_config_json'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateUserProfileDefault(array $row): array
    {
        return [
            'supplier_id' => (int) $row['supplier_id'],
            'usage' => (string) $row['usage'],
            'output_type' => (string) $row['output_type'],
            'user_id' => (int) $row['user_id'],
            'profile_id' => (int) $row['profile_id'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateDocumentOverride(array $row): array
    {
        return [
            'supplier_id' => (int) $row['supplier_id'],
            'usage' => (string) $row['usage'],
            'entity_type' => (string) $row['entity_type'],
            'entity_id' => (int) $row['entity_id'],
            'selection_source' => (string) $row['selection_source'],
            'admin_profile_id' => $row['admin_profile_id'] !== null ? (int) $row['admin_profile_id'] : null,
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<mixed> $list
     * @param list<string> $allowed
     * @return list<string>
     */
    private function normalizeList(array $list, array $allowed, string $field): array
    {
        $out = [];
        foreach ($list as $value) {
            $value = $this->oneOf((string) $value, $allowed, $field);
            if (!in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        if ($out === []) {
            throw new \InvalidArgumentException($field . ' nesmí být prázdné.');
        }

        return $out;
    }

    /**
     * @param list<string> $allowed
     */
    private function oneOf(string $value, array $allowed, string $field): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException($field . ' má nepodporovanou hodnotu.');
        }

        return $value;
    }

    private function nonEmpty(string $value, string $field, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException($field . ' má neplatnou délku.');
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private function nullableString($value, string $field, int $maxLength): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException($field . ' má neplatnou délku.');
        }

        return $value;
    }

    private function code(string $value): string
    {
        $value = $this->nonEmpty($value, 'code', 80);
        if (preg_match('/^[a-zA-Z0-9_.-]+$/', $value) !== 1) {
            throw new \InvalidArgumentException('code obsahuje nepovolené znaky.');
        }

        return $value;
    }

    /**
     * @param mixed $data
     */
    private function encodeJson($data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param mixed $json
     * @return list<string>
     */
    private function decodeJsonList($json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => is_scalar($value) ? (string) $value : '',
            $data,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param mixed $json
     * @return array<string,mixed>
     */
    private function decodeJsonObject($json): array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        return is_array($data) ? $data : [];
    }
}
