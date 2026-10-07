-- Crédito de partner aplicado como forma de pago (parcial o total).
ALTER TABLE purchases
  ADD COLUMN partner_credit_used DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER partner_credit_earned;
