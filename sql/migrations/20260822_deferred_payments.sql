-- MSI con tarjeta OpenPay: meses elegidos por el alumno (el comercio recibe el total).
ALTER TABLE purchases
  ADD COLUMN card_msi_months TINYINT UNSIGNED NULL AFTER charged_amount;
