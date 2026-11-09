# MySqlApi

Uma prova de conceito de **gateway SQL-over-HTTP** escrita em PHP puro. Uma aplicação pode executar SQL arbitrário no **seu próprio banco MySQL ou MariaDB** e receber JSON com diferentes formatos de resposta.

Na v0.2, as credenciais do banco **não são enviadas diretamente na requisição**. O usuário gera um token de conexão criptografado no navegador e conserva uma chave aleatória separada. O servidor usa sua chave privada e a chave enviada pelo usuário para descriptografar a conexão somente durante a execução da consulta. **Não há suporte ao protocolo antigo de cabeçalhos `DB-*`.**

> **Experimento, não serviço público pronto para produção.** Leia [SECURITY.md](SECURITY.md) e configure isolamento de rede, HTTPS, autenticação e limitações de recursos antes de expor a API.

## Requisitos

- PHP 8.1+ com `mysqli`, `openssl`, `json` e `filter`.
- Servidor web com HTTPS (Apache ou outro) e suporte a variáveis de ambiente.
- Navegador com Web Crypto (HTTPS ou `localhost`) para gerar tokens.
- Um banco MySQL/MariaDB acessível a partir do servidor, com permissões adequadas.

## Instalação pelo navegador

1. Publique os arquivos em um virtual host **HTTPS**. O `index.php` é tanto a API quanto o painel de configuração.
2. Acesse `https://seu-dominio/`. **Sem `.env`**, aparece automaticamente o instalador. Ele verifica os requisitos PHP e prepara uma chave de instalação aleatória.
3. Abra o gerenciador de arquivos/SSH da hospedagem e leia o arquivo `.mysqlapi-<identificador>-install-token` criado **na pasta acima do serviço**. O nome exato aparece na página; o conteúdo secreto nunca é exibido publicamente. Se preferir, configure `MYSQLAPI_INSTALL_TOKEN` no ambiente PHP com pelo menos 24 caracteres para evitar o arquivo de bootstrap.
4. Informe essa chave no formulário, opcionalmente configure as origens CORS e clique em **Instalar serviço**.
5. O instalador cria automaticamente **o `.env` e a chave privada fora da pasta pública**, com permissões restritas. Guarde a API key gerada, mostrada apenas no resultado da instalação.
6. Ao atualizar `https://seu-dominio/`, a página passa a ser o **gerador de tokens de banco**. Informe host, usuário, senha e banco do MySQL/MariaDB. O navegador criptografa a conexão localmente e exibe o token e a chave do usuário, que devem ser guardados separadamente.
7. Antes de abrir o serviço ao público, configure o firewall para **bloquear saídas para redes privadas, loopback, link-local e endereços de metadados** nas portas de destino MySQL. A validação de DNS no PHP é somente uma proteção adicional e não impede DNS rebinding por si só.

O `.env` fica, por padrão, **um nível acima da raiz pública do servidor** (`DOCUMENT_ROOT`). Se a aplicação estiver em uma subpasta da raiz pública, o instalador também usa a pasta acima do `DOCUMENT_ROOT`, nunca a própria raiz pública. Para definir outro local privado, configure `MYSQLAPI_ENV_FILE` no ambiente PHP antes da instalação. O serviço rejeita `.env` em diretórios reconhecidos como públicos.

O instalador não permite sobrescrever uma instalação existente. A chave de instalação temporária é removida após a conclusão. Para recriar ou migrar a instalação, guarde os segredos antigos e faça um procedimento manual controlado — **não exclua o `.env` de produção para repetir o wizard**, pois isso pode tornar os tokens existentes inutilizáveis.

### Instalação manual (alternativa)

Se você já possui automação de deploy, pode gerar a chave P-256 via CLI e configurar as variáveis de ambiente tradicionais, desde que exista o `.env` no caminho esperado para marcar a instalação:

```bash
php bin/generate-keypair.php /caminho/privado/mysqlapi-private.pem
```

