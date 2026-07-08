# NT MCP — WHMCS MCP Server Addon

Addon PHP para WHMCS que expõe 73 tools via Model Context Protocol.
Repo: `git@github.com:fcs7/whmcs-mcp.git`

## Commands

```bash
cd modules/addons/nt_mcp
composer install --ignore-platform-req=ext-iconv
./vendor/bin/phpunit --testdox                    # 103+ tests
composer audit                                    # check dependency CVEs
grep -c '#\[McpTool(' src/Tools/*.php    # 73 tools total
# Deploy manual via FTP (senha interativa — from modules/addons/nt_mcp/)
lftp -u desenvnt5442 -e "set ssl:verify-certificate no; mirror -R --only-newer --exclude .git/ --exclude vendor/ --exclude .phpunit.cache/ --exclude .full-review/ --exclude .security-hardening/ --exclude .security-hardening-archive-20260329/ --exclude data/ . /httpdocs/modules/addons/nt_mcp/; bye" desenv.ntweb.com.br
# Verify: download prod and diff against git
lftp -u desenvnt5442 -e "set ssl:verify-certificate no; mirror --exclude vendor/ --exclude .git/ --exclude data/ --exclude .phpunit.cache/ /httpdocs/modules/addons/nt_mcp/ /tmp/nt_mcp_prod_check/; bye" desenv.ntweb.com.br && for f in $(find . -name '*.php' -not -path './vendor/*' | sort); do diff -q "$f" "/tmp/nt_mcp_prod_check/$f" 2>/dev/null && echo "OK $f" || echo "DIFF $f"; done; rm -rf /tmp/nt_mcp_prod_check
```

## Architecture

- `mcp.php` — Slim entry: TLS → CORS → IP allowlist → headers → rate limit → BearerAuth → Server::run()
- `oauth.php` — Slim entry: TLS → headers → CORS → OAuthMigration → OAuthRouter::dispatch()
- `nt_mcp.php` — WHMCS addon entry (_config/_activate/_output → AdminController/OAuthApprovalController)
- `.well-known/openid-configuration/index.php` — RFC 8414 metadata discovery (redireciona para oauth.php)
- `src/Server.php` — Entry: auth → lock (timeout 5s) → `PhpMcpV1Adapter::handle()` → resposta; body >1MB rejeitado (resources e prompts desabilitados)
- `src/Mcp/` — `ServerAdapterInterface` + `PhpMcpV1Adapter`: isola a lib php-mcp/server pinada; discovery condicional (pula `discover()` com cache quente) + 11 Tools pré-registradas no container
- `src/Auth/BearerAuth.php` — Bearer token auth: `authenticate(): ?string` (static + OAuth), per-token admin binding
- `src/Security/` — CsrfProtection (HMAC nonce), RateLimiter (TransientData + file fallback)
- `src/Http/` — IpResolver, IpAllowlist, TlsEnforcer, SecurityHeaders, CorsHandler
- `src/OAuth/` — OAuthRouter, OAuthMigration, OAuthHelper, Handlers/{Token,Authorization,Registration,Metadata}Handler
- `src/Admin/` — AdminController (auth dashboard), OAuthApprovalController (5-layer approval)
- `src/Whmcs/` — LocalApiClient (60 cmd allowlist, somente não-destrutivas), CapsuleClient (3 table allowlist), CompatContainer, SystemUrl, AdminSession
- `src/Tools/*.php` — 11 classes, 73 tools: Client(12), System(10), ProjectManager(9), CRM(8), Order(7), SupportInfo(7), Billing(5), Domain(5), Ticket(5), Quote(4), Service(1) — tools de risco removidas (2026-04: 10 destrutivas/financeiras; 2026-07: 13 adicionais)
- `templates/admin/` — dashboard.php, oauth-approve.php (output escapado via htmlspecialchars)

### Admin Binding Flow

- `mcp.php` chama `BearerAuth::authenticate()` → retorna admin username vinculado ao token
- Admin propagado para `Server::run($adminUser)` → usado em todas as LocalAPI calls
- Fallback chain: per-token admin_user → global `nt_mcp_admin_user` config → hardcoded 'admin'
- Static token: admin lido de `nt_mcp_bearer_token_admin` (tblconfiguration)
- OAuth token: admin lido de `mod_nt_mcp_oauth_tokens.admin_user` (propagado de `approved_by` na aprovação)

## OAuth 2.1 Flow

