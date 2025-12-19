-- =====================================================
-- SISTEMA DE ANÁLISE DE INADIMPLÊNCIA - AQUABEAT
-- Query Consolidada para Auditoria Completa
-- =====================================================

USE [MultiClubes]
GO

-- Parâmetros Centralizados
DECLARE @DataInicio DATETIME = '2024-11-01T00:00:00';
DECLARE @DataFim DATETIME = GETDATE();
DECLARE @ProdutoFiltro VARCHAR(50) = '%Sócio%';
DECLARE @Periodo3Meses DATETIME = DATEADD(MONTH, -3, GETDATE());
DECLARE @Periodo6Meses DATETIME = DATEADD(MONTH, -6, GETDATE());
DECLARE @Periodo1Ano DATETIME = DATEADD(YEAR, -1, GETDATE());

-- =====================================================
-- PARTE 1: ANÁLISE DE TÍTULOS E PRODUTOS
-- =====================================================

IF OBJECT_ID('tempdb..#TitulosCompletos') IS NOT NULL DROP TABLE #TitulosCompletos;

WITH UltimoProduto AS (
    SELECT
        NumeroTitulo,
        NomeProduto,
        Categoria,
        StatusTitulo,
        ROW_NUMBER() OVER (PARTITION BY NumeroTitulo ORDER BY DataVenda DESC) AS rn
    FROM [dbo].[PaidTitles]
    WHERE [NomeProduto] LIKE @ProdutoFiltro
      AND [DataCadastro] BETWEEN @DataInicio AND @DataFim
),
PrimeiroProduto AS (
    SELECT
        NumeroTitulo,
        NomeProduto AS NomeProdutoOriginal,
        Categoria AS CategoriaOriginal,
        DataVenda AS DataPrimeiraVenda,
        ROW_NUMBER() OVER (PARTITION BY NumeroTitulo ORDER BY DataVenda ASC) AS rn
    FROM [dbo].[PaidTitles]
    WHERE [NomeProduto] LIKE @ProdutoFiltro
      AND [DataCadastro] BETWEEN @DataInicio AND @DataFim
)
SELECT
    pt.NumeroTitulo,
    pp.NomeProdutoOriginal,
    up.NomeProduto AS NomeProdutoAtual,
    CASE
        WHEN pp.NomeProdutoOriginal <> up.NomeProduto THEN 'Sim'
        ELSE 'Não'
    END AS AlterouVagas,
    up.Categoria,
    up.StatusTitulo,
    MAX(pt.DataCadastro) AS DataCadastro,
    pp.DataPrimeiraVenda,
    MAX(pt.DataVenda) AS DataUltimaVenda,
    MAX(pt.OrigemVenda) AS OrigemVenda,
    MAX(pt.ResidentialPhone) AS TelefoneResidencial,
    MAX(pt.Promotor) AS Promotor,
    MAX(pt.Gerente) AS Gerente,
    MAX(pt.NomeTitular) AS NomeTitular,
    MAX(pt.DocumentoTitular) AS DocumentoTitular,
    MAX(pt.QuantidadeParcelasVenda) AS QuantidadeParcelasVenda,

    -- Informações do Cartão
    MAX(pt.NumeroCartao) AS NumeroCartao,
    MAX(pt.Bandeira) AS Bandeira,
    MAX(pt.PaymentType) AS TipoPagamentoCartao,

    -- Parcelas com pagamento
    STRING_AGG(
        CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
        THEN CAST(pt.NumeroParcelaVenda AS VARCHAR(5)) END,
        ','
    ) WITHIN GROUP (ORDER BY pt.NumeroParcelaVenda) AS ParcelasPagas,

    STRING_AGG(
        CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
        THEN CAST(COALESCE(pt.ValorPago, pt.Total) AS VARCHAR(20)) END,
        ','
    ) WITHIN GROUP (ORDER BY pt.NumeroParcelaVenda) AS ValoresPagos,

    MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN pt.PaymentMode END) AS FormaPagamento,

    -- Contadores de parcelas
    SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) AS QtdParcelasPagas,

    -- Classificação do tipo de pagamento
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 'À Vista'
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 'Cartão Parcelado'
        ELSE 'Recorrente'
    END AS TipoPagamento,

    -- Valores
    MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN COALESCE(pt.ValorPago, pt.Total) END) AS ValorParcela,

    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN COALESCE(pt.ValorPago, pt.Total) END)
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN COALESCE(pt.ValorPago, pt.Total) END)
        ELSE MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN COALESCE(pt.ValorPago, pt.Total) END) * MAX(pt.QuantidadeParcelasVenda)
    END AS ValorTotalPlano,

    SUM(COALESCE(pt.ValorPago, pt.Total, 0)) AS TotalPago,

    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE (MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN COALESCE(pt.ValorPago, pt.Total) END) * MAX(pt.QuantidadeParcelasVenda)) - SUM(COALESCE(pt.ValorPago, pt.Total, 0))
    END AS SaldoRestante,

    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE MAX(pt.QuantidadeParcelasVenda) - SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END)
    END AS ParcelasRestantes,

    -- Classificação de Inadimplência
    CASE
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = 1
        AND MAX(pt.QuantidadeParcelasVenda) > 1
        THEN 'INADIMPLENTE - Apenas 1ª Parcela'

        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = 2
        AND MAX(pt.QuantidadeParcelasVenda) > 2
        THEN 'INADIMPLENTE - Apenas 2 Parcelas'

        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) < MAX(pt.QuantidadeParcelasVenda) * 0.5
        AND MAX(pt.QuantidadeParcelasVenda) > 1
        THEN 'INADIMPLENTE - Menos de 50%'

        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) < MAX(pt.QuantidadeParcelasVenda)
        AND MAX(pt.QuantidadeParcelasVenda) > 1
        THEN 'PARCIALMENTE ADIMPLENTE'

        ELSE 'ADIMPLENTE'
    END AS StatusInadimplencia,

    -- Período da inadimplência
    CASE
        WHEN pp.DataPrimeiraVenda >= @Periodo3Meses THEN '0-3 meses'
        WHEN pp.DataPrimeiraVenda >= @Periodo6Meses THEN '3-6 meses'
        WHEN pp.DataPrimeiraVenda >= @Periodo1Ano THEN '6-12 meses'
        ELSE 'Mais de 1 ano'
    END AS PeriodoTitulo,

    DATEDIFF(DAY, pp.DataPrimeiraVenda, GETDATE()) AS DiasDesdeVenda