No `.env` privado (fora do document root):

```text
MYSQLAPI_PRIVATE_KEY_FILE="/caminho/privado/mysqlapi-private.pem"
MYSQLAPI_API_KEYS="uma-chave-longa-e-aleatoria-do-servico"
MYSQLAPI_ALLOWED_ORIGINS="https://app.exemplo.com"
```

Gere a API key com um gerador criptográfico (por exemplo, `openssl rand -hex 32`). Ela é **diferente** da chave do usuário que participa da criptografia do token. Sem API keys, as consultas retornam **503**, salvo `MYSQLAPI_ALLOW_UNAUTHENTICATED=1` para experimentos locais.

### Requisição

```http
POST / HTTP/1.1
Content-Type: application/json
Authorization: Bearer SUA_API_KEY_DO_SERVICO

{
  "token": "mapi1.TOKEN_GERADO",
  "key": "CHAVE_GERADA_PELO_USUARIO",
  "sql": "SELECT id, nome FROM clientes LIMIT 10",
  "responseType": "table"
}
```

Exemplo com `curl` (substitua os valores por segredos reais no ambiente da aplicação, nunca no código público):

```bash
curl https://seu-dominio/ \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $MYSQLAPI_API_KEY" \
  -d "{\"token\":\"$MYSQLAPI_TOKEN\",\"key\":\"$MYSQLAPI_USER_KEY\",\"sql\":\"SELECT VERSION()\",\"responseType\":\"single\"}"
```

**Somente o backend de uma aplicação deve armazenar token e chave de acesso.** Não os inclua em Flutter distribuído, JavaScript público, apps móveis ou repositórios. A aplicação cliente continua responsável por autorizar suas próprias operações e o usuário MySQL determina os privilégios SQL.

### Resposta

```json
{
  "status": "OK",
  "responseType": "table",
  "dataNames": [],
  "data": [{"id": 1, "nome": "Exemplo"}],
  "requestId": "a1b2c3d4e5f60708"
}
```

Erros retornam um status HTTP adequado (400, 401, 403, 405, 413, 415, 429, 502 ou 503) e uma mensagem genérica, sem ecoar o SQL ou revelar a senha. Use o `requestId` para correlacionar diagnósticos. Uma consulta válida sem linhas pode retornar `status: "EMPTY"`.

## Tipos de resposta

Informe `responseType` no JSON. O padrão é `sets`.

| Tipo | Resultado em `data` |
| --- | --- |
| `sets` (padrão) | Todos os conjuntos de resultados (array de arrays de linhas) |
| `single`, `value` | Primeiro valor da primeira linha, ou `null` |
| `pairs`, `pair` | Lista de objetos: primeira coluna como chave e última como valor |
| `table`, `view`, `set` | Primeiro conjunto de linhas |
| `row`, `first` | Primeira linha do primeiro conjunto, ou `null` |
| `list`, `array`, `values` | Primeira coluna de cada linha do primeiro conjunto |
| `namedsets`, `namedset` | Conjuntos nomeados pelo array `dataNames` |
| `namedrows`, `namedrow` | Primeira linha de cada conjunto, nomeada por `dataNames` |

Exemplo de múltiplos conjuntos:

```json
{
  "token": "mapi1.TOKEN_GERADO",
  "key": "CHAVE_GERADA_PELO_USUARIO",
  "sql": "SELECT 1 AS total; SELECT 2 AS pendentes;",
  "responseType": "namedrows",
  "dataNames": ["total", "pendentes"]
}
```

O projeto aceita múltiplas instruções SQL propositalmente. Não use `?` como placeholder: `mysqli::multi_query()` **não** vincula parâmetros. A aplicação que constrói SQL com dados de usuários deve usar parametrização do lado dela, nunca concatenar entradas não confiáveis.

## Configuração

