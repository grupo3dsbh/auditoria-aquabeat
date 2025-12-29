-- Criar tabela para histórico de cartões (auditoria)
-- Permite rastrear todos os cartões usados por cada título

CREATE TABLE IF NOT EXISTS titulo_cartoes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    titulo_id BIGINT NOT NULL,
    numero_cartao VARCHAR(20),
    bandeira VARCHAR(50),
    tipo_pagamento VARCHAR(50),
    data_primeiro_uso DATETIME NULL,
    ordem_uso INT DEFAULT 1,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_titulo_id (titulo_id),
    INDEX idx_numero_cartao (numero_cartao),
    INDEX idx_bandeira (bandeira),

    FOREIGN KEY (titulo_id) REFERENCES titulos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remover campos de cartão da tabela titulos (movidos para titulo_cartoes)
-- Mantemos por enquanto como NULL para compatibilidade
ALTER TABLE titulos MODIFY COLUMN numero_cartao TEXT NULL;
ALTER TABLE titulos MODIFY COLUMN bandeira TEXT NULL;
ALTER TABLE titulos MODIFY COLUMN tipo_pagamento_cartao VARCHAR(100) NULL;

-- Adicionar comentários
ALTER TABLE titulos MODIFY COLUMN numero_cartao TEXT NULL COMMENT 'DEPRECATED - Usar tabela titulo_cartoes';
ALTER TABLE titulos MODIFY COLUMN bandeira TEXT NULL COMMENT 'DEPRECATED - Usar tabela titulo_cartoes';
ALTER TABLE titulos MODIFY COLUMN tipo_pagamento_cartao VARCHAR(100) NULL COMMENT 'DEPRECATED - Usar tabela titulo_cartoes';