- `oauth.php` roda com `define('CLIENTAREA', true)` — NÃO tem acesso à sessão admin
- Cookies admin WHMCS são path-scoped a `/admin/` — nunca enviados para `/modules/`
- Authorization: oauth.php cria pending request no DB → redireciona para `/admin/addonmodules.php?module=nt_mcp&authorize=REQUEST_ID`
- A aprovação ocorre em `OAuthApprovalController` (via `nt_mcp_output()`), não em oauth.php
- `addonmodules.php` = output page (`_output()`); `configaddonmods.php` = config page (activate/deactivate)
- Addon precisa de permissão no role group: Configuration > Addon Modules > NT MCP > Access Control
- DB tables: `mod_nt_mcp_oauth_clients`, `mod_nt_mcp_oauth_codes`, `mod_nt_mcp_oauth_tokens`
- DB columns adicionais (migration lazy via hasColumn): `tokens.admin_user`, `tokens.last_used_at`, `codes.approved_by`
- Admin auto-detect na UI: `$_SESSION['adminid']` → `tbladmins.username` (confiável — cookies admin path-scoped)

## Conventions

- Tools: `#[McpTool(name: 'whmcs_*', description: '...')]` — retornam `json_encode(..., JSON_PRETTY_PRINT)`
- LocalAPI tools injetam `LocalApiClient`, CRM tools injetam `CapsuleClient`
- Não usar try/catch nos tools — o framework captura exceções automaticamente
- PHP 8.2+ obrigatório (PHPUnit 11)

## Security Layers (do not remove)

- TLS enforced em mcp.php e oauth.php (bypass: `NT_MCP_ALLOW_HTTP=1` só p/ dev local)
- Rate limiting: mcp 60/min, register 20/hr, authorize 20/min, token 30/min — TransientData + file fallback
- CORS origin allowlist: `nt_mcp_cors_origins` (CSV em tblconfiguration) — vazia=`*`; definida+origin-no-allowlist=origem específica+`Vary: Origin`; definida+origin-fora-do-allowlist=sem header (browser bloqueia); sem `HTTP_ORIGIN` (CLI)=`*`
- Bearer token: SHA-256 hash + `hash_equals()` timing-safe
- OAuth codes: SHA-256 hash no DB, consumo atômico (`$affected === 0`)
- CSRF: HMAC-SHA256 nonce em todos os forms admin
- Command allowlist: 60 comandos em `LocalApiClient::ALLOWED_COMMANDS`
- Table/column allowlist: 3 tabelas CRM em `CapsuleClient::ALLOWED_TABLES/COLUMNS`
- Trusted proxy IP: `IpResolver::resolve()` — usa `\App::getClientIp()` do WHMCS quando disponível (coherence guard contra spoof em conexão direta); `isTrustedProxy()` mescla Trusted Proxies nativo (aba Security, chave `TrustedProxyIps`) ∪ `nt_mcp_trusted_proxies` (aditivo/opcional); fallback rightmost-untrusted XFF
- Content-Length guard: Server.php rejeita >1MB
- customfields: json_encode (sem serialize), max 50 fields, 8KB, scalar-only
- Passwords stripped de responses (ClientTools, ServiceTools)
- Audit log: API calls logados com params sensíveis redactados
- Admin action audit: logActivity() em regenerate_token, revoke_token, remove_client (ações destrutivas UI)
- Per-token admin binding: cada token registra qual admin o criou/aprovou
- File access: 5 .htaccess (root, data/, src/, vendor/, tests/) — whitelist apenas mcp.php, oauth.php, nt_mcp.php
- CapsuleClient query limit: MAX 500 rows por SELECT (hard-clamped)
- Write-class gate (WO-2): `LocalApiClient` classifica cada comando (READ/WRITE/DESTRUCTIVE/FINANCIAL/COST/COMMS). WRITE on por padrão; classes default-DENY (DESTRUCTIVE/FINANCIAL/COST/COMMS) agora vazias por remoção física de tools (mecanismo mantido como defesa em profundidade); master switch `nt_mcp_readonly` (fail-closed). Espelhado em `CapsuleClient::assertWritable()`. Impersonação clampada: `adminid`/`adminusername` forçados ao admin do token
- Admin fail-closed (WO-7): sem `nt_mcp_admin_user` resolvível, `BearerAuth` e `Server::run()` negam (401) — nunca vinculam ao superadmin `admin`

## Gotchas

