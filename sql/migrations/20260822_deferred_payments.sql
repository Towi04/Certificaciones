-- Pagos diferidos (meses) + calendario por compra
ALTER TABLE purchases
  ADD COLUMN installment_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER charged_amount,
  ADD COLUMN installment_amount DECIMAL(12,2) NULL AFTER installment_count,
  ADD COLUMN paid_installments TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER installment_amount;

CREATE TABLE IF NOT EXISTS purchase_installments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id BIGINT UNSIGNED NOT NULL,
  sequence_no TINYINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  due_date DATE NULL,
  status ENUM('pending','awaiting_payment','paid','cancelled') NOT NULL DEFAULT 'pending',
  openpay_charge_id VARCHAR(80) NULL,
  openpay_clabe VARCHAR(30) NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_purchase_installment_seq (purchase_id, sequence_no),
  KEY idx_installments_status (status),
  KEY idx_installments_openpay (openpay_charge_id),
  CONSTRAINT fk_installments_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
