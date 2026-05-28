-- MyInvoice.cz — Splatnost faktury: jednotka (dny vs. kalendářní měsíc)
--
-- Před touto migrací byla splatnost vždy v dnech: supplier.default_payment_due_days
-- + per-client / per-project override `payment_due_default` (taky v dnech) a v UI
-- jen volný number input "Default splatnost (dnů)".
--
-- Praxe ale chce "měsíční" splatnost ve smyslu kalendářního měsíce, ne fixních
-- 30 dní — tedy 1.2. → 1.3. (a ne 3.3., jak by vyšlo +30 dní v únoru). A pro
-- různé klienty se preferovaná délka liší, takže potřebujeme per-client override
-- celého výrazu (unit + value), ne jen čísla.
--
-- Přidáváme:
--
--   * supplier.default_payment_due_unit — 'days' nebo 'month'. Spolu s existujícím
--     `default_payment_due_days` určuje výchozí splatnost. Při unit='month' se
--     `default_payment_due_days` interpretuje jako počet kalendářních měsíců
--     (typicky 1) a frontend přičte „+N months" se last-day overflow handlingem
--     (31.1. + 1 měsíc → 28./29.2., ne 3.3.).
--
--   * clients.payment_due_unit — per-client override jednotky.
--     NULL = dědit ze supplieru. Spolu s existujícím `payment_due_default`
--     (per-client override hodnoty) tvoří kompletní override.
--
-- Idempotence: MariaDB native IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS default_payment_due_unit ENUM('days','month') NOT NULL DEFAULT 'days'
        COMMENT 'Jednotka výchozí splatnosti. days = default_payment_due_days dní, month = tolik kalendářních měsíců (overflow → poslední den měsíce).'
        AFTER default_payment_due_days;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS payment_due_unit ENUM('days','month') NULL DEFAULT NULL
        COMMENT 'Per-client override jednotky splatnosti. NULL = dědit ze supplier.default_payment_due_unit.'
        AFTER payment_due_default;
