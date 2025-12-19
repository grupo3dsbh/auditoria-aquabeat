# Como Exportar CSV com Cabeçalho do SQL Server

## Problema
O SQL Server Management Studio (SSMS) não inclui cabeçalhos ao usar "Save Results As...".

## Solução 1: Configurar SSMS para Incluir Cabeçalhos (RECOMENDADO)

### Passos:
1. Abra o SQL Server Management Studio (SSMS)
2. Vá em: **Tools** → **Options**
3. Navegue até: **Query Results** → **SQL Server** → **Results to Grid**
4. Marque a opção: **"Include column headers when copying or saving the results"**
5. Clique em **OK**
6. Execute sua query
7. Clique com botão direito nos resultados → **"Save Results As..."**
8. Escolha formato CSV

**IMPORTANTE**: Você precisa usar **"Copy with Headers"** em vez de "Save Results As":
- Execute a query
- Clique com botão direito nos resultados
- Selecione **"Copy with Headers"**
- Cole no Excel ou Notepad
- Salve como CSV com codificação UTF-8

---

## Solução 2: Usar BCP (Bulk Copy Program) - Linha de Comando

```cmd
bcp "SELECT * FROM (SEU_QUERY_AQUI) AS Results" queryout "C:\temp\export.csv" -c -t";" -S SEU_SERVIDOR -d SEU_BANCO -T
```

### Exemplo Real:
```cmd
bcp "EXEC sp_executesql N'USE SeuBanco; SELECT * FROM (SUA_QUERY) AS R'" queryout "C:\temp\inadimplencia.csv" -c -t";" -S localhost -d AquabeatDB -T
```

**Limitação**: BCP não adiciona cabeçalhos automaticamente. Veja Solução 3.

---

## Solução 3: Query Modificada com UNION (MELHOR PARA AUTOMAÇÃO)

Esta query adiciona uma linha de cabeçalho como primeiro resultado:

```sql
-- Primeira linha: Cabeçalhos
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

-- Dados reais
SELECT
    CAST(up.NumeroTitulo AS VARCHAR(50)),
    CAST(up.NomeProduto AS VARCHAR(200)),
    CAST(up.Categoria AS VARCHAR(100)),
    CAST(up.StatusTitulo AS VARCHAR(50)),
    CAST(p.Promotor AS VARCHAR(200)),
    CAST(CONVERT(VARCHAR(10), p.DataVenda, 120) AS VARCHAR(10)),
    CAST(CONVERT(VARCHAR(10), p.DataCadastro, 120) AS VARCHAR(10)),
    CAST(CONVERT(VARCHAR(10), p.DataPrimeiraCobranca, 120) AS VARCHAR(10)),
    CAST(CONVERT(VARCHAR(10), p.DataUltimaCobranca, 120) AS VARCHAR(10)),
    CAST(CAST(p.ValorTotal AS DECIMAL(18,2)) AS VARCHAR(50)),
    CAST(CAST(p.ValorPago AS DECIMAL(18,2)) AS VARCHAR(50)),
    CAST(CAST(p.ValorEmAberto AS DECIMAL(18,2)) AS VARCHAR(50)),
    CAST(p.QuantidadeParcelas AS VARCHAR(10)),
    CAST(p.ParcelasPagas AS VARCHAR(10)),
    CAST(p.ParcelasEmAberto AS VARCHAR(10)),
    CAST(p.PrimeiraParcelaPaga AS VARCHAR(3)),
    CAST(CASE
        WHEN p.ParcelasEmAberto > 0 AND DATEDIFF(DAY, p.DataUltimaCobranca, GETDATE()) > 30
        THEN 'Inadimplente'
        ELSE 'Adimplente'
    END AS VARCHAR(20)),
    CAST(CASE
        WHEN p.ParcelasEmAberto > 0
        THEN DATEDIFF(DAY, p.DataUltimaCobranca, GETDATE())
        ELSE 0
    END AS VARCHAR(10)),
    CAST(CASE WHEN DATEDIFF(MONTH, p.DataPrimeiraCobranca, GETDATE()) >= 3
        AND p.ParcelasEmAberto > 0 THEN 'Sim' ELSE 'Não' END AS VARCHAR(3)),
    CAST(CASE WHEN DATEDIFF(MONTH, p.DataPrimeiraCobranca, GETDATE()) >= 6
        AND p.ParcelasEmAberto > 0 THEN 'Sim' ELSE 'Não' END AS VARCHAR(3)),
    CAST(CASE WHEN DATEDIFF(MONTH, p.DataPrimeiraCobranca, GETDATE()) >= 12
        AND p.ParcelasEmAberto > 0 THEN 'Sim' ELSE 'Não' END AS VARCHAR(3)),
    CAST(p.DocumentoTitular AS VARCHAR(20)),
    CAST(p.NomeTitular AS VARCHAR(200)),
    CAST(p.EmailTitular AS VARCHAR(200)),
    CAST(p.TelefoneTitular AS VARCHAR(50)),
    CAST(c.NumeroCartao AS VARCHAR(20)),
    CAST(c.BandeiraCartao AS VARCHAR(50)),
    CAST(c.NomeTitularCartao AS VARCHAR(200)),
    CAST(c.ValidadeCartao AS VARCHAR(10)),
    CAST(c.DocumentoTitularCartao AS VARCHAR(20))

FROM (
    SELECT NumeroTitulo, NomeProduto, Categoria, StatusTitulo,
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
```

**Como usar**:
1. Execute esta query no SSMS
2. Use "Save Results As..."
3. O arquivo já terá a linha de cabeçalho

---

## Solução 4: PowerShell Script (AUTOMAÇÃO COMPLETA)

Crie um arquivo `exportar_csv.ps1`:

```powershell
# Configurações
$servidor = "SEU_SERVIDOR"
$banco = "SEU_BANCO"
$arquivoSaida = "C:\temp\inadimplencia_" + (Get-Date -Format "yyyyMMdd_HHmmss") + ".csv"
$queryFile = "C:\caminho\para\query_simples_csv.sql"

# Ler query do arquivo
$query = Get-Content $queryFile -Raw

# Executar query e exportar
Invoke-Sqlcmd -ServerInstance $servidor -Database $banco -Query $query |
    Export-Csv -Path $arquivoSaida -NoTypeInformation -Encoding UTF8 -Delimiter ";"

Write-Host "Arquivo exportado com sucesso: $arquivoSaida"
```

**Execute**:
```powershell
powershell -ExecutionPolicy Bypass -File exportar_csv.ps1
```

---

## Recomendação Final

**Para uso imediato**: Use a **Solução 1** com "Copy with Headers"

**Para automação**: Use a **Solução 3** (Query com UNION) - já está pronta para usar!

---

## Formato do CSV Esperado

O sistema espera CSV com:
- **Delimitador**: Ponto e vírgula (;)
- **Codificação**: UTF-8
- **Primeira linha**: Cabeçalhos das colunas
- **Aspas**: Opcional, usar apenas se necessário

### Exemplo correto:
```
NumeroTitulo;NomeProduto;Categoria;StatusTitulo;Promotor;DataVenda
SAC0094;Sócio Safira Título - 2 Vagas;Titulo;Ativo;João Silva;2024-11-15
SAC0095;Sócio Safira Título - 2 Vagas;Titulo;Ativo;Maria Santos;2024-11-16
```

### Exemplo incorreto (sem cabeçalho):
```
SAC0094;Sócio Safira Título - 2 Vagas;Titulo;Ativo;João Silva;2024-11-15
SAC0095;Sócio Safira Título - 2 Vagas;Titulo;Ativo;Maria Santos;2024-11-16
```
