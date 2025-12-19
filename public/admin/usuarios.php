<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

$db = Database::getInstance();

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    Auth::createUser($_POST);
                    setFlashMessage('success', 'Usuário criado com sucesso!');
                    break;

                case 'update':
                    Auth::updateUser($_POST['id'], $_POST);
                    setFlashMessage('success', 'Usuário atualizado com sucesso!');
                    break;

                case 'delete':
                    Auth::deleteUser($_POST['id']);
                    setFlashMessage('success', 'Usuário deletado com sucesso!');
                    break;
            }
        }
        redirect('usuarios.php');
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$usuarios = Auth::getAllUsers();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gerenciar Usuários - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../navbar.php'; ?>

    <div class="container mt-4">
        <h2><i class="bi bi-people"></i> Gerenciar Usuários</h2>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo sanitize($error); ?></div>
        <?php endif; ?>

        <?php if ($success = getFlashMessage('success')): ?>
            <div class="alert alert-success"><?php echo sanitize($success); ?></div>
        <?php endif; ?>

        <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#modalNovoUsuario">
            <i class="bi bi-plus-circle"></i> Novo Usuário
        </button>

        <div class="card">
            <div class="card-body">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <th>Nível</th>
                            <th>Status</th>
                            <th>Último Login</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $usuario): ?>
                            <tr>
                                <td><?php echo sanitize($usuario['nome']); ?></td>
                                <td><?php echo sanitize($usuario['email']); ?></td>
                                <td><span class="badge bg-primary"><?php echo ucfirst($usuario['nivel_acesso']); ?></span></td>
                                <td>
                                    <span class="badge bg-<?php echo $usuario['ativo'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $usuario['ativo'] ? 'Ativo' : 'Inativo'; ?>
                                    </span>
                                </td>
                                <td><?php echo formatDateTime($usuario['ultimo_login']); ?></td>
                                <td>
                                    <?php if ($usuario['id'] != Auth::userId()): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Confirma exclusão?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $usuario['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Novo Usuário -->
    <div class="modal fade" id="modalNovoUsuario" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Novo Usuário</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">

                        <div class="mb-3">
                            <label class="form-label">Nome *</label>
                            <input type="text" name="nome" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">E-mail *</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Senha *</label>
                            <input type="password" name="senha" class="form-control" minlength="8" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nível de Acesso *</label>
                            <select name="nivel_acesso" class="form-select" required>
                                <option value="visualizador">Visualizador</option>
                                <option value="analista">Analista</option>
                                <option value="gerente">Gerente</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Criar Usuário</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
