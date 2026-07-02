-- 0148_user_supplier_access.sql
-- Per-user omezení přístupu na dodavatele (multi-supplier instalace).
--
-- Sémantika: žádný záznam pro user_id = uživatel vidí všechny dodavatele
-- (zpětná kompatibilita — stávajících uživatelů se migrace nijak nedotkne).
-- Role admin restrikci vždy ignoruje; enforcement dělá SupplierScopeMiddleware.

CREATE TABLE IF NOT EXISTS user_supplier_access (
  user_id     BIGINT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED    NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, supplier_id),
  KEY idx_usa_supplier (supplier_id),
  CONSTRAINT fk_usa_user     FOREIGN KEY (user_id)     REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_usa_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
