-- amount_to_pay nově zahrnuje `rounding` (haléřové zaokrouhlení „k úhradě").
--
-- Dřív:  total_with_vat - advance_paid_amount          (zaokrouhlení se ztrácelo)
-- Teď:   total_with_vat - advance_paid_amount + rounding
--
-- Důvod: u dokladů se zaokrouhlením (typicky ISDOC s <PayableRoundingAmount>,
-- účtenky z čerpaček) je reálná částka k úhradě = součet položek + zaokrouhlení.
-- `rounding` se dosud evidoval jen informativně a do amount_to_pay (= co se platí
-- a na co se párují platby z banky) nevstupoval → "k úhradě" sedělo o haléře vedle.
--
-- BEZPEČNÉ: všechny existující doklady mají rounding = 0.00 (NOT NULL DEFAULT 0),
-- takže přepočtená hodnota je identická (X - Y + 0 = X - Y). Mění se jen budoucí
-- zaokrouhlené doklady. `total_with_vat` zůstává základ+DPH (správně pro DPH/KH).

ALTER TABLE invoices
  MODIFY COLUMN amount_to_pay DECIMAL(12,2)
    AS (`total_with_vat` - `advance_paid_amount` + `rounding`) STORED;

ALTER TABLE purchase_invoices
  MODIFY COLUMN amount_to_pay DECIMAL(12,2)
    AS (`total_with_vat` - `advance_paid_amount` + `rounding`) STORED;
