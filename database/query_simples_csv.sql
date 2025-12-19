-- =====================================================
-- QUERY SIMPLES PARA EXPORTAÇÃO CSV
-- Apenas dados principais - Agregações são feitas no PHP
-- =====================================================

USE [MultiClubes]
GO

-- Parâmetros
DECLARE @DataInicio DATETIME = '2024-11-01T00:00:00';
DECLARE @DataFim DATETIME = GETDATE();
DECLARE @ProdutoFiltro VARCHAR(50) = '%Sócio%';
DECLARE @Periodo3Meses DATETIME = DATEADD(MONTH, -3, GETDATE());
DECLARE @Periodo6Meses DATETIME = DATEADD(MONTH, -6, GETDATE());
DECLARE @Periodo1Ano DATETIME = DATEADD(YEAR, -1, GETDATE());

-- CTE para produto mais recente
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
-- CTE para primeiro produto
PrimeiroProduto AS (
    SELECT
        NumeroTitulo,
        NomeProduto AS NomeProdutoOriginal,
        DataVenda AS DataPrimeiraVenda,
        ROW_NUMBER() OVER (PARTITION BY NumeroTitulo ORDER BY DataVenda ASC) AS rn
    FROM [dbo].[PaidTitles]
    WHERE [NomeProduto] LIKE @ProdutoFiltro
      AND [DataCadastro] BETWEEN @DataInicio AND @DataFim
)
-- SELECT principal
SELECT
    -- Identificação do Título
    pt.NumeroTitulo,
    pp.NomeProdutoOriginal,
    up.NomeProduto AS NomeProdutoAtual,
    CASE WHEN pp.NomeProdutoOriginal <> up.NomeProduto THEN 'Sim' ELSE 'Não' END AS AlterouVagas,
    up.Categoria,
    up.StatusTitulo,

    -- Datas
    MAX(pt.DataCadastro) AS DataCadastro,
    pp.DataPrimeiraVenda,
    MAX(pt.DataVenda) AS DataUltimaVenda,

    -- Cliente
    MAX(pt.NomeTitular) AS NomeTitular,
    MAX(pt.DocumentoTitular) AS DocumentoTitular,
    MAX(pt.ResidentialPhone) AS TelefoneResidencial,

    -- Venda
    MAX(pt.OrigemVenda) AS OrigemVenda,
    MAX(pt.Promotor) AS Promotor,
    MAX(pt.Gerente) AS Gerente,

    -- Cartão
    MAX(pt.NumeroCartao) AS NumeroCartao,
    MAX(pt.Bandeira) AS Bandeira,
    MAX(pt.PaymentType) AS TipoPagamentoCartao,

    -- Parcelas
    MAX(pt.QuantidadeParcelasVenda) AS QuantidadeParcelasVenda,
    SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) AS QtdParcelasPagas,

    -- Valores
    MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN COALESCE(pt.ValorPago, pt.Total) END) AS ValorParcela,
    SUM(COALESCE(pt.ValorPago, pt.Total, 0)) AS TotalPago,

    -- Saldo Restante
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE (MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN COALESCE(pt.ValorPago, pt.Total) END) * MAX(pt.QuantidadeParcelasVenda))
             - SUM(COALESCE(pt.ValorPago, pt.Total, 0))
    END AS SaldoRestante,

    -- Parcelas Restantes
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE MAX(pt.QuantidadeParcelasVenda) - SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END)
    END AS ParcelasRestantes,

    -- Forma de Pagamento
    MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN pt.PaymentMode END) AS FormaPagamento,

    -- Tipo de Pagamento
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 'À Vista'
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 'Cartão Parcelado'
        ELSE 'Recorrente'
    END AS TipoPagamento,

    -- Status de Inadimplência
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

    -- Período
    CASE
        WHEN pp.DataPrimeiraVenda >= @Periodo3Meses THEN '0-3 meses'
        WHEN pp.DataPrimeiraVenda >= @Periodo6Meses THEN '3-6 meses'
        WHEN pp.DataPrimeiraVenda >= @Periodo1Ano THEN '6-12 meses'
        ELSE 'Mais de 1 ano'
    END AS PeriodoTitulo,

    -- Dias desde venda
    DATEDIFF(DAY, pp.DataPrimeiraVenda, GETDATE()) AS DiasDesdeVenda,

    -- Lista de parcelas pagas (para referência)
    STRING_AGG(
        CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
        THEN CAST(pt.NumeroParcelaVenda AS VARCHAR(5)) END,
        ','
    ) WITHIN GROUP (ORDER BY pt.NumeroParcelaVenda) AS ListaParcelasPagas,

    -- Lista de valores pagos
    STRING_AGG(
        CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
        THEN CAST(COALESCE(pt.ValorPago, pt.Total) AS VARCHAR(20)) END,
        ','
    ) WITHIN GROUP (ORDER BY pt.NumeroParcelaVenda) AS ListaValoresPagos

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
    pp.DataPrimeiraVenda
ORDER BY
    pp.DataPrimeiraVenda DESC,
    pt.NumeroTitulo;

GO

-- =====================================================
-- INSTRUÇÕES PARA EXPORTAR:
-- =====================================================
-- 1. Execute a query acima (F5)
-- 2. Clique com botão direito nos resultados
-- 3. "Save Results As..."
-- 4. Salvar como: auditoria_titulos.csv
-- 5. Encoding: UTF-8 (importante!)
-- 6. Fazer upload no sistema PHP
-- =====================================================
