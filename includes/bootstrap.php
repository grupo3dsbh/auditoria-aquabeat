<?php
/**
 * Bootstrap - Inicialização do Sistema
 */

// Prevenir acesso direto
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Carregar configurações
$configFile = APP_ROOT . '/config/config.php';

if (!file_exists($configFile)) {
    // Redirecionar para instalação
    if (basename($_SERVER['PHP_SELF']) !== 'install.php') {
        header('Location: install.php');
        exit;
    }
    return;
}

require_once $configFile;

// Configurar timezone
date_default_timezone_set(TIMEZONE);

// Configurar exibição de erros
if (DEBUG_MODE) {
    error_reporting(ERROR_REPORTING_LEVEL ?? E_ALL);
    ini_set('display_errors', DISPLAY_ERRORS ? '1' : '0');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Carregar classes
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AIService.php';
require_once __DIR__ . '/CSVImporter.php';

// Inicializar sessão e autenticação
Auth::init();

// Funções auxiliares
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function jsonResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function formatCurrency($value) {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function formatPercentage($value, $decimals = 2) {
    return number_format($value, $decimals, ',', '.') . '%';
}

function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) return '-';
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = 'd/m/Y H:i') {
    if (empty($datetime)) return '-';
    return date($format, strtotime($datetime));
}

function sanitize($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function getStatusBadgeClass($status) {
    $classes = [
        'Ativo' => 'success',
        'Bloqueado' => 'warning',
        'Cancelado' => 'danger',
        'Vencido' => 'danger',
        'Pendente' => 'secondary'
    ];

    return $classes[$status] ?? 'secondary';
}

function getRiskBadgeClass($risco) {
    $classes = [
        'BAIXO' => 'success',
        'BAIXO RISCO' => 'success',
        'MÉDIO' => 'warning',
        'MÉDIO RISCO' => 'warning',
        'ALTO' => 'danger',
        'ALTO RISCO' => 'danger',
        'CRÍTICO' => 'danger',
        'MUITO CRÍTICO' => 'danger',
        'FRAUDE PROVÁVEL' => 'dark'
    ];

    return $classes[$risco] ?? 'secondary';
}

function getConfig($key, $default = null) {
    static $cache = [];

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $db = Database::getInstance();
        $value = $db->fetchColumn(
            "SELECT valor FROM config_sistema WHERE chave = ?",
            [$key]
        );

        $cache[$key] = $value !== false ? $value : $default;
        return $cache[$key];

    } catch (Exception $e) {
        return $default;
    }
}

function setConfig($key, $value, $descricao = null, $tipo = 'texto') {
    try {
        $db = Database::getInstance();

        $exists = $db->exists('config_sistema', 'chave = ?', [$key]);

        if ($exists) {
            $db->update('config_sistema', ['valor' => $value], 'chave = ?', [$key]);
        } else {
            $db->insert('config_sistema', [
                'chave' => $key,
                'valor' => $value,
                'descricao' => $descricao,
                'tipo' => $tipo
            ]);
        }

        return true;

    } catch (Exception $e) {
        Logger::error("Failed to set config: " . $e->getMessage());
        return false;
    }
}

function requireAuth($redirectTo = 'login.php') {
    if (!Auth::isLoggedIn()) {
        redirect($redirectTo);
    }
}

function requireAdmin($redirectTo = 'index.php') {
    requireAuth();

    if (!Auth::isAdmin()) {
        $_SESSION['error'] = 'Acesso negado. Apenas administradores podem acessar esta página.';
        redirect($redirectTo);
    }
}

function requirePermission($permission, $redirectTo = 'index.php') {
    requireAuth();

    if (!Auth::hasPermission($permission)) {
        $_SESSION['error'] = 'Você não tem permissão para acessar esta funcionalidade.';
        redirect($redirectTo);
    }
}

function getFlashMessage($type) {
    if (isset($_SESSION[$type])) {
        $message = $_SESSION[$type];
        unset($_SESSION[$type]);
        return $message;
    }
    return null;
}

function setFlashMessage($type, $message) {
    $_SESSION[$type] = $message;
}

function uploadFile($file, $allowedExtensions = null) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erro no upload do arquivo.');
    }

    if ($file['size'] > UPLOAD_MAX_SIZE) {
        throw new Exception('Arquivo muito grande. Tamanho máximo: ' . (UPLOAD_MAX_SIZE / 1024 / 1024) . 'MB');
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowed = $allowedExtensions ?? ALLOWED_EXTENSIONS;

    if (!in_array($extension, $allowed)) {
        throw new Exception('Tipo de arquivo não permitido. Extensões permitidas: ' . implode(', ', $allowed));
    }

    // Criar diretório de upload se não existir
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    // Gerar nome único para o arquivo
    $newName = uniqid() . '_' . time() . '.' . $extension;
    $destination = UPLOAD_DIR . $newName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception('Erro ao salvar arquivo.');
    }

    return [
        'filename' => $newName,
        'path' => $destination,
        'original_name' => $file['name'],
        'size' => $file['size'],
        'extension' => $extension
    ];
}

function deleteFile($path) {
    if (file_exists($path) && is_file($path)) {
        return unlink($path);
    }
    return false;
}

function pagination($total, $perPage, $currentPage, $url) {
    $totalPages = ceil($total / $perPage);

    if ($totalPages <= 1) {
        return '';
    }

    $html = '<nav aria-label="Paginação"><ul class="pagination justify-content-center">';

    // Anterior
    if ($currentPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '&page=' . ($currentPage - 1) . '">Anterior</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Anterior</span></li>';
    }

    // Páginas
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '&page=1">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        if ($i == $currentPage) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $url . '&page=' . $i . '">' . $i . '</a></li>';
        }
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '&page=' . $totalPages . '">' . $totalPages . '</a></li>';
    }

    // Próxima
    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '&page=' . ($currentPage + 1) . '">Próxima</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Próxima</span></li>';
    }

    $html .= '</ul></nav>';

    return $html;
}

// Log de inicialização
if (Auth::isLoggedIn()) {
    Logger::debug("User session active", ['user_id' => Auth::userId()]);
}
