# NT MCP — WHMCS MCP Server Addon

Addon PHP para WHMCS que expõe 54 tools via Model Context Protocol.
Repo: `git@github.com:fcs7/whmcs-mcp.git`

## Commands

```bash
cd modules/addons/nt_mcp
composer install --ignore-platform-req=ext-iconv
./vendor/bin/phpunit --testdox                    # 41 tests, 66 assertions
composer audit                                    # check dependency CVEs
grep -c '#\[McpTool\]' src/Tools/*.php   # 54 tools total
# Deploy via FTP (senha interativa — from modules/addons/nt_mcp/)
lftp -u desenvnt5442 -e "set ssl:verify-certificate no; mirror -R --only-newer --exclude .git/ --exclude vendor/ --exclude .phpunit.cache/ --exclude .full-review/ --exclude .security-hardening/ --exclude .security-hardening-archive-20260329/ --exclude data/ . /httpdocs/modules/addons/nt_mcp/; bye" desenv.ntweb.com.br
# Verify: download prod and diff against git
lftp -u desenvnt5442 -e "set ssl:verify-certificate no; mirror --exclude vendor/ --exclude .git/ --exclude data/ --exclude .phpunit.cache/ /httpdocs/modules/addons/nt_mcp/ /tmp/nt_mcp_prod_check/; bye" desenv.ntweb.com.br && for f in $(find . -name '*.php' -not -path './vendor/*' | sort); do diff -q "$f" "/tmp/nt_mcp_prod_check/$f" 2>/dev/null && echo "OK $f" || echo "DIFF $f"; done; rm -rf /tmp/nt_mcp_prod_check
```

## Architecture

- `nt_mcp.php` — Entry point WHMCS (_config/_activate/_output)
- `oauth.php` — OAuth 2.1 endpoint (register, authorize, token, metadata discovery)
- `mcp.php` — Endpoint HTTP: init.php → BearerAuth → Server::run()
- `src/Server.php` — Bootstrap php-mcp/server, DI via CompatContainer
- `src/Auth/BearerAuth.php` — Bearer token validation (static hash + OAuth tokens via SHA-256)
- `src/Whmcs/LocalApiClient.php` — Wrapper localAPI(), throws RuntimeException on error
- `src/Whmcs/CapsuleClient.php` — Direct DB via Capsule ORM (select/insert/update/delete)
- `src/Whmcs/CompatContainer.php` — PSR-11 container com auto-wiring (bridge PSR v1/v2)
- `src/Tools/*.php` — 9 classes com #[McpTool] para auto-discovery

## OAuth 2.1 Flow

- `oauth.php` roda com `define('CLIENTAREA', true)` — NÃO tem acesso à sessão admin
- Cookies admin WHMCS são path-scoped a `/admin/` — nunca enviados para `/modules/`
- Authorization: oauth.php cria pending request no DB → redireciona para `/admin/addonmodules.php?module=nt_mcp&authorize=REQUEST_ID`
- A aprovação ocorre em `nt_mcp_output()` (contexto admin autenticado), não em oauth.php
- `addonmodules.php` = output page (`_output()`); `configaddonmods.php` = config page (activate/deactivate)
- Addon precisa de permissão no role group: Configuration > Addon Modules > NT MCP > Access Control
- DB tables: `mod_nt_mcp_oauth_clients`, `mod_nt_mcp_oauth_codes`, `mod_nt_mcp_oauth_tokens`

## Conventions

- Tools: `#[McpTool(name: 'whmcs_*', description: '...')]` — retornam `json_encode(..., JSON_PRETTY_PRINT)`
- LocalAPI tools injetam `LocalApiClient`, CRM tools injetam `CapsuleClient`
- Não usar try/catch nos tools — o framework captura exceções automaticamente
- PHP 8.2+ obrigatório (PHPUnit 11)

## Security Layers (do not remove)

- TLS enforced em mcp.php e oauth.php (bypass: `NT_MCP_ALLOW_HTTP=1` só p/ dev local)
- Rate limiting: 4 endpoints (mcp, register, authorize, token) via TransientData + file fallback
- Bearer token: SHA-256 hash + `hash_equals()` timing-safe
- OAuth codes: SHA-256 hash no DB, consumo atômico (`$affected === 0`)
- CSRF: HMAC-SHA256 nonce em todos os forms admin
- Command allowlist: 42 comandos em `LocalApiClient::ALLOWED_COMMANDS`
- Table/column allowlist: 3 tabelas CRM em `CapsuleClient::ALLOWED_TABLES/COLUMNS`
- Trusted proxy IP: `_ntMcpGetClientIp()` (mcp.php), `_oauthGetClientIp()` (oauth.php)
- Content-Length guard: Server.php rejeita >1MB
- customfields: json_encode (sem serialize), max 50 fields, 8KB, scalar-only
- Passwords stripped de responses (ClientTools, ServiceTools)
- Audit log: API calls logados com params sensíveis redactados

## Gotchas

- **Admin session path-scoping** — cookies admin só são enviados para `/admin/*`, não funcionam em `/modules/addons/`
- **CLIENTAREA vs ADMINAREA** — `define('CLIENTAREA', true)` carrega sessão cliente; para sessão admin usar redirect ao painel admin
- **Addon access control** — cada addon precisa permissão explícita por role group (Setup > Addon Modules > Configure > Access Control)
- **Deploy** — via `lftp` com `set ssl:verify-certificate no` (SSH indisponível no Plesk)
- **Não commitar debug logs** — nunca usar `@file_put_contents('/tmp/...')` em código; usar logging estruturado
- **CRM table names são placeholders** (`mod_mgcrm_*` em CrmTools.php) — verificar no banco real
- **mcp.php** requer `__DIR__ . '/../../../init.php'` (3 níveis até raiz WHMCS)
- **php-mcp/server API real** difere da documentação web: usar HttpTransportHandler, CompatContainer, ArrayConfigurationRepository
- **ext-iconv** pode não estar habilitada — usar `--ignore-platform-req=ext-iconv` no composer
- **Bearer Token** armazenado em tblconfiguration, gerado na ativação do addon
- **Nunca criar debug/token files no servidor** — `debug-log.php` e `mcp-make-token.php` são backdoors; usar WHMCS Activity Log
- **Sempre comparar git vs prod** antes e depois de deploy — servidor pode ter arquivos extras ou versões antigas
- **lftp requer senha interativa** — sem senha, falha silenciosamente ("assume anonymous login")
- **php-mcp/server pinado em ^1.0** (atual 1.1.0) — v3.x é breaking change, não atualizar sem branch dedicada
- **Global lock serializa requests** — Server.php usa LOCK_EX em `data/nt_mcp_global.lock`; aceitável para 1-3 admins, gargalo para 5+
- **Audit fix IDs** — comentários `// SECURITY FIX (F1)` a `(F8)` + `(M-02)` referenciam findings da auditoria de production readiness; não remover
- **Excluir do deploy**: `.full-review/`, `.security-hardening*/`, `.phpunit.cache/`, `data/` (runtime state)
