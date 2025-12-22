<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

$promotor = $_GET['promotor'] ?? null;

if (!$promotor) {
    setFlashMessage('error', 'Consultor não especificado.');
    header('Location: index.php');
    exit;
}

$db = Database::getInstance();

// Buscar informações do consultor
$dataInicio = '2024-11-01';
$dataFim = date('Y-m-t', strtotime('-2 months'));

$titulosConsultor = $db->fetchAll("
    SELECT *
    FROM titulos
    WHERE promotor = ?
      AND data_primeira_venda BETWEEN ? AND ?
    ORDER BY data_primeira_venda DESC
", [$promotor, $dataInicio . ' 00:00:00', $dataFim . ' 23:59:59']);

if (empty($titulosConsultor)) {
    setFlashMessage('error', 'Nenhum título encontrado para este consultor.');
    header('Location: index.php');
    exit;
}

// Análise estatística
$stats = [
    'total_vendas' => count($titulosConsultor),
    'debito' => 0,
    'pix' => 0,
    'credito' => 0,
    'bloqueados' => 0,
    'apenas_1_parcela' => 0,
    'apenas_2_parcelas' => 0,
    'inadimplentes' => 0,
    'adimplentes' => 0,
    'problemas_debito_pix' => []
];

foreach ($titulosConsultor as $titulo) {
    // Contar formas de pagamento
    if (stripos($titulo['bandeira'], 'DEBITO') !== false || stripos($titulo['bandeira'], 'DEBIT') !== false) {
        $stats['debito']++;
    } elseif (stripos($titulo['bandeira'], 'PIX') !== false || stripos($titulo['bandeira'], 'CARTEIRA') !== false) {
        $stats['pix']++;
    } elseif (stripos($titulo['bandeira'], 'CREDITO') !== false || stripos($titulo['bandeira'], 'CREDIT') !== false) {
        $stats['credito']++;
    }

    // Contar status
    if ($titulo['status_titulo'] === 'Bloqueado' || $titulo['status_titulo'] === 'Cancelado') {
        $stats['bloqueados']++;
    }

    if ($titulo['qtd_parcelas_pagas'] == 1) {
        $stats['apenas_1_parcela']++;
    } elseif ($titulo['qtd_parcelas_pagas'] == 2) {
        $stats['apenas_2_parcelas']++;
    }

    if (stripos($titulo['status_inadimplencia'], 'INADIMPLENTE') !== false) {
        $stats['inadimplentes']++;
    } else {
        $stats['adimplentes']++;
    }

    // Identificar problemas com débito/PIX
    $isDebitoPix = (stripos($titulo['bandeira'], 'DEBITO') !== false ||
                    stripos($titulo['bandeira'], 'DEBIT') !== false ||
                    stripos($titulo['bandeira'], 'PIX') !== false ||
                    stripos($titulo['bandeira'], 'CARTEIRA') !== false);

    $temProblema = ($titulo['status_titulo'] === 'Bloqueado' ||
                   $titulo['status_titulo'] === 'Cancelado' ||
                   $titulo['qtd_parcelas_pagas'] <= 2);

    if ($isDebitoPix && $temProblema) {
        $stats['problemas_debito_pix'][] = $titulo;
    }
}

// Calcular taxa de risco
$stats['taxa_risco'] = count($stats['problemas_debito_pix']) > 0
    ? (count($stats['problemas_debito_pix']) / $stats['total_vendas']) * 100
    : 0;

// Gerar análise com IA (se solicitado via POST)
$analiseIA = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gerar_analise'])) {
    try {
        $analiseIA = gerarAnaliseIA($promotor, $stats, $stats['problemas_debito_pix']);
    } catch (Exception $e) {
        setFlashMessage('error', 'Erro ao gerar análise: ' . $e->getMessage());
    }
}

/**
 * Gera análise inteligente usando IA
 */
