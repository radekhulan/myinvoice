<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class EmailTemplateRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Vrátí DB override šablony pro (code, locale) nebo null.
     * @return array{id:int, code:string, locale:string, subject:string, body_html:string, body_text:string, updated_at:string}|null
     */
    public function find(string $code, string $locale): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, locale, subject, body_html, body_text, updated_at, updated_by
               FROM email_templates WHERE code = ? AND locale = ?'
        );
        $stmt->execute([$code, $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $row['id'] = (int) $row['id'];
        if ($row['updated_by'] !== null) $row['updated_by'] = (int) $row['updated_by'];
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function listAll(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT id, code, locale, subject, updated_at FROM email_templates ORDER BY code, locale'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Upsert podle (code, locale). */
    public function save(string $code, string $locale, string $subject, string $bodyHtml, string $bodyText, ?int $userId): void
    {
        $existing = $this->find($code, $locale);
        $pdo = $this->db->pdo();
        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE email_templates
                    SET subject = ?, body_html = ?, body_text = ?, updated_by = ?
                  WHERE id = ?'
            );
            $stmt->execute([$subject, $bodyHtml, $bodyText, $userId, $existing['id']]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO email_templates (code, locale, subject, body_html, body_text, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$code, $locale, $subject, $bodyHtml, $bodyText, $userId]);
        }
    }

    public function delete(string $code, string $locale): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM email_templates WHERE code = ? AND locale = ?');
        return $stmt->execute([$code, $locale]);
    }
}
