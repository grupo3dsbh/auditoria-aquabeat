-- Aumentar tamanho de colunas que podem ter valores longos
ALTER TABLE titulos MODIFY COLUMN bandeira VARCHAR(100);
ALTER TABLE titulos MODIFY COLUMN numero_cartao VARCHAR(150);
ALTER TABLE titulos MODIFY COLUMN tipo_pagamento VARCHAR(100);
ALTER TABLE titulos MODIFY COLUMN forma_pagamento VARCHAR(150);
ALTER TABLE titulos MODIFY COLUMN origem_venda VARCHAR(150);
