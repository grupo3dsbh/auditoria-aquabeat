<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

$user = Auth::user();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $dados = [
            'nome' => $_POST['nome'] ?? $user['nome']
        ];

        if (!empty($_POST['senha'])) {
            if ($_POST['senha'] !== $_POST['senha_confirm']) {
                throw new Exception('As senhas não coincidem.');
            }
            $dados['senha'] = $_POST['senha'];
        }

        Auth::updateUser($user['id'], $dados);

        setFlashMessage('success', 'Perfil atualizado com sucesso!');
        redirect('perfil.php');

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$error = $error ?? getFlashMessage('error');
$success = getFlashMessage('success');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-6 offset-md-3">
                <h2><i class="bi bi-person-circle"></i> Meu Perfil</h2>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo sanitize($error); ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo sanitize($success); ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Nome</label>
                                <input type="text" name="nome" class="form-control" value="<?php echo sanitize($user['nome']); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">E-mail</label>
                                <input type="email" class="form-control" value="<?php echo sanitize($user['email']); ?>" disabled>
                                <small class="text-muted">O e-mail não pode ser alterado</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Nível de Acesso</label>
                                <input type="text" class="form-control" value="<?php echo ucfirst($user['nivel_acesso']); ?>" disabled>
                            </div>

                            <hr>

                            <h5>Alterar Senha</h5>

                            <div class="mb-3">
                                <label class="form-label">Nova Senha</label>
                                <input type="password" name="senha" class="form-control" minlength="8">
                                <small class="text-muted">Deixe em branco para não alterar</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Confirmar Senha</label>
                                <input type="password" name="senha_confirm" class="form-control" minlength="8">
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Salvar Alterações
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Voltar
                            </a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
