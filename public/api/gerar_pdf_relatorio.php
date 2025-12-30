<?php
/**
 * API Pública: Gerar PDF com Análise IA
 * Endpoint para gerar relatório em PDF com análise de inadimplência usando IA
 */

// Suprimir warnings e notices - apenas erros fatais
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

define('APP_ROOT', dirname(dirname(__DIR__)));

// Tentar carregar bootstrap com tratamento de erro
try {
    require_once APP_ROOT . '/includes/bootstrap.php';
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao carregar sistema: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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

// Try-catch global para capturar qualquer erro
try {

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
$tipoPlanilha = $input['tipo_planilha'] ?? 'padrao'; // 'padrao' (50 títulos) ou 'auditoria' (todos que requerem atenção)

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

// Buscar todos os títulos com cartões
// Verificar se a tabela titulo_cartoes existe
$tabelaCartoesExiste = false;
try {
    $db->query("SELECT 1 FROM titulo_cartoes LIMIT 1");
    $tabelaCartoesExiste = true;
} catch (Exception $e) {
    // Tabela não existe, ignorar
}

if ($tabelaCartoesExiste) {
    // Query com JOIN na tabela titulo_cartoes
    $sql = "SELECT t.*,
            GROUP_CONCAT(tc.numero_cartao ORDER BY tc.ordem_uso SEPARATOR ' | ') as cartoes_lista,
            GROUP_CONCAT(tc.bandeira ORDER BY tc.ordem_uso SEPARATOR ' | ') as bandeiras_lista,
            MAX(tc.tipo_pagamento) as tipo_cartao
            FROM titulos t
            LEFT JOIN titulo_cartoes tc ON t.id = tc.titulo_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY t.id
            ORDER BY t.data_primeira_venda DESC";
} else {
    // Query sem JOIN (tabela titulo_cartoes não existe)
    $sql = "SELECT t.*,
            NULL as cartoes_lista,
            NULL as bandeiras_lista,
            NULL as tipo_cartao
            FROM titulos t
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.data_primeira_venda DESC";
}

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
$aiService = new AIService();

$dadosAnalise = [
    'total_titulos' => $totalTitulos,
    'inadimplentes' => $inadimplentes,
    'adimplentes' => $adimplentes,
    'taxa_inadimplencia' => $taxaInadimplencia,
    'valor_total_pago' => $valorTotalPago,
    'valor_total_perdido' => $valorTotalPerdido,
    'cartoes_risco' => $cartoesRisco,
    'promotores_risco' => $promotoresRisco
];

try {
    $resultadoIA = $aiService->analyzeDelinquencyData($dadosAnalise, 'geral');
    $analiseIA = $resultadoIA['success'] ? $resultadoIA['content'] : 'Erro ao gerar análise: ' . ($resultadoIA['error'] ?? 'Desconhecido');
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
    'usuario_nome' => $tokenData['usuario_nome'],
    'tipo_planilha' => $tipoPlanilha
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

} catch (Throwable $e) {
    // Capturar qualquer erro e retornar JSON
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Gerar HTML formatado para impressão/PDF
 */
function gerarHTMLRelatorio($titulos, $dados) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
              (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
    $data = date('d/m/Y H:i');

    // Filtrar títulos de acordo com o tipo de planilha
    $tipoPlanilha = $dados['tipo_planilha'] ?? 'padrao';

    if ($tipoPlanilha === 'auditoria') {
        // Planilha de Auditoria: Mostrar TODOS os títulos que requerem atenção
        $titulosPreview = array_filter($titulos, function($titulo) {
            // Inadimplentes
            $isInadimplente = strpos($titulo['status_inadimplencia'], 'INADIMPLENTE') !== false;

            // Pagamentos com débito ou PIX
            $formaPagamento = strtolower($titulo['forma_pagamento'] ?? '');
            $tipoPagamento = strtolower($titulo['tipo_pagamento'] ?? '');
            $tipoCartao = strtolower($titulo['tipo_cartao'] ?? '');

            $ehDebito = (strpos($formaPagamento, 'débito') !== false ||
                        strpos($tipoPagamento, 'débito') !== false ||
                        strpos($tipoCartao, 'débito') !== false ||
                        strpos($tipoCartao, 'debit') !== false);

            $ehPIX = (strpos($formaPagamento, 'pix') !== false ||
                     strpos($formaPagamento, 'carteira') !== false);

            // Sem cartão
            $semCartao = empty($titulo['cartoes_lista']);

            // Retorna TRUE se requer atenção
            return $isInadimplente || $ehDebito || $ehPIX || $semCartao;
        });
        $titulosPreview = array_values($titulosPreview); // Reindexar
    } else {
        // Planilha Padrão: Limitar a 50 primeiros títulos
        $titulosPreview = array_slice($titulos, 0, 50);
    }

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

            .alerta-pagamento {
                background-color: #fff3cd !important;
                border-left: 3px solid #ff9800;
            }

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

        <h2>📋 <?php echo $tipoPlanilha === 'auditoria' ? 'Planilha de Auditoria (Títulos que Requerem Atenção)' : 'Preview dos Resultados (Primeiros 50)'; ?></h2>
        <p class="text-muted">
            <em>Mostrando <?php echo count($titulosPreview); ?> de <?php echo $dados['total_titulos']; ?> títulos</em>
            <?php if ($tipoPlanilha === 'auditoria'): ?>
                <br><strong>Critérios de atenção:</strong> Inadimplentes, Pagamentos em Débito/PIX ou Sem Cartão
            <?php endif; ?>
        </p>
        <table>
            <thead>
                <tr>
                    <th>Título</th>
                    <th>Titular</th>
                    <th>Data Venda</th>
                    <th>Promotor</th>
                    <th>Forma Pgto</th>
                    <th>Cartões</th>
                    <th>Status</th>
                    <th>Parcelas</th>
                    <th>Valor Pago</th>
                    <th>Saldo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($titulosPreview as $titulo): ?>
                <?php
                // Detectar se NÃO é cartão de crédito (requer atenção)
                $formaPagamento = strtolower($titulo['forma_pagamento'] ?? '');
                $tipoPagamento = strtolower($titulo['tipo_pagamento'] ?? '');
                $tipoCartao = strtolower($titulo['tipo_cartao'] ?? '');
                $temCartao = !empty($titulo['cartoes_lista']);

                $ehDebito = (strpos($formaPagamento, 'débito') !== false ||
                            strpos($tipoPagamento, 'débito') !== false ||
                            strpos($tipoCartao, 'débito') !== false ||
                            strpos($tipoCartao, 'debit') !== false);

                $ehPIX = (strpos($formaPagamento, 'pix') !== false ||
                         strpos($formaPagamento, 'carteira') !== false);

                $classeLinha = ($ehDebito || $ehPIX) ? 'class="alerta-pagamento"' : '';
                ?>
                <tr <?php echo $classeLinha; ?>>
                    <td><?php echo htmlspecialchars($titulo['numero_titulo']); ?></td>
                    <td><?php echo htmlspecialchars($titulo['nome_titular']); ?></td>
                    <td><?php echo $titulo['data_primeira_venda'] ? date('d/m/Y', strtotime($titulo['data_primeira_venda'])) : '-'; ?></td>
                    <td>
                        <a href="<?php echo $baseUrl; ?>/relatorios.php?promotor=<?php echo urlencode($titulo['promotor']); ?>" target="_blank">
                            <?php echo htmlspecialchars($titulo['promotor']); ?>
                        </a>
                    </td>
                    <td>
                        <?php
                        if ($ehDebito) {
                            echo '<span class="badge badge-warning">⚠️ DÉBITO</span>';
                        } elseif ($ehPIX) {
                            echo '<span class="badge badge-warning">⚠️ PIX/Carteira</span>';
                        } else {
                            echo htmlspecialchars($titulo['forma_pagamento'] ?: $titulo['tipo_pagamento'] ?: '-');
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if ($temCartao) {
                            $cartoes = explode(' | ', $titulo['cartoes_lista']);
                            $bandeiras = explode(' | ', $titulo['bandeiras_lista'] ?? '');
                            echo '<small>';
                            foreach ($cartoes as $i => $cartao) {
                                $bandeira = $bandeiras[$i] ?? '';
                                echo htmlspecialchars(substr($cartao, -4)) . ($bandeira ? " ($bandeira)" : '');
                                if ($i < count($cartoes) - 1) echo '<br>';
                            }
                            echo '</small>';
                        } else {
                            echo '<span class="badge badge-warning">⚠️ Sem cartão</span>';
                        }
                        ?>
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
