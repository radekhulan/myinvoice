-- Částka k úhradě vydané faktury zahrnuje zaokrouhlení dokladu (#258).
-- Zaokrouhlení převzaté z importu (Fakturoid / iDoklad) se do DPH nepočítá,
-- ale klient platí zaokrouhlenou částku, takže na ni musí sedět párování plateb,
-- stav úhrady, QR platba i pohledávky. Existující doklady mají rounding = 0,
-- hodnoty se tedy nemění. MODIFY je opakovatelné.

SET NAMES utf8mb4;

ALTER TABLE invoices
  MODIFY COLUMN amount_to_pay DECIMAL(12,2)
    AS (total_with_vat + rounding - advance_paid_amount) STORED;
