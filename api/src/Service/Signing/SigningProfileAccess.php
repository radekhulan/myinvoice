<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing;

/**
 * RBAC pravidla pro obecné podpisové profily.
 *
 * Admin spravuje supplier/admin profily. Accountant smí spravovat jen vlastní
 * profily a jen pokud to admin v signing_settings povolil. Readonly nemutuje.
 */
final class SigningProfileAccess
{
    public function canCreate(string $role, bool $accountantProfilesEnabled): bool
    {
        if ($role === 'admin') {
            return true;
        }

        return $role === 'accountant' && $accountantProfilesEnabled;
    }

    public function canManage(
        string $role,
        int $currentUserId,
        ?int $ownerUserId,
        bool $accountantProfilesEnabled,
    ): bool {
        if ($role === 'admin') {
            return true;
        }

        return $role === 'accountant'
            && $accountantProfilesEnabled
            && $ownerUserId !== null
            && $ownerUserId === $currentUserId;
    }

    public function canManageSupplierDefaults(string $role): bool
    {
        return $role === 'admin';
    }
}
