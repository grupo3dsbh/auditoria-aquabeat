-- Migration: Adicionar coluna usado_relatorios na tabela titulos
-- Data: 2025-12-22
-- Descrição: Adiciona coluna para controlar quais títulos são usados nos relatórios
--            Permite manter títulos DIP, SAC, SAP no banco sem exibi-los nos relatórios

-- Adicionar coluna usado_relatorios (default TRUE para SFA e SBF, FALSE para outros)
ALTER TABLE titulos ADD COLUMN usado_relatorios BOOLEAN DEFAULT TRUE;

-- Atualizar títulos existentes: SFA e SBF = TRUE
UPDATE titulos SET usado_relatorios = TRUE WHERE (numero_titulo LIKE 'SFA%' OR numero_titulo LIKE 'SBF%');

-- Atualizar títulos existentes: Outros prefixos = FALSE
UPDATE titulos SET usado_relatorios = FALSE WHERE numero_titulo NOT LIKE 'SFA%' AND numero_titulo NOT LIKE 'SBF%';

-- Adicionar índice para melhorar performance das queries
CREATE INDEX idx_titulos_usado_relatorios ON titulos(usado_relatorios);
