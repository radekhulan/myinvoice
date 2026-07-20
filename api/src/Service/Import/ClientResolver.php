<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Ares\AresClient;

/**
 * Resolve klienta z importovaného XML/ISDOC:
 *   1. Lookup v `clients` podle (supplier_id, ic) — pokud existuje, vrátí jeho id.
 *   2. Pokud ne, ARES lookup podle IČO — preferovaná fakturační adresa z ARES.
 *   3. Fallback na adresu z XML, pokud ARES IČO nezná (zahraniční, neexistující).
 *   4. Vytvoří nový clients row, vrátí id.
 *
 * Vstup: array z parseru (`company_name, ic, dic, street, city, zip, country_iso2, email, phone`).
 * Pokud je email dostupný, použije se jako volitelný `main_email`.
 */
final class ClientResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClientRepository $clients,
        private readonly AresClient $ares,
        private readonly \MyInvoice\Service\Ares\ViesClient $vies,
        private readonly \MyInvoice\Service\Ares\VendorVatPayerResolver $vatPayer,
    ) {}

    /**
     * Resolve klient pro vydané faktury (default — `is_customer=1, is_vendor=0`).
     *
     * @param array<string,?string> $parsedClient
     * @return array{id:int, created:bool}
     */
    public function resolve(array $parsedClient, int $supplierId): array
    {
        return $this->resolveAny($parsedClient, $supplierId, isCustomer: true, isVendor: false);
    }

    /**
     * Resolve vendor pro přijaté faktury — `is_vendor=1`. Pokud existující klient
     * matchuje IČ ale dosud nebyl vendor, jen flagne is_vendor=1 (zachová is_customer).
     * Dual-role firma OK.
     *
     * @param array<string,?string> $parsedVendor
     * @return array{id:int, created:bool, role_added:bool, is_vat_payer:?bool}
     */
    public function resolveVendor(array $parsedVendor, int $supplierId): array
    {
        $r = $this->resolveAny($parsedVendor, $supplierId, isCustomer: false, isVendor: true);

        // Pokud našel existující (created=false), zkontroluj is_vendor flag a doplň.
        $roleAdded = false;
        if (!$r['created']) {
            $stmt = $this->db->pdo()->prepare('SELECT is_vendor FROM clients WHERE id = ?');
            $stmt->execute([$r['id']]);
            $isVendor = (int) $stmt->fetchColumn();
            if ($isVendor === 0) {
                $this->clients->markAsVendor($r['id']);
                $roleAdded = true;
            }
        }

        // Plátcovství DPH dodavatele z ARES (CZ) / VIES (zahraniční) — uloží na klienta
        // (nový i existující). Z toho pak import vynutí vat_deduction='none' u neplátce.
        $vat = $this->vatPayer->resolveAndPersist($r['id'], $parsedVendor['ic'] ?? null, $parsedVendor['dic'] ?? null);

        return ['id' => $r['id'], 'created' => $r['created'], 'role_added' => $roleAdded, 'is_vat_payer' => $vat['is_vat_payer']];
    }

    /**
     * @param array<string,?string> $parsed
     * @return array{id:int, created:bool}
     */
    private function resolveAny(array $parsed, int $supplierId, bool $isCustomer, bool $isVendor): array
    {
        $ic = $this->normalizeIc($parsed['ic'] ?? null);

        // 1a. Lookup podle (supplier_id, ic) — primární klíč pro CZ entity
        if ($ic !== null) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM clients WHERE supplier_id = ? AND ic = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $ic]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                return ['id' => (int) $existing, 'created' => false];
            }
        }

        // 1b. Lookup podle DIČ (pro EU dodavatele bez CZ IČO — Anthropic PBC, Stripe, ...)
        $dic = trim((string) ($parsed['dic'] ?? ''));
        if ($ic === null && $dic !== '') {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM clients WHERE supplier_id = ? AND dic = ? LIMIT 1"
            );
            $stmt->execute([$supplierId, $dic]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                return ['id' => (int) $existing, 'created' => false];
            }
        }

        // 1c. Last resort — exact company_name match (pro zahraniční bez IČO i DIČ).
        // Bez tohoto by AI/inbox scan vytvořila duplikát pro každou fakturu.
        $companyName = trim((string) ($parsed['company_name'] ?? ''));
        if ($ic === null && $dic === '' && $companyName !== '') {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM clients
                  WHERE supplier_id = ?
                    AND (ic IS NULL OR ic = '')
                    AND (dic IS NULL OR dic = '')
                    AND company_name = ?
                  LIMIT 1"
            );
            $stmt->execute([$supplierId, $companyName]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                return ['id' => (int) $existing, 'created' => false];
            }
        }

        $parsedClient = $parsed;

        // 2. ARES merge — pokud IČO je české (8 číslic) a ARES odpoví
        $aresData = null;
        if ($ic !== null && strlen($ic) === 8) {
            $resp = $this->ares->lookup($ic);
            if ($resp !== null && ($resp['found'] ?? false) && isset($resp['data'])) {
                $aresData = $resp['data'];
            }
        }

        // 2b. VIES fallback — pokud nemáme IČO ale máme DIČ (zahraniční dodavatel),
        // zkusíme VIES validation pro company_name + adresu z EU registru.
        $viesData = null;
        $rawDic = trim((string) ($parsedClient['dic'] ?? ''));
        if ($aresData === null && $ic === null && $rawDic !== '') {
            try {
                $viesResp = $this->vies->lookup($rawDic);
                if (!empty($viesResp['valid']) && !empty($viesResp['data'])) {
                    $viesData = $viesResp['data'];
                }
            } catch (\Throwable) {
                // VIES timeout / network failure — silent fallback na parsedClient data
            }
        }

        // 3. Sestavení dat klienta — ARES → VIES → AI/XML extracted data (priority)
        // main_email je od #221 nullable — neznámý e-mail necháváme prázdný,
        // dřívější placeholder 'unknown@import.local' vypadal jako reálná adresa.
        $email = trim((string) ($parsedClient['email'] ?? '')) ?: null;

        $data = [
            'company_name' => $aresData['company_name']
                ?? $viesData['company_name']
                ?? ($parsedClient['company_name'] ?? 'Importovaný klient'),
            'ic'           => $ic,
            'dic'          => $aresData['dic'] ?? $viesData['dic'] ?? ($parsedClient['dic'] ?? null) ?: null,
            'street'       => $aresData['street'] ?? $viesData['street'] ?? ($parsedClient['street'] ?? '') ?: '—',
            'city'         => $aresData['city']   ?? $viesData['city']   ?? ($parsedClient['city']   ?? '') ?: '—',
            'zip'          => $aresData['zip']    ?? $viesData['zip']    ?? ($parsedClient['zip']    ?? '') ?: '00000',
            'country_iso2' => $aresData['country_iso2'] ?? $viesData['country_iso2']
                ?? ($parsedClient['country_iso2'] ?? 'CZ') ?: 'CZ',
            'main_email'   => $email,
            'phone'        => $parsedClient['phone'] ?? null,
            'web'          => $parsedClient['web'] ?? null,
            'language'     => 'cs',
            'is_customer'  => $isCustomer,
            'is_vendor'    => $isVendor,
        ];

        // 4. Create
        $id = $this->clients->create($data, $supplierId);
        return ['id' => $id, 'created' => true];
    }

    private function normalizeIc(?string $ic): ?string
    {
        if ($ic === null) return null;
        $clean = preg_replace('/\D/', '', $ic) ?? '';
        return $clean !== '' ? $clean : null;
    }
}
