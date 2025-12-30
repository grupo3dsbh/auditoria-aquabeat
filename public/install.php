<?php
/**
 * Instalador do Sistema de Auditoria
 */

session_start();

// Verificar se já está instalado
if (file_exists(__DIR__ . '/../config/config.php')) {
    $config = include __DIR__ . '/../config/config.php';
    if (defined('DB_HOST') && !isset($_GET['reinstall'])) {
        header('Location: index.php');
        exit;
    }
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors = [];
$success = [];

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1) {
        // Validar dados do banco
        $db_host = $_POST['db_host'] ?? '';
        $db_name = $_POST['db_name'] ?? '';
        $db_user = $_POST['db_user'] ?? '';
        $db_pass = $_POST['db_pass'] ?? '';

        if (empty($db_host) || empty($db_name) || empty($db_user)) {
            $errors[] = 'Preencha todos os campos obrigatórios do banco de dados.';
        }

        if (empty($errors)) {
            // Testar conexão
            try {
                $dsn = "mysql:host={$db_host};charset=utf8mb4";
                $pdo = new PDO($dsn, $db_user, $db_pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                // Criar banco se não existir
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$db_name}`");

                // Salvar na sessão
                $_SESSION['install'] = [
                    'db_host' => $db_host,
                    'db_name' => $db_name,
                    'db_user' => $db_user,
                    'db_pass' => $db_pass
                ];

                $success[] = 'Conexão com banco de dados estabelecida com sucesso!';
                header('Location: install.php?step=2');
                exit;

            } catch (PDOException $e) {
                $errors[] = 'Erro ao conectar ao banco: ' . $e->getMessage();
            }
        }

    } elseif ($step === 2) {
        // Executar SQL
        if (!isset($_SESSION['install'])) {
            header('Location: install.php?step=1');
            exit;
        }

        $install = $_SESSION['install'];

        try {
            $dsn = "mysql:host={$install['db_host']};dbname={$install['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $install['db_user'], $install['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Ler e executar schema
            $schema = file_get_contents(__DIR__ . '/../database/schema.sql');

            // Remover comandos SQL Server específicos e ajustar para MySQL
            $schema = str_replace(['USE [MultiClubes]', 'GO'], '', $schema);

            // Executar cada comando SQL separadamente
            $statements = array_filter(
                array_map('trim', explode(';', $schema)),
                function($stmt) {
                    return !empty($stmt) && !preg_match('/^--/', $stmt);
                }
            );

            foreach ($statements as $statement) {
                if (!empty(trim($statement))) {
                    $pdo->exec($statement);
                }
            }

            $success[] = 'Banco de dados criado com sucesso!';
            header('Location: install.php?step=3');
            exit;

        } catch (PDOException $e) {
            $errors[] = 'Erro ao criar estrutura do banco: ' . $e->getMessage();
        }

    } elseif ($step === 3) {
        // Configurar administrador e sistema
        if (!isset($_SESSION['install'])) {
            header('Location: install.php?step=1');
            exit;
        }

        $nome_empresa = $_POST['nome_empresa'] ?? 'Aquabeat Auditoria';
        $site_url = $_POST['site_url'] ?? 'http://localhost';
        $admin_nome = $_POST['admin_nome'] ?? '';
        $admin_email = $_POST['admin_email'] ?? '';
        $admin_senha = $_POST['admin_senha'] ?? '';
        $admin_senha_confirm = $_POST['admin_senha_confirm'] ?? '';
        $ai_provider = $_POST['ai_provider'] ?? 'groq';
        $ai_key = $_POST['ai_key'] ?? '';

        // Validações
        if (empty($admin_nome) || empty($admin_email) || empty($admin_senha)) {
            $errors[] = 'Preencha todos os campos do administrador.';
        }

        if ($admin_senha !== $admin_senha_confirm) {
            $errors[] = 'As senhas não coincidem.';
        }

        if (strlen($admin_senha) < 8) {
            $errors[] = 'A senha deve ter no mínimo 8 caracteres.';
        }

        if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-mail inválido.';
        }

        if (empty($errors)) {
            $install = $_SESSION['install'];

            try {
                $dsn = "mysql:host={$install['db_host']};dbname={$install['db_name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $install['db_user'], $install['db_pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                // Atualizar configurações
                $stmt = $pdo->prepare("UPDATE config_sistema SET valor = ? WHERE chave = ?");
                $stmt->execute([$nome_empresa, 'nome_empresa']);
                $stmt->execute([$ai_provider, 'api_ia_provider']);
                $stmt->execute([$ai_key, 'api_ia_key']);

                // Criar admin
                $senha_hash = password_hash($admin_senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("DELETE FROM usuarios WHERE email = ?");
                $stmt->execute([$admin_email]);

                $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, nivel_acesso, ativo) VALUES (?, ?, ?, 'admin', TRUE)");
                $stmt->execute([$admin_nome, $admin_email, $senha_hash]);

                // Criar arquivo de configuração
                $config_content = "<?php\n\n";
                $config_content .= "// Configurações do Banco de Dados\n";
                $config_content .= "define('DB_HOST', '{$install['db_host']}');\n";
                $config_content .= "define('DB_NAME', '{$install['db_name']}');\n";
                $config_content .= "define('DB_USER', '{$install['db_user']}');\n";
                $config_content .= "define('DB_PASS', '" . addslashes($install['db_pass']) . "');\n";
                $config_content .= "define('DB_CHARSET', 'utf8mb4');\n\n";

                $config_content .= "// Configurações do Sistema\n";
                $config_content .= "define('SITE_URL', '{$site_url}');\n";
                $config_content .= "define('SITE_NAME', '{$nome_empresa}');\n";
                $config_content .= "define('TIMEZONE', 'America/Sao_Paulo');\n\n";

                $config_content .= "// Configurações de Sessão\n";
                $config_content .= "define('SESSION_NAME', 'AUDITORIA_SESSION');\n";
                $config_content .= "define('SESSION_LIFETIME', 7200);\n\n";

                $config_content .= "// Configurações de Segurança\n";
                $salt = bin2hex(random_bytes(32));
                $config_content .= "define('SALT_KEY', '{$salt}');\n";
                $config_content .= "define('HASH_ALGORITHM', 'sha256');\n";
                $config_content .= "define('PASSWORD_MIN_LENGTH', 8);\n\n";

                $config_content .= "// Configurações de Upload\n";
                $config_content .= "define('UPLOAD_DIR', __DIR__ . '/../uploads/');\n";
                $config_content .= "define('UPLOAD_MAX_SIZE', 50 * 1024 * 1024);\n";
                $config_content .= "define('ALLOWED_EXTENSIONS', ['csv', 'xlsx', 'xls']);\n\n";

                $config_content .= "// Configurações de Log\n";
                $config_content .= "define('LOG_DIR', __DIR__ . '/../logs/');\n";
                $config_content .= "define('LOG_LEVEL', 'INFO');\n";
                $config_content .= "define('LOG_RETENTION_DAYS', 90);\n\n";

                $config_content .= "// Configurações de API IA\n";
                $config_content .= "define('AI_PROVIDER', '{$ai_provider}');\n";
                $config_content .= "define('AI_API_KEY', '" . addslashes($ai_key) . "');\n";
                $config_content .= "define('AI_MODEL_GROQ', 'llama-3.3-70b-versatile');\n";
                $config_content .= "define('AI_MODEL_OPENAI', 'gpt-4-turbo-preview');\n";
                $config_content .= "define('AI_MAX_TOKENS', 4000);\n";
                $config_content .= "define('AI_TEMPERATURE', 0.7);\n\n";

                $config_content .= "// Configurações de Debug\n";
                $config_content .= "define('DEBUG_MODE', false);\n";
                $config_content .= "define('DISPLAY_ERRORS', false);\n\n";

                $config_content .= "// Configurações de Paginação\n";
                $config_content .= "define('RECORDS_PER_PAGE', 50);\n\n";

                $config_content .= "// Versão do Sistema\n";
                $config_content .= "define('SYSTEM_VERSION', '1.0.0');\n";

                file_put_contents(__DIR__ . '/../config/config.php', $config_content);

                // Limpar sessão de instalação
                unset($_SESSION['install']);

                $success[] = 'Sistema instalado com sucesso!';
                header('Location: install.php?step=4');
                exit;

            } catch (Exception $e) {
                $errors[] = 'Erro ao configurar sistema: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - Sistema de Auditoria</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            max-width: 600px;
            width: 100%;
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 14px;
        }

        .progress {
            display: flex;
            background: #f5f5f5;
            padding: 20px 30px;
        }

        .progress-step {
            flex: 1;
            text-align: center;
            position: relative;
            padding: 10px;
        }

        .progress-step::before {
            content: attr(data-step);
            display: block;
            width: 40px;
            height: 40px;
            background: white;
            border: 2px solid #ddd;
            border-radius: 50%;
            margin: 0 auto 10px;
            line-height: 36px;
            font-weight: bold;
            color: #999;
        }

        .progress-step.active::before {
            background: #667eea;
            border-color: #667eea;
            color: white;
        }

        .progress-step.completed::before {
            background: #10b981;
            border-color: #10b981;
            color: white;
            content: '✓';
        }

        .progress-step span {
            font-size: 12px;
            color: #666;
        }

        .progress-step.active span {
            color: #667eea;
            font-weight: bold;
        }

        .content {
            padding: 30px;
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fee;
            color: #c00;
            border-left: 4px solid #c00;
        }

        .alert-success {
            background: #efe;
            color: #0a0;
            border-left: 4px solid #0a0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-group small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 12px;
        }

        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #5568d3;
        }

        .btn-block {
            display: block;
            width: 100%;
        }

        .text-center {
            text-align: center;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            background: #10b981;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            margin: 0 auto 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Sistema de Auditoria - Aquabeat</h1>
            <p>Instalação e Configuração Inicial</p>
        </div>

        <div class="progress">
            <div class="progress-step <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>" data-step="1">
                <span>Banco de Dados</span>
            </div>
            <div class="progress-step <?php echo $step >= 2 ? ($step > 2 ? 'completed' : 'active') : ''; ?>" data-step="2">
                <span>Estrutura</span>
            </div>
            <div class="progress-step <?php echo $step >= 3 ? ($step > 3 ? 'completed' : 'active') : ''; ?>" data-step="3">
                <span>Configuração</span>
            </div>
            <div class="progress-step <?php echo $step >= 4 ? 'active' : ''; ?>" data-step="4">
                <span>Concluído</span>
            </div>
        </div>

        <div class="content">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success[0]); ?>
                </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <h2>Etapa 1: Configuração do Banco de Dados</h2>
                <p>Informe os dados de conexão com o banco de dados MySQL.</p>
                <br>

                <form method="POST">
                    <div class="form-group">
                        <label for="db_host">Servidor do Banco *</label>
                        <input type="text" id="db_host" name="db_host" value="localhost" required>
                        <small>Geralmente "localhost"</small>
                    </div>

                    <div class="form-group">
                        <label for="db_name">Nome do Banco *</label>
                        <input type="text" id="db_name" name="db_name" value="auditoria_aquabeat" required>
                        <small>O banco será criado automaticamente se não existir</small>
                    </div>

                    <div class="form-group">
                        <label for="db_user">Usuário *</label>
                        <input type="text" id="db_user" name="db_user" value="root" required>
                    </div>

                    <div class="form-group">
                        <label for="db_pass">Senha</label>
                        <input type="password" id="db_pass" name="db_pass" value="">
                    </div>

                    <button type="submit" class="btn btn-block">Próximo</button>
                </form>

            <?php elseif ($step === 2): ?>
                <h2>Etapa 2: Criar Estrutura do Banco</h2>
                <p>Clique em "Criar Estrutura" para criar todas as tabelas necessárias.</p>
                <br>

                <form method="POST">
                    <button type="submit" class="btn btn-block">Criar Estrutura</button>
                </form>

            <?php elseif ($step === 3): ?>
                <h2>Etapa 3: Configurações Finais</h2>
                <p>Configure o sistema e crie o usuário administrador.</p>
                <br>

                <form method="POST">
                    <h3>Empresa</h3>
                    <div class="form-group">
                        <label for="nome_empresa">Nome da Empresa *</label>
                        <input type="text" id="nome_empresa" name="nome_empresa" value="Aquabeat Auditoria" required>
                    </div>

                    <div class="form-group">
                        <label for="site_url">URL do Site *</label>
                        <input type="url" id="site_url" name="site_url" value="<?php echo 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']); ?>" required>
                    </div>

                    <hr style="margin: 30px 0;">

                    <h3>Administrador</h3>
                    <div class="form-group">
                        <label for="admin_nome">Nome Completo *</label>
                        <input type="text" id="admin_nome" name="admin_nome" required>
                    </div>

                    <div class="form-group">
                        <label for="admin_email">E-mail *</label>
                        <input type="email" id="admin_email" name="admin_email" required>
                    </div>

                    <div class="form-group">
                        <label for="admin_senha">Senha *</label>
                        <input type="password" id="admin_senha" name="admin_senha" required>
                        <small>Mínimo 8 caracteres</small>
                    </div>

                    <div class="form-group">
                        <label for="admin_senha_confirm">Confirmar Senha *</label>
                        <input type="password" id="admin_senha_confirm" name="admin_senha_confirm" required>
                    </div>

                    <hr style="margin: 30px 0;">

                    <h3>Inteligência Artificial (Opcional)</h3>
                    <div class="form-group">
                        <label for="ai_provider">Provedor de IA</label>
                        <select id="ai_provider" name="ai_provider">
                            <option value="groq">Groq (Recomendado - Gratuito)</option>
                            <option value="openai">OpenAI (Pago)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="ai_key">Chave da API</label>
                        <input type="text" id="ai_key" name="ai_key">
                        <small>Obtenha em: <a href="https://console.groq.com" target="_blank">console.groq.com</a> ou <a href="https://platform.openai.com" target="_blank">platform.openai.com</a></small>
                    </div>

                    <button type="submit" class="btn btn-block">Concluir Instalação</button>
                </form>

            <?php elseif ($step === 4): ?>
                <div class="text-center">
                    <div class="success-icon">✓</div>
                    <h2>Instalação Concluída!</h2>
                    <p>O sistema foi instalado com sucesso.</p>
                    <br>
                    <a href="index.php" class="btn">Acessar Sistema</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
