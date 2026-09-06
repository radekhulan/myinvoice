<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Pdf\RaiffeisenbankStatementPdfParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Parser PDF výpisu Raiffeisenbank. Testuje se přes seamy parseHeaderFromText()/
 * parseTransactionsFromText() na VYMYŠLENÉM textu (žádná reálná zákaznická data dle
 * pravidla feedback_test_data_local_only — účty, jména, VS i částky jsou syntetické).
 *
 * RB má „vertikální" layout (každé pole na vlastním řádku), kotva transakce = dvojice
 * dat (zaúčtování + valuta), a ODLIŠNÝ číselný formát: TEČKA desetinná, MEZERA tisíce.
 */
final class RaiffeisenbankStatementPdfParserTest extends TestCase
{
    private function parser(): RaiffeisenbankStatementPdfParser
    {
        return new RaiffeisenbankStatementPdfParser(new NullLogger());
    }

    /** Obalí syntetické řádky transakcí do plné struktury výpisu (hlavička + patička). */
    private function statement(string $prev, string $curr, string $credit, string $debit, string $rows): string
    {
        return "Výpis z běžného účtu č. 1234567890 CZK\n"
            . "Pořadové č. výpisu:  za období: 6 1. 6. 2026 - 30. 6. 2026\n"
            . "Testovací Firma s.r.o.\n"
            . "Raiffeisenbank a.s., Hvězdova 1716/2b, 140 78 Praha 4, IČO 49240901.\n"
            . "Přehled\n"
            . "Číslo účtu:\t1234567890/5500 CZK\n"
            . "Název účtu:\tTestovací Firma s.r.o.\n"
            . "IBAN:\tCZ0055000000001234567890\n"
            . "BIC:\tRZBCCZPP\n"
            . "Počáteční zůstatek:\t{$prev}\n"
            . "Příjmy celkem:\t{$credit}\n"
            . "Výdaje celkem:\t{$debit}\n"
            . "Konečný zůstatek:\t{$curr}\n"
            . "Pohledávky po splatnosti:\t0.00\n"
            . "Poplatky celkem:\t0.00\n"
            . "Výpis pohybů\n"
            . "Datum Kategorie transakce Typ transakce\tVS\tPoplatek Částka\n"
            . "Valuta Číslo protiúčtu Zpráva\tKS\tPůvodní částka\n"
            . "Kód transakceNázev protiúčtu Poznámka\tSS\tKurz\n"
            . $rows
            . "Zpráva pro klienta\n"
            . "Vklad na tomto účtu podléhá ochraně, kterou poskytuje systém pojištění pohledávek z vkladů.\n";
    }

    public function testParsesHeaderWithDotDecimalAndSpaceThousands(): void
    {
        $header = $this->parser()->parseHeaderFromText(
            $this->statement('1 234 567.89', '1 240 000.00', '9 999.99', '1 234.56', '')
        );

        self::assertSame('1234567890', $header['account_number']);
        self::assertSame('2026-06-30', $header['statement_date']);
        self::assertSame('6', $header['statement_number']);
        self::assertSame('CZK', $header['currency']);
        self::assertSame(1234567.89, $header['prev_balance']);
        self::assertSame(1240000.00, $header['curr_balance']);
        self::assertSame(9999.99, $header['credit_total']);
        self::assertSame(1234.56, $header['debit_total']);
    }

    public function testNegativeBalancesAndFeeBreakdownUseMainTransactionAmount(): void
    {
        $rows = "30. 6. 2026\n30. 6. 2026\n1000000005\n"
              . "Poplatek\tVedení účtu\n"
              . "KS:898\n"
              . "Souhrnná položka k účtu 1000000005.\n"
              . "898\t-49.00 CZK\n"
              . "V rámci souhrnné položky k účtu jsou zahrnuty poplatky za následující služby:\n"
              . "Přímé bankovnictví: 0.00 CZK\n"
              . "Vedení běžného účtu v hlavní měně: 49.00 CZK\n"
              . "Vedení spořicího účtu: 0.00 CZK\n";
        $text = str_replace(
            "Poplatky celkem:\t0.00",
            "Poplatky celkem:\t999.00",
            $this->statement('-100.00', '-149.00', '0.00', '49.00', $rows),
        );

        $result = $this->parser()->parse('%PDF-fake', $text);

        self::assertSame(-100.0, $result['header']['prev_balance']);
        self::assertSame(-149.0, $result['header']['curr_balance']);
        self::assertSame(49.0, $result['header']['debit_total']);
        self::assertCount(1, $result['transactions']);
        self::assertSame(-49.0, $result['transactions'][0]['amount']);
        self::assertStringContainsString(
            'V rámci souhrnné položky k účtu jsou zahrnuty poplatky',
            (string) $result['transactions'][0]['description'],
        );
    }