function gerarAnaliseIA($promotor, $stats, $problemas) {
    $prompt = "Você é um consultor de negócios especializado em análise de inadimplência e gestão de equipes comerciais.

SITUAÇÃO DO CONSULTOR: {$promotor}

DADOS:
- Total de vendas: {$stats['total_vendas']}
- Vendas em Débito: {$stats['debito']}
- Vendas em PIX: {$stats['pix']}
- Vendas em Crédito: {$stats['credito']}
- Títulos Bloqueados: {$stats['bloqueados']}
- Apenas 1ª parcela paga: {$stats['apenas_1_parcela']}
- Apenas 2 parcelas pagas: {$stats['apenas_2_parcelas']}
- Taxa de Risco: " . number_format($stats['taxa_risco'], 1) . "%

PROBLEMAS IDENTIFICADOS:
- " . count($problemas) . " títulos vendidos em DÉBITO/PIX que foram bloqueados ou tiveram apenas 1-2 parcelas pagas

IMPORTANTE: Cartão de DÉBITO em múltiplos títulos é ALTO RISCO porque:
- Em crédito, as parcelas são cobradas automaticamente (recorrência)
- Em débito, o cliente precisa ter saldo a cada mês (alto risco de inadimplência)
- PIX/Carteira Digital tem o mesmo problema

TAREFA: Gere uma análise profissional em formato de relatório com:

## 1. DIAGNÓSTICO
Explique de forma clara e objetiva o que está acontecendo com este consultor.

## 2. PRINCIPAIS PROBLEMAS
Liste os 3 principais problemas identificados.

## 3. SUGESTÕES DE ABORDAGEM PROFISSIONAL
Como o gestor deve conversar com este consultor de forma respeitosa e construtiva?
Forneça um roteiro de conversa.

## 4. AÇÕES CORRETIVAS
O que instruir ao consultor para evitar novos casos?
Seja específico e prático.

## 5. MÉTRICAS DE ACOMPANHAMENTO
Quais indicadores devem ser monitorados nos próximos meses?

Use tom profissional, respeitoso e construtivo. Foque em soluções.";

    // Aqui você pode usar OpenAI, Anthropic Claude, ou outra API de IA
    // Por enquanto, vou retornar uma análise de exemplo

    return gerarAnaliseExemplo($promotor, $stats);
}

/**
 * Análise de exemplo (substituir por API real posteriormente)
 */
