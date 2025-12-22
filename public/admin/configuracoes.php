<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach ($_POST as $key => $value) {
            if ($key !== 'submit') {
                setConfig($key, $value);
            }
        }

        Logger::logAction(Auth::userId(), 'update_config', 'Atualizou configurações do sistema');

        setFlashMessage('success', 'Configurações salvas com sucesso!');
        redirect('configuracoes.php');

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$configs = [
    'nome_empresa' => getConfig('nome_empresa', 'Aquabeat Auditoria'),
    'api_ia_provider' => getConfig('api_ia_provider', 'groq'),
    'api_ia_key' => getConfig('api_ia_key', ''),
    'registros_por_pagina' => getConfig('registros_por_pagina', '50'),
    'modo_debug' => getConfig('modo_debug', '0')
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Configurações - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../navbar.php'; ?>

    <div class="container mt-4">
        <h2><i class="bi bi-sliders"></i> Configurações do Sistema</h2>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <?php if ($success = getFlashMessage('success')): ?>
            <div class="alert alert-success"><?php echo sanitize($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <h5>Empresa</h5>

                    <div class="mb-3">
                        <label class="form-label">Nome da Empresa</label>
                        <input type="text" name="nome_empresa" class="form-control" value="<?php echo sanitize($configs['nome_empresa']); ?>">
                    </div>

                    <hr>

                    <h5>Inteligência Artificial</h5>

                    <div class="mb-3">
                        <label class="form-label">Provedor de IA</label>
                        <select name="api_ia_provider" class="form-select">
                            <option value="groq" <?php echo $configs['api_ia_provider'] == 'groq' ? 'selected' : ''; ?>>Groq (Gratuito)</option>
                            <option value="openai" <?php echo $configs['api_ia_provider'] == 'openai' ? 'selected' : ''; ?>>OpenAI (Pago)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Chave da API</label>
                        <input type="text" name="api_ia_key" class="form-control" value="<?php echo sanitize($configs['api_ia_key']); ?>">
                        <small class="text-muted">
                            Groq: <a href="https://console.groq.com" target="_blank">console.groq.com</a> |
                            OpenAI: <a href="https://platform.openai.com" target="_blank">platform.openai.com</a>
                        </small>
                    </div>

                    <hr>

                    <h5>Sistema</h5>

                    <div class="mb-3">
                        <label class="form-label">Registros por Página</label>
                        <input type="number" name="registros_por_pagina" class="form-control" value="<?php echo $configs['registros_por_pagina']; ?>">
                    </div>

                    <hr>

                    <h5>Desenvolvimento</h5>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input type="hidden" name="modo_debug" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="modoDebug" name="modo_debug" value="1" <?php echo $configs['modo_debug'] == '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="modoDebug">
                                <strong>Modo de Depuração</strong>
                            </label>
                        </div>
                        <small class="text-muted">
                            <i class="bi bi-exclamation-triangle text-warning"></i>
                            Quando ativado, exibe erros PHP em todas as páginas e mostra uma tarja de desenvolvimento.
                            <strong class="text-danger">NÃO use em produção!</strong>
                        </small>
                    </div>

                    <button type="submit" name="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Salvar Configurações
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
