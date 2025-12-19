<?php
/**
 * Classe de Autenticação e Autorização
 */

class Auth {
    private static $user = null;
    private static $permissions = null;

    /**
     * Inicializar sessão
     */
    public static function init() {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path' => '/',
                'domain' => '',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }

        // Verificar timeout de sessão
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
            self::logout();
            return;
        }

        $_SESSION['last_activity'] = time();

        // Carregar usuário se estiver logado
        if (isset($_SESSION['user_id'])) {
            self::loadUser($_SESSION['user_id']);
        }
    }

    /**
     * Fazer login
     */
    public static function login($email, $password, $remember = false) {
        $db = Database::getInstance();

        $user = $db->fetchOne(
            "SELECT * FROM usuarios WHERE email = ? AND ativo = TRUE",
            [$email]
        );

        if (!$user) {
            Logger::warning("Login failed: user not found", ['email' => $email]);
            return false;
        }

        if (!password_verify($password, $user['senha_hash'])) {
            Logger::warning("Login failed: invalid password", ['email' => $email]);
            return false;
        }

        // Atualizar último login
        $db->update(
            'usuarios',
            ['ultimo_login' => date('Y-m-d H:i:s')],
            'id = ?',
            [$user['id']]
        );

        // Criar sessão
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_nivel'] = $user['nivel_acesso'];
        $_SESSION['last_activity'] = time();

        // Regenerar ID da sessão por segurança
        session_regenerate_id(true);

        self::$user = $user;

        Logger::info("User logged in", ['user_id' => $user['id'], 'email' => $email]);
        Logger::logAction($user['id'], 'login', 'Usuário fez login no sistema');

        return true;
    }

    /**
     * Fazer logout
     */
    public static function logout() {
        if (self::isLoggedIn()) {
            $userId = $_SESSION['user_id'];
            Logger::info("User logged out", ['user_id' => $userId]);
            Logger::logAction($userId, 'logout', 'Usuário saiu do sistema');
        }

        $_SESSION = [];

        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }

        session_destroy();

        self::$user = null;
        self::$permissions = null;
    }

    /**
     * Verificar se está logado
     */
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && self::$user !== null;
    }

    /**
     * Obter usuário atual
     */
    public static function user() {
        return self::$user;
    }

    /**
     * Obter ID do usuário atual
     */
    public static function userId() {
        return self::$user['id'] ?? null;
    }

    /**
     * Verificar se o usuário tem um nível de acesso específico
     */
    public static function hasRole($role) {
        if (!self::isLoggedIn()) {
            return false;
        }

        $hierarchy = [
            'visualizador' => 1,
            'analista' => 2,
            'gerente' => 3,
            'admin' => 4
        ];

        $userLevel = $hierarchy[self::$user['nivel_acesso']] ?? 0;
        $requiredLevel = $hierarchy[$role] ?? 999;

        return $userLevel >= $requiredLevel;
    }

    /**
     * Verificar se é admin
     */
    public static function isAdmin() {
        return self::hasRole('admin');
    }

    /**
     * Verificar se tem uma permissão específica
     */
    public static function hasPermission($permission) {
        if (!self::isLoggedIn()) {
            return false;
        }

        // Admin tem todas as permissões
        if (self::isAdmin()) {
            return true;
        }

        // Carregar permissões se ainda não carregadas
        if (self::$permissions === null) {
            self::loadPermissions();
        }

        return in_array($permission, self::$permissions);
    }

    /**
     * Verificar se tem qualquer uma das permissões
     */
    public static function hasAnyPermission($permissions) {
        foreach ($permissions as $permission) {
            if (self::hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verificar se tem todas as permissões
     */
    public static function hasAllPermissions($permissions) {
        foreach ($permissions as $permission) {
            if (!self::hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Carregar usuário
     */
    private static function loadUser($userId) {
        $db = Database::getInstance();

        $user = $db->fetchOne(
            "SELECT * FROM usuarios WHERE id = ? AND ativo = TRUE",
            [$userId]
        );

        if ($user) {
            self::$user = $user;
        } else {
            self::logout();
        }
    }

    /**
     * Carregar permissões do usuário
     */
    private static function loadPermissions() {
        if (!self::isLoggedIn()) {
            self::$permissions = [];
            return;
        }

        $db = Database::getInstance();

        $permissions = $db->fetchAll(
            "SELECT permissao FROM permissoes_usuarios WHERE usuario_id = ? AND concedida = TRUE",
            [self::userId()]
        );

        self::$permissions = array_column($permissions, 'permissao');
    }

    /**
     * Criar novo usuário
     */
    public static function createUser($data) {
        $db = Database::getInstance();

        // Validações
        if (empty($data['nome']) || empty($data['email']) || empty($data['senha'])) {
            throw new Exception('Preencha todos os campos obrigatórios.');
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail inválido.');
        }

        if (strlen($data['senha']) < PASSWORD_MIN_LENGTH) {
            throw new Exception('A senha deve ter no mínimo ' . PASSWORD_MIN_LENGTH . ' caracteres.');
        }

        // Verificar se e-mail já existe
        if ($db->exists('usuarios', 'email = ?', [$data['email']])) {
            throw new Exception('Este e-mail já está cadastrado.');
        }

        // Hash da senha
        $senhaHash = password_hash($data['senha'], PASSWORD_DEFAULT);

        // Inserir usuário
        $userId = $db->insert('usuarios', [
            'nome' => $data['nome'],
            'email' => $data['email'],
            'senha_hash' => $senhaHash,
            'nivel_acesso' => $data['nivel_acesso'] ?? 'visualizador',
            'ativo' => $data['ativo'] ?? true,
            'criado_por' => self::userId()
        ]);

        Logger::info("User created", ['user_id' => $userId, 'email' => $data['email']]);
        Logger::logAction(self::userId(), 'create_user', 'Criou usuário: ' . $data['nome'], 'usuarios', $userId);

        return $userId;
    }

    /**
     * Atualizar usuário
     */
    public static function updateUser($userId, $data) {
        $db = Database::getInstance();

        // Obter dados antigos para log
        $oldData = $db->fetchOne("SELECT * FROM usuarios WHERE id = ?", [$userId]);

        if (!$oldData) {
            throw new Exception('Usuário não encontrado.');
        }

        $updateData = [];

        if (isset($data['nome'])) {
            $updateData['nome'] = $data['nome'];
        }

        if (isset($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('E-mail inválido.');
            }

            // Verificar se e-mail já existe para outro usuário
            if ($db->exists('usuarios', 'email = ? AND id != ?', [$data['email'], $userId])) {
                throw new Exception('Este e-mail já está cadastrado.');
            }

            $updateData['email'] = $data['email'];
        }

        if (isset($data['senha']) && !empty($data['senha'])) {
            if (strlen($data['senha']) < PASSWORD_MIN_LENGTH) {
                throw new Exception('A senha deve ter no mínimo ' . PASSWORD_MIN_LENGTH . ' caracteres.');
            }

            $updateData['senha_hash'] = password_hash($data['senha'], PASSWORD_DEFAULT);
        }

        if (isset($data['nivel_acesso'])) {
            $updateData['nivel_acesso'] = $data['nivel_acesso'];
        }

        if (isset($data['ativo'])) {
            $updateData['ativo'] = $data['ativo'];
        }

        if (empty($updateData)) {
            return true;
        }

        $db->update('usuarios', $updateData, 'id = ?', [$userId]);

        Logger::info("User updated", ['user_id' => $userId]);
        Logger::logAction(self::userId(), 'update_user', 'Atualizou usuário: ' . $oldData['nome'], 'usuarios', $userId, $oldData, $updateData);

        return true;
    }

    /**
     * Deletar usuário
     */
    public static function deleteUser($userId) {
        $db = Database::getInstance();

        $user = $db->fetchOne("SELECT * FROM usuarios WHERE id = ?", [$userId]);

        if (!$user) {
            throw new Exception('Usuário não encontrado.');
        }

        // Não permitir deletar o próprio usuário
        if ($userId == self::userId()) {
            throw new Exception('Você não pode deletar sua própria conta.');
        }

        // Não permitir deletar se for o único admin
        if ($user['nivel_acesso'] === 'admin') {
            $adminCount = $db->count('usuarios', "nivel_acesso = 'admin' AND ativo = TRUE");
            if ($adminCount <= 1) {
                throw new Exception('Não é possível deletar o único administrador do sistema.');
            }
        }

        $db->delete('usuarios', 'id = ?', [$userId]);

        Logger::info("User deleted", ['user_id' => $userId]);
        Logger::logAction(self::userId(), 'delete_user', 'Deletou usuário: ' . $user['nome'], 'usuarios', $userId);

        return true;
    }

    /**
     * Obter todos os usuários
     */
    public static function getAllUsers($filters = []) {
        $db = Database::getInstance();

        $where = ['1=1'];
        $params = [];

        if (isset($filters['ativo'])) {
            $where[] = 'ativo = ?';
            $params[] = $filters['ativo'];
        }

        if (isset($filters['nivel_acesso'])) {
            $where[] = 'nivel_acesso = ?';
            $params[] = $filters['nivel_acesso'];
        }

        if (isset($filters['search'])) {
            $where[] = '(nome LIKE ? OR email LIKE ?)';
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $sql = "SELECT id, nome, email, nivel_acesso, ativo, ultimo_login, criado_em
                FROM usuarios
                WHERE " . implode(' AND ', $where) . "
                ORDER BY nome ASC";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Adicionar permissão a um usuário
     */
    public static function grantPermission($userId, $permission) {
        $db = Database::getInstance();

        $db->query(
            "INSERT INTO permissoes_usuarios (usuario_id, permissao, concedida) VALUES (?, ?, TRUE)
             ON DUPLICATE KEY UPDATE concedida = TRUE",
            [$userId, $permission]
        );

        Logger::logAction(self::userId(), 'grant_permission', "Concedeu permissão '{$permission}' ao usuário", 'permissoes_usuarios', $userId);
    }

    /**
     * Revogar permissão de um usuário
     */
    public static function revokePermission($userId, $permission) {
        $db = Database::getInstance();

        $db->delete('permissoes_usuarios', 'usuario_id = ? AND permissao = ?', [$userId, $permission]);

        Logger::logAction(self::userId(), 'revoke_permission', "Revogou permissão '{$permission}' do usuário", 'permissoes_usuarios', $userId);
    }
}
