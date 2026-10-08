-- Lotes de registro masivo partner (producto + fecha + CSV + un comprobante).

CREATE TABLE IF NOT EXISTS partner_registration_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  partner_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  exam_date DATE NULL,
  exam_time TIME NULL,
  expected_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  student_count INT UNSIGNED NOT NULL DEFAULT 0,
  proof_path VARCHAR(255) NULL,
  status ENUM('payment_review','paid','cancelled') NOT NULL DEFAULT 'payment_review',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  paid_at DATETIME NULL,
  paid_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_prb_partner (partner_id),
  KEY idx_prb_status (status),
  CONSTRAINT fk_prb_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
  CONSTRAINT fk_prb_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_prb_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_prb_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE purchases
  ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER partner_id,
  ADD KEY idx_purchases_batch (batch_id),
  ADD CONSTRAINT fk_purchases_batch FOREIGN KEY (batch_id) REFERENCES partner_registration_batches(id) ON DELETE SET NULL;
