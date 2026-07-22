<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

/**
 * Společná vrstva parserů bankovních e-mailových avíz (PR #118, @blondak) —
 * normalizace textu, regex match s pojmenovanými skupinami, parsování částek,
 * dat, účtů, symbolů a měn. Bank-specifická detekce (supports) a extrakce
 * polí (parse) zůstávají v konkrétních parserech.
 *
 * Helpery jsou sjednocené na nejrobustnější z původních per-bank variant
 * (parseAmount/cleanNullable z UniCredit, parseDate/splitAccount/normalizeCurrency
 * z Regex parseru) — chovají se proto v okrajích velkoryseji než původní
 * úzké verze: parseAmount zvládá oba oddělovače tisíců i znaménko, parseDate
 * má po výčtu formátů lenient fallback `new DateTimeImmutable`, cleanNullable
 * nuluje i „N/A".
 */
abstract class AbstractBankEmailNoticeParser implements BankEmailNoticeParserInterface
{
    protected EmailNoticeTextNormalizer $normalizer;

    public function __construct(?EmailNoticeTextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new EmailNoticeTextNormalizer();
    }

    abstract public function defaultProvider(): ?BankEmailNoticeProvider;

    protected function normalizeText(string $text): string
    {
        return $this->normalizer->normalize($text);
    }

    /**
     * Sklopí českou/slovenskou (a běžnou latinkovou) diakritiku na ASCII
     * („Směr"→„Smer", „Částka"→„Castka", „Kč"→„Kc"). Slouží k diakritiku-
     * tolerantnímu matchování: přeposlaná avíza chodí občas v legacy kódování
     * nebo s rozbitou/chybějící diakritikou (#58), takže ani detekce parseru
     * (`supports()`), ani extrakce polí nesmí stát a padat na jednom konkrétním
     * accentovaném znaku v patternu. Mapa se sestaví jednou (vč. verzálek).
     */
    protected function foldDiacritics(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        static $map = null;
        if ($map === null) {
            $lower = [
                'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
                'č' => 'c', 'ć' => 'c', 'ç' => 'c',
                'ď' => 'd', 'đ' => 'd',
                'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e',
                'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
                'ĺ' => 'l', 'ľ' => 'l', 'ł' => 'l',
                'ň' => 'n', 'ń' => 'n', 'ñ' => 'n',
                'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
                'ŕ' => 'r', 'ř' => 'r',
                'š' => 's', 'ś' => 's', 'ß' => 'ss',
                'ť' => 't',
                'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u',
                'ý' => 'y', 'ÿ' => 'y',
                'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
            ];
            $map = $lower;
            foreach ($lower as $from => $to) {
                $map[mb_strtoupper($from, 'UTF-8')] = mb_strtoupper($to, 'UTF-8');
            }
        }
        return strtr($text, $map);
    }

