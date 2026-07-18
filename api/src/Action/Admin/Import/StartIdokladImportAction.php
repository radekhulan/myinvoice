<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\IdokladClient;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/imports/idoklad/start
 *
 * Body: { include_bank_accounts?: bool, include_bank_transactions?: bool, include_clients?: bool, include_issued?: bool, include_received?: bool, include_receipts?: bool, dry_run?: bool }
 *
 * Vytvoří import_jobs řádek se status='queued' a spustí background worker
 * (detached process — Windows DETACHED_PROCESS, Linux nohup). UI pak polluje
 * status přes StatusAction.
 *
 * Pre-check: anti-double-import. Pokud existuje běžící iDoklad job pro daný
 * tenant (status='running' / 'queued'), vrátí 409. UX: tlačítko Start v UI
 * je disabled když poslední job je running.
 */
final class StartIdokladImportAction
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly IdokladClient $idoklad,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        // Credentials check — bez nich nemá smysl spustit
        $creds = $this->idoklad->getCredentials($supplierId);
        if ($creds === null) {
            return Json::error($response, 'no_credentials',
                'iDoklad credentials nejsou nastaveny. Nastav je v Nastavení → Externí integrace.', 400);
        }

        // Ukliď zaseknuté joby (mrtvý worker), jinak by navždy blokovaly nový start.
        $this->jobs->reapStale($supplierId, 'idoklad');

        // Anti-double-import: již běžící job?
        foreach ($this->jobs->listForTenant($supplierId, 'idoklad', limit: 5) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true)) {
                return Json::error($response, 'already_running',
                    "iDoklad import už běží (job #{$existing['id']}, status: {$existing['status']}).",
                    409,
                    ['existing_job_id' => $existing['id']],
                );
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $params = [
            'include_bank_accounts'=> $body['include_bank_accounts'] ?? true,
            'include_bank_transactions' => !empty($body['include_bank_transactions']),
            'include_clients'      => $body['include_clients']      ?? true,
            'include_issued'       => $body['include_issued']       ?? true,
            'include_received'     => $body['include_received']     ?? true,
            'include_receipts'     => $body['include_receipts']     ?? true,
            'incremental'          => !empty($body['incremental']),
            'download_attachments' => !empty($body['download_attachments']),
            'dry_run'              => !empty($body['dry_run']),
        ];

        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($supplierId, 'idoklad', $params, $userId);

        // Spawn detached background worker.
        $this->spawnWorker($jobId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.idoklad_started', $userId, 'import_job', $jobId, $params,
            $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'job_id' => $jobId,
            'status' => 'queued',
            'params' => $params,
        ], 201);
    }

    /**
     * Detached spawn — Windows přes proc_open DETACHED_PROCESS, Linux přes nohup.
     * Worker pak běží nezávisle na request lifecycle. Per memory:
     * feedback_windows_paths — Windows path handling se liší od Linux.
     */
    private function spawnWorker(int $jobId): void
    {
        // Stejný ověřený mechanismus jako admin/cron-jobs (BackgroundProcess).
        // POZOR: root z Bootstrap::rootDir(), NE dirname(__DIR__, 4) — to z
        // api/src/Action/Admin/Import/ ukazovalo na .../api → cesta api/api/bin/…
        // neexistovala a worker se nikdy nespustil (job uvízl "queued").
        $rootDir = \MyInvoice\Bootstrap::rootDir();
        \MyInvoice\Service\BackgroundProcess::spawnPhp(
            $rootDir . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            \MyInvoice\Infrastructure\Config\RuntimePaths::log('import-worker.log'),
            $rootDir,
        );
    }
}
