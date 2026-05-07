# NT MCP Server — WHMCS Addon

Servidor MCP (Model Context Protocol) que expoe 86 ferramentas WHMCS como ferramentas para o Claude. Funciona como **Conector** — conecta o Claude ao seu WHMCS para gerenciar clientes, faturas, tickets, servicos, dominios, pedidos, projetos e CRM via conversacao.

> **Para a experiencia completa**, combine este Conector com a **Habilidade** (Skill) que ensina o Claude a usar os 86 tools → **[fcs7/whmcs-mcp-plugin](https://github.com/fcs7/whmcs-mcp-plugin)**

### Como os componentes se encaixam

| Conceito | O que faz | Repositorio |
|----------|-----------|-------------|
| **Conector** (este repo) | Expoe 86 tools MCP via HTTP — o Claude *pode* usar | Voce esta aqui |
| **Habilidade** ([plugin repo](https://github.com/fcs7/whmcs-mcp-plugin)) | Ensina o Claude *como* usar os tools — parametros, workflows, boas praticas | [fcs7/whmcs-mcp-plugin](https://github.com/fcs7/whmcs-mcp-plugin) |

> Sem a Habilidade o Claude tem acesso aos tools mas pode errar parametros ou nao saber a melhor sequencia de operacoes. Sem o Conector, a Habilidade nao tem como executar nada.

## Requisitos

- **PHP** >= 8.2
- **WHMCS** 7.x ou 8.x
- **Composer** (para instalar dependencias)
- **HTTPS** obrigatorio (o endpoint rejeita HTTP com status 421)
- **Apache** com `mod_rewrite` (para protecao `.htaccess` e discovery OAuth)

## Instalacao

### 0. Preparacao inicial

Antes de comecar, voce precisa:

1. **Clonar ou baixar este repositorio:**
   ```bash
   git clone https://github.com/fcs7/whmcs-mcp.git
   cd whmcs-mcp
   ```
   Ou baixe o ZIP em https://github.com/fcs7/whmcs-mcp/releases

2. **Acessar seu servidor WHMCS** via SSH ou Plesk File Manager

3. **Determinar o caminho raiz do WHMCS:**
   - Em hosting Plesk: `/home/seu_usuario/public_html/` ou `/home/seu_usuario/httpdocs/`
   - Procure pelos arquivos: `init.php`, `admin/index.php`, `modules/`
   - Este sera chamado de `{WHMCS_ROOT}` nos passos abaixo

### 1. Enviar arquivos para o WHMCS

Envie o diretorio `modules/addons/nt_mcp/` para `{WHMCS_ROOT}/modules/addons/nt_mcp/`.

**IMPORTANTE:** Nao envie: `vendor/`, `tests/`, `.full-review/`, `.security-hardening/`, `data/`, `.git/`, `deploy/`

#### Opcao A: Via SSH (RECOMENDADO)

Se voce tem acesso SSH ao servidor:

```bash
# No seu computador, dentro do repo clonado:
scp -r modules/addons/nt_mcp/ usuario@seu-servidor:public_html/modules/addons/nt_mcp/

# OU se estiver dentro do servidor:
cp -r modules/addons/nt_mcp/ /home/seu_usuario/public_html/modules/addons/nt_mcp/
```

#### Opcao B: Via Plesk File Manager (SEM SSH)

Se nao tem acesso SSH, use o File Manager do Plesk:

1. **Acesse** o Plesk da sua hospedagem (geralmente https://seu-servidor:8443)
2. **Navegue** ate `File Manager > httpdocs/modules/addons/`
3. **Crie a pasta** `nt_mcp` (clique direito > New Folder)
4. **Faca upload** dos seguintes arquivos/pastas (em ordem):
   - `mcp.php`
   - `oauth.php`
   - `nt_mcp.php`
   - `composer.json`
   - `composer.lock`
   - `.htaccess`
   - `src/` (pasta inteira)
   - `.well-known/` (pasta inteira)

   **Dica:** Compacte `src/` e `.well-known/` em ZIP antes do upload, depois extraia no Plesk para acelerar.

5. **Verifique** a estrutura na apos o upload (ver abaixo)

#### Opcao C: Via FTP/SFTP (OUTRO CLIENTE)

Use um cliente FTP como FileZilla, WinSCP ou Cyberduck:

1. **Conecte-se** ao seu servidor FTP/SFTP
2. **Navegue** ate `httpdocs/modules/addons/`
3. **Crie a pasta** `nt_mcp`
4. **Faca upload** dos arquivos (mesma lista acima)

#### Estrutura esperada apos o upload:

```
{WHMCS_ROOT}/                # Exemplo: /home/seu_usuario/public_html/
  init.php
  modules/
    addons/
      nt_mcp/               # ← Voce esta aqui apos o upload
        mcp.php             # Endpoint principal MCP (START_POINT)
        oauth.php           # Servidor OAuth 2.1
        nt_mcp.php          # Addon hooks (ativacao, admin UI)
        composer.json
        composer.lock
        .htaccess           # Protecao: rejeita acesso direto a vendor/, src/, etc
        
        src/
          Server.php        # Bootstrap php-mcp/server
          Auth/
            BearerAuth.php  # Autenticacao Bearer token + OAuth 2.1
          OAuth/
            OAuthRouter.php
            Handlers/
          Tools/
            Client.php      # 12 tools de clientes
            Order.php       # 9 tools de pedidos
            ...             # (9 classes no total)
          Whmcs/
            LocalApiClient.php   # Wrapper com allowlist de comandos
            CapsuleClient.php    # Query builder com allowlist
            CompatContainer.php  # PSR-11 container
          Admin/
            AdminController.php  # Dashboard admin
          Http/
            IpResolver.php       # CIDR proxy resolution
          Security/
            ...
          
        .well-known/
          openid-configuration/
            index.php       # RFC 8414 fallback
        
        vendor/             # Criado pelo composer no passo 2
        tests/              # (NAO envie - fica local)
        deploy/             # (NAO envie - templates para seu deploy)
```

**Checklist apos o upload:**
- [ ] Arquivo `mcp.php` existe em `modules/addons/nt_mcp/`
- [ ] Pasta `src/` contem ao menos `Server.php` e `Auth/BearerAuth.php`
- [ ] Arquivo `.htaccess` esta presente (protege a pasta)
- [ ] Permissoes: pasta com 755, arquivos com 644 (se aplicavel)

### 2. Instalar dependências (Composer)

O addon usa PHP Composer para gerenciar dependências. Você **PRECISA** completar este passo antes de ativar o addon.

#### Opcao A: Via SSH (RECOMENDADO)

Se voce tem acesso SSH:

```bash
# Conecte ao servidor via SSH, depois execute:
cd /home/seu_usuario/public_html/modules/addons/nt_mcp
composer install --no-dev --ignore-platform-req=ext-iconv
```

**Que verifica:** Cria a pasta `vendor/` com todas as bibliotecas necesarias.

#### Opcao B: Sem SSH - Instalar localmente e enviar

Se seu hosting **nao tem SSH**, faca isso na sua maquina:

```bash
# 1. Certifique-se que tem Composer instalado
# Download em https://getcomposer.org/download/

# 2. No seu computador, dentro do repo clonado:
cd modules/addons/nt_mcp
composer install --no-dev --ignore-platform-req=ext-iconv

# 3. Isso cria a pasta vendor/ localmente
# Agora envie vendor/ para o servidor:
#    - Via Plesk File Manager (compacte em ZIP primeiro)
#    - Via FTP/SFTP
#    - Via seu cliente de sincronizacao

# Exemplo com SCP (se SSH estiver disponivel para upload):
scp -r vendor/ usuario@seu-servidor:public_html/modules/addons/nt_mcp/
```

**IMPORTANTE:** A pasta `vendor/` **DEVE** estar presente para o addon funcionar.

#### Verifica se funcionou:

```bash
# No servidor, verifique:
ls -la /home/seu_usuario/public_html/modules/addons/nt_mcp/vendor/

# Deve haver varias pastas: autoload.php, php-mcp/, psr/, etc.
```

### 3. Ativar o addon no WHMCS

#### Passo A: Acessar a tela de addons

1. **Abra** seu admin WHMCS (geralmente `https://seu-servidor/admin/`)
2. Autentique-se como administrador
3. **Navegue** para: **Setup** > **Addon Modules**
4. **Procure** por **NT MCP Server** na lista (use Ctrl+F se necessario)
5. **Clique em** **Activate** (botao verde)

#### Passo B: Configurar o addon

Apos clicar Activate, você verá uma tela com:

1. **Bearer Token (gerado automaticamente):**
   ```
   Token: sk-xxxxxxxxxxxxxxxxxxxxxxxxxx
   ```
   - **COPIE este token agora** — ele so é exibido uma UNICA VEZ
   - Se perder, você tera que regenerar na tela do addon depois
   - Guarde em um lugar seguro (1Password, .env, etc)

2. **Admin User para API Local:**
   - Escolha o **username** de um administrador WHMCS ativo
   - Deve ser alguem que existe em `Setup > Administrator Users`
   - Exemplo: `admin` (usuario padrao) ou `seu_admin_user`
   - Este usuario sera usado para chamar a WHMCS Local API em seu nome

   **Por que?** O addon precisa de permissao para chamar comandos WHMCS. Ele usa este usuario administrativo para fazer isso de forma controlada.

3. **Clique em** **Salvar** ou **Continue**

**Apos ativar:**
- O addon aparecera em **Setup > Addon Modules > NT MCP Server** com opcoes:
  - **Configurar** — Alterar usuario admin, renovar token, revogar clientes OAuth
  - **Desativar** — Para desabilitar o addon

### 4. Configurar discovery OAuth (rewrite rules)

O Claude Code e outros clientes OAuth precisam descobrir o servidor OAuth 2.1 automaticamente via RFC 8414. Para isso, adicione regras ao `.htaccess` na **raiz do WHMCS**.

#### Localizacao do arquivo:

- Se WHMCS esta em `/home/seu_usuario/public_html/` → arquivo é `/home/seu_usuario/public_html/.htaccess`
- Se WHMCS esta em `/home/seu_usuario/httpdocs/` → arquivo é `/home/seu_usuario/httpdocs/.htaccess`

#### Opcao A: Via Plesk File Manager

1. **Acesse** Plesk File Manager
2. **Navegue** para a raiz (httpdocs/)
3. **Procure** por `.htaccess` (use "Show hidden files" se nao aparecer)
4. **Clique em** `.htaccess` > **Edit**
5. **Adicione** as seguintes linhas **ANTES** de qualquer outra regra RewriteRule:

```apache
# NT MCP OAuth Discovery (RFC 8414)
RewriteEngine On
RewriteRule ^\.well-known/oauth-authorization-server/?$ /modules/addons/nt_mcp/oauth.php?action=server-metadata [L,QSA]
RewriteRule ^\.well-known/openid-configuration/?$ /modules/addons/nt_mcp/oauth.php?action=server-metadata [L,QSA]
```

6. **Clique em** **Save**

#### Opcao B: Via SSH ou FTP

```bash
# SSH - abra o .htaccess com seu editor favorito:
nano /home/seu_usuario/public_html/.htaccess

# Ou use um cliente FTP para editar remotamente
# Abra em um editor de texto, adicione as regras no topo, salve
```

#### Se o arquivo .htaccess nao existir:

1. **Crie um novo arquivo** chamado `.htaccess`
2. **Adicione o conteudo:**

```apache
RewriteEngine On
RewriteBase /

# NT MCP OAuth Discovery (RFC 8414)
RewriteRule ^\.well-known/oauth-authorization-server/?$ /modules/addons/nt_mcp/oauth.php?action=server-metadata [L,QSA]
RewriteRule ^\.well-known/openid-configuration/?$ /modules/addons/nt_mcp/oauth.php?action=server-metadata [L,QSA]

# Suas outras regras WHMCS aqui...
```

3. **Envie** para a raiz do WHMCS

#### Verifica se funcionou:

```bash
# Teste via curl:
curl -I https://seu-servidor/.well-known/oauth-authorization-server

# Deve retornar HTTP 200 e conteudo JSON com openid_configuration_endpoint, token_endpoint, etc.
# Se retornar 404 ou erro, rewrite nao funcionou — check .htaccess
```

**Sem este passo:** O Claude Code pode demorar ate 60s tentando descobrir o servidor OAuth, ou nao conseguir conectar ao addon.

### 5. Verificacao pos-instalacao (ANTES de conectar ao Claude)

Antes de configurar o Claude Code, verifique que tudo esta funcionando:

#### Verificacao 1: Arquivos no lugar

```bash
# SSH - verifique a estrutura:
ls -la /home/seu_usuario/public_html/modules/addons/nt_mcp/

# Deve mostrar:
# - mcp.php
# - oauth.php
# - nt_mcp.php
# - composer.json
# - src/ (pasta)
# - vendor/ (pasta - criada pelo composer)
```

#### Verificacao 2: Addon ativado no WHMCS

1. **Admin WHMCS** > **Setup** > **Addon Modules**
2. **Procure** por **NT MCP Server**
3. **Status** deve ser **Active** (nao Inactive)
4. **Se nao aparecer:** Pode ser um erro de `vendor/` faltando ou permissoes erradas

#### Verificacao 3: Bearer Token guardado

1. **Admin WHMCS** > **Setup** > **Addon Modules** > **NT MCP Server** > **Configurar**
2. **Verifique** que há um token exibido (comeca com `sk-`)
3. Se nao houver, clique **Regenerar Token** e copie

#### Verificacao 4: Discovery OAuth funcionando

```bash
# Teste a descoberta:
curl -s https://seu-servidor/.well-known/oauth-authorization-server | jq .

# Deve retornar um JSON com campos como:
# {
#   "issuer": "https://seu-servidor",
#   "authorization_endpoint": "...",
#   "token_endpoint": "...",
#   ...
# }

# Se retornar 404 ou erro, volte ao Passo 4 e check .htaccess
```

#### Verificacao 5: Permissoes de arquivo

```bash
# SSH - verifique permissoes:
stat /home/seu_usuario/public_html/modules/addons/nt_mcp/mcp.php

# Deve ser -rw-r--r-- (644) ou similar
# Pastas devem ter drwxr-xr-x (755)

# Se estiverem erradas, corrija:
chmod 755 /home/seu_usuario/public_html/modules/addons/nt_mcp/
chmod 644 /home/seu_usuario/public_html/modules/addons/nt_mcp/*.php
chmod -R 755 /home/seu_usuario/public_html/modules/addons/nt_mcp/src
```

#### Troubleshooting comum nesta fase:

| Problema | Solucao |
|----------|---------|
| Addon nao aparece em Setup > Addon Modules | Verifique se `vendor/` existe. Se nao, rode `composer install`. Se ainda nao aparecer, reinicie o WHMCS ou limpe o cache do navegador. |
| Bearer Token nao aparece na tela do addon | Token foi perdido? Clique **Regenerar Token** para criar um novo. |
| Discovery retorna 404 | Rewrite rules do `.htaccess` nao estao funcionando. Verifique se `RewriteEngine On` esta ativo e as regras estao no topo. |
| Permissao negada ao acessar mcp.php | Permissoes erradas. Rode `chmod` conforme acima. |

Apos passar por todas as verificacoes, siga para o Passo 6 (Conectar ao Claude).

### 6. Conectar ao Claude

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

O servidor deve aparecer como `connected` com 86 tools disponiveis.

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

Sem a Habilidade, o Claude tem acesso aos 86 tools mas nao sabe os parametros de cabeca — pode errar nomes de campo ou esquecer parametros obrigatorios.

**Verificar:**

1. Reinicie o Claude Desktop
2. As 86 tools do WHMCS devem aparecer na lista de ferramentas
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
2. **Tools** — 86 ferramentas visiveis na lista
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

86 ferramentas organizadas em 11 categorias:

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

Este servidor (Conector) expoe 86 tools via MCP. A **Habilidade** ensina o Claude *como* usa-los — parametros, workflows, boas praticas.

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
│  • src/Tools/ (86 tools)     │ MCP  │  • SKILL.md (referencia)     │
│  • src/Auth/ (Bearer+OAuth)  │      │  • .mcp.json (conector auto) │
│                              │      │  • hooks.json (seguranca)    │
│  Roda em: Servidor WHMCS     │      │                              │
│           PHP 8.2+           │      │  github.com/fcs7/            │
│                              │      │  whmcs-mcp-plugin            │
└──────────────────────────────┘      └──────────────────────────────┘
```

## Licenca

Proprietario — NT Web.
