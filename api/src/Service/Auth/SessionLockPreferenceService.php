<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
use PDO;

final class SessionLockPreferenceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SessionLockPolicy $policy,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly PasskeyService $passkeys,
    ) {}

    /**
     * @return array{
     *   user_lock_after_minutes:?int,
     *   admin_lock_after_minutes:int,
     *   maximum_lock_after_minutes:int,
     *   effective_lock_after_minutes:int
     * }
     */
    public function get(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT session_lock_after_minutes
               FROM users
              WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \DomainException('Uživatel pro nastavení zámku neexistuje.');
        }

        return $this->describe(self::nullableTimeout($row['session_lock_after_minutes'] ?? null));
    }

    /**
     * @return array{
     *   user_lock_after_minutes:?int,
     *   admin_lock_after_minutes:int,
     *   maximum_lock_after_minutes:int,
     *   effective_lock_after_minutes:int
     * }
     */
    public function update(int $userId, ?int $timeoutMinutes): array
    {
        $this->policy->assertUserTimeoutAllowed($timeoutMinutes);
        // Odemknout jde jen passkey. Vlastní zámek bez použitelné passkey by
        // vyrobil session, kterou lze ukončit pouze odhlášením — stejná podmínka
        // jako u ručního zámku, jen na straně preference.
        if ($timeoutMinutes !== null
            && (!$this->passkeys->isAvailable()
                || $this->credentials->countActiveForUser($userId) === 0)
        ) {
            throw new \InvalidArgumentException(
                'Vlastní automatický zámek vyžaduje alespoň jednu aktivní passkey.',
            );
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users
                SET session_lock_after_minutes = ?
              WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([$timeoutMinutes, $userId]);

        return $this->get($userId);
    }

    /**
     * @return array{
     *   user_lock_after_minutes:?int,
     *   admin_lock_after_minutes:int,
     *   maximum_lock_after_minutes:int,
     *   effective_lock_after_minutes:int
     * }
     */
    private function describe(?int $userTimeoutMinutes): array
    {
        return [
            'user_lock_after_minutes' => $userTimeoutMinutes,
            'admin_lock_after_minutes' => $this->policy->timeoutMinutes(),
            'maximum_lock_after_minutes' => $this->policy->maximumUserTimeoutMinutes(),
            'effective_lock_after_minutes' => $this->policy->effectiveTimeoutMinutes(
                $userTimeoutMinutes,
            ),
        ];
    }

    private static function nullableTimeout(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
