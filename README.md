# MySqlApi

Esta API aceita requisições POST com um payload em texto simples contendo uma consulta SQL. A requisição deve incluir cabeçalhos com os parâmetros de conexão para o banco de dados e o tipo de resposta desejado.

## Requisição

**Método:** POST

**Cabeçalhos:**
- **DB-Hostname:** O hostname do banco de dados.
- **DB-Username:** O nome de usuário do banco de dados.
- **DB-Password:** A senha do banco de dados (opcional).
- **DB-Database:** O nome do banco de dados.
- **DB-Port:** A porta do banco de dados (opcional).
- **DB-Socket:** O socket do banco de dados (opcional).
- **Response-Type:** O tipo de resposta desejado (opcional, padrão é SETS).
- **Data-Names:** Uma lista separada por virgula (,) ou ponto e virgula (;) com os nomes de cada conjunto de dados (para `namedSets` e `namedRows`).

**URL:** `https://sqlapi.kaizonaro.com/`

**Corpo:**
```sql
SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;
```

## Resposta

A resposta será no formato JSON.

**Sucesso:**
```json
{
    "sql": "SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;",
    "status": "OK",
    "responseType": "sets",
    "dataNames": [],
    "message": "Data found",
    "data": [
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
}
```

**Erro:**
```json
{
    "sql": "SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;",
    "status": "ERROR",
    "responseType": "sets",
    "dataNames": [],
    "message": "Mensagem de erro aqui",
    "data": null
}
```

## Tipos de Resposta

Você pode usar os seguintes valores para o cabeçalho `Response-Type` para alterar o formato da resposta:

- **single:** Retorna apenas o primeiro item da primeira coluna do primeiro conjunto de dados.
```json
{
    "sql": "SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;",
    "status": "OK",
    "responseType": "single",
    "dataNames": [],
    "message": "Data found",
    "data": "value1"
}
```

- **pairs:** Retorna um array JSON de objetos com a primeira coluna como chaves e a última coluna como valores.
```json
{
    "sql": "SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;",
    "status": "OK",
    "responseType": "pairs",
    "dataNames": [],
    "message": "Data found",
    "data": [
        {"value1": "value2"},
        {"value3": "value4"},
        ...
    ]
}
```

- **table:** Retorna apenas o primeiro conjunto de dados.
```json
{
    "sql": "SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;",
    "status": "OK",
    "responseType": "table",
    "dataNames": [],
    "message": "Data found",
    "data": [
        {
            "column1": "value1",
            "column2": "value2",
            ...
        },
        ...
    ]
}
```

- **row:** Retorna apenas a primeira linha do primeiro conjunto de dados.
```json
{
    "sql": "SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;",
    "status": "OK",
    "responseType": "row",
    "dataNames": [],
    "message": "Data found",
    "data": {
        "column1": "value1",
        "column2": "value2",
        ...
    }
}
```

- **list:** Retorna um array com todos os valores da primeira coluna.
```json
{
    "sql": "SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;",
    "status": "OK",
    "responseType": "list",
    "dataNames": [],
    "message": "Data found",
    "data": [
        "value1",
        "value3",
        ...
    ]
}
```
- **namedsets:** Retorna conjuntos de dados nomeados.
```json
{
    "sql": "SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;",
    "status": "OK",
    "responseType": "namedsets",
    "dataNames": ["set1", "set2"],
    "message": "Data found",
    "data": {
        "set1": [
            {
                "column1": "value1",
                "column2": "value2",
                ...
            },
            ...
        ],
        "set2": [
            {
                "column1": "value1",
                "column2": "value2",
                ...
            },
            ...
        ]
    }
}
```

- **namedrows:** Retorna linhas nomeadas.
```json
{
    "sql": "SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;",
    "status": "OK",
    "responseType": "namedrows",
    "dataNames": ["row1", "row2"],
    "message": "Data found",
    "data": {
        "row1": {
            "column1": "value1",
            "column2": "value2",
            ...
        },
        "row2": {
            "column1": "value1",
            "column2": "value2",
            ...
        }
    }
}
```

- **default:** Retorna todos os conjuntos de dados como um array JSON (comportamento padrão).
```json
{
    "sql": "SELECT `column1`, `column2`, ... FROM `table` WHERE `column3` = ?;",
    "status": "OK",
    "responseType": "sets",
    "dataNames": [],
    "message": "Data found",
    "data": [
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
}
```
