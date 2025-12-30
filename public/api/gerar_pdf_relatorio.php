<?php
/**
 * API Pública: Gerar PDF com Análise IA
 * Endpoint para gerar relatório em PDF com análise de inadimplência usando IA
 */

define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Função para retornar erro JSON
function apiError($message, $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Função para validar token de API
function validarToken($token) {
    $db = Database::getInstance();

    // Buscar token no banco
    $tokenData = $db->fetchOne("
        SELECT t.*, u.nome as usuario_nome, u.email as usuario_email
        FROM api_tokens t
        INNER JOIN usuarios u ON t.usuario_id = u.id
        WHERE t.token = ? AND t.ativo = 1
    ", [$token]);

    if (!$tokenData) {
        return false;
    }

    // Verificar expiração
    if ($tokenData['expira_em'] && strtotime($tokenData['expira_em']) < time()) {
        return false;
    }

    // Atualizar último uso
    $db->update('api_tokens', [
        'ultimo_uso' => date('Y-m-d H:i:s')
    ], 'id = ?', [$tokenData['id']]);

    return $tokenData;
}

// Validar método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Método não permitido. Use POST.', 405);
}

// Validar autenticação
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
    apiError('Token de autenticação não fornecido. Use: Authorization: Bearer SEU_TOKEN', 401);
}

$token = $matches[1];
$tokenData = validarToken($token);

if (!$tokenData) {
    apiError('Token inválido ou expirado', 401);
}

// Ler dados JSON
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    apiError('JSON inválido no body da requisição');
}

// Extrair parâmetros
$dataInicio = $input['data_inicio'] ?? null;
$dataFim = $input['data_fim'] ?? null;
$promotorFiltro = $input['promotor'] ?? null;
$statusInadimplencia = $input['status_inadimplencia'] ?? null;

// Validar datas
if (!$dataInicio || !$dataFim) {
    apiError('Parâmetros data_inicio e data_fim são obrigatórios');
}

// Buscar dados
$db = Database::getInstance();

// Obter última importação
$ultimaImportacao = $db->fetchOne("SELECT * FROM importacoes WHERE status = 'concluido' ORDER BY concluido_em DESC LIMIT 1");
if (!$ultimaImportacao) {
    apiError('Nenhuma importação concluída encontrada', 404);
}

$importacaoId = $ultimaImportacao['id'];

// Construir query
$where = ["importacao_id = ?"];
$params = [$importacaoId];

$where[] = "data_primeira_venda >= ?";
$params[] = $dataInicio . ' 00:00:00';

$where[] = "data_primeira_venda <= ?";
$params[] = $dataFim . ' 23:59:59';

if ($promotorFiltro) {
    $where[] = "promotor = ?";
    $params[] = $promotorFiltro;
}

if ($statusInadimplencia) {
    $where[] = "status_inadimplencia = ?";
    $params[] = $statusInadimplencia;
}

// Buscar todos os títulos
$sql = "SELECT * FROM titulos WHERE " . implode(' AND ', $where) . " ORDER BY data_primeira_venda DESC";
$titulos = $db->fetchAll($sql, $params);

// Calcular estatísticas
$totalTitulos = count($titulos);
$inadimplentes = 0;
$adimplentes = 0;
$valorTotalPago = 0;
$valorTotalPerdido = 0;
$cartoesRisco = [];
$promotoresRisco = [];

foreach ($titulos as $titulo) {
    if (strpos($titulo['status_inadimplencia'], 'INADIMPLENTE') !== false) {
        $inadimplentes++;
        $valorTotalPerdido += $titulo['saldo_restante'] ?? 0;
    } else {
        $adimplentes++;
    }
    $valorTotalPago += $titulo['total_pago'] ?? 0;

    // Identificar cartões de risco
    if (!empty($titulo['numero_cartao']) && $titulo['taxa_inadimplencia_cartao'] > 50) {
        if (!isset($cartoesRisco[$titulo['numero_cartao']])) {
            $cartoesRisco[$titulo['numero_cartao']] = [
                'numero' => $titulo['numero_cartao'],
                'bandeira' => $titulo['bandeira'],
                'total_titulos' => $titulo['total_titulos_no_cartao'],
                'inadimplentes' => $titulo['titulos_inadimplentes_no_cartao'],
                'taxa' => $titulo['taxa_inadimplencia_cartao']
            ];
        }
    }

    // Identificar promotores de risco
    if (!empty($titulo['promotor']) && $titulo['taxa_inadimplencia_consultor'] > 40) {
        if (!isset($promotoresRisco[$titulo['promotor']])) {
            $promotoresRisco[$titulo['promotor']] = [
                'nome' => $titulo['promotor'],
                'vendas' => $titulo['vendas_consultor'],
                'inadimplentes' => $titulo['total_inadimplentes_consultor'],
                'taxa' => $titulo['taxa_inadimplencia_consultor']
            ];
        }
    }
}

