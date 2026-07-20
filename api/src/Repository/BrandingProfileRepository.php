<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class BrandingProfileRepository
{
    private const FIELDS = [
        'name', 'display_name', 'tagline', 'email', 'reply_to', 'phone', 'web',
        'email_footer', 'accent_color', 'pdf_logo_show_name', 'is_active',
        'email_profile_id',
    ];

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM branding_profiles WHERE supplier_id = ?';
        if ($activeOnly) $sql .= ' AND is_active = 1';
        $sql .= ' ORDER BY is_active DESC, name ASC, id ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findForSupplier(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM branding_profiles WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function create(int $supplierId, array $data): int
    {
        $normalized = $this->normalize($data);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO branding_profiles
                (supplier_id, name, display_name, tagline, email, reply_to, phone, web,
                 email_footer, accent_color, pdf_logo_show_name, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId, $normalized['name'], $normalized['display_name'], $normalized['tagline'],
            $normalized['email'], $normalized['reply_to'], $normalized['phone'], $normalized['web'],
            $normalized['email_footer'], $normalized['accent_color'],
            $normalized['pdf_logo_show_name'], $normalized['is_active'],
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function update(int $id, int $supplierId, array $data): bool
    {
        $sets = [];
        $params = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $data)) continue;
            $sets[] = $field . ' = ?';
            $params[] = $this->normalizeValue($field, $data[$field]);
        }
        if ($sets === []) return $this->findForSupplier($id, $supplierId) !== null;
        $params[] = $id;
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE branding_profiles SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0 || $this->findForSupplier($id, $supplierId) !== null;
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM branding_profiles WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    public function setLogoPath(int $id, int $supplierId, ?string $logoPath): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE branding_profiles SET logo_path = ? WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$logoPath, $id, $supplierId]);
        return $stmt->rowCount() > 0 || $this->findForSupplier($id, $supplierId) !== null;
    }

    public function saveInvoiceTemplate(int $id, int $supplierId, string $html, string $css): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE branding_profiles SET invoice_template_html = ?, invoice_template_css = ? WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$html, $css, $id, $supplierId]);
        return $stmt->rowCount() > 0 || $this->findForSupplier($id, $supplierId) !== null;
    }

    /** @return array{html:?string,css:?string}|null */
    public function invoiceTemplate(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT invoice_template_html, invoice_template_css FROM branding_profiles WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['html' => $row['invoice_template_html'], 'css' => $row['invoice_template_css']];
    }

    public function resetInvoiceTemplate(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE branding_profiles SET invoice_template_html = NULL, invoice_template_css = NULL WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0 || $this->findForSupplier($id, $supplierId) !== null;
    }

    /** @return array<string,mixed> */
    private function normalize(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $default = match ($field) {
                'accent_color' => '#3B2D83',
                'pdf_logo_show_name', 'is_active' => true,
                default => null,
            };
            $out[$field] = $this->normalizeValue($field, $data[$field] ?? $default);
        }
        return $out;
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        if (in_array($field, ['pdf_logo_show_name', 'is_active'], true)) return !empty($value) ? 1 : 0;
        if ($field === 'email_profile_id') return $value === null || $value === '' ? null : (int) $value;
        $value = trim((string) ($value ?? ''));
        if ($field === 'accent_color') return strtoupper($value ?: '#3B2D83');
        return $value === '' && $field !== 'name' ? null : $value;
    }

    /** @return array<string,mixed> */
    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['pdf_logo_show_name'] = (bool) $row['pdf_logo_show_name'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['email_profile_id'] = $row['email_profile_id'] !== null ? (int) $row['email_profile_id'] : null;
        $row['has_invoice_template'] = !empty($row['invoice_template_html']);
        unset($row['invoice_template_html'], $row['invoice_template_css']);
        return $row;
    }
}
