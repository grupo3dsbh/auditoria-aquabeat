<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('../login.php');
requirePermission('admin');

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'criar':
                // Gerar token único
                $token = bin2hex(random_bytes(32));
                $nome = $_POST['nome'] ?? '';
                $descricao = $_POST['descricao'] ?? '';
                $expiraDias = !empty($_POST['expira_dias']) ? (int)$_POST['expira_dias'] : null;
                $expiraEm = $expiraDias ? date('Y-m-d H:i:s', strtotime("+{$expiraDias} days")) : null;

                $db->insert('api_tokens', [
                    'usuario_id' => $userId,
                    'nome' => $nome,
                    'token' => $token,
                    'descricao' => $descricao,
                    'expira_em' => $expiraEm,
                    'ativo' => 1
                ]);

                setFlashMessage('success', 'Token criado com sucesso!');
                redirect('api_tokens.php');
                break;

            case 'revogar':
                $tokenId = (int)$_POST['token_id'];
                $db->update('api_tokens', ['ativo' => 0], 'id = ?', [$tokenId]);
                setFlashMessage('success', 'Token revogado com sucesso!');
                redirect('api_tokens.php');
                break;

            case 'deletar':
                $tokenId = (int)$_POST['token_id'];
                $db->delete('api_tokens', 'id = ?', [$tokenId]);
                setFlashMessage('success', 'Token deletado com sucesso!');
                redirect('api_tokens.php');
                break;
        }
    }
}

// Buscar todos os tokens do usuário
$tokens = $db->fetchAll("
    SELECT * FROM api_tokens
    WHERE usuario_id = ?
    ORDER BY criado_em DESC
", [$userId]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Tokens de API - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h2><i class="bi bi-key"></i> Tokens de API</h2>
                <p class="text-muted">Gerencie tokens de acesso para integração com a API do sistema</p>
            </div>
        </div>

        <?php if ($success = getFlashMessage('success')): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo sanitize($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error = getFlashMessage('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo sanitize($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Criar Novo Token -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Criar Novo Token</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="criar">

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Nome do Token *</label>
                                <input type="text" name="nome" class="form-control" required
                                       placeholder="Ex: Token Mobile App, Token Integração">
                                <small class="text-muted">Nome descritivo para identificar o token</small>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Validade (dias)</label>
                                <input type="number" name="expira_dias" class="form-control"
                                       placeholder="Deixe em branco para nunca expirar">
                                <small class="text-muted">Número de dias até expiração (vazio = sem expiração)</small>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="descricao" class="form-control" rows="2"
                                  placeholder="Descreva para que serve este token..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-key"></i> Gerar Token
                    </button>
                </form>
            </div>
        </div>

        <!-- Lista de Tokens -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-list"></i> Tokens Existentes</h5>
            </div>
            <div class="card-body">
                <?php if (empty($tokens)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Nenhum token criado ainda. Crie seu primeiro token acima!
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Token</th>
                                    <th>Descrição</th>
                                    <th>Status</th>
                                    <th>Último Uso</th>
                                    <th>Expira Em</th>
                                    <th>Criado Em</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tokens as $token): ?>
                                    <?php
                                    $expirado = $token['expira_em'] && strtotime($token['expira_em']) < time();
                                    $ativo = $token['ativo'] && !$expirado;
                                    ?>
                                    <tr class="<?php echo $ativo ? '' : 'table-secondary'; ?>">
                                        <td>
                                            <strong><?php echo sanitize($token['nome']); ?></strong>
                                        </td>
                                        <td>
                                            <code class="bg-light p-1 rounded" style="font-size: 0.85em;">
                                                <?php echo sanitize($token['token']); ?>
                                            </code>
                                            <button class="btn btn-sm btn-outline-secondary ms-2"
                                                    onclick="copiarToken('<?php echo $token['token']; ?>')">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </td>
                                        <td>
                                            <small><?php echo sanitize($token['descricao']); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($ativo): ?>
                                                <span class="badge bg-success">Ativo</span>
                                            <?php elseif ($expirado): ?>
                                                <span class="badge bg-danger">Expirado</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Revogado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo $token['ultimo_uso'] ? date('d/m/Y H:i', strtotime($token['ultimo_uso'])) : '-'; ?>
                                        </td>
                                        <td>
                                            <?php
                                            if ($token['expira_em']) {
                                                echo date('d/m/Y', strtotime($token['expira_em']));
                                                if ($expirado) {
                                                    echo ' <span class="text-danger">(Expirado)</span>';
                                                }
                                            } else {
                                                echo '<span class="text-muted">Nunca</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y H:i', strtotime($token['criado_em'])); ?>
                                        </td>
                                        <td>
                                            <?php if ($ativo): ?>
                                                <form method="POST" style="display: inline;"
                                                      onsubmit="return confirm('Tem certeza que deseja revogar este token?');">
                                                    <input type="hidden" name="action" value="revogar">
                                                    <input type="hidden" name="token_id" value="<?php echo $token['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-warning">
                                                        <i class="bi bi-x-circle"></i> Revogar
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display: inline;"
                                                  onsubmit="return confirm('Tem certeza que deseja deletar este token permanentemente?');">
                                                <input type="hidden" name="action" value="deletar">
                                                <input type="hidden" name="token_id" value="<?php echo $token['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Documentação da API -->
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-book"></i> Documentação da API</h5>
            </div>
            <div class="card-body">
                <h6>Endpoint: Gerar PDF com Análise IA</h6>
                <pre class="bg-light p-3 rounded"><code>POST <?php echo $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME'], 2); ?>/api/gerar_pdf_relatorio.php</code></pre>

                <h6 class="mt-3">Headers:</h6>
                <pre class="bg-light p-3 rounded"><code>Authorization: Bearer SEU_TOKEN_AQUI
Content-Type: application/json</code></pre>

                <h6 class="mt-3">Body (JSON):</h6>
                <pre class="bg-light p-3 rounded"><code>{
  "data_inicio": "2024-11-01",
  "data_fim": "2025-01-15",
  "promotor": "Nome do Promotor (opcional)",
  "status_inadimplencia": "INADIMPLENTE (opcional)"
}</code></pre>

                <h6 class="mt-3">Resposta de Sucesso:</h6>
                <pre class="bg-light p-3 rounded"><code>{
  "success": true,
  "html": "&lt;html&gt;...&lt;/html&gt;",
  "estatisticas": {
    "total_titulos": 1234,
    "inadimplentes": 456,
    "taxa_inadimplencia": 37.0
  },
  "analise_ia": "Análise detalhada..."
}</code></pre>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function copiarToken(token) {
        navigator.clipboard.writeText(token).then(() => {
            alert('Token copiado para a área de transferência!');
        }).catch(err => {
            console.error('Erro ao copiar:', err);
        });
    }
    </script>
</body>
</html>