function gerarAnaliseExemplo($promotor, $stats) {
    return "## 1. DIAGNÓSTICO

O consultor **{$promotor}** apresenta um padrão de risco elevado em suas vendas, com **" . number_format($stats['taxa_risco'], 1) . "%** das transações utilizando formas de pagamento de alto risco (Débito/PIX) que resultaram em bloqueios ou poucas parcelas pagas.

**Análise do padrão:**
- De {$stats['total_vendas']} vendas totais, {$stats['debito']} foram em débito e {$stats['pix']} em PIX
- {$stats['bloqueados']} títulos foram bloqueados/cancelados
- {$stats['apenas_1_parcela']} títulos tiveram apenas 1 parcela paga (possível foco em premiação)

## 2. PRINCIPAIS PROBLEMAS

1. **Uso excessivo de Débito/PIX**: Essas formas de pagamento NÃO garantem recorrência automática, aumentando drasticamente o risco de inadimplência.

2. **Alta taxa de bloqueios**: Títulos bloqueados indicam possível fraude, documentação irregular ou problemas no processo de venda.

3. **Padrão de \"apenas 1 parcela\"**: Sugere que clientes estão pagando apenas para ganhar a premiação inicial, sem intenção de continuar.

## 3. SUGESTÕES DE ABORDAGEM PROFISSIONAL

**Roteiro de Conversa:**

*Início (tom positivo):*
\"[Nome], agradeço seu empenho nas vendas. Gostaria de conversar sobre algumas oportunidades de melhoria que identifiquei nos seus processos.\"

*Apresentação dos dados:*
\"Notei que uma parte significativa das suas vendas está sendo feita em cartão de débito e PIX. Você sabia que essas formas de pagamento têm uma taxa de inadimplência muito maior que o crédito?\"

*Explicação técnica:*
\"No crédito, as parcelas são cobradas automaticamente. No débito e PIX, o cliente precisa ter saldo disponível todo mês, o que aumenta muito o risco de não pagamento.\"

*Pergunta investigativa:*
\"Você tem enfrentado alguma dificuldade para aprovar crédito com os clientes? Podemos ajudar nisso?\"

*Fechamento construtivo:*
\"Vamos trabalhar juntos para melhorar esses números. Tenho algumas sugestões que vão te ajudar a ter mais segurança nas vendas.\"

## 4. AÇÕES CORRETIVAS

**Instruções para o consultor:**

1. **PRIORIZAR CARTÃO DE CRÉDITO**
   - Sempre oferecer crédito como primeira opção
   - Explicar ao cliente os benefícios da cobrança automática
   - Se necessário, ajudar o cliente a aumentar o limite

2. **CRITÉRIOS PARA DÉBITO/PIX**
   - Só aceitar se o cliente tiver histórico comprovado de adimplência
   - Evitar em vendas de alto valor
   - Documentar bem a escolha (email, WhatsApp confirmando)

3. **VERIFICAÇÃO DE DOCUMENTAÇÃO**
   - Conferir todos os documentos antes de finalizar
   - Validar dados de contato (telefone, email)
   - Fazer ligação de confirmação em 24h após a venda

4. **QUALIFICAÇÃO DO LEAD**
   - Entender a real necessidade do cliente
   - Evitar vender apenas pela premiação
   - Garantir que o cliente entende o compromisso mensal

5. **FOLLOW-UP PÓS-VENDA**
   - Ligar para o cliente 1 semana após a venda
   - Confirmar se está tudo certo
   - Antecipar possíveis problemas

## 5. MÉTRICAS DE ACOMPANHAMENTO

**Monitorar mensalmente:**

- **Taxa de uso de crédito**: Meta mínima 80%
- **Taxa de bloqueios**: Meta máxima 5%
- **Taxa de apenas 1 parcela**: Meta máxima 10%
- **Taxa de inadimplência geral**: Meta máxima 25%
- **NPS dos clientes**: Aplicar pesquisa de satisfação

**Ações se não melhorar em 60 dias:**
- Treinamento individual
- Acompanhamento de vendas (observação)
- Definição de metas específicas
- Possível realocação de função

**IMPORTANTE:** Este consultor pode estar apenas mal orientado. Com treinamento e acompanhamento adequado, a situação pode reverter completamente.";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análise Inteligente - <?php echo sanitize($promotor); ?> - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .stat-card {
            border-left: 4px solid #0d6efd;
            padding: 15px;
            margin-bottom: 15px;
        }
        .stat-card.danger {
            border-left-color: #dc3545;
        }
        .stat-card.warning {
            border-left-color: #ffc107;
        }
        .stat-card.success {
            border-left-color: #198754;
        }
        .markdown-content h2 {
            color: #0d6efd;
            font-size: 1.5rem;
            margin-top: 30px;
            margin-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
        }
        .markdown-content h3 {
            color: #495057;
            font-size: 1.2rem;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        .markdown-content ul {
            margin-left: 20px;
        }
        .markdown-content li {
            margin-bottom: 8px;
        }
        .markdown-content strong {
            color: #212529;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2><i class="bi bi-robot"></i> Análise Inteligente (IA)</h2>
                        <p class="text-muted">Consultor: <strong><?php echo sanitize($promotor); ?></strong></p>
                    </div>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar ao Dashboard
                    </a>
                </div>
            </div>
        </div>

        <!-- Estatísticas do Consultor -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="bi bi-bar-chart"></i> Estatísticas do Consultor</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <h3><?php echo $stats['total_vendas']; ?></h3>
                                    <p class="text-muted mb-0">Total de Vendas</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card danger">
                                    <h3><?php echo $stats['debito']; ?></h3>
                                    <p class="text-muted mb-0">Vendas em Débito</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card warning">
                                    <h3><?php echo $stats['pix']; ?></h3>
                                    <p class="text-muted mb-0">Vendas em PIX</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card success">
                                    <h3><?php echo $stats['credito']; ?></h3>
                                    <p class="text-muted mb-0">Vendas em Crédito</p>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-3">
                                <div class="stat-card danger">
                                    <h3><?php echo $stats['bloqueados']; ?></h3>
                                    <p class="text-muted mb-0">Títulos Bloqueados</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card warning">
                                    <h3><?php echo $stats['apenas_1_parcela']; ?></h3>
                                    <p class="text-muted mb-0">Apenas 1ª Parcela</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card danger">
                                    <h3><?php echo $stats['inadimplentes']; ?></h3>
                                    <p class="text-muted mb-0">Inadimplentes</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card <?php echo $stats['taxa_risco'] >= 50 ? 'danger' : ($stats['taxa_risco'] >= 30 ? 'warning' : 'success'); ?>">
                                    <h3><?php echo number_format($stats['taxa_risco'], 1); ?>%</h3>
                                    <p class="text-muted mb-0">Taxa de Risco</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Problemas Identificados -->
        <?php if (!empty($stats['problemas_debito_pix'])): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5><i class="bi bi-exclamation-triangle"></i> Problemas Identificados (Débito/PIX)</h5>
                    </div>
                    <div class="card-body">
                        <p><strong><?php echo count($stats['problemas_debito_pix']); ?> título(s)</strong> vendido(s) em Débito ou PIX que foram bloqueados ou tiveram apenas 1-2 parcelas pagas.</p>

                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Número Título</th>
                                        <th>Cliente</th>
                                        <th>Bandeira</th>
                                        <th>Status</th>
                                        <th>Parcelas Pagas</th>
                                        <th>Data Venda</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['problemas_debito_pix'] as $problema): ?>
                                        <tr>
                                            <td><?php echo sanitize($problema['numero_titulo']); ?></td>
                                            <td><?php echo sanitize($problema['nome_titular']); ?></td>
                                            <td>
                                                <?php echo sanitize($problema['bandeira']); ?>
                                                <?php if (stripos($problema['bandeira'], 'DEBITO') !== false): ?>
                                                    <span class="badge bg-danger">DÉBITO</span>
                                                <?php elseif (stripos($problema['bandeira'], 'PIX') !== false): ?>
                                                    <span class="badge bg-info">PIX</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo sanitize($problema['status_titulo']); ?></td>
                                            <td><?php echo $problema['qtd_parcelas_pagas']; ?>/<?php echo $problema['quantidade_parcelas_venda']; ?></td>
                                            <td><?php echo formatDate($problema['data_primeira_venda']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Gerar Análise com IA -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="bi bi-robot"></i> Análise Inteligente com IA</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!$analiseIA): ?>
                            <p>Clique no botão abaixo para gerar uma análise completa com sugestões de como abordar este consultor profissionalmente e evitar novos casos.</p>

                            <form method="POST">
                                <button type="submit" name="gerar_analise" class="btn btn-primary btn-lg">
                                    <i class="bi bi-robot"></i> Gerar Análise com IA
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="markdown-content">
                                <?php
                                // Converter markdown simples para HTML
                                $html = str_replace('## ', '<h2>', $analiseIA);
                                $html = str_replace("\n\n", '</p><p>', $html);
                                $html = str_replace('**', '<strong>', $html);
                                $html = str_replace('</strong>', '</strong>', $html);
                                echo '<div>' . nl2br($html) . '</div>';
                                ?>
                            </div>

                            <hr class="my-4">

                            <form method="POST">
                                <button type="submit" name="gerar_analise" class="btn btn-outline-primary">
                                    <i class="bi bi-arrow-repeat"></i> Gerar Nova Análise
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
