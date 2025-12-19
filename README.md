# Sistema de Auditoria Aquabeat - Análise de Inadimplência

Sistema completo de análise e auditoria de inadimplência desenvolvido em PHP com integração de Inteligência Artificial para insights avançados.

## 📋 Índice

- [Características](#características)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Estrutura do Projeto](#estrutura-do-projeto)
- [Configuração](#configuração)
- [Uso](#uso)
- [Funcionalidades](#funcionalidades)
- [API de IA](#api-de-ia)
- [Queries SQL](#queries-sql)
- [Segurança](#segurança)
- [Licença](#licença)

## ✨ Características

### Análise Completa de Inadimplência
- **Taxa de Inadimplência por Período**: 3 meses, 6 meses e 1 ano
- **Análise de Títulos**: Títulos ativos, bloqueados, cancelados
- **Dados Completos de Cartão**: Identificação de fraudes por cartão compartilhado
- **Ranking de Consultores**: Lista de consultores com maior inadimplência
- **Identificação de Brechas**: Detecção automática de padrões suspeitos

### Sistema de Gestão
- **Gestão de Usuários**: 4 níveis de acesso (Admin, Gerente, Analista, Visualizador)
- **Log de Ações**: Rastreamento completo de todas as ações do sistema
- **Importação Inteligente de CSV**: Mapeamento automático e manual de colunas
- **Dashboard Interativo**: Visualização em tempo real dos indicadores
- **Filtros Avançados**: Múltiplos filtros para análise detalhada

### Inteligência Artificial
- **Resumo Automático**: Análise e resumo de dados com IA
- **Identificação de Alertas**: Detecção automática de pontos críticos
- **Suporte Groq e OpenAI**: Escolha o provedor de IA
- **Análises Personalizadas**: Análise por consultor, cartão e período

### Relatórios e Exportação
- **Geração de PDF**: Relatórios profissionais em PDF
- **Exportação CSV**: Download de dados filtrados
- **Relatórios Salvos**: Salvar configurações de filtros
- **Múltiplas Visualizações**: Tabelas, gráficos e indicadores

## 🔧 Requisitos

### Servidor
- PHP 7.4 ou superior
- MySQL 5.7 ou superior / MariaDB 10.2+
- Apache ou Nginx
- Extensões PHP:
  - PDO MySQL
  - cURL
  - mbstring
  - JSON
  - OpenSSL

### Opcional
- Composer (para futuras dependências)
- Node.js (para assets front-end)

## 📦 Instalação

### 1. Clonar o Repositório

```bash
git clone https://github.com/grupo3dsbh/auditoria-aquabeat.git
cd auditoria-aquabeat
```

### 2. Configurar Servidor Web

#### Apache
```apache
<VirtualHost *:80>
    ServerName auditoria.local
    DocumentRoot /var/www/auditoria-aquabeat/public

    <Directory /var/www/auditoria-aquabeat/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/auditoria-error.log
    CustomLog ${APACHE_LOG_DIR}/auditoria-access.log combined
</VirtualHost>
```

#### Nginx
```nginx
server {
    listen 80;
    server_name auditoria.local;
    root /var/www/auditoria-aquabeat/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 3. Permissões

```bash
chmod 755 /var/www/auditoria-aquabeat
chmod -R 755 auditoria-system/public
chmod -R 777 auditoria-system/uploads
chmod -R 777 auditoria-system/logs
```

### 4. Instalação via Web

Acesse `http://seu-dominio/install.php` e siga os passos:

1. **Configuração do Banco de Dados**
   - Host: `localhost`
   - Banco: `auditoria_aquabeat`
   - Usuário: `root` (ou seu usuário MySQL)
   - Senha: (sua senha MySQL)

2. **Criar Estrutura**
   - O instalador criará automaticamente todas as tabelas

3. **Configurações Finais**
   - Nome da Empresa: `Aquabeat Auditoria`
   - URL do Site: `http://seu-dominio`
   - Dados do Administrador
   - Chave da API de IA (opcional)

4. **Conclusão**
   - Acesse o sistema com as credenciais criadas

## 📁 Estrutura do Projeto

```
auditoria-aquabeat/
├── admin/                          # Painel administrativo
│   ├── index.php                   # Dashboard admin
│   ├── usuarios.php                # Gestão de usuários
│   ├── configuracoes.php           # Configurações do sistema
│   └── logs.php                    # Visualização de logs
├── assets/                         # Arquivos estáticos
│   ├── css/                        # Estilos CSS
│   ├── js/                         # Scripts JavaScript
│   └── img/                        # Imagens e logo
├── config/                         # Configurações
│   ├── config.sample.php           # Exemplo de configuração
│   └── config.php                  # Configuração (gerado na instalação)
├── database/                       # Scripts SQL
│   ├── schema.sql                  # Estrutura do banco de dados
│   └── queries_analise_inadimplencia.sql  # Queries de análise
├── includes/                       # Classes e funções
│   ├── Auth.php                    # Autenticação
│   ├── Database.php                # Conexão com banco
│   ├── Logger.php                  # Sistema de logs
│   ├── AIService.php               # Integração com IA
│   ├── CSVImporter.php             # Importação de CSV
│   └── bootstrap.php               # Inicialização
├── logs/                           # Arquivos de log
├── public/                         # Arquivos públicos
│   ├── index.php                   # Dashboard principal
│   ├── login.php                   # Login
│   ├── logout.php                  # Logout
│   ├── relatorios.php              # Relatórios
│   ├── upload.php                  # Upload de CSV
│   ├── install.php                 # Instalador
│   └── navbar.php                  # Menu de navegação
├── uploads/                        # Arquivos carregados
└── README.md                       # Este arquivo
```

## ⚙️ Configuração

### Arquivo de Configuração

O arquivo `config/config.php` é gerado automaticamente durante a instalação. Principais configurações:

```php
// Banco de Dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'auditoria_aquabeat');
define('DB_USER', 'root');
define('DB_PASS', '');

// API de IA (Opcional)
define('AI_PROVIDER', 'groq');  // 'groq' ou 'openai'
define('AI_API_KEY', 'sua-chave-aqui');
```

### Obter Chave da API de IA

#### Groq (Recomendado - Gratuito)
1. Acesse [console.groq.com](https://console.groq.com)
2. Crie uma conta
3. Vá em "API Keys"
4. Gere uma nova chave
5. Cole no arquivo de configuração

#### OpenAI (Pago)
1. Acesse [platform.openai.com](https://platform.openai.com)
2. Crie uma conta
3. Adicione créditos
4. Gere uma API key
5. Cole no arquivo de configuração

## 🚀 Uso

### 1. Login no Sistema

Credenciais padrão (altere após primeiro login):
- **Email**: admin@aquabeat.com
- **Senha**: (definida na instalação)

### 2. Importar Dados CSV

1. Acesse **Importar CSV** no menu
2. Faça upload do arquivo CSV exportado do SQL Server
3. Escolha mapeamento automático ou manual
4. Confirme a importação
5. Aguarde o processamento

### 3. Visualizar Relatórios

Acesse **Relatórios** para:
- Ver análise geral
- Filtrar por consultor
- Filtrar por cartão
- Filtrar por período
- Exportar em PDF ou CSV

### 4. Análise com IA

Na página de relatórios:
1. Configure os filtros desejados
2. Clique em "Analisar com IA"
3. Aguarde o processamento
4. Veja o resumo e insights

## 🎯 Funcionalidades

### Dashboard Principal
- **Visão Geral**: Indicadores principais (total de títulos, inadimplentes, etc.)
- **Top 5 Consultores**: Consultores com maior taxa de inadimplência
- **Top 5 Cartões de Risco**: Cartões suspeitos de fraude
- **Última Importação**: Informações da última importação

### Relatórios

#### Filtros Disponíveis
- **Status do Título**: Ativo, Bloqueado, Cancelado, Vencido
- **Status de Inadimplência**: Adimplente, Inadimplente, Parcial
- **Período**: 3 meses, 6 meses, 1 ano, customizado
- **Consultor**: Filtrar por vendedor
- **Cartão**: Filtrar por número de cartão
- **Apenas 1ª Parcela**: Títulos com pagamento somente da primeira parcela
- **Quantidade de Parcelas Pagas**: Filtro por número de parcelas pagas

#### Visualizações
- **Tabela Completa**: Todos os dados de títulos
- **Análise de Consultores**: Ranking e estatísticas
- **Análise de Cartões**: Cartões de risco
- **Análise Temporal**: Evolução por período

### Gestão de Usuários (Admin)

#### Níveis de Acesso
1. **Administrador**: Acesso total ao sistema
2. **Gerente**: Acesso a relatórios e análises avançadas
3. **Analista**: Acesso a relatórios e filtros
4. **Visualizador**: Apenas visualização de relatórios

#### Funcionalidades
- Criar, editar e excluir usuários
- Definir permissões específicas
- Visualizar log de ações de cada usuário
- Desativar/ativar usuários

### Log de Ações

O sistema registra automaticamente:
- Login/Logout de usuários
- Importações de CSV
- Criação/edição de usuários
- Alterações em configurações
- Exportação de relatórios
- Análises com IA

## 🤖 API de IA

### Groq

Modelo padrão: `llama-3.1-70b-versatile`

**Vantagens**:
- Gratuito
- Rápido
- Alta qualidade de análise
- Limite generoso de requisições

### OpenAI

Modelo padrão: `gpt-4-turbo-preview`

**Vantagens**:
- Análises mais detalhadas
- Melhor compreensão de contexto
- Mais opções de modelos

**Desvantagens**:
- Pago (por tokens)
- Pode ser mais lento

### Tipos de Análise

1. **Análise Geral**: Resumo executivo dos dados
2. **Análise de Consultores**: Foco em desempenho de vendedores
3. **Análise de Cartões**: Detecção de fraudes
4. **Análise Temporal**: Tendências ao longo do tempo

## 📊 Queries SQL

### Query Consolidada

O arquivo `database/queries_analise_inadimplencia.sql` contém a query consolidada que:

1. **Analisa Títulos**:
   - Status de inadimplência
   - Parcelas pagas vs restantes
   - Valores pagos e em aberto

2. **Analisa Cartões**:
   - Múltiplos documentos por cartão
   - Taxa de inadimplência por cartão
   - Nível de risco de fraude

3. **Analisa Consultores**:
   - Total de vendas
   - Taxa de inadimplência
   - Evolução por período (3, 6, 12 meses)
   - Valor recebido vs perdido

4. **Calcula Scores**:
   - Score de risco geral (0-100)
   - Classificação de risco (Baixo, Médio, Alto, Crítico)
   - Alertas automáticos

### Executar Manualmente

```sql
-- Conectar ao banco
USE [MultiClubes]

-- Executar a query
@database/queries_analise_inadimplencia.sql

-- Exportar resultado como CSV
-- Use SQL Server Management Studio ou bcp utility
```

## 🔒 Segurança

### Medidas Implementadas

1. **Autenticação**:
   - Senhas com hash bcrypt
   - Sessões com timeout
   - Proteção contra session hijacking

2. **Autorização**:
   - Níveis de acesso
   - Permissões granulares
   - Validação em cada requisição

3. **Proteção de Dados**:
   - SQL Injection: PDO com prepared statements
   - XSS: Sanitização de outputs
   - CSRF: Tokens em formulários (a implementar)

4. **Logs**:
   - Todas as ações são registradas
   - IP e User-Agent salvos
   - Retenção configurável

### Recomendações

1. **Altere senhas padrão** imediatamente
2. **Use HTTPS** em produção
3. **Mantenha PHP atualizado**
4. **Configure firewall** adequadamente
5. **Faça backups regulares** do banco de dados
6. **Limite acesso** ao diretório de uploads
7. **Monitore logs** regularmente

## 📈 Indicadores Principais

### Taxa de Inadimplência

```
Taxa = (Inadimplentes / Total de Títulos) × 100
```

**Classificação**:
- < 10%: Excelente
- 10-20%: Bom
- 20-30%: Atenção
- 30-50%: Alto
- > 50%: Crítico

### Score de Risco (0-100)

Calculado com base em:
- Status de inadimplência (30 pontos)
- Status do título (20 pontos)
- Taxa de inadimplência do cartão (25 pontos)
- Taxa de inadimplência do consultor (25 pontos)

**Classificação**:
- 0-25: Baixo Risco
- 26-50: Médio Risco
- 51-75: Alto Risco
- 76-100: Crítico

### Nível de Risco de Cartão

- **Baixo Risco**: < 5 documentos, taxa < 25%
- **Médio Risco**: 5-10 documentos, taxa 25-40%
- **Alto Risco**: > 10 documentos, taxa > 40%
- **Fraude Provável**: > 10 documentos + taxa > 60%

### Nível de Risco de Consultor

- **Baixo**: Taxa < 15%
- **Médio**: Taxa 15-30%
- **Alto**: Taxa 30-50%
- **Crítico**: Taxa > 50%

## 🔄 Workflow de Importação

1. **Upload**: Usuário faz upload do CSV
2. **Análise**: Sistema detecta encoding e colunas
3. **Mapeamento**: Sugestão automática ou manual
4. **Processamento**: Importação em lotes de 100 registros
5. **Agregação**: Cálculo de análises de consultores e cartões
6. **Finalização**: Status atualizado e dados disponíveis

## 🆘 Solução de Problemas

### Erro de Conexão com Banco

```
SQLSTATE[HY000] [1045] Access denied
```

**Solução**: Verifique credenciais em `config/config.php`

### Erro de Permissão em Uploads

```
Warning: move_uploaded_file(): Permission denied
```

**Solução**:
```bash
chmod -R 777 uploads/
chmod -R 777 logs/
```

### Timeout na Importação

**Solução**: Aumente o `max_execution_time` no php.ini:
```ini
max_execution_time = 300
```

### Erro na API de IA

```
Error: Invalid API key
```

**Solução**: Verifique a chave em Configurações do Admin

## 📝 Changelog

### Versão 1.0.0 (2025-01-19)
- ✨ Lançamento inicial
- 🔐 Sistema de autenticação completo
- 📊 Dashboard interativo
- 📁 Importação de CSV com mapeamento automático
- 🤖 Integração com Groq e OpenAI
- 📈 Relatórios avançados com filtros
- 📋 Gestão de usuários e permissões
- 📝 Sistema de logs completo

## 🤝 Suporte

Para suporte e dúvidas:
- **Email**: suporte@aquabeat.com
- **GitHub Issues**: [github.com/grupo3dsbh/auditoria-aquabeat/issues](https://github.com/grupo3dsbh/auditoria-aquabeat/issues)

## 📄 Licença

Copyright © 2025 Aquabeat. Todos os direitos reservados.

---

**Desenvolvido com ❤️ para Auditoria Aquabeat**
