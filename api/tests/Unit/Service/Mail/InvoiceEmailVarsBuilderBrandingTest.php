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

    public function testEnabledBrandingProfilesLoadSelectedProfileWithoutProfileSnapshot(): void
    {
        $pdo = $this->brandingDatabase();
        $pdo->exec(
            "INSERT INTO supplier
                (id, branding_profiles_enabled, email_branding_enabled, email_accent_color, logo_path)
             VALUES
                (1, 1, 0, '#AAAAAA', NULL)"
        );
        $pdo->exec(
            "INSERT INTO branding_profiles
                (id, supplier_id, display_name, tagline, email, reply_to, phone, web, email_footer,
                 logo_path, accent_color, branding_enabled, pdf_logo_show_name, email_profile_id, is_active)
             VALUES
                (7, 1, 'Profilová značka', 'Profilový slogan', 'brand@example.test',
                 'reply@example.test', '+420 111 222 333', 'https://brand.example.test',
                 'Profilová patička', 'storage/supplier-logos/sup-1-brand-7-abcdef123456.png',
                 '#654321', 1, 0, 9, 1)"
        );

        $vars = $this->builder($pdo)->buildReminder([
            'supplier_id' => 1,
            'branding_profile_id' => 7,
            'supplier_snapshot' => $this->supplierSnapshot(),
            'invoice_type' => 'invoice',
            'varsymbol' => '',
            'status' => 'draft',
            'total_with_vat' => 1210,
        ], 1, 'cs');

        self::assertSame(7, $vars['supplier']['branding_profile_id']);
        self::assertSame('Profilová značka', $vars['supplier']['display_name']);
        self::assertSame('Profilová patička', $vars['supplier']['email_footer']);
        self::assertSame('storage/supplier-logos/sup-1-brand-7-abcdef123456.png', $vars['supplier']['logo_path']);
        self::assertSame('#654321', $vars['supplier']['email_accent_color']);
        self::assertSame(9, $vars['supplier']['email_profile_id']);
        self::assertSame('reply@example.test', $vars['supplier']['reply_to']);
    }

    public function testIssuedInvoiceKeepsBrandingProfileFromSnapshot(): void
    {
        $pdo = $this->brandingDatabase();
        $pdo->exec(
            "INSERT INTO supplier
                (id, branding_profiles_enabled, email_branding_enabled, email_accent_color, logo_path)
             VALUES
                (1, 1, 0, '#AAAAAA', NULL)"
        );

        $snapshot = array_merge(
            json_decode($this->supplierSnapshot(), true, flags: JSON_THROW_ON_ERROR),
            [
            'branding_profile_id' => 7,
            'display_name' => 'Zmrazená značka',
            'email_footer' => 'Zmrazená patička',
            'logo_path' => 'storage/supplier-logos/sup-1-brand-7-abcdef123456.png',
            'email_branding_enabled' => true,
            'email_accent_color' => '#654321',
            'email_profile_id' => 9,
            ],
        );

        $vars = $this->builder($pdo)->buildReminder([
            'supplier_id' => 1,
            'branding_profile_id' => 7,
            'supplier_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'invoice_type' => 'invoice',
            'varsymbol' => '',
            'status' => 'issued',
            'total_with_vat' => 1210,
        ], 1, 'cs');

        self::assertSame(7, $vars['supplier']['branding_profile_id']);
        self::assertSame('Zmrazená značka', $vars['supplier']['display_name']);
        self::assertSame('Zmrazená patička', $vars['supplier']['email_footer']);
        self::assertSame('storage/supplier-logos/sup-1-brand-7-abcdef123456.png', $vars['supplier']['logo_path']);
        self::assertSame('#654321', $vars['supplier']['email_accent_color']);
        self::assertSame(9, $vars['supplier']['email_profile_id']);
    }

    private function brandingDatabase(): PDO
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
            'CREATE TABLE branding_profiles (
                id INTEGER PRIMARY KEY,
                supplier_id INTEGER NOT NULL,
                display_name TEXT NULL,
                tagline TEXT NULL,
                email TEXT NULL,
                reply_to TEXT NULL,
                phone TEXT NULL,
                web TEXT NULL,
                email_footer TEXT NULL,
                logo_path TEXT NULL,
                accent_color TEXT NULL,
                branding_enabled INTEGER NOT NULL,
                pdf_logo_show_name INTEGER NOT NULL,
                email_profile_id INTEGER NULL,
                is_active INTEGER NOT NULL
            )'
        );
        return $pdo;
    }

    private function builder(PDO $pdo): InvoiceEmailVarsBuilder
    {
        $connection = new Connection($this->createStub(Config::class));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $pdo);

        return new InvoiceEmailVarsBuilder(
            $connection,
            (new \ReflectionClass(QrPaymentGenerator::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(InvoicePublicLinkService::class))->newInstanceWithoutConstructor(),
        );
    }

    private function supplierSnapshot(): string
    {
        return json_encode([
            'id' => 1,
            'company_name' => 'Testovací dodavatel s.r.o.',
            'display_name' => 'Testovací dodavatel',
            'street' => 'Testovací 1',
            'city' => 'Praha',
            'zip' => '100 00',
            'country_name_cs' => 'Česko',
            'email' => 'supplier@example.test',
        ], JSON_THROW_ON_ERROR);
    }
}
