-- Idempotencia del abono de crédito partner al confirmar pago.
ALTER TABLE purchases
  ADD COLUMN partner_credit_applied_at DATETIME NULL AFTER partner_credit_earned;
