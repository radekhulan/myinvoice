<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Bootstrap;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin CRUD pro email šablony:
 *   GET    /api/admin/email-templates                   — list (DB + file defaults)
 *   GET    /api/admin/email-templates/{code}/{locale}   — detail (DB nebo defaultní soubor)
 *   PUT    /api/admin/email-templates/{code}/{locale}   — upsert override
 *   DELETE /api/admin/email-templates/{code}/{locale}   — smaž override (vrátí na default)
 */
final class EmailTemplateAction
{
    /**
     * Známé kódy šablon — fix list, ne dynamický.
     * Při přidání nového typu emailu rozšířit zde a v api/templates/email/.
     */
    private const KNOWN = ['invoice_send', 'invoice_payment_thanks', 'invoice_reminder', 'proforma_reminder', 'invoice_approval', 'recurring_draft_reminder', 'password_reset', 'login_otp', 'email_profile_test', 'work_report_link', 'work_report_access_code'];
    private const LOCALES = ['cs', 'en'];

    public function __construct(
        private readonly EmailTemplateRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Mailer $mailer,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;

        $byKey = [];
        foreach ($this->repo->listAll() as $row) {
            $byKey[$row['code'] . '.' . $row['locale']] = [
                'has_override' => true,
                'updated_at'   => $row['updated_at'],
            ];
        }

        $rows = [];
        foreach (self::KNOWN as $code) {
            foreach (self::LOCALES as $locale) {
                $key = "$code.$locale";
                $rows[] = [
                    'code'         => $code,
                    'locale'       => $locale,
                    'has_override' => $byKey[$key]['has_override'] ?? false,
                    'updated_at'   => $byKey[$key]['updated_at'] ?? null,
                ];
            }
        }
        return Json::ok($response, ['data' => $rows]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $code = (string) ($args['code'] ?? '');
        $locale = (string) ($args['locale'] ?? '');
        if (!in_array($code, self::KNOWN, true) || !in_array($locale, self::LOCALES, true)) {
            return Json::error($response, 'not_found', 'Šablona neexistuje.', 404);
        }

        $tpl = $this->repo->find($code, $locale);
        $defaults = $this->loadDefaults($code, $locale);

        return Json::ok($response, [
            'code'      => $code,
            'locale'    => $locale,
            'subject'   => $tpl['subject']   ?? $defaults['subject'],
            'body_html' => $tpl['body_html'] ?? $defaults['body_html'],
            'body_text' => $tpl['body_text'] ?? $defaults['body_text'],
            'has_override' => $tpl !== null,
            'updated_at'   => $tpl['updated_at'] ?? null,
            'defaults'  => $defaults, // pro „Reset na default" tlačítko v UI
        ]);
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $code = (string) ($args['code'] ?? '');
        $locale = (string) ($args['locale'] ?? '');
        if (!in_array($code, self::KNOWN, true) || !in_array($locale, self::LOCALES, true)) {
            return Json::error($response, 'not_found', 'Šablona neexistuje.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $subject  = trim((string) ($body['subject']   ?? ''));
        $bodyHtml = (string) ($body['body_html'] ?? '');
        $bodyText = (string) ($body['body_text'] ?? '');

        if ($subject === '')  return Json::error($response, 'validation_failed', 'Chybí subject.', 400);
        if ($bodyHtml === '') return Json::error($response, 'validation_failed', 'Chybí body_html.', 400);
        if ($bodyText === '') return Json::error($response, 'validation_failed', 'Chybí body_text.', 400);

        // Pre-render přes sandbox — zachytíme nepovolené tagy/filtry/syntax dřív,
        // než user pošle email a uvidí runtime crash (issue #25 follow-up).
        $validation = $this->mailer->validateUserTemplate($bodyHtml, $bodyText);
        if ($validation !== null) {
            return Json::error($response, 'validation_failed', $validation['message'], 400, [
                'field' => $validation['field'],
            ]);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->repo->save($code, $locale, $subject, $bodyHtml, $bodyText, isset($user['id']) ? (int) $user['id'] : null);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('email_template.saved', $user['id'] ?? null, 'email_template', null, [
            'code' => $code, 'locale' => $locale,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['saved' => true]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $code = (string) ($args['code'] ?? '');
        $locale = (string) ($args['locale'] ?? '');
        $this->repo->delete($code, $locale);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('email_template.reset', $user['id'] ?? null, 'email_template', null, [
            'code' => $code, 'locale' => $locale,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    private function loadDefaults(string $code, string $locale): array
    {
        $dir = Bootstrap::rootDir() . '/api/templates/email';
        $html = @file_get_contents("$dir/{$code}.{$locale}.html.twig") ?: '';
        $text = @file_get_contents("$dir/{$code}.{$locale}.txt.twig") ?: '';
        return [
            'subject'   => $this->defaultSubject($code, $locale),
            'body_html' => $html,
            'body_text' => $text,
        ];
    }

    private function defaultSubject(string $code, string $locale): string
    {
        $cs = [
            'invoice_send'      => 'Faktura {{ invoice.varsymbol }}',
            'invoice_payment_thanks' => 'Děkujeme za úhradu faktury {{ invoice.varsymbol }}',
            'invoice_reminder'  => 'Upomínka — faktura {{ invoice.varsymbol }} ({{ days_overdue }} dní po splatnosti)',
            'proforma_reminder' => 'Připomínka — záloha {{ invoice.varsymbol }} ({{ days_overdue }} dní po splatnosti)',
            'invoice_approval'  => 'Žádost o schválení výkazu práce ({{ invoice.varsymbol_or_id }})',
            'recurring_draft_reminder' => 'Koncept pravidelné faktury se brzy vystaví ({{ issue_date }})',
            'password_reset'    => 'Obnova hesla',
            'login_otp'         => 'Ověřovací kód pro přihlášení',
            'email_profile_test'=> 'Test odesílacího profilu',
            'work_report_link'  => 'Náhled na výkaz práce — MyInvoice.cz',
            'work_report_access_code' => 'Ověřovací kód pro náhled výkazu práce — MyInvoice.cz',
        ];
        $en = [
            'invoice_send'      => 'Invoice {{ invoice.varsymbol }}',
            'invoice_payment_thanks' => 'Thank you for your payment — invoice {{ invoice.varsymbol }}',
            'invoice_reminder'  => 'Reminder — invoice {{ invoice.varsymbol }} ({{ days_overdue }} days overdue)',
            'proforma_reminder' => 'Reminder — proforma {{ invoice.varsymbol }} ({{ days_overdue }} days overdue)',
            'invoice_approval'  => 'Work report — please approve ({{ invoice.varsymbol_or_id }})',
            'recurring_draft_reminder' => 'Recurring invoice draft will be issued soon ({{ issue_date }})',
            'password_reset'    => 'Password reset',
            'login_otp'         => 'Sign-in verification code',
            'email_profile_test'=> 'Sending profile test',
            'work_report_link'  => 'Work report preview — MyInvoice.cz',
            'work_report_access_code' => 'Verification code for work report preview — MyInvoice.cz',
        ];
        return ($locale === 'en' ? $en : $cs)[$code] ?? '';
    }

    private function guard(Request $request, Response $response, ?Response &$err): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);
            return false;
        }
        $err = null;
        return true;
    }
}
