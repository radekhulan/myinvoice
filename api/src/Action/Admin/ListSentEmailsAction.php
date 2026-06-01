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
 * Query: ?type=&limit=100&offset=0
 *
 * Přehled odeslaných e-mailů — vyfiltrovaný pohled na activity_log omezený
 * na e-mailové akce, s JOINem na fakturu + klienta a normalizovaným příjemcem
 * vytaženým z payloadu (klíč i typ se mezi akcemi liší: `to` string|array, `recipients` array).
 * Admin only.
 */
final class ListSentEmailsAction
{
    /**
     * Akce z activity_log, které představují jeden odeslaný e-mail.
     * Záměrně NEobsahuje `invoice.reminder_sent_bulk` — to je souhrnný audit
     * záznam hromadné akce; jednotlivé upomínky z dávky se logují samostatně
     * jako `invoice.reminder_sent` (viz BulkSendRemindersAction → ReminderService).
     */
    private const EMAIL_ACTIONS = [
        'invoice.sent',
        'invoice.reminder_sent',
        'invoice.approval_reminder_sent',
        'invoice.payment_thanks_sent',
        'recurring.reminder_sent',
        'email.sent_test',
        'email.sent_test_reminder',
    ];

    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $q = $request->getQueryParams();

        // Vždy omezeno na e-mailové akce; ?type= případně zúží na jednu z nich.
        $actions = self::EMAIL_ACTIONS;
        if (!empty($q['type']) && in_array((string) $q['type'], self::EMAIL_ACTIONS, true)) {
            $actions = [(string) $q['type']];
        }
        $placeholders = implode(',', array_fill(0, count($actions), '?'));

        $limit = max(1, min(500, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        // Faktura se u většiny akcí váže přes entity_id (entity_type='invoice'),
        // ale `recurring.reminder_sent` má entity recurring_template a fakturu
        // nese až v payloadu. Resolvneme oba případy, aby odkaz mířil správně.
        // payload->>'$.invoice_id' (JSON_UNQUOTE) — bez něj by JSON_EXTRACT vrátil
        // string v uvozovkách ("561") a JOIN na číselné i.id by tiše nematchnul.
        $sql = "SELECT al.id, al.user_id, u.email AS user_email, u.name AS user_name,
                       al.action, i.id AS invoice_id, al.payload, al.created_at,
                       i.varsymbol AS invoice_varsymbol,
                       c.company_name AS client_company_name
                  FROM activity_log al
             LEFT JOIN users u    ON u.id = al.user_id
             LEFT JOIN invoices i ON i.id = COALESCE(
                       CASE WHEN al.entity_type = 'invoice' THEN al.entity_id END,
                       al.payload->>'$.invoice_id'
                   )
             LEFT JOIN clients c  ON c.id = i.client_id
                 WHERE al.action IN ($placeholders)
              ORDER BY al.id DESC
                 LIMIT $limit OFFSET $offset";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($actions);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $data = [];
        foreach ($rows as $r) {
            $payload = $r['payload'] !== null ? json_decode((string) $r['payload'], true) : [];
            $data[] = [
                'id'                  => (int) $r['id'],
                'action'              => $r['action'],
                'created_at'          => $r['created_at'],
                'user_name'           => $r['user_name'],
                'user_email'          => $r['user_email'],
                'invoice_id'          => $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null,
                'invoice_varsymbol'   => $r['invoice_varsymbol'],
                'client_company_name' => $r['client_company_name'],
                'recipients'          => $this->extractRecipients(is_array($payload) ? $payload : []),
                'smtp_response'       => isset($payload['smtp_response']) ? (string) $payload['smtp_response'] : null,
            ];
        }

        // Celkový počet (pro paginaci) — stejný filtr akcí.
        $countSql = "SELECT COUNT(*) FROM activity_log WHERE action IN ($placeholders)";
        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute($actions);
        $total = (int) $countStmt->fetchColumn();

        // Počty per typ (pro filtr dropdown) — vždy přes všechny e-mailové akce, nezávisle na ?type.
        $allPlaceholders = implode(',', array_fill(0, count(self::EMAIL_ACTIONS), '?'));
        $typesStmt = $this->db->pdo()->prepare(
            "SELECT action, COUNT(*) AS cnt FROM activity_log
              WHERE action IN ($allPlaceholders) GROUP BY action ORDER BY action"
        );
        $typesStmt->execute(self::EMAIL_ACTIONS);
        $types = $typesStmt->fetchAll(\PDO::FETCH_ASSOC);

        return Json::ok($response, [
            'data'   => $data,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
            'types'  => $types,
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
}
