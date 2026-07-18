<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementImporter;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Bank\StatementScanner;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Bank statement endpoints (M5b).
 *
 *   POST   /api/bank-statements/upload         multipart file=...
 *   POST   /api/bank-statements/upload-pdf     multipart file=... (banky bez GPC — Creditas)
 *   GET    /api/bank-statements                list
 *   GET    /api/bank-statements/{id}           detail (+ transactions)
 *   POST   /api/bank-transactions/{id}/match   { invoice_id }  manual match
 *   POST   /api/bank-transactions/{id}/ignore  mark as ignored
 *   POST   /api/bank-transactions/{id}/unmatch reset back to unmatched
 */
final class BankStatementAction
{
    /** Absolutní tolerance shody částky ve stejné měně (měna faktury). */
    private const CANDIDATE_AMOUNT_TOLERANCE = 1.0;
    /** Relativní tolerance pro cross-currency shodu (CZK platba cizoměnové faktury) —
     *  banka si bere spread klidně ~2 % a kurz se za pár dní pohne, takže 4 % dává
     *  rezervu, aby se kandidáti reálně našli. */
    private const CANDIDATE_FX_TOLERANCE_PCT = 0.04;
    /** Okno ±N dní kolem data transakce (issue_date nebo due_date faktury). */
    private const CANDIDATE_DAY_WINDOW = 14;
    /** Fallback okno, když v CANDIDATE_DAY_WINDOW nic nesedí — širší rozsah + povolí
     *  i shodu bez převodu měny (viz searchMatchCandidates $allowRawAmountFallback). */
    private const CANDIDATE_FALLBACK_DAY_WINDOW = 90;

    /** Sloučená úhrada (split): výchozí okno ±N dní pro hledání kombinací (uživatel může rozšířit). */
    private const SPLIT_DAY_WINDOW = 7;
    /** Sloučená úhrada: horní mez okna, kterou smí uživatel zvolit. */
    private const SPLIT_DAY_WINDOW_MAX = 60;
    /** Sloučená úhrada: max počet faktur v jedné kombinaci (omezení subset-sum). */
    private const SPLIT_MAX_INVOICES = 6;
    /** Sloučená úhrada: max velikost poolu faktur na klienta (omezení kombinatoriky). */
    private const SPLIT_POOL_PER_CLIENT = 14;
    /** Sloučená úhrada: max počet vrácených návrhů kombinací. */
    private const SPLIT_MAX_SUGGESTIONS = 8;

    public function __construct(
        private readonly Connection $db,
        private readonly StatementImporter $importer,
        private readonly StatementMatcher $matcher,
        private readonly StatementScanner $scanner,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoiceRepository $invoices,
        private readonly GpcParser $parser,
        private readonly FinalFromProformaCreator $finalCreator,
        private readonly \MyInvoice\Repository\PurchaseInvoiceRepository $purchaseRepo,
        private readonly \MyInvoice\Service\Invoice\PurchaseInvoiceCalculator $purCalc,
        private readonly \MyInvoice\Service\Mail\PaymentThanksMailer $paymentThanks,
        private readonly \MyInvoice\Service\Invoice\InvoicePaymentService $payments,
        private readonly \MyInvoice\Service\Invoice\PaymentTaxDocumentCreator $taxDocCreator,
        private readonly \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry $pdfParsers,
    ) {}

    public function scan(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $root = trim((string) $this->config->get('bank_import.scan_root', ''));
        if (!$this->scanConfigured()) {
            return Json::error($response, 'config_missing', 'cfg.bank_import.scan_root není nastaveno nebo adresář neexistuje.', 400);
        }
        $summary = $this->scanner->scan($root);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.scanned', $user['id'] ?? null, null, null, $summary, $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, $summary);
    }

    /**
     * Je adresářové skenování bankovních výpisů nakonfigurované? (cfg.bank_import.scan_root
     * nastaveno na existující adresář). UI podle toho zobrazuje tlačítko „Skenovat adresář".
     */
    private function scanConfigured(): bool
    {
        $root = trim((string) $this->config->get('bank_import.scan_root', ''));
        return $root !== '' && is_dir($root);
    }

    public function upload(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Soubor chybí.', 400);
        }

