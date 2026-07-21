<?php

declare(strict_types=1);

namespace MyInvoice\Service\Branding;

final class BrandingProfileOverlay
{
    /** @param array<string,mixed> $supplier @param array<string,mixed> $profile @return array<string,mixed> */
    public static function apply(array $supplier, array $profile): array
    {
        foreach (['display_name', 'tagline', 'email', 'phone', 'web', 'email_footer', 'logo_path'] as $field) {
            if (($profile[$field] ?? null) !== null && $profile[$field] !== '') {
                $supplier[$field] = $profile[$field];
            }
        }
        $supplier['branding_profile_id'] = (int) $profile['id'];
        $supplier['reply_to'] = $profile['reply_to'] ?: null;
        $supplier['email_profile_id'] = $profile['email_profile_id'] !== null ? (int) $profile['email_profile_id'] : null;
        $supplier['email_branding_enabled'] = (bool) ($profile['branding_enabled'] ?? true);
        $supplier['email_accent_color'] = (string) ($profile['accent_color'] ?: '#3B2D83');
        $supplier['pdf_logo_show_name'] = (bool) ($profile['pdf_logo_show_name'] ?? true);
        return $supplier;
    }
}
