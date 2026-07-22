<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

/**
 * MONETA Money Bank — Info Servis avízo „Přišly peníze" / „Odešly peníze"
 * (infoservis@moneta.cz, Evypis@moneta.cz).
 *
 * HTML šablona se po strip_tags zploští na labely:
 *   Účet: 238***891          (prostředek maskovaný — mapování přes AccountNumberNormalizer)
 *   Datum: 01.07.2026
 *   Částka: 35 000,00 Kč
 *   Popis: … VS: 202600009
 *   Od: 264555317/0300
 *   Disponibilní zůstatek: 64 831,42 Kč
 *
 * Kód banky u vlastního účtu v avízu chybí → doplní se /0600 (MONETA).
 * Směr nese nadpis/předmět (Přišly = příchozí, Odešly = odchozí/záporná částka).
 */
final class MonetaBankEmailNoticeParser extends AbstractBankEmailNoticeParser
{
    private const BANK_CODE = '0600';

    public function key(): string
    {
        return 'moneta';
    }

    protected function parserLabel(): string
    {
        return 'MONETA Money Bank';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'system:' . $this->key(),
            code: $this->key(),
            name: 'MONETA Money Bank - Přišly/Odešly peníze',
            parserType: $this->key(),
            enabled: true,
            senderWhitelist: 'infoservis@moneta.cz Evypis@moneta.cz',
            subjectPattern: 'Přišly\\s+peníze|Odešly\\s+peníze|Prisly\\s+penize|Odesly\\s+penize',
            bodyPattern: 'Účet:|Ucet:',
            fieldPatterns: [],
            normalizerConfig: [],
            system: true,
        );
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!$this->senderMatchesDomain($message, 'moneta.cz')) {
            return false;
        }

        $subject = $this->compact(mb_strtolower($this->foldDiacritics($message->subject), 'UTF-8'));
        if (
            !str_contains($subject, 'prisly penize')
            && !str_contains($subject, 'odesly penize')
        ) {
            return false;
        }

        $text = $this->compact(mb_strtolower($this->foldDiacritics($this->normalizeText($message->text)), 'UTF-8'));
        return str_contains($text, 'ucet:')
            && str_contains($text, 'castka:')
            && (str_contains($text, 'prisly penize') || str_contains($text, 'odesly penize'));
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizeText($message->text);
        $folded = $this->foldDiacritics($text);

        $account = $this->required(
            $folded,
            '/(?:^|\R)\s*Ucet:\s*(?<value>[0-9*]+(?:\/[0-9]{4})?)/iu',
            'cílový účet',
        );
        $postedAt = $this->required(
            $folded,
            '/(?:^|\R)\s*Datum:\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4})/iu',
            'datum',
        );
        $amountCurrency = $this->match(
            $folded,
            '/(?:^|\R)\s*Castka:\s*(?<amount>[+\-]?[0-9][0-9 ]*,[0-9]{2})\s*(?<currency>Kc|CZK|EUR|USD|€|[A-Z]{3})/iu',
        );
        if ($amountCurrency === null) {
            throw new \RuntimeException($this->parserLabel() . ' parser nenašel částku a měnu.');
        }

        $variableSymbol = $this->optional($text, '/\bVS:\s*(?<value>[0-9]+)/iu')
            ?? $this->optional($folded, '/\bVS:\s*(?<value>[0-9]+)/iu');
        $counterparty = $this->optional(
            $text,
            '/(?:^|\R)\s*Od:\s*(?<value>[0-9\-]+\/[0-9]{4})/iu',
        );
        // Popis ber z původního textu (ne folded), ať zůstane diakritika ve zprávě.
        $note = $this->optional(
            $text,
            '/(?:^|\R)\s*Popis:\s*(?<value>.+?)(?=\n\s*Od:)/isu',
        );
        $balance = $this->optional(
            $folded,
            '/(?:^|\R)\s*Disponibilni\s+zustatek:\s*(?<value>[+\-]?[0-9][0-9 ]*,[0-9]{2})/iu',
        );

        [$recipientAccount, $recipientBank] = $this->splitAccount($account);
        // Maskovaný účet (238***891) neprojde regexem splitAccount — nech číslo + doplň /0600.
        if ($recipientAccount === null || $recipientAccount === '' || str_contains($recipientAccount, '*')) {
            $recipientAccount = preg_replace('/[^0-9*]/', '', explode('/', $account, 2)[0]) ?? $account;
            $recipientBank = null;
        }
        [$cpAccount, $cpBank] = $this->splitAccount((string) $counterparty);

        $direction = preg_match('/odesly\s+penize/iu', $folded) === 1 ? 'Odešly peníze' : 'Přišly peníze';

        return new ParsedBankEmailNotice(
            variableSymbol: $this->normalizeSymbol((string) $variableSymbol),
            amount: $this->applyDirection($this->parseAmount((string) $amountCurrency['amount']), $direction),
            currency: $this->normalizeCurrency((string) ($amountCurrency['currency'] ?? 'CZK')),
            postedAt: $this->parseDate($postedAt),
            recipientAccount: $recipientAccount . '/' . ($recipientBank ?? self::BANK_CODE),
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            message: $note,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
        );
    }
}