    public function testParsesIncomingPaymentWithCounterpartyName(): void
    {
        // Příchozí úhrada: Kategorie / účet / Název protiúčtu / Typ+částka (bez znaménka).
        $rows = "4. 6. 2026\n4. 6. 2026\n1000000001\n"
              . "Platba\n"
              . "9876543210/0800\n"
              . "Jan Novák\n"
              . "Příchozí úhrada\t2 000.00 CZK\n";
        $text = $this->statement('10 000.00', '12 000.00', '2 000.00', '0.00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame('2026-06-04', $rowsOut[0]['posted_at']);
        self::assertSame(2000.0, $rowsOut[0]['amount']);
        self::assertSame('9876543210', $rowsOut[0]['counterparty_account']);
        self::assertSame('0800', $rowsOut[0]['counterparty_bank']);
        self::assertSame('Jan Novák', $rowsOut[0]['counterparty_name']);
        self::assertSame('1000000001', $rowsOut[0]['bank_ref']);
    }

    public function testParsesOutgoingPaymentWithoutNameHasVsAndNoName(): void
    {
        // Odchozí „Jednorázová úhrada" bez Názvu protiúčtu → za účtem stojí Typ transakce
        // (nesmí se vzít jako název), VS je labelovaný i jako samostatný sloupec, debet „-".
        $rows = "10. 6. 2026\n10. 6. 2026\n1000000002\n"
              . "Platba\n"
              . "2000000002/5500\n"
              . "Jednorázová úhrada\n"
              . "VS:0000445566\n"
              . "0000445566\t-1 500.00 CZK\n";
        $text = $this->statement('10 000.00', '8 500.00', '0.00', '1 500.00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame(-1500.0, $rowsOut[0]['amount']);
        self::assertSame('2000000002', $rowsOut[0]['counterparty_account']);
        self::assertSame('5500', $rowsOut[0]['counterparty_bank']);
        self::assertSame('445566', $rowsOut[0]['variable_symbol']);
        self::assertNull($rowsOut[0]['counterparty_name']);
    }

    public function testParsesInterestTransactionWithoutAccount(): void
    {
        // Úrok: žádný protiúčet, KS labelovaný + samostatný sloupec, částka bez sufixu měny.
        $rows = "30. 6. 2026\n30. 6. 2026\n1000000003\n"
              . "Úrok\tKladný úrok\n"
              . "KS:308\n"
              . "Úrok 06/2026\n"
              . "308\n"
              . "250.00\n";
        $text = $this->statement('10 000.00', '10 250.00', '250.00', '0.00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame(250.0, $rowsOut[0]['amount']);
        self::assertNull($rowsOut[0]['counterparty_account']);
        self::assertSame('308', $rowsOut[0]['constant_symbol']);
        self::assertNull($rowsOut[0]['counterparty_name']);
        self::assertStringContainsString('Kladný úrok', (string) $rowsOut[0]['description']);
    }

    public function testNoteWithSlashNumberNotMistakenForAccount(): void
    {
        // Poznámka „04/2026" nesmí být rozpoznána jako číslo protiúčtu (kotveno na celý řádek).
        $rows = "30. 6. 2026\n30. 6. 2026\n1000000004\n"
              . "Úrok\tKladný úrok\n"
              . "Bonusový úrok 04/2026\n"
              . "100.00\n";
        $text = $this->statement('10 000.00', '10 100.00', '100.00', '0.00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertNull($rowsOut[0]['counterparty_account']);
        self::assertSame(100.0, $rowsOut[0]['amount']);
    }

    public function testForeignCardPaymentUsesCzkAmountNotRateOrOriginal(): void
    {
        // Kartová platba v cizí měně: pod CZK částkou je původní částka v USD a kurz
        // „CZK/USD". Účetní hodnota je jen CZK částka — původní částku ani kurz nezapočítat
        // (regrese #205: parser bral poslední .dd, tj. kurz 21.83, místo -132.29 CZK).
        $rows = "9. 6. 2026\n7. 6. 2026\n9076437020\n"
              . "Platba kartou\n"
              . "Anonymizováno\n"
              . "Platba kartou\n"
              . "KS:1178\n"
              . "PK: 516872XXXXXX7989\n"
              . "6,06 USD;OPENAI; SAN FRANCISCO; USA\n"
              . "1178\t-132.29 CZK\n"
              . "-6.06 USD\n"
              . "21.83 CZK/USD\n";
        $text = $this->statement('10 000.00', '9 867.71', '0.00', '132.29', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame(-132.29, $rowsOut[0]['amount']);
        self::assertSame('1178', $rowsOut[0]['constant_symbol']);
        // Kurz „21.83 CZK/USD" ani „-6.06 USD" se nesmí objevit v popisu; merchant ano.
        self::assertStringNotContainsString('CZK/USD', (string) $rowsOut[0]['description']);
        self::assertStringNotContainsString('21.83', (string) $rowsOut[0]['description']);
        self::assertStringContainsString('OPENAI', (string) $rowsOut[0]['description']);

        // A self-check projde (součet -132.29 = pohyb zůstatku).
        $result = $this->parser()->parse('%PDF-fake', $text);
        self::assertSame(-132.29, $result['transactions'][0]['amount']);
    }

    public function testForeignCardPaymentWithThreeDecimalRate(): void
    {
        // Kartová platba v cizí měně, kde kurz má TŘI desetinná místa („21.716 CZK/USD").
        // Detekce řádku kurzu nesmí být vázaná na 2 desetinná místa, jinak parser vezme
        // číselný prefix kurzu jako částku (regrese #205 follow-up: součet nesedí o kurz).
        $rows = "9. 5. 2026\n7. 5. 2026\n9076437021\n"
              . "Platba kartou\n"
              . "Anonymizováno\n"
              . "Platba kartou\n"
              . "KS:1178\n"
              . "PK: 516872XXXXXX7989\n"
              . "6,06 USD;OPENAI; SAN FRANCISCO; USA\n"
              . "1178\t-131.60 CZK\n"
              . "-6.06 USD\n"
              . "21.716 CZK/USD\n";
        $text = $this->statement('10 000.00', '9 868.40', '0.00', '131.60', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame(-131.60, $rowsOut[0]['amount']);
        // Kurz „21.716 CZK/USD" (ani jeho prefix 21.71) se nesmí objevit v popisu.
        self::assertStringNotContainsString('CZK/USD', (string) $rowsOut[0]['description']);
        self::assertStringNotContainsString('21.71', (string) $rowsOut[0]['description']);
        self::assertStringContainsString('OPENAI', (string) $rowsOut[0]['description']);

        // A self-check projde (součet -131.60 = pohyb zůstatku).
        $result = $this->parser()->parse('%PDF-fake', $text);
        self::assertSame(-131.60, $result['transactions'][0]['amount']);
    }

    public function testEmptyMonthWithZeroTransactionsIsValid(): void
    {
        $result = $this->parser()->parse('%PDF-fake', $this->statement('500.00', '500.00', '0.00', '0.00', ''));
        self::assertSame([], $result['transactions']);
        self::assertSame(500.0, $result['header']['prev_balance']);
    }

    public function testSelfCheckRejectsWhenSumDoesNotMatchBalanceDelta(): void
    {
        // Součet částek (+2 000) nesedí na pohyb zůstatku dle hlavičky (+9 999) → zamítnout.
        $rows = "4. 6. 2026\n4. 6. 2026\n1000000001\n"
              . "Platba\n9876543210/0800\nJan Novák\nPříchozí úhrada\t2 000.00 CZK\n";
        $text = $this->statement('10 000.00', '19 999.00', '2 000.00', '0.00', $rows);

        $this->expectException(\RuntimeException::class);
        $this->parser()->parse('%PDF-fake', $text);
    }

    public function testSupportsDetectsRaiffeisenbank(): void
    {
        $parser = $this->parser();
        self::assertTrue($parser->supports("Raiffeisenbank a.s., Hvězdova 1716/2b\nBIC:\tRZBCCZPP\n"));
        self::assertTrue($parser->supports("Raiffeisenbank a.s.\nwww.rb.cz\n"));
        self::assertFalse($parser->supports("Komerční banka, a.s.\nVÝPIS PERIODICKÝ\n"));
        self::assertFalse($parser->supports("Banka CREDITAS a.s.\ncreditas.cz\n"));
    }
}
