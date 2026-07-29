<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Guard serverového PDF override: musí zůstat mimo UI/API, v runtime datech
 * a s fallbackem na vestavěnou šablonu.
 */
final class InvoiceCustomTemplateOverrideTest extends TestCase
{
    public function testOverrideIsRuntimeOnlyAndHasSafeFallback(): void
    {
        $root = dirname(__DIR__, 3);
        $renderer = file_get_contents($root . '/api/src/Service/Pdf/InvoicePdfRenderer.php');
        self::assertIsString($renderer);

        self::assertStringContainsString(
            "RuntimePaths::storage(self::CUSTOM_TEMPLATE)",
            $renderer,
        );
        self::assertStringContainsString(
            "private const CUSTOM_TEMPLATE = 'templates/invoice/invoice-custom.twig';",
            $renderer,
        );
        self::assertStringContainsString(
            "return \$twig->render('invoice.twig', \$vars);",
            $renderer,
        );
        self::assertStringContainsString(
            'catch (\\Twig\\Error\\Error $e)',
            $renderer,
        );
        self::assertStringContainsString(
            '$this->templateOverrideMtime()',
            $renderer,
        );
    }

    public function testNoCustomTemplateUploadOrEditorIsExposed(): void
    {
        $root = dirname(__DIR__, 3);
        $routes = file_get_contents($root . '/api/src/Routes.php');
        $openApi = file_get_contents($root . '/api/openapi.yaml');
        $settingsUi = file_get_contents($root . '/web/src/components/settings/BrandingProfilesSettings.vue');

        self::assertIsString($routes);
        self::assertIsString($openApi);
        self::assertIsString($settingsUi);
        self::assertStringNotContainsString('invoice-custom.twig', $routes);
        self::assertStringNotContainsString('invoice-custom.twig', $openApi);
        self::assertStringNotContainsString('invoice-custom.twig', $settingsUi);
    }
}
