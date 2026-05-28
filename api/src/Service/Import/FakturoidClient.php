<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Fakturoid API v3 client — podporuje dva auth flow side-by-side:
 *
 *  1) **OAuth2 Client Credentials** (issue #31, povinné pro účty založené po 2024):
 *     POST https://app.fakturoid.cz/api/v3/oauth/token
 *       Authorization: Basic base64(client_id:client_secret)
 *       Content-Type:  application/x-www-form-urlencoded
 *       Body:          grant_type=client_credentials
 *     → { access_token, expires_in (~7200), token_type: "Bearer" }
 *     Bearer token se cachuje v `supplier.fakturoid_access_token_enc` + expires_at.
 *
 *  2) **Legacy BasicAuth** (personal API token + email — pro starší účty):
 *     Authorization: Basic base64(email:api_key)
 *
 * URL pattern: https://app.fakturoid.cz/api/v3/accounts/{slug}/...
 * Priorita: pokud má supplier `fakturoid_client_id` → OAuth2; jinak BasicAuth.
 * User-Agent: REQUIRED header (jinak 403) — `MyInvoice.cz/<version> (radek@hulan.cz)`.
 *
 * Rate limit: 240 req/min hard, naše soft 200/min → throttle při >180.
 */
final class FakturoidClient
{
    private const API_BASE = 'https://app.fakturoid.cz/api/v3/accounts';
    private const TOKEN_URL = 'https://app.fakturoid.cz/api/v3/oauth/token';
    private const USER_AGENT = 'MyInvoice.cz Import (https://github.com/radekhulan/myinvoice; radek@hulan.cz)';
    private const TIMEOUT = 30;
    private const RATE_LIMIT_THRESHOLD = 180; // req/min

