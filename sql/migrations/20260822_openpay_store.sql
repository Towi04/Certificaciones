-- OXXO / tienda de conveniencia vía OpenPay
ALTER TABLE purchases
  MODIFY payment_method ENUM(
    'none','openpay_spei','openpay_card','openpay_store',
    'transfer_proof','partner_account','credit'
  ) NOT NULL DEFAULT 'none',
  ADD COLUMN openpay_store_reference VARCHAR(50) NULL AFTER openpay_clabe,
  ADD COLUMN openpay_barcode_url VARCHAR(512) NULL AFTER openpay_store_reference;
