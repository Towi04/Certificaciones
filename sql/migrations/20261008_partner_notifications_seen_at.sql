-- Marca de lectura del centro de notificaciones partner.
ALTER TABLE partners
  ADD COLUMN notifications_seen_at DATETIME NULL AFTER tutorial_json;
