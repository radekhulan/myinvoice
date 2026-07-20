-- MyInvoice.cz — více brandingových profilů pod jedním dodavatelem (#195)
--
-- Stávající branding na supplier zůstává implicitním výchozím profilem.
-- Nová tabulka proto obsahuje pouze další volitelné obchodní identity.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS branding_profiles (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_id            INT UNSIGNED NOT NULL,
  name                   VARCHAR(100) NOT NULL,
  display_name           VARCHAR(190) NULL,
  tagline                VARCHAR(255) NULL,
  email                  VARCHAR(190) NULL,
  reply_to               VARCHAR(190) NULL,
  phone                  VARCHAR(40) NULL,
  web                    VARCHAR(255) NULL,
  email_footer           TEXT NULL,
  logo_path              VARCHAR(500) NULL,
  accent_color           VARCHAR(7) NOT NULL DEFAULT '#3B2D83',
  pdf_logo_show_name     TINYINT(1) NOT NULL DEFAULT 1,
  is_active              TINYINT(1) NOT NULL DEFAULT 1,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branding_profiles_supplier_name (supplier_id, name),
  KEY idx_branding_profiles_supplier_active (supplier_id, is_active),
  CONSTRAINT fk_branding_profiles_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS default_branding_profile_id INT UNSIGNED NULL
    AFTER invoice_number_period;

ALTER TABLE recurring_invoice_templates
  ADD COLUMN IF NOT EXISTS branding_profile_id INT UNSIGNED NULL
    AFTER supplier_id;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS branding_profile_id INT UNSIGNED NULL
    AFTER supplier_id;

ALTER TABLE clients
  ADD CONSTRAINT fk_clients_default_branding_profile
    FOREIGN KEY IF NOT EXISTS (default_branding_profile_id) REFERENCES branding_profiles(id) ON DELETE SET NULL;

ALTER TABLE recurring_invoice_templates
  ADD CONSTRAINT fk_recurring_branding_profile
    FOREIGN KEY IF NOT EXISTS (branding_profile_id) REFERENCES branding_profiles(id) ON DELETE SET NULL;

ALTER TABLE invoices
  ADD CONSTRAINT fk_invoices_branding_profile
    FOREIGN KEY IF NOT EXISTS (branding_profile_id) REFERENCES branding_profiles(id) ON DELETE SET NULL;
