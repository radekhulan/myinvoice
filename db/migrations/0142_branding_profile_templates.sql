-- Volitelné odesílací a PDF šablony brandingových profilů (#195).
SET NAMES utf8mb4;

ALTER TABLE branding_profiles
  ADD COLUMN IF NOT EXISTS email_profile_id BIGINT UNSIGNED NULL AFTER reply_to,
  ADD COLUMN IF NOT EXISTS invoice_template_html MEDIUMTEXT NULL AFTER pdf_logo_show_name,
  ADD COLUMN IF NOT EXISTS invoice_template_css MEDIUMTEXT NULL AFTER invoice_template_html;

ALTER TABLE branding_profiles
  ADD CONSTRAINT fk_branding_email_profile
    FOREIGN KEY IF NOT EXISTS (supplier_id, email_profile_id)
    REFERENCES email_profiles(supplier_id, id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS branding_email_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branding_profile_id INT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  locale CHAR(2) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  body_text MEDIUMTEXT NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branding_email_template (branding_profile_id, code, locale),
  CONSTRAINT fk_branding_email_template_profile FOREIGN KEY (branding_profile_id) REFERENCES branding_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_branding_email_template_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
