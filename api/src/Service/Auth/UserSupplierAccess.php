<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Per-user omezení přístupu na dodavatele (tabulka user_supplier_access).
 *
 * Sémantika: žádný záznam = bez omezení (uživatel vidí všechny dodavatele);
 * role admin vidí vždy vše. Enforcement dělá SupplierScopeMiddleware, tady je
 * sdílená logika pro middleware, /auth/me, GET /suppliers a admin správu uživatelů.
 */
final class UserSupplierAccess
{
    /** Per-request memoizace: middleware, /auth/me i GET /suppliers se ptají na totéž. */
    private ?bool $tableExists = null;
    /** @var array<int, list<int>|null> */
    private array $cache = [];

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Instalace, kde ještě neproběhla migrace 0148 (kód nasazený dřív než migrace),
     * se musí chovat jako bez omezení — ne spadnout na každém requestu.
     * Stejný přístup jako `supportsOssItemColumns` v InvoiceRepository.
     */
    private function tableExists(): bool
    {
        if ($this->tableExists === null) {
            $stmt = $this->db->pdo()->query("SHOW TABLES LIKE 'user_supplier_access'");
            $this->tableExists = $stmt !== false && $stmt->fetch() !== false;
        }
        return $this->tableExists;
    }

    /**
     * Povolené supplier_id pro uživatele; null = bez omezení (žádný záznam).
     *
     * @return list<int>|null
     */
    public function allowedIds(int $userId): ?array
    {
        if ($userId <= 0) return null;
        if (array_key_exists($userId, $this->cache)) return $this->cache[$userId];
        if (!$this->tableExists()) return $this->cache[$userId] = null;

        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id FROM user_supplier_access WHERE user_id = ? ORDER BY supplier_id'
        );
        $stmt->execute([$userId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        return $this->cache[$userId] = ($ids === [] ? null : $ids);
    }

    /**
     * Povolené supplier_id pro usera z request attributu; admin = null = vše.
     *
     * @param array<string, mixed> $user
     * @return list<int>|null
     */
    public function allowedIdsForUser(array $user): ?array
    {
        if (($user['role'] ?? '') === 'admin') return null;
        return $this->allowedIds((int) ($user['id'] ?? 0));
    }

    /**
     * Nahradí přiřazení uživatele (transakčně). Prázdné pole = zrušit omezení.
     *
     * @param list<int> $supplierIds
     */
    public function replaceForUser(int $userId, array $supplierIds): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_supplier_access WHERE user_id = ?')->execute([$userId]);
            if ($supplierIds !== []) {
                $ins = $pdo->prepare('INSERT INTO user_supplier_access (user_id, supplier_id) VALUES (?, ?)');
                foreach (array_values(array_unique(array_map('intval', $supplierIds))) as $sid) {
                    $ins->execute([$userId, $sid]);
                }
            }
            $pdo->commit();
            unset($this->cache[$userId]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Mapa user_id => list<int> pro admin výpis uživatelů (jeden dotaz).
     *
     * @return array<int, list<int>>
     */
    public function idsByUser(): array
    {
        $map = [];
        if (!$this->tableExists()) return $map;
        $rows = $this->db->pdo()->query(
            'SELECT user_id, supplier_id FROM user_supplier_access ORDER BY user_id, supplier_id'
        )->fetchAll(\PDO::FETCH_NUM);
        foreach ($rows as [$uid, $sid]) {
            $map[(int) $uid][] = (int) $sid;
        }
        return $map;
    }
}
