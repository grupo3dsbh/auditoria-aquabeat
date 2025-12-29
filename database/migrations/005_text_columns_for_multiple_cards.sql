-- Aumentar colunas para suportar múltiplos cartões (auditoria completa)
-- Usar TEXT para colunas que podem ter listas concatenadas

ALTER TABLE titulos MODIFY COLUMN numero_cartao TEXT;
ALTER TABLE titulos MODIFY COLUMN bandeira TEXT;
ALTER TABLE titulos MODIFY COLUMN tipo_pagamento_cartao VARCHAR(100);
ALTER TABLE titulos MODIFY COLUMN forma_pagamento VARCHAR(150);
ALTER TABLE titulos MODIFY COLUMN origem_venda VARCHAR(150);
ALTER TABLE titulos MODIFY COLUMN lista_parcelas_pagas TEXT;
ALTER TABLE titulos MODIFY COLUMN lista_valores_pagos TEXT;
