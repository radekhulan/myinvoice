-- Brandingové profily jsou volitelný modul. Migrace je samostatná také pro
-- vývojové instalace, které již spustily starší podobu migrace 0143.
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS branding_profiles_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER pdf_logo_show_name;
