/*
================================================================================
QUERY PARA EXPORTAÇÃO CSV COM CABEÇALHO INCLUÍDO
================================================================================

Esta query adiciona uma linha de cabeçalho como primeiro resultado.
Permite exportar diretamente do SSMS sem precisar configurar opções.

COMO USAR:
1. Execute esta query no SQL Server Management Studio
2. Clique com botão direito nos resultados
3. "Save Results As..." e escolha CSV
4. O arquivo já terá os cabeçalhos na primeira linha

DELIMITADOR: Ponto e vírgula (;)
CODIFICAÇÃO: Salve como UTF-8 ao exportar

================================================================================
*/

-- PRIMEIRA LINHA: CABEÇALHOS
SELECT
    'NumeroTitulo' AS NumeroTitulo,
    'NomeProduto' AS NomeProduto,
    'Categoria' AS Categoria,
    'StatusTitulo' AS StatusTitulo,
    'Promotor' AS Promotor,
    'DataVenda' AS DataVenda,
    'DataCadastro' AS DataCadastro,
    'DataPrimeiraCobranca' AS DataPrimeiraCobranca,
    'DataUltimaCobranca' AS DataUltimaCobranca,
    'ValorTotal' AS ValorTotal,
    'ValorPago' AS ValorPago,
    'ValorEmAberto' AS ValorEmAberto,
    'QuantidadeParcelas' AS QuantidadeParcelas,
    'ParcelasPagas' AS ParcelasPagas,
    'ParcelasEmAberto' AS ParcelasEmAberto,
    'PrimeiraParcelaPaga' AS PrimeiraParcelaPaga,
    'StatusInadimplencia' AS StatusInadimplencia,
    'DiasInadimplente' AS DiasInadimplente,
    'Inadimplente3Meses' AS Inadimplente3Meses,
    'Inadimplente6Meses' AS Inadimplente6Meses,
    'Inadimplente12Meses' AS Inadimplente12Meses,
    'DocumentoTitular' AS DocumentoTitular,
    'NomeTitular' AS NomeTitular,
    'EmailTitular' AS EmailTitular,
    'TelefoneTitular' AS TelefoneTitular,
    'NumeroCartao' AS NumeroCartao,
    'BandeiraCartao' AS BandeiraCartao,
    'NomeTitularCartao' AS NomeTitularCartao,
    'ValidadeCartao' AS ValidadeCartao,
    'DocumentoTitularCartao' AS DocumentoTitularCartao

UNION ALL

