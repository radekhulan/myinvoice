-- MyInvoice.cz — PDF nastavení přímo na podpisovém profilu
--
-- Profily už nejsou jen nosič certifikátu. Pro PDF podpisy drží i volitelnou
-- profilovou konfiguraci TSA a důvodu podpisu; prázdné hodnoty dědí výchozí
-- nastavení dodavatele.
--
-- Idempotence: MariaDB-native ADD COLUMN IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE signing_profiles
  ADD COLUMN IF NOT EXISTS pdf_tsa_url VARCHAR(255) NULL AFTER default_backend,
  ADD COLUMN IF NOT EXISTS pdf_tsa_username VARCHAR(190) NULL AFTER pdf_tsa_url,
  ADD COLUMN IF NOT EXISTS pdf_tsa_password_enc VARCHAR(255) NULL AFTER pdf_tsa_username,
  ADD COLUMN IF NOT EXISTS pdf_reason VARCHAR(120) NULL AFTER pdf_tsa_password_enc;
