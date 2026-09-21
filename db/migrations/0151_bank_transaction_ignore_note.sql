-- Volitelná uživatelská poznámka k ignorování bankovní transakce.
ALTER TABLE bank_transactions ADD COLUMN IF NOT EXISTS ignore_note VARCHAR(1000) NULL;