-- DADOS REAIS
SELECT
    CAST(up.NumeroTitulo AS VARCHAR(50)) AS NumeroTitulo,
    CAST(up.NomeProduto AS VARCHAR(200)) AS NomeProduto,
    CAST(up.Categoria AS VARCHAR(100)) AS Categoria,
    CAST(up.StatusTitulo AS VARCHAR(50)) AS StatusTitulo,
    CAST(p.Promotor AS VARCHAR(200)) AS Promotor,
    CAST(CONVERT(VARCHAR(10), p.DataVenda, 120) AS VARCHAR(10)) AS DataVenda,
    CAST(CONVERT(VARCHAR(10), p.DataCadastro, 120) AS VARCHAR(10)) AS DataCadastro,
    CAST(CONVERT(VARCHAR(10), p.DataPrimeiraCobranca, 120) AS VARCHAR(10)) AS DataPrimeiraCobranca,
    CAST(CONVERT(VARCHAR(10), p.DataUltimaCobranca, 120) AS VARCHAR(10)) AS DataUltimaCobranca,
    CAST(CAST(p.ValorTotal AS DECIMAL(18,2)) AS VARCHAR(50)) AS ValorTotal,
    CAST(CAST(p.ValorPago AS DECIMAL(18,2)) AS VARCHAR(50)) AS ValorPago,
    CAST(CAST(p.ValorEmAberto AS DECIMAL(18,2)) AS VARCHAR(50)) AS ValorEmAberto,
    CAST(p.QuantidadeParcelas AS VARCHAR(10)) AS QuantidadeParcelas,
    CAST(p.ParcelasPagas AS VARCHAR(10)) AS ParcelasPagas,
    CAST(p.ParcelasEmAberto AS VARCHAR(10)) AS ParcelasEmAberto,
    CAST(p.PrimeiraParcelaPaga AS VARCHAR(3)) AS PrimeiraParcelaPaga,
    -- STATUS DE INADIMPLÊNCIA CORRIGIDO
    -- Calcula quantas parcelas DEVERIAM estar pagas baseado no tempo desde a venda
    CAST(CASE
        -- Calcular parcelas esperadas = meses desde a venda + 1
        WHEN p.ParcelasPagas < (DATEDIFF(MONTH, p.DataVenda, GETDATE()) + 1)
             AND p.ParcelasPagas < p.QuantidadeParcelas
        THEN
            CASE
                -- Se pagou apenas 1 parcela e deveria ter pago mais
                WHEN p.ParcelasPagas = 1 AND (DATEDIFF(MONTH, p.DataVenda, GETDATE()) + 1) > 1
                THEN 'INADIMPLENTE - Apenas 1ª Parcela'

                -- Se está devendo menos de 50% das parcelas esperadas
                WHEN CAST(p.ParcelasPagas AS FLOAT) / (DATEDIFF(MONTH, p.DataVenda, GETDATE()) + 1) >= 0.5
                THEN 'INADIMPLENTE - Menos de 50%'

                -- Se está devendo mais de 50%
                ELSE 'INADIMPLENTE - Mais de 50%'
            END

        -- Se pagou todas as parcelas esperadas até agora
        WHEN p.ParcelasPagas >= (DATEDIFF(MONTH, p.DataVenda, GETDATE()) + 1)
             OR p.ParcelasPagas >= p.QuantidadeParcelas
        THEN 'ADIMPLENTE'

        -- Default
        ELSE 'ADIMPLENTE'
    END AS VARCHAR(50)) AS StatusInadimplencia,
    -- Dias em atraso (apenas se realmente inadimplente)
    CAST(CASE
        WHEN p.ParcelasPagas < (DATEDIFF(MONTH, p.DataVenda, GETDATE()) + 1)
             AND p.ParcelasPagas < p.QuantidadeParcelas
        THEN DATEDIFF(DAY, DATEADD(MONTH, p.ParcelasPagas, p.DataVenda), GETDATE())
        ELSE 0
    END AS VARCHAR(10)) AS DiasInadimplente,
    -- Inadimplente há 3 meses ou mais
    CAST(CASE
        WHEN DATEDIFF(MONTH, p.DataVenda, GETDATE()) >= 3
             AND p.ParcelasPagas < (DATEDIFF(MONTH, p.DataVenda, GETDATE()) + 1 - 2)
        THEN 'Sim'
        ELSE 'Não'
    END AS VARCHAR(3)) AS Inadimplente3Meses,
    -- Inadimplente há 6 meses ou mais
    CAST(CASE
        WHEN DATEDIFF(MONTH, p.DataVenda, GETDATE()) >= 6
             AND p.ParcelasPagas < (DATEDIFF(MONTH, p.DataVenda, GETDATE()) + 1 - 5)
        THEN 'Sim'
        ELSE 'Não'
    END AS VARCHAR(3)) AS Inadimplente6Meses,
    -- Inadimplente há 12 meses ou mais
    CAST(CASE
        WHEN DATEDIFF(MONTH, p.DataVenda, GETDATE()) >= 12
             AND p.ParcelasPagas < (DATEDIFF(MONTH, p.DataVenda, GETDATE()) + 1 - 11)
        THEN 'Sim'
        ELSE 'Não'
    END AS VARCHAR(3)) AS Inadimplente12Meses,
    CAST(p.DocumentoTitular AS VARCHAR(20)) AS DocumentoTitular,
    CAST(p.NomeTitular AS VARCHAR(200)) AS NomeTitular,
    CAST(p.EmailTitular AS VARCHAR(200)) AS EmailTitular,
    CAST(p.TelefoneTitular AS VARCHAR(50)) AS TelefoneTitular,
    CAST(c.NumeroCartao AS VARCHAR(20)) AS NumeroCartao,
    CAST(c.BandeiraCartao AS VARCHAR(50)) AS BandeiraCartao,
    CAST(c.NomeTitularCartao AS VARCHAR(200)) AS NomeTitularCartao,
    CAST(c.ValidadeCartao AS VARCHAR(10)) AS ValidadeCartao,
    CAST(c.DocumentoTitularCartao AS VARCHAR(20)) AS DocumentoTitularCartao

FROM (
    -- CTE: Pegar apenas o último produto de cada título
    SELECT
        NumeroTitulo,
        NomeProduto,
        Categoria,
        StatusTitulo,
        ROW_NUMBER() OVER (PARTITION BY NumeroTitulo ORDER BY DataVenda DESC) AS rn
    FROM [dbo].[PaidTitles]
    WHERE [NomeProduto] LIKE '%Sócio%'
      AND [DataCadastro] BETWEEN '2024-11-01' AND GETDATE()
) up

INNER JOIN [dbo].[PaidTitles] p ON up.NumeroTitulo = p.NumeroTitulo
LEFT JOIN [dbo].[Cards] c ON p.NumeroTitulo = c.NumeroTitulo

WHERE up.rn = 1
  AND p.StatusTitulo IN ('Ativo', 'Bloqueado')
  AND p.PrimeiraParcelaPaga = 'Sim'

ORDER BY up.NumeroTitulo;

/*
================================================================================
FORMATO DE SAÍDA ESPERADO:
================================================================================

NumeroTitulo;NomeProduto;Categoria;StatusTitulo;Promotor;DataVenda;...
SAC0094;Sócio Safira Título - 2 Vagas;Titulo;Ativo;João Silva;2024-11-15;...
SAC0095;Sócio Safira Título - 2 Vagas;Titulo;Ativo;Maria Santos;2024-11-16;...

A primeira linha contém os nomes das colunas (cabeçalhos).
As linhas seguintes contêm os dados reais.

================================================================================
IMPORTANTE:
================================================================================

1. Ao salvar, escolha codificação UTF-8
2. Use ponto e vírgula (;) como delimitador
3. A primeira linha (cabeçalhos) será incluída automaticamente

================================================================================
*/
