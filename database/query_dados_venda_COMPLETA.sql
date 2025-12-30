/*
================================================================================
QUERY PARA EXPORTAÇÃO DE DADOS COM HISTÓRICO COMPLETO DE CARTÕES - CORRIGIDA
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

-- SELECT principal com todas as agregações
SELECT
    -- Identificação do Título
    pt.NumeroTitulo,

    -- Produto Original (primeira venda com "Sócio")
    (SELECT TOP 1 NomeProduto
     FROM [dbo].[PaidTitles]
     WHERE NumeroTitulo = pt.NumeroTitulo
       AND NomeProduto LIKE '%Sócio%'
     ORDER BY DataVenda ASC) AS NomeProdutoOriginal,

    -- Produto Atual = CATEGORIA (ignora Pulseira Troca, Mudança de categoria, etc)
    (SELECT TOP 1 Categoria
     FROM [dbo].[PaidTitles]
     WHERE NumeroTitulo = pt.NumeroTitulo
     ORDER BY DataVenda DESC) AS NomeProdutoAtual,

    -- Alterou Vagas? (compara Produto Original com Categoria Atual)
    CASE WHEN
        (SELECT TOP 1 NomeProduto FROM [dbo].[PaidTitles] WHERE NumeroTitulo = pt.NumeroTitulo AND NomeProduto LIKE '%Sócio%' ORDER BY DataVenda ASC) <>
        (SELECT TOP 1 Categoria FROM [dbo].[PaidTitles] WHERE NumeroTitulo = pt.NumeroTitulo ORDER BY DataVenda DESC)
    THEN 'Sim' ELSE 'Não' END AS AlterouVagas,

    -- Categoria
    (SELECT TOP 1 Categoria
     FROM [dbo].[PaidTitles]
     WHERE NumeroTitulo = pt.NumeroTitulo
     ORDER BY DataVenda DESC) AS Categoria,

    -- Status
    (SELECT TOP 1 StatusTitulo
     FROM [dbo].[PaidTitles]
     WHERE NumeroTitulo = pt.NumeroTitulo
     ORDER BY DataVenda DESC) AS StatusTitulo,

    -- Datas
    MAX(pt.DataCadastro) AS DataCadastro,
    MIN(pt.DataVenda) AS DataPrimeiraVenda,
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
    STUFF((
        SELECT DISTINCT ' | ' + NumeroCartao
        FROM [dbo].[PaidTitles]
        WHERE NumeroTitulo = pt.NumeroTitulo
          AND NumeroCartao IS NOT NULL
        FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 3, '') AS NumeroCartao,

    -- TODAS AS BANDEIRAS (concatenadas com " | ")
    STUFF((
        SELECT DISTINCT ' | ' + Bandeira
        FROM [dbo].[PaidTitles]
        WHERE NumeroTitulo = pt.NumeroTitulo
          AND Bandeira IS NOT NULL
        FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 3, '') AS Bandeira,

    -- Tipo de pagamento
    MAX(pt.PaymentType) AS TipoPagamentoCartao,

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
        WHEN MIN(pt.DataVenda) >= @Periodo3Meses THEN '0-3 meses'
        WHEN MIN(pt.DataVenda) >= @Periodo6Meses THEN '3-6 meses'
        WHEN MIN(pt.DataVenda) >= @Periodo1Ano THEN '6-12 meses'
        ELSE 'Mais de 1 ano'
    END AS PeriodoTitulo,

    -- Dias desde venda
    DATEDIFF(DAY, MIN(pt.DataVenda), GETDATE()) AS DiasDesdeVenda,

    -- Lista de parcelas pagas (para referência)
    STRING_AGG(
        CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
        THEN CAST(pt.NumeroParcelaVenda AS VARCHAR(5)) END,
        ','
    ) AS ListaParcelasPagas,

    -- Lista de valores pagos
    STRING_AGG(
        CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
        THEN CAST(COALESCE(pt.ValorPago, pt.Total) AS VARCHAR(20)) END,
        ','
    ) AS ListaValoresPagos

FROM [dbo].[PaidTitles] pt
WHERE pt.[DataCadastro] BETWEEN @DataInicio AND @DataFim
  -- FILTRO 1: Apenas prefixos SFA, SAF, SBF
  AND (
      pt.[NumeroTitulo] LIKE 'SFA-%'
      OR pt.[NumeroTitulo] LIKE 'SAF-%'
      OR pt.[NumeroTitulo] LIKE 'SBF-%'
  )
  -- FILTRO 2: Produto ORIGINAL deve conter "Sócio"
  AND (SELECT TOP 1 NomeProduto
       FROM [dbo].[PaidTitles]
       WHERE NumeroTitulo = pt.NumeroTitulo
       ORDER BY DataVenda ASC) LIKE '%Sócio%'
  -- FILTRO 3: CATEGORIA deve conter "Sócio" (ignora Pulseira Troca, Consumo Crédito, etc)
  AND (SELECT TOP 1 Categoria
       FROM [dbo].[PaidTitles]
       WHERE NumeroTitulo = pt.NumeroTitulo
       ORDER BY DataVenda DESC) LIKE '%Sócio%'
  -- FILTRO 4: Status válidos (usando última venda)
  AND (SELECT TOP 1 StatusTitulo
       FROM [dbo].[PaidTitles]
       WHERE NumeroTitulo = pt.NumeroTitulo
       ORDER BY DataVenda DESC) IN ('Ativo', 'Bloqueado', 'Cancelado', 'Vencido')

GROUP BY
    pt.NumeroTitulo

ORDER BY
    MIN(pt.DataVenda) DESC,
    pt.NumeroTitulo;

GO