- **Autoloader order CRÍTICO** — `vendor/autoload.php` DEVE ser carregado ANTES de `init.php` em todo entry point (`mcp.php`, `oauth.php`). WHMCS carrega `psr/log` v1 (params sem type hints); nosso vendor tem v3 (typed `string|\Stringable`). Se `init.php` carrega primeiro, v1 é registrada e qualquer classe v3 (incluindo `NullLogger` dentro de `php-mcp/server`) causa **fatal declaration compatibility** silencioso — sem output, sem shutdown handler, sem log.
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
- **Lock global com timeout** — `Server::acquireLockWithTimeout()` faz `LOCK_EX|LOCK_NB` por até 5s → responde 503+`Retry-After` no timeout em vez de bloquear o worker FPM indefinidamente (era a causa da cascata 504 no `/admin`). Ainda serializa requests
- **Custo por request (perfilado)** — dominante NÃO era o discovery, e sim `queueMessageForAll`: a lib enfileira uma cópia da resposta (~5.5KB) para CADA cliente ativo; clientes que não voltam nunca drenam `mcp_state_messages_<id>` → cache single-file incha e a latência quadratiza (sessão única que reusa o session-id é FLAT ~120ms; o storm só aparece com múltiplas sessões/flood de session-ids). Mitigações: (i) `PhpMcpV1Adapter::trackActiveClientAndGc()` poda clientes ociosos (`CLIENT_TTL=600s`) + teto rígido (`MAX_ACTIVE_CLIENTS=50`), deletando filas órfãs; (ii) `discover()` só no cold cache (`mcp_state_elements` sem TTL, invalidado no `nt_mcp_upgrade()`). **Débito #1 remanescente:** o single-file FileCache continua o gargalo real p/ alta concorrência — fix definitivo é cache por-cliente/Redis
- **Débito deferido conscientemente** — split do IpResolver (322L) NÃO feito (recém-unificado no WO-TP e testado; risco > ganho); o `str_replace('"properties":[]'→'{}')` em Server.php e o `LoggerInterface` anônimo são workarounds de compat mantidos de propósito. Constructor injection dos seams do BearerAuth adicionado, setters mantidos como back-compat
- **Audit fix IDs** — comentários `// SECURITY FIX (F1)` a `(F8)` + `(M-02)` referenciam findings da auditoria de production readiness; não remover
- **Excluir do deploy**: `.full-review/`, `.security-hardening*/`, `.phpunit.cache/`, `data/` (runtime state)
- **property_exists() guard** — colunas novas (`admin_user`, `approved_by`, `last_used_at`) podem não existir em DBs pré-migration; usar `property_exists($row, 'col')` antes de acessar
- **Pending audit findings** — F-05, F-10, F-12 resolvidos. Resolvidos no refactor: F-07 (RateLimiter), F-11 (TokenHandler). Mitigados: F-06 (IpAllowlist), F-14 (SystemUrl — intencional)
- **Semgrep PHP parser** — não suporta constructor promotion com `readonly` (PHP 8.2); RateLimiter gera PartialParsing warning, findings nesse arquivo podem ser incompletos
- **`deploy/htaccess-well-known.conf`** — regras RewriteRule a inserir no `.htaccess` da raiz WHMCS (antes das regras WHMCS existentes) para que Claude.ai auto-descubra o OAuth 2.1 via RFC 8414 (`/.well-known/oauth-authorization-server`); sem esse passo, Custom Connector do Claude.ai não consegue descobrir os endpoints
- **Trusted proxy unificado (WO-TP)** — `IpResolver` reusa o IP resolvido pelo WHMCS (`\App::getClientIp()`) e mescla a lista nativa `TrustedProxyIps` (aba Security) ∪ `nt_mcp_trusted_proxies`. Consequências: (i) proxies da lista NATIVA também autorizam `X-Forwarded-Proto` e `NT_MCP_ALLOW_HTTP` no `TlsEnforcer` — liste só proxies próprios na aba Security; (ii) o caminho nativo honra o "Proxy IP Header" (ex.: CF-Connecting-IP), mas o fallback só lê `X-Forwarded-For`; (iii) se a chave nativa não for `TrustedProxyIps` na versão instalada, a unificação vira no-op — observável pelo error_log "X-Forwarded-For present but no trusted proxies configured". `nt_mcp_trusted_proxies` é agora opcional/aditivo
- **Config obrigatória pré-deploy** — `nt_mcp_admin_user` DEVE estar setado antes do deploy (senão 401 fail-closed, ver WO-7); operador também configura `nt_mcp_allowed_ips`, `nt_mcp_cors_origins`, e (opcional) `nt_mcp_trusted_proxies` / Trusted Proxies nativo do WHMCS
