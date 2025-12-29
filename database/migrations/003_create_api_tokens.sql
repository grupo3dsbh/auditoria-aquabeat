-- Migration: Criar tabela de tokens de API
-- Data: 2025-01-XX

CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    nome VARCHAR(255) NOT NULL COMMENT 'Nome descritivo do token (ex: "Token Mobile App")',
    token VARCHAR(64) UNIQUE NOT NULL COMMENT 'Token único gerado',
    descricao TEXT NULL COMMENT 'Descrição do uso do token',
    permissoes JSON NULL COMMENT 'Permissões específicas do token',
    ultimo_uso DATETIME NULL COMMENT 'Data do último uso',
    expira_em DATETIME NULL COMMENT 'Data de expiração (NULL = nunca expira)',
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_token (token),
    INDEX idx_usuario (usuario_id),
    INDEX idx_ativo (ativo),
    INDEX idx_expira_em (expira_em),

    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar índice composto para validação rápida
CREATE INDEX idx_token_ativo_expira ON api_tokens(token, ativo, expira_em);
