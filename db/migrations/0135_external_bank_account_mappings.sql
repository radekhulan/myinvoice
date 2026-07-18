-- Mapování bankovních účtů externích integrací na účty MyInvoice.
-- Externí synchronizace účty v currencies nevytváří ani nepřepisuje.

CREATE TABLE IF NOT EXISTS external_bank_account_mappings (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id         INT UNSIGNED NOT NULL,
    provider            VARCHAR(32) NOT NULL,
    external_account_id VARCHAR(100) NOT NULL,
    currency_id         INT UNSIGNED NULL,
    external_currency_id VARCHAR(100) NULL,
    external_bank_id    VARCHAR(100) NULL,
    account_number      VARCHAR(50) NULL,
    iban                VARCHAR(50) NULL,
    name                VARCHAR(100) NULL,
    is_default          TINYINT(1) NOT NULL DEFAULT 0,
    sync_status         ENUM('matched','unmatched','ambiguous') NOT NULL DEFAULT 'unmatched',
    synced_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_external_bank_account (supplier_id, provider, external_account_id),
    KEY idx_external_bank_currency (currency_id),
    KEY idx_external_bank_status (supplier_id, provider, sync_status),
    CONSTRAINT fk_external_bank_supplier FOREIGN KEY (supplier_id)
        REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_external_bank_currency FOREIGN KEY (currency_id)
        REFERENCES currencies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
