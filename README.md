# API Documentation

Esta API aceita requisições POST com um payload em texto simples contendo uma consulta SQL. A requisição deve incluir cabeçalhos com a string de conexão para o banco de dados e o tipo de resposta desejado.

## Requisição

**Método:** POST

**Cabeçalhos:**
- **Connection-String:** A string de conexão para o banco de dados.
- **Response-Type:** O tipo de resposta desejado (opcional).

**URL:** `http://sqlapi.kaizonaro.com`

**Corpo:**
```sql
SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;
```

**Parâmetros da Query String:**

Qualquer parâmetro enviado na query string será utilizado como parâmetro da consulta SQL. Por exemplo, se a URL for `http://sqlapi.kaizonaro.com?param1=value1&param2=value2`, os valores `value1` e `value2` serão utilizados como parâmetros na consulta SQL.

## Resposta

A resposta será no formato JSON.

**Sucesso:**
```json
[
    [
        {
            "column1": "value1",
            "column2": "value2",
            ...
        },
        ...
    ],
    [
        {
            "column1": "value1",
            "column2": "value2",
            ...
        },
        ...
    ],
    ...
]
```

**Erro:**
```json
{
    "error": "Mensagem de erro aqui"
}
```

## Tipos de Resposta

Você pode usar os seguintes valores para o cabeçalho `Response-Type` para alterar o formato da resposta:

- **single:** Retorna apenas o primeiro item da primeira coluna do primeiro conjunto de dados.
```json
"value1"
```

- **pairs:** Retorna um array JSON de objetos com a primeira coluna como chaves e a última coluna como valores.
```json
[
    {"value1": "value2"},
    {"value3": "value4"},
    ...
]
```

- **table:** Retorna apenas o primeiro conjunto de dados.
```json
[
    {
        "column1": "value1",
        "column2": "value2",
        ...
    },
    ...
]
```

- **row:** Retorna apenas a primeira linha do primeiro conjunto de dados.
```json
{
    "column1": "value1",
    "column2": "value2",
    ...
}
```

- **list:** Retorna um array com todos os valores da primeira coluna.
```json
[
    "value1",
    "value3",
    ...
]
```

- **none:** Executa a consulta sem retornar um conjunto de resultados, retornando apenas o número de linhas afetadas.
```json
{
    "affected_rows": 5
}
```

- **default:** Retorna todos os conjuntos de dados como um array JSON (comportamento padrão).
```json
[
    [
        {
            "column1": "value1",
            "column2": "value2",
            ...
        },
        ...
    ],
    [
        {
            "column1": "value1",
            "column2": "value2",
            ...
        },
        ...
    ],
    ...
]
```
