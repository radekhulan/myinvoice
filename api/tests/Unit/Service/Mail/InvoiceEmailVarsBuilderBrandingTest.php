<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use PDO;
use PHPUnit\Framework\TestCase;

final class InvoiceEmailVarsBuilderBrandingTest extends TestCase
{
    public function testDisabledBrandingProfilesKeepLegacySupplierBrandingForIssuedInvoice(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE supplier (
                id INTEGER PRIMARY KEY,
                branding_profiles_enabled INTEGER NOT NULL,
                email_branding_enabled INTEGER NOT NULL,
                email_accent_color TEXT NULL,
                logo_path TEXT NULL
            )'
        );
        $pdo->exec(
            "INSERT INTO supplier
                (id, branding_profiles_enabled, email_branding_enabled, email_accent_color, logo_path)
             VALUES
                (1, 0, 1, '#123456', 'storage/supplier-logos/sup-1.png')"
        );

        $connection = new Connection($this->createStub(Config::class));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $pdo);

        $builder = new InvoiceEmailVarsBuilder(
            $connection,
            (new \ReflectionClass(QrPaymentGenerator::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(InvoicePublicLinkService::class))->newInstanceWithoutConstructor(),
        );

        $vars = $builder->buildReminder([
            'supplier_id' => 1,
            'supplier_snapshot' => json_encode([
                'id' => 1,
                'company_name' => 'Testovací dodavatel s.r.o.',
                'display_name' => 'Testovací dodavatel',
                'street' => 'Testovací 1',
                'city' => 'Praha',
                'zip' => '100 00',
                'country_name_cs' => 'Česko',
                'email' => 'supplier@example.test',
            ], JSON_THROW_ON_ERROR),
            'invoice_type' => 'invoice',
            'varsymbol' => '',
            'status' => 'issued',
            'total_with_vat' => 1210,
        ], 1, 'cs');

        self::assertSame('Testovací dodavatel', $vars['supplier']['display_name']);
        self::assertTrue($vars['supplier']['email_branding_enabled']);
        self::assertSame('#123456', $vars['supplier']['email_accent_color']);
        self::assertSame('storage/supplier-logos/sup-1.png', $vars['supplier']['logo_path']);
    }
}
