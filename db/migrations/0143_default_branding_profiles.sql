-- Sjednocení původního supplier brandingu s brandingovými profily (#195).
SET NAMES utf8mb4;

ALTER TABLE branding_profiles
  ADD COLUMN IF NOT EXISTS branding_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER accent_color;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS default_branding_profile_id INT UNSIGNED NULL AFTER pdf_logo_show_name;

-- Pro každý existující supplier vytvoř profil přesně odpovídající dosavadnímu
-- implicitnímu brandingu. Vystavené doklady ani jejich snapshoty se nemění.
INSERT INTO branding_profiles
    (supplier_id, name, display_name, tagline, email, phone, web, logo_path,
     accent_color, branding_enabled, pdf_logo_show_name, is_active)
SELECT s.id,
       CASE WHEN EXISTS (
           SELECT 1 FROM branding_profiles existing
            WHERE existing.supplier_id = s.id AND existing.name = 'Výchozí profil'
       ) THEN CONCAT('Výchozí profil #', s.id) ELSE 'Výchozí profil' END,
       s.display_name, s.tagline, s.email, s.phone, s.web, s.logo_path,
       COALESCE(NULLIF(s.email_accent_color, ''), '#3B2D83'),
       s.email_branding_enabled, s.pdf_logo_show_name, 1
  FROM supplier s
 WHERE s.default_branding_profile_id IS NULL
   AND NOT EXISTS (
       SELECT 1 FROM branding_profiles bp
        WHERE bp.supplier_id = s.id AND bp.name = CONCAT('Výchozí profil #', s.id)
   );

UPDATE supplier s
JOIN branding_profiles bp
  ON bp.supplier_id = s.id
 AND bp.name = CASE WHEN EXISTS (
       SELECT 1 FROM branding_profiles existing
        WHERE existing.supplier_id = s.id AND existing.name = 'Výchozí profil'
          AND existing.id <> bp.id
     ) THEN CONCAT('Výchozí profil #', s.id) ELSE 'Výchozí profil' END
SET s.default_branding_profile_id = bp.id
WHERE s.default_branding_profile_id IS NULL;

-- Cyklický FK supplier → profil → supplier MariaDB odmítá kvůli existujícímu
-- ON DELETE CASCADE. Stejný supplier a ochranu výchozího profilu hlídá repository.
