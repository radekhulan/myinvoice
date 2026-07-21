<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Branding;

use MyInvoice\Service\Branding\BrandingProfileOverlay;
use PHPUnit\Framework\TestCase;

final class BrandingProfileOverlayTest extends TestCase
{
    public function testOnlyNonEmptyIdentityFieldsOverrideSupplier(): void
    {
        $supplier = ['display_name' => 'Original', 'email' => 'original@example.test', 'phone' => '123'];
        $profile = [
            'id' => 7, 'display_name' => 'Brand', 'tagline' => null, 'email' => '',
            'reply_to' => 'reply@example.test', 'phone' => null, 'web' => null,
            'email_footer' => 'Footer', 'logo_path' => 'storage/branding/logo.png',
            'email_profile_id' => 3, 'branding_enabled' => 1,
            'accent_color' => '#123ABC', 'pdf_logo_show_name' => 0,
        ];

        $result = BrandingProfileOverlay::apply($supplier, $profile);

        self::assertSame('Brand', $result['display_name']);
        self::assertSame('original@example.test', $result['email']);
        self::assertSame('123', $result['phone']);
        self::assertSame(7, $result['branding_profile_id']);
        self::assertSame(3, $result['email_profile_id']);
        self::assertSame('#123ABC', $result['email_accent_color']);
        self::assertFalse($result['pdf_logo_show_name']);
    }
}
