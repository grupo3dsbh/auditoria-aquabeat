-- Correção da tabela titulo_cartoes
-- Adiciona coluna tipo_pagamento se não existir

-- Tentar adicionar a coluna (ignorar erro se já existir)
ALTER TABLE titulo_cartoes
ADD COLUMN IF NOT EXISTS tipo_pagamento VARCHAR(50) AFTER bandeira;

-- Garantir que numero_cartao tem o tamanho correto
ALTER TABLE titulo_cartoes
MODIFY COLUMN numero_cartao VARCHAR(150);

-- Adicionar índice se não existir
ALTER TABLE titulo_cartoes
ADD INDEX IF NOT EXISTS idx_tipo_pagamento (tipo_pagamento);
