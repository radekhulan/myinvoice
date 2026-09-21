<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Mail\PaymentThanksMailer;
use PDO;

/**
 * Matchne bankovní transakci na fakturu podle VS + amount.
 *
 * Strategie:
 *   1. Příchozí (amount > 0) — hledá fakturu se shodným varsymbol
 *      a) |amount - amount_to_pay| <= 0.05 Kč → 'auto_exact', faktura → paid
 *      b) |amount - amount_to_pay| <= 1 Kč → 'auto_partial' (částečná platba)
 *   2. Odchozí (amount < 0) — hledá přijatou fakturu podle payment_variable_symbol,
 *      varsymbol nebo vendor_invoice_number a částky včetně zaokrouhlení, payment_matches N:N.
 *
 * Tolerance 0.05 Kč pro exact match: typické zaokrouhlení 21 % DPH na
 * vícepoložkové faktuře dává ±0.01 — ±0.04 Kč rozdíl mezi součtem
 * položek a total_with_vat. Příklad: 1241.34 × 1.21 = 1502.0214 →
 * zaokrouhlí buď na 1502.02 (per řádek po výpočtu DPH) nebo 1502.00
 * (suma per řádek bez DPH × sazba). Bank převod je za jednu z hodnot,
 * faktura má druhou — diff 0.02 je legitní. Před 0.05 tolerancí toto
 * padalo do auto_partial a faktura zůstávala neoznačena jako paid.
 *
 * Multi-supplier: VS je unique per (supplier_id, varsymbol). Matcher určuje
 * supplier_id z bank_statement.account_number → currencies.account_number → supplier_id.
 * Pokud žádná currency neodpovídá účtu (bank statement nepatří žádnému supplierovi),
 * vrátí 'unmatched/unknown_supplier'.
 */
final class StatementMatcher
{
    /** Tolerance pro auto_exact match — pokrývá DPH zaokrouhlení (±2-4 haléře typicky). */
    private const EXACT_MATCH_TOLERANCE = 0.05;
    /** Tolerance pro auto_partial — vyšší rozdíly už se ručně rozeznají (splátka / přeplatek). */
    private const PARTIAL_MATCH_TOLERANCE = 1.0;
    /** Účetní (tuzemská) měna — base částky, na kterou přepočítáváme cizoměnové faktury. */
    private const LOCAL_CURRENCY = 'CZK';
    /** Relativní tolerance pro cross-currency shodu (tuzemská platba cizoměnové faktury).
     *  Banka si na převodu bere spread klidně ~2 % a kurz se za pár dní pohne — 4 %
     *  dává rezervu, aby přepočet přes kurz faktury reálně sednul. */
    private const FX_MATCH_TOLERANCE_PCT = 0.04;

    public function __construct(
        private readonly Connection $db,
        private readonly FinalFromProformaCreator $finalCreator,
        // Volitelný — automatické cesty (GPC import, e-mailové avízo, cron, rescan) jdou
        // přes match() (ne přes MarkPaidAction/manualMatch), takže děkovný e-mail za úhradu
        // se musí poslat odsud. Nullable kvůli izolovaným konstrukcím v testech/skriptech;
        // produkční wiring (Bootstrap) ho vždy injektuje. Viz #127.
        private readonly ?PaymentThanksMailer $paymentThanks = null,
        // Evidence plateb (#89) — exact/partial match příchozí platby vytváří záznam
        // v invoice_payments (N:1, idempotentně přes UNIQUE bank_transaction_id).
        // Nullable kvůli izolovaným konstrukcím v testech; bez service běží legacy
        // chování (přímý UPDATE statusu, bez payment řádků). Bootstrap injektuje vždy.
        private readonly ?InvoicePaymentService $payments = null,
        // Daňový doklad k přijaté platbě — auto DRAFT při částečné úhradě proformy
        // (jen plátce DPH, ne-RC; creator si podmínky hlídá sám, viz catch níže).
        private readonly ?PaymentTaxDocumentCreator $taxDocCreator = null,
        // Aktivita dokladu — zápis „payment_matched" proti vystavené i přijaté faktuře,
        // ať je auto-spárování platby vidět v aktivitě dokladu (GPC import, e-mailové
        // avízo, cron, rematch jdou přes match(), takže ruční logging v Action vrstvě
        // je míjí). Nullable kvůli izolovaným konstrukcím v testech; Bootstrap injektuje.
        private readonly ?ActivityLogger $activityLogger = null,
    ) {}

