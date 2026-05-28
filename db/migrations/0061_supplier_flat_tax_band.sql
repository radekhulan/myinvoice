-- MyInvoice.cz — Paušální daň (flat tax) band per supplier
--
-- Pro OSVČ v paušálním režimu potřebujeme vědět, do kterého pásma spadá, ať
-- můžeme spočítat aktuální % limitu pro pásmo a varovat před překročením
-- (TaxThresholdService → widget v Přehled / CRM).
--
-- Pásma a měsíční zálohy pro 2026 (per `Pokyn GFŘ`, viz manuál):
--   band1: do 1 000 000 Kč/rok      → 9 984 Kč/měs (2026; 2025 bylo 8 716)
--   band2: do 1 500 000 Kč/rok      → 16 745 Kč/měs
--   band3: do 2 000 000 Kč/rok      → 27 139 Kč/měs
--   none:  není v paušálu (klasický daňový režim)
--
-- Doplňkové info: paušalista nesmí být plátce DPH (podmínka §7a ZDP).
-- TaxThresholdService z toho vyplývá: pro paušalisty počítáme i tuto kontrolu.
--
-- Hodnoty pásem (limit + měsíční záloha) se mění s každým rokem — proto je
-- držíme v PHP (TaxThresholdService::FLAT_TAX_BANDS), ne v DB.

SET NAMES utf8mb4;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS flat_tax_band ENUM('none','band1','band2','band3') NOT NULL DEFAULT 'none'
        COMMENT 'Paušální daň pásmo. none = klasický daňový režim, band1/2/3 = paušál s limitem 1M/1.5M/2M Kč/rok.';
