<?php

declare(strict_types=1);

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = (string) ($argv[1] ?? '');
$rootDir = (string) ($argv[2] ?? '');
$barrier = (string) ($argv[3] ?? '');
$arguments = array_slice($argv, 4);
$loaded = Config::load($rootDir)->all();
$loaded['redis']['enabled'] = false;
$config = new Config($loaded);
$db = new Connection($config);

// Připravenost hlásíme souborem, ne stdoutem: `stream_set_blocking(false)` je
// pro proc_open pipe na Windows no-op, takže rodič READY nepřečte dřív, než
// worker skončí — handshake by tam nikdy nedoběhl. Soubor funguje všude stejně.
@touch(dirname($barrier) . DIRECTORY_SEPARATOR . 'ready-' . getmypid());
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$deadline = microtime(true) + 10;
// is_file() čte PHP stat cache, která si pamatuje i negativní výsledek. Bez
// invalidace se barrier vytvořený jiným procesem nikdy neobjeví a worker vždy
// vyprší — proto tyhle dva testy nikdy nic neověřily.
clearstatcache(true, $barrier);
while (!is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDOUT, "{\"ok\":false,\"error\":\"barrier_timeout\"}\n");
        exit(2);
    }
    usleep(1_000);
    clearstatcache(true, $barrier);
}

try {
    if ($mode === 'rotate') {
        [$sessionToken, $credentialId] = $arguments;
        $sessions = new SessionManager(
            $db,
            $config,
            new DatabaseSecurityClock(),
        );
        $sessions->rotateLocked($sessionToken, 'passkey', (int) $credentialId);
    } elseif ($mode === 'consume') {
        [$flowToken, $purpose, $userId, $sessionToken] = $arguments;
        $ceremonies = new WebAuthnCeremonyStore($db, new DatabaseSecurityClock());
        $ceremonies->consume(
            $flowToken,
            $purpose,
            (int) $userId,
            $sessionToken !== '-' ? $sessionToken : null,
            null,
        );
    } else {
        throw new InvalidArgumentException('Unknown concurrency worker mode.');
    }
    fwrite(STDOUT, "{\"ok\":true}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'error' => $e::class,
    ], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
} finally {
    $db->close();
}
