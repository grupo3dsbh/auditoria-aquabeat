<?php
/**
 * Arquivo de Configuração do Sistema de Auditoria
 *
 * Renomeie este arquivo para config.php e preencha com suas configurações
 */

// Configurações do Banco de Dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'auditoria_aquabeat');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Configurações do Sistema
define('SITE_URL', 'http://localhost/auditoria-system');
define('SITE_NAME', 'Sistema de Auditoria - Aquabeat');
define('TIMEZONE', 'America/Sao_Paulo');

// Configurações de Sessão
define('SESSION_NAME', 'AUDITORIA_SESSION');
define('SESSION_LIFETIME', 7200); // 2 horas em segundos

// Configurações de Segurança
define('SALT_KEY', 'mude-esta-chave-por-uma-aleatoria-e-segura');
define('HASH_ALGORITHM', 'sha256');
define('PASSWORD_MIN_LENGTH', 8);

// Configurações de Upload
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_MAX_SIZE', 50 * 1024 * 1024); // 50MB em bytes
define('ALLOWED_EXTENSIONS', ['csv', 'xlsx', 'xls']);

// Configurações de Log
define('LOG_DIR', __DIR__ . '/../logs/');
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR, CRITICAL
define('LOG_RETENTION_DAYS', 90);

// Configurações de API IA
define('AI_PROVIDER', 'groq'); // groq ou openai
define('AI_API_KEY', ''); // Preencher com sua chave
define('AI_MODEL_GROQ', 'llama-3.3-70b-versatile'); // Atualizado: 3.1 foi descontinuado
define('AI_MODEL_OPENAI', 'gpt-4-turbo-preview');
define('AI_MAX_TOKENS', 4000);
define('AI_TEMPERATURE', 0.7);

// Configurações de E-mail
define('SMTP_ENABLED', false);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_FROM', '');
define('SMTP_FROM_NAME', 'Sistema de Auditoria');
define('SMTP_ENCRYPTION', 'tls'); // tls ou ssl

// Configurações de Debug
define('DEBUG_MODE', false);
define('DISPLAY_ERRORS', false);
define('ERROR_REPORTING_LEVEL', E_ALL);

// Configurações de Paginação
define('RECORDS_PER_PAGE', 50);
define('MAX_RECORDS_PER_PAGE', 500);

// Configurações de Cache
define('CACHE_ENABLED', true);
define('CACHE_LIFETIME', 3600); // 1 hora em segundos

// Versão do Sistema
define('SYSTEM_VERSION', '1.0.0');
