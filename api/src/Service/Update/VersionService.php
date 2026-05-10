<?php

declare(strict_types=1);

namespace MyInvoice\Service\Update;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Version service — kontrola nové verze, cache release notes, detekce
 * runtime prostředí (Docker / nativní), spouštění upgradu.
 *
 * Aktuální verze se čte z `VERSION` souboru v rootu repa (jeden řádek
 * semver). Poslední dostupná verze + release notes se cachují v tabulce
 * `app_meta` (key `latest_version`, `latest_release_notes`,
 * `latest_release_url`, `latest_published_at`, `last_check_at`,
 * `last_check_error`).
 *
 * Cron `api/bin/cron-version-check.php` denně volá
 * `refreshLatestVersion()`. UI volá `getStatus()` (hot, z cache) — neudělá
 * blocking síťový call.
 */
final class VersionService
{
    private const META_KEYS = [
        'latest_version',
        'latest_release_notes',
        'latest_release_url',
        'latest_published_at',
        'last_check_at',
        'last_check_error',
    ];

    private const RELEASES_API = 'https://api.github.com/repos/radekhulan/myinvoice/releases/latest';
    private const HTTP_TIMEOUT = 10;

    private readonly PDO $db;
    private readonly string $rootDir;

    public function __construct(Connection $connection)
    {
        $this->db      = $connection->pdo();
        $this->rootDir = Bootstrap::rootDir();
    }

    public function getCurrentVersion(): string
    {
        $path = $this->rootDir . '/VERSION';
        if (!is_file($path)) {
            return 'unknown';
        }
        $v = trim((string) @file_get_contents($path));
        return $v !== '' ? $v : 'unknown';
    }

    /**
     * Heuristika prostředí. Docker container má `/.dockerenv`, nativní
     * (LAMP/XAMPP/WSL) ne. Některé alternativy (Podman) `/.dockerenv`
     * nemají; detekce není 100% spolehlivá, ale stačí pro UI / volbu
     * upgrade flow.
     */
    public function detectEnvironment(): string
    {
        if (is_file('/.dockerenv') || is_file('/run/.containerenv')) {
            return 'docker';
        }
        return 'native';
    }

    /**
     * Stav verze pro API: aktuální + cache poslední, has_update, last
     * check timestamp / error.
     */
    public function getStatus(): array
    {
        $cache = $this->loadCache();
        $current = $this->getCurrentVersion();
        $latest  = $cache['latest_version'] ?? null;
        $hasUpdate = $latest && $this->isNewer($latest, $current);

        return [
            'current'              => $current,
            'latest'               => $latest,
            'has_update'           => $hasUpdate,
            'release_notes_md'     => $cache['latest_release_notes'] ?? null,
            'release_url'          => $cache['latest_release_url'] ?? null,
            'published_at'         => $cache['latest_published_at'] ?? null,
            'last_check_at'        => $cache['last_check_at'] ?? null,
            'last_check_error'     => $cache['last_check_error'] ?? null,
            'environment'          => $this->detectEnvironment(),
            'upgrade_in_progress'  => $this->isUpgradeInProgress(),
            'last_upgrade_result'  => $this->loadUpgradeResult(),
        ];
    }

    /**
     * Refresh cache z GitHub Releases API. Volá cron + admin „Zkontrolovat
     * teď" tlačítko. Vrací updated status (post-fetch).
     */
    public function refreshLatestVersion(): array
    {
        try {
            $data = $this->fetchLatestRelease();
            $tag = ltrim((string) ($data['tag_name'] ?? ''), 'v');
            if ($tag === '') {
                throw new \RuntimeException('GitHub release neobsahuje tag_name.');
            }
            $this->saveCache([
                'latest_version'       => $tag,
                'latest_release_notes' => (string) ($data['body'] ?? ''),
                'latest_release_url'   => (string) ($data['html_url'] ?? ''),
                'latest_published_at'  => (string) ($data['published_at'] ?? ''),
                'last_check_at'        => date(\DateTimeInterface::ATOM),
                'last_check_error'     => '',
            ]);
        } catch (\Throwable $e) {
            $this->saveCache([
                'last_check_at'    => date(\DateTimeInterface::ATOM),
                'last_check_error' => $e->getMessage(),
            ]);
        }
        return $this->getStatus();
    }