$taxaInadimplencia = $totalTitulos > 0 ? round(($inadimplentes / $totalTitulos) * 100, 2) : 0;

// Gerar análise com IA
require_once APP_ROOT . '/includes/IAProvider.php';
$iaProvider = new IAProvider();

$prompt = "Analise os seguintes dados de inadimplência e forneça insights objetivos (sem acusar ninguém):

**Estatísticas Gerais:**
- Total de títulos: {$totalTitulos}
- Inadimplentes: {$inadimplentes}
- Adimplentes: {$adimplentes}
- Taxa de inadimplência: {$taxaInadimplencia}%
- Valor total pago: R$ " . number_format($valorTotalPago, 2, ',', '.') . "
- Valor total perdido: R$ " . number_format($valorTotalPerdido, 2, ',', '.') . "

**Cartões com Alta Inadimplência:**
" . (!empty($cartoesRisco) ? implode("\n", array_map(function($c) {
    return "- {$c['numero']} ({$c['bandeira']}): {$c['inadimplentes']}/{$c['total_titulos']} títulos inadimplentes ({$c['taxa']}%)";
}, $cartoesRisco)) : 'Nenhum cartão de alto risco identificado') . "

**Promotores com Alta Inadimplência:**
" . (!empty($promotoresRisco) ? implode("\n", array_map(function($p) {
    return "- {$p['nome']}: {$p['inadimplentes']}/{$p['vendas']} vendas inadimplentes ({$p['taxa']}%)";
}, $promotoresRisco)) : 'Nenhum promotor de alto risco identificado') . "

Forneça uma análise em 3-5 parágrafos destacando:
1. Visão geral da situação
2. Padrões identificados
3. Possíveis causas (sem culpar indivíduos)
4. Recomendações de ação";

try {
    $analiseIA = $iaProvider->chat($prompt);
} catch (Exception $e) {
    $analiseIA = "Erro ao gerar análise: " . $e->getMessage();
}

// Gerar HTML do relatório
$html = gerarHTMLRelatorio($titulos, [
    'total_titulos' => $totalTitulos,
    'inadimplentes' => $inadimplentes,
    'adimplentes' => $adimplentes,
    'taxa_inadimplencia' => $taxaInadimplencia,
    'valor_total_pago' => $valorTotalPago,
    'valor_total_perdido' => $valorTotalPerdido,
    'cartoes_risco' => $cartoesRisco,
    'promotores_risco' => $promotoresRisco,
    'analise_ia' => $analiseIA,
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
    'usuario_nome' => $tokenData['usuario_nome']
]);

