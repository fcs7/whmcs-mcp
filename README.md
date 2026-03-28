# NT MCP Server — WHMCS Addon

Servidor MCP (Model Context Protocol) que expoe operacoes WHMCS como ferramentas para Claude Code. Permite que o assistente AI gerencie clientes, faturas, tickets, servicos, dominios, pedidos, projetos e CRM diretamente via conversacao.

## Requisitos

- **PHP** >= 8.2
- **WHMCS** 7.x ou 8.x
- **Composer** (para instalar dependencias)
- **HTTPS** obrigatorio (o endpoint rejeita HTTP com status 421)
- **Apache** com `mod_rewrite` (para protecao `.htaccess`)

## Instalacao

### 1. Enviar arquivos para o WHMCS

Envie o diretorio `modules/addons/nt_mcp/` para dentro da instalacao do WHMCS. O destino e `httpdocs/modules/addons/nt_mcp/`.

**Via Plesk (File Manager):**

1. Acesse o **File Manager** do Plesk
2. Navegue ate `httpdocs/modules/addons/`
3. Crie a pasta `nt_mcp`
4. Faca upload dos arquivos: `mcp.php`, `nt_mcp.php`, `composer.json`, `composer.lock` e as pastas `src/`
5. **Nao envie** as pastas `vendor/`, `tests/` ou `.security-hardening/`

**Via SFTP/SCP:**

```bash
scp -r modules/addons/nt_mcp/ usuario@servidor:httpdocs/modules/addons/nt_mcp/
```

**Via SSH (se tiver acesso ao servidor):**

```bash
cd /var/www/vhosts/seudominio.com.br/httpdocs
cp -r /caminho/local/modules/addons/nt_mcp/ modules/addons/nt_mcp/
```

A estrutura final deve ser:

```
httpdocs/                  # Raiz do WHMCS (Plesk document root)
  init.php
  modules/
    addons/
      nt_mcp/
        mcp.php            # Endpoint HTTP publico
        nt_mcp.php         # Hooks do addon (ativacao, admin UI)
        composer.json
        composer.lock
        .htaccess          # Protecao de diretorios
        src/
          Server.php
          Auth/
          Tools/
          Whmcs/
        vendor/            # Sera criado no proximo passo
```

### 2. Instalar dependencias

O `composer install` precisa ser executado dentro do servidor. Existem duas opcoes:

**Opcao A — Via SSH no Plesk (recomendado):**

No Plesk, va em **Websites & Domains > seu dominio > Terminal** (ou acesse via SSH):

```bash
cd httpdocs/modules/addons/nt_mcp/
composer install --no-dev --ignore-platform-req=ext-iconv
```

**Opcao B — Enviar vendor/ pronto:**

Se nao tiver acesso SSH, instale as dependencias localmente e envie a pasta `vendor/`:

```bash
# No seu computador local
cd modules/addons/nt_mcp/
composer install --no-dev --ignore-platform-req=ext-iconv

# Depois envie a pasta vendor/ via SFTP ou File Manager do Plesk
```

Isso instala o `php-mcp/server` (framework MCP) e suas dependencias.

### 3. Verificar permissoes (Plesk)

No Plesk, os arquivos enviados geralmente ja herdam as permissoes corretas do usuario do dominio. Verifique que:

- Arquivos PHP: `644` (`rw- r-- r--`)
- Diretorios: `755` (`rwx r-x r-x`)
- `configuration.php` do WHMCS: `600` (`rw- --- ---`) — ja deve estar assim

Se precisar corrigir via SSH:

```bash
cd httpdocs/modules/addons/nt_mcp/
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
```

### 4. Ativar o addon no WHMCS

1. Acesse o admin do WHMCS
2. Va em **Setup > Addon Modules**
3. Encontre **NT MCP Server** e clique em **Activate**
4. Na mensagem de sucesso, **copie o Bearer Token exibido** — ele so aparece uma vez

> **IMPORTANTE:** O token e armazenado como hash SHA-256. Apos a ativacao, o token em texto claro nao pode ser recuperado. Se perder, sera necessario regenerar.

### 5. Configurar o Claude Code

Adicione o servidor MCP no arquivo de configuracao do Claude Code:

**Configuracao global** (`~/.claude.json`):

```json
{
  "mcpServers": {
    "whmcs-ntweb": {
      "type": "http",
      "url": "https://seu-whmcs.com/modules/addons/nt_mcp/mcp.php",
      "headers": {
        "Authorization": "Bearer SEU_TOKEN_AQUI"
      }
    }
  }
}
```

**Ou por projeto** (`.claude/settings.json` na raiz do projeto):

