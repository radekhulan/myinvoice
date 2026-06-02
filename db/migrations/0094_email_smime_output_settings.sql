-- MyInvoice.cz - S/MIME output settings + jednotny certifikat profilu
--
-- Pracovni migrace pro FR-45:
-- - rozsiri mapovani podpisovych vystupu o `usage`, aby se stejnym mechanismem
--   dalo mapovat PDF i S/MIME vystupy,
-- - sjednoti certifikat podpisoveho profilu na jeden credential bez rozliseni
--   PDF/S/MIME.
--
-- Idempotence: vse pres MariaDB native IF [NOT] EXISTS. Dedup credentialu
-- ZAMERNE neodkazuje sloupec `usage` (ktery tataz migrace mazu) v ORDER BY,
-- aby byl cely soubor re-runnable i po odstraneni sloupce. Poradi
-- (deleted_at IS NULL, is_active, id) zachova zivy/nejnovejsi credential;
-- pdf-preference neni potreba, protoze v dobe migrace jeste zadne S/MIME
-- credentialy neexistuji (≤1 credential na profil).

SET NAMES utf8mb4;

ALTER TABLE pdf_signature_output_settings
  ADD COLUMN IF NOT EXISTS `usage` ENUM('pdf','email_smime') NOT NULL DEFAULT 'pdf' AFTER supplier_id;

UPDATE pdf_signature_output_settings
   SET `usage` = 'email_smime'
 WHERE output_type LIKE 'email\_%';

-- Sjednoceni na 1 credential per profil: ponech ziveho/nejnovejsiho, ostatni smaz.
CREATE TEMPORARY TABLE IF NOT EXISTS keep_signing_credentials AS
  SELECT id
    FROM (
      SELECT id,
             ROW_NUMBER() OVER (
               PARTITION BY profile_id
               ORDER BY (deleted_at IS NULL) DESC,
                        is_active DESC,
                        id DESC
             ) AS rn
        FROM signing_credentials
    ) ranked
   WHERE rn = 1;

DELETE c
  FROM signing_credentials c
  LEFT JOIN keep_signing_credentials k ON k.id = c.id
 WHERE k.id IS NULL;

DROP TEMPORARY TABLE IF EXISTS keep_signing_credentials;

ALTER TABLE signing_credentials
  DROP INDEX IF EXISTS uq_signing_credential_usage;

ALTER TABLE signing_credentials
  DROP COLUMN IF EXISTS `usage`;

ALTER TABLE signing_credentials
  ADD UNIQUE KEY IF NOT EXISTS uq_signing_credential_profile (profile_id);
