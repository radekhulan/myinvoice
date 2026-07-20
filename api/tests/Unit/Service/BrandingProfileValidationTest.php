<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service;

use MyInvoice\Service\Branding\BrandingProfileValidation;
use PHPUnit\Framework\TestCase;

final class BrandingProfileValidationTest extends TestCase
{
    public function testMinimalProfileIsValid(): void
    {
        self::assertSame([], BrandingProfileValidation::validate(['name' => 'Projekt A']));
    }

    public function testNameIsRequiredOnCreate(): void
    {
        self::assertArrayHasKey('name', BrandingProfileValidation::validate([]));
    }

    public function testNameMayBeOmittedOnPartialUpdate(): void
    {
        self::assertSame([], BrandingProfileValidation::validate(['tagline' => 'Nový slogan'], true));
    }

    public function testInvalidEmailsAreRejected(): void
    {
        $errors = BrandingProfileValidation::validate([
            'name' => 'Projekt A',
            'email' => 'neplatny-email',
            'reply_to' => 'také špatně',
        ]);
        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('reply_to', $errors);
    }

    public function testOptionalEmailsMayBeEmpty(): void
    {
        self::assertSame([], BrandingProfileValidation::validate([
            'name' => 'Projekt A',
            'email' => '',
            'reply_to' => null,
        ]));
    }

    public function testAccentColorMustBeFullHex(): void
    {
        self::assertArrayHasKey('accent_color', BrandingProfileValidation::validate([
            'name' => 'Projekt A',
            'accent_color' => '#123',
        ]));
        self::assertSame([], BrandingProfileValidation::validate([
            'name' => 'Projekt A',
            'accent_color' => '#12abEF',
        ]));
    }
}
