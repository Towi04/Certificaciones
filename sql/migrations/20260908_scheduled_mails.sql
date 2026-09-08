-- Cola de correos/jobs diferidos (inventario iTEP, recordatorios, etc.)
CREATE TABLE IF NOT EXISTS scheduled_mails (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_id BIGINT UNSIGNED NOT NULL,
  job_code VARCHAR(80) NOT NULL,
  send_at DATETIME NOT NULL,
  payload_json JSON NULL,
  status ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  last_error VARCHAR(500) NULL,
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sched_due (status, send_at),
  KEY idx_sched_tracking (tracking_id, job_code, status),
  CONSTRAINT fk_sched_tracking FOREIGN KEY (tracking_id) REFERENCES trackings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
