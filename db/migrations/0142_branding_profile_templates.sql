-- Volitelný odesílací profil brandingových profilů (#195).
SET NAMES utf8mb4;

ALTER TABLE branding_profiles
  ADD COLUMN IF NOT EXISTS email_profile_id BIGINT UNSIGNED NULL AFTER reply_to;

ALTER TABLE branding_profiles
  ADD CONSTRAINT fk_branding_email_profile
    FOREIGN KEY IF NOT EXISTS (supplier_id, email_profile_id)
    REFERENCES email_profiles(supplier_id, id) ON DELETE RESTRICT;
