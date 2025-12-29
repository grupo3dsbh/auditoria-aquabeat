<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('../login.php');
requirePermission('admin');

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$isAdmin = Auth::isAdmin();

// Buscar todos os usuários (para seleção no formulário, se admin)
$usuarios = [];
if ($isAdmin) {
    $usuarios = $db->fetchAll("SELECT id, nome, email FROM usuarios ORDER BY nome");
}

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

                // Se for admin, pode escolher o usuário; senão usa o próprio
                $usuarioIdToken = $isAdmin && !empty($_POST['usuario_id']) ? (int)$_POST['usuario_id'] : $userId;

                // Processar permissões
                $permissoes = [];
                if (!empty($_POST['permissoes'])) {
                    $permissoes = is_array($_POST['permissoes']) ? $_POST['permissoes'] : [$_POST['permissoes']];
                }
                $permissoesJson = !empty($permissoes) ? json_encode($permissoes) : null;

                $db->insert('api_tokens', [
                    'usuario_id' => $usuarioIdToken,
                    'nome' => $nome,
                    'token' => $token,
                    'descricao' => $descricao,
                    'expira_em' => $expiraEm,
                    'permissoes' => $permissoesJson,
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

// Buscar tokens: se admin, buscar todos; senão apenas do usuário atual
if ($isAdmin) {
    $tokens = $db->fetchAll("
        SELECT t.*, u.nome as usuario_nome, u.email as usuario_email
        FROM api_tokens t
        INNER JOIN usuarios u ON t.usuario_id = u.id
        ORDER BY t.criado_em DESC
    ");
} else {
    $tokens = $db->fetchAll("
        SELECT t.*, u.nome as usuario_nome, u.email as usuario_email
        FROM api_tokens t
        INNER JOIN usuarios u ON t.usuario_id = u.id
        WHERE t.usuario_id = ?
        ORDER BY t.criado_em DESC
    ", [$userId]);
}

// Detectar esquema HTTP/HTTPS de forma segura
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
          (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
$baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME'], 2);
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

                    <?php if ($isAdmin): ?>
                    <div class="mb-3">
                        <label class="form-label">Usuário *</label>
                        <select name="usuario_id" class="form-control" required>
                            <option value="">Selecione um usuário...</option>
                            <?php foreach ($usuarios as $usuario): ?>
                                <option value="<?php echo $usuario['id']; ?>">
                                    <?php echo sanitize($usuario['nome']); ?> (<?php echo sanitize($usuario['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Selecione o usuário que terá acesso a este token</small>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Permissões</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="permissoes[]" value="gerar_pdf" id="permPDF" checked>
                            <label class="form-check-label" for="permPDF">
                                Gerar PDF com Análise IA
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="permissoes[]" value="consultar_relatorios" id="permRelatorios" checked>
                            <label class="form-check-label" for="permRelatorios">
                                Consultar Relatórios
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="permissoes[]" value="exportar_dados" id="permExportar" checked>
                            <label class="form-check-label" for="permExportar">
                                Exportar Dados
                            </label>
                        </div>
                        <small class="text-muted">Selecione as permissões que este token terá</small>
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
                                    <?php if ($isAdmin): ?><th>Usuário</th><?php endif; ?>
                                    <th>Token</th>
                                    <th>Permissões</th>
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
                                    $permissoes = $token['permissoes'] ? json_decode($token['permissoes'], true) : [];
                                    ?>
                                    <tr class="<?php echo $ativo ? '' : 'table-secondary'; ?>">
                                        <td>
                                            <strong><?php echo sanitize($token['nome']); ?></strong>
                                        </td>
                                        <?php if ($isAdmin): ?>
                                        <td>
                                            <small>
                                                <?php echo sanitize($token['usuario_nome']); ?><br>
                                                <span class="text-muted"><?php echo sanitize($token['usuario_email']); ?></span>
                                            </small>
                                        </td>
                                        <?php endif; ?>
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
                                            <small>
                                                <?php if (!empty($permissoes)): ?>
                                                    <?php foreach ($permissoes as $perm): ?>
                                                        <span class="badge bg-info me-1"><?php echo sanitize($perm); ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Todas</span>
                                                <?php endif; ?>
                                            </small>
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
                <pre class="bg-light p-3 rounded"><code>POST <?php echo $baseUrl; ?>/api/gerar_pdf_relatorio.php</code></pre>

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