INTO #TitulosCompletos
FROM [dbo].[PaidTitles] pt
INNER JOIN UltimoProduto up ON pt.NumeroTitulo = up.NumeroTitulo AND up.rn = 1
INNER JOIN PrimeiroProduto pp ON pt.NumeroTitulo = pp.NumeroTitulo AND pp.rn = 1
WHERE pt.[NomeProduto] LIKE @ProdutoFiltro
  AND pt.[DataCadastro] BETWEEN @DataInicio AND @DataFim
  AND up.StatusTitulo IN ('Ativo', 'Bloqueado', 'Cancelado', 'Vencido')
GROUP BY
    pt.NumeroTitulo,
    pp.NomeProdutoOriginal,
    up.NomeProduto,
    up.Categoria,
    up.StatusTitulo,
    pp.DataPrimeiraVenda;

-- =====================================================
-- PARTE 2: ANÁLISE DE CARTÕES COMPARTILHADOS
-- =====================================================

IF OBJECT_ID('tempdb..#AnaliseCartoes') IS NOT NULL DROP TABLE #AnaliseCartoes;

SELECT
    NumeroCartao,
    Bandeira,
    TipoPagamentoCartao,
    COUNT(DISTINCT NumeroTitulo) AS TotalTitulosNoCartao,
    COUNT(DISTINCT DocumentoTitular) AS TotalDocumentosNoCartao,
    COUNT(DISTINCT Promotor) AS TotalPromotoresNoCartao,
    COUNT(DISTINCT CASE WHEN StatusTitulo = 'Ativo' THEN NumeroTitulo END) AS TitulosAtivos,
    COUNT(DISTINCT CASE WHEN StatusTitulo = 'Bloqueado' THEN NumeroTitulo END) AS TitulosBloqueados,
    COUNT(DISTINCT CASE WHEN StatusTitulo = 'Cancelado' THEN NumeroTitulo END) AS TitulosCancelados,
    COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) AS TitulosInadimplentes,

    CAST(COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) * 100.0 /
         NULLIF(COUNT(DISTINCT NumeroTitulo), 0) AS DECIMAL(5,2)) AS TaxaInadimplenciaCartao,

    MIN(DataPrimeiraVenda) AS PrimeiraVendaCartao,
    MAX(DataUltimaVenda) AS UltimaVendaCartao,

    CASE
        WHEN COUNT(DISTINCT DocumentoTitular) >= 10
        AND COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) * 100.0 /
            NULLIF(COUNT(DISTINCT NumeroTitulo), 0) >= 30
        THEN 'FRAUDE PROVÁVEL'

        WHEN COUNT(DISTINCT DocumentoTitular) >= 10
        OR COUNT(DISTINCT CASE WHEN StatusTitulo IN ('Bloqueado', 'Cancelado') THEN NumeroTitulo END) * 100.0 /
           NULLIF(COUNT(DISTINCT NumeroTitulo), 0) >= 40
        THEN 'ALTO RISCO'

        WHEN COUNT(DISTINCT DocumentoTitular) >= 5
        OR COUNT(DISTINCT CASE WHEN StatusTitulo IN ('Bloqueado', 'Cancelado') THEN NumeroTitulo END) * 100.0 /
           NULLIF(COUNT(DISTINCT NumeroTitulo), 0) >= 25
        THEN 'MÉDIO RISCO'

        ELSE 'BAIXO RISCO'
    END AS NivelRiscoCartao

