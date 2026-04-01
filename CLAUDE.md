# NT MCP — WHMCS MCP Server Addon

Addon PHP para WHMCS que expõe 96 tools via Model Context Protocol.
Repo: `git@github.com:fcs7/whmcs-mcp.git`

## Commands

```bash
cd modules/addons/nt_mcp
composer install --ignore-platform-req=ext-iconv
./vendor/bin/phpunit --testdox                    # 77 tests, 118 assertions
composer audit                                    # check dependency CVEs
grep -c '#\[McpTool\]' src/Tools/*.php   # 96 tools total
# Deploy pipeline (from repo root)
./scripts/deploy.sh prod   # → novo.ntweb.com.br (producao)
./scripts/deploy.sh dev    # → desenv.ntweb.com.br (desenvolvimento)
./scripts/verify.sh prod   # verificacao pos-deploy
MCP_TOKEN=xxx ./scripts/verify.sh prod  # verificacao com teste MCP autenticado
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
- `src/Server.php` — Bootstrap php-mcp/server, DI via CompatContainer, global LOCK_EX (resources e prompts desabilitados)
- `src/Auth/BearerAuth.php` — Bearer token auth: `authenticate(): ?string` (static + OAuth), per-token admin binding
- `src/Security/` — CsrfProtection (HMAC nonce), RateLimiter (TransientData + file fallback)
- `src/Http/` — IpResolver, IpAllowlist, TlsEnforcer, SecurityHeaders, CorsHandler
- `src/OAuth/` — OAuthRouter, OAuthMigration, OAuthHelper, Handlers/{Token,Authorization,Registration,Metadata}Handler
- `src/Admin/` — AdminController (auth dashboard), OAuthApprovalController (5-layer approval)
- `src/Whmcs/` — LocalApiClient (83 cmd allowlist), CapsuleClient (3 table allowlist), CompatContainer, SystemUrl, AdminSession
- `src/Tools/*.php` — 11 classes, 96 tools: Client(13), Billing(12), System(11), Order(10), ProjectManager(10), Domain(9), CRM(8), SupportInfo(7), Quote(6), Service(5), Ticket(5)
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
- Bearer token: SHA-256 hash + `hash_equals()` timing-safe
- OAuth codes: SHA-256 hash no DB, consumo atômico (`$affected === 0`)
- CSRF: HMAC-SHA256 nonce em todos os forms admin
- Command allowlist: 83 comandos em `LocalApiClient::ALLOWED_COMMANDS`
- Table/column allowlist: 3 tabelas CRM em `CapsuleClient::ALLOWED_TABLES/COLUMNS`
- Trusted proxy IP: `IpResolver::resolve()` — rightmost-untrusted from X-Forwarded-For behind configured proxies
- Content-Length guard: Server.php rejeita >1MB
- customfields: json_encode (sem serialize), max 50 fields, 8KB, scalar-only
- Passwords stripped de responses (ClientTools, ServiceTools)
- Audit log: API calls logados com params sensíveis redactados
- Admin action audit: logActivity() em regenerate_token, revoke_token, remove_client (ações destrutivas UI)
- Per-token admin binding: cada token registra qual admin o criou/aprovou
- File access: 5 .htaccess (root, data/, src/, vendor/, tests/) — whitelist apenas mcp.php, oauth.php, nt_mcp.php
- CapsuleClient query limit: MAX 500 rows por SELECT (hard-clamped)

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
- **Global lock serializa requests** — Server.php usa LOCK_EX em `data/nt_mcp_global.lock`; aceitável para 1-3 admins, gargalo para 5+
- **Audit fix IDs** — comentários `// SECURITY FIX (F1)` a `(F8)` + `(M-02)` referenciam findings da auditoria de production readiness; não remover
- **Excluir do deploy**: `.full-review/`, `.security-hardening*/`, `.phpunit.cache/`, `data/` (runtime state)
- **property_exists() guard** — colunas novas (`admin_user`, `approved_by`, `last_used_at`) podem não existir em DBs pré-migration; usar `property_exists($row, 'col')` antes de acessar
- **Pending audit findings** — F-05, F-10, F-12 resolvidos. Resolvidos no refactor: F-07 (RateLimiter), F-11 (TokenHandler). Mitigados: F-06 (IpAllowlist), F-14 (SystemUrl — intencional)
- **Semgrep PHP parser** — não suporta constructor promotion com `readonly` (PHP 8.2); RateLimiter gera PartialParsing warning, findings nesse arquivo podem ser incompletos