    /**
     * Zaloguj spárování platby proti dokladu (vystavená 'invoice' / přijatá
     * 'purchase_invoice'), ať je auto-úhrada vidět v aktivitě dokladu. Volá se AŽ
     * PO commitu (mimo transakci). Best-effort — logging nikdy nesmí shodit párování.
     */
    private function logPaymentMatch(
        string $entityType,
        int $entityId,
        ?int $supplierId,
        string $matchStatus,
        float $amount,
        string $vs,
        int $transactionId,
        bool $alreadyPaid = false,
    ): void {
        if ($this->activityLogger === null) {
            return;
        }
        try {
            $this->activityLogger->log(
                $entityType . '.payment_matched',
                null,
                $entityType,
                $entityId,
                [
                    'match'               => $matchStatus,
                    'amount'              => round($amount, 2),
                    'variable_symbol'     => $vs !== '' ? $vs : null,
                    'bank_transaction_id' => $transactionId,
                    'source'              => 'bank',
                    'already_paid'        => $alreadyPaid ?: null,
                ],
                null,
                null,
                // purchase_invoice ActivityLogger sám neresolvuje → předáváme supplier
                // explicitně; pro 'invoice' se doplní auto z entity (může být null).
                $supplierId,
            );
        } catch (\Throwable) {
            // logging nesmí rozbít párování
        }
    }

    /**
     * Očekávaná částka faktury vyjádřená v měně transakce + tolerance (exact, partial).
     * Vrací null, když měny nejdou spolehlivě porovnat (cizoměnový účet × jiná měna faktury
     * — bez kurzu transakce neumíme převést; raději nepárovat než špatně).
     *
     * @return array{expected: float, exact: float, partial: float}|null
     */
    private function expectedMatch(float $invoiceAmount, string $invoiceCcy, float $rate, ?string $txCurrency, ?float $exactTolerance = null): ?array
    {
        $exact = $exactTolerance !== null && $exactTolerance >= 0.0
            ? $exactTolerance
            : self::EXACT_MATCH_TOLERANCE;
        // Neznámá měna transakce (legacy výpisy) nebo shodná měna → přímé porovnání.
        if ($txCurrency === null || strtoupper($txCurrency) === strtoupper($invoiceCcy)) {
            return ['expected' => $invoiceAmount, 'exact' => $exact, 'partial' => max($exact, self::PARTIAL_MATCH_TOLERANCE)];
        }
        // Tuzemská platba cizoměnové faktury → přepočet kurzem faktury (CZK = částka × kurz).
        // Relativní tolerance kvůli kurzovému driftu; partial tier zde nemá smysl (= exact).
        if (strtoupper($txCurrency) === self::LOCAL_CURRENCY) {
            $r = $rate > 0 ? $rate : 1.0;
            // Bankovní pohyb je na haléře; nezaokrouhlený mezivýsledek nesmí
            // na hraně tolerance rozhodnout jinak než skutečná peněžní částka.
            $czk = round($invoiceAmount * $r, 2);
            $tol = max($exact, $czk * self::FX_MATCH_TOLERANCE_PCT);
            return ['expected' => $czk, 'exact' => $tol, 'partial' => $tol];
        }
        // Cizoměnový účet × jiná měna faktury (např. EUR výpis × CZK/USD faktura) — skip.
        return null;
    }

    public function match(int $transactionId): array
    {
        $pdo = $this->db->pdo();
        $tx = $pdo->prepare(
            'SELECT bt.*, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank,
                    bs.currency AS statement_currency
               FROM bank_transactions bt
               JOIN bank_statements   bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $tx->execute([$transactionId]);
        $row = $tx->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => 'unmatched', 'reason' => 'transaction_not_found'];
        }
        $vs = $row['variable_symbol'];
        $amount = (float) $row['amount'];
        // VS může chybět (karetní platby) — řeší se per-směr níže: odchozí přes fuzzy
        // (částka + podobný název), příchozí stále vyžadují VS.
        // Currency guard: tx currency (preferováno) → statement currency
        // (fallback) → null (backward compat — staré výpisy bez měny matchují
        // jakoukoli fakturu, jak to dělaly před fixem v4.1.0).
        $txCurrency = is_string($row['currency'] ?? null) && $row['currency'] !== ''
            ? (string) $row['currency']
            : (is_string($row['statement_currency'] ?? null) && $row['statement_currency'] !== ''
                ? (string) $row['statement_currency']
                : null);
        // Outgoing (amount < 0) → match na purchase_invoice (přijatou) — fáze 3.
        // Incoming (amount > 0) → match na invoice (vydanou) — existing flow.
        $isOutgoing = $amount < 0;
        $exactTolerance = isset($row['match_tolerance']) && $row['match_tolerance'] !== null
            ? max(0.0, (float) $row['match_tolerance'])
            : null;

