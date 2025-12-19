# Guia de Instalação Rápida

## Instalação Local (Desenvolvimento)

### 1. Requisitos
```bash
# Verificar versão do PHP
php -v  # Deve ser 7.4 ou superior

# Verificar extensões
php -m | grep -E 'pdo|curl|mbstring|json'
```

### 2. Configurar Servidor Local

#### XAMPP/WAMP
1. Copie a pasta `auditoria-system` para `C:\xampp\htdocs\`
2. Acesse `http://localhost/auditoria-system/public/install.php`

#### PHP Built-in Server
```bash
cd auditoria-system/public
php -S localhost:8000
```
Acesse `http://localhost:8000/install.php`

### 3. Configurar Banco de Dados

#### MySQL via linha de comando
```bash
mysql -u root -p
```

```sql
CREATE DATABASE auditoria_aquabeat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'auditoria'@'localhost' IDENTIFIED BY 'senha_segura';
GRANT ALL PRIVILEGES ON auditoria_aquabeat.* TO 'auditoria'@'localhost';
FLUSH PRIVILEGES;
```

#### phpMyAdmin
1. Acesse `http://localhost/phpmyadmin`
2. Clique em "Novo"
3. Nome: `auditoria_aquabeat`
4. Cotejamento: `utf8mb4_unicode_ci`
5. Criar

### 4. Executar Instalador Web

Acesse `http://localhost/auditoria-system/public/install.php`

**Passo 1 - Banco de Dados:**
- Servidor: `localhost`
- Nome do Banco: `auditoria_aquabeat`
- Usuário: `root` (ou usuário criado)
- Senha: (sua senha)

**Passo 2 - Estrutura:**
- Clique em "Criar Estrutura"
- Aguarde criação das tabelas

**Passo 3 - Configuração:**
- Nome da Empresa: `Aquabeat Auditoria`
- URL: `http://localhost/auditoria-system/public`
- Nome Admin: Seu nome
- Email: seu@email.com
- Senha: Mínimo 8 caracteres
- Chave IA: (deixe em branco por enquanto)

**Passo 4 - Concluído:**
- Clique em "Acessar Sistema"
- Faça login com suas credenciais

## Instalação em Servidor de Produção

### 1. Upload via FTP/SFTP
```bash
# Copiar arquivos via SCP
scp -r auditoria-system/ user@servidor.com:/var/www/html/

# Ou via FTP client (FileZilla, WinSCP)
```

### 2. Configurar Permissões
```bash
ssh user@servidor.com
cd /var/www/html/auditoria-system

# Permissões de diretórios
chmod 755 public config includes database
chmod 777 uploads logs

# Permissões de arquivos
find . -type f -exec chmod 644 {} \;
find public -type f -name "*.php" -exec chmod 755 {} \;
```

### 3. Configurar Virtual Host

#### Apache
```bash
sudo nano /etc/apache2/sites-available/auditoria.conf
```

```apache
<VirtualHost *:80>
    ServerName auditoria.seudominio.com
    DocumentRoot /var/www/html/auditoria-system/public

    <Directory /var/www/html/auditoria-system/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/auditoria-error.log
    CustomLog ${APACHE_LOG_DIR}/auditoria-access.log combined
</VirtualHost>
```

```bash
sudo a2ensite auditoria.conf
sudo systemctl reload apache2
```

#### Nginx
```bash
sudo nano /etc/nginx/sites-available/auditoria
```

```nginx
server {
    listen 80;
    server_name auditoria.seudominio.com;
    root /var/www/html/auditoria-system/public;
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

    location ~ /\. {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/auditoria /etc/nginx/sites-enabled/
sudo systemctl reload nginx
```

### 4. SSL/HTTPS com Let's Encrypt
```bash
sudo apt install certbot python3-certbot-apache

# Para Apache
sudo certbot --apache -d auditoria.seudominio.com

# Para Nginx
sudo certbot --nginx -d auditoria.seudominio.com

# Renovação automática
sudo certbot renew --dry-run
```

### 5. Otimizações PHP (php.ini)
```bash
sudo nano /etc/php/7.4/apache2/php.ini
# ou
sudo nano /etc/php/7.4/fpm/php.ini
```

