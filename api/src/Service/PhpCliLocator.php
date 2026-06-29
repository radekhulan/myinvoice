<?php

declare(strict_types=1);

namespace MyInvoice\Service;

/**
 * Najde CLI `php` binárku pro spouštění detached workerů (import, cron).
 *
 * Pod IIS / FastCGI je `PHP_BINARY` typicky `php-cgi.exe`, a pod **php-fpm**
 * (oficiální Docker image) je `PHP_BINARY` přímo binárka `php-fpm` — obojí CLI
 * skripty (`if (PHP_SAPI !== 'cli') exit;`) spustí špatně. php-fpm navíc CLI
 * argumenty/skript nepochopí, vypíše usage a skončí, takže worker se nikdy
 * nespustí (job zůstane navždy „queued"). Tento helper takové ne-CLI SAPI
 * binárky přeskočí a vrátí skutečnou cestu k CLI php (sibling, běžné cesty, …).
 */
final class PhpCliLocator
{
    /**
     * @return string|null Cesta / příkaz CLI php, nebo null pokud nic nenalezeno.
     */
    public static function resolve(): ?string
    {
        $candidates = [];
        $b = PHP_BINARY;
        if ($b !== '') {
            $candidates[] = $b;
            $dir = dirname($b);
            $candidates[] = $dir . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = 'C:\\inetpub\\php\\php.exe';
            $candidates[] = 'C:\\Program Files\\PHP\\php.exe';
            $candidates[] = 'php.exe';
        } else {
            $candidates[] = '/usr/bin/php';
            $candidates[] = '/usr/local/bin/php';
            $candidates[] = 'php';
        }

        foreach ($candidates as $c) {
            // Vyhneme se ne-CLI SAPI binárkám (php-cgi, php-win, phpdbg, php-fpm) — chceme jen CLI.
            if (self::isNonCliSapiName(basename($c))) {
                continue;
            }
            if (str_contains($c, DIRECTORY_SEPARATOR) || str_contains($c, '/')) {
                if (is_file($c)) {
                    return $c;
                }
            } else {
                // Holý název → necháme PATH lookup na OS.
                return $c;
            }
        }

        return null;
    }

    /**
     * Je `$name` (basename binárky) ne-CLI SAPI, kterou nelze použít pro spuštění
     * CLI workeru? Pokrývá php-cgi / php-win / phpdbg a — klíčové pod php-fpm —
     * `php-fpm` (tam je `PHP_BINARY` právě tato binárka).
     */
    public static function isNonCliSapiName(string $name): bool
    {
        $name = strtolower($name);

        return $name === 'php-cgi.exe' || $name === 'php-cgi'
            || $name === 'php-win.exe'
            || str_starts_with($name, 'phpdbg')
            || str_starts_with($name, 'php-fpm');
    }
}
