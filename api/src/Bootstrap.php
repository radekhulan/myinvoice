<?php

declare(strict_types=1);

namespace MyInvoice;

use DI\ContainerBuilder;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\CsrfMiddleware;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Middleware\IpAllowlistMiddleware;
use MyInvoice\Middleware\RateLimitMiddleware;
use MyInvoice\Middleware\RequireTotpMiddleware;
use MyInvoice\Middleware\RoleMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\IpMatcher;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

final class Bootstrap
{
    public static function rootDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function buildApp(): App
    {
        $rootDir = self::rootDir();
        $config  = Config::load($rootDir);

        // Bezpečnostní guard: v produkci pepper musí být nastavený (jinak hesla nemají druhotnou ochranu)
        $env    = (string) $config->get('app.env', 'production');
        $pepper = (string) $config->get('app.pepper', '');
        if ($env === 'production' && $pepper === '') {
            throw new \RuntimeException('cfg.app.pepper není nastaven (vygeneruj: openssl rand -base64 32). V produkci je povinný.');
        }

        date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Prague'));

        // PHP error log → log/php-errors.log (jinak by warnings/notices padaly do
        // system php_errors.log, který je mimo repo). Display_errors v dev=on, prod=off.
        // Pokud je nastaven MYINVOICE_DATA_DIR, ukládáme i tento log do data_dir
        // (drží všechen state pod jediným perzistentním volume).
        $logDir = ($config->dataDir() ?? $rootDir) . '/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        ini_set('log_errors', '1');
        ini_set('error_log', $logDir . '/php-errors.log');
        // NIKDY display_errors=on pro API endpoints — JSON response by byla kontaminována
        // deprecation/notice warningy (typicky vendor 3rd-party kód). Logujeme do souboru.
        // Dev env: warnings se objeví v log/php-errors.log + log/app-YYYY-MM-DD.log.
        ini_set('display_errors', '0');
        // Reporting: E_ALL minus E_DEPRECATED (PHP 8.5 deprecates older patterns ve vendoru,
        // které nemůžeme fixnout — nechceme je v error log spamovat).
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $builder = new ContainerBuilder();
        $builder->useAttributes(false);
        $builder->addDefinitions([
            Config::class => $config,

            LoggerInterface::class => function (ContainerInterface $c) use ($config): LoggerInterface {
                $logger = new Logger('myinvoice');
                $path   = (string) $config->get('logging.path');
                $level  = self::resolveLogLevel((string) $config->get('logging.level', 'info'));
                $maxFiles = (int) $config->get('logging.max_files', 90);
                if (!is_dir(dirname($path))) {
                    @mkdir(dirname($path), 0755, true);
                }
                $logger->pushHandler(new RotatingFileHandler($path, $maxFiles, $level));
                return $logger;
            },

            ResponseFactory::class => fn () => new ResponseFactory(),
            Connection::class      => fn (ContainerInterface $c) => new Connection($c->get(Config::class), $c->get(LoggerInterface::class)),
            RedisProbe::class      => fn (ContainerInterface $c) => new RedisProbe($c->get(Config::class)),
            RedisFactory::class    => fn (ContainerInterface $c) => new RedisFactory($c->get(Config::class)),
            \MyInvoice\Service\Signing\SigningPassphraseProviderInterface::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Signing\SigningPassphraseProvider(
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
            ),
            \MyInvoice\Service\Signing\Pdf\PdfSigningService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Signing\Pdf\PdfSigningService(
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Service\Signing\Pdf\NativePdfSignatureBackend::class),
                $c->get(\MyInvoice\Repository\SigningProfileRepository::class),
                $c->get(\MyInvoice\Service\Signing\SigningPassphraseProviderInterface::class),
            ),
            \MyInvoice\Service\Mail\Mailer::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Mail\Mailer(
                $c->get(Config::class),
                $c->get(LoggerInterface::class),
                $c->get(Connection::class),
                $c->get(\MyInvoice\Repository\EmailTemplateRepository::class),
                $c->get(\MyInvoice\Service\Signing\Email\EmailSigningService::class),
            ),

            // IpMatcher má v konstruktoru volitelný `?Config $config = null`. Autowiring
            // takový parametr neresolvuje (dosadí default null), takže clientIpFromRequest()
            // by ignorovalo cfg.ip_allowlist.trusted_proxies a vždy vracelo REMOTE_ADDR.
            // Za reverse proxy → audit log a brute-force lockout vidí IP proxy místo
            // reálného klienta. Explicitní injekce Configu to opravuje.
            IpMatcher::class       => fn (ContainerInterface $c) => new IpMatcher($c->get(Config::class)),
        ]);

        $container = $builder->build();
        AppFactory::setContainer($container);

        $app = AppFactory::create();

        Routes::register($app);

        // Slim 4 LIFO: poslední `add()` = NEJVĚTŠÍ vrstva = běží JAKO PRVNÍ.
        // Cílový order běhu (outside → inside):
        //   IpAllowlist → FirstRunLock → Auth → RequireTotp → Role → SupplierScope → ApiScope → RateLimit → CSRF → Routing → BodyParsing → Action
        // → add() v opačném pořadí (innermost první):
        $app->addBodyParsingMiddleware();                            // innermost
        $app->addRoutingMiddleware();
        $app->add($container->get(CsrfMiddleware::class));           // potřebuje session z Auth (bearer skip)
        $app->add($container->get(RateLimitMiddleware::class));      // chrání forgot/setup/login/ARES + per-user/per-token limity
        $app->add($container->get(ApiScopeMiddleware::class));       // bearer-only: enforce read / read_write scope
        $app->add($container->get(SupplierScopeMiddleware::class));  // multi-supplier scope (X-Supplier-Id / token's supplier_id)
        $app->add($container->get(RoleMiddleware::class));           // RBAC — kontrola role po Auth
        $app->add($container->get(RequireTotpMiddleware::class));    // vynucení 2FA pokud cfg.auth.require_totp=true (bearer skip)
        $app->add($container->get(AuthMiddleware::class));           // načte session nebo bearer token
        $app->add($container->get(FirstRunLockMiddleware::class));   // 423 pokud users prázdná
        $app->add($container->get(IpAllowlistMiddleware::class));    // outermost user mw
        $app->add(new ApiVersionRewriteMiddleware());                // /api/v1/* → /api/* před vším ostatním

        $displayErrors = (bool) $config->get('app.debug', false);
        $app->addErrorMiddleware($displayErrors, true, true, $container->get(LoggerInterface::class));

        return $app;
    }

    private static function resolveLogLevel(string $level): \Monolog\Level
    {
        return match (strtolower($level)) {
            'debug'   => \Monolog\Level::Debug,
            'info'    => \Monolog\Level::Info,
            'notice'  => \Monolog\Level::Notice,
            'warning' => \Monolog\Level::Warning,
            'error'   => \Monolog\Level::Error,
            default   => \Monolog\Level::Info,
        };
    }
}
