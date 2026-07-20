<?php

declare(strict_types=1);

namespace MyInvoice\Service\Branding;

final class BrandingProfileValidation
{
    /** @return array<string,list<string>> */
    public static function validate(array $data, bool $partial = false): array
    {
        $errors = [];
        if (!$partial || array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') $errors['name'][] = 'Název profilu je povinný';
            if (mb_strlen($name) > 100) $errors['name'][] = 'Název profilu má nejvýše 100 znaků';
        }
        foreach (['email', 'reply_to'] as $field) {
            if (!array_key_exists($field, $data)) continue;
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field][] = 'E-mail musí mít platný tvar';
            }
        }
        if (array_key_exists('accent_color', $data)) {
            $color = trim((string) ($data['accent_color'] ?? ''));
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $errors['accent_color'][] = 'Barva musí mít formát #RRGGBB';
            }
        }
        foreach (['display_name' => 190, 'tagline' => 255, 'phone' => 40, 'web' => 255] as $field => $max) {
            if (array_key_exists($field, $data) && mb_strlen(trim((string) ($data[$field] ?? ''))) > $max) {
                $errors[$field][] = "Pole má nejvýše {$max} znaků";
            }
        }
        return $errors;
    }
}
