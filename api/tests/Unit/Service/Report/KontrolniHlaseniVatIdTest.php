<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Normalizace EU VAT ID a kódu státu pro KH oddíl A.2 (issue #164).
 *
 * vatid_dod je alfanumerické (na rozdíl od českého číselného DIČ) — musí zachovat
 * písmena a odříznout jen prefix země; k_stat respektuje DPH kód EU (Řecko EL ≠ ISO GR).
 */
final class KontrolniHlaseniVatIdTest extends TestCase
{
    /** @return list<array{0:?string,1:?string,2:string}> */
    public static function vatIdProvider(): array
    {
        return [
            // [raw VAT ID, country ISO2, expected vatid_dod]
            'IE s písmeny (issue #164)'      => ['IE3668997OH', 'IE', '3668997OH'],
            'IE bez prefixu'                 => ['3668997OH',   'IE', '3668997OH'],
            'AT začíná U'                    => ['ATU12345678', 'AT', 'U12345678'],
            'DE jen číslice'                 => ['DE123456789', 'DE', '123456789'],
            'NL alfanumerické'               => ['NL123456789B01', 'NL', '123456789B01'],
            'Řecko ISO GR, prefix EL'        => ['EL123456789', 'GR', '123456789'],
            'mezery a oddělovače'            => ['IE 366 8997 OH', 'IE', '3668997OH'],
            '3. země bez VAT ID'             => [null, 'US', ''],
            'prázdné'                        => ['', 'IE', ''],
            'bez země neořezává'             => ['IE3668997OH', null, 'IE3668997OH'],
        ];
    }

    #[DataProvider('vatIdProvider')]
    public function testCleanEuVatId(?string $raw, ?string $country, string $expected): void
    {
        $this->assertSame($expected, KontrolniHlaseniBuilder::cleanEuVatId($raw, $country));
    }

    public function testKhCountryCodeMapsGreece(): void
    {
        $this->assertSame('EL', KontrolniHlaseniBuilder::khCountryCode('GR'));
        $this->assertSame('EL', KontrolniHlaseniBuilder::khCountryCode('gr'));
        $this->assertSame('IE', KontrolniHlaseniBuilder::khCountryCode('ie'));
        $this->assertSame('', KontrolniHlaseniBuilder::khCountryCode(null));
    }

    /**
     * Identifikace dodavatele v A.2 se řídí EXISTENCÍ DIČ registrace k DPH v členském
     * státě, ne sídlem dodavatele — a když chybí, vrací se PRÁZDNÁ identifikace, ne
     * `null`. Řádek se z A.2 nevyřazuje: metodika GFŘ i dokumentace `vatid_dod`
     * v dphkh1.xsd shodně říkají, že u dodavatele bez VAT ID pole „Identifikace
     * dodavatele“ zůstává prázdné a EPO na to reaguje jen propustnými chybami
     * č. 58 / č. 60 (issue #53).
     *
     * @return array<string, array{0:?string,1:bool,2:?string,3:array{k_stat:string,vatid_dod:string}}>
     */
    public static function a2IdentificationProvider(): array
    {
        $none = ['k_stat' => '', 'vatid_dod' => ''];

        return [
            // [country ISO2, is EU, raw VAT ID, expected identification]
            'EU plátce'                 => ['IE', true,  'IE3668997OH', ['k_stat' => 'IE', 'vatid_dod' => '3668997OH']],
            'Řecko → k_stat EL'         => ['GR', true,  'EL123456789', ['k_stat' => 'EL', 'vatid_dod' => '123456789']],
            // Sídlo mimo EU, ale registrace v členském státě (US firma s irským DIČ):
            // k_stat se bere z prefixu VAT ID, protože `k_stat` = stát REGISTRACE.
            '3. země s EU registrací'   => ['US', false, 'IE3668997OH', ['k_stat' => 'IE', 'vatid_dod' => '3668997OH']],
            // Číslo, které není EU VAT ID: OSS non-union (EU372041333), US federal ID,
            // britské DIČ po Brexitu. Do `vatid_dod` nepatří → prázdná identifikace.
            '3. země s OSS číslem'      => ['US', false, 'EU372041333', $none],
            '3. země s vlastním číslem' => ['US', false, 'US12-3456789', $none],
            'GB po Brexitu'             => ['GB', false, 'GB123456789', $none],
            '3. země bez VAT ID'        => ['US', false, null, $none],
            'EU neplátce (bez VAT ID)'  => ['DE', true,  null, $none],
            'EU s prázdným VAT ID'      => ['DE', true,  '   ', $none],
            'EU s VAT ID jen prefix'    => ['DE', true,  'DE', $none],
            'neznámá země, EU VAT ID'   => [null, true,  'DE123456789', ['k_stat' => 'DE', 'vatid_dod' => '123456789']],
        ];
    }

    /** @param array{k_stat:string,vatid_dod:string} $expected */
    #[DataProvider('a2IdentificationProvider')]
    public function testA2Identification(?string $iso2, bool $isEu, ?string $vatId, array $expected): void
    {
        $this->assertSame($expected, KontrolniHlaseniBuilder::a2Identification($iso2, $isEu, $vatId));
    }

    public function testCleanDicStaysNumericForCzech(): void
    {
        // Regrese: české DIČ pořád jen číslice (oddíly A.1/A.4/B.1/B.2).
        $this->assertSame('12345678', KontrolniHlaseniBuilder::cleanDic('CZ12345678'));
        $this->assertSame('12345678', KontrolniHlaseniBuilder::cleanDic('CZ 1234 5678'));
    }
}
