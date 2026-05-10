<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Config;

/**
 * Konfigurace aplikace — načítá `cfg.php` z rootu repa,
 * volitelně mergne `cfg.local.php` přes `array_replace_recursive`,
 * a nakonec aplikuje environment overrides (12-factor).
 *
 * Přístup přes dot notation: $cfg->get('db.host'), $cfg->get('smtp.from_email').
 *
 * Environment overrides: pokud je nastavena ENV proměnná z mapy v
 * `envOverrideMap()`, přepíše hodnotu z cfg.php. To umožňuje běh v rootless
 * kontejnerových PaaS (Railway, Heroku, Fly.io) bez bind-mount cfg.php.
 *
 * V kontejnerovém deploymentu stačí dodat soubor cfg.php se základní
 * strukturou (může být celý prázdný `<?php return [];`) a všechny citlivé
 * údaje předat přes ENV. Lokální dev / VPS nasazení funguje beze změny
 * (cfg.php má přednost před chybějícími ENV).
 */
final class Config
{
    private const UNRESOLVED_ENV_REFERENCE_PATTERN = '/^\$\{[A-Za-z_][A-Za-z0-9_]*\}$/';

    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(string $rootDir): self
    {
        $basePath  = $rootDir . DIRECTORY_SEPARATOR . 'cfg.php';
        $localPath = $rootDir . DIRECTORY_SEPARATOR . 'cfg.local.php';

        if (!is_file($basePath)) {
            throw new \RuntimeException("cfg.php nenalezen v {$rootDir}");
        }

        $base  = require $basePath;
        $local = is_file($localPath) ? require $localPath : [];

        if (!is_array($base) || !is_array($local)) {
            throw new \RuntimeException('cfg.php (a cfg.local.php) musí vracet pole');
        }

        $merged = array_replace_recursive($base, $local);
        $merged = self::applyEnvOverrides($merged);

        return new self($merged);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $value    = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function all(): array
    {
        return $this->data;
    }

    /**
     * Mapa ENV proměnných na cfg klíče (dot notation) + cast typ.
     * Pokud ENV není nastavena (getenv vrátí false), cfg hodnota se nemění.
     *
     * Konvence: prefix MYINVOICE_ pro app-specific. Sekundárně podporujeme
     * i běžné názvy (DATABASE_URL, REDIS_URL, PORT) z PaaS platforem.
     *
     * @return array<string,array{0:string,1:string}> ENV name => [cfg path, type]
     */
    private static function envOverrideMap(): array
    {
        return [
            // App
            'MYINVOICE_APP_ENV'     => ['app.env', 'string'],
            'MYINVOICE_APP_DEBUG'   => ['app.debug', 'bool'],
            'MYINVOICE_APP_URL'     => ['app.url', 'string'],
            'MYINVOICE_PEPPER'      => ['app.pepper', 'string'],
            'MYINVOICE_SECRET_KEY'  => ['app.secret_encryption_key', 'string'],
            'MYINVOICE_TIMEZONE'    => ['app.timezone', 'string'],
            'MYINVOICE_LOCALE'      => ['app.locale_default', 'string'],

            // Database (jednotlivé klíče i kompozitní DATABASE_URL)
            'MYINVOICE_DB_HOST'    => ['db.host', 'string'],
            'MYINVOICE_DB_PORT'    => ['db.port', 'int'],
            'MYINVOICE_DB_NAME'    => ['db.name', 'string'],
            'MYINVOICE_DB_USER'    => ['db.user', 'string'],
            'MYINVOICE_DB_PASS'    => ['db.pass', 'string'],
            'MYINVOICE_DB_SOCKET'  => ['db.socket', 'string'],

            // Mainstream PaaS aliasy (Railway, Heroku, Fly.io)
            'MYSQL_HOST'     => ['db.host', 'string'],
            'MYSQL_PORT'     => ['db.port', 'int'],
            'MYSQL_DATABASE' => ['db.name', 'string'],
            'MYSQL_USER'     => ['db.user', 'string'],
            'MYSQL_PASSWORD' => ['db.pass', 'string'],

            // Redis
            'MYINVOICE_REDIS_ENABLED' => ['redis.enabled', 'bool'],
            'MYINVOICE_REDIS_HOST'    => ['redis.host', 'string'],
            'MYINVOICE_REDIS_PORT'    => ['redis.port', 'int'],
            'MYINVOICE_REDIS_AUTH'    => ['redis.auth', 'string'],
            'MYINVOICE_REDIS_DB'      => ['redis.db', 'int'],
            'MYINVOICE_REDIS_PREFIX'  => ['redis.prefix', 'string'],
            'REDIS_HOST'              => ['redis.host', 'string'],
            'REDIS_PORT'              => ['redis.port', 'int'],
            'REDIS_PASSWORD'          => ['redis.auth', 'string'],

            // Session
            'MYINVOICE_SESSION_DRIVER'       => ['session.driver', 'string'],
            'MYINVOICE_SESSION_COOKIE_SECURE'=> ['session.cookie_secure', 'bool'],
            'MYINVOICE_SESSION_SAMESITE'     => ['session.cookie_samesite', 'string'],

            // SMTP
            'MYINVOICE_SMTP_HOST'       => ['smtp.host', 'string'],
            'MYINVOICE_SMTP_PORT'       => ['smtp.port', 'int'],
            'MYINVOICE_SMTP_ENCRYPTION' => ['smtp.encryption', 'string'],
            'MYINVOICE_SMTP_AUTH'       => ['smtp.auth_enabled', 'bool'],
            'MYINVOICE_SMTP_USER'       => ['smtp.user', 'string'],
            'MYINVOICE_SMTP_PASS'       => ['smtp.pass', 'string'],
            'MYINVOICE_SMTP_FROM_EMAIL' => ['smtp.from_email', 'string'],
            'MYINVOICE_SMTP_FROM_NAME'  => ['smtp.from_name', 'string'],

            // Captcha (Cloudflare Turnstile)
            'MYINVOICE_TURNSTILE_SITE_KEY'   => ['captcha.site_key', 'string'],
            'MYINVOICE_TURNSTILE_SECRET_KEY' => ['captcha.secret_key', 'string'],

            // Logging
            'MYINVOICE_LOG_LEVEL' => ['logging.level', 'string'],
        ];
    }

    private static function applyEnvOverrides(array $data): array
    {
        // 1) Strukturovaný DATABASE_URL (mysql://user:pass@host:port/db)
        $dbUrl = getenv('DATABASE_URL') ?: getenv('MYSQL_URL');
        if (is_string($dbUrl) && $dbUrl !== '' && !self::isUnresolvedEnvReference($dbUrl)) {
            $parts = parse_url($dbUrl);
            if (is_array($parts)) {
                if (isset($parts['host']))     { $data['db']['host'] = $parts['host']; }
                if (isset($parts['port']))     { $data['db']['port'] = (int) $parts['port']; }
                if (isset($parts['user']))     { $data['db']['user'] = urldecode($parts['user']); }
                if (isset($parts['pass']))     { $data['db']['pass'] = urldecode($parts['pass']); }
                if (isset($parts['path']))     { $data['db']['name'] = ltrim($parts['path'], '/'); }
            }
        }

        // 2) Strukturovaný REDIS_URL (redis://[:pass]@host:port/db)
        $redisUrl = getenv('REDIS_URL');
        if (is_string($redisUrl) && $redisUrl !== '' && !self::isUnresolvedEnvReference($redisUrl)) {
            $parts = parse_url($redisUrl);
            if (is_array($parts)) {
                if (isset($parts['host'])) { $data['redis']['host'] = $parts['host']; }
                if (isset($parts['port'])) { $data['redis']['port'] = (int) $parts['port']; }
                if (isset($parts['pass'])) { $data['redis']['auth'] = urldecode($parts['pass']); }
                if (isset($parts['path'])) {
                    $db = ltrim($parts['path'], '/');
                    if ($db !== '') { $data['redis']['db'] = (int) $db; }
                }
                $data['redis']['enabled'] = true;
            }
        }

        // 3) Per-key ENV overrides
        foreach (self::envOverrideMap() as $envName => [$path, $type]) {
            $raw = getenv($envName);
            if ($raw === false) {
                continue;
            }
            if (self::isUnresolvedEnvReference($raw)) {
                continue;
            }
            $value = self::castEnv($raw, $type);
            $data  = self::setByPath($data, $path, $value);
        }

        return $data;
    }

    private static function isUnresolvedEnvReference(string $raw): bool
    {
        return preg_match(self::UNRESOLVED_ENV_REFERENCE_PATTERN, trim($raw)) === 1;
    }

    private static function castEnv(string $raw, string $type): mixed
    {
        return match ($type) {
            'bool'   => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $raw,
            'int'    => (int) $raw,
            'float'  => (float) $raw,
            default  => $raw,
        };
    }

    private static function setByPath(array $data, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $ref      = &$data;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                break;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        return $data;
    }
}