    private Client $http;
    /** @var array<int, list<int>>  supplier_id → list timestamps (rolling 60s) */
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
     * Načti všechny dostupné credentials pro daný supplier.
     *
     * Vrátí asociativní pole s těmito klíči (každý nullable):
     *   - slug          — account slug (povinné pro API path)
     *   - email         — legacy BasicAuth username (jen pro legacy flow)
     *   - api_key       — legacy BasicAuth password (jen pro legacy flow)
     *   - client_id     — OAuth2 client_id (jen pro OAuth2 flow)
     *   - client_secret — OAuth2 client_secret (jen pro OAuth2 flow)
     *
     * Vrátí null pokud není nastaven ani slug, ani žádný auth materiál.
     *
     * @return array{slug:string, email:?string, api_key:?string, client_id:?string, client_secret:?string}|null
     */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT fakturoid_slug, fakturoid_email, fakturoid_api_key_enc,
                    fakturoid_client_id, fakturoid_client_secret_enc
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['fakturoid_slug'])) {
            return null;
        }
        $slug = (string) $row['fakturoid_slug'];

        $apiKey = null;
        if (!empty($row['fakturoid_api_key_enc'])) {
            try {
                $apiKey = $this->crypto->decrypt((string) $row['fakturoid_api_key_enc']);
            } catch (\Throwable $e) {
                $this->logger->error('Fakturoid api_key decryption failed', ['supplier_id' => $supplierId]);
            }
        }

        $clientSecret = null;
        if (!empty($row['fakturoid_client_secret_enc'])) {
            try {
                $clientSecret = $this->crypto->decrypt((string) $row['fakturoid_client_secret_enc']);
            } catch (\Throwable $e) {
                $this->logger->error('Fakturoid client_secret decryption failed', ['supplier_id' => $supplierId]);
            }
        }

        $hasOAuth = !empty($row['fakturoid_client_id']) && $clientSecret !== null && $clientSecret !== '';
        $hasBasic = !empty($row['fakturoid_email']) && $apiKey !== null && $apiKey !== '';
        if (!$hasOAuth && !$hasBasic) {
            return null;
        }

        return [
            'slug'          => $slug,
            'email'         => !empty($row['fakturoid_email']) ? (string) $row['fakturoid_email'] : null,
            'api_key'       => $apiKey,
            'client_id'     => !empty($row['fakturoid_client_id']) ? (string) $row['fakturoid_client_id'] : null,
            'client_secret' => $clientSecret,
        ];
    }

    /**
     * Zda má supplier nastavený OAuth2 flow (priorita před BasicAuth).
     */
    public function hasOAuthCredentials(int $supplierId): bool
    {
        $creds = $this->getCredentials($supplierId);
        return $creds !== null
            && $creds['client_id'] !== null && $creds['client_id'] !== ''
            && $creds['client_secret'] !== null && $creds['client_secret'] !== '';
    }

    /**
     * Set legacy BasicAuth credentials (email + personal API token).
     * Maže OAuth2 token cache, aby další request nepřežil za starou identitu.
     */
    public function setCredentials(int $supplierId, string $slug, string $email, string $apiKey): void
    {
        $enc = $apiKey === '' ? null : $this->crypto->encrypt($apiKey);
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET fakturoid_slug = ?, fakturoid_email = ?, fakturoid_api_key_enc = ?,
                    fakturoid_access_token_enc = NULL, fakturoid_access_token_expires_at = NULL
              WHERE id = ?'
        )->execute([$slug ?: null, $email ?: null, $enc, $supplierId]);
    }

    /**
     * Set OAuth2 credentials (client_id + client_secret). Slug zůstává sdílený.
     * Maže OAuth2 token cache, aby další request fetch fresh token.
     */
    public function setOAuthCredentials(int $supplierId, string $slug, string $clientId, string $clientSecret): void
    {
        $enc = $clientSecret === '' ? null : $this->crypto->encrypt($clientSecret);
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET fakturoid_slug = ?, fakturoid_client_id = ?, fakturoid_client_secret_enc = ?,
                    fakturoid_access_token_enc = NULL, fakturoid_access_token_expires_at = NULL
              WHERE id = ?'
        )->execute([$slug ?: null, $clientId ?: null, $enc, $supplierId]);
    }

    /**
     * Vyčistí všechny Fakturoid credentials (BasicAuth i OAuth2 + token cache).
     */
    public function clearCredentials(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET fakturoid_slug = NULL, fakturoid_email = NULL, fakturoid_api_key_enc = NULL,
                    fakturoid_client_id = NULL, fakturoid_client_secret_enc = NULL,
                    fakturoid_access_token_enc = NULL, fakturoid_access_token_expires_at = NULL
              WHERE id = ?'
        )->execute([$supplierId]);
    }

    /**
     * Test connectivity — GET /account.json (jednoduchý endpoint, vrací jméno účtu).
     * Vrací stejnou strukturu pro BasicAuth i OAuth2.
     */
    public function testConnection(int $supplierId): array
    {
        try {
            $creds = $this->getCredentials($supplierId);
            if ($creds === null) {
                return ['ok' => false, 'error' => 'Credentials nenastaveny'];
            }
            $url = self::API_BASE . '/' . urlencode($creds['slug']) . '/account.json';
            $this->throttle($supplierId);
            $resp = $this->http->get($url, [
                'headers' => $this->authHeaders($supplierId, $creds),
            ]);
            $code = $resp->getStatusCode();
            if ($code !== 200) {
                return ['ok' => false, 'error' => "HTTP {$code}: " . substr((string) $resp->getBody(), 0, 200)];
            }
            $data = json_decode((string) $resp->getBody(), true);
            return ['ok' => true, 'account_name' => $data['name'] ?? null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * GET /subjects.json (nebo invoices.json, expenses.json) s pagination.
     * Fakturoid používá Link header pro next page (nikoliv page/total v body).
     *
     * @return array{items: list<array<string,mixed>>, next_page: ?string}
     */
    public function get(int $supplierId, string $endpoint, int $page = 1, array $extraQuery = []): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            throw new \RuntimeException('Fakturoid credentials nejsou nastaveny.');
        }
        $url = self::API_BASE . '/' . urlencode($creds['slug']) . '/' . ltrim($endpoint, '/');
        $query = array_merge(['page' => $page], $extraQuery);

        $this->throttle($supplierId);
        $resp = $this->http->get($url, [
            'headers' => $this->authHeaders($supplierId, $creds),
            'query'   => $query,
        ]);
        $code = $resp->getStatusCode();

        // OAuth2 token mid-flight expired — vyhoď cache + retry once
        if ($code === 401 && $this->isUsingOAuth($creds)) {
            $this->logger->info('Fakturoid 401 — refreshing OAuth2 token', ['supplier_id' => $supplierId, 'endpoint' => $endpoint]);
            $this->invalidateToken($supplierId);
            $resp = $this->http->get($url, [
                'headers' => $this->authHeaders($supplierId, $creds),
                'query'   => $query,
            ]);
            $code = $resp->getStatusCode();
        }

        if ($code === 429) {
            // Hit rate limit — sleep podle Retry-After + retry once
            $retry = (int) ($resp->getHeader('Retry-After')[0] ?? 5);
            $this->logger->info('Fakturoid 429 — sleeping', ['retry_after' => $retry]);
            sleep(min($retry, 30));
            $resp = $this->http->get($url, ['headers' => $this->authHeaders($supplierId, $creds), 'query' => $query]);
            $code = $resp->getStatusCode();
        }
        if ($code !== 200) {
            throw new \RuntimeException("Fakturoid GET {$endpoint} failed (HTTP {$code}): " . substr((string) $resp->getBody(), 0, 200));
        }
        $body = (string) $resp->getBody();
        $items = json_decode($body, true);
        if (!is_array($items)) {
            throw new \RuntimeException("Fakturoid GET {$endpoint} returned invalid JSON.");
        }
        return ['items' => $items, 'next_page' => $this->parseNextPage($resp->getHeader('Link'))];
    }

    /**
     * Generator přes všechny stránky.
     *
     * @return iterable<array<string,mixed>>
     */
    public function getAll(int $supplierId, string $endpoint, array $extraQuery = []): iterable
    {
        $page = 1;
        do {
            $res = $this->get($supplierId, $endpoint, $page, $extraQuery);
            foreach ($res['items'] as $item) {
                yield $item;
            }
            // Fakturoid API v3 neposílá RFC 5988 Link header (oproti komentáři u parseNextPage)
            // — `next_page` je vždy null, takže původní podmínka ukončila smyčku po první stránce.
            // Pokračujeme tedy dokud dostáváme plnou stránku (Fakturoid používá per_page=40).
            $hasMore = count($res['items']) >= 40;
            $page++;
        } while ($hasMore);
    }

    /**
     * Stáhne Fakturoidem vygenerované PDF vydané faktury (GET …/invoices/{id}/download.pdf).
     * Vrátí binární obsah, nebo null (204 = PDF se ještě generuje, nebo chyba).
     */
    public function downloadInvoicePdf(int $supplierId, int $invoiceId): ?string
    {
        return $this->binaryGet($supplierId, 'invoices/' . $invoiceId . '/download.pdf');
    }

    /**
     * Stáhne přílohu výdaje (originální doklad od dodavatele). Fakturoid vrací
     * v expense JSON pole `attachment` jako URL — stáhneme ho s autorizací.
     */
    public function downloadAttachment(int $supplierId, string $attachmentUrl): ?string
    {
        if ($attachmentUrl === '') return null;
        return $this->binaryGet($supplierId, $attachmentUrl, absolute: true);
    }

    /**
     * GET binárního obsahu (PDF / příloha). `$absolute` = endpoint je už plná URL
     * (Fakturoid attachment), jinak se skládá z API_BASE + slug. 401 → refresh + retry.
     */
    private function binaryGet(int $supplierId, string $endpoint, bool $absolute = false): ?string
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) return null;

        $url = $absolute
            ? $endpoint
            : self::API_BASE . '/' . urlencode($creds['slug']) . '/' . ltrim($endpoint, '/');

        $headers = $this->authHeaders($supplierId, $creds);
        $headers['Accept'] = '*/*'; // ne JSON — chceme binárku

        $this->throttle($supplierId);
        $resp = $this->http->get($url, ['headers' => $headers]);
        $code = $resp->getStatusCode();

        if ($code === 401 && $this->isUsingOAuth($creds)) {
            $this->invalidateToken($supplierId);
            $headers = $this->authHeaders($supplierId, $creds);
            $headers['Accept'] = '*/*';
            $resp = $this->http->get($url, ['headers' => $headers]);
            $code = $resp->getStatusCode();
        }

        if ($code !== 200) return null; // 204 = PDF not ready, 404 = bez přílohy
        $body = (string) $resp->getBody();
        return $body !== '' ? $body : null;
    }

    /**
     * Sestaví auth header podle dostupných credentials.
     * Priorita: OAuth2 (pokud client_id + client_secret) → BasicAuth.
     *
     * @param array{slug:string, email:?string, api_key:?string, client_id:?string, client_secret:?string} $creds
     * @return array<string,string>
     */
    private function authHeaders(int $supplierId, array $creds): array
    {
        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Accept'     => 'application/json',
        ];

        if ($this->isUsingOAuth($creds)) {
            $token = $this->getAccessToken($supplierId, $creds);
            $headers['Authorization'] = 'Bearer ' . $token;
        } else {
            // Legacy BasicAuth — email + personal API token
            if ($creds['email'] === null || $creds['api_key'] === null) {
                throw new \RuntimeException('Fakturoid credentials neúplné (chybí email/api_key i client_id/client_secret).');
            }
            $basic = base64_encode($creds['email'] . ':' . $creds['api_key']);
            $headers['Authorization'] = 'Basic ' . $basic;
        }

        return $headers;
    }

    /**
     * @param array{client_id:?string, client_secret:?string, ...} $creds
     */
    private function isUsingOAuth(array $creds): bool
    {
        return !empty($creds['client_id']) && !empty($creds['client_secret']);
    }

    /**
     * Vrátí valid OAuth2 Bearer token. Pokud je cached a expires_at > now + 60s, vrátí z cache.
     * Jinak fetch fresh + uloží encrypted cache.
     *
     * @param array{client_id:?string, client_secret:?string, ...} $creds
     */
    public function getAccessToken(int $supplierId, ?array $creds = null): string
    {
        if ($creds === null) {
            $creds = $this->getCredentials($supplierId);
            if ($creds === null || !$this->isUsingOAuth($creds)) {
                throw new \RuntimeException('Fakturoid OAuth2 credentials nejsou nastaveny.');
            }
        }

        // Pokus o cache hit
        $stmt = $this->db->pdo()->prepare(
            'SELECT fakturoid_access_token_enc, fakturoid_access_token_expires_at
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row && !empty($row['fakturoid_access_token_enc']) && !empty($row['fakturoid_access_token_expires_at'])) {
            $expires = strtotime((string) $row['fakturoid_access_token_expires_at']);
            if ($expires !== false && $expires > time() + 60) {
                try {
                    return $this->crypto->decrypt((string) $row['fakturoid_access_token_enc']);
                } catch (\Throwable $e) {
                    $this->logger->warning('Fakturoid token cache decrypt failed — refreshing', ['supplier_id' => $supplierId]);
                }
            }
        }

        return $this->fetchToken($supplierId, (string) $creds['client_id'], (string) $creds['client_secret']);
    }

    /**
     * POST /api/v3/oauth/token — OAuth2 Client Credentials grant.
     */
    private function fetchToken(int $supplierId, string $clientId, string $clientSecret): string
    {
        $this->throttle($supplierId);
        $resp = $this->http->post(self::TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'User-Agent'    => self::USER_AGENT,
                'Accept'        => 'application/json',
            ],
            'form_params' => [
                'grant_type' => 'client_credentials',
            ],
        ]);
        $code = $resp->getStatusCode();
        $body = (string) $resp->getBody();
        if ($code !== 200) {
            $this->logger->error('Fakturoid OAuth2 token request failed', [
                'supplier_id' => $supplierId,
                'http_code'   => $code,
                'body'        => substr($body, 0, 500),
            ]);
            // invalid_client = nejčastěji špatný ZDROJ credentials: Fakturoid v3 rozlišuje
            // "Client Credentials Flow" (Nastavení → Uživatelský profil → API v3 přístupové
            // údaje — tohle používáme) a "Authorization Code Flow" (OAuth integrace). Credentials
            // ze stránky OAuth integrací s grant_type=client_credentials Fakturoid odmítne.
            $hint = str_contains($body, 'invalid_client')
                ? ' — Zkontroluj, že Client ID i Secret pocházejí z "Nastavení → Uživatelský profil →'
                  . ' API v3 přístupové údaje" (Client Credentials Flow), NE ze stránky OAuth integrací.'
                : '';
            throw new \RuntimeException("Fakturoid OAuth2 token request failed (HTTP {$code}): " . substr($body, 0, 200) . $hint);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Fakturoid OAuth2 response neobsahuje access_token.');
        }
        $accessToken = (string) $data['access_token'];
        $expiresIn = (int) ($data['expires_in'] ?? 7200); // default 2h per docs
        $expiresAt = (new \DateTimeImmutable('+' . $expiresIn . ' seconds'))->format('Y-m-d H:i:s');

        // Cache do DB (šifrovaný)
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET fakturoid_access_token_enc = ?, fakturoid_access_token_expires_at = ?
              WHERE id = ?'
        )->execute([$this->crypto->encrypt($accessToken), $expiresAt, $supplierId]);

        return $accessToken;
    }

    private function invalidateToken(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET fakturoid_access_token_enc = NULL, fakturoid_access_token_expires_at = NULL
              WHERE id = ?'
        )->execute([$supplierId]);
    }

    /**
     * Fakturoid používá RFC 5988 Link header pro pagination.
     * Format: <url>; rel="next", <url>; rel="last"
     */
    private function parseNextPage(array $linkHeaders): ?string
    {
        $line = $linkHeaders[0] ?? null;
        if ($line === null) return null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $line, $m)) {
            return $m[1];
        }
        return null;
    }

    private function throttle(int $supplierId): void
    {
        $now = time();
        $log = $this->requestLog[$supplierId] ?? [];
        $log = array_values(array_filter($log, fn ($t) => $t > $now - 60));
        if (count($log) >= self::RATE_LIMIT_THRESHOLD) {
            $this->logger->info('Fakturoid throttle — sleep 1s', ['supplier_id' => $supplierId]);
            sleep(1);
        }
        $log[] = $now;
        $this->requestLog[$supplierId] = $log;
    }
}