        // Limit velikosti — GPC výpisy bývají max stovky kB. 5 MiB je více než dost a chrání před DoS.
        // Pozn.: getSize() může být null (neznámá délka) → fallback na stream, a po
        // načtení ještě backstop přes strlen, aby null-size upload neprošel.
        $maxSize = 5 * 1024 * 1024;
        $declaredSize = $file->getSize() ?? $file->getStream()->getSize();
        if ($declaredSize !== null && $declaredSize > $maxSize) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 5 MiB).', 413);
        }

        // Whitelist přípon dle cfg.bank_import.allowed_exts
        $name = (string) $file->getClientFilename();
        $allowedExts = (array) $this->config->get('bank_import.allowed_exts', ['gpc', 'txt']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExts, true)) {
            return Json::error($response, 'invalid_extension', 'Nepovolená přípona souboru. Povolené: ' . implode(', ', $allowedExts), 400);
        }

        $content = (string) $file->getStream()->getContents();
        if (strlen($content) > $maxSize) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 5 MiB).', 413);
        }
        if (strlen($content) < 50) {
            return Json::error($response, 'empty_file', 'Soubor je prázdný.', 400);
        }

        // MIME check — GPC/ABO je plain text, odmítneme cokoliv binárního.
        // PHP 8.5+ deprecates finfo_close() (resource je auto-freed), proto ho neuvádíme.
        if (function_exists('finfo_buffer')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_buffer($finfo, $content);
                if ($mime !== '' && !str_starts_with($mime, 'text/') && $mime !== 'application/octet-stream') {
                    return Json::error($response, 'invalid_mime', 'Soubor není textový (detekováno: ' . $mime . ').', 400);
                }
            }
        }

        // MS-P2-1: parse hlavičku, ověř že account_number patří currencies aktuálního supplieru
        try {
            $parsed = $this->parser->parse($content);
        } catch (\Throwable $e) {
            return Json::error($response, 'parse_failed', 'Nelze parsovat: ' . $e->getMessage(), 400);
        }
        $resolved = $this->resolveTargetCurrency($request, (string) ($parsed['header']['account_number'] ?? ''));
        if ($resolved['error'] !== null) {
            return $resolved['error']($response);
        }

        try {
            $r = $this->importer->import($content, $name, (int) ($user['id'] ?? 0), $resolved['currency_id']);
        } catch (\Throwable $e) {
            return Json::error($response, 'parse_failed', 'Nelze parsovat: ' . $e->getMessage(), 400);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.statement_imported', $user['id'] ?? null, 'bank_statement', $r['statement_id'], $r, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $r);
    }

    /**
     * POST /api/bank-statements/upload-pdf  (multipart file=...)
     *
     * „Upload PDF" — pro banky bez GPC/ABO exportu (Creditas jako první). Text
     * extrahuje a rozparsuje {@see \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry}
     * (bank-specifický parser dle rozpoznaného layoutu, se self-checkem proti hlavičkovým
     * součtům), persist stejnou cestou jako GPC import (dedupe, currency/account
     * resolution, matching — {@see \MyInvoice\Service\Bank\StatementImporter::importParsedPdf()}).
     */
    public function importPdf(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Soubor chybí.', 400);
        }

        $maxSize = 5 * 1024 * 1024;
        $declaredSize = $file->getSize() ?? $file->getStream()->getSize();
        if ($declaredSize !== null && $declaredSize > $maxSize) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 5 MiB).', 413);
        }

        $name = (string) $file->getClientFilename();
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            return Json::error($response, 'invalid_extension', 'Nepovolená přípona souboru. Povolené: pdf', 400);
        }

        $pdfBytes = (string) $file->getStream()->getContents();
        if (strlen($pdfBytes) > $maxSize) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 5 MiB).', 413);
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return Json::error($response, 'invalid_pdf', 'Soubor není platné PDF.', 400);
        }

        try {
            $parsed = $this->pdfParsers->parse($pdfBytes);
        } catch (\Throwable $e) {
            return Json::error($response, 'parse_failed', 'Nelze parsovat: ' . $e->getMessage(), 400);
        }

        $resolved = $this->resolveTargetCurrency($request, (string) ($parsed['header']['account_number'] ?? ''));
        if ($resolved['error'] !== null) {
            return $resolved['error']($response);
        }

        try {
            $r = $this->importer->importParsedPdf($parsed, $pdfBytes, $name, (int) ($user['id'] ?? 0), $resolved['currency_id']);
        } catch (\Throwable $e) {
            return Json::error($response, 'parse_failed', 'Nelze parsovat: ' . $e->getMessage(), 400);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.statement_pdf_imported', $user['id'] ?? null, 'bank_statement', $r['statement_id'], $r + ['parser' => $parsed['parser'] ?? null], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $r);
    }

    /**
     * MS-P2-1 + #167: ověří, že account_number patří currencies aktuálního supplieru,
     * a vybere cílový měnový účet. U víceměnového účtu se SDÍLENÝM číslem (Raiffeisenbank:
     * CZK/EUR/USD = jedno číslo) nelze měnu z výpisu odvodit → vyžádej `account_id`.
     * Sdíleno mezi GPC (`upload`) a PDF (`importPdf`) uploadem.
     *
     * @return array{currency_id: ?int, error: null|(callable(Response): Response)}
     */
    private function resolveTargetCurrency(Request $request, string $accountNumber): array
    {
        if ($accountNumber === '') {
            return ['currency_id' => null, 'error' => null];
        }

        $sid = SupplierGuard::currentId($request);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, label, account_number, bank_code, iban FROM currencies WHERE supplier_id = ?'
        );
        $stmt->execute([$sid]);
        $matches = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $iban = isset($row['iban']) && is_string($row['iban']) ? $row['iban'] : null;
            if (\MyInvoice\Service\Bank\AccountNumberNormalizer::matchesAny($accountNumber, $row['account_number'] ?? null, $iban)) {
                $matches[] = $row;
            }
        }
        if ($matches === []) {
            return ['currency_id' => null, 'error' => fn (Response $response) => Json::error(
                $response,
                'wrong_supplier_account',
                "Bankovní účet $accountNumber není registrovaný u aktuálního supplier (Settings → měny → bankovní spojení).",
                409
            )];
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $rawAccountId = $body['account_id'] ?? null;
        if ($rawAccountId !== null && $rawAccountId !== '') {
            // Zvolený účet musí být mezi shodami (tím je vynucený scope na supplieru
            // i shoda čísla účtu — nelze podstrčit cizí účet/měnu).
            $accId = (int) $rawAccountId;
            $chosen = null;
            foreach ($matches as $m) {
                if ((int) $m['id'] === $accId) { $chosen = $m; break; }
            }
            if ($chosen === null) {
                return ['currency_id' => null, 'error' => fn (Response $response) => Json::error(
                    $response,
                    'invalid_account',
                    'Zvolený měnový účet neodpovídá číslu účtu ve výpisu nebo nepatří aktuálnímu dodavateli.',
                    422
                )];
            }
            return ['currency_id' => $accId, 'error' => null];
        }

        // Bez explicitní volby: jediný odpovídající účet → auto. Víc účtů se stejným
        // číslem účtu je nejednoznačných dvěma způsoby a ani GPC/ABO ani PDF hlavička
        // to neumí rozhodnout:
        //   • #167 — jedno fyzické číslo vedené ve více měnách (sdílené bank_code),
        //   • #206 — různé banky se stejným číslem před lomítkem (různý bank_code);
        //     GPC 074 kód banky vlastního účtu nenese a kód v 075 je banka protistrany.
        // V obou případech vyžádej ruční výběr místo tichého přiřazení k prvnímu
        // (typicky výchozímu) účtu — jinak výpis skončí pod špatným účtem.
        if (count($matches) > 1) {
            $candidates = array_map(fn ($m) => [
                'account_id'     => (int) $m['id'],
                'code'           => (string) $m['code'],
                'bank_code'      => isset($m['bank_code']) && (string) $m['bank_code'] !== '' ? (string) $m['bank_code'] : null,
                'account_number' => (string) ($m['account_number'] ?? ''),
                'label'          => $this->accountCandidateLabel($m),
            ], $matches);
            return ['currency_id' => null, 'error' => fn (Response $response) => Json::error(
                $response,
                'ambiguous_account_currency',
                'Tomuto číslu účtu odpovídá více bankovních účtů (různá měna nebo kód banky) — zvolte cílový účet.',
                409,
                ['candidates' => array_values($candidates)]
            )];
        }
        // Jednoznačný účet: použij konkrétní supplier-scoped řádek (autoritativní
        // měna i kód banky, tenant-safe — na rozdíl od tenant-less lookupu v importeru).
        return ['currency_id' => (int) $matches[0]['id'], 'error' => null];
    }

    /**
     * Srozumitelný popis kandidáta účtu do výběrového modalu (#167/#206). Vždy nese
     * měnu i číslo účtu s kódem banky, aby šly odlišit jak měnové varianty téhož
     * čísla (#167), tak různé banky se stejným číslem účtu (#206) — dvě CZK varianty
     * by jinak měly shodný label.
     *
     * @param array<string,mixed> $m currencies řádek (code, label, account_number, bank_code)
     */
    private function accountCandidateLabel(array $m): string
    {
        $code = (string) $m['code'];
        $bank = isset($m['bank_code']) && (string) $m['bank_code'] !== '' ? (string) $m['bank_code'] : null;
        $acct = trim((string) ($m['account_number'] ?? ''));
        $acctDisplay = $acct !== '' ? ($bank !== null ? $acct . '/' . $bank : $acct) : null;
        $name = trim((string) ($m['label'] ?? ''));

        $bits = [$name !== '' ? $name : $code];
        if ($name !== '' && stripos($name, $code) === false) { $bits[] = $code; }
        if ($acctDisplay !== null) { $bits[] = $acctDisplay; }
        return implode(' — ', $bits);
    }

    public function list(Request $request, Response $response): Response
    {
        // Multi-supplier scope: filter podle (account_number, bank_code) z currencies aktuálního supplier.
        // GPC zero-paduje účet (`0000001000000005`), currencies bez padding (`1000000005`) — porovnáváme
        // normalizované hodnoty (REGEXP_REPLACE non-digits + TRIM leading zeros).
        $sid = SupplierGuard::currentId($request);
        $limit = 50;
        $qp = $request->getQueryParams();
        $page = max(1, (int) ($qp['page'] ?? 1));
        $offset = ($page - 1) * $limit; // int (page castnuto) → bezpečně inline do LIMIT/OFFSET

        // Volitelné filtry rok/měsíc (statement_date) + číslo účtu. statement_date je
        // u avíz-výpisů 1. den měsíce, takže YEAR()/MONTH() funguje i pro ně.
        $filter  = (array) ($qp['filter'] ?? []);
        $year    = isset($filter['year'])  && $filter['year']  !== '' ? (int) $filter['year']  : null;
        $month   = isset($filter['month']) && $filter['month'] !== '' ? (int) $filter['month'] : null;
        $account = isset($filter['account']) ? trim((string) $filter['account']) : '';
        $bankCode = isset($filter['bank_code']) ? trim((string) $filter['bank_code']) : '';

        // Společný scope filtr (account_number/bank_code z currencies dodavatele).
        $scopeSql = "EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
              )";

        // Filtr WHERE fragment + parametry (sdílený mezi COUNT a výběrem řádků). Účet
        // porovnáváme normalizovaně (stejně jako scope), ať padding/lomítko nevadí.
        $filterSql = '';
        $filterParams = [];
        if ($year !== null)  { $filterSql .= ' AND YEAR(bs.statement_date) = ?';  $filterParams[] = $year; }
        if ($month !== null) { $filterSql .= ' AND MONTH(bs.statement_date) = ?'; $filterParams[] = $month; }
        if ($account !== '') {
            $filterSql .= " AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', ''))
                              = TRIM(LEADING '0' FROM REGEXP_REPLACE(?, '[^0-9]', ''))";
            $filterParams[] = $account;
            if ($bankCode !== '') {
                $accountCountStmt = $this->db->pdo()->prepare(
                    "SELECT COUNT(*) FROM currencies cur
                      WHERE cur.supplier_id = ?
                        AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                          = TRIM(LEADING '0' FROM REGEXP_REPLACE(?, '[^0-9]', ''))"
                );
                $accountCountStmt->execute([$sid, $account]);
                $allowMissingBankCode = (int) $accountCountStmt->fetchColumn() === 1 ? 1 : 0;
                $filterSql .= " AND (bs.bank_code = ?
                                  OR ((bs.bank_code IS NULL OR bs.bank_code = '') AND ? = 1))";
                $filterParams[] = $bankCode;
                $filterParams[] = $allowMissingBankCode;
            }
        }

        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM bank_statements bs WHERE $scopeSql$filterSql");
        $countStmt->execute(array_merge([$sid], $filterParams));
        $total = (int) $countStmt->fetchColumn();

        // account_label: vlastní pojmenování účtu z currencies.label (např. "CZK — Fio Bank")
        // přes scalar subselect (LIMIT 1 — sup. může mít jen 1 záznam per account_number+bank_code).
        $stmt = $this->db->pdo()->prepare(
            "SELECT bs.id, bs.source, bs.file_name, bs.account_number,
                    -- Kód uložený na výpisu je autoritativní. U starších záznamů bez
                    -- bank_code doplň kód z currencies jen tehdy, když je pro dané číslo
                    -- účtu jednoznačný; LIMIT 1 by při shodném čísle u více bank zobrazil
                    -- náhodnou banku (#206).
                    COALESCE(
                      bs.bank_code,
                      (SELECT CASE
                                WHEN COUNT(DISTINCT NULLIF(cur.bank_code, '')) = 1
                                THEN MAX(NULLIF(cur.bank_code, ''))
                                ELSE NULL
                              END
                         FROM currencies cur
                        WHERE cur.supplier_id = ?
                          AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                            = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                      )
                    ) AS bank_code,
                    bs.currency, bs.statement_date, bs.statement_number,
                    bs.prev_balance, bs.curr_balance, bs.transaction_count, bs.matched_count, bs.imported_at,
                    (bs.file_content IS NOT NULL) AS has_file,
                    (bs.pdf_content IS NOT NULL) AS has_pdf, bs.pdf_name,
                    (SELECT cur.label FROM currencies cur
                      WHERE cur.supplier_id = ?
                        AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                          = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                        AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                      LIMIT 1) AS account_label
               FROM bank_statements bs
              WHERE $scopeSql$filterSql
              ORDER BY bs.statement_date DESC, bs.id DESC
              LIMIT $limit OFFSET $offset"
        );
        $stmt->execute(array_merge([$sid, $sid, $sid], $filterParams));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['transaction_count'] = (int) $r['transaction_count'];
            $r['matched_count'] = (int) $r['matched_count'];
            $r['prev_balance'] = (float) $r['prev_balance'];
            $r['curr_balance'] = (float) $r['curr_balance'];
            $r['has_file'] = (bool) $r['has_file'];
            $r['has_pdf'] = (bool) $r['has_pdf'];
        }
        unset($r);

        // Volby pro filtry (počítané přes CELÝ scope, ne přes aktuální filtr — ať
        // dropdowny nemizí podle zvoleného roku/účtu). Roky z statement_date,
        // účty distinct + jejich label.
        $yearsStmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT YEAR(bs.statement_date) AS y
               FROM bank_statements bs WHERE $scopeSql AND bs.statement_date IS NOT NULL
              ORDER BY y DESC"
        );
        $yearsStmt->execute([$sid]);
        $years = array_values(array_filter(array_map(
            static fn ($y) => (int) $y,
            $yearsStmt->fetchAll(\PDO::FETCH_COLUMN)
        )));

        // Účty pro filtr bereme z CURRENCIES (konfigurované bankovní účty dodavatele),
        // ne ze surových account_number ve výpisech — tím máme:
        //   • každý účet právě 1× (tentýž účet chodí z avíza i z GPC v jiném formátu),
        //   • autoritativní kód banky (na statementu může chybět, typicky u avíz),
        //   • stejné pořadí jako v adminu (Nastavení → bankovní účty: code, výchozí, label).
        // EXISTS jen omezí na účty, které reálně mají nějaký výpis (jinak by filtr nedával smysl).
        $accStmt = $this->db->pdo()->prepare(
            "SELECT cur.account_number, cur.bank_code, cur.label
               FROM currencies cur
              WHERE cur.supplier_id = ?
                AND cur.account_number IS NOT NULL AND cur.account_number <> ''
                AND EXISTS (
                    SELECT 1 FROM bank_statements bs
                     WHERE TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', ''))
                         = TRIM(LEADING '0' FROM REGEXP_REPLACE(cur.account_number, '[^0-9]', ''))
                       AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )
              ORDER BY cur.code, cur.is_default DESC, cur.label"
        );
        $accStmt->execute([$sid]);
        $accounts = array_map(static fn ($a) => [
            'account_number' => (string) $a['account_number'],
            'bank_code'      => $a['bank_code'] !== null ? (string) $a['bank_code'] : null,
            'label'          => $a['label'] !== null ? (string) $a['label'] : null,
        ], $accStmt->fetchAll(\PDO::FETCH_ASSOC));

        return Json::ok($response, [
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'years' => $years,
            'accounts' => $accounts,
            // Adresářové skenování je nastavené? UI podle toho zobrazí tlačítko „Skenovat adresář".
            'scan_configured' => $this->scanConfigured(),
        ]);
    }

    /**
     * GET /api/bank-statements/account-balances
     *
     * Přehled zůstatků na bankovních účtech dodavatele:
     *   • aktuální stav (konečný zůstatek posledního GPC výpisu daného účtu),
     *   • měsíční vývoj (závěrečný zůstatek za každý měsíc, carry-forward),
     *   • celkový součet přepočtený na CZK kurzem ČNB ke konci každého měsíce.
     *
     * Zdroje zůstatku (per měsíc/aktuální stav vyhrává novější datum, při shodě GPC):
     *   • `source = 'gpc'` — autoritativní konečný zůstatek z hlavičky 074,
     *   • e-mailová avíza (`bank_transactions.balance`) — disponibilní zůstatek
     *     z těla avíza (Creditas/Fio/RB), typicky čerstvější než poslední výpis.
     *
     * Přepočet na CZK: kurz z cache `exchange_rates`, nejbližší rate_date ≤ konec měsíce
     * (fallback nejstarší známý). Měny bez jakéhokoli kurzu → `missing_rates`.
     */
    public function accountBalances(Request $request, Response $response): Response
    {
        $sid = SupplierGuard::currentId($request);
        if ($sid <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolen dodavatel.', 400);
        }
        $pdo = $this->db->pdo();

        // Měnové účty dodavatele s vyplněným číslem účtu (v pořadí jako v adminu).
        $accStmt = $pdo->prepare(
            "SELECT id, code, label, account_number, bank_code, is_default
               FROM currencies
              WHERE supplier_id = ?
                AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY is_default DESC, code, label"
        );
        $accStmt->execute([$sid]);
        $currencyAccounts = $accStmt->fetchAll(\PDO::FETCH_ASSOC);

        // GPC výpisy pro konkrétní účet: normalizovaný match čísla účtu (padding/lomítko),
        // kompatibilní kód banky a měna (disambiguace víceměnového sdíleného čísla — #167).
        $stStmt = $pdo->prepare(
            "SELECT DATE_FORMAT(bs.statement_date, '%Y-%m') AS ym,
                    bs.statement_date AS sdate,
                    bs.curr_balance   AS bal
               FROM bank_statements bs
              WHERE bs.source = 'gpc'
                AND bs.statement_date IS NOT NULL
                AND bs.curr_balance IS NOT NULL
                AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', ''))
                  = TRIM(LEADING '0' FROM REGEXP_REPLACE(?, '[^0-9]', ''))
                AND (bs.bank_code IS NULL OR ? IS NULL OR bs.bank_code = ?)
                AND (bs.currency IS NULL OR bs.currency = '' OR bs.currency = ?)
              ORDER BY bs.statement_date ASC, bs.id ASC"
        );

        // Zůstatky z e-mailových avíz pro konkrétní účet (bank_transactions.balance,
        // migrace 0125): měsíční email_notice statement má statement_date = 1. den
        // měsíce, proto se datum bere z transakce (posted_at), ne z výpisu.
        $emStmt = $pdo->prepare(
            "SELECT DATE_FORMAT(bt.posted_at, '%Y-%m') AS ym,
                    bt.posted_at AS sdate,
                    bt.balance   AS bal
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.source = 'email_notice'
                AND bt.balance IS NOT NULL
                AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', ''))
                  = TRIM(LEADING '0' FROM REGEXP_REPLACE(?, '[^0-9]', ''))
                AND (bs.bank_code IS NULL OR ? IS NULL OR bs.bank_code = ?)
                AND (bs.currency IS NULL OR bs.currency = '' OR bs.currency = ?)
              ORDER BY bt.posted_at ASC, bt.id ASC"
        );

        /** @var array<int,array<string,mixed>> $perAcc */
        $perAcc = [];
        $allMonths = [];   // set 'YYYY-MM' napříč účty (osa celkového grafu)
        $codesUsed = [];   // ne-CZK měny → potřebují kurz

        foreach ($currencyAccounts as $ca) {
            $code = strtoupper((string) $ca['code']);
            $params = [
                (string) $ca['account_number'],
                $ca['bank_code'], $ca['bank_code'],
                $code,
            ];
            $stStmt->execute($params);
            $rows = $stStmt->fetchAll(\PDO::FETCH_ASSOC);
            $emStmt->execute($params);
            $emailRows = $emStmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === [] && $emailRows === []) {
                continue; // účet bez GPC výpisů i avíz se zůstatkem se nezobrazuje
            }

            // Závěrečný zůstatek za měsíc = poslední záznam v měsíci (řazeno ASC →
            // přepíše se); avízo přebije GPC jen s ostře novějším datem (shoda → GPC).
            $closings = [];
            foreach ($rows as $r) {
                $closings[(string) $r['ym']] = ['bal' => (float) $r['bal'], 'date' => (string) $r['sdate'], 'src' => 'gpc'];
            }
            foreach ($emailRows as $r) {
                $ym = (string) $r['ym'];
                $prev = $closings[$ym] ?? null;
                if ($prev === null || $prev['src'] === 'email_notice' || (string) $r['sdate'] > $prev['date']) {
                    $closings[$ym] = ['bal' => (float) $r['bal'], 'date' => (string) $r['sdate'], 'src' => 'email_notice'];
                }
            }
            ksort($closings);
            $monthClosings = array_map(static fn (array $c): float => $c['bal'], $closings);
            $months = array_keys($closings);
            $last = $closings[$months[count($months) - 1]];

            $perAcc[] = [
                'ca'            => $ca,
                'code'          => $code,
                'monthClosings' => $monthClosings,
                'firstYm'       => $months[0],
                'lastYm'        => $months[count($months) - 1],
                'current'       => $last['bal'],
                'currentDate'   => $last['date'],
                'currentSource' => $last['src'],
                'count'         => count($rows),
            ];
            foreach ($months as $m) { $allMonths[$m] = true; }
            if ($code !== 'CZK') { $codesUsed[$code] = true; }
        }

        // Přednačti kurzy pro použité měny (vzestupně dle data) + eviduj chybějící.
        $rateSeries = [];
        $missing = [];
        if ($codesUsed !== []) {
            $in = implode(',', array_fill(0, count($codesUsed), '?'));
            $rStmt = $pdo->prepare(
                "SELECT currency_code, rate_date, rate FROM exchange_rates
                  WHERE currency_code IN ($in) ORDER BY currency_code, rate_date ASC"
            );
            $rStmt->execute(array_keys($codesUsed));
            foreach ($rStmt->fetchAll(\PDO::FETCH_ASSOC) as $rr) {
                $rateSeries[(string) $rr['currency_code']][] = [
                    'd' => (string) $rr['rate_date'],
                    'r' => (float) $rr['rate'],
                ];
            }
            foreach (array_keys($codesUsed) as $c) {
                if (!isset($rateSeries[$c])) { $missing[] = $c; }
            }
        }

        // CZK za 1 jednotku měny k datu (nejbližší kurz ≤ datum, fallback nejstarší známý).
        $rateFor = static function (string $code, string $dateYmd) use ($rateSeries): ?float {
            if ($code === 'CZK') return 1.0;
            $series = $rateSeries[$code] ?? null;
            if ($series === null) return null;
            $chosen = null;
            foreach ($series as $pt) {
                if ($pt['d'] <= $dateYmd) { $chosen = $pt['r']; } else { break; }
            }
            return $chosen ?? $series[0]['r'];
        };

        // Enumeruj měsíce 'YYYY-MM' od–do včetně.
        $enumMonths = static function (string $from, string $to): array {
            $out = [];
            $cur = $from;
            $guard = 0;
            while ($cur <= $to && $guard++ < 600) {
                $out[] = $cur;
                [$y, $m] = array_map('intval', explode('-', $cur));
                if (++$m > 12) { $m = 1; $y++; }
                $cur = sprintf('%04d-%02d', $y, $m);
            }
            return $out;
        };

        $capMonths = 36;      // grafy: max poslední 3 roky
        $todayYmd = (new \DateTimeImmutable('now'))->format('Y-m-d');

        // Sestav řádky účtů + jejich měsíční řady (nativní měna, carry-forward).
        $accounts = [];
        foreach ($perAcc as $pa) {
            $full = $enumMonths($pa['firstYm'], $pa['lastYm']);
            $carry = [];
            $lk = null;
            foreach ($full as $m) {
                if (isset($pa['monthClosings'][$m])) { $lk = $pa['monthClosings'][$m]; }
                $carry[$m] = $lk;
            }
            $range = count($full) > $capMonths ? array_slice($full, -$capMonths) : $full;
            $series = [];
            foreach ($range as $m) {
                $series[] = ['month' => $m, 'balance' => $carry[$m] !== null ? round($carry[$m], 2) : null];
            }

            $curRate = $rateFor($pa['code'], $todayYmd);
            $accounts[] = [
                'id'                  => (int) $pa['ca']['id'],
                'code'                => $pa['code'],
                'label'               => (string) ($pa['ca']['label'] ?? '') !== '' ? (string) $pa['ca']['label'] : $pa['code'],
                'account_number'      => (string) $pa['ca']['account_number'],
                'bank_code'           => $pa['ca']['bank_code'] !== null ? (string) $pa['ca']['bank_code'] : null,
                'is_default'          => (bool) $pa['ca']['is_default'],
                'current_balance'     => round($pa['current'], 2),
                'current_balance_czk' => $curRate !== null ? round($pa['current'] * $curRate, 2) : null,
                'statement_date'      => $pa['currentDate'],
                'current_source'      => $pa['currentSource'],
                'statement_count'     => (int) $pa['count'],
                'months'              => $series,
            ];
        }

        // Celkový graf v CZK — společná osa, carry-forward per účet, kurz ke konci měsíce.
        $totalMonths = [];
        if ($allMonths !== []) {
            $ms = array_keys($allMonths);
            sort($ms);
            $axis = $enumMonths($ms[0], $ms[count($ms) - 1]);
            if (count($axis) > $capMonths) { $axis = array_slice($axis, -$capMonths); }
            foreach ($axis as $m) {
                $monthEnd = date('Y-m-t', (int) strtotime($m . '-01'));
                $sum = 0.0;
                $have = false;
                foreach ($perAcc as $pa) {
                    $bal = null;
                    foreach ($pa['monthClosings'] as $ym => $b) {
                        if ($ym <= $m) { $bal = $b; } else { break; }
                    }
                    if ($bal === null) { continue; }
                    $rate = $rateFor($pa['code'], $monthEnd);
                    if ($rate === null) { continue; } // měna bez kurzu — vynech (flag missing_rates)
                    $sum += $bal * $rate;
                    $have = true;
                }
                $totalMonths[] = ['month' => $m, 'balance_czk' => $have ? round($sum, 2) : null];
            }
        }

        $totalCurrent = 0.0;
        foreach ($accounts as $a) {
            if ($a['current_balance_czk'] !== null) { $totalCurrent += $a['current_balance_czk']; }
        }

        return Json::ok($response, [
            'base_currency' => 'CZK',
            'accounts'      => $accounts,
            'total_czk'     => [
                'current' => round($totalCurrent, 2),
                'months'  => $totalMonths,
            ],
            'missing_rates' => $missing,
        ]);
    }

    /**
     * DELETE /api/bank-statements/{id}
     *
     * Smaže výpis vč. transakcí (ON DELETE CASCADE) a payment_matches (CASCADE
     * přes bank_transactions). NEresetuje status faktur — ty zůstávají paid
     * (manuální cleanup u faktur, kterých se to týká, je doménou uživatele).
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if ($sid <= 0 || $id <= 0) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        // RBAC — pouze admin. Účetní (accountant) může nahrávat a párovat,
        // ale destruktivní smazání výpisu vč. všech transakcí + party párování
        // nechte na adminovi (forensic integrity — uzávěrku DPH/KH je třeba
        // mít stabilní).
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin smí mazat výpisy.', 403);
        }

        // Supplier scope check — stejný pattern jako detail()
        $pdo = $this->db->pdo();
        $owned = $pdo->prepare(
            "SELECT bs.file_name, bs.source, bs.matched_count FROM bank_statements bs
              WHERE bs.id = ?
                AND EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )"
        );
        $owned->execute([$id, $sid]);
        $ownedRow = $owned->fetch(\PDO::FETCH_ASSOC);
        if ($ownedRow === false) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }
        $fileName = (string) $ownedRow['file_name'];

        // Avízo-výpis (e-mailová bankovní avíza) smí jít smazat jen když na něm nezbývá
        // žádná spárovaná položka — typicky poté, co párování převzal oficiální GPC výpis
        // (EmailNoticeReconciler). Smazání výpisu se spárovanými transakcemi by jinak
        // osiřelo zaplacené faktury (invoice_payments.bank_transaction_id je ON DELETE
        // SET NULL → platba zůstane, ztratí ale vazbu; payment_matches CASCADE → vazba
        // přijaté faktury zmizí úplně). U GPC chování neměníme.
        // Počítáme ŽIVĚ (ne uložený matched_count) — odolné vůči stale hodnotě.
        if ((string) $ownedRow['source'] === 'email_notice') {
            $matchedLive = (int) $pdo->query(
                "SELECT COUNT(*) FROM bank_transactions
                  WHERE statement_id = " . (int) $id . "
                    AND match_status IN ('auto_exact','auto_partial','manual')"
            )->fetchColumn();
            if ($matchedLive > 0) {
                return Json::error(
                    $response,
                    'has_matches',
                    'Avízo-výpis má spárované položky. Nejdřív je rozpáruj (nebo nech převzít oficiálním GPC výpisem).',
                    409
                );
            }
        }

        $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.statement_deleted', $user['id'] ?? null, 'bank_statement', $id, [
            'file_name' => $fileName,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * GET /api/bank-statements/{id}/download
     *
     * Vrátí originální obsah GPC souboru (uložený v bank_statements.file_content
     * od migrace 0045). Pro statementy importované před touto migrací vrací 404.
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if ($sid <= 0 || $id <= 0) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT bs.file_name, bs.file_content
               FROM bank_statements bs
              WHERE bs.id = ?
                AND EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )"
        );
        $stmt->execute([$id, $sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }
        if ($row['file_content'] === null || $row['file_content'] === '') {
            return Json::error($response, 'file_unavailable',
                'Originální soubor není k dispozici (výpis byl importován před verzí 4.1.0).', 410);
        }

        $fileName = (string) ($row['file_name'] ?: ('vypis-' . $id . '.gpc'));
        // ASCII-only filename pro Content-Disposition (RFC 6266 fallback) +
        // odstranění CRLF / quotes (header injection guard).
        $safeName = preg_replace('/[\x00-\x1f"\\\\]/', '_', $fileName) ?? $fileName;

        $response->getBody()->write((string) $row['file_content']);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=windows-1250')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withHeader('Content-Length', (string) strlen((string) $row['file_content']))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Ověří, že výpis #$id patří aktuálnímu supplieru (přes account_number →
     * currencies.supplier_id, stejný normalizovaný match jako list/detail/download).
     */
    private function statementOwned(int $id, int $sid): bool
    {
        if ($id <= 0 || $sid <= 0) return false;
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM bank_statements bs
              WHERE bs.id = ?
                AND EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )
              LIMIT 1"
        );
        $stmt->execute([$id, $sid]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * POST /api/bank-statements/{id}/pdf  (multipart file=...)
     *
     * Přiloží k existujícímu výpisu PDF verzi (např. oficiální PDF výpis z banky).
     * Ukládá se jako MEDIUMBLOB do bank_statements.pdf_content (stejně jako GPC).
     * Admin nebo účetní (write role).
     */
    public function uploadPdf(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if (!$this->statementOwned($id, $sid)) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        // Avízo-výpis (virtuální, složený z e-mailových bankovních avíz) nemá originální
        // PDF — přikládání PDF u něj nedává smysl. UI tlačítko skrývá, server pro jistotu blokuje.
        $srcStmt = $this->db->pdo()->prepare('SELECT source FROM bank_statements WHERE id = ?');
        $srcStmt->execute([$id]);
        if ((string) $srcStmt->fetchColumn() === 'email_notice') {
            return Json::error($response, 'unsupported', 'K avízo-výpisu nelze přikládat PDF.', 400);
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Soubor chybí.', 400);
        }

        // PDF výpisy bývají do pár MB; 10 MiB je bezpečný strop (MEDIUMBLOB zvládá 16 MiB).
        // getSize() může být null → fallback na stream + backstop přes strlen níže.
        $maxSize = 10 * 1024 * 1024;
        $declaredSize = $file->getSize() ?? $file->getStream()->getSize();
        if ($declaredSize !== null && $declaredSize > $maxSize) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 10 MiB).', 413);
        }

        $name = (string) $file->getClientFilename();
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            return Json::error($response, 'invalid_extension', 'Povolené je jen PDF.', 400);
        }

        $content = (string) $file->getStream()->getContents();
        if (strlen($content) > $maxSize) {
            return Json::error($response, 'file_too_large', 'Soubor je příliš velký (max 10 MiB).', 413);
        }
        // Magic bytes — PDF musí začínat "%PDF-" (případně s BOM/whitespace na začátku).
        if (!str_starts_with(ltrim($content, "\x00\x09\x0a\x0d\x20\xef\xbb\xbf"), '%PDF-')) {
            return Json::error($response, 'invalid_pdf', 'Soubor není platné PDF.', 400);
        }
        if (function_exists('finfo_buffer')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_buffer($finfo, $content);
                if ($mime !== '' && $mime !== 'application/pdf') {
                    return Json::error($response, 'invalid_mime', 'Soubor není PDF (detekováno: ' . $mime . ').', 400);
                }
            }
        }

        $hash = hash('sha256', $content);
        $this->db->pdo()->prepare(
            'UPDATE bank_statements
                SET pdf_content = ?, pdf_name = ?, pdf_hash = ?, pdf_size_bytes = ?, pdf_uploaded_at = NOW()
              WHERE id = ?'
        )->execute([$content, $name, $hash, strlen($content), $id]);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.pdf_uploaded', $user['id'] ?? null, 'bank_statement', $id, [
            'pdf_name' => $name,
            'size'     => strlen($content),
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['uploaded' => true, 'pdf_name' => $name]);
    }

    /**
     * GET /api/bank-statements/{id}/pdf
     *
     * Stáhne přiložené PDF (bank_statements.pdf_content). 404 pokud výpis nepatří
     * supplieru nebo PDF není nahrané.
     */
    public function downloadPdf(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if (!$this->statementOwned($id, $sid)) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $stmt = $this->db->pdo()->prepare('SELECT pdf_name, pdf_content, account_number FROM bank_statements WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['pdf_content'] === null || $row['pdf_content'] === '') {
            return Json::error($response, 'pdf_unavailable', 'K tomuto výpisu není nahrané PDF.', 404);
        }

        $fileName = (string) ($row['pdf_name'] ?: ('vypis-' . $id . '.pdf'));
        // Číslo účtu na začátek názvu, pokud tam ještě není — ať se stažené PDF
        // z různých účtů nepletou (např. „2026-02.pdf" → „1000000005-2026-02.pdf").
        // „Už obsahuje" testujeme i podle čistých číslic (formát s lomítkem/pomlčkou).
        // Trim vedoucí nuly (zero-padded účet „000123-456" → „123-456").
        $account = ltrim(trim((string) ($row['account_number'] ?? '')), '0');
        if ($account !== '') {
            $acctDigits = preg_replace('/\D/', '', $account) ?? '';
            $nameDigits = preg_replace('/\D/', '', $fileName) ?? '';
            $alreadyHas = str_contains($fileName, $account)
                || ($acctDigits !== '' && str_contains($nameDigits, $acctDigits));
            $acctSafe = preg_replace('/[^A-Za-z0-9_-]/', '', $account) ?? '';
            if (!$alreadyHas && $acctSafe !== '') {
                $fileName = $acctSafe . '-' . $fileName;
            }
        }
        $safeName = preg_replace('/[\x00-\x1f"\\\\]/', '_', $fileName) ?? $fileName;

        $response->getBody()->write((string) $row['pdf_content']);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withHeader('Content-Length', (string) strlen((string) $row['pdf_content']))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * DELETE /api/bank-statements/{id}/pdf
     *
     * Smaže přiložené PDF (GPC i transakce zůstávají). Admin nebo účetní.
     */
    public function deletePdf(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if (!$this->statementOwned($id, $sid)) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $this->db->pdo()->prepare(
            'UPDATE bank_statements
                SET pdf_content = NULL, pdf_name = NULL, pdf_hash = NULL, pdf_size_bytes = NULL, pdf_uploaded_at = NULL
              WHERE id = ?'
        )->execute([$id]);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.pdf_deleted', $user['id'] ?? null, 'bank_statement', $id, [], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        // Normalize porovnání account_number — viz `list()` komentář.
        // POZOR: explicit columns (ne `bs.*`) — file_content je MEDIUMBLOB se surovými
        // CP1250 bajty GPC souboru a Json::ok() na něj padá s "Malformed UTF-8" když
        // se to dostane do json_encode. Místo toho exposujeme jen `has_file` flag,
        // bajty se stahují přes /download endpoint.
        $stmt = $this->db->pdo()->prepare(
            "SELECT bs.id, bs.source, bs.file_name, bs.file_hash, bs.account_number, bs.bank_code,
                    bs.currency, bs.statement_number, bs.statement_date,
                    bs.prev_balance, bs.curr_balance, bs.credit_total, bs.debit_total,
                    bs.transaction_count, bs.matched_count,
                    bs.imported_at, bs.imported_by,
                    (bs.file_content IS NOT NULL) AS has_file,
                    (bs.pdf_content IS NOT NULL) AS has_pdf, bs.pdf_name,
                    (SELECT cur.label FROM currencies cur
                      WHERE cur.supplier_id = ?
                        AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                          = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                        AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                      LIMIT 1) AS account_label
               FROM bank_statements bs
              WHERE bs.id = ?
                AND EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )"
        );
        $stmt->execute([$sid, $id, $sid]);
        $s = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$s) return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);

        $txStmt = $this->db->pdo()->prepare(
            'SELECT bt.*, i.varsymbol AS matched_varsymbol, i.amount_to_pay AS matched_invoice_amount,
                    i.client_id, c.company_name AS matched_client_name,
                    pm.purchase_invoice_id AS matched_purchase_invoice_id,
                    COALESCE(NULLIF(p.vendor_invoice_number, \'\'), p.varsymbol) AS matched_purchase_ref,
                    vc.company_name AS matched_vendor_name
               FROM bank_transactions bt
          LEFT JOIN invoices i ON i.id = bt.matched_invoice_id
          LEFT JOIN clients c ON c.id = i.client_id
          LEFT JOIN (SELECT bank_transaction_id, MIN(id) AS min_id
                       FROM payment_matches GROUP BY bank_transaction_id) pmx
                 ON pmx.bank_transaction_id = bt.id
          LEFT JOIN payment_matches pm ON pm.id = pmx.min_id
          LEFT JOIN purchase_invoices p ON p.id = pm.purchase_invoice_id
          LEFT JOIN clients vc ON vc.id = p.vendor_id
              WHERE bt.statement_id = ?
           ORDER BY bt.posted_at, bt.id'
        );
        $txStmt->execute([$id]);
        $transactions = $txStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Sloučená úhrada (split): jedna transakce může mít platby na VÍCE vystavených
        // faktur (migrace 0119). Doplň seznam spárovaných faktur z invoice_payments —
        // u běžného 1:1 párování je v něm jeden prvek, u splitu víc. Zdroj pravdy pro
        // zobrazení, kdo všechno byl touto platbou uhrazen.
        $matchedByTx = [];
        $txIds = array_map(static fn ($t) => (int) $t['id'], $transactions);
        if ($txIds !== []) {
            $ph = implode(',', array_fill(0, count($txIds), '?'));
            $mp = $this->db->pdo()->prepare(
                "SELECT p.bank_transaction_id AS tx_id, p.invoice_id, p.amount,
                        i.varsymbol, i.invoice_type, c.company_name AS client_name
                   FROM invoice_payments p
                   JOIN invoices i ON i.id = p.invoice_id
              LEFT JOIN clients c ON c.id = i.client_id
                  WHERE p.bank_transaction_id IN ($ph)
               ORDER BY p.id"
            );
            $mp->execute($txIds);
            foreach ($mp->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $matchedByTx[(int) $r['tx_id']][] = [
                    'invoice_id'   => (int) $r['invoice_id'],
                    'varsymbol'    => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
                    'invoice_type' => (string) $r['invoice_type'],
                    'amount'       => (float) $r['amount'],
                    'client_name'  => $r['client_name'] !== null ? (string) $r['client_name'] : null,
                ];
            }
        }

        foreach ($transactions as &$t) {
            $t['id'] = (int) $t['id'];
            $t['amount'] = (float) $t['amount'];
            $t['balance'] = isset($t['balance']) ? (float) $t['balance'] : null;
            $t['matched_invoice_id'] = $t['matched_invoice_id'] !== null ? (int) $t['matched_invoice_id'] : null;
            $t['matched_purchase_invoice_id'] = $t['matched_purchase_invoice_id'] !== null ? (int) $t['matched_purchase_invoice_id'] : null;
            $t['matched_purchase_ref'] = isset($t['matched_purchase_ref']) && $t['matched_purchase_ref'] !== null ? (string) $t['matched_purchase_ref'] : null;
            $t['matched_vendor_name'] = isset($t['matched_vendor_name']) && $t['matched_vendor_name'] !== null ? (string) $t['matched_vendor_name'] : null;
            $t['matched_invoices'] = $matchedByTx[$t['id']] ?? [];
        }
        unset($t);
        $s['id'] = (int) $s['id'];
        $s['has_file'] = (bool) ($s['has_file'] ?? false);
        $s['has_pdf'] = (bool) ($s['has_pdf'] ?? false);
        $s['transactions'] = $transactions;
        return Json::ok($response, $s);
    }

    /**
     * POST /api/bank-transactions/{id}/create-purchase-invoice
     *
     * Založí KONCEPT přijaté faktury (doklad o úhradě) z ODCHOZÍ bankovní transakce.
     * Spáruje dodavatele dle názvu protistrany, jinak ho založí (minimální). Předvyplní
     * fakturu (částka, datum, VS, měna, popis) a vrátí ID k otevření v editoru. Žádné
     * automatické párování ani placení — jen draft k revizi + nahrání PDF.
     */
    public function createPurchaseInvoice(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $txId = (int) ($args['id'] ?? 0);
        if (!$this->txBelongsToCurrentSupplier($request, $txId)) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = (int) ($user['id'] ?? 0);
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT bt.amount, bt.posted_at, bt.variable_symbol, bt.counterparty_name,
                    bt.counterparty_account, bt.description, bs.account_number
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $stmt->execute([$txId]);
        $tx = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$tx) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }
        if ((float) $tx['amount'] >= 0) {
            return Json::error($response, 'not_outgoing',
                'Přijatou fakturu lze založit jen z odchozí (záporné) platby.', 400);
        }

        $gross = round(abs((float) $tx['amount']), 2);
        $postedAt = (string) ($tx['posted_at'] ?? date('Y-m-d'));
        $name = trim((string) ($tx['counterparty_name'] ?? ''));
        [$currencyId, $currencyCode] = $this->resolveStatementCurrency($supplierId, (string) ($tx['account_number'] ?? ''));

        // Dodavatele NEzakládáme — musí existovat a uživatel ho vybral ve VendorPickeru
        // (vč. tlačítka „nový dodavatel"). Backend jen ověří, že patří tenantovi.
        $vendorId = (int) (((array) ($request->getParsedBody() ?? []))['vendor_id'] ?? 0);
        if ($vendorId <= 0) {
            return Json::error($response, 'vendor_required', 'Vyber dodavatele.', 400);
        }
        $vchk = $pdo->prepare('SELECT supplier_id FROM clients WHERE id = ?');
        $vchk->execute([$vendorId]);
        if ((int) $vchk->fetchColumn() !== $supplierId) {
            return Json::error($response, 'vendor_not_found', 'Dodavatel neexistuje.', 400);
        }
        $pdo->prepare('UPDATE clients SET is_vendor = 1 WHERE id = ? AND is_vendor = 0')->execute([$vendorId]);

        // VS patří do pole varsymbol; vendor_invoice_number (povinné + součást unikátního
        // klíče uq_pi_vendor_invoice) nesmíme plnit VS — kolidovalo by. Dáme unikátní
        // placeholder BANK-{txId}, skutečné číslo dokladu doplní uživatel po nahrání PDF.
        $varsymbol = mb_substr(trim((string) ($tx['variable_symbol'] ?? '')), 0, 20) ?: null;
        $vendorInvoiceNumber = 'BANK-' . $txId;
        $descr = trim((string) ($tx['description'] ?? '')) ?: ($name ?: 'Platba z bankovního výpisu');

        // Už existuje koncept z této transakce? (opakované kliknutí) → přátelská hláška místo 500.
        $dupe = $pdo->prepare(
            'SELECT id FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? LIMIT 1'
        );
        $dupe->execute([$supplierId, $vendorId, $vendorInvoiceNumber]);
        if ($existingId = (int) $dupe->fetchColumn()) {
            return Json::error($response, 'already_exists',
                'Z této transakce už koncept přijaté faktury existuje (#' . $existingId . ').', 409);
        }

        $piId = $this->purchaseRepo->createDraft([
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $vendorInvoiceNumber,
            'varsymbol'             => $varsymbol,
            'document_kind'         => 'invoice',
            'issue_date'            => $postedAt,
            'tax_date'              => $postedAt,
            'due_date'              => $postedAt,
            'received_at'           => $postedAt,
            'currency_id'           => $currencyId,
            'note_above_items'      => 'Předvyplněno z bankovního výpisu (tx #' . $txId . '). Zkontroluj DPH + nahraj PDF.',
        ], $userId, $supplierId);

        // Jedna položka v hrubé částce, sazba 0 % → total = uhrazená částka (po nahrání
        // PDF uživatel upraví rozpad DPH / položky).
        $zeroRateId = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->purchaseRepo->replaceItems($piId, [[
            'description'            => mb_substr($descr, 0, 255),
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => $gross,
            'vat_rate_id'            => $zeroRateId ?: null,
            'order_index'            => 0,
        ]]);
        $this->purCalc->recompute($piId);

        // Spáruj platbu na nově vzniklou přijatou fakturu (manuální, user-initiated klikem).
        // Draft fakturu neoznačujeme jako paid — to udělá uživatel po finalizaci; jen vazba.
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO payment_matches
                    (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
                 VALUES (?, ?, ?, ?, 'manual', ?)"
            )->execute([$supplierId, $txId, $piId, $gross, $userId ?: null]);
            $pdo->prepare(
                "UPDATE bank_transactions SET match_status = 'manual', matched_at = NOW(), matched_by = ? WHERE id = ?"
            )->execute([$userId ?: null, $txId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.purchase_draft_created', $userId, 'purchase_invoice', $piId, [
            'bank_transaction_id' => $txId, 'vendor_id' => $vendorId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'purchase_invoice_id' => $piId,
            'vendor_id'           => $vendorId,
            'currency'            => $currencyCode,
        ], 201);
    }

    /**
     * Měna dle účtu výpisu (normalizovaný match na currencies.account_number), fallback CZK.
     *
     * @return array{0:int, 1:string} [currency_id, code]
     */
    private function resolveStatementCurrency(int $supplierId, string $accountNumber): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, account_number FROM currencies WHERE supplier_id = ? AND is_active = 1
              ORDER BY is_default DESC, id ASC'
        );
        $stmt->execute([$supplierId]);
        $all = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($all as $c) {
            if (!empty($c['account_number'])
                && \MyInvoice\Service\Bank\AccountNumberNormalizer::equals((string) $c['account_number'], $accountNumber)) {
                return [(int) $c['id'], (string) $c['code']];
            }
        }
        foreach ($all as $c) {
            if ($c['code'] === 'CZK') return [(int) $c['id'], 'CZK'];
        }
        return $all ? [(int) $all[0]['id'], (string) $all[0]['code']] : [0, 'CZK'];
    }

    /**
     * Ověří, že bank_transaction patří aktuálnímu supplier-i (přes statement.account_number
     * → currencies.account_number/supplier_id). Vrací true / false; nevyhazuje výjimku,
     * caller pak vrátí 404.
     *
     * Sjednocený check pro všechny mutující ops na bank_transactions (match/ignore/unmatch).
     * Bez tohoto guardu by accountant z S1 mohl měnit transakce S2 (CWE-639 BOLA, security
     * report @andrejtomci #1).
     */
    private function txBelongsToCurrentSupplier(Request $request, int $txId): bool
    {
        $sid = SupplierGuard::currentId($request);
        if ($sid <= 0 || $txId <= 0) return false;
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?
                AND EXISTS (
                    SELECT 1 FROM currencies cur
                     WHERE cur.supplier_id = ?
                       AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                         = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                       AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )"
        );
        $stmt->execute([$txId, $sid]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Návrh faktur ke spárování dle částky + data (±14 dní), když transakce nemá
     * VS nebo VS nesedí. Prohledá vystavené i přijaté faktury — kvůli dobropisům
     * může příchozí platba patřit k přijaté faktuře a naopak, takže směr (znaménko)
     * nefiltrujeme. Zahrnuje i zaplacené faktury (duplicitní/druhá platba, doplatek).
     *
     * Měna: cizoměnová faktura placená z CZK účtu se porovnává přes kurz faktury
     * (CZK = částka × kurz) s relativní tolerancí (bankovní spread + drift). Vrací
     * seznam k výběru vč. přepočtené částky; ruční zadání VS zůstává druhou možností.
     *
     * Když v ±14 dnech nic nesedí, automaticky zkusí širší okno (±90 dní) a navíc
     * povolí i shodu na syrovou částku bez FX převodu (klient zaplatil "stejné číslo"
     * z cizoměnového účtu, aniž by šlo o skutečný kurzový přepočet — časté u
     * zahraničních plateb, kde odesílatel jen přepíše částku bez ohledu na měnu).
     * Takové kandidáty FE označí příznakem `currency_mismatch`.
     *
     * GET /api/bank-transactions/{id}/match-candidates → { candidates: [...], fallback: bool }
     */
    public function matchCandidates(Request $request, Response $response, array $args): Response
    {
        $txId = (int) ($args['id'] ?? 0);
        if (!$this->txBelongsToCurrentSupplier($request, $txId)) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $sid = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();

        // Efektivní měna transakce = měna transakce, jinak měna výpisu (= měna účtu), jinak CZK.
        $stmt = $pdo->prepare(
            "SELECT bt.amount, bt.posted_at,
                    UPPER(COALESCE(NULLIF(bt.currency,''), NULLIF(bs.currency,''), 'CZK')) AS ccy
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?"
        );
        $stmt->execute([$txId]);
        $tx = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $txAmount = abs((float) ($tx['amount'] ?? 0));
        $posted   = (string) ($tx['posted_at'] ?? date('Y-m-d'));
        $txCcy    = (string) ($tx['ccy'] ?? 'CZK');
        if ($txAmount <= 0.0) {
            return Json::ok($response, ['candidates' => [], 'fallback' => false]);
        }

        $candidates = $this->searchMatchCandidates($sid, $posted, $txAmount, $txCcy, self::CANDIDATE_DAY_WINDOW, false);
        $fallback = false;
        if ($candidates === []) {
            $fallback = true;
            $candidates = $this->searchMatchCandidates($sid, $posted, $txAmount, $txCcy, self::CANDIDATE_FALLBACK_DAY_WINDOW, true);
        }

        return Json::ok($response, ['candidates' => $candidates, 'fallback' => $fallback]);
    }

    /**
     * @return list<array{type:string,id:int,ref:?string,amount:float,currency:string,
     *   converted_amount:?float,converted_currency:?string,issue_date:string,due_date:string,
     *   party:?string,paid:bool,currency_mismatch:bool}>
     */
    private function searchMatchCandidates(int $sid, string $posted, float $txAmount, string $txCcy, int $win, bool $allowRawAmountFallback): array
    {
        $pdo = $this->db->pdo();

        // Otevřené i zaplacené doklady v okně ±N dní (vydané + přijaté). 'paid' zahrnujeme —
        // uživatel chce spárovat i s už zaplacenou fakturou (duplicitní/druhá platba, doplatek).
        // Částku NEfiltrujeme v SQL — kvůli cizí měně se porovnává přes kurz až v PHP.
        $issued = "SELECT 'invoice' AS mtype, i.id, i.varsymbol AS ref, i.amount_to_pay AS amount,
                          i.exchange_rate, i.issue_date, i.due_date, cur.code AS currency,
                          c.company_name AS party, i.status AS status
                     FROM invoices i
                     JOIN currencies cur ON cur.id = i.currency_id
                     LEFT JOIN clients c ON c.id = i.client_id
                    WHERE i.supplier_id = ?
                      AND i.status IN ('issued','sent','reminded','paid')
                      AND i.invoice_type IN ('invoice','proforma','credit_note')
                      AND (ABS(DATEDIFF(i.due_date, ?)) <= ? OR ABS(DATEDIFF(i.issue_date, ?)) <= ?)";

        $purchase = "SELECT 'purchase_invoice' AS mtype, p.id,
                            COALESCE(NULLIF(p.vendor_invoice_number,''), p.varsymbol) AS ref, p.amount_to_pay AS amount,
                            p.exchange_rate, p.issue_date, p.due_date, cur.code AS currency,
                            c.company_name AS party, p.status AS status
                       FROM purchase_invoices p
                       JOIN currencies cur ON cur.id = p.currency_id
                       LEFT JOIN clients c ON c.id = p.vendor_id
                      WHERE p.supplier_id = ?
                        AND p.status IN ('received','booked','paid')
                        AND (ABS(DATEDIFF(p.due_date, ?)) <= ? OR ABS(DATEDIFF(p.issue_date, ?)) <= ?)";

        $sql = "SELECT * FROM ($issued UNION ALL $purchase) cand ORDER BY cand.due_date DESC LIMIT 300";
        $branch = [$sid, $posted, $win, $posted, $win];
        $q = $pdo->prepare($sql);
        $q->execute(array_merge($branch, $branch));

        $absTol = self::CANDIDATE_AMOUNT_TOLERANCE;
        $pct    = self::CANDIDATE_FX_TOLERANCE_PCT;
        $local  = 'CZK';
        $postedTs = strtotime($posted) ?: time();

        $candidates = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $invAmt = (float) $r['amount'];
            // Dobropisy (vydané i přijaté) nesou ZÁPORNOU amount_to_pay (total_with_vat < 0),
            // jejich úhrada/refundace ale dorazí na účet s opačným znaménkem: přijatý dobropis
            // = dodavatel vrací → kladný pohyb. Porovnáváme proto magnitudy (|faktura| × |tx|),
            // jinak by se záporný kandidát na kladný pohyb nikdy netrefil do tolerance.
            $invMag = abs($invAmt);
            $invCcy = strtoupper((string) $r['currency']);
            $rate   = (float) ($r['exchange_rate'] ?: 0);
            if ($rate <= 0) {
                $rate = 1.0; // CZK faktura / chybějící kurz
            }

            $converted = null; // částka přepočtená do měny transakce (jen u cross-currency)
            $currencyMismatch = false;
            if ($invCcy === $txCcy) {
                $expected = $invMag;
                $tol = $absTol;
            } elseif ($txCcy === $local) {
                // Cizoměnová faktura placená v CZK → přepočet kurzem faktury (CZK = částka × kurz).
                $expected = $invMag * $rate;
                $tol = max($absTol, $expected * $pct);
                $converted = $expected;
            } elseif ($allowRawAmountFallback) {
                // Fallback (nic nesedí přesně): porovnej syrovou magnitudu BEZ FX převodu —
                // typicky zahraniční klient převede "stejné číslo" z cizoměnového účtu, aniž
                // by šlo o reálný kurzový přepočet. Hodnoty pak ekonomicky nesedí, ale číselně
                // ano — proto je uživatel musí potvrdit ručně, nikdy se to neděje automaticky.
                $expected = $invMag;
                $tol = $absTol;
                $currencyMismatch = true;
            } else {
                // Cizoměnový účet × jiná měna faktury — bez kurzu transakce nepřevedeme. Skip.
                continue;
            }

            $diff = abs($expected - $txAmount);
            if ($diff > $tol) {
                continue;
            }

            $dueTs = strtotime((string) $r['due_date']) ?: $postedTs;
            $candidates[] = [
                'type'               => $r['mtype'],
                'id'                 => (int) $r['id'],
                'ref'                => ($r['ref'] ?? '') !== '' ? (string) $r['ref'] : null,
                'amount'             => $invAmt,
                'currency'           => $invCcy,
                'converted_amount'   => $converted !== null ? round($converted, 2) : null,
                'converted_currency' => $converted !== null ? $txCcy : null,
                'issue_date'         => $r['issue_date'],
                'due_date'           => $r['due_date'],
                'party'              => $r['party'] !== null ? (string) $r['party'] : null,
                'paid'               => ($r['status'] ?? '') === 'paid',
                'currency_mismatch'  => $currencyMismatch,
                '_rel'               => $expected > 0 ? $diff / $expected : 0.0,
                '_dayDist'           => (int) round(abs($dueTs - $postedTs) / 86400),
            ];
        }

        // Nejlepší relativní shoda první; při shodě nejbližší datum splatnosti (fallback =
        // "nejlepší shoda" myšleno i časově, ne jen nejnovější); cap 25.
        usort($candidates, static fn (array $a, array $b): int =>
            ($a['_rel'] <=> $b['_rel']) ?: ($a['_dayDist'] <=> $b['_dayDist']));
        $candidates = array_slice($candidates, 0, 25);
        foreach ($candidates as &$c) {
            unset($c['_rel'], $c['_dayDist']);
        }
        unset($c);

        return $candidates;
    }

    /**
     * Návrhy sloučené úhrady: jedna PŘÍCHOZÍ platba pokrývá VÍCE vystavených faktur
     * (klient zaplatil 2+ faktur jednou platbou, součet sedí, VS nesedí).
     *
     * GET /api/bank-transactions/{id}/split-suggestions?invoice_id=&window=&max=
     *   → { suggestions: [{ client_id, client_name, total, currency, count, invoices: [...] }], window, max }
     *
     * Tvrdé omezení: kombinace jen v rámci JEDNOHO klienta (client_id). Default okno
     * ±7 dní kolem data platby (rozšiřitelné). Klient s názvem podobným protistraně se
     * řadí první. Volitelná „kotva" invoice_id = uživatel už jednu fakturu vybral a
     * částka nesedí → dohledáme další faktury TÉHOŽ klienta, aby součet seděl.
     */
    public function splitSuggestions(Request $request, Response $response, array $args): Response
    {
        $txId = (int) ($args['id'] ?? 0);
        if (!$this->txBelongsToCurrentSupplier($request, $txId)) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $sid = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();

        $q = $request->getQueryParams();
        $window = (int) ($q['window'] ?? self::SPLIT_DAY_WINDOW);
        $window = max(1, min(self::SPLIT_DAY_WINDOW_MAX, $window));
        $maxInv = (int) ($q['max'] ?? self::SPLIT_MAX_INVOICES);
        $maxInv = max(2, min(self::SPLIT_MAX_INVOICES, $maxInv));
        $anchorId = (int) ($q['invoice_id'] ?? 0);

        // Efektivní měna transakce + částka (jen příchozí — split je sloučená úhrada NÁM).
        $stmt = $pdo->prepare(
            "SELECT bt.amount, bt.posted_at, bt.counterparty_name,
                    UPPER(COALESCE(NULLIF(bt.currency,''), NULLIF(bs.currency,''), 'CZK')) AS ccy
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?"
        );
        $stmt->execute([$txId]);
        $tx = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $txAmount = round((float) ($tx['amount'] ?? 0), 2);
        if ($txAmount <= 0.0) {
            // Sloučená úhrada dává smysl jen u příchozí platby (klient platí nám).
            return Json::ok($response, ['suggestions' => [], 'window' => $window, 'max' => $maxInv]);
        }
        $posted = (string) ($tx['posted_at'] ?? date('Y-m-d'));
        $txCcy  = (string) ($tx['ccy'] ?? 'CZK');
        $cpName = (string) ($tx['counterparty_name'] ?? '');

        // Kotva: omez na klienta vybrané faktury (musí patřit tenantovi a být otevřená).
        $anchorClientId = 0;
        if ($anchorId > 0) {
            $a = $pdo->prepare(
                "SELECT client_id FROM invoices
                  WHERE id = ? AND supplier_id = ?
                    AND status IN ('issued','sent','reminded','paid')
                    AND invoice_type IN ('invoice','proforma')"
            );
            $a->execute([$anchorId, $sid]);
            $anchorClientId = (int) $a->fetchColumn();
            if ($anchorClientId <= 0) {
                return Json::ok($response, ['suggestions' => [], 'window' => $window, 'max' => $maxInv]);
            }
        }

        // Pool: vystavené faktury v okně ±window dní. Zahrnuje i ZAPLACENÉ — u nich jde
        // o rekonciliaci (navázat existující platbu na transakci), proto k nim tahám
        // sumu+počet dosud nenavázaných plateb (bank_transaction_id IS NULL). Částku
        // NEfiltrujeme v SQL (efektivní příspěvek se počítá v PHP — kvůli cizí měně + paid).
        $sql = "SELECT i.id, i.client_id, i.varsymbol AS ref, i.amount_to_pay, i.paid_total, i.status,
                       i.exchange_rate, i.issue_date, i.due_date, cur.code AS currency,
                       c.company_name AS party,
                       (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip
                         WHERE ip.invoice_id = i.id AND ip.bank_transaction_id IS NULL) AS reconcilable,
                       (SELECT COUNT(*) FROM invoice_payments ip
                         WHERE ip.invoice_id = i.id AND ip.bank_transaction_id IS NULL) AS reconcilable_count
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
             LEFT JOIN clients c ON c.id = i.client_id
                 WHERE i.supplier_id = ?
                   AND i.client_id IS NOT NULL
                   AND i.status IN ('issued','sent','reminded','paid')
                   AND i.invoice_type IN ('invoice','proforma')
                   AND (ABS(DATEDIFF(i.due_date, ?)) <= ? OR ABS(DATEDIFF(i.issue_date, ?)) <= ?)";
        $params = [$sid, $posted, $window, $posted, $window];
        if ($anchorClientId > 0) {
            $sql .= " AND i.client_id = ?";
            $params[] = $anchorClientId;
        }
        $sql .= " ORDER BY ABS(DATEDIFF(COALESCE(i.due_date, i.issue_date), ?)) ASC, i.id DESC LIMIT 600";
        $params[] = $posted;
        $ps = $pdo->prepare($sql);
        $ps->execute($params);

        // Seskup do klientů + přepočti zbytek do měny transakce.
        $byClient = [];      // client_id => list of item
        $anchorItem = null;
        foreach ($ps->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $remaining = round((float) $r['amount_to_pay'] - (float) $r['paid_total'], 2);
            $isPaid    = (string) $r['status'] === 'paid' || $remaining <= self::CANDIDATE_AMOUNT_TOLERANCE;
            if ($isPaid) {
                // Zaplacená faktura → rekonciliace: efektivní příspěvek = dosud nenavázaná
                // platba. Nabízíme jen když existuje PRÁVĚ JEDNA (jinak nejednoznačné /
                // už spárované s jinou transakcí — viz reconcileToBankTransaction).
                if ((int) ($r['reconcilable_count'] ?? 0) !== 1) {
                    continue;
                }
                $effective = round((float) ($r['reconcilable'] ?? 0), 2);
            } else {
                $effective = $remaining;
            }
            if ($effective <= 0) {
                continue;
            }
            $invCcy = strtoupper((string) $r['currency']);
            $rate   = (float) ($r['exchange_rate'] ?: 0);
            $conv   = $this->remainingInTxCurrency($effective, $invCcy, $rate, $txCcy);
            if ($conv === null) {
                continue; // cizoměnový účet × jiná měna faktury bez kurzu → nepřevedeme
            }
            $cid = (int) $r['client_id'];
            $item = [
                'id'              => (int) $r['id'],
                'ref'             => ($r['ref'] ?? '') !== '' ? (string) $r['ref'] : null,
                'amount'          => $effective,
                'currency'        => $invCcy,
                'converted'       => round($conv, 2),
                'is_fx'           => $invCcy !== $txCcy,
                'is_paid'         => $isPaid,
                'issue_date'      => $r['issue_date'],
                'due_date'        => $r['due_date'],
                'party'           => $r['party'] !== null ? (string) $r['party'] : null,
            ];
            $byClient[$cid][] = $item;
            if ($anchorId > 0 && $item['id'] === $anchorId) {
                $anchorItem = $item;
            }
        }
        if ($anchorId > 0 && $anchorItem === null) {
            return Json::ok($response, ['suggestions' => [], 'window' => $window, 'max' => $maxInv]);
        }

        $suggestions = [];
        foreach ($byClient as $cid => $items) {
            // Pool na klienta omezíme (pořadí už dle blízkosti data); kotvu vždy ponecháme.
            if (count($items) > self::SPLIT_POOL_PER_CLIENT) {
                if ($anchorItem !== null) {
                    $items = array_values(array_filter($items, static fn ($x) => $x['id'] !== $anchorId));
                    $items = array_slice($items, 0, self::SPLIT_POOL_PER_CLIENT - 1);
                    $items[] = $anchorItem;
                } else {
                    $items = array_slice($items, 0, self::SPLIT_POOL_PER_CLIENT);
                }
            }
            $hasFx = false;
            foreach ($items as $it) {
                if ($it['is_fx']) { $hasFx = true; break; }
            }
            $tol = max(self::CANDIDATE_AMOUNT_TOLERANCE, $hasFx ? $txAmount * self::CANDIDATE_FX_TOLERANCE_PCT : 0.0);

            if ($anchorItem !== null) {
                // Kotva fixní: dohledej kombinace ZBYTKU (1..max-1) na (cíl − kotva).
                $rest = array_values(array_filter($items, static fn ($x) => $x['id'] !== $anchorId));
                $combos = $this->findSubsetsSummingTo($rest, $txAmount - $anchorItem['converted'], $tol, 1, $maxInv - 1);
                foreach ($combos as $combo) {
                    array_unshift($combo, $anchorItem);
                    $suggestions[] = $this->buildSuggestion($cid, $combo, $txCcy, $cpName, $items[0]['party'] ?? null, $txAmount, $posted);
                }
            } else {
                $combos = $this->findSubsetsSummingTo($items, $txAmount, $tol, 2, $maxInv);
                foreach ($combos as $combo) {
                    $suggestions[] = $this->buildSuggestion($cid, $combo, $txCcy, $cpName, $items[0]['party'] ?? null, $txAmount, $posted);
                }
            }
        }

        // Řazení: shoda jména protistrany (desc), méně faktur, faktury blíž datu platby
        // (víc kombinací se stejným součtem vzniká, když mají faktury shodné částky —
        // preferuj tu „nejbližší", ne náhodnou s daleko splatnou fakturou), menší odchylka.
        usort($suggestions, static function (array $a, array $b): int {
            return ($b['_name_sim'] <=> $a['_name_sim'])
                ?: ($a['count'] <=> $b['count'])
                ?: ($a['_date_dist'] <=> $b['_date_dist'])
                ?: ($a['_diff'] <=> $b['_diff']);
        });
        $suggestions = array_slice($suggestions, 0, self::SPLIT_MAX_SUGGESTIONS);
        foreach ($suggestions as &$s) {
            unset($s['_name_sim'], $s['_diff'], $s['_date_dist']);
        }
        unset($s);

        return Json::ok($response, ['suggestions' => $suggestions, 'window' => $window, 'max' => $maxInv]);
    }

    /**
     * Sestaví návrh kombinace pro odpověď (+ pomocná pole pro řazení).
     * @param list<array<string,mixed>> $combo
     */
    private function buildSuggestion(int $clientId, array $combo, string $txCcy, string $cpName, ?string $party, float $target, string $posted): array
    {
        $total = 0.0;
        $invoices = [];
        $dateDist = 0.0;
        $postedTs = strtotime($posted) ?: 0;
        foreach ($combo as $it) {
            $total += (float) $it['converted'];
            $ref = $it['due_date'] ?? $it['issue_date'] ?? null;
            if ($ref !== null && $postedTs > 0) {
                $refTs = strtotime((string) $ref);
                if ($refTs !== false) {
                    $dateDist += abs($refTs - $postedTs) / 86400;
                }
            }
            $invoices[] = [
                'id'         => $it['id'],
                'ref'        => $it['ref'],
                'amount'     => $it['amount'],
                'currency'   => $it['currency'],
                'converted'  => $it['is_fx'] ? $it['converted'] : null,
                'is_paid'    => (bool) ($it['is_paid'] ?? false),
                'issue_date' => $it['issue_date'],
                'due_date'   => $it['due_date'],
            ];
        }
        return [
            'client_id'   => $clientId,
            'client_name' => $party,
            'currency'    => $txCcy,
            'total'       => round($total, 2),
            'count'       => count($invoices),
            'invoices'    => $invoices,
            '_name_sim'   => $this->nameSimilarity($cpName, (string) $party),
            '_diff'       => abs(round($total, 2) - $target),
            '_date_dist'  => round($dateDist, 2),
        ];
    }

    /**
     * Zbytek faktury přepočtený do měny transakce (mirror txAmountInInvoiceCurrency,
     * opačný směr): stejná měna → přímo; CZK transakce × cizoměnová faktura → ×kurz;
     * cizoměnový účet × jiná měna faktury bez kurzu → null (nepřevedeme).
     */
    private function remainingInTxCurrency(float $remaining, string $invCcy, float $rate, string $txCcy): ?float
    {
        $invCcy = strtoupper($invCcy);
        $txCcy  = strtoupper($txCcy);
        if ($invCcy === $txCcy) {
            return $remaining;
        }
        // Cizoměnová faktura placená v CZK → přepočet kurzem faktury. Bez platného kurzu
        // NEpřevádíme (žádný tichý fallback 1:1 — to by vyrobilo nesmyslnou částku platby).
        if ($txCcy === 'CZK' && $rate > 0) {
            return $remaining * $rate;
        }
        return null;
    }

    /**
     * Najde kombinace položek, jejichž součet `converted` ≈ target (±tol), o velikosti
     * minSize..maxSize. DFS s prořezáváním (položky setříděné sestupně). Vrací list
     * kombinací (každá = list položek). Omezeno na rozumný počet řešení.
     *
     * @param list<array<string,mixed>> $items
     * @return list<list<array<string,mixed>>>
     */
    private function findSubsetsSummingTo(array $items, float $target, float $tol, int $minSize, int $maxSize): array
    {
        if ($target <= -$tol || $maxSize < $minSize || $minSize < 1) {
            return [];
        }
        // Sestupně dle converted → dřívější prořezání při překročení cíle.
        usort($items, static fn ($a, $b) => $b['converted'] <=> $a['converted']);
        $n = count($items);
        $results = [];
        $limit = 30; // strop řešení (anti-exploze u stejných částek)

        $dfs = function (int $start, array $picked, float $sum) use (&$dfs, &$results, $items, $n, $target, $tol, $minSize, $maxSize, $limit): void {
            if (count($results) >= $limit) {
                return;
            }
            if ($sum > $target + $tol) {
                return; // všechny converted > 0 → další přidání jen zvýší součet
            }
            if (count($picked) >= $minSize && abs($sum - $target) <= $tol) {
                $results[] = $picked;
                // pokračujeme dál — můžou být jiné kombinace, ale tuhle nerozšiřujeme
                return;
            }
            if (count($picked) >= $maxSize) {
                return;
            }
            for ($i = $start; $i < $n; $i++) {
                $next = $picked;
                $next[] = $items[$i];
                $dfs($i + 1, $next, $sum + (float) $items[$i]['converted']);
                if (count($results) >= $limit) {
                    return;
                }
            }
        };
        $dfs(0, [], 0.0);
        return $results;
    }

    /**
     * Podobnost dvou názvů firem (0..1) — Jaccard překryv normalizovaných tokenů.
     * Mirror StatementMatcher::nameSimilarity (sdílený význam pro fuzzy shodu protistrany).
     */
    private function nameSimilarity(string $a, string $b): float
    {
        $ta = $this->nameTokens($a);
        $tb = $this->nameTokens($b);
        if (!$ta || !$tb) {
            return 0.0;
        }
        $inter = array_intersect($ta, $tb);
        $union = array_unique(array_merge($ta, $tb));
        return count($union) > 0 ? count($inter) / count($union) : 0.0;
    }

    /**
     * Normalizace názvu na tokeny (mirror StatementMatcher::nameTokens).
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        $s = mb_strtoupper($name, 'UTF-8');
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? '';
        $stop = ['SRO', 'AS', 'INC', 'LTD', 'LLC', 'GMBH', 'VOS', 'SPOL', 'THE', 'AND',
                 'CZ', 'CZE', 'SK', 'SVK', 'DE', 'DEU', 'NL', 'NLD', 'USA', 'GBR', 'AT', 'AUT',
                 'PRAHA', 'PRAGUE', 'BRNO', 'PLZEN', 'OSTRAVA'];
        $tokens = [];
        foreach (preg_split('/\s+/', trim($s)) as $tok) {
            if (strlen($tok) < 3 || in_array($tok, $stop, true)) {
                continue;
            }
            $tokens[] = $tok;
        }
        return array_values(array_unique($tokens));
    }

    public function manualMatch(Request $request, Response $response, array $args): Response
    {
        $txId = (int) ($args['id'] ?? 0);
        if (!$this->txBelongsToCurrentSupplier($request, $txId)) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $invoiceId = (int) ($body['invoice_id'] ?? 0);
        $purchaseInvoiceId = (int) ($body['purchase_invoice_id'] ?? 0);
        $varsymbol = trim((string) ($body['varsymbol'] ?? ''));

        // Sloučená úhrada (split): jedna příchozí platba → více vystavených faktur.
        if (isset($body['invoice_ids']) && is_array($body['invoice_ids'])) {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $body['invoice_ids']),
                static fn (int $v): bool => $v > 0,
            )));
            if (count($ids) >= 2) {
                return $this->manualMatchSplit($request, $response, $txId, $ids);
            }
            if (count($ids) === 1) {
                $invoiceId = $ids[0]; // degraduj na běžné 1:1 párování
            }
        }

        // Purchase invoice match (přijatá faktura — outgoing payment)
        if ($purchaseInvoiceId > 0) {
            return $this->manualMatchPurchase($request, $response, $txId, $purchaseInvoiceId);
        }

        // Pokud uživatel poslal varsymbol místo invoice_id, najdi fakturu v supplier scope.
        // Fallback: zkus i přijaté faktury (purchase_invoices) — pro outgoing transakce.
        if ($invoiceId <= 0 && $varsymbol !== '') {
            $sid = SupplierGuard::currentId($request);
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1'
            );
            $stmt->execute([$sid, $varsymbol]);
            $invoiceId = (int) $stmt->fetchColumn();
            if ($invoiceId <= 0) {
                // Fallback: purchase_invoice match (přijatá faktura, my platíme dodavateli)
                $stmt = $this->db->pdo()->prepare(
                    // OR na vendor_invoice_number — viz StatementMatcher::matchPurchase
                    // (uživatel při manuálním matchi taky zadá VS dodavatele, ne naše PF-...).
                    'SELECT id FROM purchase_invoices
                       WHERE supplier_id = ?
                         AND (varsymbol = ? OR vendor_invoice_number = ?)
                       LIMIT 1'
                );
                $stmt->execute([$sid, $varsymbol, $varsymbol]);
                $pid = (int) $stmt->fetchColumn();
                if ($pid > 0) {
                    return $this->manualMatchPurchase($request, $response, $txId, $pid);
                }
                return Json::error($response, 'invoice_not_found',
                    "Faktura ani přijatá faktura s VS '$varsymbol' nenalezena.", 404);
            }
        }

        if ($invoiceId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí invoice_id nebo varsymbol.', 400);
        }

        // Faktura musí patřit aktuálnímu supplier (anti cross-supplier match)
        $invoice = $this->invoices->find($invoiceId);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'invoice_not_found', 'Faktura nenalezena.', 404);
        }
        if (
            in_array($invoice['status'], ['issued', 'sent', 'reminded'], true)
            && !InvoiceAmountPolicy::canBeMarkedPaid($invoice)
        ) {
            return Json::error($response, 'invalid_amount', InvoiceAmountPolicy::NON_POSITIVE_MARK_PAID_MESSAGE, 409);
        }

        $pdo = $this->db->pdo();

        // Načti transakci pro posted_at (datum úhrady ze skutečnosti, ne dnes), částku
        // a měnu (pro záznam platby v měně faktury) + statement_id.
        $tx = $pdo->prepare(
            'SELECT bt.posted_at, bt.statement_id, bt.amount, bt.variable_symbol, bt.bank_ref,
                    COALESCE(NULLIF(bt.currency, ""), bs.currency) AS tx_currency
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $tx->execute([$txId]);
        $txRow = $tx->fetch(\PDO::FETCH_ASSOC) ?: [];
        $postedAt = (string) ($txRow['posted_at'] ?? date('Y-m-d'));
        $statementId = (int) ($txRow['statement_id'] ?? 0);

        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);

        // Guard: transakce už založila platbu na JINÉ faktuře — tiché přepárování by
        // nechalo platbu (a paid stav) na původní faktuře a novou by jen flagnulo.
        // Uživatel musí nejdřív zrušit stávající spárování (smaže i platbu).
        $existingPayment = $pdo->prepare(
            'SELECT invoice_id FROM invoice_payments WHERE bank_transaction_id = ?'
        );
        $existingPayment->execute([$txId]);
        $existingPaymentInvoiceId = $existingPayment->fetchColumn();
        if ($existingPaymentInvoiceId !== false && (int) $existingPaymentInvoiceId !== $invoiceId) {
            return Json::error(
                $response,
                'tx_already_paired',
                'Transakce už eviduje platbu na jiné faktuře. Nejdřív zruš stávající spárování.',
                409,
            );
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = ?, match_status = 'manual', matched_at = NOW(), matched_by = ?
                  WHERE id = ?"
            )->execute([$invoiceId, $userId ?: null, $txId]);

            // Pokud faktura ještě není paid/cancelled, zaeviduj platbu (#89) — částka
            // transakce v měně faktury. Plné pokrytí → service překlopí na 'paid';
            // podplatba → faktura zůstává pohledávkou (částečná úhrada).
            $finalDraftId = null;
            $taxDocId = null;
            $markedPaid = false;
            $partialPayment = false;
            if (in_array($invoice['status'], ['issued', 'sent', 'reminded'], true)) {
                $remaining = round((float) ($invoice['amount_to_pay'] ?? 0) - (float) ($invoice['paid_total'] ?? 0), 2);
                $invAmount = $this->txAmountInInvoiceCurrency(
                    (float) ($txRow['amount'] ?? 0),
                    (string) ($invoice['currency'] ?? 'CZK'),
                    (float) ($invoice['exchange_rate'] ?? 0),
                    isset($txRow['tx_currency']) && $txRow['tx_currency'] !== null ? (string) $txRow['tx_currency'] : null,
                    $remaining,
                );

                // Idempotence: transakce už mohla platbu založit (legacy auto_partial flag
                // z dob před evidencí plateb ji nemá, nově ano) — nevkládat duplicitně.
                $existing = $pdo->prepare('SELECT id FROM invoice_payments WHERE bank_transaction_id = ?');
                $existing->execute([$txId]);
                if ($existing->fetchColumn() === false && $invAmount > 0) {
                    $recorded = $this->payments->recordPayment($invoiceId, $invAmount, $postedAt, [
                        'source'              => 'bank',
                        'bank_transaction_id' => $txId,
                        'variable_symbol'     => isset($txRow['variable_symbol']) ? (string) $txRow['variable_symbol'] : null,
                        'bank_reference'      => isset($txRow['bank_ref']) ? (string) $txRow['bank_ref'] : null,
                        'created_by'          => $userId,
                    ]);
                    $markedPaid = $recorded['became_paid'];
                    $partialPayment = !$recorded['became_paid'];

                    if (($invoice['invoice_type'] ?? '') === 'proforma') {
                        if ($markedPaid) {
                            // Zaplacená proforma → DRAFT finální faktury (DUZP = datum platby)
                            $finalDraftId = $this->finalCreator->create($invoiceId, $userId ?: 0, $postedAt);
                        } else {
                            // Částečná úhrada proformy → DRAFT daňového dokladu k přijaté
                            // platbě (plátce DPH, ne-RC; creator si podmínky hlídá sám).
                            try {
                                $taxDocId = $this->taxDocCreator->createForPayment((int) $recorded['payment_id'], $userId ?: 0);
                            } catch (\RuntimeException) {
                                // Neplátce / reverse charge — doklad se nevystavuje.
                            }
                        }
                    }
                }
            }

            // Recompute matched_count na výpisu (pro UI badge "12/14")
            if ($statementId > 0) {
                $pdo->prepare(
                    "UPDATE bank_statements
                        SET matched_count = (
                            SELECT COUNT(*) FROM bank_transactions
                             WHERE statement_id = ?
                               AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                        )
                      WHERE id = ?"
                )->execute([$statementId, $statementId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::error($response, 'match_failed', 'Manuální párování selhalo: ' . $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.tx_manual_match', $userId ?: null, 'bank_transaction', $txId, [
            'invoice_id'      => $invoiceId,
            'paid_at'         => $postedAt,
            'final_draft_id'  => $finalDraftId,
            'partial_payment' => $partialPayment,
            'tax_document_id' => $taxDocId,
        ], $ip, $request->getHeaderLine('User-Agent'));
        if ($finalDraftId !== null) {
            $this->logger->log('proforma.final_issued', $userId ?: null, 'invoice', $invoiceId, [
                'final_invoice_id' => $finalDraftId,
                'trigger'          => 'bank_match_manual',
            ], $ip, $request->getHeaderLine('User-Agent'));
        }
        // Děkovný e-mail za úhradu (issue #57) — jen při autom. označení po párování
        // a jen pokud má dodavatel zapnuté auto-odesílání. Mimo transakci, best-effort
        // (service chyby odchytí — selhání e-mailu nesmí rozbít spárování).
        $thanks = null;
        if ($markedPaid) {
            $thanks = $this->paymentThanks->sendForInvoice(
                $invoiceId,
                'bank_match',
                $userId ?: null,
                $ip,
                $request->getHeaderLine('User-Agent'),
                requireUnsent: true,
            );
        }

        $result = ['matched' => true, 'paid_at' => $postedAt];
        if ($finalDraftId !== null) {
            $result['final_draft_id'] = $finalDraftId;
        }
        if ($partialPayment) {
            $result['partial_payment'] = true;
        }
        if ($taxDocId !== null) {
            $result['tax_document_id'] = $taxDocId;
        }
        if ($thanks !== null && ($thanks['status'] ?? '') === 'sent') {
            $result['payment_thanks_sent'] = true;
        }
        return Json::ok($response, $result);
    }

    /**
     * Částka transakce v měně faktury (mirror StatementMatcher::txAmountInInvoiceCurrency):
     * stejná/neznámá měna → přímo; CZK platba cizoměnové faktury → děleno kurzem faktury;
     * jinak $fallback (zbývající částka — manuální match = uživatel říká „tahle platba
     * patří k téhle faktuře", bez převoditelné měny bereme doplacení zbytku).
     */
    private function txAmountInInvoiceCurrency(float $txAmount, string $invCcy, float $rate, ?string $txCurrency, float $fallback): float
    {
        if ($txCurrency === null || strtoupper($txCurrency) === strtoupper($invCcy)) {
            return round($txAmount, 2);
        }
        if (strtoupper($txCurrency) === 'CZK') {
            $r = $rate > 0 ? $rate : 1.0;
            return round($txAmount / $r, 2);
        }
        return round($fallback, 2);
    }

    /**
     * Sloučená úhrada: jedna PŘÍCHOZÍ platba pokryje VÍCE vystavených faktur naráz
     * (klient zaplatil 2+ faktur jednou platbou, součet sedí, VS nesedí).
     *
     * Pravidla (potvrzeno se zadavatelem):
     *   - jen příchozí platba (amount > 0); přijaté faktury split neřeší (mají vlastní cestu);
     *   - VŠECHNY faktury musí patřit STEJNÉMU klientovi (tvrdý guard i v potvrzení);
     *   - každá faktura se uhradí svým PLNÝM zbytkem → součet zbytků (v měně platby) musí
     *     ≈ částka platby (tolerance jako u kandidátů). Žádné částečné rozpouštění platby.
     *
     * Každá faktura dostane řádek v invoice_payments se stejným bank_transaction_id
     * (migrace 0119 uvolnila UNIQUE na (bank_transaction_id, invoice_id)). Zaplacená
     * proforma → DRAFT finální faktury. bank_transactions.matched_invoice_id ukazuje na
     * první fakturu (kvůli kompatibilitě UI/odpárování); úplný seznam drží invoice_payments.
     *
     * @param list<int> $invoiceIds
     */
    private function manualMatchSplit(Request $request, Response $response, int $txId, array $invoiceIds): Response
    {
        $sid = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();
        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);

        // Transakce — jen příchozí (sloučená úhrada nám).
        $tx = $pdo->prepare(
            'SELECT bt.posted_at, bt.statement_id, bt.amount, bt.variable_symbol, bt.bank_ref,
                    UPPER(COALESCE(NULLIF(bt.currency, ""), NULLIF(bs.currency, ""), "CZK")) AS tx_currency
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $tx->execute([$txId]);
        $txRow = $tx->fetch(\PDO::FETCH_ASSOC) ?: [];
        $txAmount = round((float) ($txRow['amount'] ?? 0), 2);
        if ($txAmount <= 0.0) {
            return Json::error($response, 'not_incoming',
                'Sloučenou úhradu lze spárovat jen u příchozí (kladné) platby.', 400);
        }
        $postedAt = (string) ($txRow['posted_at'] ?? date('Y-m-d'));
        $statementId = (int) ($txRow['statement_id'] ?? 0);
        $txCcy = (string) ($txRow['tx_currency'] ?? 'CZK');

        // Načti vybrané faktury (supplier scope). Pořadí dle vstupu.
        $place = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT i.id, i.supplier_id, i.invoice_type, i.status, i.client_id,
                    i.amount_to_pay, i.paid_total, i.exchange_rate, cur.code AS currency,
                    (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip
                      WHERE ip.invoice_id = i.id AND ip.bank_transaction_id IS NULL) AS reconcilable,
                    (SELECT COUNT(*) FROM invoice_payments ip
                      WHERE ip.invoice_id = i.id AND ip.bank_transaction_id IS NULL) AS reconcilable_count
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id IN ($place)"
        );
        $stmt->execute($invoiceIds);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }

        $clientId = null;
        $sumConverted = 0.0;
        $hasFx = false;
        $toPay = [];      // id => částka k úhradě v měně faktury (jen NEzaplacené → recordPayment)
        $reconcile = [];  // id => true (ZAPLACENÁ faktura → jen navázat existující platbu)
        $convById = [];   // id => příspěvek faktury v měně platby (pro re-validaci pod zámkem)
        foreach ($invoiceIds as $iid) {
            $inv = $byId[$iid] ?? null;
            if ($inv === null || (int) $inv['supplier_id'] !== $sid) {
                return Json::error($response, 'invoice_not_found', "Faktura #$iid nenalezena.", 404);
            }
            if (!in_array((string) $inv['invoice_type'], ['invoice', 'proforma'], true)) {
                return Json::error($response, 'invalid_type',
                    "Doklad #$iid není faktura ani zálohová faktura.", 409);
            }
            if (!in_array((string) $inv['status'], ['issued', 'sent', 'reminded', 'paid'], true)) {
                return Json::error($response, 'invalid_status',
                    "Fakturu #$iid v jejím stavu nelze takto spárovat.", 409);
            }
            $cid = $inv['client_id'] !== null ? (int) $inv['client_id'] : 0;
            if ($cid <= 0) {
                return Json::error($response, 'no_client', "Faktura #$iid nemá klienta.", 409);
            }
            if ($clientId === null) {
                $clientId = $cid;
            } elseif ($cid !== $clientId) {
                return Json::error($response, 'client_mismatch',
                    'Sloučená úhrada musí být v rámci jednoho klienta.', 409);
            }
            $remaining = round((float) $inv['amount_to_pay'] - (float) $inv['paid_total'], 2);
            $isPaid = (string) $inv['status'] === 'paid' || $remaining <= self::CANDIDATE_AMOUNT_TOLERANCE;
            if ($isPaid) {
                // Zaplacená faktura → rekonciliace (navázat existující platbu, žádné dvojí
                // zdanění/přeplacení). Vyžaduje právě jednu dosud nenavázanou platbu.
                if ((int) ($inv['reconcilable_count'] ?? 0) !== 1) {
                    return Json::error($response, 'cannot_reconcile',
                        "Fakturu #$iid nelze rekonciliovat (nemá právě jednu nenavázanou platbu).", 409);
                }
                $contrib = round((float) ($inv['reconcilable'] ?? 0), 2);
                if ($contrib <= 0) {
                    return Json::error($response, 'cannot_reconcile',
                        "Fakturu #$iid nelze rekonciliovat (nulová nenavázaná platba).", 409);
                }
                $reconcile[$iid] = true;
            } else {
                if ($remaining <= 0) {
                    return Json::error($response, 'nothing_to_pay', "Faktura #$iid nemá co uhradit.", 409);
                }
                $contrib = $remaining;
                $toPay[$iid] = $remaining;
            }
            $conv = $this->remainingInTxCurrency(
                $contrib, (string) $inv['currency'], (float) ($inv['exchange_rate'] ?: 0), $txCcy
            );
            if ($conv === null) {
                return Json::error($response, 'currency_mismatch',
                    "Fakturu #$iid nelze převést do měny platby (chybí kurz).", 409);
            }
            $convById[$iid] = round($conv, 2);
            $sumConverted += $conv;
            if (strtoupper((string) $inv['currency']) !== $txCcy) {
                $hasFx = true;
            }
        }

        // Guard: součet zbytků (v měně platby) musí ≈ částka platby.
        $tol = max(self::CANDIDATE_AMOUNT_TOLERANCE, $hasFx ? $txAmount * self::CANDIDATE_FX_TOLERANCE_PCT : 0.0);
        if (abs(round($sumConverted, 2) - $txAmount) > $tol) {
            return Json::error($response, 'sum_mismatch',
                'Součet faktur (' . number_format(round($sumConverted, 2), 2, ',', ' ') . ' ' . $txCcy
                . ') neodpovídá částce platby (' . number_format($txAmount, 2, ',', ' ') . ' ' . $txCcy . ').',
                409);
        }

        // Guard: transakce už eviduje platbu na faktuře MIMO vybranou množinu → odpárovat napřed.
        $existing = $pdo->prepare('SELECT invoice_id FROM invoice_payments WHERE bank_transaction_id = ?');
        $existing->execute([$txId]);
        $alreadyPaidIds = array_map('intval', $existing->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        foreach ($alreadyPaidIds as $paidIid) {
            if (!in_array($paidIid, $invoiceIds, true)) {
                return Json::error($response, 'tx_already_paired',
                    'Transakce už eviduje platbu na jiné faktuře. Nejdřív zruš stávající spárování.', 409);
            }
        }

        $finalDraftIds = [];
        $paidInvoiceIds = [];
        $pdo->beginTransaction();
        try {
            // Anti-TOCTOU (race → přeplacení): zamkni faktury a přepočti zbytky POD ZÁMKEM.
            // Souběžná platba (jiná tx / dvojklik / cron rematch) by jinak mohla fakturu
            // přeplatit — guard součtu výše běžel na hodnotách načtených mimo transakci.
            // Po zámku znovu ověříme součet; pokud se stav mezitím změnil → rollback + 409.
            $lock = $pdo->prepare("SELECT id, amount_to_pay, paid_total FROM invoices WHERE id IN ($place) FOR UPDATE");
            $lock->execute($invoiceIds);
            $lockedRem = [];
            foreach ($lock->fetchAll(\PDO::FETCH_ASSOC) as $lr) {
                $lockedRem[(int) $lr['id']] = round((float) $lr['amount_to_pay'] - (float) $lr['paid_total'], 2);
            }
            $sumLocked = 0.0;
            $newCount = 0;
            foreach ($invoiceIds as $iid) {
                if (in_array($iid, $alreadyPaidIds, true)) {
                    continue; // platba už existuje (idempotence) — nezahrnuj do součtu
                }
                $newCount++;
                if (isset($reconcile[$iid])) {
                    // Zaplacená faktura: rekonciliace nemění paid_total, příspěvek je fixní
                    // (ověřený nad nenavázanou platbou před zámkem). Žádné riziko přeplacení.
                    $sumLocked += $convById[$iid];
                    continue;
                }
                $rem = $lockedRem[$iid] ?? 0.0;
                if ($rem <= 0) {
                    $pdo->rollBack();
                    return Json::error($response, 'state_changed',
                        'Stav faktur se mezitím změnil (faktura už nemá co uhradit). Zkus párování znovu.', 409);
                }
                $conv = $this->remainingInTxCurrency(
                    $rem, (string) $byId[$iid]['currency'], (float) ($byId[$iid]['exchange_rate'] ?: 0), $txCcy
                );
                if ($conv === null) {
                    $pdo->rollBack();
                    return Json::error($response, 'currency_mismatch',
                        "Fakturu #$iid nelze převést do měny platby (chybí kurz).", 409);
                }
                $toPay[$iid] = $rem;
                $sumLocked += $conv;
            }
            if ($newCount > 0 && abs(round($sumLocked, 2) - $txAmount) > $tol) {
                $pdo->rollBack();
                return Json::error($response, 'state_changed',
                    'Stav faktur se mezitím změnil (součet už nesedí na částku platby). Zkus párování znovu.', 409);
            }

            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = ?, match_status = 'manual', matched_at = NOW(), matched_by = ?
                  WHERE id = ?"
            )->execute([$invoiceIds[0], $userId ?: null, $txId]);

            foreach ($invoiceIds as $iid) {
                if (in_array($iid, $alreadyPaidIds, true)) {
                    continue; // idempotence — platba už existuje (opakované potvrzení)
                }
                if (isset($reconcile[$iid])) {
                    // Zaplacená faktura → navázat existující platbu na transakci (bez nové platby).
                    $this->payments->reconcileToBankTransaction($iid, $txId, [
                        'variable_symbol' => isset($txRow['variable_symbol']) ? (string) $txRow['variable_symbol'] : null,
                        'bank_reference'  => isset($txRow['bank_ref']) ? (string) $txRow['bank_ref'] : null,
                    ]);
                    continue;
                }
                $recorded = $this->payments->recordPayment($iid, $toPay[$iid], $postedAt, [
                    'source'              => 'bank',
                    'bank_transaction_id' => $txId,
                    'variable_symbol'     => isset($txRow['variable_symbol']) ? (string) $txRow['variable_symbol'] : null,
                    'bank_reference'      => isset($txRow['bank_ref']) ? (string) $txRow['bank_ref'] : null,
                    'created_by'          => $userId,
                ]);
                if ($recorded['became_paid']) {
                    $paidInvoiceIds[] = $iid;
                    if ((string) ($byId[$iid]['invoice_type'] ?? '') === 'proforma') {
                        $finalDraftIds[$iid] = $this->finalCreator->create($iid, $userId ?: 0, $postedAt);
                    }
                }
            }

            if ($statementId > 0) {
                $pdo->prepare(
                    "UPDATE bank_statements
                        SET matched_count = (
                            SELECT COUNT(*) FROM bank_transactions
                             WHERE statement_id = ?
                               AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                        )
                      WHERE id = ?"
                )->execute([$statementId, $statementId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::error($response, 'match_failed', 'Sloučené párování selhalo: ' . $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.tx_manual_match_split', $userId ?: null, 'bank_transaction', $txId, [
            'invoice_ids'     => $invoiceIds,
            'client_id'       => $clientId,
            'paid_at'         => $postedAt,
            'final_draft_ids' => array_values($finalDraftIds),
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Děkovné e-maily za úhradu — per faktura, best-effort (selhání nesmí rozbít spárování).
        foreach ($paidInvoiceIds as $iid) {
            $this->paymentThanks->sendForInvoice(
                $iid, 'bank_match', $userId ?: null, $ip, $request->getHeaderLine('User-Agent'), requireUnsent: true,
            );
        }

        $result = ['matched' => true, 'split' => true, 'paid_at' => $postedAt, 'invoice_ids' => $invoiceIds];
        if ($finalDraftIds !== []) {
            $result['final_draft_ids'] = array_values($finalDraftIds);
        }
        return Json::ok($response, $result);
    }

    /**
     * Manual match transakce ↔ purchase_invoice (přijatá faktura, outgoing payment).
     * Používá payment_matches table (N:N model), na rozdíl od vystavených které mají
     * 1:1 přes bank_transactions.matched_invoice_id.
     */
    private function manualMatchPurchase(Request $request, Response $response, int $txId, int $purchaseInvoiceId): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();

        // Validate purchase invoice belongs to tenant + is in payable status
        $stmt = $pdo->prepare(
            'SELECT id, supplier_id, status, COALESCE(amount_to_pay, total_with_vat, 0) AS amount_to_pay
               FROM purchase_invoices WHERE id = ?'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $pi = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$pi || (int) $pi['supplier_id'] !== $supplierId) {
            return Json::error($response, 'purchase_not_found', 'Přijatá faktura nenalezena.', 404);
        }
        // 'paid' povolujeme — kandidáti nabízejí i zaplacené faktury (duplicitní/druhá
        // platba, doplatek); transakci jen navážeme, paid_at nepřepisujeme (viz níže).
        // Mirror StatementMatcher::matchPurchase i vystavené faktury (manualMatch).
        $alreadyPaid = ($pi['status'] === 'paid');
        if (!in_array($pi['status'], ['received', 'booked', 'paid'], true)) {
            return Json::error($response, 'invalid_status',
                "Přijatou fakturu ve stavu '{$pi['status']}' nelze spárovat.", 409);
        }

        // Load transaction for amount + posted_at
        $tx = $pdo->prepare('SELECT posted_at, amount, statement_id FROM bank_transactions WHERE id = ?');
        $tx->execute([$txId]);
        $txRow = $tx->fetch(\PDO::FETCH_ASSOC) ?: [];
        $postedAt = (string) ($txRow['posted_at'] ?? date('Y-m-d'));
        $statementId = (int) ($txRow['statement_id'] ?? 0);
        $absAmount = abs((float) ($txRow['amount'] ?? 0));

        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);

        $pdo->beginTransaction();
        try {
            // Mark purchase paid — jen pokud ještě není (ručně zaplacenou jen navážeme,
            // status/paid_at nepřepisujeme — respektujeme stav nastavený uživatelem).
            if (!$alreadyPaid) {
                $pdo->prepare(
                    "UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                )->execute([$postedAt, $purchaseInvoiceId]);
            }

            // Insert payment_match row (N:N support pro splátky)
            $pdo->prepare(
                "INSERT INTO payment_matches
                    (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
                 VALUES (?, ?, ?, ?, 'manual', ?)"
            )->execute([$supplierId, $txId, $purchaseInvoiceId, $absAmount, $userId ?: null]);

            // Mark transakci jako manual (matched_invoice_id zůstane NULL — to je pro vystavené)
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET match_status = 'manual', matched_at = NOW(), matched_by = ?
                  WHERE id = ?"
            )->execute([$userId ?: null, $txId]);

            // Recompute statement counter
            if ($statementId > 0) {
                $pdo->prepare(
                    "UPDATE bank_statements SET matched_count = (
                        SELECT COUNT(*) FROM bank_transactions
                         WHERE statement_id = ? AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                    ) WHERE id = ?"
                )->execute([$statementId, $statementId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return Json::error($response, 'match_failed', 'Párování selhalo: ' . $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.tx_manual_match_purchase', $userId ?: null, 'bank_transaction', $txId, [
            'purchase_invoice_id' => $purchaseInvoiceId,
            'paid_at'             => $postedAt,
            'amount'              => $absAmount,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'matched'             => true,
            'paid_at'             => $postedAt,
            'purchase_invoice_id' => $purchaseInvoiceId,
        ]);
    }

    public function unmatch(Request $request, Response $response, array $args): Response
    {
        $txId = (int) ($args['id'] ?? 0);
        if (!$this->txBelongsToCurrentSupplier($request, $txId)) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT id, statement_id, matched_invoice_id, posted_at, match_status
               FROM bank_transactions WHERE id = ?'
        );
        $stmt->execute([$txId]);
        $tx = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$tx) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $statementId = (int) $tx['statement_id'];
        $invoiceId = $tx['matched_invoice_id'] !== null ? (int) $tx['matched_invoice_id'] : 0;
        $postedAt = (string) ($tx['posted_at'] ?? '');

        // Supplier scope check — fakturu (pokud byla spárována) ověř proti aktuálnímu supplier.
        // Pokud transakce nebyla spárovaná (jen 'ignored'), ověř scope přes statement → currencies.
        if ($invoiceId > 0) {
            $invoice = $this->invoices->find($invoiceId);
            if (!SupplierGuard::owns($request, $invoice)) {
                return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
            }
        } else {
            $sid = SupplierGuard::currentId($request);
            $own = $pdo->prepare(
                "SELECT 1 FROM bank_statements bs
                  WHERE bs.id = ?
                    AND EXISTS (
                        SELECT 1 FROM currencies cur
                         WHERE cur.supplier_id = ?
                           AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                             = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                           AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                    )"
            );
            $own->execute([$statementId, $sid]);
            if (!$own->fetchColumn()) {
                return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
            }
        }

        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);

        // Guard (#89): k platbě této transakce existuje nestornovaný daňový doklad
        // k přijaté platbě — odpárování by rozbilo daňovou stopu. Nejdřív doklad
        // smazat (koncept) nebo stornovat, pak teprve rušit spárování.
        $tdGuard = $pdo->prepare(
            "SELECT COUNT(*)
               FROM invoice_payments p
               JOIN invoices td ON td.id = p.tax_document_invoice_id
              WHERE p.bank_transaction_id = ? AND td.status <> 'cancelled'"
        );
        $tdGuard->execute([$txId]);
        if ((int) $tdGuard->fetchColumn() > 0) {
            return Json::error(
                $response,
                'has_tax_document',
                'K platbě z této transakce je vystavený daňový doklad k přijaté platbě. Nejdřív ho smaž (koncept) nebo stornuj.',
                409,
            );
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = NULL,
                        match_status       = 'unmatched',
                        matched_at         = NULL,
                        matched_by         = NULL
                  WHERE id = ?"
            )->execute([$txId]);

            // Evidence plateb (#89): smaž platbu založenou touto transakcí — service
            // přepočítá paid_total a případně vrátí fakturu ze stavu 'paid' (sent/issued).
            $deletedPayment = $this->payments->deleteForBankTransaction($txId);

            // Legacy heuristika pro spárování z dob před evidencí plateb (žádný payment
            // řádek): pokud byla faktura označena jako paid s paid_at = posted_at této
            // transakce a nemá jinou stále spárovanou transakci, vrať ji na 'issued'.
            // (Konzervativní — neměníme stav, který někdo nastavil ručně později.)
            if (!$deletedPayment && $invoiceId > 0 && $postedAt !== '') {
                $other = $pdo->prepare(
                    "SELECT COUNT(*) FROM bank_transactions
                      WHERE matched_invoice_id = ?
                        AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                        AND id <> ?"
                );
                $other->execute([$invoiceId, $txId]);
                $stillMatched = (int) $other->fetchColumn();
                if ($stillMatched === 0) {
                    $rev = $pdo->prepare(
                        "UPDATE invoices
                            SET status = 'issued', paid_at = NULL
                          WHERE id = ?
                            AND status = 'paid'
                            AND paid_at = ?"
                    );
                    $rev->execute([$invoiceId, $postedAt]);
                    if ($rev->rowCount() > 0) {
                        // Backfill 'legacy' platba (migrace 0108) odpovídá tomuto
                        // historickému spárování — smaž a přepočti paid_total, jinak
                        // by faktura zůstala issued s plným paid_total (nekonzistence).
                        $pdo->prepare(
                            "DELETE FROM invoice_payments WHERE invoice_id = ? AND source = 'legacy'"
                        )->execute([$invoiceId]);
                        $pdo->prepare(
                            'UPDATE invoices i
                                SET i.paid_total = (SELECT COALESCE(SUM(p.amount), 0)
                                                      FROM invoice_payments p WHERE p.invoice_id = i.id)
                              WHERE i.id = ?'
                        )->execute([$invoiceId]);
                    }
                }
            }

            if ($statementId > 0) {
                $pdo->prepare(
                    "UPDATE bank_statements
                        SET matched_count = (
                            SELECT COUNT(*) FROM bank_transactions
                             WHERE statement_id = ?
                               AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                        )
                      WHERE id = ?"
                )->execute([$statementId, $statementId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::error($response, 'unmatch_failed', 'Zrušení spárování selhalo: ' . $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.tx_unmatch', $userId ?: null, 'bank_transaction', $txId, [
            'previous_invoice_id' => $invoiceId ?: null,
            'previous_status'     => $tx['match_status'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['unmatched' => true]);
    }

    public function ignore(Request $request, Response $response, array $args): Response
    {
        $txId = (int) ($args['id'] ?? 0);
        if (!$this->txBelongsToCurrentSupplier($request, $txId)) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $pdo = $this->db->pdo();
        // Načti previous state pro audit log (před UPDATE)
        $prev = $pdo->prepare(
            'SELECT statement_id, match_status, matched_invoice_id FROM bank_transactions WHERE id = ?'
        );
        $prev->execute([$txId]);
        $prevRow = $prev->fetch(\PDO::FETCH_ASSOC) ?: [];
        $statementId = (int) ($prevRow['statement_id'] ?? 0);
        $previousStatus = (string) ($prevRow['match_status'] ?? '');
        $previousInvoiceId = $prevRow['matched_invoice_id'] !== null ? (int) $prevRow['matched_invoice_id'] : null;

        $pdo->prepare("UPDATE bank_transactions SET match_status = 'ignored' WHERE id = ?")->execute([$txId]);

        // Pokud byla transakce dříve matched (auto/manual), recompute count na výpisu
        if ($statementId > 0) {
            $pdo->prepare(
                "UPDATE bank_statements
                    SET matched_count = (
                        SELECT COUNT(*) FROM bank_transactions
                         WHERE statement_id = ?
                           AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                    )
                  WHERE id = ?"
            )->execute([$statementId, $statementId]);
        }

        // Audit log — destructive op musí být dohledatelná (forensic integrity).
        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.tx_ignore', $userId ?: null, 'bank_transaction', $txId, [
            'previous_status'     => $previousStatus,
            'previous_invoice_id' => $previousInvoiceId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ignored' => true]);
    }

    /**
     * Přepároj všechny dosud nespárované transakce výpisu — užitečné poté, co
     * uživatel ex-post doplnil přijaté/vystavené faktury, které by se daly napárovat.
     *
     * Volá StatementMatcher::match() pro každou transakci ve stavu 'unmatched' nebo
     * 'auto_partial'. Stávající 'auto_exact', 'manual' a 'ignored' nejsou dotčeny.
     */
    public function rematch(Request $request, Response $response, array $args): Response
    {
        $statementId = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if ($sid <= 0 || $statementId <= 0) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $pdo = $this->db->pdo();
        $owned = $pdo->prepare(
            "SELECT 1 FROM bank_statements bs
              WHERE bs.id = ?
                AND EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                     AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )"
        );
        $owned->execute([$statementId, $sid]);
        if (!$owned->fetchColumn()) {
            return Json::error($response, 'not_found', 'Výpis nenalezen.', 404);
        }

        $txs = $pdo->prepare(
            "SELECT id FROM bank_transactions
              WHERE statement_id = ?
                AND match_status IN ('unmatched', 'auto_partial')"
        );
        $txs->execute([$statementId]);
        $txIds = $txs->fetchAll(\PDO::FETCH_COLUMN);

        $newlyMatched = 0;
        $newlyPartial = 0;
        $stillUnmatched = 0;
        foreach ($txIds as $txId) {
            $r = $this->matcher->match((int) $txId);
            $s = (string) ($r['status'] ?? 'unmatched');
            if ($s === 'auto_exact') $newlyMatched++;
            elseif ($s === 'auto_partial') $newlyPartial++;
            else $stillUnmatched++;
        }

        // Recompute matched_count na výpisu
        $pdo->prepare(
            "UPDATE bank_statements
                SET matched_count = (
                    SELECT COUNT(*) FROM bank_transactions
                     WHERE statement_id = ?
                       AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                )
              WHERE id = ?"
        )->execute([$statementId, $statementId]);

        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.statement_rematch', $userId ?: null, 'bank_statement', $statementId, [
            'considered'       => count($txIds),
            'newly_matched'    => $newlyMatched,
            'newly_partial'    => $newlyPartial,
            'still_unmatched'  => $stillUnmatched,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'considered'      => count($txIds),
            'newly_matched'   => $newlyMatched,
            'newly_partial'   => $newlyPartial,
            'still_unmatched' => $stillUnmatched,
        ]);
    }
}
