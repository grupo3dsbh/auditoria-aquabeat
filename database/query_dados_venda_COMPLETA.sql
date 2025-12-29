/*
================================================================================
QUERY PARA EXPORTAÇÃO DE DADOS COM HISTÓRICO COMPLETO DE CARTÕES
================================================================================
Para usar com a nova estrutura de tabela separada titulo_cartoes

IMPORTANTE: Esta query concatena TODOS os cartões usados com pipe (|)
- Cada título = 1 registro
- Múltiplos cartões separados por " | "
- Múltiplas bandeiras separadas por " | "
- A lógica de inadimplência será calculada no PHP após importação
- Filtro: Apenas produtos que são COTAS (contém "Sócio" no nome)
*/

USE [MultiClubes]
GO

-- Parâmetros
DECLARE @DataInicio DATETIME = '2024-11-01T00:00:00';
DECLARE @DataFim DATETIME = GETDATE();
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
    WHERE [DataCadastro] BETWEEN @DataInicio AND @DataFim
),
-- CTE para primeiro produto
PrimeiroProduto AS (
    SELECT
        NumeroTitulo,
        NomeProduto AS NomeProdutoOriginal,
        DataVenda AS DataPrimeiraVenda,
        ROW_NUMBER() OVER (PARTITION BY NumeroTitulo ORDER BY DataVenda ASC) AS rn
    FROM [dbo].[PaidTitles]
    WHERE [DataCadastro] BETWEEN @DataInicio AND @DataFim
),
-- CTE para TODOS os cartões (não apenas o último!)
TodosCartoes AS (
    SELECT
        NumeroTitulo,
        -- Concatenar TODOS os cartões únicos com " | "
        STRING_AGG(NumeroCartao, ' | ') WITHIN GROUP (ORDER BY NumeroCartao) AS TodosNumeroCartao,
        -- Concatenar TODAS as bandeiras únicas com " | "
        STRING_AGG(Bandeira, ' | ') WITHIN GROUP (ORDER BY Bandeira) AS TodasBandeiras,
        -- Tipo de pagamento (pegar o mais recente)
        MAX(PaymentType) AS TipoPagamentoCartao
    FROM (
        SELECT DISTINCT
            NumeroTitulo,
            NumeroCartao,
            Bandeira,
            PaymentType
        FROM [dbo].[PaidTitles]
        WHERE NumeroCartao IS NOT NULL
          AND [DataCadastro] BETWEEN @DataInicio AND @DataFim
    ) AS CartoesUnicos
    GROUP BY NumeroTitulo
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

    -- TODOS OS CARTÕES (concatenados com " | ")
    tc.TodosNumeroCartao AS NumeroCartao,
    tc.TodasBandeiras AS Bandeira,
    tc.TipoPagamentoCartao,

    -- Parcelas (DADOS BRUTOS - sem cálculo de inadimplência)
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
LEFT JOIN TodosCartoes tc ON pt.NumeroTitulo = tc.NumeroTitulo
WHERE pt.[DataCadastro] BETWEEN @DataInicio AND @DataFim
  AND up.StatusTitulo IN ('Ativo', 'Bloqueado', 'Cancelado', 'Vencido')
  -- FILTRO IMPORTANTE: Apenas produtos que são COTAS (contém "Sócio")
  AND up.NomeProduto LIKE '%Sócio%'
  -- Filtro por prefixo do número do título
  AND (pt.[NumeroTitulo] LIKE '%SBF%' OR pt.[NumeroTitulo] LIKE '%SFA%' OR pt.[NumeroTitulo] LIKE '%SAF%')

GROUP BY
    pt.NumeroTitulo,
    pp.NomeProdutoOriginal,
    up.NomeProduto,
    up.Categoria,
    up.StatusTitulo,
    pp.DataPrimeiraVenda,
    tc.TodosNumeroCartao,
    tc.TodasBandeiras,
    tc.TipoPagamentoCartao

ORDER BY
    pp.DataPrimeiraVenda DESC,
    pt.NumeroTitulo;

GO
