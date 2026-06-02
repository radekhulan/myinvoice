-- MyInvoice.cz — obecné podpisové profily
--
-- Datový model pro budoucí správu podpisových profilů per supplier a per user.
-- Profily jsou primárním místem pro certifikáty, TSA a mapování výstupů.
-- Starší supplier-level podpis se migruje v 0093 do profilu dodavatele.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS signing_settings (
  supplier_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  accountant_profiles_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_signing_settings_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signing_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id TINYINT UNSIGNED NOT NULL,
  owner_user_id BIGINT UNSIGNED NULL COMMENT 'NULL = admin/supplier profil, jinak vlastni profil uzivatele.',
  name VARCHAR(120) NOT NULL,
  code VARCHAR(80) NOT NULL,
  allowed_usages_json JSON NOT NULL,
  default_backend VARCHAR(40) NOT NULL DEFAULT 'native',
  pdf_tsa_url VARCHAR(255) NULL,
  pdf_tsa_username VARCHAR(190) NULL,
  pdf_tsa_password_enc VARCHAR(255) NULL,
  pdf_reason VARCHAR(120) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,

  UNIQUE KEY uq_signing_profile_code (supplier_id, code),
  UNIQUE KEY uq_signing_profile_supplier_id (supplier_id, id),
  KEY idx_signing_profiles_supplier_owner (supplier_id, owner_user_id, deleted_at, is_active),
  KEY idx_signing_profiles_created_by (created_by),
  CONSTRAINT fk_signing_profile_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_signing_profile_owner
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_signing_profile_created_by
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signing_credentials (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_id BIGINT UNSIGNED NOT NULL,
  `usage` ENUM('pdf','email_smime') NOT NULL,
  certificate_path VARCHAR(255) NOT NULL,
  certificate_fingerprint CHAR(64) NULL,
  certificate_subject VARCHAR(255) NULL,
  certificate_email VARCHAR(190) NULL,
  certificate_valid_from DATETIME NULL,
  certificate_valid_to DATETIME NULL,
  certificate_usage_json JSON NULL,
  passphrase_policy ENUM('encrypted_store','passphrase_file','prompt_on_use') NOT NULL DEFAULT 'encrypted_store',
  passphrase_profile_id VARCHAR(120) NULL,
  encrypted_passphrase VARCHAR(512) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,

  UNIQUE KEY uq_signing_credential_usage (profile_id, `usage`),
  KEY idx_signing_credentials_profile (profile_id, deleted_at, is_active),
  KEY idx_signing_credentials_created_by (created_by),
  CONSTRAINT fk_signing_credential_profile
    FOREIGN KEY (profile_id) REFERENCES signing_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_signing_credential_created_by
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pdf_signature_output_settings (
  supplier_id TINYINT UNSIGNED NOT NULL,
  output_type VARCHAR(40) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  backend VARCHAR(40) NOT NULL DEFAULT 'native',
  selection_source ENUM('logged_in_user','admin_profile_settings') NOT NULL DEFAULT 'admin_profile_settings',
  user_profile_fallback ENUM('admin_profile_settings','fail_closed','fallback_unsigned') NOT NULL DEFAULT 'fallback_unsigned',
  default_profile_id BIGINT UNSIGNED NULL,
  failure_policy ENUM('fallback_unsigned','fail_closed','skip_when_unconfigured') NOT NULL DEFAULT 'fallback_unsigned',
  signature_config_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id, output_type),
  KEY idx_pdf_sig_default_profile (default_profile_id),
  CONSTRAINT fk_pdf_sig_output_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_pdf_sig_output_default_profile
    FOREIGN KEY (supplier_id, default_profile_id) REFERENCES signing_profiles(supplier_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signature_role_profiles (
  supplier_id TINYINT UNSIGNED NOT NULL,
  `usage` ENUM('pdf','email_smime') NOT NULL,
  output_type VARCHAR(40) NOT NULL,
  role ENUM('admin','accountant','readonly') NOT NULL,
  profile_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id, `usage`, output_type, role),
  KEY idx_sig_role_profile (profile_id),
  CONSTRAINT fk_sig_role_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sig_role_profile
    FOREIGN KEY (supplier_id, profile_id) REFERENCES signing_profiles(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signature_user_profiles (
  supplier_id TINYINT UNSIGNED NOT NULL,
  `usage` ENUM('pdf','email_smime') NOT NULL,
  output_type VARCHAR(40) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  profile_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id, `usage`, output_type, user_id),
  KEY idx_sig_user_profile (profile_id),
  CONSTRAINT fk_sig_user_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sig_user_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sig_user_profile
    FOREIGN KEY (supplier_id, profile_id) REFERENCES signing_profiles(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signature_document_overrides (
  supplier_id TINYINT UNSIGNED NOT NULL,
  `usage` ENUM('pdf','email_smime') NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  selection_source ENUM('logged_in_user','admin_profile_settings') NOT NULL,
  admin_profile_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id, `usage`, entity_type, entity_id),
  KEY idx_sig_doc_admin_profile (admin_profile_id),
  KEY idx_sig_doc_created_by (created_by),
  CONSTRAINT fk_sig_doc_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sig_doc_admin_profile
    FOREIGN KEY (supplier_id, admin_profile_id) REFERENCES signing_profiles(supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_sig_doc_created_by
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
