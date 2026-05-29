-- MyInvoice.cz — Podepisování PDF faktur certifikátem (PAdES)
--
-- Per-dodavatel server-side elektronický podpis PDF vydaných faktur a výkazů
-- víceprací. Úroveň PAdES-B, volitelně PAdES-T (RFC 3161 časové razítko).
-- Heslo k P12 je uloženo šifrovaně (SecretEncryption, prefix enc:v1:), samotný
-- P12 leží mimo web root pod data-dir (storage/certs/), nikdy ne v DB.
--
-- Idempotence: MariaDB-native IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS pdf_signing_enabled TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Podepisovat PDF vydaných faktur certifikátem?' AFTER signature_path,
  ADD COLUMN IF NOT EXISTS signing_cert_path VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Cesta k P12 certifikátu (pod data-dir, mimo web root).' AFTER pdf_signing_enabled,
  ADD COLUMN IF NOT EXISTS signing_cert_password_enc VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Heslo k P12, šifrované SecretEncryption (enc:v1:...).' AFTER signing_cert_path,
  ADD COLUMN IF NOT EXISTS signing_tsa_url VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'RFC 3161 TSA endpoint pro PAdES-T. NULL = PAdES-B bez razítka.' AFTER signing_cert_password_enc,
  ADD COLUMN IF NOT EXISTS signing_reason VARCHAR(100) NULL DEFAULT NULL
    COMMENT 'Důvod podpisu do signature dictionary (default Faktura).' AFTER signing_tsa_url;
