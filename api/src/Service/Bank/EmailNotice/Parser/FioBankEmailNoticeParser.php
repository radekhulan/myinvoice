<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

/**
 * Fio banka — dva formáty avíza (oba z domény fio.cz):
 *
 * A) Legacy „Fio banka - prijem/vydaj na konte" (automat@fio.cz), řádkové
 *    `Pole: hodnota`:
 *      Příjem na kontě: 2901618111      (nebo „Výdaj na kontě:" — směr nese label)
 *      Částka: 3 898,00
 *      VS: 26006439
 *      Zpráva příjemci: text            (u výdaje „US: text")
 *      Aktuální zůstatek: 168 459,32
 *      Protiúčet: 1234567890/0800
 *      SS:
 *      KS:
 *    Avízo neobsahuje datum (bere se z Date hlavičky e-mailu) ani měnu
 *    (default CZK, případný kód za částkou se respektuje).
 *
 * B) Novější prozaické avízo (issue #34) — směr, datum, částku i měnu nese
 *    věta, zbytek je blok „Další parametry":
 *      zůstatek účtu 1111 se 24.08.2026 v 12:43 zvýšil o 10,00 CZK.
 *      Další parametry
 *      Typ pohybu: Okamžitá příchozí platba
 *      Protistrana: 1234567890/0800 (ing. Novák)
 *      ID pokynu: 1111
 *      Variabilní symbol: 0000
 *      Konstantní symbol: 0308
 *      Uživatelský symbol: xxx
 *      Zpráva pro příjemce: Faktura 1111
 *      Aktuální zůstatek: 1,75 CZK
 *    „zvýšil" = příjem, „snížil" = výdaj (ukládá se se záporným znaménkem).
 *
 * C) Anglická varianta formátu B (předmět „Fio banka - new income on account"),
 *    datum je ve tvaru m/d/Y, částka s desetinnou tečkou a měnou před ní:
 *      the balance of account 1111 has increased by CZK 1.00 on 10/25/2026 at 10:37.
 *      Counterparty: 1234567890/0800 (ing. Novák)
 *      Instruction ID: 1111
 *      Variable symbol: 0000
 *      Message for beneficiary: Faktura 1111
 *      Current balance: CZK 1.75
 *
 * Účet „na kontě" / „zůstatek účtu" je bez kódu banky — doplní se /2010
 * (účet vedený u Fio).
 */
final class FioBankEmailNoticeParser extends AbstractBankEmailNoticeParser
{
    /** Formát B: hlavička „zůstatek účtu … se … zvýšil/snížil o … CZK". */
    private const PROSE_HEADER = '/z[ůu]statek\s+[úu][čc]tu\s*(?<account>[0-9\-]+(?:\/[0-9]{4})?)'
        . '\s+se\s+(?<date>\d{1,2}\.\s*\d{1,2}\.\s*\d{4})(?:\s+v\s+(?<time>\d{1,2}:\d{2}))?'
        . '\s+(?<direction>zv[ýy][šs]il|sn[íi][žz]il)\s+o\s*(?<amount>[+\-]?[0-9][0-9 .]*,[0-9]{2})'
        . '\s*(?<currency>[A-Za-z]{3}|Kč|Kc)?/u';

    /** Formát C: hlavička „balance of account … has increased/decreased by CZK … on m/d/Y". */
    private const PROSE_HEADER_EN = '/balance\s+of\s+account\s*(?<account>[0-9\-]+(?:\/[0-9]{4})?)'
        . '\s+has\s+(?<direction>increased|decreased)\s+by\s*(?<currency>[A-Za-z]{3})?'
        . '\s*(?<amount>[+\-]?[0-9][0-9,]*\.[0-9]{2})'
        . '(?:\s+on\s+(?<month>\d{1,2})\/(?<day>\d{1,2})\/(?<year>\d{4}))?/iu';

    public function key(): string
    {
        return 'fio';
    }