    protected function compact(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @return array<string,string>|null
     */
    protected function match(string $text, string $pattern): ?array
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }
        $out = [];
        foreach ($m as $key => $value) {
            if (is_string($key)) {
                $out[$key] = trim((string) $value);
            }
        }
        return $out;
    }

    protected function required(string $text, string $pattern, string $label): string
    {
        $value = $this->optional($text, $pattern);
        if ($value === null) {
            throw new \RuntimeException($this->parserLabel() . " parser nenašel {$label}.");
        }
        return $value;
    }

    protected function optional(string $text, string $pattern): ?string
    {
        $m = $this->match($text, $pattern);
        if ($m === null || !isset($m['value'])) {
            return null;
        }
        return $this->cleanNullable($m['value']);
    }

    /**
     * Částka z textu avíza: zahodí měnu/mezery/nbsp, zachová znaménko a podle
     * POSLEDNÍHO oddělovače rozliší desetinnou čárku vs. tečku („1.234,56"
     * i „1,234.56" → 1234.56). Pozor na ambiguitu „2.500" bez desetin —
     * čte se jako 2.5 (desetinná tečka), ne 2500; CZ banky ale desetiny
     * v avízech uvádějí vždy („2 500,00").
     */
    protected function parseAmount(string $value): float
    {
        $value = preg_replace('/[^\d,.\-+]/u', '', trim($value)) ?? $value;
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastDot > $lastComma) {
                $value = str_replace(',', '', $value);
            } else {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            }
        } elseif ($lastComma !== false) {
            $value = str_replace(',', '.', $value);
        }

        $amount = (float) $value;
        return $negative ? -$amount : $amount;
    }

    /**
     * Datum → 'Y-m-d'. Nejdřív explicitní CZ formáty (d.m.Y s/bez mezer a času,
     * ATOM, RFC2822), pak lenient fallback `new DateTimeImmutable` — ten je
     * záměrně poslední: u ambiguózních tvarů (05/04/2026) parsuje po americku,
     * ale systémové parsery mu datum předají už vyfiltrované regexem na CZ tvar.
     */
    protected function parseDate(string $value, string $label = 'validní datum platby'): string
    {
        $value = $this->compact($value);
        foreach ([
            'd.m.Y H:i:s',
            'd.m.Y H:i',
            'd. m. Y H:i:s',
            'd. m. Y H:i',
            'd.m.Y',
            'd. m. Y',
            \DateTimeInterface::ATOM,
            \DateTimeInterface::RFC2822,
        ] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            throw new \RuntimeException($this->parserLabel() . " parser nenašel {$label}.");
        }
    }

    /**
     * Rozdělí „číslo/kód banky" na [účet, banka]; nejdřív účet vytáhne
     * z okolního textu (normalizeAccount), bez kódu banky vrací [hodnota, null].
     *
     * @return array{0:?string,1:?string}
     */
    protected function splitAccount(string $value): array
    {
        $value = $this->normalizeAccount($value);
        if ($value === '') {
            return [null, null];
        }
        if (preg_match('/^(?<account>[0-9\-]+)\/(?<bank>[0-9]{4})$/', $value, $m) === 1) {
            return [$m['account'], $m['bank']];
        }
        return [$value, null];
    }

    protected function normalizeAccount(string $value): string
    {
        $value = trim($value);
        if (preg_match('/[0-9\-]+\/[0-9]{4}/', $value, $m) === 1) {
            return $m[0];
        }
        return $value;
    }

    /** VS/KS/SS: jen číslice bez leading nul (konzistentní s párováním GPC). */
    protected function normalizeSymbol(string $value): string
    {
        return VariableSymbolNormalizer::forMatching($value);
    }

    protected function digitsOnly(string $value): string
    {
        return VariableSymbolNormalizer::digits($value);
    }

    protected function cleanNullable(string $value): ?string
    {
        $value = $this->compact($value);
        if ($value === '' || in_array(strtoupper($value), ['N/A', 'NA'], true)) {
            return null;
        }
        return mb_substr($value, 0, 255);
    }

    /**
     * Match odesílatele na doménu banky s podporou PŘEPOSLANÝCH avíz (#161).
     *
     * Přímé avízo: `From` hlavička sedí na doménu banky → hotovo.
     * Přeposlané avízo (FW:) má `From` na adrese uživatele (přeposílatele)
     * a původní odesílatel je „uvnitř" zprávy. Některé MUA (Outlook) ale do
     * textové části nevloží přeposlaný `From:` blok — jediná stopa po bance
     * pak zůstává v patičce/podpisu (např. „napište na info@creditas.cz",
     * „Vaše Banka CREDITAS"). Proto fallback: když `From` nesedí, hledá se
     * adresa banky kdekoli v těle (přeposlaná hlavička i patička).
     *
     * Tento fallback je OPT-IN per IMAP účet (`allow_forwarded`), defaultně
     * vypnutý — zapíná se jen pro schránku, do které avíza chodí přeposlaná.
     * Stejně jako SenderDomain jde o pouhý ROUTING na správný parser — odesílatel
     * je beztak spoofnutelný. Skutečnou pojistkou zůstává striktní struktura těla
     * v `supports()` + povinné mapování cílového účtu na účet dodavatele.
     */
    protected function senderMatchesDomain(BankEmailNoticeMessage $message, string ...$domains): bool
    {
        if (SenderDomain::matches($message->sender, ...$domains)) {
            return true;
        }
        if (!$message->allowForwarded) {
            return false;
        }
        // Volitelné omezení, OD KOHO smí přeposlaná avíza chodit: `From` musí sedět
        // na nastaveného přeposílatele (adresa nebo doména). Prázdné = libovolný.
        if ($message->forwardedFrom !== '' && !$this->forwarderMatches($message->sender, $message->forwardedFrom)) {
            return false;
        }
        if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+/', $message->text, $m) >= 1) {
            foreach ($m[0] as $address) {
                if (SenderDomain::matches($address, ...$domains)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Sedí `From` přeposlané zprávy na nastaveného přeposílatele? Hodnota s „@"
     * = přesná adresa (case-insensitive, tolerantní k „Jméno <adresa>"), bez „@"
     * = doména (vč. subdomén přes SenderDomain).
     */
    private function forwarderMatches(string $sender, string $forwardedFrom): bool
    {
        $forwardedFrom = strtolower(trim($forwardedFrom));
        if ($forwardedFrom === '') {
            return true;
        }
        if (!str_contains($forwardedFrom, '@')) {
            return SenderDomain::matches($sender, $forwardedFrom);
        }
        $address = strtolower(trim($sender));
        if (preg_match('/<([^<>]+)>\s*$/', $address, $m) === 1) {
            $address = trim($m[1]);
        }
        return $address === $forwardedFrom;
    }

    /**
     * Whitelist odesílatelů providera (mezera/čárka/středník oddělené adresy);
     * prázdný = povolit vše. Matchuje přesnou adresu i tvar „Jméno <adresa>".
     */
    protected function senderAllowed(string $sender, string $whitelist): bool
    {
        $whitelist = trim($whitelist);
        if ($whitelist === '') {
            return true;
        }
        $sender = strtolower($sender);
        foreach (preg_split('/[\s,;]+/', strtolower($whitelist)) ?: [] as $allowed) {
            $allowed = trim($allowed);
            if ($allowed === '') {
                continue;
            }
            if ($sender === $allowed || str_contains($sender, '<' . $allowed . '>')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Varianta `senderAllowed` s podporou PŘEPOSLANÝCH (FW) avíz (#161) — pro Regex
     * providery (ČS i custom), které routují podle whitelistu adres místo domény banky.
     *
     * Přímé avízo: `From` sedí na whitelist → hotovo. Přeposlané avízo (opt-in
     * `allow_forwarded`) má `From` přeposílatele, proto se whitelistovaná doména
     * hledá i v těle (přeposlaná hlavička / patička), volitelně po pinu přeposílatele.
     * Prázdný whitelist = povolit vše (i přeposlané). Routing-only, viz `senderMatchesDomain`.
     */
    protected function senderAllowedForwarded(BankEmailNoticeMessage $message, string $whitelist): bool
    {
        if ($this->senderAllowed($message->sender, $whitelist)) {
            return true;
        }
        if (!$message->allowForwarded) {
            return false;
        }
        if ($message->forwardedFrom !== '' && !$this->forwarderMatches($message->sender, $message->forwardedFrom)) {
            return false;
        }
        if (trim($whitelist) === '') {
            return true;
        }
        $domains = [];
        foreach (preg_split('/[\s,;]+/', strtolower($whitelist)) ?: [] as $allowed) {
            $allowed = trim($allowed);
            if ($allowed === '') {
                continue;
            }
            $at = strrpos($allowed, '@');
            $domain = $at !== false ? substr($allowed, $at + 1) : $allowed;
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }
        if ($domains === []) {
            return false;
        }
        if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+/', $message->text, $m) >= 1) {
            foreach ($m[0] as $address) {
                if (SenderDomain::matches($address, ...$domains)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Sjednotí měnu na ISO kód. Banky často píší symbol „Kč" / „€" místo „CZK" / „EUR".
     */
    protected function normalizeCurrency(string $value): string
    {
        $v = trim($value);
        $upper = mb_strtoupper($v, 'UTF-8');
        $map = [
            'KČ' => 'CZK',
            'KC' => 'CZK',
            'CZK' => 'CZK',
            '€' => 'EUR',
            'EUR' => 'EUR',
            '$' => 'USD',
            'USD' => 'USD',
            '£' => 'GBP',
            'GBP' => 'GBP',
            'ZŁ' => 'PLN',
            'ZL' => 'PLN',
            'PLN' => 'PLN',
        ];
        if (isset($map[$upper])) {
            return $map[$upper];
        }
        if (isset($map[$v])) {
            return $map[$v];
        }
        $letters = preg_replace('/[^A-ZÁ-Ž]/u', '', $upper) ?? $upper;
        if (isset($map[$letters])) {
            return $map[$letters];
        }
        return $letters !== '' ? mb_substr($letters, 0, 3, 'UTF-8') : $upper;
    }

    /**
     * Česká spořitelna nerozlišuje příjem/výdej znaménkem, ale řádkem
     * „Směr platby: příchozí/odchozí" (Fio labelem „Výdaj na kontě").
     * Odchozí platbu ulož se záporným znaménkem (konzistentní s GPC),
     * ať se nepáruje proti pohledávkám.
     */
    protected function applyDirection(float $amount, string $direction): float
    {
        $direction = mb_strtolower(trim($direction), 'UTF-8');
        if ($direction === '') {
            return $amount;
        }
        // Moneta Info Servis: předmět/nadpis „Odešly peníze" (ne „odchozí").
        if (preg_match('/odchoz|ode[sš]l|výdej|vydej|výdaj|vydaj|debet|odepsán|odepsan|outgoing/u', $direction) === 1) {
            return -abs($amount);
        }
        return abs($amount);
    }

    abstract protected function parserLabel(): string;
}
