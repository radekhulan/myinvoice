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
 *
 * `MYINVOICE_DATA_DIR` (volitelná ENV): pokud je nastavená, **všechny**
 * stateful adresáře (log/, storage/{invoices,uploads,backup,sessions,cache},
 * private/dkim/) se přesunou pod tuto cestu. Cílem je mít v Dockeru jediný
 * persistentní volume (např. /data) a zbytek kontejneru jako read-only —
 * eliminuje potřebu symlinkovat /storage, /private, /log, cfg.php zvlášť.
 * Navíc se z `${MYINVOICE_DATA_DIR}/cfg.local.php` (pokud existuje) načte
 * per-instance override, takže uživatelská konfigurace přežije image update.
 */
final class Config
{
    private const UNRESOLVED_ENV_REFERENCE_PATTERN = '/^\$\{[A-Za-z_][A-Za-z0-9_]*\}$/';

    private array $data;
    private ?string $dataDir;

    public function __construct(array $data, ?string $dataDir = null)
    {
        $this->data    = $data;
        $this->dataDir = $dataDir;
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

        // Baseline defaults pro non-secret veřejné konstanty (ARES/VIES URLs,
        // timeouty, cache TTL, ...). Cíl: image-baked stub `<?php return [];`
        // musí dát funkční ARES/VIES lookup bez nutnosti bind-mountu cfg.docker.php
        // ani ENV overrides. Reportováno v issue #30 — uživatel nasadil přes
        // GHCR image bez cfg mountu a ARES vracela cURL error 3 "no host"
        // protože $config->get('ares.api') byl prázdný string.
        // Pořadí: defaults → cfg.php → cfg.local.php → DATA_DIR cfg.local → ENV.
        $merged = array_replace_recursive(self::baselineDefaults(), $base, $local);

        // Volitelně merge cfg.local.php z DATA_DIR (pokud je set), aby uživatel
        // mohl držet kompletní per-instance override mimo image.
        $dataDir = self::resolveDataDir();
        if ($dataDir !== null) {
            $dataLocalPath = $dataDir . DIRECTORY_SEPARATOR . 'cfg.local.php';
            if (is_file($dataLocalPath)) {
                $dataLocal = require $dataLocalPath;
                if (!is_array($dataLocal)) {
                    throw new \RuntimeException('cfg.local.php v MYINVOICE_DATA_DIR musí vracet pole');
                }
                $merged = array_replace_recursive($merged, $dataLocal);
            }
        }

        $merged = self::applyEnvOverrides($merged);

        // DATA_DIR má přednost před per-key path konfigurací — sjednocení všech
        // stateful adresářů (log/, storage/, private/) pod jediný volume.
        if ($dataDir !== null) {
            $merged = self::applyDataDirOverrides($merged, $dataDir);
        }

        // Poslední krok: relativní cesty v cfg ukotvi k rootu aplikace.
        // Pod cron/Task Scheduler je CWD procesu jinde (System32, $HOME, …),
        // takže např. 'output_dir' => 'storage/backup' by vyrobilo adresář
        // mimo aplikaci. Po applyDataDirOverrides je to pro přepsané klíče
        // no-op (data-dir cesty jsou absolutní).
        $merged = self::anchorRelativePaths($merged, $rootDir);

        return new self($merged, $dataDir);
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
     * Vrací absolutní cestu z `MYINVOICE_DATA_DIR`, pokud je ENV nastavená.
     * Když je nastavená, všechny stateful adresáře žijí pod touto cestou.
     */
    public function dataDir(): ?string
    {
        return $this->dataDir;
    }

    /**
     * Načte hodnotu MYINVOICE_DATA_DIR z prostředí. Vrací normalizovanou
     * absolutní cestu (bez závěrečného oddělovače) nebo null pokud není set.
     *
     * Public static, aby ji mohly volat konzumenti, kteří si Config neumí
     * snadno vstříknout (např. `VersionService` má jen `Connection`).
     */
    public static function resolveDataDir(): ?string
    {
        $raw = getenv('MYINVOICE_DATA_DIR');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $path = rtrim(trim($raw), "/\\");
        return $path === '' ? null : $path;
    }

    /**
     * Sjednotí všechny stateful cesty pod `${dataDir}` — pokud uživatel
     * nastaví MYINVOICE_DATA_DIR, nemusí řešit jednotlivé volume mounty
     * pro log/, storage/ a private/.
     *
     * Mapa: cfg klíč (dot notation) => relativní podcesta uvnitř data_dir.
     */
    private static function applyDataDirOverrides(array $data, string $dataDir): array
    {
        $sep = DIRECTORY_SEPARATOR;
        $map = [
            'logging.path'                => 'log' . $sep . 'app.log',
            'storage.invoices_dir'        => 'storage' . $sep . 'invoices',
            'storage.uploads_dir'         => 'storage' . $sep . 'uploads',
            'storage.backup_dir'          => 'storage' . $sep . 'backup',
            'storage.sessions_dir'        => 'storage' . $sep . 'sessions',
            'storage.cache_dir'           => 'storage' . $sep . 'cache',
            'cron.backup.output_dir'      => 'storage' . $sep . 'backup',
            // #115: archiv PDF přijatých faktur — cfg.sample.php má `__DIR__ . '/storage/...'`,
            // což v Dockeru míří do vrstvy kontejneru (NE do /data volume) → soubory
            // zmizely při každém image updatu. Musí žít pod data dir jako ostatní storage.
            'purchase_invoice.archive_storage' => 'storage' . $sep . 'purchase-invoices',
            'invoice.import_archive_storage'   => 'storage' . $sep . 'invoices-imported',
            'smtp.dkim.private_key_path'  => 'private' . $sep . 'dkim' . $sep . 'myinvoice.pem',
            'smtp.dkim.public_key_path'   => 'private' . $sep . 'dkim' . $sep . 'myinvoice.pub',
            'smtp.dkim.dns_doc_path'      => 'private' . $sep . 'dkim' . $sep . 'dns.txt',
        ];

        foreach ($map as $path => $rel) {
            $data = self::setByPath($data, $path, $dataDir . $sep . $rel);
        }

        return $data;
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
    /**
     * Baseline defaults aplikované **před** cfg.php — jen pro non-secret veřejné
     * konstanty a statické registry služeb (URLs třetích stran, timeouty,
     * TTL cache, výchozí parser class names). Vše, co je opravdu
     * tajné nebo per-instance (DB credentials, pepper, SMTP host, ...), tady
     * NESMÍ být — to musí přijít z cfg.php / ENV.
     *
     * Smysl: image-baked stub `<?php return [];` musí být plně funkční pro
     * základní use case (ARES/VIES lookup), aby ENV-only deploy fungoval bez
     * dalšího setupu. Per issue #30 (cURL error 3 "no host part" z prázdné ARES URL).
     *
     * @return array<string, mixed>
     */
    private static function baselineDefaults(): array
    {
        return [
            'ares' => [
                'api'       => 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty',
                'cache_ttl' => 86400,
                'timeout'   => 5,
            ],
            'vies' => [
                'rest_api'  => 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms',
                'wsdl'      => 'http://ec.europa.eu/taxation_customs/vies/services/checkVatService.wsdl',
                'cache_ttl' => 10800,
                'timeout'   => 8,
            ],
            // Registr plátců DPH (CRPDPH/MFČR) — zveřejněné bankovní účty + nespolehlivý plátce.
            'crpdph' => [
                'endpoint'  => 'https://adisrws.mfcr.cz/adistc/axis2/services/rozhraniCRPDPH.rozhraniCRPDPHSOAP',
                'cache_ttl' => 86400,
                'timeout'   => 8,
            ],
            'bank_email' => [
                'notice_parsers' => [
                    'regex' => \MyInvoice\Service\Bank\EmailNotice\Parser\RegexBankEmailNoticeParser::class,
                    'raiffeisenbank' => \MyInvoice\Service\Bank\EmailNotice\Parser\RaiffeisenbankEmailNoticeParser::class,
                    'unicredit' => \MyInvoice\Service\Bank\EmailNotice\Parser\UnicreditBankEmailNoticeParser::class,
                    'csob' => \MyInvoice\Service\Bank\EmailNotice\Parser\CsobBankEmailNoticeParser::class,
                    'fio' => \MyInvoice\Service\Bank\EmailNotice\Parser\FioBankEmailNoticeParser::class,
                    'creditas' => \MyInvoice\Service\Bank\EmailNotice\Parser\CreditasBankEmailNoticeParser::class,
                    'moneta' => \MyInvoice\Service\Bank\EmailNotice\Parser\MonetaBankEmailNoticeParser::class,
                ],
            ],
        ];
    }

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
            // Cookie name přes ENV kvůli full-ENV deployům (Portainer/Dockge/PaaS):
            // přes plain HTTP musí být ne-`__Host-` jméno (`__Host-` vyžaduje Secure),
            // jinak se přihlašovací cookie neuloží. Default je `__Host-myinvoice_session`.
            'MYINVOICE_SESSION_COOKIE_NAME'  => ['session.cookie_name', 'string'],
            'MYINVOICE_SESSION_SAMESITE'     => ['session.cookie_samesite', 'string'],

            // Auth
            'MYINVOICE_AUTH_REQUIRE_TOTP'    => ['auth.require_totp', 'bool'],

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

            // ARES / VIES (veřejné registry — URLs mají baselineDefaults(),
            // ENV jen pro override v izolovaných prostředích nebo na staging
            // mirror endpoint)
            'MYINVOICE_ARES_API'       => ['ares.api', 'string'],
            'MYINVOICE_ARES_TIMEOUT'   => ['ares.timeout', 'int'],
            'MYINVOICE_VIES_REST_API'  => ['vies.rest_api', 'string'],
            'MYINVOICE_VIES_TIMEOUT'   => ['vies.timeout', 'int'],
            'MYINVOICE_CRPDPH_ENDPOINT' => ['crpdph.endpoint', 'string'],
            'MYINVOICE_CRPDPH_TIMEOUT'  => ['crpdph.timeout', 'int'],

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
            // Nepřítomná i prázdná ENV se ignoruje. Docker Compose při mapovém
            // zápisu `KEY: ${VAR}` dosadí prázdný řetězec i když VAR v .env chybí
            // — bez tohoto guardu by takový prázdný override přebil hodnotu
            // z cfg.php (např. SMTP z bind-mountnutého cfg.docker.php → smtp.port=0).
            if ($raw === false || $raw === '') {
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

    /**
     * Cfg klíče (dot notation) nesoucí cestu na filesystem. Pokud uživatel
     * zadá relativní hodnotu (např. 'storage/backup'), ukotví se k rootu
     * aplikace — jinak by se resolvovala proti CWD procesu, které je pod
     * cron/Task Scheduler jinde a soubory by končily mimo aplikaci (reálně
     * se stalo s cron.backup.output_dir z cfg.sample.php).
     *
     * Superset mapy v applyDataDirOverrides() + purchase_invoice.inbox_dir
     * (ta pod data dir nepatří — je to uživatelův vstupní adresář).
     * Záměrně NEobsahuje db.dump_tool: jméno binárky bez cesty se hledá v PATH.
     */
    private const PATH_KEYS = [
        'logging.path',
        'storage.invoices_dir',
        'storage.uploads_dir',
        'storage.backup_dir',
        'storage.sessions_dir',
        'storage.cache_dir',
        'cron.backup.output_dir',
        'purchase_invoice.archive_storage',
        'purchase_invoice.inbox_dir',
        'invoice.import_archive_storage',
        'smtp.dkim.private_key_path',
        'smtp.dkim.public_key_path',
        'smtp.dkim.dns_doc_path',
    ];

    private static function anchorRelativePaths(array $data, string $rootDir): array
    {
        $root = rtrim($rootDir, '/\\');
        foreach (self::PATH_KEYS as $key) {
            $value = self::getByPath($data, $key);
            if (!is_string($value) || $value === '' || self::isAbsolutePath($value)) {
                continue;
            }
            $data = self::setByPath($data, $key, $root . DIRECTORY_SEPARATOR . $value);
        }
        return $data;
    }

    /** Windows drive (C:\ i C:/), UNC (\\server\share) i POSIX (/var) tvar. */
    private static function isAbsolutePath(string $path): bool
    {
        return (bool) preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path);
    }

    private static function getByPath(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
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
