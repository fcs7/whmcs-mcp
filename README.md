# NT MCP Server — WHMCS Addon

Servidor MCP (Model Context Protocol) que expoe 54 operacoes WHMCS como ferramentas para o Claude. Funciona como **Conector** — conecta o Claude ao seu WHMCS para gerenciar clientes, faturas, tickets, servicos, dominios, pedidos, projetos e CRM via conversacao.

> **Para a experiencia completa**, combine este Conector com a **Habilidade** (Skill) que ensina o Claude a usar os 54 tools → **[fcs7/whmcs-mcp-plugin](https://github.com/fcs7/whmcs-mcp-plugin)**

### Como os componentes se encaixam

| Conceito | O que faz | Repositorio |
|----------|-----------|-------------|
| **Conector** (este repo) | Expoe 54 tools MCP via HTTP — o Claude *pode* usar | Voce esta aqui |
| **Habilidade** ([plugin repo](https://github.com/fcs7/whmcs-mcp-plugin)) | Ensina o Claude *como* usar os tools — parametros, workflows, boas praticas | [fcs7/whmcs-mcp-plugin](https://github.com/fcs7/whmcs-mcp-plugin) |

> Sem a Habilidade o Claude tem acesso aos tools mas pode errar parametros ou nao saber a melhor sequencia de operacoes. Sem o Conector, a Habilidade nao tem como executar nada.

## Requisitos

- **PHP** >= 8.2
- **WHMCS** 7.x ou 8.x
- **Composer** (para instalar dependencias)
- **HTTPS** obrigatorio (o endpoint rejeita HTTP com status 421)
- **Apache** com `mod_rewrite` (para protecao `.htaccess` e discovery OAuth)

## Instalacao

### 1. Enviar arquivos para o WHMCS

Envie o diretorio `modules/addons/nt_mcp/` para dentro da instalacao do WHMCS. O destino e `httpdocs/modules/addons/nt_mcp/`.

**Via SFTP/SCP:**

```bash
scp -r modules/addons/nt_mcp/ usuario@servidor:httpdocs/modules/addons/nt_mcp/
```

**Via Plesk (File Manager):**

1. Acesse o **File Manager** do Plesk
2. Navegue ate `httpdocs/modules/addons/`
3. Crie a pasta `nt_mcp`
4. Faca upload dos arquivos e pastas: `mcp.php`, `nt_mcp.php`, `oauth.php`, `composer.json`, `composer.lock`, `src/`, `.well-known/`
5. **Nao envie** as pastas `vendor/`, `tests/`, `.full-review/`, `.security-hardening/`, `data/` ou `deploy/`

A estrutura final deve ser:

```
httpdocs/                  # Raiz do WHMCS (Plesk document root)
  .well-known/
    oauth-authorization-server/
      index.php            # RFC 8414 discovery (ver passo 4)
  init.php
  modules/
    addons/
      nt_mcp/
        mcp.php            # Endpoint MCP HTTP
        nt_mcp.php         # Hooks do addon (ativacao, admin UI)
        oauth.php          # Servidor OAuth 2.1 (register, authorize, token)
        composer.json
        composer.lock
        .htaccess          # Protecao de diretorios
        .well-known/
          openid-configuration/
            index.php      # Discovery fallback
        src/
          Server.php       # Bootstrap MCP server
          Auth/
            BearerAuth.php # Autenticacao Bearer + OAuth
          Tools/            # 9 classes com 54 tools
          Whmcs/
            LocalApiClient.php   # Wrapper localAPI() com allowlist
            CapsuleClient.php    # Query builder DB com allowlist
            CompatContainer.php  # PSR-11 container com auto-wiring
        vendor/            # Criado pelo composer (nao versionado)
```

### 2. Instalar dependencias

**Via SSH (recomendado):**

```bash
cd httpdocs/modules/addons/nt_mcp/
composer install --no-dev --ignore-platform-req=ext-iconv
```

**Sem SSH (enviar vendor/ pronto):**

```bash
# No computador local
cd modules/addons/nt_mcp/
composer install --no-dev --ignore-platform-req=ext-iconv
# Depois envie a pasta vendor/ via SFTP ou File Manager
```

### 3. Ativar o addon no WHMCS

1. Acesse o admin do WHMCS
2. Va em **Setup > Addon Modules**
3. Encontre **NT MCP Server** e clique em **Activate**
4. Na mensagem de sucesso, **copie o Bearer Token exibido** — ele so aparece uma vez
5. No campo **Admin User para API Local**, configure o **username** de um administrador ativo do WHMCS (deve existir em `tbladmins`)

> **IMPORTANTE:** O token e armazenado como hash SHA-256. Apos a ativacao, o token em texto claro nao pode ser recuperado. Se perder, regenere na tela do addon.

### 4. Configurar discovery OAuth (rewrite rules)

Para que o Claude Code descubra automaticamente o servidor OAuth, adicione as seguintes regras ao `.htaccess` na **raiz do WHMCS** (`httpdocs/.htaccess`):

```apache
# NT MCP OAuth Discovery (RFC 8414)
RewriteEngine On
RewriteRule ^\.well-known/oauth-authorization-server/?$ /modules/addons/nt_mcp/oauth.php?action=server-metadata [L,QSA]
RewriteRule ^\.well-known/openid-configuration/?$ /modules/addons/nt_mcp/oauth.php?action=server-metadata [L,QSA]
```

Estas regras redirecionam a discovery OAuth padrao para o endpoint do addon. Sem elas, o Claude Code pode demorar 60s no timeout do discovery.

> O arquivo `deploy/htaccess-well-known.conf` contem estas regras prontas para copiar.

### 5. Conectar ao Claude

Apos instalar o addon, conecte o Claude ao servidor. O processo depende de qual cliente voce usa.

---

#### 5a. Claude Code (recomendado — experiencia completa)

> Claude Code = CLI, Desktop app, Web app, IDE extensions.

**Metodo 1 — Plugin (recomendado, automatiza tudo):**

O plugin **[fcs7/whmcs-mcp-plugin](https://github.com/fcs7/whmcs-mcp-plugin)** configura Conector + Habilidade + Hooks de uma vez.

```bash
# 1. Configure a variavel com sua URL
export WHMCS_MCP_URL="https://seu-whmcs.com/modules/addons/nt_mcp/mcp.php"

# 2. Instale o plugin (via /plugin no chat, ou manualmente no settings.json)
```

Veja instrucoes detalhadas no **[README do plugin](https://github.com/fcs7/whmcs-mcp-plugin)**.

**Metodo 2 — So o Conector (sem plugin):**

Se nao quiser o plugin, adicione so o Conector. Adicione ao `~/.claude.json`:

```json
{
  "mcpServers": {
    "whmcs": {
      "type": "http",
      "url": "https://seu-whmcs.com/modules/addons/nt_mcp/mcp.php"
    }
  }
}
```

Na primeira conexao, o fluxo OAuth inicia automaticamente:
1. Descobre o servidor OAuth via RFC 8414
2. Registra um client via Dynamic Client Registration (RFC 7591)
3. Abre o navegador para autorizacao — aprove no painel admin do WHMCS
4. Obtem um token OAuth 2.1 com PKCE (S256) valido por 24 horas (renovado automaticamente)

**Metodo alternativo — Token estatico (sem OAuth):**

```json
{
  "mcpServers": {
    "whmcs": {
      "type": "http",
      "url": "https://seu-whmcs.com/modules/addons/nt_mcp/mcp.php",
      "headers": {
        "Authorization": "Bearer SEU_TOKEN_AQUI"
      }
    }
  }
}
```

Use o token copiado na etapa 3 (ativacao do addon). Este token nao expira ate ser regenerado manualmente.

**Verificar conexao:**

```bash
claude        # iniciar o Claude Code
/mcp          # ver status dos servidores MCP
```

O servidor deve aparecer como `connected` com 54 tools disponiveis.

**Debug:**

```bash
claude --debug-file /tmp/claude_mcp.log
# Em outro terminal:
tail -f /tmp/claude_mcp.log
```

---

#### 5b. Claude Desktop

> Claude Desktop = app standalone da Anthropic. Nao suporta plugins, mas suporta Habilidades e Conectores separadamente.

No Claude Desktop voce configura **2 partes** manualmente:

**Parte 1 — Conector (obrigatorio):**

Va em **Settings > Conectores** e adicione a URL do seu servidor:

```
https://seu-whmcs.com/modules/addons/nt_mcp/mcp.php
```

O OAuth e iniciado automaticamente na primeira chamada de tool.

> **Alternativa via arquivo de configuracao:** Edite `claude_desktop_config.json` (macOS: `~/Library/Application Support/Claude/`, Windows: `%APPDATA%\Claude/`, Linux: `~/.config/Claude/`):
>
> ```json
> {
>   "mcpServers": {
>     "whmcs": {
>       "url": "https://seu-whmcs.com/modules/addons/nt_mcp/mcp.php"
>     }
>   }
> }
> ```

**Parte 2 — Habilidade (recomendado):**

Va em **Settings > Habilidades** e crie uma **habilidade pessoal** com o conteudo do arquivo [`SKILL.md` do plugin](https://github.com/fcs7/whmcs-mcp-plugin/blob/main/skills/whmcs-mcp/SKILL.md).

Sem a Habilidade, o Claude tem acesso aos 54 tools mas nao sabe os parametros de cabeca — pode errar nomes de campo ou esquecer parametros obrigatorios.

**Verificar:**

1. Reinicie o Claude Desktop
2. As 54 tools do WHMCS devem aparecer na lista de ferramentas
3. Teste: pergunte "liste meus clientes do WHMCS"

**Troubleshooting Claude Desktop:**

- **Tools nao aparecem:** Reinicie o Claude Desktop. Verifique se a URL esta correta
- **Timeout na autorizacao:** Verifique se as rewrite rules do passo 4 estao no `.htaccess` raiz do WHMCS
- **Erro de certificado:** Certificado SSL invalido ou auto-assinado

---

#### Resumo: o que voce precisa em cada cenario

| Cenario | Conector | Habilidade | Hooks |
|---------|:--------:|:----------:|:-----:|
| Claude Code + plugin | Auto (`.mcp.json`) | Auto (SKILL.md) | Auto (`hooks.json`) |
| Claude Code sem plugin | Manual (`~/.claude.json`) | — | — |
| Claude Desktop | Manual (Settings > Conectores) | Manual (Settings > Habilidades) | N/A |

---

### 6. Verificar tudo

Apos configurar qualquer cliente, confirme que:

1. **Conexao** — o cliente mostra o servidor como conectado
2. **Tools** — 54 ferramentas visiveis na lista
3. **Execucao** — pergunte "liste os clientes do WHMCS" e confirme que retorna dados reais

## Autenticacao

O servidor suporta dois metodos de autenticacao:

### OAuth 2.1 (recomendado)

Fluxo completo com PKCE S256:
- Dynamic Client Registration (RFC 7591)
- Authorization Code Grant com PKCE (RFC 7636)
- Protected Resource Metadata (RFC 9728)
- Authorization Server Metadata (RFC 8414)

Tabelas criadas automaticamente no primeiro uso:
- `mod_nt_mcp_oauth_clients` — clients registrados
- `mod_nt_mcp_oauth_codes` — auth codes com PKCE
- `mod_nt_mcp_oauth_tokens` — tokens (armazenados como SHA-256 hash)

### Bearer Token estatico

Token gerado na ativacao do addon, armazenado como hash SHA-256 em `tblconfiguration`. Validado via `hash_equals()` (timing-safe).

## Configuracao de Seguranca

### Admin User

As chamadas `localAPI()` do WHMCS sao executadas como o usuario configurado em **Addons > NT MCP Server**. Este usuario **deve existir** na tabela `tbladmins` do WHMCS.

Se nao configurado, o padrao e `admin` — que pode nao existir na sua instalacao.

### IP Allowlist (opcional)

Restrinja o acesso ao endpoint MCP a IPs especificos:

```sql
INSERT INTO tblconfiguration (setting, value)
VALUES ('nt_mcp_allowed_ips', '203.0.113.10,198.51.100.0/24')
ON DUPLICATE KEY UPDATE value = VALUES(value);
```

Suporta IPs individuais, CIDR IPv4 e IPv6. Se vazio, todos os IPs sao aceitos.

### TLS (HTTPS)

O endpoint rejeita HTTP automaticamente (421). A deteccao considera `$_SERVER['HTTPS']`, porta 443 e header `X-Forwarded-Proto`.

### Trusted Proxies (Plesk/nginx)

Se o WHMCS roda atras de um reverse proxy (ex: Plesk com nginx), configure os IPs do proxy para que o rate limiting e IP allowlist usem o IP real do cliente:

```sql
INSERT INTO tblconfiguration (setting, value)
VALUES ('nt_mcp_trusted_proxies', '127.0.0.1,::1')
ON DUPLICATE KEY UPDATE value = VALUES(value);
```

Sem esta configuracao, todos os requests aparecem como `127.0.0.1` e compartilham o mesmo bucket de rate limiting.

### Rate Limiting

Rate limiting por IP em todos os endpoints:

| Endpoint | Limite | Periodo |
|----------|:------:|---------|
| MCP (`mcp.php`) | 60 req | 1 minuto |
| OAuth Register | 20 req | 1 hora |
| OAuth Authorize | 20 req | 1 minuto |
| OAuth Token | 30 req | 1 minuto |

Ao exceder, retorna `429 Too Many Requests` com header `Retry-After`.

## Ferramentas Disponiveis

54 ferramentas organizadas em 9 categorias:

| Categoria | Qty | Descricao |
|-----------|:---:|-----------|
| **ClientTools** | 8 | Listar, buscar, criar, atualizar e fechar clientes; produtos, dominios e faturas |
| **BillingTools** | 6 | Faturas, pagamentos, transacoes |
| **TicketTools** | 5 | Tickets: listar, abrir, responder, atualizar |
| **ServiceTools** | 5 | Servicos: suspender, reativar, terminar, upgrade |
| **DomainTools** | 4 | Dominios: registrar, renovar, nameservers |
| **OrderTools** | 5 | Pedidos: aceitar, cancelar, deletar |
| **CrmTools** | 8 | Contatos/leads, follow-ups, notas, Kanban (requer ModulesGarden CRM) |
| **ProjectManagerTools** | 10 | Projetos, tarefas, time tracking, mensagens |
| **SystemTools** | 3 | Estatisticas, email, activity log |

## Seguranca

Defesa em profundidade em 3 camadas:

1. **Autenticacao** — OAuth 2.1 com PKCE S256 ou Bearer Token SHA-256 (`hash_equals`)
2. **Gateway API** — Allowlist de 37 comandos WHMCS; campos sensiveis bloqueados
3. **Acesso a dados** — Tabelas e colunas restritas por allowlist no acesso direto ao banco

Controles adicionais: security headers (HSTS, CSP, X-Frame-Options), rate limiting, audit logging, CORS, protecao `.htaccess`, validacao de Session ID.

## Troubleshooting

### "Capabilities: none" no Claude Code

1. Verifique se o admin user configurado existe em `tbladmins`
2. Verifique se as rewrite rules do OAuth estao no `.htaccess` raiz
3. Use `claude --debug-file /tmp/claude_mcp.log` para ver erros detalhados

### "Client not initialized" em tools/call

O servidor PHP-FPM perde estado entre requests. O workaround esta implementado em `Server.php`. Se persistir, verifique que o diretorio `data/` dentro do addon existe e tem permissoes de escrita (criado automaticamente com permissao 0700).

### "No matching admin user found"

O admin user configurado nao existe no WHMCS. Va em **Addons > NT MCP Server** e configure um username valido da tabela `tbladmins`.

### Token invalido apos atualizar

Versoes anteriores armazenavam o token em texto claro. A versao atual usa SHA-256. **Regenere o token** na tela do addon.

### Erro 421 Misdirected Request

HTTPS obrigatorio. Verifique certificado SSL, System URL do WHMCS com `https://`, e header `X-Forwarded-Proto` se usa proxy.

### Ferramentas CRM nao funcionam

Requerem o modulo **ModulesGarden CRM** e suas tabelas `mod_mgcrm_*`.

### Erro 500

- PHP < 8.2: verifique versao em **Plesk > PHP Settings**
- `vendor/` ausente: execute `composer install`
- `init.php` nao encontrado: confirme estrutura de 3 niveis (`nt_mcp/ → addons/ → modules/ → httpdocs/init.php`)

## Deploy Rapido (Resumo)

```bash
# 1. Instalar dependencias localmente
cd modules/addons/nt_mcp/
composer install --no-dev --ignore-platform-req=ext-iconv

# 2. Enviar para o servidor
scp -r . usuario@servidor:httpdocs/modules/addons/nt_mcp/

# 3. Adicionar rewrite rules ao .htaccess raiz (ver passo 4 da instalacao)

# 4. No admin WHMCS: Setup > Addon Modules > NT MCP Server > Activate
#    Configurar admin user valido

# 5. Configurar ~/.claude.json com URL do endpoint

# 6. Testar: /mcp no Claude Code
```

## Habilidade (Skill) — Ensinar o Claude a Usar os Tools

Este servidor (Conector) expoe 54 tools via MCP. A **Habilidade** ensina o Claude *como* usa-los — parametros, workflows, boas praticas.

**[fcs7/whmcs-mcp-plugin](https://github.com/fcs7/whmcs-mcp-plugin)** — Conector + Habilidade + Hooks de seguranca

| Cliente | Como instalar a Habilidade |
|---------|---------------------------|
| **Claude Code** | Instale o plugin → tudo automatico (ver passo 5a) |
| **Claude Desktop** | Crie habilidade pessoal com o conteudo do SKILL.md (ver passo 5b) |

```
┌──────────────────────────────┐      ┌──────────────────────────────┐
│  whmcs-mcp  ← este repo     │      │  whmcs-mcp-plugin            │
│  CONECTOR                    │      │  HABILIDADE + HOOKS          │
│                              │      │                              │
│  Addon WHMCS (PHP):          │      │  Claude Code: Plugin auto    │
│  • mcp.php (endpoint HTTP)   │      │  Claude Desktop: SKILL.md    │
│  • oauth.php (OAuth 2.1)     │ ──── │                              │
│  • src/Tools/ (54 tools)     │ MCP  │  • SKILL.md (referencia)     │
│  • src/Auth/ (Bearer+OAuth)  │      │  • .mcp.json (conector auto) │
│                              │      │  • hooks.json (seguranca)    │
│  Roda em: Servidor WHMCS     │      │                              │
│           PHP 8.2+           │      │  github.com/fcs7/            │
│                              │      │  whmcs-mcp-plugin            │
└──────────────────────────────┘      └──────────────────────────────┘
```

## Licenca

Proprietario — NT Web.