```json
{
  "mcpServers": {
    "whmcs-ntweb": {
      "type": "http",
      "url": "https://seu-whmcs.com/modules/addons/nt_mcp/mcp.php",
      "headers": {
        "Authorization": "Bearer SEU_TOKEN_AQUI"
      }
    }
  }
}
```

Substitua `https://seu-whmcs.com` pela URL real do seu WHMCS e `SEU_TOKEN_AQUI` pelo token copiado na etapa 4.

## Configuracao de Seguranca

### Admin User

Por padrao, as chamadas `localAPI()` do WHMCS sao executadas como o usuario `admin`. Para alterar:

1. Acesse **Addons > NT MCP Server** no admin do WHMCS
2. No campo **Admin User para API Local**, informe o username de um administrador ativo
3. Clique em **Salvar Admin User**

O usuario deve ter permissoes suficientes para executar os comandos API utilizados pelas ferramentas.

### IP Allowlist (opcional)

Restrinja o acesso ao endpoint MCP a IPs especificos:

1. Execute no banco de dados do WHMCS:

```sql
INSERT INTO tblconfiguration (setting, value)
VALUES ('nt_mcp_allowed_ips', '203.0.113.10,198.51.100.0/24')
ON DUPLICATE KEY UPDATE value = VALUES(value);
```

2. Use IPs separados por virgula. Suporta CIDR para IPv4 e IPv6:

```
203.0.113.10              # IP exato
198.51.100.0/24           # Range CIDR IPv4
2001:db8::/32             # Range CIDR IPv6
```

3. Se o campo estiver vazio ou nao existir, todos os IPs sao aceitos (comportamento padrao).

### TLS (HTTPS)

O endpoint **rejeita** requisicoes HTTP automaticamente (responde `421 Misdirected Request`). A deteccao considera:

- `$_SERVER['HTTPS']`
- Porta 443
- Header `X-Forwarded-Proto: https` (proxy reverso)

**Para desenvolvimento local**, se necessario desabilitar temporariamente:

```bash
NT_MCP_ALLOW_HTTP=1 php mcp.php
```

Ou defina `NT_MCP_ALLOW_HTTP=1` no `.env` do servidor.

### Regenerar Token

Se o token for comprometido ou perdido:

1. Acesse **Addons > NT MCP Server** no admin do WHMCS
2. Clique em **Regenerar Token**
3. Confirme a acao — o token anterior sera invalidado imediatamente
4. Copie o novo token exibido e atualize o `~/.claude.json`

## Ferramentas Disponiveis

54 ferramentas organizadas em 9 categorias:

| Categoria | Ferramentas | Descricao |
|-----------|:-----------:|-----------|
| **ClientTools** | 8 | Listar, buscar, criar, atualizar e fechar clientes; listar produtos, dominios e faturas do cliente |
| **BillingTools** | 6 | Listar e buscar faturas, criar fatura, registrar pagamento, atualizar fatura, listar transacoes |
| **TicketTools** | 5 | Listar e buscar tickets, abrir ticket, responder, atualizar status/prioridade |
| **ServiceTools** | 5 | Listar servicos, suspender, reativar, terminar, fazer upgrade |
| **DomainTools** | 4 | Listar dominios, registrar, renovar, atualizar nameservers |
| **OrderTools** | 5 | Listar e buscar pedidos, aceitar, cancelar, deletar |
| **CrmTools** | 8 | Contatos/leads, criar lead, atualizar contato, follow-ups, notas, visao Kanban |
| **ProjectManagerTools** | 10 | Projetos, tarefas, cronometro (time tracking), mensagens |
| **SystemTools** | 3 | Estatisticas gerais, envio de email, log de atividades |

> **CRM:** As ferramentas CRM requerem o modulo **ModulesGarden CRM** instalado no WHMCS. Elas acessam as tabelas `mod_mgcrm_contacts`, `mod_mgcrm_followups` e `mod_mgcrm_notes`.

## Rate Limiting

O endpoint aplica limite de **60 requisicoes por minuto por IP**. Ao exceder, retorna `429 Too Many Requests` com header `Retry-After`.

O mecanismo utiliza o cache transiente do WHMCS (`tblconfiguration`). Se indisponivel, faz fallback para arquivos em `/tmp/nt_mcp_rate/`.

## Seguranca

O addon implementa defesa em profundidade em 3 camadas:

1. **Camada de autenticacao** — Bearer Token com hash SHA-256, validacao de tamanho minimo, `hash_equals()` contra timing attacks
2. **Camada de gateway API** — Apenas 37 comandos WHMCS permitidos via allowlist; campos sensiveis (password, credit, permissions) bloqueados estruturalmente
3. **Camada de acesso a dados** — Tabelas e colunas restritas por allowlist no acesso direto ao banco

Controles adicionais:

