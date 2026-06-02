-- MyInvoice.cz — migrace legacy supplier PDF podpisu do podpisového profilu
--
-- Převádí původní supplier-level P12/TSA nastavení do admin profilu dodavatele.
-- Runtime po této migraci používá pouze signing_profiles/signing_credentials.

SET NAMES utf8mb4;

-- Pokud byl dříve smazaný profil se stejným kódem, uvolni unikátní klíč pro
-- migrovaný aktivní profil dodavatele.
UPDATE signing_profiles
   SET code = CONCAT('deleted_', id, '_supplier_default')
 WHERE code = 'supplier_default'
   AND deleted_at IS NOT NULL;

INSERT INTO signing_profiles
  (supplier_id, owner_user_id, name, code, allowed_usages_json, default_backend,
   pdf_tsa_url, pdf_tsa_username, pdf_tsa_password_enc, pdf_reason, is_active, created_by)
SELECT
  s.id,
  NULL,
  'Profil dodavatele',
  'supplier_default',
  JSON_ARRAY('pdf'),
  'native',
  NULLIF(s.signing_tsa_url, ''),
  NULLIF(s.signing_tsa_username, ''),
  NULLIF(s.signing_tsa_password_enc, ''),
  NULLIF(s.signing_reason, ''),
  1,
  NULL
FROM supplier s
WHERE COALESCE(s.signing_cert_path, '') <> ''
  AND NOT EXISTS (
    SELECT 1
      FROM signing_profiles p
     WHERE p.supplier_id = s.id
       AND p.code = 'supplier_default'
       AND p.deleted_at IS NULL
  );

INSERT INTO signing_credentials
  (profile_id, `usage`, certificate_path, certificate_fingerprint, certificate_subject,
   certificate_email, certificate_valid_from, certificate_valid_to, certificate_usage_json,
   passphrase_policy, passphrase_profile_id, encrypted_passphrase, is_active, created_by)
SELECT
  p.id,
  'pdf',
  s.signing_cert_path,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  'encrypted_store',
  NULL,
  s.signing_cert_password_enc,
  1,
  NULL
FROM supplier s
JOIN signing_profiles p
  ON p.supplier_id = s.id
 AND p.code = 'supplier_default'
 AND p.deleted_at IS NULL
WHERE COALESCE(s.signing_cert_path, '') <> ''
  AND NOT EXISTS (
    SELECT 1
      FROM signing_credentials c
     WHERE c.profile_id = p.id
       AND c.`usage` = 'pdf'
       AND c.deleted_at IS NULL
  );

-- Dřívější výchozí chování podepisovalo faktury/výkazy přímo podle supplier
-- certifikátu i bez explicitního řádku v pdf_signature_output_settings.
-- Po migraci proto založ explicitní výchozí mapování na migrovaný admin profil.
INSERT INTO pdf_signature_output_settings
  (supplier_id, output_type, enabled, backend, selection_source, user_profile_fallback,
   default_profile_id, failure_policy, signature_config_json)
SELECT
  p.supplier_id,
  output_types.output_type,
  1,
  'native',
  'admin_profile_settings',
  'fallback_unsigned',
  p.id,
  'fallback_unsigned',
  NULL
FROM signing_profiles p
JOIN supplier s
  ON s.id = p.supplier_id
JOIN (
    SELECT 'invoice' AS output_type
    UNION ALL
    SELECT 'work_report' AS output_type
) output_types
WHERE p.code = 'supplier_default'
  AND p.deleted_at IS NULL
  AND COALESCE(s.signing_cert_path, '') <> ''
  AND NOT EXISTS (
    SELECT 1
      FROM pdf_signature_output_settings existing
     WHERE existing.supplier_id = p.supplier_id
       AND existing.output_type = output_types.output_type
  );

UPDATE pdf_signature_output_settings o
JOIN signing_profiles p
  ON p.supplier_id = o.supplier_id
 AND p.code = 'supplier_default'
 AND p.deleted_at IS NULL
SET o.selection_source = 'admin_profile_settings',
    o.default_profile_id = p.id
WHERE o.selection_source = 'supplier_default';

UPDATE pdf_signature_output_settings o
SET o.selection_source = 'admin_profile_settings',
    o.default_profile_id = NULL
WHERE o.selection_source = 'supplier_default';

UPDATE pdf_signature_output_settings o
JOIN signing_profiles p
  ON p.supplier_id = o.supplier_id
 AND p.code = 'supplier_default'
 AND p.deleted_at IS NULL
SET o.user_profile_fallback = 'admin_profile_settings'
WHERE o.user_profile_fallback = 'supplier_default';

UPDATE pdf_signature_output_settings o
SET o.user_profile_fallback = 'fallback_unsigned'
WHERE o.user_profile_fallback = 'supplier_default';

UPDATE signature_document_overrides d
JOIN signing_profiles p
  ON p.supplier_id = d.supplier_id
 AND p.code = 'supplier_default'
 AND p.deleted_at IS NULL
SET d.selection_source = 'admin_profile_settings',
    d.admin_profile_id = p.id
WHERE d.selection_source = 'supplier_default';

UPDATE signature_document_overrides d
SET d.selection_source = 'admin_profile_settings',
    d.admin_profile_id = NULL
WHERE d.selection_source = 'supplier_default';

ALTER TABLE pdf_signature_output_settings
  MODIFY selection_source ENUM('logged_in_user','admin_profile_settings') NOT NULL DEFAULT 'admin_profile_settings',
  MODIFY user_profile_fallback ENUM('admin_profile_settings','fail_closed','fallback_unsigned') NOT NULL DEFAULT 'fallback_unsigned';

ALTER TABLE signature_document_overrides
  MODIFY selection_source ENUM('logged_in_user','admin_profile_settings') NOT NULL;

ALTER TABLE supplier
  DROP COLUMN IF EXISTS pdf_signing_enabled,
  DROP COLUMN IF EXISTS signing_cert_path,
  DROP COLUMN IF EXISTS signing_cert_password_enc,
  DROP COLUMN IF EXISTS signing_tsa_url,
  DROP COLUMN IF EXISTS signing_reason,
  DROP COLUMN IF EXISTS signing_tsa_username,
  DROP COLUMN IF EXISTS signing_tsa_password_enc;