    protected function parserLabel(): string
    {
        return 'Fio banka';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'system:' . $this->key(),
            code: $this->key(),
            name: 'Fio banka - příjem/výdaj na kontě',
            parserType: $this->key(),
            enabled: true,
            senderWhitelist: 'fio.cz',
            subjectPattern: 'Fio\\s+banka\\s+-\\s+(?:prijem|vydaj|příjem|výdaj)\\s+na\\s+kont[ěe]',
            bodyPattern: '(?:(?:Příjem|Výdaj)\\s+na\\s+kontě|z[ůu]statek\\s+[úu][čc]tu|balance\\s+of\\s+account)',
            fieldPatterns: [],
            normalizerConfig: [],
            system: true,
        );
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!$this->senderMatchesDomain($message, 'fio.cz')) {
            return false;
        }

        $text = $this->normalizeText($message->text);
        if ($this->isProseNotice($text) || $this->isEnglishProseNotice($text)) {
            return true;
        }

        $subject = $this->compact(mb_strtolower($message->subject, 'UTF-8'));
        if (
            !str_contains($subject, 'fio banka')
            || preg_match('/(?:prijem|vydaj|příjem|výdaj)\s+na\s+kont[ěe]/u', $subject) !== 1
        ) {
            return false;
        }

        $text = $this->compact(mb_strtolower($text, 'UTF-8'));
        return preg_match('/(?:příjem|výdaj|prijem|vydaj)\s+na\s+kont[ěe]\s*:/u', $text) === 1
            && (str_contains($text, 'částka') || str_contains($text, 'castka'));
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizeText($message->text);

        if ($this->isProseNotice($text)) {
            return $this->parseProseNotice($text, $message);
        }
        if ($this->isEnglishProseNotice($text)) {
            return $this->parseEnglishProseNotice($text, $message);
        }

        $header = $this->match(
            $text,
            '/(?:^|\R)\s*(?<direction>Příjem|Prijem|Výdaj|Vydaj)\s+na\s+kont[ěe]\s*:\s*(?<account>[0-9\-]+(?:\/[0-9]{4})?)/iu',
        );
        if ($header === null) {
            throw new \RuntimeException('Fio banka parser nenašel cílový účet.');
        }
        $amountCurrency = $this->match(
            $text,
            '/(?:^|\R)\s*Částka\s*:\s*(?<amount>[+\-]?[0-9][0-9 .]*,[0-9]{2})(?:\s*(?<currency>[A-Za-z]{3}|Kč))?/u',
        );
        if ($amountCurrency === null) {
            throw new \RuntimeException('Fio banka parser nenašel částku.');
        }
        if (!$message->date instanceof \DateTimeImmutable) {
            throw new \RuntimeException('Fio banka parser nenašel datum e-mailu.');
        }

        $variableSymbol = $this->optional($text, '/(?:^|\R)\s*VS\s*:\s*(?<value>[0-9]+)/u');
        $constantSymbol = $this->optional($text, '/(?:^|\R)\s*KS\s*:\s*(?<value>[0-9]+)/u');
        $note = $this->optional($text, '/(?:^|\R)\s*(?:Zpráva\s+příjemci|US)\s*:\s*(?<value>[^\r\n]+)/u');
        $counterparty = $this->optional($text, '/(?:^|\R)\s*Protiúčet\s*:\s*(?<value>[0-9\-]+(?:\/[0-9]{4})?)/u');
        $balance = $this->optional(
            $this->foldDiacritics($text),
            '/(?:^|\R)\s*Aktualni\s+zustatek\s*:\s*(?<value>[+\-]?[0-9][0-9 .]*,[0-9]{2})/iu',
        );

        [$recipientAccount, $recipientBank] = $this->splitAccount((string) $header['account']);
        [$cpAccount, $cpBank] = $this->splitAccount((string) $counterparty);

        $currency = trim((string) ($amountCurrency['currency'] ?? ''));

        return new ParsedBankEmailNotice(
            variableSymbol: $this->normalizeSymbol((string) $variableSymbol),
            amount: $this->applyDirection($this->parseAmount((string) $amountCurrency['amount']), (string) $header['direction']),
            currency: $currency !== '' ? $this->normalizeCurrency($currency) : 'CZK',
            postedAt: $message->date->format('Y-m-d'),
            recipientAccount: $recipientAccount . '/' . ($recipientBank ?? '2010'),
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            constantSymbol: $constantSymbol,
            message: $note,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
        );
    }

    private function isProseNotice(string $text): bool
    {
        return preg_match(self::PROSE_HEADER, $text) === 1;
    }

    /**
     * Formát B (#34). Datum i měna jsou v těle, takže na rozdíl od legacy
     * varianty nepotřebují Date hlavičku — ta slouží jen jako fallback.
     */
    private function parseProseNotice(string $text, BankEmailNoticeMessage $message): ParsedBankEmailNotice
    {
        $header = $this->match($text, self::PROSE_HEADER);
        if ($header === null) {
            throw new \RuntimeException('Fio banka parser nenašel částku.');
        }

        $date = trim((string) ($header['date'] ?? ''));
        if ($date !== '') {
            $postedAt = $this->parseDate($date);
        } elseif ($message->date instanceof \DateTimeImmutable) {
            $postedAt = $message->date->format('Y-m-d');
        } else {
            throw new \RuntimeException('Fio banka parser nenašel datum e-mailu.');
        }

        $variableSymbol = $this->optional($text, '/(?:^|\R)\s*Variabiln[íi]\s+symbol\s*:\s*(?<value>[0-9]+)/u');
        $constantSymbol = $this->optional($text, '/(?:^|\R)\s*Konstantn[íi]\s+symbol\s*:\s*(?<value>[0-9]+)/u');
        $note = $this->optional($text, '/(?:^|\R)\s*Zpr[áa]va\s+pro\s+p[řr][íi]jemce\s*:\s*(?<value>[^\r\n]+)/u')
            ?? $this->optional($text, '/(?:^|\R)\s*Zpr[áa]va\s+pro\s+m[ěe]\s*:\s*(?<value>[^\r\n]+)/u')
            ?? $this->optional($text, '/(?:^|\R)\s*U[žz]ivatelsk[ýy]\s+symbol\s*:\s*(?<value>[^\r\n]+)/u');
        $bankRef = $this->optional($text, '/(?:^|\R)\s*ID\s+pokynu\s*:\s*(?<value>[^\r\n]+)/u');
        $balance = $this->optional(
            $this->foldDiacritics($text),
            '/(?:^|\R)\s*Aktualni\s+zustatek\s*:\s*(?<value>[+\-]?[0-9][0-9 .]*,[0-9]{2})/iu',
        );

        [$cpAccount, $cpBank, $cpName] = $this->parseCounterparty($text);
        [$recipientAccount, $recipientBank] = $this->splitAccount((string) $header['account']);

        $currency = trim((string) ($header['currency'] ?? ''));
        $direction = preg_match('/^sn[íi][žz]il$/u', (string) $header['direction']) === 1 ? 'výdaj' : 'příjem';

        return new ParsedBankEmailNotice(
            variableSymbol: $this->normalizeSymbol((string) $variableSymbol),
            amount: $this->applyDirection($this->parseAmount((string) $header['amount']), $direction),
            currency: $currency !== '' ? $this->normalizeCurrency($currency) : 'CZK',
            postedAt: $postedAt,
            recipientAccount: $recipientAccount . '/' . ($recipientBank ?? '2010'),
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            counterpartyName: $cpName,
            constantSymbol: $constantSymbol,
            message: $note,
            bankRef: $bankRef,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
        );
    }

    private function isEnglishProseNotice(string $text): bool
    {
        return preg_match(self::PROSE_HEADER_EN, $text) === 1;
    }

    /**
     * Formát C. Datum m/d/Y se ověřuje přes checkdate, aby se prohozený den
     * a měsíc (25/10/2026) nepřevedl tiše na jiné datum.
     */
    private function parseEnglishProseNotice(string $text, BankEmailNoticeMessage $message): ParsedBankEmailNotice
    {
        $header = $this->match($text, self::PROSE_HEADER_EN);
        if ($header === null) {
            throw new \RuntimeException('Fio banka parser nenašel částku.');
        }

        $year = (int) ($header['year'] ?? 0);
        if ($year > 0) {
            $month = (int) $header['month'];
            $day = (int) $header['day'];
            if (!checkdate($month, $day, $year)) {
                throw new \RuntimeException('Fio banka parser nenašel validní datum platby.');
            }
            $postedAt = sprintf('%04d-%02d-%02d', $year, $month, $day);
        } elseif ($message->date instanceof \DateTimeImmutable) {
            $postedAt = $message->date->format('Y-m-d');
        } else {
            throw new \RuntimeException('Fio banka parser nenašel datum e-mailu.');
        }

        $variableSymbol = $this->optional($text, '/(?:^|\R)\s*Variable\s+symbol\s*:\s*(?<value>[0-9]+)/iu');
        $constantSymbol = $this->optional($text, '/(?:^|\R)\s*Constant\s+symbol\s*:\s*(?<value>[0-9]+)/iu');
        $note = $this->optional($text, '/(?:^|\R)\s*Message\s+for\s+beneficiary\s*:\s*(?<value>[^\r\n]+)/iu')
            ?? $this->optional($text, '/(?:^|\R)\s*Message\s+for\s+me\s*:\s*(?<value>[^\r\n]+)/iu')
            ?? $this->optional($text, '/(?:^|\R)\s*User\s+(?:symbol|message)\s*:\s*(?<value>[^\r\n]+)/iu');
        $bankRef = $this->optional($text, '/(?:^|\R)\s*Instruction\s+ID\s*:\s*(?<value>[^\r\n]+)/iu');
        $balance = $this->optional(
            $text,
            '/(?:^|\R)\s*Current\s+balance\s*:\s*(?:[A-Za-z]{3}\s*)?(?<value>[+\-]?[0-9][0-9,]*\.[0-9]{2})/iu',
        );

        [$cpAccount, $cpBank, $cpName] = $this->parseCounterparty($text, 'Counterparty');
        [$recipientAccount, $recipientBank] = $this->splitAccount((string) $header['account']);

        $currency = trim((string) ($header['currency'] ?? ''));
        $direction = strtolower((string) $header['direction']) === 'decreased' ? 'výdaj' : 'příjem';

        return new ParsedBankEmailNotice(
            variableSymbol: $this->normalizeSymbol((string) $variableSymbol),
            amount: $this->applyDirection($this->parseAmount((string) $header['amount']), $direction),
            currency: $currency !== '' ? $this->normalizeCurrency($currency) : 'CZK',
            postedAt: $postedAt,
            recipientAccount: $recipientAccount . '/' . ($recipientBank ?? '2010'),
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            counterpartyName: $cpName,
            constantSymbol: $constantSymbol,
            message: $note,
            bankRef: $bankRef,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
        );
    }

    /**
     * „Protistrana: 1234567890/0800 (ing. Novák)" → účet, kód banky, název.
     * Kód banky bere 1–4 číslice (avíza chodí i s maskovaným protiúčtem),
     * název je volitelný a může stát i sám (zahraniční protistrana bez účtu).
     *
     * @return array{0:?string,1:?string,2:?string}
     */
    private function parseCounterparty(string $text, string $label = 'Protistrana'): array
    {
        $value = $this->optional($text, '/(?:^|\R)\s*' . $label . '\s*:\s*(?<value>[^\r\n]+)/iu');
        if ($value === null) {
            return [null, null, null];
        }

        $name = null;
        if (preg_match('/\s*\((?<name>[^)]*)\)\s*$/u', $value, $m) === 1) {
            $name = $this->cleanNullable((string) $m['name']);
            $value = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $value));
        }

        if (preg_match('/^(?<account>[0-9\-]+)\/(?<bank>[0-9]{1,4})$/', $value, $m) === 1) {
            return [$m['account'], $m['bank'], $name];
        }

        return [$this->cleanNullable($value), null, $name];
    }
}
