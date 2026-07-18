-- Bankovní pohyby importované z iDokladu.
-- source_ref nese stabilní iDoklad BankStatement.Id a zajišťuje idempotenci.

ALTER TABLE bank_statements
  MODIFY COLUMN source ENUM('gpc','email_notice','pdf','idoklad') NOT NULL DEFAULT 'gpc';

ALTER TABLE bank_transactions
  MODIFY COLUMN source ENUM('statement','email_notice','idoklad') NOT NULL DEFAULT 'statement';

ALTER TABLE bank_transactions
  ADD UNIQUE KEY IF NOT EXISTS uq_bank_transaction_source_ref (source, source_ref);
