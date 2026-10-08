-- Progreso del tutorial onboarding del portal partner (por vista).

ALTER TABLE partners
  ADD COLUMN tutorial_json JSON NULL AFTER pending_json;
