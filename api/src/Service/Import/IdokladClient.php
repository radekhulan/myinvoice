<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * iDoklad API v3 client — OAuth2 client_credentials + cached bearer.
 *
 * Endpoints:
 *   - POST https://identity.idoklad.cz/server/connect/token (OAuth2)
 *   - GET  https://api.idoklad.cz/v3/Contacts
 *   - GET  https://api.idoklad.cz/v3/IssuedInvoices
 *   - GET  https://api.idoklad.cz/v3/ReceivedInvoices
 *
 * Rate limit per docs: 60 req/min. Vlastní hint counter — pokud >50 req/min,
 * sleep 1s před requestem (smooth rate).
 *
 * Token cache: supplier.idoklad_access_token + idoklad_token_expires_at.
 * Refresh při expiraci nebo 401 response.
 */
final class IdokladClient
{
    private const TOKEN_URL = 'https://identity.idoklad.cz/server/connect/token';
    private const API_BASE  = 'https://api.idoklad.cz/v3';
    private const TIMEOUT   = 30;
    private const RATE_LIMIT_THRESHOLD = 50; // req/min — nad tím throttle

    private Client $http;
    /** @var array<int, list<int>>  supplier_id → list timestamps (rolling 60s window) */
    private array $requestLog = [];

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $crypto,
        private readonly LoggerInterface $logger,
    ) {
        $this->http = new Client([
            'timeout' => self::TIMEOUT,
            'http_errors' => false,
        ]);
    }

    /**
     * Načti credentials pro daný supplier. Vrátí null pokud nejsou nastaveny.
     *
     * @return array{client_id:string, client_secret:string}|null
     */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT idoklad_client_id, idoklad_client_secret_enc FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['idoklad_client_id']) || empty($row['idoklad_client_secret_enc'])) {
            return null;
        }
        try {
            $secret = $this->crypto->decrypt((string) $row['idoklad_client_secret_enc']);
        } catch (\Throwable $e) {
            $this->logger->error('iDoklad client_secret decryption failed', ['supplier_id' => $supplierId]);
            return null;
        }
        return ['client_id' => (string) $row['idoklad_client_id'], 'client_secret' => $secret];
    }

    /**
     * Set credentials. Secret se šifruje před uložením.
     */
    public function setCredentials(int $supplierId, string $clientId, string $clientSecret): void
    {
        $enc = $clientSecret === '' ? null : $this->crypto->encrypt($clientSecret);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET idoklad_client_id = ?, idoklad_client_secret_enc = ?,
                                  idoklad_access_token = NULL, idoklad_token_expires_at = NULL
              WHERE id = ?'
        )->execute([$clientId ?: null, $enc, $supplierId]);
    }

    /**
     * Test connectivity — pokus o získání access tokenu. Vrátí true při úspěchu.
     */
    public function testConnection(int $supplierId): array
    {
        try {
            $token = $this->fetchToken($supplierId, force: true);
            return ['ok' => true, 'expires_in_seconds' => $token['expires_in'] ?? null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Vrátí valid bearer token. Cached pokud platný, jinak fetch + cache.
     */
    public function getToken(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT idoklad_access_token, idoklad_token_expires_at FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row && !empty($row['idoklad_access_token']) && !empty($row['idoklad_token_expires_at'])) {
            $expires = strtotime((string) $row['idoklad_token_expires_at']);
            if ($expires !== false && $expires > time() + 60) {
                return (string) $row['idoklad_access_token'];
            }
        }
        $token = $this->fetchToken($supplierId);
        return $token['access_token'];
    }

    /**
     * Fetch nový OAuth2 token + cache do DB.
     *
     * @return array{access_token:string, expires_in:int, token_type:string}
     */
    private function fetchToken(int $supplierId, bool $force = false): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            throw new \RuntimeException('iDoklad credentials nejsou nastaveny pro tohoto suppliera.');
        }

        $this->throttle($supplierId);
        $resp = $this->http->post(self::TOKEN_URL, [
            'form_params' => [
                'client_id'     => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'grant_type'    => 'client_credentials',
                'scope'         => 'idoklad_api',
            ],
        ]);
        $code = $resp->getStatusCode();
        $body = (string) $resp->getBody();
        if ($code !== 200) {
            throw new \RuntimeException("iDoklad OAuth2 token request failed (HTTP {$code}): {$body}");
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('iDoklad OAuth2 response neobsahuje access_token.');
        }
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $expiresAt = (new \DateTimeImmutable('+' . $expiresIn . ' seconds'))->format('Y-m-d H:i:s');

        // Cache do DB
        $this->db->pdo()->prepare(
            'UPDATE supplier SET idoklad_access_token = ?, idoklad_token_expires_at = ? WHERE id = ?'
        )->execute([$data['access_token'], $expiresAt, $supplierId]);

        return [
            'access_token' => (string) $data['access_token'],
            'expires_in'   => $expiresIn,
            'token_type'   => (string) ($data['token_type'] ?? 'Bearer'),
        ];
    }

    /**
     * GET /v3/{endpoint} s pagination. Vrátí jeden page (Items + TotalItems).
     *
     * @return array{Items: list<array<string,mixed>>, TotalItems: int, TotalPages?: int}
     */
    public function get(int $supplierId, string $endpoint, int $page = 1, int $pageSize = 100, array $extraQuery = []): array
    {
        $token = $this->getToken($supplierId);
        $url = self::API_BASE . '/' . ltrim($endpoint, '/');
        $query = array_merge(['PageSize' => $pageSize, 'Page' => $page], $extraQuery);

        $this->throttle($supplierId);
        $resp = $this->http->get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            'query'   => $query,
        ]);
        $code = $resp->getStatusCode();
        $body = (string) $resp->getBody();

        // Token expired mid-request — retry once with fresh
        if ($code === 401) {
            $this->logger->info('iDoklad 401 — refreshing token', ['supplier_id' => $supplierId, 'endpoint' => $endpoint]);
            $token = $this->fetchToken($supplierId, force: true)['access_token'];
            $resp = $this->http->get($url, [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                'query'   => $query,
            ]);
            $code = $resp->getStatusCode();
            $body = (string) $resp->getBody();
        }
        if ($code !== 200) {
            throw new \RuntimeException("iDoklad GET {$endpoint} failed (HTTP {$code}): {$body}");
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("iDoklad GET {$endpoint} returned invalid JSON.");
        }
        return self::parseListResponse($data);
    }

    /**
     * Rozbalí iDoklad v3 list response do `{Items, TotalItems, TotalPages}`.
     *
     * iDoklad v3 zabaluje každou odpověď do ApiResult envelope:
     *   { "Data": <payload>, "IsSuccess": true, "ErrorCode": 0, "Message": null, ... }
     * U stránkovaných list endpointů je payload Page objekt s přesně třemi klíči:
     *   { "Items": [...], "TotalItems": N, "TotalPages": M }
     * Některé endpointy (např. Attachments) vrací Data rovnou jako pole. Envelope MUSÍME
     * rozbalit — jinak bychom iterovali klíče Page wrapperu (Items/TotalItems/TotalPages),
     * což dává falešné „3 záznamy" u každé entity (viz #80).
     *
     * @param array<string,mixed> $data  dekódovaná JSON odpověď
     * @return array{Items: list<array<string,mixed>>, TotalItems: int, TotalPages: int}
     */
    public static function parseListResponse(array $data): array
    {
        $payload = (isset($data['Data']) && is_array($data['Data'])) ? $data['Data'] : $data;

        if (isset($payload['Items']) && is_array($payload['Items'])) {
            $items = $payload['Items'];
        } elseif (array_is_list($payload)) {
            $items = $payload;
        } else {
            $items = [];
        }

        return [
            'Items'      => array_values($items),
            'TotalItems' => (int) ($payload['TotalItems'] ?? $data['TotalItems'] ?? count($items)),
            'TotalPages' => (int) ($payload['TotalPages'] ?? $data['TotalPages'] ?? 0),
        ];
    }

    /**
     * Iterator přes všechny stránky daného endpointu. Yield jednotlivé items.
     *
     * @return iterable<array<string,mixed>>
     */
    public function getAll(int $supplierId, string $endpoint, array $extraQuery = [], int $pageSize = 100): iterable
    {
        $page = 1;
        do {
            $res = $this->get($supplierId, $endpoint, $page, $pageSize, $extraQuery);
            foreach ($res['Items'] as $item) {
                yield $item;
            }
            $hasMore = count($res['Items']) === $pageSize;
            $page++;
        } while ($hasMore);
    }

    /** @var array<int, array<int,string>>  supplier_id → (iDoklad CurrencyId → ISO kód) */
    private array $currencyCodeCache = [];
    /** @var array<int, array<int,string>>  supplier_id → (iDoklad CountryId → ISO2) */
    private array $countryCodeCache = [];

    /**
     * Mapa iDoklad CurrencyId → ISO kód (CZK, EUR, …). Cachováno per supplier.
     *
     * NUTNÉ, protože list endpointy (IssuedInvoices/ReceivedInvoices/CreditNotes) vrací
     * jen `CurrencyId` (int), NE `CurrencyCode` ani nested `Currency.Code`. Bez téhle mapy
     * skončily všechny importované doklady v CZK bez ohledu na reálnou měnu (viz #80 audit).
     *
     * @return array<int,string>
     */
    public function currencyCodeMap(int $supplierId): array
    {
        if (isset($this->currencyCodeCache[$supplierId])) return $this->currencyCodeCache[$supplierId];
        $map = [];
        try {
            foreach ($this->getAll($supplierId, 'Currencies') as $c) {
                $id = (int) ($c['Id'] ?? 0);
                $code = strtoupper(trim((string) ($c['Code'] ?? '')));
                if ($id > 0 && $code !== '') $map[$id] = $code;
            }
        } catch (\Throwable $e) {
            $this->logger->info('iDoklad currencyCodeMap failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
        }
        return $this->currencyCodeCache[$supplierId] = $map;
    }

    /**
     * Mapa iDoklad CountryId → ISO2 kód (CZ, SK, DE, …). Cachováno per supplier.
     *
     * List endpoint Contacts vrací jen `CountryId` (int), NE nested `Country.Code` — bez
     * téhle mapy dostali všichni kontakti zemi CZ, což navíc rozbíjelo detekci reverse-charge
     * u zahraničních dodavatelů (viz #80 audit).
     *
     * @return array<int,string>
     */
    public function countryCodeMap(int $supplierId): array
    {
        if (isset($this->countryCodeCache[$supplierId])) return $this->countryCodeCache[$supplierId];
        $map = [];
        try {
            foreach ($this->getAll($supplierId, 'Countries') as $c) {
                $id = (int) ($c['Id'] ?? 0);
                $code = strtoupper(trim((string) ($c['Code'] ?? '')));
                if ($id > 0 && $code !== '') $map[$id] = $code;
            }
        } catch (\Throwable $e) {
            $this->logger->info('iDoklad countryCodeMap failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
        }
        return $this->countryCodeCache[$supplierId] = $map;
    }

    /**
     * Stáhne PDF pro vydanou fakturu (rendered iDoklad PDF). Endpoint:
     *   GET /v2/IssuedInvoices/{id}/GetPdf  (Accept: application/json).
     *
     * Pozn.: PDF render je dostupný JEN na v2 API — v3 vrací UnsupportedApiVersion,
     * a cesta je /GetPdf, ne /Document (/Document 404). Tělo je bare base64 JSON
     * string (celé tělo je "JVBERi0..."), tj. json_decode → base64 → %PDF bajty.
     */
    public function downloadIssuedPdf(int $supplierId, int $idokladInvoiceId): ?string
    {
        $token = $this->getToken($supplierId);
        $url = str_replace('/v3', '/v2', self::API_BASE) . '/IssuedInvoices/' . $idokladInvoiceId . '/GetPdf';
        $this->throttle($supplierId);
        $resp = $this->http->get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        ]);
        if ($resp->getStatusCode() !== 200) return null;
        return self::decodeIssuedPdfBody((string) $resp->getBody());
    }

    /**
     * Rozbalí tělo v2 GetPdf odpovědi (JSON string literal s base64 PDF) na surové
     * PDF bajty. Vrátí null, pokud tělo není validní base64 PDF dokument.
     */
    public static function decodeIssuedPdfBody(string $body): ?string
    {
        $b64 = json_decode($body, true);
        if (!is_string($b64) || $b64 === '') {
            return null;
        }
        $pdf = base64_decode($b64, true);

        return ($pdf !== false && str_starts_with($pdf, '%PDF')) ? $pdf : null;
    }

    /**
     * List attachments pro přijatou fakturu (PDF originály od dodavatele).
     *
     * iDoklad v3 endpoint: GET /v3/Attachments/{documentId}/{documentType}/{compressed}
     * documentType = `ReceivedInvoice` (enum 5). Odpověď nese bajty inline jako
     * base64 `FileBytes` — žádný separátní download request.
     * (Starý endpoint /ReceivedInvoices/{id}/Attachments vracel 404 → žádné PDF, viz #80.)
     *
     * @return list<array<string,mixed>>  [{Id, FileName, FileBytes(base64)}]
     */
    public function listReceivedAttachments(int $supplierId, int $idokladInvoiceId): array
    {
        try {
            $r = $this->get($supplierId, 'Attachments/' . $idokladInvoiceId . '/ReceivedInvoice/false', 1, 100);
            return $r['Items'];
        } catch (\Throwable $e) {
            $this->logger->info('iDoklad listReceivedAttachments failed', ['invoice_id' => $idokladInvoiceId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Rolling 60s window throttle — pokud bylo víc než RATE_LIMIT_THRESHOLD requests,
     * sleep 1s.
     */
    private function throttle(int $supplierId): void
    {
        $now = time();
        $log = $this->requestLog[$supplierId] ?? [];
        // Drop stale (>60s)
        $log = array_values(array_filter($log, fn ($t) => $t > $now - 60));
        if (count($log) >= self::RATE_LIMIT_THRESHOLD) {
            $this->logger->info('iDoklad throttle — sleeping 1s', ['supplier_id' => $supplierId, 'requests_in_window' => count($log)]);
            sleep(1);
        }
        $log[] = $now;
        $this->requestLog[$supplierId] = $log;
    }
}
