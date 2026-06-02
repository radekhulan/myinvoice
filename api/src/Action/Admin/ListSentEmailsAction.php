<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/sent-emails
 * Query: ?type=&status=&limit=100&offset=0
 *
 * Přehled odeslaných e-mailů — vyfiltrovaný pohled na activity_log omezený
 * na e-mailové akce, s JOINem na fakturu + klienta a normalizovaným příjemcem
 * vytaženým z payloadu (klíč i typ se mezi akcemi liší: `to` string|array, `recipients` array).
 *
 * Každá akce má „success" i „failed" variantu (viz SENT_TO_FAILED). Selhání se loguje
 * v catch blocích jednotlivých odesílacích cest (SendEmailAction, ReminderService,
 * PaymentThanksMailer, cron-send-approval-reminders, RecurringDraftReminder, test sendy).
 * Řádek tak nese `status` ('sent'|'failed') a u selhání `error`.
 *
 * `?type=` filtruje podle logického typu (= success akce); zahrne i jeho failed variantu.
 * `?status=sent|failed` zúží jen na úspěšné, resp. neúspěšné.
 * Admin only.
 */
final class ListSentEmailsAction
{
    /**
     * Logický typ e-mailu (success akce) → jeho failed varianta v activity_log.
     * Záměrně NEobsahuje `invoice.reminder_sent_bulk` — to je souhrnný audit
     * záznam hromadné akce; jednotlivé upomínky z dávky se logují samostatně
     * jako `invoice.reminder_sent` / `invoice.reminder_failed` (viz ReminderService).
     */
    private const SENT_TO_FAILED = [
        'invoice.sent'                   => 'invoice.send_failed',
        'invoice.reminder_sent'          => 'invoice.reminder_failed',
        'invoice.approval_reminder_sent' => 'invoice.approval_reminder_failed',
        'invoice.payment_thanks_sent'    => 'invoice.payment_thanks_failed',
        'recurring.reminder_sent'        => 'recurring.reminder_failed',
        'email.sent_test'                => 'email.test_failed',
        'email.sent_test_reminder'       => 'email.test_reminder_failed',
    ];

    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $q = $request->getQueryParams();

        $sentActions   = array_keys(self::SENT_TO_FAILED);
        $failedActions = array_values(self::SENT_TO_FAILED);
        $allActions    = [...$sentActions, ...$failedActions];

        // Filtr typu (logický = success akce) → zahrne success i failed variantu.
        $type = (string) ($q['type'] ?? '');
        $actions = $allActions;
        if ($type !== '' && isset(self::SENT_TO_FAILED[$type])) {
            $actions = [$type, self::SENT_TO_FAILED[$type]];
        }

        // Filtr stavu — sent / failed (default = oba).
        $status = (string) ($q['status'] ?? '');
        if ($status === 'sent') {
            $actions = array_values(array_intersect($actions, $sentActions));
        } elseif ($status === 'failed') {
            $actions = array_values(array_intersect($actions, $failedActions));
        }

        $placeholders = implode(',', array_fill(0, count($actions), '?'));

