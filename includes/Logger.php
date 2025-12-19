<?php
/**
 * Classe de Gerenciamento de Logs
 */

class Logger {
    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';
    const CRITICAL = 'CRITICAL';

    private static $levels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];

    public static function log($level, $message, $context = []) {
        // Verificar se o nível de log está habilitado
        if (!defined('LOG_LEVEL') || !isset(self::$levels[LOG_LEVEL]) || !isset(self::$levels[$level])) {
            return;
        }

        if (self::$levels[$level] < self::$levels[LOG_LEVEL]) {
            return;
        }

        // Formatar mensagem
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}";

        if (!empty($context)) {
            $logMessage .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $logMessage .= PHP_EOL;

        // Definir arquivo de log
        $logFile = self::getLogFile($level);

        // Criar diretório se não existir
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Escrever log
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

        // Rotacionar logs antigos
        self::rotateLogs();
    }

    public static function debug($message, $context = []) {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info($message, $context = []) {
        self::log(self::INFO, $message, $context);
    }

    public static function warning($message, $context = []) {
        self::log(self::WARNING, $message, $context);
    }

    public static function error($message, $context = []) {
        self::log(self::ERROR, $message, $context);
    }

    public static function critical($message, $context = []) {
        self::log(self::CRITICAL, $message, $context);
    }

    private static function getLogFile($level) {
        $logDir = defined('LOG_DIR') ? LOG_DIR : __DIR__ . '/../logs/';
        $date = date('Y-m-d');

        // Logs críticos e de erro vão para arquivo separado
        if (in_array($level, [self::ERROR, self::CRITICAL])) {
            return $logDir . "error-{$date}.log";
        }

        return $logDir . "app-{$date}.log";
    }

    private static function rotateLogs() {
        if (!defined('LOG_RETENTION_DAYS') || !defined('LOG_DIR')) {
            return;
        }

        $logDir = LOG_DIR;
        $retentionDays = (int)LOG_RETENTION_DAYS;

        if ($retentionDays <= 0 || !is_dir($logDir)) {
            return;
        }

        $cutoffDate = strtotime("-{$retentionDays} days");

        $files = glob($logDir . '*.log');
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffDate) {
                unlink($file);
            }
        }
    }

    /**
     * Registrar ação do usuário no banco de dados
     */
    public static function logAction($userId, $action, $description, $table = null, $recordId = null, $oldData = null, $newData = null) {
        try {
            $db = Database::getInstance();

            $data = [
                'usuario_id' => $userId,
                'acao' => $action,
                'descricao' => $description,
                'tabela' => $table,
                'registro_id' => $recordId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'dados_anteriores' => $oldData ? json_encode($oldData) : null,
                'dados_novos' => $newData ? json_encode($newData) : null
            ];

            $db->insert('log_acoes', $data);

        } catch (Exception $e) {
            self::error('Failed to log action: ' . $e->getMessage());
        }
    }

    /**
     * Obter logs de ações do banco de dados
     */
    public static function getActionLogs($filters = [], $limit = 100, $offset = 0) {
        try {
            $db = Database::getInstance();

            $where = ['1=1'];
            $params = [];

            if (!empty($filters['usuario_id'])) {
                $where[] = 'usuario_id = ?';
                $params[] = $filters['usuario_id'];
            }

            if (!empty($filters['acao'])) {
                $where[] = 'acao = ?';
                $params[] = $filters['acao'];
            }

            if (!empty($filters['tabela'])) {
                $where[] = 'tabela = ?';
                $params[] = $filters['tabela'];
            }

            if (!empty($filters['data_inicio'])) {
                $where[] = 'criado_em >= ?';
                $params[] = $filters['data_inicio'];
            }

            if (!empty($filters['data_fim'])) {
                $where[] = 'criado_em <= ?';
                $params[] = $filters['data_fim'];
            }

            $sql = "SELECT la.*, u.nome as usuario_nome, u.email as usuario_email
                    FROM log_acoes la
                    LEFT JOIN usuarios u ON la.usuario_id = u.id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY la.criado_em DESC
                    LIMIT ? OFFSET ?";

            $params[] = $limit;
            $params[] = $offset;

            return $db->fetchAll($sql, $params);

        } catch (Exception $e) {
            self::error('Failed to get action logs: ' . $e->getMessage());
            return [];
        }
    }
}
