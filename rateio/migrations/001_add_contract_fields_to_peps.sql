-- =====================================================================
--  MIGRAÇÃO: Adicionar campos de contrato à tabela peps
--  Data: 2026-09-12
-- =====================================================================

-- Adicionar novos campos à tabela peps
ALTER TABLE peps ADD COLUMN IF NOT EXISTS centro_custo VARCHAR(12) DEFAULT '';
ALTER TABLE peps ADD COLUMN IF NOT EXISTS periodo_meses INT DEFAULT 12;
ALTER TABLE peps ADD COLUMN IF NOT EXISTS data_inicio DATE DEFAULT NULL;
ALTER TABLE peps ADD COLUMN IF NOT EXISTS data_termino DATE DEFAULT NULL;
ALTER TABLE peps ADD COLUMN IF NOT EXISTS responsavel_nome VARCHAR(150) DEFAULT '';
ALTER TABLE peps ADD COLUMN IF NOT EXISTS responsavel_cpf VARCHAR(20) DEFAULT '';
