-- MyInvoice.cz — Per-dodavatel práh „po kolika dnech po splatnosti poslat první upomínku".
--
-- Cron `bin/cron-send-reminders.php` upomene fakturu, která je víc než N dní
-- po splatnosti. Dosud byla hodnota napevno (CLI --days, default 3); nově ji
-- lze nastavit per dodavatel v Nastavení. CLI --days ji případně přebije.
-- Default 3 = zachovává stávající chování.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS reminder_days_after_due INT NOT NULL DEFAULT 3 AFTER auto_send_reminders;
