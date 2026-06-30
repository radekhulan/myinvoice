-- Strojově čitelný / autoritativní ZDROJOVÝ artefakt přijaté faktury.
--
-- Samostatná osa vedle `pdf_*` (lidsky čitelný render). U importů ze strukturovaného
-- zdroje (ISDOC/ISDOCX, později Pohoda XML / iDoklad / Fakturoid JSON) se sem uloží
-- ORIGINÁLNÍ nahrané bajty as-is (u .isdocx NEROZBALENÉ — zachová podpis ZIP obálky;
-- u embedded ISDOC v PDF/A-3 vytažený XML). Důvod: strojový originál má pro audit/FÚ
-- (10letá archivační lhůta) vyšší hodnotu než PDF render a umožňuje zpětnou
-- rekonstrukci/re-extrakci bez datové migrace.
--
-- `source_format` je formát-agnostický ENUM, ať budoucí zdroje nepotřebují další migraci.
-- Zápis je write-once (PurchaseInvoiceRepository::setSourceMetadata nepřepisuje, je-li
-- source_path už vyplněn) — evidenční stopa se nesmí přepsat re-extrakcí.

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS source_path VARCHAR(255) NULL DEFAULT NULL AFTER pdf_uploaded_at,
  ADD COLUMN IF NOT EXISTS source_hash CHAR(64) NULL DEFAULT NULL AFTER source_path,
  ADD COLUMN IF NOT EXISTS source_size_bytes INT(10) UNSIGNED NULL DEFAULT NULL AFTER source_hash,
  ADD COLUMN IF NOT EXISTS source_original_name VARCHAR(255) NULL DEFAULT NULL AFTER source_size_bytes,
  ADD COLUMN IF NOT EXISTS source_format ENUM('isdoc','isdocx','pdf','pohoda_xml','idoklad_json','fakturoid_json') NULL DEFAULT NULL AFTER source_original_name,
  ADD COLUMN IF NOT EXISTS source_uploaded_at TIMESTAMP NULL DEFAULT NULL AFTER source_format;
