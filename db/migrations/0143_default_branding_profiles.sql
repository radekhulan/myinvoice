-- Brandingové profily jsou opt-in a ve výchozím stavu nemění stávající chování (#195).
SET NAMES utf8mb4;

ALTER TABLE branding_profiles
  ADD COLUMN IF NOT EXISTS branding_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER accent_color;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS branding_profiles_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER pdf_logo_show_name,
  ADD COLUMN IF NOT EXISTS default_branding_profile_id INT UNSIGNED NULL AFTER branding_profiles_enabled;

-- Cyklický FK supplier → profil → supplier MariaDB odmítá kvůli existujícímu
-- ON DELETE CASCADE. Stejný supplier a úklid defaultu hlídá repository.
