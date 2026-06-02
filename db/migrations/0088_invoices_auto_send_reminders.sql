-- MyInvoice.cz — Per-faktura přepínač automatického posílání upomínek.
--
-- Když je 0, cron `bin/cron-send-reminders.php` přeskočí tuto konkrétní fakturu,
-- i když má dodavatel i klient upomínky zapnuté. Ruční upomínky (jednotlivé
-- i hromadné z UI) fungují dál.
-- Default 1 = upomínky se posílají (zachovává stávající chování).

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS auto_send_reminders TINYINT(1) NOT NULL DEFAULT 1 AFTER reverse_charge;
