-- Programa de niveles partner (Bronze / Silver / Gold) + convenio.
-- CNCM u otros convenios especiales pueden quedar fuera del programa (tier_program = 0).

ALTER TABLE partners
  ADD COLUMN tier_program TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = participa en escala Bronze/Silver/Gold' AFTER tier,
  ADD COLUMN agreement_starts_at DATE NULL AFTER notes,
  ADD COLUMN agreement_ends_at DATE NULL AFTER agreement_starts_at,
  ADD COLUMN tier_evaluated_at DATETIME NULL AFTER agreement_ends_at;

-- Convenios especiales (p. ej. CNCM) no entran a la escala automática.
UPDATE partners SET tier_program = 0 WHERE tier = 'cncm';