        // Určení supplier_id z bank účtu (currencies.account_number + bank_code).
        // Normalizace přes AccountNumberNormalizer (řeší zero-padding a prefix).
        // Porovnává se i domácí část IBANu (#109) — cizoměnové účty bývají evidované
        // jen IBANem (viz schema currencies), GPC ale nese domácí číslo účtu; bez toho
        // EUR výpis skončil jako unknown_supplier_for_account a nikdy se nespároval.
        $supplierId = 0;
        if (!empty($row['recipient_account'])) {
            $stmt = $pdo->query(
                'SELECT supplier_id, account_number, iban, bank_code FROM currencies
                  WHERE account_number IS NOT NULL OR iban IS NOT NULL'
            );
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $candidate) {
                $iban = isset($candidate['iban']) && is_string($candidate['iban']) ? $candidate['iban'] : null;
                // Bank code filter (jen když výpis kód banky nese): kandidátův kód
                // z bank_code sloupce, případně z IBANu. Neznámý kód nevyřazuje —
                // radši porovnat číslo účtu než ztratit IBAN-only řádek.
                if (!empty($row['recipient_bank'])) {
                    $candidateBank = (string) ($candidate['bank_code'] ?? '');
                    if ($candidateBank === '' && $iban !== null) {
                        $candidateBank = (string) AccountNumberNormalizer::czechIbanBankCode($iban);
                    }
                    if ($candidateBank !== '' && $candidateBank !== (string) $row['recipient_bank']) {
                        continue;
                    }
                }
                if (AccountNumberNormalizer::matchesAny((string) $row['recipient_account'], $candidate['account_number'] ?? null, $iban)) {
                    $supplierId = (int) $candidate['supplier_id'];
                    break;
                }
            }
        }
        if ($supplierId === 0) {
            return ['status' => 'unmatched', 'reason' => 'unknown_supplier_for_account'];
        }

        // ── Outgoing → purchase_invoice (přijaté faktury) ────────────────
        if ($isOutgoing) {
            // Rematch bere i auto_partial. Částečnou shodu dle VS (confidence 70, status
            // faktury nemění) zahodíme a vyhodnotíme znovu, ať ji třeba nově započtené
            // zaokrouhlení povýší na přesnou. Fuzzy / částka+datum shody fakturu už
            // překlopily na paid — ty necháme a jen ohlásíme, jinak by vznikla duplicitní
            // payment_matches.
            if (($row['match_status'] ?? '') === 'auto_partial') {
                $prevStmt = $pdo->prepare('SELECT purchase_invoice_id, match_type, match_confidence FROM payment_matches WHERE bank_transaction_id = ?');
                $prevStmt->execute([$transactionId]);
                $prev = $prevStmt->fetchAll(PDO::FETCH_ASSOC);
                $resettable = $prev !== [] && array_filter(
                    $prev,
                    static fn (array $p): bool => $p['match_type'] !== 'auto' || (int) $p['match_confidence'] !== 70,
                ) === [];
                if ($prev !== [] && !$resettable) {
                    return [
                        'status'              => 'auto_partial',
                        'purchase_invoice_id' => (int) $prev[0]['purchase_invoice_id'],
                        'already_recorded'    => true,
                    ];
                }
                if ($resettable) {
                    $pdo->prepare('DELETE FROM payment_matches WHERE bank_transaction_id = ?')->execute([$transactionId]);
                    $pdo->prepare("UPDATE bank_transactions SET match_status = 'unmatched', matched_at = NULL WHERE id = ?")
                        ->execute([$transactionId]);
                }
            }
            // 1) přesný match dle VS dodavatele (vendor_invoice_number / varsymbol)
            if ($vs) {
                $res = $this->matchPurchase($pdo, $supplierId, (string) $vs, abs($amount), (string) $row['posted_at'], $transactionId, $txCurrency);
                if (($res['status'] ?? 'unmatched') !== 'unmatched') {
                    return $res;
                }
            }
            // 2) karetní platby (bez VS) / VS bez shody → fuzzy dle částky + podobného
            //    názvu protistrany (u karet je název obchodníka odlišný od jména dodavatele).
            $res = $this->matchPurchaseFuzzy($pdo, $supplierId, abs($amount), (string) ($row['counterparty_name'] ?? ''), (string) $row['posted_at'], $transactionId, $txCurrency);
            if (($res['status'] ?? 'unmatched') !== 'unmatched') {
                return $res;
            }
            // 3) poslední záchrana: shoda dle ČÁSTKY + DATA (±14 dní), stejně jako ruční
            //    nabídka kandidátů (matchCandidates) — VČETNĚ zaplacených faktur a bez ohledu
            //    na VS/název. Fuzzy nad tímto nestačí: vynechává paid (uživatel značí faktury
            //    paid hned) a je moc přísný (0,05 Kč, shoda názvu), takže karetní/bez-VS platby
            //    se automaticky nikdy nespárovaly, i když ručně se kandidát nabídl. Bezpečnost
            //    drží pravidlo „právě jeden kandidát".
            return $this->matchPurchaseByAmountDate($pdo, $supplierId, abs($amount), (string) $row['posted_at'], $transactionId, $txCurrency);
        }

        // Příchozí platby stále vyžadují VS (vystavené faktury se párují na náš VS).
        if (!$vs) {
            return ['status' => 'unmatched', 'reason' => 'no_vs'];
        }

        // ── Incoming → invoice (vystavené faktury) — existing flow ─────────
        // Najdi fakturu s VS = transakce.VS, supplier scope, status in (issued, sent, reminded, paid),
        // amount_to_pay sedí. 'paid' je v setu, aby se transakce navázala i na fakturu už označenou
        // za zaplacenou ručně (ať ve výpisu nevisí unmatched). Status/paid_at v tom případě
        // ponecháme — netouchujeme stav, který uživatel nastavil ručně.
        // Proformu povolujeme — zaplacená proforma se označí paid a navíc vytvoří DRAFT finální faktury.
        // Currency-aware match: fakturu hledáme jen dle VS (ne dle měny), částku pak
        // porovnáváme v měně transakce — u cizoměnové faktury placené z CZK účtu přes
        // kurz faktury (viz expectedMatch). Nebezpečný případ (EUR výpis × CZK faktura
        // stejného VS+amount) expectedMatch vrátí null → zůstane unmatched.
        // VS match: 1) přesná shoda (rychlá cesta pro čistě číselné VS), 2) numerická
        // shoda po normalizaci — `invoices.varsymbol` slouží i jako číslo dokladu, takže
        // může nést pomlčku/lomítko (např. „2026-00001"), zatímco banka pošle jen číslice
        // („202600001"). CAST(REGEXP_REPLACE(...) AS UNSIGNED) zrcadlí
        // VariableSymbolNormalizer::forMatching (číslice bez vodicích nul). REGEXP '[1-9]'
        // vyřadí prázdné / samé-nuly varsymboly (CAST '' → 0), aby nevznikla planá shoda.
        $vsDigits = VariableSymbolNormalizer::digits((string) $vs);
        $sql = "SELECT i.id, i.varsymbol, i.amount_to_pay, i.paid_total, i.exchange_rate, i.status, i.invoice_type, cur.code AS currency
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND (i.varsymbol = ?
                        OR (i.varsymbol REGEXP '[1-9]'
                            AND CAST(REGEXP_REPLACE(i.varsymbol, '[^0-9]', '') AS UNSIGNED) = CAST(? AS UNSIGNED)))
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'proforma')
                 LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$supplierId, $vs, $vsDigits]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            return ['status' => 'unmatched', 'reason' => 'no_invoice_with_vs', 'tx_currency' => $txCurrency];
        }

        // Spárovaná proforma (existuje nestornovaný finál): pohledávku nese FINÁL —
        // doplatek poslaný pod VS proformy se přesměruje na něj. Platba na proformě
        // by visela mimo dluh (receivable guard proformy s finálem vylučuje) a navíc
        // by spustila daňový doklad k platbě, který už finál nikdy neodečte (§ 37a).
        if (($inv['invoice_type'] ?? '') === 'proforma') {
            $proformaPaid = ($inv['status'] === 'paid');
            $fin = $pdo->prepare(
                "SELECT i.id, i.varsymbol, i.amount_to_pay, i.paid_total, i.exchange_rate, i.status, i.invoice_type, cur.code AS currency
                   FROM invoices i
                   JOIN currencies cur ON cur.id = i.currency_id
                  WHERE i.parent_invoice_id = ? AND i.invoice_type = 'invoice'
                    AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                  ORDER BY i.id LIMIT 1"
            );
            $fin->execute([(int) $inv['id']]);
            $finRow = $fin->fetch(PDO::FETCH_ASSOC);
            if ($finRow) {
                // Přesměruj na finál JEN když je co vybrat — finál nese otevřenou pohledávku
                // NEBO proforma sama ještě není uhrazená (finál je doklad k úhradě). Když je
                // ale proforma i finál plně vyrovnané, platba už jen potvrzuje uhrazenou
                // zálohu a navážeme ji na PROFORMU: finál z uhrazené proformy má
                // amount_to_pay=0 i paid_total=0 (pohledávku i platbu drží proforma), takže
                // přesměrování by porovnávalo platbu proti nule a uhrazená záloha by se
                // nikdy nespárovala (issue: „zálohové faktury se nepárují").
                $finRemaining = round((float) $finRow['amount_to_pay'] - (float) ($finRow['paid_total'] ?? 0), 2);
                $finSettled = ($finRow['status'] === 'paid') || $finRemaining <= 0.005;
                if (!($proformaPaid && $finSettled)) {
                    $inv = $finRow;
                }
            }
        }

        // Idempotence (#89): transakce už jednou založila platbu (rematch bere i
        // auto_partial) — nic znovu nevytvářet, jen reportovat stávající stav.
        if ($this->payments !== null) {
            $dup = $pdo->prepare('SELECT invoice_id FROM invoice_payments WHERE bank_transaction_id = ?');
            $dup->execute([$transactionId]);
            $dupInvoiceId = $dup->fetchColumn();
            if ($dupInvoiceId !== false) {
                return [
                    'status'           => (string) ($row['match_status'] ?? 'auto_partial') ?: 'auto_partial',
                    'invoice_id'       => (int) $dupInvoiceId,
                    'already_recorded' => true,
                ];
            }
        }

        // Porovnáváme proti ZBÝVAJÍCÍ částce (amount_to_pay - paid_total) — dřívější
        // částečné úhrady (jiné transakce, ruční záznamy) match nesmí rozbít (#89).
        $alreadyPaid = ($inv['status'] === 'paid');
        $remaining = round((float) $inv['amount_to_pay'] - (float) ($inv['paid_total'] ?? 0), 2);
        // Už zaplacená faktura má remaining = 0 (paid_total pokrývá celou částku), takže
        // porovnání plné bankovní platby proti 0 by vždy selhalo a paid faktura by ve
        // výpisu visela jako unmatched — přesně tohle uživatel reportoval. Chceme ji ale
        // jen NAVÁZAT (status/paid_at zůstane), proto porovnáváme proti CELKOVÉ částce
        // faktury (amount_to_pay; fallback paid_total, kdyby header byl 0). U nezaplacených
        // zůstává porovnání proti zbývajícímu dluhu beze změny.
        $compareBase = $alreadyPaid
            ? round(max((float) $inv['amount_to_pay'], (float) ($inv['paid_total'] ?? 0)), 2)
            : $remaining;
        $m = $this->expectedMatch($compareBase, (string) $inv['currency'], (float) ($inv['exchange_rate'] ?: 0), $txCurrency, $exactTolerance);
        if ($m === null) {
            return ['status' => 'unmatched', 'reason' => 'currency_mismatch',
                    'tx_currency' => $txCurrency, 'invoice_currency' => $inv['currency']];
        }

        $diff = abs($amount - $m['expected']);
        // Exact = doplacení v toleranci; drobný přeplatek do partial tier (≤ 1 Kč)
        // bereme taky jako úhradu. Ve stejné měně evidujeme skutečnou částku;
        // cross-currency exact vypořádá celý zbytek v měně faktury.
        $isExact = $diff <= $m['exact']
            || (!$alreadyPaid && $amount > $m['expected'] && $diff <= $m['partial']);
        if ($isExact) {
            // Exact match — pokud faktura ještě není paid, zaevidovat platbu (service
            // překlopí status + paid_at) a u proformy vyrobit final draft.
            // Pro již ručně paid fakturu jen navážeme transakci (status/paid_at netknuté).
            $pdo->beginTransaction();
            try {
                if (!$alreadyPaid) {
                    if ($this->payments !== null) {
                        $this->payments->recordPayment(
                            (int) $inv['id'],
                            $this->txAmountInInvoiceCurrency(
                                $amount,
                                $inv,
                                $txCurrency,
                                $remaining,
                                settleRemaining: true,
                            ),
                            (string) $row['posted_at'],
                            [
                                'source'              => 'bank',
                                'bank_transaction_id' => $transactionId,
                                'variable_symbol'     => (string) $vs,
                                'bank_reference'      => isset($row['bank_ref']) ? (string) $row['bank_ref'] : null,
                            ],
                        );
                    } else {
                        // Legacy fallback (izolované konstrukce bez payment service).
                        $pdo->prepare(
                            "UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                        )->execute([$row['posted_at'], $inv['id']]);
                    }
                }
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET matched_invoice_id = ?, match_status = 'auto_exact', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$inv['id'], $transactionId]);

                $finalDraftId = null;
                if (!$alreadyPaid && $inv['invoice_type'] === 'proforma') {
                    // DUZP finálního dokladu = den přijetí platby z výpisu, ne dnešek.
                    $finalDraftId = $this->finalCreator->create((int) $inv['id'], 0, (string) $row['posted_at']);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            // Děkovný e-mail za úhradu (#57/#127) — jen pro fakturu nově označenou jako
            // paid (ne ručně paid, kterou jen navazujeme). Best-effort, mimo transakci:
            // mailer si sám ohlídá enabled + auto_send (bank_match trigger) i idempotenci
            // a nikdy nevyhazuje výjimku, takže selhání e-mailu nerozbije spárování.
            if (!$alreadyPaid) {
                $this->paymentThanks?->sendForInvoice((int) $inv['id'], 'bank_match', null, null, null, requireUnsent: true);
            }

            $this->logPaymentMatch('invoice', (int) $inv['id'], null, 'auto_exact', $amount, (string) $vs, $transactionId, $alreadyPaid);

            $result = ['status' => 'auto_exact', 'invoice_id' => (int) $inv['id'], 'varsymbol' => $vs];
            if ($finalDraftId !== null) {
                $result['final_draft_id'] = $finalDraftId;
            }
            if ($alreadyPaid) {
                $result['already_paid'] = true;
            }
            return $result;
        }

        // Podplatba (částečná úhrada, #89): VS sedí, částka je menší než zbývající.
        // Zaeviduj platbu (faktura zůstává pohledávkou se sníženým zůstatkem) a u
        // proformy vystav DRAFT daňového dokladu k přijaté platbě (plátce DPH, ne-RC).
        if (!$alreadyPaid && $this->payments !== null && $amount < $m['expected'] - $m['exact']) {
            $pdo->beginTransaction();
            try {
                $recorded = $this->payments->recordPayment(
                    (int) $inv['id'],
                    $this->txAmountInInvoiceCurrency($amount, $inv, $txCurrency, 0.0),
                    (string) $row['posted_at'],
                    [
                        'source'              => 'bank',
                        'bank_transaction_id' => $transactionId,
                        'variable_symbol'     => (string) $vs,
                        'bank_reference'      => isset($row['bank_ref']) ? (string) $row['bank_ref'] : null,
                    ],
                );
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET matched_invoice_id = ?, match_status = 'auto_partial', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$inv['id'], $transactionId]);

                $taxDocId = null;
                if ($inv['invoice_type'] === 'proforma' && $this->taxDocCreator !== null) {
                    try {
                        $taxDocId = $this->taxDocCreator->createForPayment((int) $recorded['payment_id'], 0);
                    } catch (\RuntimeException) {
                        // Neplátce DPH / reverse charge / jiná podmínka — doklad se nevystavuje.
                    }
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $this->logPaymentMatch('invoice', (int) $inv['id'], null, 'auto_partial', $amount, (string) $vs, $transactionId);

            $result = [
                'status'          => 'auto_partial',
                'invoice_id'      => (int) $inv['id'],
                'partial_payment' => true,
                'remaining'       => $recorded['remaining'],
                'diff'            => $diff,
            ];
            if ($taxDocId !== null) {
                $result['tax_document_id'] = $taxDocId;
            }
            return $result;
        }

        if ($diff <= $m['partial']) {
            // Partial match (legacy fallback / již paid faktura) — flag, ale nepaint paid.
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = ?, match_status = 'auto_partial', matched_at = NOW()
                  WHERE id = ?"
            )->execute([$inv['id'], $transactionId]);
            return ['status' => 'auto_partial', 'invoice_id' => (int) $inv['id'], 'diff' => $diff];
        }

        return ['status' => 'unmatched', 'reason' => 'amount_mismatch', 'expected' => $m['expected'], 'got' => $amount];
    }

    /**
     * Částka transakce vyjádřená v měně faktury (pro záznam do invoice_payments).
     * Stejná/neznámá měna → přímo. U přijaté úplné FX úhrady ukládáme
     * celý zbytek v měně faktury; skutečná CZK částka zůstává na bankovní transakci.
     * Částečná CZK platba se nadále přepočítá kurzem faktury. Jiná nepřevoditelná
     * kombinace měn použije $fallback.
     */
    private function txAmountInInvoiceCurrency(
        float $txAmount,
        array $inv,
        ?string $txCurrency,
        float $fallback,
        bool $settleRemaining = false,
    ): float
    {
        $invCcy = strtoupper((string) $inv['currency']);
        if ($txCurrency === null || strtoupper($txCurrency) === $invCcy) {
            return round($txAmount, 2);
        }
        if ($settleRemaining) {
            return round($fallback, 2);
        }
        if (strtoupper($txCurrency) === self::LOCAL_CURRENCY) {
            $r = (float) ($inv['exchange_rate'] ?: 0);
            $r = $r > 0 ? $r : 1.0;
            return round($txAmount / $r, 2);
        }
        return round($fallback, 2);
    }

    /**
     * Match outgoing transakce na přijatou fakturu.
     * bank_transactions.matched_invoice_id slouží jen pro vystavené faktury,
     * pro přijaté používáme payment_matches table (N:N model).
     */
    private function matchPurchase(\PDO $pdo, int $supplierId, string $vs, float $absAmount, string $postedAt, int $transactionId, ?string $txCurrency = null): array
    {
        // 'paid' v setu: dovolíme navázat transakci i na ručně zaplacenou přijatou fakturu
        // (ať ve výpisu nevisí). Status/paid_at v tom případě nepřepisujeme.
        // Currency guard viz match() — bez něj by EUR výdaj napároval CZK přijatou.
        //
        // Platební VS dodavatele může být jiný než číslo dokladu. Vedle něj
        // zachováváme hledání podle interního i dodavatelského čísla dokladu,
        // včetně numerické normalizace (oddělovače a úvodní nuly).
        $vsDigits = VariableSymbolNormalizer::digits($vs);
        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number,
                       COALESCE(pi.amount_to_pay, pi.total_with_vat, 0) + COALESCE(pi.rounding, 0) AS amount_to_pay,
                       pi.exchange_rate, pi.status, cur.code AS currency
                  FROM purchase_invoices pi
             LEFT JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND (pi.payment_variable_symbol = ? OR pi.varsymbol = ? OR pi.vendor_invoice_number = ?
                        OR (pi.payment_variable_symbol REGEXP '[1-9]'
                            AND CAST(REGEXP_REPLACE(pi.payment_variable_symbol, '[^0-9]', '') AS UNSIGNED) = CAST(? AS UNSIGNED))
                        OR (pi.varsymbol REGEXP '[1-9]'
                            AND CAST(REGEXP_REPLACE(pi.varsymbol, '[^0-9]', '') AS UNSIGNED) = CAST(? AS UNSIGNED))
                        OR (pi.vendor_invoice_number REGEXP '[1-9]'
                            AND CAST(REGEXP_REPLACE(pi.vendor_invoice_number, '[^0-9]', '') AS UNSIGNED) = CAST(? AS UNSIGNED)))
                   AND pi.status IN ('received', 'booked', 'paid')
                 LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$supplierId, $vs, $vs, $vs, $vsDigits, $vsDigits, $vsDigits]);
        $pi = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pi) {
            return ['status' => 'unmatched', 'reason' => 'no_purchase_with_vs', 'tx_currency' => $txCurrency];
        }

        $m = $this->expectedMatch((float) $pi['amount_to_pay'], (string) ($pi['currency'] ?? self::LOCAL_CURRENCY), (float) ($pi['exchange_rate'] ?: 0), $txCurrency);
        if ($m === null) {
            return ['status' => 'unmatched', 'reason' => 'currency_mismatch_purchase',
                    'tx_currency' => $txCurrency, 'invoice_currency' => $pi['currency']];
        }

        $alreadyPaid = ($pi['status'] === 'paid');
        $diff = abs($absAmount - $m['expected']);
        if ($diff <= $m['exact']) {
            $pdo->beginTransaction();
            try {
                if (!$alreadyPaid) {
                    $pdo->prepare(
                        "UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                    )->execute([$postedAt, $pi['id']]);
                }
                // payment_matches je N:N — INSERT bezpečný i pro paid invoice.
                // (Pokud by user spustil rematch znovu, transakce je už auto_exact a do
                // rematch setu nespadne — duplikace tedy nehrozí.)
                $pdo->prepare(
                    "INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
                     VALUES (?, ?, ?, ?, 'auto', 95)"
                )->execute([$supplierId, $transactionId, $pi['id'], $absAmount]);
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET match_status = 'auto_exact', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$transactionId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $this->logPaymentMatch('purchase_invoice', (int) $pi['id'], $supplierId, 'auto_exact', $absAmount, (string) $vs, $transactionId, $alreadyPaid);

            $result = ['status' => 'auto_exact', 'purchase_invoice_id' => (int) $pi['id'], 'varsymbol' => $vs];
            if ($alreadyPaid) {
                $result['already_paid'] = true;
            }
            return $result;
        }
        if ($diff <= $m['partial']) {
            // Partial: zaznam do payment_matches + status na tx, ať UI vidí link
            // (předtím tady byl jen `return` bez zápisu — transakce zůstávaly
            // unmatched, partial match se v UI nikdy nezobrazil).
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
                     VALUES (?, ?, ?, ?, 'auto', 70)"
                )->execute([$supplierId, $transactionId, $pi['id'], $absAmount]);
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET match_status = 'auto_partial', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$transactionId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $this->logPaymentMatch('purchase_invoice', (int) $pi['id'], $supplierId, 'auto_partial', $absAmount, (string) $vs, $transactionId);

            return [
                'status' => 'auto_partial',
                'purchase_invoice_id' => (int) $pi['id'],
                'diff' => $diff,
                'expected' => (float) $pi['amount_to_pay'],
                'got' => $absAmount,
            ];
        }
        return ['status' => 'unmatched', 'reason' => 'amount_mismatch_purchase', 'expected' => $m['expected'], 'got' => $absAmount];
    }

    /**
     * Fuzzy párování ODCHOZÍ platby na přijatou fakturu — pro karetní platby (bez VS),
     * kde název protistrany ve výpisu (obchodník) bývá odlišný od jména dodavatele.
     * Pravidlo: shodná částka (v toleranci) + měna + status received/booked. Z těchto
     * kandidátů vybere ty s podobným názvem (sdílí významný token). Spáruje JEN když je
     * právě jeden takový — jinak je shoda nejednoznačná a necháme unmatched (radši ručně
     * než špatně). Confidence 60 + auto_partial = příznak ke kontrole.
     */
    private function matchPurchaseFuzzy(\PDO $pdo, int $supplierId, float $absAmount, string $cpName, string $postedAt, int $transactionId, ?string $txCurrency): array
    {
        // Měnu nefiltrujeme v SQL — částku porovnáváme přes expectedMatch (cizoměnová
        // faktura placená kartou z CZK účtu se přepočte kurzem faktury).
        $sql = "SELECT pi.id, COALESCE(pi.amount_to_pay, pi.total_with_vat, 0) + COALESCE(pi.rounding, 0) AS amount_to_pay,
                       pi.exchange_rate, c.company_name AS vendor_name, cur.code AS currency
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
             LEFT JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status IN ('received', 'booked')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$supplierId]);

        $similar = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = $this->expectedMatch((float) $r['amount_to_pay'], (string) ($r['currency'] ?? self::LOCAL_CURRENCY), (float) ($r['exchange_rate'] ?: 0), $txCurrency);
            if ($m === null || abs($absAmount - $m['expected']) > $m['exact']) {
                continue; // částka (po přepočtu) musí sedět
            }
            if ($this->nameSimilarity($cpName, (string) $r['vendor_name']) > 0.0) {
                $similar[] = $r;
            }
        }
        if (count($similar) !== 1) {
            return ['status' => 'unmatched', 'reason' => empty($similar) ? 'no_fuzzy_match' : 'ambiguous_fuzzy_match'];
        }
        $pi = $similar[0];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ? AND status <> 'paid'")
                ->execute([$postedAt, $pi['id']]);
            $pdo->prepare(
                "INSERT INTO payment_matches
                    (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
                 VALUES (?, ?, ?, ?, 'auto', 60)"
            )->execute([$supplierId, $transactionId, (int) $pi['id'], $absAmount]);
            $pdo->prepare("UPDATE bank_transactions SET match_status = 'auto_partial', matched_at = NOW() WHERE id = ?")
                ->execute([$transactionId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->logPaymentMatch('purchase_invoice', (int) $pi['id'], $supplierId, 'auto_partial', $absAmount, '', $transactionId);

        return ['status' => 'auto_partial', 'purchase_invoice_id' => (int) $pi['id'], 'fuzzy' => true];
    }

    /** Okno ±N dní kolem data platby pro shodu dle částky+data (zrcadlí BankStatementAction). */
    private const AMOUNT_DATE_DAY_WINDOW = 14;

    /**
     * Poslední záchrana pro ODCHOZÍ platbu bez shody VS i názvu: napáruj přijatou fakturu
     * dle ČÁSTKY (v toleranci expectedMatch — ±1 Kč, resp. 4 % u cizí měny) + DATA (±14 dní
     * kolem posted_at), stejně jako ruční nabídka kandidátů (matchCandidates).
     *
     * Oproti fuzzy: (1) zahrnuje i ZAPLACENÉ faktury (uživatel je typicky značí paid hned,
     * takže by jinak nikdy neprošly), (2) nevyžaduje shodu názvu, (3) volnější tolerance.
     * Bezpečnost drží pravidlo „PRÁVĚ JEDEN kandidát": při 0 nebo 2+ shodách necháme
     * unmatched (radši ručně než špatně). Faktury, které už mají párování (payment_matches),
     * se vylučují, ať se druhá platba nenaváže na už vyrovnanou fakturu.
     *
     * Zapisujeme jako auto_partial (confidence 65) — bez VS/názvu jde o slabší důkaz, ať
     * to uživatel v UI vidí jako „ke kontrole". Nezaplacenou fakturu překlopí na paid.
     */
    private function matchPurchaseByAmountDate(\PDO $pdo, int $supplierId, float $absAmount, string $postedAt, int $transactionId, ?string $txCurrency): array
    {
        $win = self::AMOUNT_DATE_DAY_WINDOW;
        $sql = "SELECT pi.id, pi.status,
                       COALESCE(pi.amount_to_pay, pi.total_with_vat, 0) + COALESCE(pi.rounding, 0) AS amount_to_pay,
                       pi.exchange_rate, cur.code AS currency
                  FROM purchase_invoices pi
             LEFT JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status IN ('received', 'booked', 'paid')
                   AND (ABS(DATEDIFF(pi.due_date, ?)) <= ? OR ABS(DATEDIFF(pi.issue_date, ?)) <= ?)
                   AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.purchase_invoice_id = pi.id)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$supplierId, $postedAt, $win, $postedAt, $win]);

        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = $this->expectedMatch((float) $r['amount_to_pay'], (string) ($r['currency'] ?? self::LOCAL_CURRENCY), (float) ($r['exchange_rate'] ?: 0), $txCurrency);
            if ($m === null) {
                continue; // měny nejdou spolehlivě porovnat → přeskoč
            }
            if (abs($absAmount - $m['expected']) <= $m['partial']) {
                $matches[] = $r;
            }
        }
        if (count($matches) !== 1) {
            return ['status' => 'unmatched', 'reason' => $matches === [] ? 'no_amount_date_match' : 'ambiguous_amount_date_match'];
        }
        $pi = $matches[0];
        $alreadyPaid = ($pi['status'] ?? '') === 'paid';

        $pdo->beginTransaction();
        try {
            if (!$alreadyPaid) {
                $pdo->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ? AND status <> 'paid'")
                    ->execute([$postedAt, $pi['id']]);
            }
            $pdo->prepare(
                "INSERT INTO payment_matches
                    (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
                 VALUES (?, ?, ?, ?, 'auto', 65)"
            )->execute([$supplierId, $transactionId, (int) $pi['id'], $absAmount]);
            $pdo->prepare("UPDATE bank_transactions SET match_status = 'auto_partial', matched_at = NOW() WHERE id = ?")
                ->execute([$transactionId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->logPaymentMatch('purchase_invoice', (int) $pi['id'], $supplierId, 'auto_partial', $absAmount, '', $transactionId, $alreadyPaid);

        return ['status' => 'auto_partial', 'purchase_invoice_id' => (int) $pi['id'], 'amount_date' => true];
    }

    /**
     * Podobnost dvou názvů firem (0..1) — Jaccard překryv normalizovaných tokenů.
     * Sdílený významný token (např. značka) → > 0.
     */
    private function nameSimilarity(string $a, string $b): float
    {
        $ta = $this->nameTokens($a);
        $tb = $this->nameTokens($b);
        if (!$ta || !$tb) return 0.0;
        $inter = array_intersect($ta, $tb);
        $union = array_unique(array_merge($ta, $tb));
        return count($union) > 0 ? count($inter) / count($union) : 0.0;
    }

    /**
     * Normalizace názvu na tokeny: velká písmena, bez diakritiky, jen alfanum, tokeny
     * délky >= 3, bez právních forem / kódů zemí / častých lokalit.
     *
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
            if (strlen($tok) < 3 || in_array($tok, $stop, true)) continue;
            $tokens[] = $tok;
        }
        return array_values(array_unique($tokens));
    }
}