    /**
     * Trigger upgrade. Pro Docker zapíše flag soubor — host-side watcher
     * (`cmd/docker-update-watcher.{sh,ps1}`) ho zachytí a spustí
     * `docker-update.{sh,ps1}`. Pro nativní zatím vrací instrukci.
     *
     * @return array<string,mixed>  result se status / message / instruction
     */
    public function triggerUpgrade(?string $targetVersion, string $requestedByEmail): array
    {
        $env = $this->detectEnvironment();
        $latest = $this->loadCache()['latest_version'] ?? null;
        $target = $targetVersion ?: $latest;

        if (!$target) {
            return [
                'status'  => 'error',
                'message' => 'Není dostupná žádná novější verze. Spusť nejdřív refresh.',
            ];
        }

        if ($env === 'docker') {
            $flag = $this->upgradeFlagPath();
            $payload = [
                'target_version'      => $target,
                'requested_by_email'  => $requestedByEmail,
                'requested_at'        => date(\DateTimeInterface::ATOM),
            ];
            if (!is_dir(dirname($flag))) {
                @mkdir(dirname($flag), 0755, true);
            }
            $ok = @file_put_contents($flag, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if ($ok === false) {
                return [
                    'status'  => 'error',
                    'message' => 'Nelze zapsat flag soubor pro upgrade ('
                        . $flag . '). Zkontroluj práva storage/.',
                ];
            }
            // Smaž starý result, ať UI ukáže "in progress"
            @unlink($this->upgradeResultPath());
            return [
                'status'         => 'queued',
                'message'        => 'Požadavek na upgrade na v' . $target . ' byl zařazen. '
                    . 'Aplikuje host-side watcher (cmd/docker-update-watcher.{sh,ps1}).',
                'environment'    => 'docker',
                'target_version' => $target,
            ];
        }

        // Nativní auto-update zatím přes copy-paste instrukci. Phase 2
        // doplní download production bundle z GitHub release a extrakci.
        return [
            'status'      => 'manual_required',
            'environment' => 'native',
            'target_version' => $target,
            'message'     => 'Pro nativní instalaci spusť na hostu:',
            'instructions' => [
                'git fetch --tags',
                'git checkout v' . $target,
                'cd api && composer install --no-dev && cd ..',
                'cd web && pnpm install && pnpm build && cd ..',
                'php tools/generateManualHtml.php',
                'php tools/exportManualToPdf.php',
                'php api/bin/migrate.php',
            ],
        ];
    }

    /** Watcher / docker-update.{sh,ps1} píše result sem po dokončení. */
    public function loadUpgradeResult(): ?array
    {
        $path = $this->upgradeResultPath();
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function isUpgradeInProgress(): bool
    {
        return is_file($this->upgradeFlagPath());
    }

    public function upgradeFlagPath(): string
    {
        return $this->stateBaseDir() . '/storage/upgrade-requested.json';
    }

    public function upgradeResultPath(): string
    {
        return $this->stateBaseDir() . '/storage/upgrade-result.json';
    }

    /**
     * Pokud je nastavená MYINVOICE_DATA_DIR, ukládáme upgrade flag/result tam
     * (zbytek kontejneru může být read-only). Jinak fallback na rootDir, aby
     * starší docker-compose setupy s `app-storage:/var/www/html/storage`
     * volume zůstaly funkční.
     */
    private function stateBaseDir(): string
    {
        return Config::resolveDataDir() ?? $this->rootDir;
    }

    // ---------- internals ------------------------------------------------

    private function loadCache(): array
    {
        $stmt = $this->db->prepare('SELECT k, v FROM app_meta WHERE k IN ('
            . implode(',', array_fill(0, count(self::META_KEYS), '?'))
            . ')');
        $stmt->execute(self::META_KEYS);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            $out[$k] = $v;
        }
        return $out;
    }

    /** @param array<string,string> $kv */
    private function saveCache(array $kv): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO app_meta (k, v) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE v = VALUES(v)'
        );
        foreach ($kv as $k => $v) {
            $stmt->execute([$k, (string) $v]);
        }
    }

    /** @return array<string,mixed> */
    private function fetchLatestRelease(): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: MyInvoice.cz/version-check\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents(self::RELEASES_API, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('GitHub Releases API neodpovídá (timeout nebo network error).');
        }
        // $http_response_header (magic global)
        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('#^HTTP/\S+\s+(\d+)#', $statusLine, $m) || (int) $m[1] >= 400) {
            throw new \RuntimeException('GitHub Releases API vrátil ' . $statusLine);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('GitHub Releases API vrátil ne-JSON odpověď.');
        }
        return $data;
    }

    /** Porovnání semver — vrátí true pokud $a > $b. */
    private function isNewer(string $a, string $b): bool
    {
        return version_compare($a, $b, '>');
    }
}