INTO #AnaliseCartoes
FROM #TitulosCompletos
WHERE NumeroCartao IS NOT NULL AND NumeroCartao <> ''
GROUP BY NumeroCartao, Bandeira, TipoPagamentoCartao
HAVING COUNT(DISTINCT NumeroTitulo) >= 2;

-- =====================================================
-- PARTE 3: ANÁLISE POR CONSULTOR
-- =====================================================

IF OBJECT_ID('tempdb..#AnaliseConsultores') IS NOT NULL DROP TABLE #AnaliseConsultores;

SELECT
    Promotor,
    COUNT(DISTINCT NumeroTitulo) AS TotalVendas,
    COUNT(DISTINCT DocumentoTitular) AS TotalClientes,

    -- Por Status
    COUNT(DISTINCT CASE WHEN StatusTitulo = 'Ativo' THEN NumeroTitulo END) AS VendasAtivas,
    COUNT(DISTINCT CASE WHEN StatusTitulo = 'Bloqueado' THEN NumeroTitulo END) AS VendasBloqueadas,
    COUNT(DISTINCT CASE WHEN StatusTitulo = 'Cancelado' THEN NumeroTitulo END) AS VendasCanceladas,

    -- Por Inadimplência
    COUNT(DISTINCT CASE WHEN StatusInadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela' THEN NumeroTitulo END) AS Inadimplentes1Parcela,
    COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) AS TotalInadimplentes,
    COUNT(DISTINCT CASE WHEN StatusInadimplencia = 'ADIMPLENTE' THEN NumeroTitulo END) AS TotalAdimplentes,

    -- Por Período
    COUNT(DISTINCT CASE WHEN PeriodoTitulo = '0-3 meses' AND StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) AS Inadimplentes3Meses,
    COUNT(DISTINCT CASE WHEN PeriodoTitulo = '3-6 meses' AND StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) AS Inadimplentes6Meses,
    COUNT(DISTINCT CASE WHEN PeriodoTitulo = '6-12 meses' AND StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) AS Inadimplentes1Ano,

    -- Taxas
    CAST(COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) * 100.0 /
         NULLIF(COUNT(DISTINCT NumeroTitulo), 0) AS DECIMAL(5,2)) AS TaxaInadimplenciaGeral,

    CAST(COUNT(DISTINCT CASE WHEN PeriodoTitulo = '0-3 meses' AND StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) * 100.0 /
         NULLIF(COUNT(DISTINCT CASE WHEN PeriodoTitulo = '0-3 meses' THEN NumeroTitulo END), 0) AS DECIMAL(5,2)) AS TaxaInadimplencia3Meses,

    CAST(COUNT(DISTINCT CASE WHEN PeriodoTitulo = '3-6 meses' AND StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) * 100.0 /
         NULLIF(COUNT(DISTINCT CASE WHEN PeriodoTitulo = '3-6 meses' THEN NumeroTitulo END), 0) AS DECIMAL(5,2)) AS TaxaInadimplencia6Meses,

    CAST(COUNT(DISTINCT CASE WHEN PeriodoTitulo = '6-12 meses' AND StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) * 100.0 /
         NULLIF(COUNT(DISTINCT CASE WHEN PeriodoTitulo = '6-12 meses' THEN NumeroTitulo END), 0) AS DECIMAL(5,2)) AS TaxaInadimplencia1Ano,

    -- Valores
    SUM(TotalPago) AS ValorTotalRecebido,
    SUM(SaldoRestante) AS ValorTotalPerdido,

    -- Classificação
    CASE
        WHEN COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) * 100.0 /
             NULLIF(COUNT(DISTINCT NumeroTitulo), 0) >= 50 THEN 'CRÍTICO'
        WHEN COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) * 100.0 /
             NULLIF(COUNT(DISTINCT NumeroTitulo), 0) >= 30 THEN 'ALTO'
        WHEN COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) * 100.0 /
             NULLIF(COUNT(DISTINCT NumeroTitulo), 0) >= 15 THEN 'MÉDIO'
        ELSE 'BAIXO'
    END AS NivelRiscoConsultor,

    MIN(DataPrimeiraVenda) AS PrimeiraVenda,
    MAX(DataUltimaVenda) AS UltimaVenda

