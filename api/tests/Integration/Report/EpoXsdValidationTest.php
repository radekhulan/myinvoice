<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\IncomeTaxBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\SouhrnneHlaseniBuilder;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Integrace test: vygenerované EPO XML (DPH/KH/SH/DPFO/DPPO) MUSÍ projít XSD
 * validation MFČR schémat (storage/xsd/*.xsd).
 *
 * **Soft skip** pokud schema chybí v storage/xsd/ (production deploy nemusí mít).
 * Lokálně + CI po `bash cmd/download-xsd.sh` test fakticky validuje.
 *
 * Používá vlastního syntetického dodavatele, takže výsledek nezávisí na datech
 * konkrétní vývojové instalace. Test je **Integration** (DB-touching), ne Unit.
 */
final class EpoXsdValidationTest extends TestCase
{
    private XmlSchemaValidator $validator;
    private ?\MyInvoice\Infrastructure\Database\Connection $conn = null;
    private int $supplierId = 0;

    /** @var array<string, callable(): array{xml: string, summary: array, warnings: array}> */
    private array $builders = [];

    protected function tearDown(): void
    {
        if ($this->conn !== null && $this->supplierId > 0) {
            $this->conn->pdo()->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
        // Uvolni MySQL connection (per-metodu container by jinak kumuloval connections
        // přes celý běh → MariaDB max_connections).
        $this->conn?->close();
    }

    protected function setUp(): void
    {
        // Soft-skip: test je Integration (vyžaduje DB s reálnými fakturami + cfg.php
        // pro DB connection). V CI runneru (GitHub Actions) cfg.php neexistuje (je
        // gitignored), takže skipujeme — Bootstrap::buildApp() by jinak fatalně padl.
        // Defenzivně skipujeme i pokud chybí XSD adresář (někdo smazal commitnutá schémata).
        // Oba checky MUSÍ proběhnout PŘED Bootstrap::buildApp(), protože jinak fatal.
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        $xsdDir = $rootDir . '/api/xsd';
        if (!is_dir($xsdDir) || count(glob($xsdDir . '/*.xsd') ?: []) === 0) {
            $this->markTestSkipped('Žádné XSD v api/xsd/ — chybí commitnutá schémata MFČR.');
        }

        $container = Bootstrap::buildApp()->getContainer();
        $this->validator = $container->get(XmlSchemaValidator::class);
        $this->conn = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
        $this->supplierId = $this->createSyntheticSupplier();

        $year = (int) date('Y');
        $month = (int) date('n');

        // Lazy builders — každý test si volá svůj
        $this->builders = [
            'dphdp3' => fn () => $container->get(DphPriznaniBuilder::class)
                ->build($this->supplierId, $year, $month, 'monthly'),
            'dphkh1' => fn () => $container->get(KontrolniHlaseniBuilder::class)
                ->build($this->supplierId, $year, $month),
            'dphshv' => fn () => $container->get(SouhrnneHlaseniBuilder::class)
                ->build($this->supplierId, $year, $month),
            'dpfdp5' => fn () => $container->get(IncomeTaxBuilder::class)
                ->build($this->supplierId, $year - 1, 'fo'),
            'dppdp9' => fn () => $container->get(IncomeTaxBuilder::class)
                ->build($this->supplierId, $year - 1, 'po'),
        ];
    }

    public function testDphdp3PassesXsdValidation(): void
    {
        $this->assertBuilderPassesXsd('dphdp3');
    }

    public function testDphkh1PassesXsdValidation(): void
    {
        $this->assertBuilderPassesXsd('dphkh1');
    }

    public function testDphshvPassesXsdValidation(): void
    {
        $this->assertBuilderPassesXsd('dphshv');
    }

    /**
     * DPFO/DPPO jsou MVP foundation — výkaz není kompletní. Test jen že XML
     * obsahuje validní strukturu (Pisemnost + DPFDP5/DPPDP9 root), ne plnou XSD
     * validaci (záměrně neúplný výkaz).
     */
    public function testDpfdp5ProducesValidXmlStructure(): void
    {
        if (!$this->validator->hasSchema('dpfdp5')) {
            $this->markTestSkipped('XSD schema dpfdp5.xsd není v storage/xsd/ — spusť `bash cmd/download-xsd.sh dpfdp5`.');
        }
        $result = ($this->builders['dpfdp5'])();
        $this->assertNotEmpty($result['xml']);
        $this->assertStringContainsString('<DPFDP5', $result['xml']);
        $this->assertStringContainsString('<Pisemnost', $result['xml']);
    }

    public function testDppdp9ProducesValidXmlStructure(): void
    {
        if (!$this->validator->hasSchema('dppdp9')) {
            $this->markTestSkipped('XSD schema dppdp9.xsd není v storage/xsd/.');
        }
        $result = ($this->builders['dppdp9'])();
        $this->assertStringContainsString('<DPPDP9', $result['xml']);
        $this->assertStringContainsString('<Pisemnost', $result['xml']);
    }

    /**
     * VetaP coverage — kontroluje, že vygenerovaný DPH + KH XML obsahuje
     * všechny atributy, které posílá reálné EPO podání (sledováno proti
     * tomu co MFČR XSD povoluje a co lidé reálně vyplňují v UI).
     *
     * Tento test chrání proti regresi typu "přidal jsem nový sloupec do supplier
     * ale zapomněl jsem ho zapsat do builderu" nebo opačně.
     */
    public function testDphdp3VetaPContainsAllSupplierFields(): void
    {
        $this->assertVetaPHasExpectedAttributes('dphdp3', 'DPHDP3');
    }

    public function testDphkh1VetaPContainsAllSupplierFields(): void
    {
        $this->assertVetaPHasExpectedAttributes('dphkh1', 'DPHKH1');
    }

    private function assertVetaPHasExpectedAttributes(string $formCode, string $rootElement): void
    {
        $result = ($this->builders[$formCode])();
        $this->assertNotEmpty($result['xml'], "Builder {$formCode} vrátil prázdné XML");

        $xml = new \SimpleXMLElement($result['xml']);
        $root = $xml->{$rootElement};
        $this->assertNotNull($root, "{$rootElement} root element chybí");
        $vetaP = $root->VetaP;
        $this->assertNotNull($vetaP, 'VetaP element chybí');

        $attrs = [];
        foreach ($vetaP->attributes() as $k => $v) $attrs[(string) $k] = (string) $v;

        // Regrese „pole je v DB, ale nepřenese se do XML": atribut se v XML vyžaduje
        // JEN když má supplier odpovídající sloupec vyplněný. FO bez sestavitele
        // legitimně nemá opr_*/sest_*, OSVČ nemá zkrobchjm — proto podmíněně (jinak by
        // test padal na neúplných, ale validních profilech).
        $stmt = $this->conn?->pdo()->prepare(
            'SELECT financial_office_code, workplace_code, dic, taxpayer_type, company_name,
                    street_number_pop, street_number_orient, email, phone, cz_nace_code,
                    opr_jmeno, opr_prijmeni, opr_postaveni, sest_jmeno, sest_prijmeni, sest_telefon
               FROM supplier WHERE id = ?'
        );
        $stmt?->execute([$this->supplierId]);
        $sup = $stmt?->fetch(\PDO::FETCH_ASSOC) ?: [];

        // Vždy povinné (struktura každého validního plátce).
        $expected = ['c_ufo', 'dic', 'typ_ds', 'ulice', 'naz_obce', 'psc', 'stat'];
        // Podmíněné: XML atribut → supplier sloupec (vyžadováno jen když je sloupec vyplněn).
        $conditional = [
            'c_pracufo'     => 'workplace_code',
            'c_pop'         => 'street_number_pop',
            'c_orient'      => 'street_number_orient',
            'email'         => 'email',
            'c_telef'       => 'phone',
            'opr_jmeno'     => 'opr_jmeno',
            'opr_prijmeni'  => 'opr_prijmeni',
            'opr_postaveni' => 'opr_postaveni',
            'sest_jmeno'    => 'sest_jmeno',
            'sest_prijmeni' => 'sest_prijmeni',  // vlastní sloupec (fallback split sest_jmeno když prázdné)
            'sest_telef'    => 'sest_telefon',
        ];
        foreach ($conditional as $attr => $col) {
            if (!empty($sup[$col])) $expected[] = $attr;
        }
        // zkrobchjm jen pro PO (FO má místo toho jmeno/prijmeni).
        if (($sup['taxpayer_type'] ?? '') === 'po' && !empty($sup['company_name'])) {
            $expected[] = 'zkrobchjm';
        }

        $missing = [];
        foreach ($expected as $attr) {
            if (!array_key_exists($attr, $attrs) || $attrs[$attr] === '') {
                $missing[] = $attr;
            }
        }
        $this->assertEmpty(
            $missing,
            "{$formCode} VetaP chybí atributy (pole vyplněné v supplier, ale chybí v XML): "
                . implode(', ', $missing),
        );

        // Ulice nesmí obsahovat trailing číslo, když máme c_pop/c_orient zvlášť
        if (isset($attrs['c_pop']) || isset($attrs['c_orient'])) {
            $this->assertDoesNotMatchRegularExpression(
                '/\d+(?:\/\d+)?$/u',
                $attrs['ulice'],
                "ulice obsahuje číslo i když je c_pop/c_orient vyplněno zvlášť — duplicita",
            );
        }

        // c_telef MUSÍ být bez `+420` prefixu a bez mezer (EPO konvence — 9 digits).
        if (isset($attrs['c_telef']) && $attrs['c_telef'] !== '') {
            $this->assertDoesNotMatchRegularExpression(
                '/[\s+]/',
                $attrs['c_telef'],
                'c_telef obsahuje mezery nebo + prefix — normalizace neproběhla',
            );
        }

        // c_okec v VetaD je jen u DPH (KH XSD ho nemá v povolených atributech) — a jen
        // pokud supplier má vyplněný cz_nace_code (jinak se atribut legitimně nevyplní).
        $vetaD = $root->VetaD;
        if ($formCode === 'dphdp3' && !empty($sup['cz_nace_code'])) {
            $this->assertNotEmpty(
                (string) $vetaD['c_okec'],
                'VetaD.c_okec chybí — supplier.cz_nace_code se nepřenáší do XML',
            );
        }

        // d_poddp (datum podání) — vždy dnes, sdíleno DPH i KH
        $this->assertSame(
            date('d.m.Y'),
            (string) $vetaD['d_poddp'],
            'VetaD.d_poddp není dnešní datum',
        );
    }

    private function assertBuilderPassesXsd(string $formCode): void
    {
        if (!$this->validator->hasSchema($formCode)) {
            $this->markTestSkipped(
                "XSD schema {$formCode}.xsd není v storage/xsd/. Stáhni přes `bash cmd/download-xsd.sh {$formCode}`."
            );
        }
        $result = ($this->builders[$formCode])();
        $this->assertNotEmpty($result['xml'], "Builder {$formCode} vrátil prázdné XML");

        $validation = $this->validator->validate($result['xml'], $formCode);
        $this->assertSame(
            'passed',
            $validation['status'],
            "XSD validation pro {$formCode} selhala s chybami:\n  - " . implode("\n  - ", $validation['errors']),
        );
        $this->assertEmpty($validation['errors'], "XSD errors v {$formCode}: " . print_r($validation['errors'], true));
    }

    private function createSyntheticSupplier(): int
    {
        $pdo = $this->conn?->pdo() ?? throw new \RuntimeException('DB connection není inicializované.');
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query("SELECT id FROM vat_rates WHERE country = 'CZ' ORDER BY is_default DESC, id LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní CZ číselníky pro syntetického EPO dodavatele.');
        }

        $pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, ic, dic, is_vat_payer, email, phone,
                 default_currency_id, default_vat_rate_id, taxpayer_type, financial_office_code,
                 workplace_code, street_number_pop, street_number_orient)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'TEST EPO s.r.o.', 'Testovací', 'Praha', '11000', $countryId,
            '00000019', 'CZ12345678', 'epo-test@example.invalid', '123456789',
            $currencyId, $vatRateId, 'po', '451', '2001', '42', '7',
        ]);
        return (int) $pdo->lastInsertId();
    }
}
