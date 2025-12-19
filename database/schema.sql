-- =====================================================
-- SCHEMA DO BANCO DE DADOS - SISTEMA DE AUDITORIA
-- =====================================================

-- Configurações do Sistema
CREATE TABLE IF NOT EXISTS config_sistema (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chave VARCHAR(100) UNIQUE NOT NULL,
    valor TEXT,
    descricao TEXT,
    tipo ENUM('texto', 'numero', 'booleano', 'arquivo', 'json') DEFAULT 'texto',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_chave (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuários do Sistema
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    nivel_acesso ENUM('admin', 'gerente', 'analista', 'visualizador') DEFAULT 'visualizador',
    ativo BOOLEAN DEFAULT TRUE,
    ultimo_login DATETIME NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    criado_por INT NULL,
    INDEX idx_email (email),
    INDEX idx_ativo (ativo),
    FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões dos Usuários
CREATE TABLE IF NOT EXISTS permissoes_usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    permissao VARCHAR(100) NOT NULL,
    concedida BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuario (usuario_id),
    INDEX idx_permissao (permissao),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE KEY unique_usuario_permissao (usuario_id, permissao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de Ações
CREATE TABLE IF NOT EXISTS log_acoes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    acao VARCHAR(100) NOT NULL,
    descricao TEXT,
    tabela VARCHAR(100) NULL,
    registro_id INT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    dados_anteriores JSON NULL,
    dados_novos JSON NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuario (usuario_id),
    INDEX idx_acao (acao),
    INDEX idx_criado_em (criado_em),
    INDEX idx_tabela_registro (tabela, registro_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Importações de CSV
CREATE TABLE IF NOT EXISTS importacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(500) NOT NULL,
    tipo_importacao ENUM('titulos', 'consultores', 'cartoes', 'completo') DEFAULT 'completo',
    status ENUM('pendente', 'processando', 'concluido', 'erro') DEFAULT 'pendente',
    total_linhas INT DEFAULT 0,
    linhas_processadas INT DEFAULT 0,
    linhas_erro INT DEFAULT 0,
    mapeamento_colunas JSON NULL,
    mensagem_erro TEXT NULL,
    usuario_id INT NOT NULL,
    iniciado_em DATETIME NULL,
    concluido_em DATETIME NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuario (usuario_id),
    INDEX idx_status (status),
    INDEX idx_tipo (tipo_importacao),
    INDEX idx_criado_em (criado_em),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapeamento de Colunas (Templates)
CREATE TABLE IF NOT EXISTS mapeamentos_colunas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT NULL,
    tipo_dados ENUM('titulos', 'consultores', 'cartoes', 'completo') DEFAULT 'completo',
    mapeamento JSON NOT NULL,
    exemplo_linha TEXT NULL,
    padrao BOOLEAN DEFAULT FALSE,
    usuario_id INT NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_usuario (usuario_id),
    INDEX idx_tipo (tipo_dados),
    INDEX idx_padrao (padrao),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dados dos Títulos (Principal)
CREATE TABLE IF NOT EXISTS titulos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    importacao_id INT NOT NULL,

    -- Dados do Título
    numero_titulo VARCHAR(100) NOT NULL,
    nome_produto_original VARCHAR(255) NULL,
    nome_produto_atual VARCHAR(255) NULL,
    alterou_vagas BOOLEAN DEFAULT FALSE,
    categoria VARCHAR(255) NULL,
    status_titulo VARCHAR(50) NULL,
    status_inadimplencia VARCHAR(100) NULL,
    periodo_titulo VARCHAR(50) NULL,
    dias_desde_venda INT NULL,

    -- Dados do Cliente
    nome_titular VARCHAR(255) NULL,
    documento_titular VARCHAR(50) NULL,
    telefone_residencial VARCHAR(50) NULL,

    -- Dados da Venda
    data_cadastro DATETIME NULL,
    data_primeira_venda DATETIME NULL,
    data_ultima_venda DATETIME NULL,
    origem_venda VARCHAR(100) NULL,
    promotor VARCHAR(255) NULL,
    gerente VARCHAR(255) NULL,

    -- Dados de Pagamento
    quantidade_parcelas_venda INT NULL,
    qtd_parcelas_pagas INT NULL,
    parcelas_restantes INT NULL,
    lista_parcelas_pagas TEXT NULL,
    lista_valores_pagos TEXT NULL,
    forma_pagamento VARCHAR(100) NULL,
    tipo_pagamento VARCHAR(50) NULL,
    valor_parcela DECIMAL(10, 2) NULL,
    valor_total_plano DECIMAL(10, 2) NULL,
    total_pago DECIMAL(10, 2) NULL,
    saldo_restante DECIMAL(10, 2) NULL,

    -- Dados do Cartão
    numero_cartao VARCHAR(100) NULL,
    bandeira VARCHAR(50) NULL,
    tipo_pagamento_cartao VARCHAR(50) NULL,

    -- Análise do Cartão
    total_titulos_no_cartao INT NULL,
    total_documentos_no_cartao INT NULL,
    total_promotores_no_cartao INT NULL,
    titulos_inadimplentes_no_cartao INT NULL,
    taxa_inadimplencia_cartao DECIMAL(5, 2) NULL,
    nivel_risco_cartao VARCHAR(50) NULL,

    -- Análise do Consultor
    vendas_consultor INT NULL,
    clientes_consultor INT NULL,
    inadimplentes_1parcela_consultor INT NULL,
    total_inadimplentes_consultor INT NULL,
    inadimplentes_3meses_consultor INT NULL,
    inadimplentes_6meses_consultor INT NULL,
    inadimplentes_1ano_consultor INT NULL,
    taxa_inadimplencia_consultor DECIMAL(5, 2) NULL,
    taxa_inadimplencia_3meses_consultor DECIMAL(5, 2) NULL,
    taxa_inadimplencia_6meses_consultor DECIMAL(5, 2) NULL,
    taxa_inadimplencia_1ano_consultor DECIMAL(5, 2) NULL,
    valor_recebido_consultor DECIMAL(10, 2) NULL,
    valor_perdido_consultor DECIMAL(10, 2) NULL,
    nivel_risco_consultor VARCHAR(50) NULL,

    -- Indicadores de Alerta
    alerta_apenas_1parcela BOOLEAN DEFAULT FALSE,
    alerta_cartao_risco BOOLEAN DEFAULT FALSE,
    alerta_consultor_risco BOOLEAN DEFAULT FALSE,
    alerta_alterou_vagas_inadimplente BOOLEAN DEFAULT FALSE,
    score_risco_geral INT NULL,

    -- Campos extras para dados dinâmicos do CSV
    dados_extras JSON NULL,

    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_importacao (importacao_id),
    INDEX idx_numero_titulo (numero_titulo),
    INDEX idx_documento_titular (documento_titular),
    INDEX idx_promotor (promotor),
    INDEX idx_status_titulo (status_titulo),
    INDEX idx_status_inadimplencia (status_inadimplencia),
    INDEX idx_data_primeira_venda (data_primeira_venda),
    INDEX idx_numero_cartao (numero_cartao),
    INDEX idx_score_risco (score_risco_geral),
    INDEX idx_alertas (alerta_apenas_1parcela, alerta_cartao_risco, alerta_consultor_risco),

    FOREIGN KEY (importacao_id) REFERENCES importacoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Análise de Consultores (Agregada)
CREATE TABLE IF NOT EXISTS analise_consultores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    importacao_id INT NOT NULL,
    promotor VARCHAR(255) NOT NULL,

    total_vendas INT DEFAULT 0,
    total_clientes INT DEFAULT 0,
    vendas_ativas INT DEFAULT 0,
    vendas_bloqueadas INT DEFAULT 0,
    vendas_canceladas INT DEFAULT 0,

    inadimplentes_1parcela INT DEFAULT 0,
    total_inadimplentes INT DEFAULT 0,
    total_adimplentes INT DEFAULT 0,

    inadimplentes_3meses INT DEFAULT 0,
    inadimplentes_6meses INT DEFAULT 0,
    inadimplentes_1ano INT DEFAULT 0,

    taxa_inadimplencia_geral DECIMAL(5, 2) NULL,
    taxa_inadimplencia_3meses DECIMAL(5, 2) NULL,
    taxa_inadimplencia_6meses DECIMAL(5, 2) NULL,
    taxa_inadimplencia_1ano DECIMAL(5, 2) NULL,

    valor_total_recebido DECIMAL(12, 2) NULL,
    valor_total_perdido DECIMAL(12, 2) NULL,

    nivel_risco VARCHAR(50) NULL,
    primeira_venda DATETIME NULL,
    ultima_venda DATETIME NULL,

    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_importacao (importacao_id),
    INDEX idx_promotor (promotor),
    INDEX idx_nivel_risco (nivel_risco),
    INDEX idx_taxa_inadimplencia (taxa_inadimplencia_geral),

    FOREIGN KEY (importacao_id) REFERENCES importacoes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_importacao_promotor (importacao_id, promotor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Análise de Cartões (Agregada)
CREATE TABLE IF NOT EXISTS analise_cartoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    importacao_id INT NOT NULL,
    numero_cartao VARCHAR(100) NOT NULL,
    bandeira VARCHAR(50) NULL,
    tipo_pagamento_cartao VARCHAR(50) NULL,

    total_titulos_no_cartao INT DEFAULT 0,
    total_documentos_no_cartao INT DEFAULT 0,
    total_promotores_no_cartao INT DEFAULT 0,
    titulos_ativos INT DEFAULT 0,
    titulos_bloqueados INT DEFAULT 0,
    titulos_cancelados INT DEFAULT 0,
    titulos_inadimplentes INT DEFAULT 0,

    taxa_inadimplencia_cartao DECIMAL(5, 2) NULL,

    primeira_venda_cartao DATETIME NULL,
    ultima_venda_cartao DATETIME NULL,

    nivel_risco VARCHAR(50) NULL,

    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_importacao (importacao_id),
    INDEX idx_numero_cartao (numero_cartao),
    INDEX idx_nivel_risco (nivel_risco),
    INDEX idx_taxa_inadimplencia (taxa_inadimplencia_cartao),

    FOREIGN KEY (importacao_id) REFERENCES importacoes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_importacao_cartao (importacao_id, numero_cartao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relatórios Salvos
CREATE TABLE IF NOT EXISTS relatorios_salvos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT NULL,
    filtros JSON NOT NULL,
    usuario_id INT NOT NULL,
    compartilhado BOOLEAN DEFAULT FALSE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_usuario (usuario_id),
    INDEX idx_compartilhado (compartilhado),

    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resumos de IA
CREATE TABLE IF NOT EXISTS resumos_ia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    importacao_id INT NULL,
    relatorio_salvo_id INT NULL,
    tipo_analise VARCHAR(100) NOT NULL,
    prompt TEXT NOT NULL,
    resposta TEXT NOT NULL,
    modelo VARCHAR(100) NULL,
    tokens_utilizados INT NULL,
    tempo_processamento DECIMAL(5, 2) NULL,
    usuario_id INT NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_importacao (importacao_id),
    INDEX idx_relatorio (relatorio_salvo_id),
    INDEX idx_usuario (usuario_id),
    INDEX idx_tipo (tipo_analise),

    FOREIGN KEY (importacao_id) REFERENCES importacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (relatorio_salvo_id) REFERENCES relatorios_salvos(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- DADOS INICIAIS
-- =====================================================

-- Inserir configurações padrão
INSERT INTO config_sistema (chave, valor, descricao, tipo) VALUES
('nome_empresa', 'Aquabeat Auditoria', 'Nome da empresa para exibição nos relatórios', 'texto'),
('logo_empresa', '', 'Caminho do logo da empresa', 'arquivo'),
('timezone', 'America/Sao_Paulo', 'Fuso horário do sistema', 'texto'),
('registros_por_pagina', '50', 'Número de registros por página', 'numero'),
('api_ia_provider', 'groq', 'Provedor de IA (groq ou openai)', 'texto'),
('api_ia_key', '', 'Chave da API de IA', 'texto'),
('api_ia_modelo', 'llama-3.1-70b-versatile', 'Modelo de IA a ser utilizado', 'texto'),
('smtp_host', '', 'Servidor SMTP para envio de e-mails', 'texto'),
('smtp_port', '587', 'Porta SMTP', 'numero'),
('smtp_user', '', 'Usuário SMTP', 'texto'),
('smtp_password', '', 'Senha SMTP', 'texto'),
('smtp_from', '', 'E-mail remetente', 'texto'),
('sistema_instalado', 'true', 'Indica se o sistema foi instalado', 'booleano'),
('versao_sistema', '1.0.0', 'Versão do sistema', 'texto')
ON DUPLICATE KEY UPDATE valor=VALUES(valor);

-- Inserir usuário admin padrão (senha: admin123 - DEVE SER ALTERADA)
-- Hash gerado com password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO usuarios (nome, email, senha_hash, nivel_acesso, ativo)
VALUES ('Administrador', 'admin@aquabeat.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', TRUE)
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

-- Permissões padrão por nível de acesso
-- (Serão aplicadas via código PHP)
