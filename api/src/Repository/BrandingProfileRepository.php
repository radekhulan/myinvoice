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
        'email_profile_id', 'branding_enabled',
    ];

    public function __construct(private readonly Connection $db) {}

    public function isEnabled(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT branding_profiles_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT bp.*, (s.default_branding_profile_id = bp.id) AS is_default
                  FROM branding_profiles bp JOIN supplier s ON s.id = bp.supplier_id
                 WHERE bp.supplier_id = ?';
        if ($activeOnly) $sql .= ' AND bp.is_active = 1';
        $sql .= ' ORDER BY is_default DESC, bp.is_active DESC, bp.name ASC, bp.id ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findForSupplier(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bp.*, (s.default_branding_profile_id = bp.id) AS is_default
               FROM branding_profiles bp JOIN supplier s ON s.id = bp.supplier_id
              WHERE bp.id = ? AND bp.supplier_id = ?'
        );
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
                 email_footer, accent_color, branding_enabled, pdf_logo_show_name, is_active, email_profile_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId, $normalized['name'], $normalized['display_name'], $normalized['tagline'],
            $normalized['email'], $normalized['reply_to'], $normalized['phone'], $normalized['web'],
            $normalized['email_footer'], $normalized['accent_color'],
            $normalized['branding_enabled'], $normalized['pdf_logo_show_name'], $normalized['is_active'],
            $normalized['email_profile_id'],
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
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE supplier SET default_branding_profile_id = NULL WHERE id = ? AND default_branding_profile_id = ?')
                ->execute([$supplierId, $id]);
            $stmt = $pdo->prepare('DELETE FROM branding_profiles WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$id, $supplierId]);
            $deleted = $stmt->rowCount() > 0;
            $pdo->commit();
            return $deleted;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function setDefault(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE supplier s
                JOIN branding_profiles bp ON bp.id = ? AND bp.supplier_id = s.id AND bp.is_active = 1
               SET s.default_branding_profile_id = bp.id
             WHERE s.id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0 || ($this->findForSupplier($id, $supplierId)['is_default'] ?? false);
    }

    public function defaultForSupplier(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bp.*, 1 AS is_default
               FROM supplier s JOIN branding_profiles bp ON bp.id = s.default_branding_profile_id AND bp.supplier_id = s.id
              WHERE s.id = ? AND s.branding_profiles_enabled = 1 AND bp.is_active = 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function setLogoPath(int $id, int $supplierId, ?string $logoPath): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE branding_profiles SET logo_path = ? WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$logoPath, $id, $supplierId]);
        return $stmt->rowCount() > 0 || $this->findForSupplier($id, $supplierId) !== null;
    }

    /** @return array<string,mixed> */
    private function normalize(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $default = match ($field) {
                'accent_color' => '#3B2D83',
                'branding_enabled', 'pdf_logo_show_name', 'is_active' => true,
                default => null,
            };
            $out[$field] = $this->normalizeValue($field, $data[$field] ?? $default);
        }
        return $out;
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        if (in_array($field, ['branding_enabled', 'pdf_logo_show_name', 'is_active'], true)) return !empty($value) ? 1 : 0;
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
        $row['branding_enabled'] = (bool) $row['branding_enabled'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['is_default'] = (bool) ($row['is_default'] ?? false);
        $row['email_profile_id'] = $row['email_profile_id'] !== null ? (int) $row['email_profile_id'] : null;
        return $row;
    }
}
