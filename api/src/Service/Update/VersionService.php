<?php

declare(strict_types=1);

namespace MyInvoice\Service\Update;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\BackgroundProcess;
use PDO;

/**
 * Version service — kontrola nové verze, cache release notes, detekce
 * runtime prostředí (Docker / nativní), spouštění upgradu.
 *
 * Upgrade sám neprovádí: Docker deleguje na host-side watcher, nativní
 * instalace na {@see NativeUpdateService} (production bundle z release).
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

    public function __construct(
        Connection $connection,
        private readonly NativeUpdateService $native,
        private readonly Config $config,
    ) {
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
     * Smí aplikace upgrade spustit sama (Docker watcher / nativní worker)?
     * Vypíná se tam, kde nasazení nové verze řídí platforma (Coolify, Portainer,
     * Kubernetes…) — tlačítko by jen zapsalo flag, který nikdo nezpracuje.
     */
    public function isInAppUpgradeEnabled(): bool
    {
        return (bool) $this->config->get('updates.in_app_upgrade', true);
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

        // Cache je stale, pokud latest < current (instalace byla mezitím upgradnutá),
        // nebo last_check_at je víc než 24h staré, nebo chybí. Frontend pak může
        // background-spustit refresh, takže native instalace bez cronu nezůstanou
        // s neaktuální cache donekonečna.
        $lastCheckAt = $cache['last_check_at'] ?? null;
        $cacheStale = false;
        if ($latest && $current !== 'unknown' && $this->isNewer($current, $latest)) {
            // Nesmyslný stav (cache < instalace) — ignoruj cached latest úplně.
            $latest = null;
            $cache['latest_release_notes'] = null;
            $cache['latest_release_url']   = null;
            $cache['latest_published_at']  = null;
            $cacheStale = true;
        }
        if (!$lastCheckAt) {
            $cacheStale = true;
        } else {
            $age = time() - (int) strtotime((string) $lastCheckAt);
            if ($age > 86400) $cacheStale = true;
        }

        $hasUpdate = $latest && $this->isNewer($latest, $current);

        return [
            'current'              => $current,
            'latest'               => $latest,
            'has_update'           => $hasUpdate,
            'release_notes_md'     => $cache['latest_release_notes'] ?? null,
            'release_url'          => $cache['latest_release_url'] ?? null,
            'published_at'         => $cache['latest_published_at'] ?? null,
            'last_check_at'        => $lastCheckAt,
            'last_check_error'     => $cache['last_check_error'] ?? null,
            'cache_stale'          => $cacheStale,
            'environment'          => $this->detectEnvironment(),
            'in_app_upgrade'       => $this->isInAppUpgradeEnabled(),
            'upgrade_in_progress'  => $this->isUpgradeInProgress(),
            'upgrade_progress'     => $this->loadUpgradeProgress(),
            'last_upgrade_result'  => $this->loadUpgradeResult(),
        ];
    }

    /**
     * Krok právě běžícího nativního upgradu (worker ho píše do flag souboru).
     * Docker flow tohle neplní — vrátí null. Záměrně bez preflight kontrol,
     * aby polling statusu nesahal na disk víc, než musí.
     *
     * @return array{mode:string, step:string, step_index:int, step_count:int, step_message:string}|null
     */
    public function loadUpgradeProgress(): ?array
    {
        $flag = $this->upgradeFlagPath();
        if (!is_file($flag)) {
            return null;
        }
        $payload = json_decode((string) @file_get_contents($flag), true);
        if (!is_array($payload) || !isset($payload['step'])) {
            return null;
        }

        return [
            'mode'         => (string) ($payload['mode'] ?? 'native'),
            'step'         => (string) $payload['step'],
            'step_index'   => (int) ($payload['step_index'] ?? 0),
            'step_count'   => (int) ($payload['step_count'] ?? count(NativeUpdateService::STEPS)),
            'step_message' => (string) ($payload['step_message'] ?? ''),
        ];
    }

    /**
     * Preflight nativního upgradu — samostatný endpoint, protože zapisuje
     * probe soubory (kontrola práv) a nemá běžet při každém pollu statusu.
     *
     * @return array{ok:bool, supported:bool, blockers:list<string>, warnings:list<string>}
     */
    public function nativePreflight(?string $targetVersion = null): array
    {
        if ($this->detectEnvironment() !== 'native') {
            return [
                'ok'        => false,
                'supported' => false,
                'blockers'  => ['Nativní updater se v Docker instalaci nepoužívá — upgrade řeší host-side watcher.'],
                'warnings'  => [],
            ];
        }
        $target = $targetVersion ?: ($this->loadCache()['latest_version'] ?? null);
        if (!$target) {
            return [
                'ok'        => false,
                'supported' => true,
                'blockers'  => ['Není známá žádná novější verze — spusť nejdřív kontrolu.'],
                'warnings'  => [],
            ];
        }

        return $this->native->preflight((string) $target);
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
     * `docker-update.{sh,ps1}`. Pro nativní instalaci spustí detached worker
     * `api/bin/native-update.php`, který nasadí production bundle z release
     * (viz {@see NativeUpdateService}); když to prostředí neumožní, vrátí
     * copy-paste návod se stejným bundlem.
     *
     * @return array<string,mixed>  result se status / message / instruction
     */
    public function triggerUpgrade(?string $targetVersion, string $requestedByEmail): array
    {
        if (!$this->isInAppUpgradeEnabled()) {
            return [
                'status'  => 'error',
                'message' => 'Aktualizace z aplikace je vypnutá (updates.in_app_upgrade) — '
                    . 'novou verzi nasaď přes svou platformu.',
            ];
        }

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

        // Nativní instalace — stáhneme production bundle z release a nasadíme ho
        // detached CLI workerem. Když to prostředí neumožní (chybí PHP CLI, práva,
        // místo…), vrátíme copy-paste návod se stejným bundlem.
        if (!NativeUpdateService::isValidVersion((string) $target)) {
            return [
                'status'  => 'error',
                'message' => 'Cílová verze „' . $target . '" není platný semver.',
            ];
        }

        $preflight = $this->native->preflight((string) $target);
        if (!$preflight['ok']) {
            return $this->nativeManualFallback((string) $target, $preflight['blockers']);
        }

        $flag = $this->upgradeFlagPath();
        if (!is_dir(dirname($flag))) {
            @mkdir(dirname($flag), 0775, true);
        }
        $written = @file_put_contents($flag, json_encode([
            'mode'               => 'native',
            'target_version'     => $target,
            'requested_by_email' => $requestedByEmail,
            'requested_at'       => date(\DateTimeInterface::ATOM),
            'heartbeat_at'       => date(\DateTimeInterface::ATOM),
            'step'               => 'preflight',
            'step_index'         => 1,
            'step_count'         => count(NativeUpdateService::STEPS),
            'step_message'       => 'Startuji aktualizaci…',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($written === false) {
            return $this->nativeManualFallback((string) $target, [
                'Nelze zapsat ' . $flag . ' — zkontroluj práva na storage/.',
            ]);
        }
        @unlink($this->upgradeResultPath());

        // Bez workera by se spawn „povedl" a UI by pak čekalo na heartbeat,
        // který nikdy nepřijde — radši rovnou ruční návod.
        $worker = $this->rootDir . '/api/bin/native-update.php';
        if (!is_file($worker)) {
            @unlink($flag);
            return $this->nativeManualFallback((string) $target, [
                'Chybí ' . $worker . ' — instalaci schází update worker, nasaď bundle ručně podle návodu níž.',
            ]);
        }

        $spawned = BackgroundProcess::spawnPhp(
            $worker,
            ['--target=' . $target, '--requested-by=' . $requestedByEmail],
            null,
            $this->rootDir,
            $diag,
        );
        if (!$spawned) {
            @unlink($flag);
            return $this->nativeManualFallback((string) $target, [
                'Nepodařilo se spustit worker na pozadí (' . (string) $diag . ').',
            ]);
        }

        return [
            'status'         => 'queued',
            'environment'    => 'native',
            'target_version' => $target,
            'message'        => 'Aktualizace na v' . $target . ' běží na pozadí — stahuje se production bundle, '
                . 'nasadí se přes instalaci a doběhnou migrace. Průběh se ukazuje níž.',
            'warnings'       => $preflight['warnings'],
        ];
    }

    /**
     * Fallback, když automatická cesta nejde — přesné příkazy pro ruční
     * nasazení téhož production bundlu.
     *
     * @param  list<string> $blockers
     * @return array<string,mixed>
     */
    private function nativeManualFallback(string $target, array $blockers): array
    {
        $bundle = 'myinvoice-' . $target . '.tar.gz';
        $base   = 'https://github.com/radekhulan/myinvoice/releases/download/v' . $target . '/';

        return [
            'status'         => 'manual_required',
            'environment'    => 'native',
            'target_version' => $target,
            'message'        => 'Automatickou aktualizaci nelze spustit, proveď ji na hostu ručně:',
            'blockers'       => $blockers,
            'instructions'   => [
                '# Production bundle — nepotřebuje Composer ani Node',
                'curl -LO ' . $base . $bundle,
                'curl -LO ' . $base . $bundle . '.sha256',
                'sha256sum -c ' . $bundle . '.sha256',
                'tar -xzf ' . $bundle . ' --strip-components=1 \\',
                "  --exclude='cfg.php' --exclude='cfg.local.php' --exclude='cfg.docker.php' \\",
                "  --exclude='storage' --exclude='private' --exclude='log'",
                'php api/bin/migrate.php',
            ],
        ];
    }

    /**
     * Watcher / docker-update.{sh,ps1} píše result sem po dokončení.
     *
     * Self-healing jako u flagu: výsledek, který nasazená verze už překonala,
     * se zahodí — stejně jako nepotvrzený (`unknown`) výsledek, jehož cílová
     * verze je nasazená. Jinak by po ručním / platformou řízeném upgradu viselo
     * „Upgrade nepotvrzen" pro starou cílovou verzi navždy. `failed` při shodě
     * verzí zůstává: Docker může vyměnit image a pak selhat na migraci.
     */
    public function loadUpgradeResult(): ?array
    {
        $path = $this->upgradeResultPath();
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return null;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return null;

        $target  = isset($decoded['target_version']) ? (string) $decoded['target_version'] : '';
        $current = $this->getCurrentVersion();
        if ($target !== '' && $current !== 'unknown') {
            $cmp = version_compare($current, $target);
            if ($cmp > 0 || ($cmp === 0 && ($decoded['status'] ?? null) === 'unknown')) {
                @unlink($path);
                return null;
            }
        }

        return $decoded;
    }

    /** Po kolika sekundách považujeme nezpracovaný upgrade flag za prošlý (watcher neběží/spadl). */
    private const UPGRADE_FLAG_TTL = 900; // 15 min

    /**
     * Probíhá upgrade? Self-healing: samotná existence flag souboru nestačí —
     * flag se zruší (a vrátí false), pokud:
     *   a) je cílová verze už nasazená (current >= target) — upgrade proběhl mimo
     *      aplikaci, typicky ručně přes terminál, takže flag nikdo nezpracoval, nebo
     *   b) flag je starší než TTL — host-side watcher zřejmě neběží/spadl.
     * Tím se UI nezasekne na „Upgrade probíhá…" donekonečna.
     */
    public function isUpgradeInProgress(): bool
    {
        $flag = $this->upgradeFlagPath();
        if (!is_file($flag)) {
            return false;
        }

        $payload = json_decode((string) @file_get_contents($flag), true);
        $payload = is_array($payload) ? $payload : [];
        $target  = isset($payload['target_version']) ? (string) $payload['target_version'] : null;
        $current = $this->getCurrentVersion();

        // a) cílová verze už nasazená → upgrade hotový (mimo watcher). Flag tiše zruš.
        if ($target !== null && $current !== 'unknown' && version_compare($current, $target, '>=')) {
            @unlink($flag);
            return false;
        }

        // b) prošlý flag → zpracovatel (docker watcher / nativní worker) neběží.
        // Nativní worker píše `heartbeat_at` na každém kroku, takže dlouhý běh
        // (stahování bundlu, kopírování 10k souborů, migrace) TTL nepodtrhne;
        // rozhoduje poslední známá aktivita, ne čas zařazení.
        $isNative    = ($payload['mode'] ?? null) === 'native';
        $activityTs  = isset($payload['heartbeat_at']) ? strtotime((string) $payload['heartbeat_at']) : false;
        if ($activityTs === false) {
            $activityTs = isset($payload['requested_at']) ? strtotime((string) $payload['requested_at']) : false;
        }
        if ($activityTs === false) {
            $activityTs = @filemtime($flag) ?: time();
        }
        if (time() - $activityTs > self::UPGRADE_FLAG_TTL) {
            @unlink($flag);
            // Informativní result, ať uživatel ví, proč to skončilo.
            if (!is_file($this->upgradeResultPath())) {
                @file_put_contents($this->upgradeResultPath(), json_encode([
                    'status'         => 'unknown',
                    'target_version' => $target,
                    'applied_at'     => date(\DateTimeInterface::ATOM),
                    'message'        => $isNative
                        ? 'Aktualizace se přestala hlásit — worker (api/bin/native-update.php) zřejmě spadl. '
                            . 'Projdi log storage/upgrade-*.log; nasazené soubory jde vrátit ze zálohy '
                            . 'storage/updates/<verze>/backup/.'
                        : 'Požadavek na upgrade vypršel — dokončení se nepodařilo potvrdit. '
                            . 'Pokud aktualizuješ ručně přes terminál, je to v pořádku; jinak zkontroluj, '
                            . 'že na hostu běží watcher (cmd/docker-update-watcher.{sh,ps1}).',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            return false;
        }

        return true;
    }

    /**
     * Ruční zrušení zaseknutého upgrade flagu (UI tlačítko). Použije se, když
     * upgrade proběhl mimo aplikaci nebo watcher neběží a uživatel nechce čekat na TTL.
     *
     * @return array{status:string, cleared:bool}
     */
    public function cancelUpgrade(): array
    {
        $flag = $this->upgradeFlagPath();
        $existed = is_file($flag);
        @unlink($flag);
        return ['status' => 'ok', 'cleared' => $existed];
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
