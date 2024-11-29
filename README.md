# API Documentation

Esta API aceita requisições POST com um payload em texto simples contendo uma consulta SQL. A requisição deve incluir um cabeçalho com a string de conexão para o banco de dados.

## Requisição

**Método:** POST

**Cabeçalhos:**
- **Connection-String:** A string de conexão para o banco de dados.

**URL:** `http://<seu-servidor>`

**Corpo:**
```sql
SELECT `column1`, `column2`, ... FROM `table`;
```

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

## Parâmetros de Consulta

Você pode usar os seguintes parâmetros de consulta para alterar o formato da resposta:

- **responseType=single:** Retorna apenas o primeiro item da primeira coluna do primeiro conjunto de dados.
```json
"value1"
```

- **responseType=pairs:** Retorna um array JSON de objetos com a primeira coluna como chaves e a última coluna como valores.
```json
[
    {"value1": "value2"},
    {"value3": "value4"},
    ...
]
```

- **responseType=table:** Retorna apenas o primeiro conjunto de dados.
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

- **responseType=row:** Retorna apenas a primeira linha do primeiro conjunto de dados.
```json
{
    "column1": "value1",
    "column2": "value2",
    ...
}
```

- **responseType=list:** Retorna um array com todos os valores da primeira coluna.
```json
[
    "value1",
    "value3",
    ...
]
```

- **responseType=default:** Retorna todos os conjuntos de dados como um array JSON (comportamento padrão).
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
