# Modelo de segurança da MySqlApi

## Limites de confiança

- O **cliente** escolhe o banco e o SQL arbitrário. A aplicação terceira decide quem tem acesso e deve tratar SQL originado de usuários com a mesma cautela de qualquer aplicativo MySQL.
- O **servidor MySqlApi** recebe `token`, `key` e `sql`; ele **descriptografa** credenciais em memória e acessa os dados do banco. Portanto, o operador desse servidor precisa ser confiável.
- O **MySQL/MariaDB** autoriza cada operação conforme a conta de banco informada. Use contas específicas com privilégios mínimos e nunca uma conta administrativa por padrão.

## Modelo criptográfico v1

O navegador obtém a chave pública P-256 do servidor, gera uma chave efêmera ECDH P-256 e uma chave do usuário aleatória de 256 bits. Ambos os lados derivam 32 bytes ECDH; a chave AES-256-GCM é derivada com HKDF-SHA256 sobre `ECDH || userKey`, salt aleatório de 16 bytes e contexto `mysqlapi-connection-v1`. O JSON da conexão é criptografado com nonce aleatório de 12 bytes, tag GCM de 16 bytes e dados adicionais autenticados `mysqlapi|v1|kid`.

O token `mapi1.<base64url(JSON)>` contém versão, `kid`, chave pública efêmera, salt, nonce e ciphertext+tag. Ele não contém a chave do usuário. Somente um servidor com a chave privada correspondente **e** a chave do usuário consegue descriptografá-lo.

**Limitações importantes:**

1. Token e chave enviados juntos podem ser capturados e reutilizados. Não há proteção criptográfica contra replay nem revogação individual sem estado. Há expiração (máximo de 90 dias por padrão). Para revogar imediatamente, altere a senha MySQL ou rotacione a chave do servidor (isso invalida todos os tokens).
2. A página de configuração é entregue pelo próprio servidor. Um operador malicioso, ou JavaScript alterado por comprometimento, pode capturar credenciais **antes** da criptografia. Verifique o código e hospede sua própria instância se isso for um risco inaceitável.
3. A criptografia não impede o servidor de enxergar credenciais e resultados em memória durante a execução. O serviço **não** é zero-knowledge nem end-to-end contra seu operador.
4. **API key do serviço** é uma credencial adicional necessária para consultas; não é a chave de usuário que entra na criptografia. Restrinja o acesso às duas.

## Rede / SSRF adaptado a MySQL

Por padrão, o PHP rejeita hosts privados, reservados e loopback e sockets Unix. Essa validação de DNS é **somente defesa em profundidade**: o DNS pode mudar entre a validação e a conexão (`DNS rebinding`). Antes de expor o serviço à internet, bloqueie por firewall/container todas as conexões de saída para redes internas, loopback, link-local, metadata IPs e destinos reservados. Restrinja portas de saída às usadas por MySQL/MariaDB quando possível.

`MYSQLAPI_ALLOW_PRIVATE_NETWORKS=1` e `MYSQLAPI_ALLOW_UNIX_SOCKETS=1` são opções para ambientes internos **isolados**, nunca para uma instância pública genérica.

## Transporte e credenciais

- **HTTPS obrigatório** por padrão e HSTS recomendado no proxy. O override HTTP serve só para desenvolvimento em `localhost`.
- O wizard exige uma **chave de instalação** fora do diretório público (ou configurada no ambiente PHP), para impedir que o primeiro visitante configure a instância. O `.env` e a chave privada são criados fora do document root, com permissões `0600`; o segredo de bootstrap temporário é removido depois da instalação.
- Se o host não permitir gravar arquivos acima do diretório público, defina `MYSQLAPI_ENV_FILE` apontando para uma pasta privada gravável. **Nunca aponte para um diretório publicado pelo servidor web.**
- Exija TLS de MySQL quando o banco for remoto (`MYSQLAPI_MYSQL_TLS_REQUIRED=1` e um `MYSQLAPI_MYSQL_CA_FILE` confiável). Confirme que a implantação realmente valida certificados na combinação PHP/mysqlnd/servidor utilizada.
- Não guarde token, chave do usuário, API key, SQL ou resultados em JavaScript público, logs ou repositórios.
- Não configure as chaves privadas dentro do document root; aplique permissões restritivas ao arquivo e ao usuário do PHP.
- Evite logs de corpos e cabeçalhos sensíveis em proxies, WAFs, APMs e servidores web. A API registra apenas identificadores e classes de erro, não dados SQL.

## Disponibilidade e abuso

- A API limita tamanho de requisições, número de linhas retornadas, bytes de resultados, tentativas por minuto e timeout da conexão. O rate limiting implementado é baseado em arquivos **por instância**, não serve para clusters.
- `mysqli::multi_query()` executa **múltiplas instruções por design**. Limitar a resposta **não interrompe de modo confiável** SQL pesado ou efeitos das instruções subsequentes. Use timeouts no MySQL, limites de usuário, `max_execution_time` conforme apropriado, `request_terminate_timeout` no PHP-FPM, cotas e isolamento de recursos no host/proxy.
- Controles de CORS não são autenticação. Origens são configuradas explicitamente; clientes fora do navegador não obedecem CORS.

## Antes de publicar

1. Configurar chave privada, API keys fortes, HTTPS e CA MySQL.
2. Restringir egress de rede e testar bloqueio de loopback/RFC1918/metadata **na camada de rede**.
3. Separar usuários MySQL por aplicação e manter privilégio mínimo.
4. Ajustar limites de requisição, SQL, banco e rate limiting no gateway.
5. Habilitar observabilidade e alertas sem registrar segredos.
6. Validar fluxo ponta a ponta com um banco de teste isolado. Os testes de CI não executam consultas reais.

## Relatos de vulnerabilidades

Não abra issues públicos com tokens, chaves ou senhas. Entre em contato com o mantenedor por um canal privado do perfil [zonaro](https://github.com/zonaro).