```ini
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
max_input_time = 300
memory_limit = 256M
date.timezone = America/Sao_Paulo
```

Reiniciar serviço:
```bash
sudo systemctl restart apache2
# ou
sudo systemctl restart php7.4-fpm
```

## Configuração da API de IA

### Groq (Gratuito)
1. Acesse https://console.groq.com
2. Crie uma conta
3. Vá em "API Keys"
4. Gere uma nova chave
5. No sistema: Admin > Configurações > API de IA
6. Cole a chave e salve

### OpenAI (Pago)
1. Acesse https://platform.openai.com
2. Crie uma conta
3. Adicione créditos (mínimo $5)
4. Gere uma API key
5. No sistema: Admin > Configurações > API de IA
6. Cole a chave e salve

## Primeiro Uso

### 1. Fazer Login
- URL: `http://seu-dominio/`
- Email: (email cadastrado na instalação)
- Senha: (senha cadastrada)

### 2. Preparar Dados CSV

Execute a query SQL no SQL Server Management Studio:
```sql
-- Usar a query em database/queries_analise_inadimplencia.sql
```

Exportar resultado:
1. Botão direito no resultado > Save Results As...
2. Salvar como: `dados_auditoria.csv`
3. Encoding: UTF-8

### 3. Importar CSV no Sistema

1. Menu: **Importar CSV**
2. Fazer upload do arquivo
3. Aguardar análise
4. Revisar mapeamento (geralmente detecta automaticamente)
5. Confirmar importação
6. Aguardar processamento (pode levar alguns minutos)

### 4. Visualizar Relatórios

1. Menu: **Relatórios**
2. Explorar as diferentes visualizações:
   - Visão Geral
   - Análise de Consultores
   - Análise de Cartões
   - Análise Temporal

### 5. Usar IA para Análise

1. Configurar chave da API (se ainda não fez)
2. Na página de relatórios, aplicar filtros
3. Clicar em "Analisar com IA"
4. Aguardar processamento
5. Ver insights e recomendações

## Backup

### Backup do Banco de Dados
```bash
# Via linha de comando
mysqldump -u root -p auditoria_aquabeat > backup_$(date +%Y%m%d).sql

# Restaurar
mysql -u root -p auditoria_aquabeat < backup_20250119.sql
```

### Backup dos Arquivos
```bash
# Comprimir tudo
tar -czf auditoria_backup_$(date +%Y%m%d).tar.gz auditoria-system/

# Ou apenas uploads e configurações
tar -czf auditoria_data_$(date +%Y%m%d).tar.gz \
    auditoria-system/uploads/ \
    auditoria-system/config/config.php
```

## Troubleshooting

### Erro: "Access denied for user"
- Verifique credenciais em `config/config.php`
- Teste conexão: `mysql -u usuario -p -h localhost`

### Erro: "Permission denied" em uploads
```bash
chmod -R 777 uploads/
chmod -R 777 logs/
```

### Página em branco após instalação
- Verificar logs do Apache/Nginx
- Ativar debug em `config/config.php`:
```php
define('DEBUG_MODE', true);
define('DISPLAY_ERRORS', true);
```

### CSV não importa
- Verificar encoding (deve ser UTF-8)
- Verificar tamanho do arquivo (máx 50MB)
- Ver logs em `logs/app-[data].log`

### API de IA não funciona
- Verificar chave em Configurações
- Testar chave: `curl https://api.groq.com/openai/v1/models -H "Authorization: Bearer SUA_CHAVE"`
- Ver logs de erro em `logs/error-[data].log`

## Suporte

- GitHub Issues: https://github.com/grupo3dsbh/auditoria-aquabeat/issues
- Email: suporte@aquabeat.com

## Próximos Passos

Após instalação:
1. ✅ Alterar senha do admin
2. ✅ Criar outros usuários (se necessário)
3. ✅ Configurar logo da empresa (Admin > Configurações)
4. ✅ Fazer primeira importação de dados
5. ✅ Explorar relatórios e filtros
6. ✅ Configurar API de IA
7. ✅ Gerar primeiro relatório PDF
8. ✅ Configurar backup automático