INTO #AnaliseConsultores
FROM #TitulosCompletos
WHERE Promotor IS NOT NULL AND Promotor <> ''
GROUP BY Promotor;

-- =====================================================
-- RESULTADO FINAL: DATASET COMPLETO PARA EXPORTAÇÃO
-- =====================================================

SELECT
    -- Dados do Título
    tc.NumeroTitulo,
    tc.NomeProdutoOriginal,
    tc.NomeProdutoAtual,
    tc.AlterouVagas,
    tc.Categoria,
    tc.StatusTitulo,
    tc.StatusInadimplencia,
    tc.PeriodoTitulo,
    tc.DiasDesdeVenda,

    -- Dados do Cliente
    tc.NomeTitular,
    tc.DocumentoTitular,
    tc.TelefoneResidencial,

    -- Dados da Venda
    tc.DataCadastro,
    tc.DataPrimeiraVenda,
    tc.DataUltimaVenda,
    tc.OrigemVenda,
    tc.Promotor,
    tc.Gerente,

    -- Dados de Pagamento
    tc.QuantidadeParcelasVenda,
    tc.QtdParcelasPagas,
    tc.ParcelasRestantes,
    tc.ParcelasPagas AS ListaParcelasPagas,
    tc.ValoresPagos AS ListaValoresPagos,
    tc.FormaPagamento,
    tc.TipoPagamento,
    tc.ValorParcela,
    tc.ValorTotalPlano,
    tc.TotalPago,
    tc.SaldoRestante,

    -- Dados do Cartão
    tc.NumeroCartao,
    tc.Bandeira,
    tc.TipoPagamentoCartao,

    -- Análise do Cartão
    COALESCE(ac.TotalTitulosNoCartao, 1) AS TotalTitulosNoCartao,
    COALESCE(ac.TotalDocumentosNoCartao, 1) AS TotalDocumentosNoCartao,
    COALESCE(ac.TotalPromotoresNoCartao, 1) AS TotalPromotoresNoCartao,
    COALESCE(ac.TitulosInadimplentesNoCartao, 0) AS TitulosInadimplentesNoCartao,
    COALESCE(ac.TaxaInadimplenciaCartao, 0) AS TaxaInadimplenciaCartao,
    COALESCE(ac.NivelRiscoCartao, 'BAIXO RISCO') AS NivelRiscoCartao,

    -- Análise do Consultor
    cons.TotalVendas AS VendasConsultor,
    cons.TotalClientes AS ClientesConsultor,
    cons.Inadimplentes1Parcela AS Inadimplentes1ParcelaConsultor,
    cons.TotalInadimplentes AS TotalInadimplentesConsultor,
    cons.Inadimplentes3Meses AS Inadimplentes3MesesConsultor,
    cons.Inadimplentes6Meses AS Inadimplentes6MesesConsultor,
    cons.Inadimplentes1Ano AS Inadimplentes1AnoConsultor,
    cons.TaxaInadimplenciaGeral AS TaxaInadimplenciaConsultor,
    cons.TaxaInadimplencia3Meses AS TaxaInadimplencia3MesesConsultor,
    cons.TaxaInadimplencia6Meses AS TaxaInadimplencia6MesesConsultor,
    cons.TaxaInadimplencia1Ano AS TaxaInadimplencia1AnoConsultor,
    cons.ValorTotalRecebido AS ValorRecebidoConsultor,
    cons.ValorTotalPerdido AS ValorPerdidoConsultor,
    cons.NivelRiscoConsultor,

    -- Indicadores de Alerta
    CASE
        WHEN tc.StatusInadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela' THEN 'SIM'
        ELSE 'NÃO'
    END AS AlertaApenas1Parcela,

    CASE
        WHEN COALESCE(ac.NivelRiscoCartao, 'BAIXO RISCO') IN ('FRAUDE PROVÁVEL', 'ALTO RISCO') THEN 'SIM'
        ELSE 'NÃO'
    END AS AlertaCartaoRisco,

    CASE
        WHEN cons.NivelRiscoConsultor IN ('CRÍTICO', 'ALTO') THEN 'SIM'
        ELSE 'NÃO'
    END AS AlertaConsultorRisco,

    CASE
        WHEN tc.AlterouVagas = 'Sim' AND tc.StatusInadimplencia LIKE 'INADIMPLENTE%' THEN 'SIM'
        ELSE 'NÃO'
    END AS AlertaAlterouVagasInadimplente,

    -- Score Geral de Risco (0-100)
    CAST(
        (CASE WHEN tc.StatusInadimplencia LIKE 'INADIMPLENTE%' THEN 30 ELSE 0 END) +
        (CASE WHEN tc.StatusTitulo IN ('Bloqueado', 'Cancelado') THEN 20 ELSE 0 END) +
        (CASE WHEN COALESCE(ac.TaxaInadimplenciaCartao, 0) >= 50 THEN 25
              WHEN COALESCE(ac.TaxaInadimplenciaCartao, 0) >= 30 THEN 15
              WHEN COALESCE(ac.TaxaInadimplenciaCartao, 0) >= 15 THEN 5 ELSE 0 END) +
        (CASE WHEN cons.TaxaInadimplenciaGeral >= 50 THEN 25
              WHEN cons.TaxaInadimplenciaGeral >= 30 THEN 15
              WHEN cons.TaxaInadimplenciaGeral >= 15 THEN 5 ELSE 0 END)
    AS INT) AS ScoreRiscoGeral