- 7 security headers (HSTS, CSP, X-Frame-Options, etc.)
- Rate limiting IP-based (60 req/min)
- Audit logging de todas as chamadas de ferramentas no Activity Log do WHMCS
- Protecao CSRF na UI administrativa
- `.htaccess` bloqueando acesso direto a `src/`, `tests/` e `vendor/`
- Validacao de Session ID (hex 16-64 chars)
- Protecao contra host header injection

## Estrutura do Projeto

```
modules/addons/nt_mcp/
  mcp.php                  # Endpoint HTTP (TLS, IP allowlist, rate limit, auth)
  nt_mcp.php               # Hooks WHMCS (activate, deactivate, admin UI)
  composer.json
  .htaccess                # Protecao de diretorios sensiveis
  src/
    Server.php             # Bootstrap MCP, tool discovery, HTTP transport
    Auth/
      BearerAuth.php       # Autenticacao Bearer com SHA-256
    Tools/
      ClientTools.php      # 8 tools
      BillingTools.php     # 6 tools
      TicketTools.php      # 5 tools
      ServiceTools.php     # 5 tools
      DomainTools.php      # 4 tools
      OrderTools.php       # 5 tools
      CrmTools.php         # 8 tools (requer ModulesGarden CRM)
      ProjectManagerTools.php  # 10 tools
      SystemTools.php      # 3 tools
    Whmcs/
      LocalApiClient.php   # Wrapper WHMCS localAPI() com allowlist
      CapsuleClient.php    # Query builder DB com allowlist de tabelas/colunas
  tests/                   # Testes PHPUnit
  vendor/                  # Dependencias (nao versionado)
```

## Troubleshooting

### Token invalido apos atualizar

Se voce atualizou de uma versao anterior que armazenava o token em texto claro, **regenere o token** na tela do addon. A versao atual armazena apenas o hash SHA-256.

### Erro 421 Misdirected Request

O endpoint requer HTTPS. Verifique se:
- O certificado SSL esta ativo
- O WHMCS esta configurado com `System URL` usando `https://`
- Se usa proxy reverso (CloudFlare, nginx), garanta que o header `X-Forwarded-Proto: https` esta sendo enviado

### Erro 403 Forbidden (IP)

Seu IP nao esta na allowlist. Verifique a configuracao `nt_mcp_allowed_ips` no banco ou remova-a para permitir todos os IPs.

### Erro 429 Too Many Requests

Limite de 60 req/min excedido. Aguarde o tempo indicado no header `Retry-After`.

### Ferramentas CRM nao funcionam

As ferramentas `whmcs_crm_*` requerem o modulo **ModulesGarden CRM** instalado e suas tabelas (`mod_mgcrm_*`) presentes no banco.

### Composer nao encontrado no Plesk

Se o comando `composer` nao estiver disponivel no terminal do Plesk:

1. Verifique se ha um binario alternativo: `composer.phar`, `/usr/local/bin/composer`
2. Se nao houver, use a **Opcao B** da etapa 2 (enviar `vendor/` pronto pelo SFTP)
3. Ou instale o Composer manualmente via SSH:
   ```bash
   curl -sS https://getcomposer.org/installer | php
   php composer.phar install --no-dev --ignore-platform-req=ext-iconv
   ```

### Erro 500 no endpoint MCP

Possiveis causas em hospedagem Plesk:

- **PHP < 8.2**: Verifique a versao PHP configurada para o dominio em **Plesk > PHP Settings**. O addon exige PHP 8.2+
- **vendor/ ausente**: O `composer install` nao foi executado — a pasta `vendor/` deve existir dentro de `nt_mcp/`
- **init.php nao encontrado**: O `mcp.php` faz `require __DIR__ . '/../../../init.php'` — confirme que a estrutura de pastas esta correta (3 niveis: `nt_mcp/ → addons/ → modules/ → httpdocs/init.php`)

### Permissoes negadas (Plesk)

Se o Plesk mostra erros de permissao ao acessar o endpoint:

- Verifique que o handler PHP esta configurado para o dominio (Apache module ou PHP-FPM)
- Confirme que o `.htaccess` na raiz do WHMCS permite acesso a `modules/addons/`
- No Plesk, va em **Websites & Domains > Apache & nginx Settings** e garanta que `.htaccess` esta habilitado

## Deploy Rapido (Resumo)

```bash
# 1. No seu computador local
cd modules/addons/nt_mcp/
composer install --no-dev --ignore-platform-req=ext-iconv

# 2. Enviar para o servidor (SFTP, SCP ou Plesk File Manager)
scp -r . usuario@servidor:httpdocs/modules/addons/nt_mcp/

# 3. No admin WHMCS: Setup > Addon Modules > NT MCP Server > Activate

# 4. Copiar o token exibido e configurar ~/.claude.json
```

## Licenca

Proprietario — NT Web.
