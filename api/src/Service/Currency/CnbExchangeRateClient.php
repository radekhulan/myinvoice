<?php

declare(strict_types=1);

namespace MyInvoice\Service\Currency;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * ČNB denní devizové kurzy — fetch + cache + day-back fallback.
 *
 * Feed: https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt?date=DD.MM.YYYY
 *
 * Formát:
 *   03.05.2026 #84
 *   země|měna|množství|kód|kurz
 *   EMU|euro|1|EUR|24,360
 *   USA|dolar|1|USD|22,123
 *   ...
 *
 * Normalizace: kurz se uloží jako "CZK za 1 jednotku" (rate / amount). Pro většinu
 * měn je amount=1, ale např. JPY/HUF má amount=100 — feed dělíme.
 *
 * Cache: tabulka exchange_rates ((rate_date, currency_code) PK). První fetch pro
 * daný den uloží VŠECHNY kurzy z feedu (jeden HTTP call zaplní celý den, levné).
 */
final class CnbExchangeRateClient
{
    private const FEED_BASE = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt';
    private const FALLBACK_DAYS = 7;     // kolik dní zpět hledat při 404 / chybějícím dni
    private const TIMEOUT_SEC = 5;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly ?Config $config = null,
    ) {}

    /**
     * Vrátí kurz CZK / 1 jednotka měny pro daný den. Cache-first; když ve feedu
     * pro daný den měna není (víkend/svátek/404), zkusí předchozí dny (max 7).
     *
     * @return array{rate: float, rate_date: string, fallback_used: bool, source: 'cache'|'fresh'|'last_known'}|null
     */
    public function getRate(string $currencyCode, DateTimeImmutable $issueDate): ?array
    {
        $code = strtoupper(trim($currencyCode));
        if ($code === '' || $code === 'CZK') {
            return null;
        }

        // 1) cache hit přesně na issue_date
        $cached = $this->fromCache($code, $issueDate->format('Y-m-d'));
        if ($cached !== null) {
            return [
                'rate'          => $cached,
                'rate_date'     => $issueDate->format('Y-m-d'),
                'fallback_used' => false,
                'source'        => 'cache',
            ];
        }

        // 2) zkusíme fetch pro issue_date a pak postupně dny zpět (víkend / svátek)
        for ($i = 0; $i <= self::FALLBACK_DAYS; $i++) {
            $tryDate = $issueDate->modify('-' . $i . ' day');
            $tryStr  = $tryDate->format('Y-m-d');

            // Cached pro tento alternative day?
            $hit = $this->fromCache($code, $tryStr);
            if ($hit !== null) {
                return [
                    'rate'          => $hit,
                    'rate_date'     => $tryStr,
                    'fallback_used' => $i > 0,
                    'source'        => 'cache',
                ];
            }

            // Fetch + parse
            $rates = $this->fetchAndParse($tryDate);
            if ($rates !== null) {
                $this->saveBatch($tryStr, $rates);
                if (isset($rates[$code])) {
                    return [
                        'rate'          => $rates[$code],
                        'rate_date'     => $tryStr,
                        'fallback_used' => $i > 0,
                        'source'        => 'fresh',
                    ];
                }
                // Měna ve feedu pro tento den není → zkus den předtím
            }
        }

        // 3) Last-known fallback — nejnovější známý kurz dané měny z DB, ale nikdy
        //    novější než rozhodný den (doplnění kurzu starší faktuře při výpadku ČNB).
        $latest = $this->latestKnown($code, $issueDate->format('Y-m-d'));
        if ($latest !== null) {
            return [
                'rate'          => $latest['rate'],
                'rate_date'     => $latest['rate_date'],
                'fallback_used' => true,
                'source'        => 'last_known',
            ];
        }

        return null;
    }

    /**
     * Vrátí všechny požadované kurzy z jednoho kurzového dne.
     *
     * @param list<string> $currencyCodes
     * @return array{
     *   rates: array<string,float>,
     *   rate_date: string,
     *   fallback_used: bool,
     *   source: 'cache'|'fresh'|'last_known'
     * }|null
     */
    public function getRatesForCommonDate(array $currencyCodes, DateTimeImmutable $date): ?array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $currencyCodes,
        ), static fn (string $code): bool => $code !== '' && $code !== 'CZK')));

        if ($codes === []) {
            return [
                'rates' => [],
                'rate_date' => $date->format('Y-m-d'),
                'fallback_used' => false,
                'source' => 'cache',
            ];
        }

        for ($i = 0; $i <= self::FALLBACK_DAYS; $i++) {
            $tryDate = $date->modify('-' . $i . ' day');
            $tryStr = $tryDate->format('Y-m-d');

            $cached = $this->ratesFromCache($codes, $tryStr);
            if (count($cached) === count($codes)) {
                return [
                    'rates' => $cached,
                    'rate_date' => $tryStr,
                    'fallback_used' => $i > 0,
                    'source' => 'cache',
                ];
            }

            $rates = $this->fetchAndParse($tryDate);
            if ($rates === null) continue;

            $this->saveBatch($tryStr, $rates);
            $selected = array_intersect_key($rates, array_flip($codes));
            if (count($selected) === count($codes)) {
                return [
                    'rates' => array_map('floatval', $selected),
                    'rate_date' => $tryStr,
                    'fallback_used' => $i > 0,
                    'source' => 'fresh',
                ];
            }
        }

        $latest = $this->latestCommonKnown($codes, $date->format('Y-m-d'));
        if ($latest === null) return null;

        return [
            'rates' => $latest['rates'],
            'rate_date' => $latest['rate_date'],
            'fallback_used' => $latest['rate_date'] !== $date->format('Y-m-d'),
            'source' => 'last_known',
        ];
    }

    /**
     * Fetch + parse jednoho dne. Vrací map [CODE => rate_per_unit] nebo null pokud
     * feed nedostupný / 404 / parse selhal.
     *
     * @return array<string, float>|null
     */
    private function fetchAndParse(DateTimeImmutable $date): ?array
    {
        $url = self::FEED_BASE . '?date=' . $date->format('d.m.Y');

        try {
            $client = new Client([
                'timeout'         => self::TIMEOUT_SEC,
                'connect_timeout' => self::TIMEOUT_SEC,
            ]);
            $resp = $client->get($url, [
                'http_errors' => false,
                'headers'     => ['Accept' => 'text/plain'],
            ]);
            $status = $resp->getStatusCode();
            if ($status !== 200) {
                if ($status !== 404) {
                    $this->logger->warning('CNB feed neočekávaný status', ['date' => $date->format('Y-m-d'), 'status' => $status]);
                }
                return null;
            }
            $body = (string) $resp->getBody();
            return self::parse($body, $date->format('Y-m-d'));
        } catch (GuzzleException $e) {
            $this->logger->warning('CNB feed nedostupný: ' . $e->getMessage(), ['date' => $date->format('Y-m-d')]);
            return null;
        }
    }

    /**
     * Pure parser — testovatelný bez sítě. Bere obsah feedu, validuje datum
     * v hlavičce (musí odpovídat $expectedDate v Y-m-d), parsuje řádky a normalizuje
     * kurz na "1 jednotku".
     *
     * Pokud datum v hlavičce neodpovídá expected (CNB občas vrátí předchozí den
     * pro budoucí datum), vrací null — caller pak vyzkouší další den.
     *
     * @return array<string, float>|null
     */
    public static function parse(string $body, string $expectedDate): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        if (!is_array($lines) || count($lines) < 3) {
            return null;
        }

        // Header: "DD.MM.YYYY #N"
        $header = $lines[0];
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+#\d+/', $header, $m)) {
            return null;
        }
        $headerDate = $m[3] . '-' . $m[2] . '-' . $m[1];
        if ($headerDate !== $expectedDate) {
            return null;
        }

        $out = [];
        // řádek 1 je sloupcová hlavička "země|měna|množství|kód|kurz"
        for ($i = 2; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') continue;

            $cols = explode('|', $line);
            if (count($cols) !== 5) continue;

            $amount = (float) str_replace(',', '.', trim($cols[2]));
            $code   = strtoupper(trim($cols[3]));
            $rate   = (float) str_replace(',', '.', trim($cols[4]));

            if ($amount <= 0 || $rate <= 0 || $code === '') continue;

            $out[$code] = $rate / $amount;   // normalizace na 1 jednotku
        }

        return $out;
    }

    private function fromCache(string $code, string $date): ?float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT rate FROM exchange_rates WHERE rate_date = ? AND currency_code = ?'
        );
        $stmt->execute([$date, $code]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (float) $val;
    }

    /**
     * @param list<string> $codes
     * @return array<string,float>
     */
    private function ratesFromCache(array $codes, string $date): array
    {
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT currency_code, rate FROM exchange_rates
              WHERE rate_date = ? AND currency_code IN ($placeholders)"
        );
        $stmt->execute(array_merge([$date], $codes));

        $rates = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rates[(string) $row['currency_code']] = (float) $row['rate'];
        }
        return $rates;
    }

    /**
     * @param array<string, float> $rates
     */
    private function saveBatch(string $date, array $rates): void
    {
        if ($rates === []) return;

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate), fetched_at = NOW()'
        );
        foreach ($rates as $code => $rate) {
            $stmt->execute([$date, $code, $rate]);
        }
    }

    /**
     * @return array{rate: float, rate_date: string}|null
     */
    private function latestKnown(string $code, string $notAfter): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT rate, rate_date FROM exchange_rates
              WHERE currency_code = ? AND rate_date <= ?
           ORDER BY rate_date DESC
              LIMIT 1'
        );
        $stmt->execute([$code, $notAfter]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return [
            'rate'      => (float) $row['rate'],
            'rate_date' => (string) $row['rate_date'],
        ];
    }

    /**
     * @param list<string> $codes
     * @return array{rates:array<string,float>,rate_date:string}|null
     */
    private function latestCommonKnown(array $codes, string $notAfter): ?array
    {
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT rate_date
               FROM exchange_rates
              WHERE rate_date <= ? AND currency_code IN ($placeholders)
              GROUP BY rate_date
             HAVING COUNT(DISTINCT currency_code) = ?
              ORDER BY rate_date DESC
              LIMIT 1"
        );
        $stmt->execute(array_merge([$notAfter], $codes, [count($codes)]));
        $rateDate = $stmt->fetchColumn();
        if ($rateDate === false) return null;

        $rates = $this->ratesFromCache($codes, (string) $rateDate);
        if (count($rates) !== count($codes)) return null;

        return ['rates' => $rates, 'rate_date' => (string) $rateDate];
    }
}
