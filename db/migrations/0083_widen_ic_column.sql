-- MyInvoice.cz — rozšíření sloupce IČ (clients.ic, supplier.ic) na VARCHAR(20)
--
-- Fakturoid import padal na zahraničních subjektech:
--   "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'ic'"
-- Tuzemské IČO má 8 číslic, ale `registration_no` zahraničního subjektu (SK/PL/DE…)
-- bývá delší než 10 znaků. Sloupec zarovnáváme na VARCHAR(20) (stejně jako `dic`).
--
-- ares_cache.ic záměrně NEMĚNÍME — drží jen tuzemská IČO (ARES je CZ registr).
--
-- Idempotence: MODIFY je deklarativní (opakované spuštění nastaví stejnou definici).

SET NAMES utf8mb4;

ALTER TABLE clients  MODIFY COLUMN ic VARCHAR(20) NULL;
ALTER TABLE supplier MODIFY COLUMN ic VARCHAR(20) NULL;