// Retornar resposta
echo json_encode([
    'success' => true,
    'html' => $html,
    'estatisticas' => [
        'total_titulos' => $totalTitulos,
        'inadimplentes' => $inadimplentes,
        'adimplentes' => $adimplentes,
        'taxa_inadimplencia' => $taxaInadimplencia,
        'valor_total_pago' => $valorTotalPago,
        'valor_total_perdido' => $valorTotalPerdido
    ],
    'cartoes_risco' => array_values($cartoesRisco),
    'promotores_risco' => array_values($promotoresRisco),
    'analise_ia' => $analiseIA
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

/**
 * Gerar HTML formatado para impressão/PDF
 */
function gerarHTMLRelatorio($titulos, $dados) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
              (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
    $data = date('d/m/Y H:i');

    // Limitar a 50 primeiros títulos para preview
    $titulosPreview = array_slice($titulos, 0, 50);

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Relatório de Inadimplência - <?php echo date('d/m/Y'); ?></title>
        <style>
            @media print {
                .no-print { display: none; }
                body { font-size: 12pt; }
            }

            body {
                font-family: Arial, sans-serif;
                margin: 20px;
                color: #333;
            }

            .header {
                text-align: center;
                border-bottom: 3px solid #007bff;
                padding-bottom: 20px;
                margin-bottom: 30px;
            }

            h1 { color: #007bff; margin: 0; }
            h2 { color: #0056b3; border-bottom: 2px solid #dee2e6; padding-bottom: 10px; }
            h3 { color: #495057; }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin: 20px 0;
            }

            .stat-card {
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 15px;
                background: #f8f9fa;
            }

            .stat-value {
                font-size: 2em;
                font-weight: bold;
                color: #007bff;
            }

            .stat-label {
                color: #6c757d;
                font-size: 0.9em;
            }

            .alert {
                padding: 15px;
                border-radius: 5px;
                margin: 15px 0;
            }

            .alert-warning {
                background: #fff3cd;
                border: 1px solid #ffc107;
                color: #856404;
            }

            .alert-info {
                background: #d1ecf1;
                border: 1px solid #17a2b8;
                color: #0c5460;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
                font-size: 0.9em;
            }

            th, td {
                border: 1px solid #dee2e6;
                padding: 8px;
                text-align: left;
            }

            th {
                background: #007bff;
                color: white;
            }

            tr:nth-child(even) {
                background: #f8f9fa;
            }

            .badge {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 0.85em;
                font-weight: bold;
            }

            .badge-danger { background: #dc3545; color: white; }
            .badge-success { background: #28a745; color: white; }
            .badge-warning { background: #ffc107; color: #000; }

            a { color: #007bff; text-decoration: none; }
            a:hover { text-decoration: underline; }

            .footer {
                margin-top: 40px;
                padding-top: 20px;
                border-top: 2px solid #dee2e6;
                text-align: center;
                color: #6c757d;
                font-size: 0.9em;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>📊 Relatório de Inadimplência com Análise IA</h1>
            <p><strong>Período:</strong> <?php echo date('d/m/Y', strtotime($dados['data_inicio'])); ?> a <?php echo date('d/m/Y', strtotime($dados['data_fim'])); ?></p>
            <p><strong>Gerado em:</strong> <?php echo $data; ?> por <?php echo htmlspecialchars($dados['usuario_nome']); ?></p>
        </div>

        <h2>📈 Estatísticas Gerais</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($dados['total_titulos'], 0, ',', '.'); ?></div>
                <div class="stat-label">Total de Títulos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #dc3545;"><?php echo number_format($dados['inadimplentes'], 0, ',', '.'); ?></div>
                <div class="stat-label">Inadimplentes</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #28a745;"><?php echo number_format($dados['adimplentes'], 0, ',', '.'); ?></div>
                <div class="stat-label">Adimplentes</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: <?php echo $dados['taxa_inadimplencia'] > 40 ? '#dc3545' : '#ffc107'; ?>">
                    <?php echo number_format($dados['taxa_inadimplencia'], 1, ',', '.'); ?>%
                </div>
                <div class="stat-label">Taxa de Inadimplência</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="font-size: 1.3em; color: #28a745;">
                    R$ <?php echo number_format($dados['valor_total_pago'], 2, ',', '.'); ?>
                </div>
                <div class="stat-label">Valor Total Pago</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="font-size: 1.3em; color: #dc3545;">
                    R$ <?php echo number_format($dados['valor_total_perdido'], 2, ',', '.'); ?>
                </div>
                <div class="stat-label">Valor Total Perdido</div>
            </div>
        </div>

        <h2>🤖 Análise de Inteligência Artificial</h2>
        <div class="alert alert-info">
            <?php echo nl2br(htmlspecialchars($dados['analise_ia'])); ?>
        </div>

        <?php if (!empty($dados['cartoes_risco'])): ?>
        <h2>💳 Cartões com Alta Inadimplência</h2>
        <div class="alert alert-warning">
            <strong>⚠️ Atenção:</strong> Os seguintes cartões apresentam taxa de inadimplência superior a 50%
        </div>
        <table>
            <thead>
                <tr>
                    <th>Cartão</th>
                    <th>Bandeira</th>
                    <th>Total de Títulos</th>
                    <th>Inadimplentes</th>
                    <th>Taxa</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dados['cartoes_risco'] as $cartao): ?>
                <tr>
                    <td><code><?php echo htmlspecialchars($cartao['numero']); ?></code></td>
                    <td><?php echo htmlspecialchars($cartao['bandeira']); ?></td>
                    <td><?php echo $cartao['total_titulos']; ?></td>
                    <td><span class="badge badge-danger"><?php echo $cartao['inadimplentes']; ?></span></td>
                    <td><strong><?php echo number_format($cartao['taxa'], 1); ?>%</strong></td>
                    <td>
                        <a href="<?php echo $baseUrl; ?>/relatorios.php?numero_cartao=<?php echo urlencode($cartao['numero']); ?>" target="_blank">
                            Ver Detalhes
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($dados['promotores_risco'])): ?>
        <h2>👤 Promotores com Alta Inadimplência</h2>
        <div class="alert alert-warning">
            <strong>⚠️ Atenção:</strong> Os seguintes promotores apresentam taxa de inadimplência superior a 40%
        </div>
        <table>
            <thead>
                <tr>
                    <th>Promotor</th>
                    <th>Total de Vendas</th>
                    <th>Inadimplentes</th>
                    <th>Taxa</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dados['promotores_risco'] as $promotor): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($promotor['nome']); ?></strong></td>
                    <td><?php echo $promotor['vendas']; ?></td>
                    <td><span class="badge badge-danger"><?php echo $promotor['inadimplentes']; ?></span></td>
                    <td><strong><?php echo number_format($promotor['taxa'], 1); ?>%</strong></td>
                    <td>
                        <a href="<?php echo $baseUrl; ?>/relatorios.php?promotor=<?php echo urlencode($promotor['nome']); ?>" target="_blank">
                            Ver Detalhes
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h2>📋 Preview dos Resultados (Primeiros 50)</h2>
        <p class="text-muted"><em>Mostrando <?php echo count($titulosPreview); ?> de <?php echo $dados['total_titulos']; ?> títulos</em></p>
        <table>
            <thead>
                <tr>
                    <th>Título</th>
                    <th>Titular</th>
                    <th>Promotor</th>
                    <th>Status</th>
                    <th>Parcelas</th>
                    <th>Valor Pago</th>
                    <th>Saldo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($titulosPreview as $titulo): ?>
                <tr>
                    <td><?php echo htmlspecialchars($titulo['numero_titulo']); ?></td>
                    <td><?php echo htmlspecialchars($titulo['nome_titular']); ?></td>
                    <td>
                        <a href="<?php echo $baseUrl; ?>/relatorios.php?promotor=<?php echo urlencode($titulo['promotor']); ?>" target="_blank">
                            <?php echo htmlspecialchars($titulo['promotor']); ?>
                        </a>
                    </td>
                    <td>
                        <?php
                        $status = $titulo['status_inadimplencia'];
                        if (strpos($status, 'INADIMPLENTE') !== false) {
                            echo '<span class="badge badge-danger">' . htmlspecialchars($status) . '</span>';
                        } else {
                            echo '<span class="badge badge-success">' . htmlspecialchars($status) . '</span>';
                        }
                        ?>
                    </td>
                    <td><?php echo $titulo['qtd_parcelas_pagas']; ?>/<?php echo $titulo['quantidade_parcelas_venda']; ?></td>
                    <td>R$ <?php echo number_format($titulo['total_pago'] ?? 0, 2, ',', '.'); ?></td>
                    <td>R$ <?php echo number_format($titulo['saldo_restante'] ?? 0, 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer">
            <p><strong>Aquabeat Auditoria</strong> - Relatório Gerado Automaticamente</p>
            <p>Para ver todos os resultados, acesse o sistema completo em: <a href="<?php echo $baseUrl; ?>/relatorios.php"><?php echo $baseUrl; ?>/relatorios.php</a></p>
            <p class="no-print"><em>Para salvar como PDF, use Ctrl+P (Windows/Linux) ou Cmd+P (Mac) e selecione "Salvar como PDF"</em></p>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}