FROM #TitulosCompletos tc
LEFT JOIN #AnaliseCartoes ac ON tc.NumeroCartao = ac.NumeroCartao
LEFT JOIN #AnaliseConsultores cons ON tc.Promotor = cons.Promotor
ORDER BY
    ScoreRiscoGeral DESC,
    tc.DataPrimeiraVenda DESC,
    tc.Promotor,
    tc.NumeroTitulo;

-- =====================================================
-- ESTATÍSTICAS RESUMIDAS PARA DASHBOARD
-- =====================================================

-- Resumo Geral
SELECT
    'RESUMO GERAL' AS TipoAnalise,
    COUNT(DISTINCT NumeroTitulo) AS TotalTitulos,
    COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) AS TotalInadimplentes,
    CAST(COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) * 100.0 /
         NULLIF(COUNT(DISTINCT NumeroTitulo), 0) AS DECIMAL(5,2)) AS TaxaInadimplenciaGeral,
    COUNT(DISTINCT CASE WHEN StatusInadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela' THEN NumeroTitulo END) AS TitulosApenas1Parcela,
    SUM(TotalPago) AS ValorTotalRecebido,
    SUM(SaldoRestante) AS ValorTotalPerdido
FROM #TitulosCompletos

UNION ALL

-- Por Período
SELECT
    'POR PERÍODO - ' + PeriodoTitulo AS TipoAnalise,
    COUNT(DISTINCT NumeroTitulo) AS TotalTitulos,
    COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) AS TotalInadimplentes,
    CAST(COUNT(DISTINCT CASE WHEN StatusInadimplencia LIKE 'INADIMPLENTE%' THEN NumeroTitulo END) * 100.0 /
         NULLIF(COUNT(DISTINCT NumeroTitulo), 0) AS DECIMAL(5,2)) AS TaxaInadimplenciaGeral,
    COUNT(DISTINCT CASE WHEN StatusInadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela' THEN NumeroTitulo END) AS TitulosApenas1Parcela,
    SUM(TotalPago) AS ValorTotalRecebido,
    SUM(SaldoRestante) AS ValorTotalPerdido
FROM #TitulosCompletos
GROUP BY PeriodoTitulo;

-- Top 20 Consultores com Maior Inadimplência
SELECT TOP 20
    Promotor,
    TotalVendas,
    TotalInadimplentes,
    TaxaInadimplenciaGeral,
    Inadimplentes1Parcela,
    TaxaInadimplencia3Meses,
    TaxaInadimplencia6Meses,
    TaxaInadimplencia1Ano,
    ValorTotalPerdido,
    NivelRiscoConsultor
FROM #AnaliseConsultores
ORDER BY TaxaInadimplenciaGeral DESC, TotalInadimplentes DESC;

-- Top 20 Cartões com Maior Risco
SELECT TOP 20
    NumeroCartao,
    Bandeira,
    TotalTitulosNoCartao,
    TotalDocumentosNoCartao,
    TotalPromotoresNoCartao,
    TitulosInadimplentes AS TitulosInadimplentesNoCartao,
    TaxaInadimplenciaCartao,
    NivelRiscoCartao,
    PrimeiraVendaCartao,
    UltimaVendaCartao
FROM #AnaliseCartoes
ORDER BY
    CASE NivelRiscoCartao
        WHEN 'FRAUDE PROVÁVEL' THEN 1
        WHEN 'ALTO RISCO' THEN 2
        WHEN 'MÉDIO RISCO' THEN 3
        ELSE 4
    END,
    TaxaInadimplenciaCartao DESC;

-- Limpeza
DROP TABLE #TitulosCompletos;
DROP TABLE #AnaliseCartoes;
DROP TABLE #AnaliseConsultores;

GO
