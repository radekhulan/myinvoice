<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Signing;

use MyInvoice\Service\Signing\SigningProfileAccess;
use PHPUnit\Framework\TestCase;

final class SigningProfileAccessTest extends TestCase
{
    public function testAdminCanManageAllProfiles(): void
    {
        $access = new SigningProfileAccess();

        self::assertTrue($access->canCreate('admin', false));
        self::assertTrue($access->canManage('admin', 10, null, false));
        self::assertTrue($access->canManage('admin', 10, 99, false));
        self::assertTrue($access->canManageSupplierDefaults('admin'));
    }

    public function testAccountantCanOnlyManageOwnProfilesWhenEnabled(): void
    {
        $access = new SigningProfileAccess();

        self::assertTrue($access->canCreate('accountant', true));
        self::assertTrue($access->canManage('accountant', 10, 10, true));
        self::assertFalse($access->canManage('accountant', 10, 11, true));
        self::assertFalse($access->canManage('accountant', 10, null, true));
        self::assertFalse($access->canManage('accountant', 10, 10, false));
        self::assertFalse($access->canManageSupplierDefaults('accountant'));
    }

    public function testReadonlyCannotMutateProfiles(): void
    {
        $access = new SigningProfileAccess();

        self::assertFalse($access->canCreate('readonly', true));
        self::assertFalse($access->canManage('readonly', 10, 10, true));
        self::assertFalse($access->canManageSupplierDefaults('readonly'));
    }
}