O serviço carrega automaticamente o `.env` privado quando ele existe, sem sobrescrever variáveis já definidas no processo PHP. Também é possível configurar tudo diretamente nas variáveis do ambiente de hospedagem.

| Variável | Padrão | Finalidade |
| --- | --- | --- |
| `MYSQLAPI_ENV_FILE` | `.env` acima do document root | Local alternativo para o `.env` (fora do document root) |
| `MYSQLAPI_INSTALL_TOKEN` | Gerado em arquivo privado | Chave de bootstrap para autorizar a primeira instalação web |
| `MYSQLAPI_PRIVATE_KEY_FILE` | Obrigatória | Caminho absoluto para a chave privada EC P-256 fora do diretório público |
| `MYSQLAPI_API_KEYS` | Obrigatória | API keys do serviço separadas por vírgula |
| `MYSQLAPI_ALLOW_UNAUTHENTICATED` | `0` | Desativa autenticação **somente para experimento local** |
| `MYSQLAPI_ALLOWED_ORIGINS` | Vazio | Lista exata de origens CORS, separadas por vírgula; sem `*` |
| `MYSQLAPI_ALLOW_HTTP` | `0` | Permite HTTP **somente em localhost para testes** |
| `MYSQLAPI_ALLOW_PRIVATE_NETWORKS` | `0` | Permite hosts privados — somente em infraestrutura confiável |
| `MYSQLAPI_ALLOW_UNIX_SOCKETS` | `0` | Permite socket Unix em tokens gerados por outro cliente |
| `MYSQLAPI_MAX_TOKEN_AGE_DAYS` | `90` | Validade máxima de tokens, entre 1 e 365 dias |
| `MYSQLAPI_MAX_BODY_BYTES` | `65536` | Tamanho máximo do JSON de requisição |
| `MYSQLAPI_MAX_ROWS` | `1000` | Máximo de linhas acumuladas entre conjuntos |
| `MYSQLAPI_MAX_RESULT_BYTES` | `2097152` | Tamanho aproximado máximo do resultado serializado |
| `MYSQLAPI_CONNECT_TIMEOUT` | `5` | Timeout da conexão MySQL (segundos) |
| `MYSQLAPI_RATE_LIMIT_PER_MINUTE` | `60` | Limite por API key e endereço remoto, em uma instância |
| `MYSQLAPI_MYSQL_TLS_REQUIRED` | `0` | Exige TLS do banco remoto (recomendado em produção) |
| `MYSQLAPI_MYSQL_CA_FILE` | Vazio | Certificado CA para validar o MySQL remoto quando TLS é exigido |

A chave privada atual identifica o servidor por um `kid`. Rotacioná-la invalida todos os tokens existentes, que precisarão ser gerados novamente. A API não armazena tokens nem suas credenciais.

## Desenvolvimento e testes

Para testar localmente, use um servidor PHP apenas em loopback (na primeira visita, siga o instalador; o token de instalação é gerado na pasta acima do projeto):

```bash
MYSQLAPI_ALLOW_HTTP=1 php -S 127.0.0.1:8080
```

```bash
php tests/run.php
```

Os testes incluem formatos JSON, autenticação, limites, destinos bloqueados, instalação do `.env` e um token produzido com **WebCrypto/Node** e descriptografado por **PHP/OpenSSL**. O workflow de CI executa testes de unidade, verificações de sintaxe e um teste HTTP do wizard (`GET` → instalação → gerador). Não existe teste de integração com MySQL real incluído nesta prova de conceito.

## Segurança

Leia [SECURITY.md](SECURITY.md) para entender as limitações do modelo de duas chaves, risco de replay, isolamento de rede, rate limiting e configuração de produção. **Token + chave do usuário juntos equivalem a uma credencial reutilizável.** O servidor necessariamente vê os dados da conexão em memória ao executar SQL; isso não é criptografia de ponta a ponta contra o operador.

Licenciamento e contribuições: projeto experimental de [Zonaro](https://github.com/zonaro).
