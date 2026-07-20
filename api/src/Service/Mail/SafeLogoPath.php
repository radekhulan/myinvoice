<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Validuje + resolvuje `supplier.logo_path` na bezpečnou absolutní cestu.
 *
 * Defense-in-depth proti LFI přes podstrčený `logo_path` (security report
 * @andrejtomci #2, CVE-class CWE-915 + CWE-22). Hlavní obrana je odebrání
 * `logo_path` z mass-assignment whitelistu v `SettingsAction`, ale read
 * sinks (preview HTML + Mailer embed) mají vlastní validaci pro případ,
 * že by se hodnota dostala do DB jinou cestou (historická data, manuální
 * SQL, jiný service).
 *
 * Povolené tvary (SupplierLogoConverter::process pište jen tyhle):
 *   storage/supplier-logos/sup-{N}.png       — pro PNG po konverzi
 *   storage/supplier-logos/sup-{N}.svg       — případné SVG (zatím nepoužíváno)
 *
 * Vrací **absolutní cestu** pokud:
 *   - prefix odpovídá očekávanému dir
 *   - basename match `sup-{supplierId}.{ext}` (žádný traversal)
 *   - extension je v allowlistu
 *   - realpath() neutekl mimo RuntimePaths::storage('supplier-logos')
 *   - soubor reálně existuje
 *
 * Vrací null v ostatních případech (caller má fallback chování).
 */
final class SafeLogoPath
{
    private const ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
    private const SAFE_DIR    = 'storage/supplier-logos';

    public static function resolve(?string $logoPath, int $supplierId): ?string
    {
        if ($logoPath === null || $logoPath === '' || $supplierId <= 0) return null;
        $logoPath = (string) $logoPath;

        // Null bytes + traversal patterns → reject výslovně.
        if (strpos($logoPath, "\0") !== false || strpos($logoPath, '..') !== false) return null;

        // Musí začínat očekávaným prefixem (relativně k rootDir, bez leading /)
        $rel = ltrim($logoPath, '/');
        $expectedPrefix = self::SAFE_DIR . '/';
        if (!str_starts_with($rel, $expectedPrefix)) return null;

        // Extension allowlist
        $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) return null;

        // Basename validace — žádné víc-úrovňové cesty
        $basename = basename($rel);
        $quotedExt = preg_quote($ext, '/');
        if (!preg_match('/^sup-' . $supplierId . '(?:-brand-[1-9][0-9]*-[a-f0-9]{12})?\.' . $quotedExt . '$/', $basename)) {
            return null;
        }

        $rootDir = \MyInvoice\Infrastructure\Config\RuntimePaths::base();
        $abs = $rootDir . '/' . $rel;

        // realpath rejection (sym-link follow, real check that file is inside SAFE_DIR)
        $real = @realpath($abs);
        if ($real === false) return null;
        $safeBase = @realpath($rootDir . '/' . self::SAFE_DIR);
        if ($safeBase === false || !str_starts_with($real, $safeBase . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (!is_file($real)) return null;

        return $real;
    }
}