        $limit = max(1, min(500, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        // Faktura se u většiny akcí váže přes entity_id (entity_type='invoice'),
        // ale `recurring.reminder_sent` má entity recurring_template a fakturu
        // nese až v payloadu. Resolvneme oba případy, aby odkaz mířil správně.
        // JSON_UNQUOTE(JSON_EXTRACT(...)) — bez UNQUOTE by JSON_EXTRACT vrátil string
        // v uvozovkách ("561") a JOIN na číselné i.id by tiše nematchnul. (Pozor: MySQL
        // arrow operátor `->>` MariaDB nepodporuje, nutno funkcemi.)
        $sql = "SELECT al.id, al.user_id, u.email AS user_email, u.name AS user_name,
                       al.action, i.id AS invoice_id, al.payload, al.created_at,
                       i.varsymbol AS invoice_varsymbol,
                       c.company_name AS client_company_name
                  FROM activity_log al
             LEFT JOIN users u    ON u.id = al.user_id
             LEFT JOIN invoices i ON i.id = COALESCE(
                       CASE WHEN al.entity_type = 'invoice' THEN al.entity_id END,
                       JSON_UNQUOTE(JSON_EXTRACT(al.payload, '$.invoice_id'))
                   )
             LEFT JOIN clients c  ON c.id = i.client_id
                 WHERE al.action IN ($placeholders)
              ORDER BY al.id DESC
                 LIMIT $limit OFFSET $offset";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($actions);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $failedToSent = array_flip(self::SENT_TO_FAILED);

        $data = [];
        foreach ($rows as $r) {
            $payload = $r['payload'] !== null ? json_decode((string) $r['payload'], true) : [];
            if (!is_array($payload)) $payload = [];
            $action = (string) $r['action'];
            $isFailed = isset($failedToSent[$action]);
            $data[] = [
                'id'                  => (int) $r['id'],
                'action'              => $action,
                // Logický typ = success akce (i u failed řádku) — frontend podle něj
                // vybírá popisek/badge, status řeší zvlášť.
                'type'                => $isFailed ? $failedToSent[$action] : $action,
                'status'              => $isFailed ? 'failed' : 'sent',
                'created_at'          => $r['created_at'],
                'user_name'           => $r['user_name'],
                'user_email'          => $r['user_email'],
                'invoice_id'          => $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null,
                'invoice_varsymbol'   => $r['invoice_varsymbol'],
                'client_company_name' => $r['client_company_name'],
                'recipients'          => $this->extractRecipients($payload),
                'smtp_response'       => isset($payload['smtp_response']) ? (string) $payload['smtp_response'] : null,
                // Chybový text u selhání — různé cesty loggují pod `error` nebo `reason`.
                'error'               => $isFailed ? $this->extractError($payload) : null,
            ];
        }

        // Celkový počet (pro paginaci) — stejný filtr akcí.
        $countSql = "SELECT COUNT(*) FROM activity_log WHERE action IN ($placeholders)";
        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute($actions);
        $total = (int) $countStmt->fetchColumn();

        // Počty per akce přes VŠECHNY e-mailové akce (nezávisle na ?type/?status),
        // složené do logického typu: success + failed dohromady. `failed` drží
        // počet neúspěchů, ať frontend umí zvýraznit/filtrovat.
        $allPlaceholders = implode(',', array_fill(0, count($allActions), '?'));
        $cntStmt = $this->db->pdo()->prepare(
            "SELECT action, COUNT(*) AS cnt FROM activity_log
              WHERE action IN ($allPlaceholders) GROUP BY action"
        );
        $cntStmt->execute($allActions);
        $rawCounts = [];
        foreach ($cntStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rawCounts[(string) $row['action']] = (int) $row['cnt'];
        }
        $types = [];
        $failedTotal = 0;
        foreach (self::SENT_TO_FAILED as $sentAction => $failedAction) {
            $sentCnt = $rawCounts[$sentAction] ?? 0;
            $failCnt = $rawCounts[$failedAction] ?? 0;
            $failedTotal += $failCnt;
            $types[] = [
                'action' => $sentAction,
                'cnt'    => $sentCnt + $failCnt,
                'failed' => $failCnt,
            ];
        }

        return Json::ok($response, [
            'data'         => $data,
            'total'        => $total,
            'limit'        => $limit,
            'offset'       => $offset,
            'types'        => $types,
            'failed_total' => $failedTotal,
        ]);
    }

    /**
     * Příjemce z payloadu — sjednotí různé tvary napříč akcemi do pole adres.
     * `invoice.sent`/`*_reminder_sent` mají `to` jako pole, `email.sent_test` jako string,
     * `invoice.payment_thanks_sent` používá `recipients`.
     *
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private function extractRecipients(array $payload): array
    {
        $raw = $payload['to'] ?? $payload['recipients'] ?? [];
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : '',
            $raw
        ), static fn ($v) => $v !== ''));
    }

    /**
     * Chybový text u failed řádku. Cesty loggují nekonzistentně — SendEmailAction/
     * ReminderService pod `error`, PaymentThanksMailer pod `reason`. Vrátí null,
     * pokud ani jeden není string.
     *
     * @param array<string,mixed> $payload
     */
    private function extractError(array $payload): ?string
    {
        $err = $payload['error'] ?? $payload['reason'] ?? null;
        return is_string($err) && $err !== '' ? $err : null;
    }
}
