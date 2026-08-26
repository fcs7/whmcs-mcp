# NT MCP — WHMCS MCP Server Addon

Addon PHP para WHMCS que expõe 70 tools via Model Context Protocol.
Repo: `git@github.com:fcs7/whmcs-mcp.git`

## Commands

```bash
cd modules/addons/nt_mcp
composer install --ignore-platform-req=ext-iconv   # OBRIGATÓRIO em worktree novo: vendor/ não é versionado
./vendor/bin/phpunit --testdox                    # tests
composer audit                                    # check dependency CVEs
rg -o "name: '[a-z_0-9]+'" src/Tools/*.php | wc -l  # 70 tools total (rg -o '#\[McpTool' dá 72: conta os comentários "sem #[McpTool]" de QuoteTools.php e ChipTools.php)
# Deploy manual via FTP (from modules/addons/nt_mcp/). A credencial DEV compartilhada
# fica fora do git no repo do tema 2026; nunca pedir a senha novamente nem copiá-la
# para este repositório.
source /home/fcs/Documents/ntweb/whmcs-tema-2026/whmcs-theme/.env.deploy
lftp -u "desenvnt5442,$FTP_PASS" -e "set ssl:verify-certificate ${FTP_SSL_VERIFY_CERTIFICATE:-yes}; set ftp:ssl-force ${FTP_SSL_FORCE:-yes}; set ftp:ssl-protect-data ${FTP_SSL_FORCE:-yes}; mirror -R --only-newer --exclude .git/ --exclude vendor/ --exclude tests/ --exclude data/ --exclude .phpunit.cache/ --exclude .omc/ --exclude .full-review/ --exclude .security-hardening/ --exclude .security-hardening-archive-20260329/ . /httpdocs/modules/addons/nt_mcp/; bye" 191.7.26.232
# Deploy com vendor/ (troca de lib SDK — sem --exclude vendor/)
lftp -u "desenvnt5442,$FTP_PASS" -e "set ssl:verify-certificate ${FTP_SSL_VERIFY_CERTIFICATE:-yes}; set ftp:ssl-force ${FTP_SSL_FORCE:-yes}; set ftp:ssl-protect-data ${FTP_SSL_FORCE:-yes}; mirror -R --only-newer --exclude .git/ --exclude tests/ --exclude data/ --exclude vendor/bin/ --exclude .phpunit.cache/ --exclude .omc/ --exclude .full-review/ --exclude .security-hardening/ --exclude .security-hardening-archive-20260329/ . /httpdocs/modules/addons/nt_mcp/; bye" 191.7.26.232
# Testes de subprocesso (McpEndpointHttpTest, DiagnosticBoundaryTest) falham no PHP 8.5 local — rodar em container:
# `-u 1000:1000` e `zend.exception_ignore_args=1` são OBRIGATÓRIOS: como root o chmod 0000 do
# SecureFileSessionStoreTest não bloqueia a escrita, e sem php.ini os args entram no stack trace,
# fazendo o CrmExceptionTest ver "PDOException" no (string) da exceção. Duas falhas ambientais.
docker run --rm -v "$PWD:/app" -w /app -u 1000:1000 php:8.3-cli-bookworm php -d zend.exception_ignore_args=1 vendor/bin/phpunit
# Lint na versão do desenv (8.1) — a suite roda em 8.3 e o php local é 8.5; nenhum pega sintaxe 8.2+
docker run --rm -v "$PWD:/app" -w /app php:8.1-cli-bookworm sh -c 'find src -name "*.php" -exec php -l {} \;' | grep -v "^No syntax errors"
# Verify desenv: download deployed tools and count MCP attributes (usa o mesmo
# $FTP_PASS carregado acima)
lftp -u "desenvnt5442,$FTP_PASS" -e "set ssl:verify-certificate ${FTP_SSL_VERIFY_CERTIFICATE:-yes}; set ftp:ssl-force ${FTP_SSL_FORCE:-yes}; set ftp:ssl-protect-data ${FTP_SSL_FORCE:-yes}; mirror /httpdocs/modules/addons/nt_mcp/src/Tools/ /tmp/nt_mcp_desenv_check/src/Tools/; bye" 191.7.26.232 && test -f /tmp/nt_mcp_desenv_check/src/Tools/CrmTools.php && rg -o '#\[McpTool' /tmp/nt_mcp_desenv_check/src/Tools/*.php | wc -l
# Testar tools ao vivo via MCP Inspector CLI (precisa Bearer token do dashboard admin nt_mcp).
# URL termina em .php (não /mcp) => --transport é obrigatório; com --transport, usar --server-url (não posicional).
npx -y @modelcontextprotocol/inspector --cli --transport http --server-url "https://<host>/modules/addons/nt_mcp/mcp.php" \
  --header "Authorization: Bearer $TOKEN" --method tools/list
# tools/call: acrescentar --method tools/call --tool-name <nome> --tool-arg chave=valor
```

## Architecture

- `mcp.php` — Slim entry: TLS → CORS → IP allowlist → headers → rate limit → BearerAuth → Server::run()
- `oauth.php` — Slim entry: TLS → headers → CORS → OAuthMigration → OAuthRouter::dispatch()
- `nt_mcp.php` — WHMCS addon entry (_config/_activate/_output → AdminController/OAuthApprovalController)
- `.well-known/openid-configuration/index.php` — RFC 8414 metadata discovery (redireciona para oauth.php)
- `src/Server.php` — Entry: auth → método (POST/DELETE; GET 405) → M-02 1 MB guard → batch JSON-RPC (400 -32600) → PSR-7 → adapter → emit
- `src/Mcp/` — `ServerAdapterInterface` + `McpSdkAdapter`: builder do SDK com discovery por atributo cacheada em `data/cache/mcp_elements.json` via `FileElementCache` (PSR-16, `unserialize` com allowlist fechada de 4 classes + pré-varredura, arquivo 0600); sessões `SecureFileSessionStore` (dir 0700, arquivos 0600, chmod fail-closed) em `data/sessions/` TTL 1h GC 1/20; `SessionLock` (um arquivo flock POR SESSÃO em `data/session-locks/`, nome = `sess-<sha256 do id>.lock`, GC 1/20 de arquivos > 1h não segurados, 503+Retry-After se indisponível) serializa requests do mesmo `Mcp-Session-Id`; `RuntimeDirs::provision()` cria/endurece os 3 dirs na ativação e no upgrade; `StreamableHttpTransport` com `middleware: [ProtocolVersionMiddleware]` (header inválido → 400) e `maxBodyBytes` 1 MiB; `setProtocolVersion(2025-11-25)` explícito
- `src/Auth/BearerAuth.php` — Bearer token auth: `authenticate(): ?string` (static + OAuth), per-token admin binding
- `src/Security/` — CsrfProtection (HMAC nonce), RateLimiter (TransientData + file fallback)
- `src/Http/` — IpResolver, IpAllowlist, TlsEnforcer, SecurityHeaders, CorsHandler
- `src/OAuth/` — OAuthRouter, OAuthMigration, OAuthHelper, Handlers/{Token,Authorization,Registration,Metadata}Handler
- `src/Admin/` — AdminController (auth dashboard), OAuthApprovalController (5-layer approval)
- `src/Whmcs/` — LocalApiClient (73 cmd allowlist + gates READ/WRITE/DESTRUCTIVE/FINANCIAL/COST/COMMS), ResponseRedactor, CompatContainer, SystemUrl, AdminSession, GateSettings (leitura das flags de gate — ponto único, usada por LocalApiClient e ChipGuard), ChipBridge/ChipGuard (integração com o addon nt_chips)
- `src/Tools/*.php` — 12 tool classes, 70 tools: Client(12), ProjectManager(9), Order(7), Quote(7), Chip(6), System(6), Ticket(5), Domain(5), Billing(5), CRM(4), SupportInfo(3), Service(1)
- `templates/admin/` — dashboard.php, oauth-approve.php (output escapado via htmlspecialchars)

### Admin Binding Flow

- `mcp.php` chama `BearerAuth::authenticate()` → retorna admin username vinculado ao token
- Admin propagado para `Server::run($adminUser)` → usado em todas as LocalAPI calls
- Fallback chain: per-token admin_user → global `nt_mcp_admin_user` config → fail closed (401)
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

- Nome de tool DEVE começar com `whmcs_` — `McpSdkAdapterTest` assere o prefixo em TODAS, inclusive em domínio que não é WHMCS (por isso `whmcs_chip_*` e não `chip_*`).
- Tools: `#[McpTool]` (from `Mcp\Capability\Attribute\McpTool`) — retornam `json_encode(..., JSON_PRETTY_PRINT)`
- CRM READ tools usam `#[Schema(additionalProperties:false)]` + `#[Schema(minimum:…)]` e retornam `CallToolResult::error()` para envelopes de erro
- LocalAPI tools injetam `LocalApiClient`; CRM tools injetam `MgCrmRepository`
- Não usar try/catch nos tools — o framework captura exceções automaticamente
- PHP 8.1+ (composer `platform.php=8.1.34` — desenv/prod rodam 8.1; sem `readonly class`, sem tipo `true`, sem enum em const de array); PHPUnit ^10.5

## Current Tool Policy

- Expor somente as quatro leituras reais do ModulesGarden CRM via `MgCrmRepository`; writes CRM legados não são descobertos.
- Tools LocalAPI passam por `LocalApiClient::ALLOWED_COMMANDS` e gates de classe de efeito colateral.
- TODAS as classes de efeito colateral ficam bloqueadas por padrão — WRITE inclusive (`gateEnabled()` passa `default: false` para todas). Cada uma exige opt-in próprio (`nt_mcp_enable_write`, `nt_mcp_enable_destructive`, …), com valor canônico `'1'` (`'on'`/`'true'`/`'yes'` NÃO são aceitos por `ConfigFlag`).
- Cotações cobrem listar, obter, criar, atualizar, duplicar, converter em fatura e excluir. `whmcs_convert_quote_to_invoice` passa pelo gate FINANCIAL (AcceptQuote + UpdateInvoice) e `whmcs_delete_quote` pelo DESTRUCTIVE. Não existe `whmcs_send_quote`: `SendQuote` está fora do allowlist (classe COMMS).
- Saída de TODA tool passa por `ToolJson::encode()` e a resposta da LocalAPI por `ResponseRedactor::normalizeResponse()` — não usar `json_encode()` direto numa tool nova.
- Chips (`ChipTools`, 6 tools `whmcs_chip_*`) são o ÚNICO domínio que não passa pela LocalAPI — não existe comando WHMCS para chips. Escrevem via `NtChips\ChipRepository` (addon nt_chips, mesmo docroot/Capsule) atrás de `ChipBridge`, e a autorização vem de `ChipGuard` (mesmas flags: `nt_mcp_readonly` + `nt_mcp_enable_write` + allowlist de clientes do #14). `whmcs_chip_assign_service` exige `confirm=true`. Nenhuma tool devolve `lpa_code` nem coluna `pdf_*`: a projeção de `ChipBridge::project()` é allowlist de colunas.

## Security Layers (do not remove)

- TLS enforced em mcp.php e oauth.php (bypass: `NT_MCP_ALLOW_HTTP=1` só p/ dev local)
- Rate limiting: mcp 60/min, register 20/hr, authorize 20/min, token 30/min — TransientData + file fallback
- CORS origin allowlist: `nt_mcp_cors_origins` (CSV em tblconfiguration) — vazia=`*`; definida+origin-no-allowlist=origem específica+`Vary: Origin`; definida+origin-fora-do-allowlist=sem header (browser bloqueia); sem `HTTP_ORIGIN` (CLI)=`*`
- Bearer token: SHA-256 hash + `hash_equals()` timing-safe
- OAuth codes: SHA-256 hash no DB, consumo atômico (`$affected === 0`)
- CSRF: HMAC-SHA256 nonce em todos os forms admin
- Command allowlist: 55 comandos em `LocalApiClient::ALLOWED_COMMANDS`
- CRM read-only: `CrmSchema` e os objetos `CrmSelect`/`CrmCount` fecham tabelas, projeções, filtros e limites
- Trusted proxy IP: `IpResolver::resolve()` — usa `\App::getClientIp()` do WHMCS quando disponível (coherence guard contra spoof em conexão direta); `isTrustedProxy()` mescla Trusted Proxies nativo (aba Security, chave `TrustedProxyIps`) ∪ `nt_mcp_trusted_proxies` (aditivo/opcional); fallback rightmost-untrusted XFF
- Content-Length guard: Server.php rejeita >1MB; transport maxBodyBytes = 1 MiB (hard limit)
- Batch JSON-RPC rejeitado antes do SDK (rate limit por request): invalid requests → 400 -32600
- `customfields` público entra como JSON (máx. 50 campos/8KB, valores somente
  escalares/null), mas `AddClient`/`UpdateClient` exigem na LocalAPI o wire
  format legado Base64 de array PHP serializada. Só serializar DEPOIS dessa
  validação fechada; o conector nunca faz `unserialize()` do input do chamador.
- Passwords stripped de responses (ClientTools, ServiceTools)
- Audit log: API calls logados com params sensíveis redactados
- Admin action audit: logActivity() em regenerate_token, revoke_token, remove_client (ações destrutivas UI)
- Per-token admin binding: cada token registra qual admin o criou/aprovou
- File access: 5 .htaccess (root, data/, src/, vendor/, tests/) — whitelist apenas mcp.php, oauth.php, nt_mcp.php
- CRM query limits são fechados em `MgCrmRepository`/`CrmSchema`; não há executor CRM de escrita publicado
- Write-class gate (WO-2): `LocalApiClient` classifica cada comando (READ/WRITE/DESTRUCTIVE/FINANCIAL/COST/COMMS). TODAS bloqueadas por padrão, WRITE inclusive (opt-in `nt_mcp_enable_*` = `'1'`); master switch `nt_mcp_readonly` (fail-closed). Sem opt-in, `AddTicketReply` e afins morrem no gate de CLASSE — antes da allowlist por alvo (#14), que só é consultada depois; por isso o Activity Log mostra `MCP API BLOCKED BY GATE` e nunca `BLOCKED BY TARGET ALLOWLIST` enquanto a classe estiver off. `AcceptQuote`=FINANCIAL (gera fatura). Impersonação clampada: `adminid`/`adminusername` forçados ao admin do token
- Gate por alvo (#14): `nt_mcp_write_allowlist_clientids` / `nt_mcp_write_allowlist_ticketids` (CSV, opcionais, vazias = sem restrição). Comando não-READ com `clientid`/`userid`/`ticketid` fora da lista → `write_target_not_allowed` (Activity Log `MCP API BLOCKED BY TARGET ALLOWLIST`). Sem `clientid`, o dono é resolvido ANTES do gate via `GetTicket` (`ticketid`), `GetOrders id=` (`orderid`) ou `GetQuotes quoteid=` (`quoteid`) — registro deve ter `id` igual ao pedido, senão nega; guest/órfão (userid 0) não é checado pela lista de clientes. Config inválida/ilegível = nega tudo. Não cobre comandos sem id de alvo (`AddClient`, projetos/To-Do). Tool-level: `open_ticket` sem `clientid` exige `allow_guest=true`; `reply_ticket` só aceita `name/email/clientid` em ticket guest
- Admin fail-closed (WO-7): sem `nt_mcp_admin_user` resolvível, `BearerAuth` e `Server::run()` negam (401) — nunca vinculam ao superadmin `admin`
- Recusa de autorização visível ao cliente (#29): `AuthorizationAwareReferenceHandler` envolve o `ReferenceHandler` do SDK (`Builder::setReferenceHandler()`) e converte `AuthorizationException` em `ToolCallException`. Motivo: o `CallToolHandler` do SDK só preserva a mensagem de `ToolCallException` (→ `CallToolResult::error()`); qualquer outro `\Throwable` vira `-32603 "Error while executing tool"` com a mensagem DESCARTADA. `ToolCallException` é `final`, daí composição e não herança. Ponto ÚNICO de tradução — tool nova não precisa (e não deve) de try/catch. Exceção de outro tipo continua subindo intacta: falha real tem que seguir sendo `-32603`, nunca ganhar cara de recusa tratada.
- Middleware do SDK: só `ProtocolVersionMiddleware` ligado (spec: `MCP-Protocol-Version` inválido → 400; ausente tolerado). CorsMiddleware/DnsRebinding desligados de propósito — CORS/IP/TLS são nossos, em mcp.php. Perfil CORS do mcp.php = `POST, DELETE, OPTIONS` (DELETE encerra sessão); oauth.php continua `POST, OPTIONS`
- Lock por sessão (`SessionLock`): o `FileSessionStore` do SDK faz read-modify-write sem lock — requests concorrentes com o mesmo `Mcp-Session-Id` perdiam resposta e cruzavam respostas entre clientes (reproduzido). Lock segurado até depois do `emit`; initialize (sem header) não trava. UM arquivo por sessão (era 64 faixas `crc32 % 64`, que serializava sessões DIFERENTES caídas na mesma faixa — com o lock segurado pelo request inteiro, um cliente parado derrubava outro com 503)
- Isolamento de desenv/prod: `nt_mcp_expected_host` (opcional, recomendado) — verifica se hostname do request bate com a config; mismatch retorna 403 `host_mismatch`. Evita que um token reaproveitado acesse um WHMCS errado

## Gotchas

- **Autoloader order CRÍTICO** — `vendor/autoload.php` DEVE ser carregado ANTES de `init.php` em todo entry point (`mcp.php`, `oauth.php`). WHMCS carrega `psr/log` v1 (params sem type hints); nosso vendor tem v3 (typed `string|\Stringable`). Se `init.php` carrega primeiro, v1 é registrada e qualquer classe v3 (incluindo logger do SDK) causa **fatal declaration compatibility** silencioso — sem output, sem shutdown handler, sem log. Logger anônimo é passado explicitamente ao builder e ao transport para contornar.
- **Mesma armadilha vale pra QUALQUER interface PSR que o WHMCS também ship** (2026-08-23) — não só psr/log. `FileElementCache implements Psr\SimpleCache\CacheInterface` totalmente tipado colidiu com o `psr/simple-cache` v1 (sem tipos) que o WHMCS carrega; a ordem de autoload não resolve isso porque o WHMCS registra SUA PRÓPRIA cópia da interface, não a nossa. Sintoma: request autenticado nunca respondia, nginx cortava com 504 aos 60s (parecia hang de discovery, era fatal `Declaration ... must be compatible` engolido). Fix: parâmetro sem type-hint (contravariância aceita as duas) + retorno DECLARADO (covariância exige, senão quebra contra a v3 nossa) — mesmo padrão de `CompatContainer::get($id): mixed`. Antes de implementar QUALQUER interface `Psr\*`, comparar a assinatura contra `vendor/psr/<pacote>` do SERVIDOR (via lftp), não só a nossa.
- **`fileperms()` sem `clearstatcache()` = bug silencioso** (2026-08-23) — `fopen($path,'c')` cria arquivo com 0644 (umask do Plesk), não 0600. Checar `fileperms()` logo após `chmod()` SEM `clearstatcache(true, $path)` antes lê o stat CACHEADO de antes do chmod — um chmod bem-sucedido aparenta ter falhado. Bug real em `SessionLock::acquire()` e no tmp file de `FileElementCache::persist()`: 503 "Session busy" só na primeira request de cada faixa/arquivo novo, sem nenhuma contenção. Qualquer chmod-então-fileperms() novo no addon precisa do `clearstatcache()` no meio.
- **Objeto do WHMCS numa resposta vira `{}` no JSON** (2026-08-23) — `json_encode` de um objeto sem propriedade PÚBLICA produz `{}`. Vários campos monetários (`GetStats.income_*`, `stats.*` de `GetClientsDetails`, `amount` de line item, `grace_period.price` de `GetTLDPricing`) são formatadores com estado protegido, e as passadas de redação só desciam em `is_array` — passavam intactos. `ResponseRedactor::materialize()` achata objeto por método (`jsonSerialize` → `DateTimeInterface` → `toNumeric()` = `{amount, formatted}` → `__toString()` → `get_object_vars()` → `null`). Detecção por `method_exists`, nunca por nome de classe: o core é ionCube e a classe pode mudar entre versões.
- **A ORDEM do pipeline de saída é contrato de segurança** (2026-08-23) — `normalizeResponse()` faz materialize → normalizeTypes → ensureListKeys → **scrub por último**. Achatar objeto e decodificar JSON-string CRIA arrays novos; se o scrub rodar antes (como rodava), um `password` dentro de objeto ou de campo JSON-string nunca é visitado. Ao acrescentar qualquer passada que materialize dado, ela entra ANTES do scrub.
- **Data-zero do WHMCS nem sempre é `0000-00-00`** (2026-08-23) — várias datas vêm já FORMATADAS no locale da instalação (`GetQuotes.validuntil/datecreated/datesent`), e aí a sentinela é `00/00/0000`. Foi por isso que só `datepaid` virava `null` e as outras três não. `ZERO_DATE_PATTERN` cobre `0000-00-00[ 00:00:00]`, `00/00/0000`, `00-00-0000` e `00.00.0000`.
- **Coleção vazia SOME do payload** (2026-08-23) — `GetContacts`/`GetToDoItems`/`GetClientGroups`/`GetClientsAddons` omitem a chave inteira quando não há resultado; `GetClientsProducts.products` e `domains` vêm como `""`. `ResponseRedactor::GUARANTEED_LIST_KEYS` reinsere as omitidas como `[]`, enquanto `EMPTY_STRING_MEANS_EMPTY_LIST` normaliza as presentes. Allowlist por comando/chave e só com nome CONFIRMADO no payload real — chave errada cria campo fantasma, que é pior que a ausência.
- **`json_encode()` devolve `false`, não lança** (2026-08-23) — UTF-8 inválido (dado legado em latin1) fazia o `false` bater no `: string` da tool, virar `TypeError` e chegar ao cliente como `-32603` genérico. Toda tool serializa por `ToolJson::encode()` (`JSON_INVALID_UTF8_SUBSTITUTE` + erro estruturado se ainda assim falhar). Não usar `json_encode()` direto no retorno de tool.
- **WHMCS LocalAPI não é uniforme na chave de outcome** (2026-08-23) — a maioria dos comandos devolve `result: success|error`, mas `GetInvoice` (confirmado; possivelmente outros) devolve `status` no lugar. `LocalApiClient` lê `$result['result'] ?? $result['status'] ?? null` — `status` só é fallback quando `result` está AUSENTE, nunca sobrepõe. Se outro comando parecer cair sempre no ramo "indeterminado"/downstream mesmo com erro classificável no `ErrorClassifier`, suspeitar desta inconsistência primeiro.
- **Debug de fatal/hang sem SSH neste Plesk** — `logs/error_log` (raiz FTP) e `logs/php-fpm_error.log` não capturam `error_log()` explícito de forma confiável (o segundo fica permanentemente em 0 bytes; `ini_get('error_log')` retorna vazio = stderr, que não chega em nenhum log acessível via FTP). Único método que funciona: subir script PHP temporário (nome aleatório + segredo aleatório na query string, deletado logo após o uso — aprovado caso a caso pelo usuário, não é o backdoor que este arquivo proíbe) que replica o trecho suspeito com `display_errors=1` e ecoa o resultado direto na resposta HTTP. Mesma técnica serve pra `opcache_reset()` quando não há acesso ao painel Plesk pra restart do PHP-FPM.
- **Integração nt_chips: NUNCA dar require no `vendor/autoload.php` do nt_chips** — o build de produção deles roda PHP-Scoper com `Illuminate\` e `Psr\` FORA da lista de prefixados; carregar aquele vendor registra uma segunda cópia dessas interfaces e cai no mesmo fatal silencioso de "declaration compatibility" dos gotchas de PSR acima. Com o addon ATIVO o `hooks.php` dele já registra o autoloader no `init.php`, então `class_exists('\NtChips\ChipRepository')` resolve sozinho; o fallback de `ChipBridge` registra um `spl_autoload_register` só para o prefixo `NtChips\` → `modules/addons/nt_chips/src/`. As três classes usadas (`ChipRepository`, `PlayApiClient`, `AtivacaoService`) dependem só da Capsule do WHMCS e de curl nativo. nt_chips pede PHP >=8.2 no composer, mas essas três usam só `match` (8.0) e readonly property (8.1) — rodam no 8.1 do desenv. Nomes reais que divergem do óbvio: `setLpaCode()` (não `saveLpa`), `setIccid()` com CAS, `serviceOccupant()` que exclui o próprio chip (é o que torna o assign idempotente). E a armadilha pior: `PlayApiClient::consultarStatusIccid()` é consulta de LINHA (devolve `msisdn` de chip já ativado) — quem produz `play_status` é `checarIccid()`, classificado por `AtivacaoService::classificarIccid()` (discrimina pelo `success` da Play; o 409 "ICCID já alocado" vem com `success:false` e ainda assim é `alocado`, então precisa ser testado ANTES do catch-all). Não reimplementar essa leitura: errar para `alocado` libera o vínculo com ICCID que a Play recusou. Use `AtivacaoService::validarChip($chipId)`, que já persiste o status.
- **Adicionar/remover tool ou comando do `ALLOWED_COMMANDS` quebra testes de contagem hardcoded** — `McpEndpointHttpTest` (1x), `McpSdkAdapterTest` (3x asserts em 2 métodos), `FileElementCacheTest` (2x), `LocalApiClientTest` (3x: total de comandos, órfãos, distribuição READ/WRITE/...), `LocalApiClientGateTest` (lista de comandos "removidos" testados como rejeitados). O número também está no NOME de dois métodos (`tools_list_returns_exactly_N_tools_with_whmcs_prefix`, `warm_cache_still_lists_N_tools`) — renomear junto. Não são regressão real — são o contrato sendo travado de propósito; atualizar os números junto com a tool nova, não só o código de produção.
- **Admin session path-scoping** — cookies admin só são enviados para `/admin/*`, não funcionam em `/modules/addons/`
- **CLIENTAREA vs ADMINAREA** — `define('CLIENTAREA', true)` carrega sessão cliente; para sessão admin usar redirect ao painel admin
- **`mcp.php` NÃO é CLIENTAREA** — o endpoint MCP é serviço autenticado. Definir
  `CLIENTAREA` antes do bootstrap faz a `AddClient` válida cair na fronteira de
  cadastro público do WHMCS e voltar a mensagem genérica de recuperação de
  conta. Confirmado ao vivo em 2026-08-26: o payload idêntico funciona sem a
  constante. `oauth.php` continua CLIENTAREA de propósito.
- **Timers do Project Manager usam nomes inconsistentes** — `StartTaskTimer`
  recebe `taskid`, mas `EndTaskTimer` exige a chave `timerid` para o MESMO ID da
  tarefa. A interface MCP mantém `taskid` e traduz apenas a chamada de término.
- **Addon access control** — cada addon precisa permissão explícita por role group (Setup > Addon Modules > Configure > Access Control)
- **Deploy** — via `lftp` com `set ssl:verify-certificate no` (SSH indisponível no Plesk)
- **Não commitar debug logs** — nunca usar `@file_put_contents('/tmp/...')` em código; usar logging estruturado
- **CRM dependency** — `capabilities.crm` mede a disponibilidade das leituras do schema real mgCRM2 (`available|unavailable|unknown`). Falha CRM não afeta o restante do conector
- **mcp.php** requer `__DIR__ . '/../../../init.php'` (3 níveis até raiz WHMCS)
- **ext-iconv** pode não estar habilitada — usar `--ignore-platform-req=ext-iconv` no composer
- **Bearer Token** armazenado em tblconfiguration, gerado na ativação do addon
- **Nunca criar debug/token files no servidor** — `debug-log.php` e `mcp-make-token.php` são backdoors; usar WHMCS Activity Log
- **Sempre comparar git vs prod** antes e depois de deploy — servidor pode ter arquivos extras ou versões antigas
- **Credencial FTP DEV compartilhada** — fonte de verdade: `/home/fcs/Documents/ntweb/whmcs-tema-2026/whmcs-theme/.env.deploy` (arquivo local 0600, fora do git). Carregar com `source` antes do `lftp`; nunca pedir a senha ao usuário nem gravá-la neste repositório. O host operacional é `191.7.26.232` e o usuário é `desenvnt5442`
- **`mcp/sdk` pinado em 0.7.1** (pre-1.0) — v0.6→v0.7 renomeou classes (`HttpTransportHandler` → `StreamableHttpTransport`, etc.); subir de versão só em branch dedicada
- **`php-http/discovery` é plugin composer** — `allow-plugins` já no composer.json; sem isso, `composer install` falha
- **Deploy com troca de lib PRECISA incluir `vendor/`** — o comando padrão exclui vendor; usar comando "deploy com vendor/" listado em Commands
- **`data/sessions/`, `data/cache/` e `data/session-locks/` devem ser excluídos do deploy e são 0700** — sessões dinâmicas + cache de discovery + locks; excluir sempre (o comando padrão já exclui `data/`)
- **`nt_mcp_upgrade()` apaga `mcp_elements.json`** — quando há mudança de schema (tools/prompts novos), isso força rediscovery. Também limpa legacy `mcp_state.json` e reprovisiona `data/{cache,sessions,session-locks}` 0700. Sessões NÃO são afetadas (arquivos próprios). `nt_mcp_config()['version']` referencia `McpSdkAdapter::SERVER_VERSION` (atual `2.3.0`) — subir a constante é o que dispara o upgrade no WHMCS. **A chave do cache NÃO é derivada do conteúdo/mtime dos arquivos de tools** — é `md5(basePath+directories+excludeDirs)`, sempre igual. Deploy que adiciona/remove tool SEM bumpar `SERVER_VERSION` continua servindo a contagem antiga até `data/cache/mcp_elements.json` ser apagado manualmente (via lftp `rm`) ou a versão subir. Desde 2.1.0, ativação e upgrade chamam `nt_mcp_warm_element_cache()` (→ `McpSdkAdapter::warmElementCache()`, builder com `setLazyLoading(false)`): o cache é regravado ali, fora do caminho de request — antes o PRIMEIRO request pagava o discovery inteiro SEGURANDO o `SessionLock`, o que aparecia no cliente como `DeadlineExceeded`.
- **Versão de protocolo**: SDK 0.7.1 conhece `2024-11-05`, `2025-03-26`, `2025-06-18`, `2025-11-25` (header) e responde SEMPRE `2025-11-25` no initialize, qualquer que seja a pedida — não há negociação para baixo nem modo stateless. Transporte é stateful (sessão obrigatória após initialize)
- **Audit fix IDs** — comentários `// SECURITY FIX (Fn)`/`(M-02)` referenciam findings da auditoria de production readiness; não remover. Com a migração pro SDK, os fixes F5 (lock_open_failed) e F6 (resposta vazia → -32603) do Server.php antigo ficaram obsoletos: não há mais lock global e o SDK responde sempre. M-02 (1 MB) continua em Server.php + `maxBodyBytes`
- **Pending audit findings** — F-05, F-10, F-12 resolvidos. Resolvidos no refactor: F-07 (RateLimiter), F-11 (TokenHandler). Mitigados: F-06 (IpAllowlist), F-14 (SystemUrl — intencional)
- **Semgrep PHP parser** — não suporta constructor promotion com `readonly` (PHP 8.2); RateLimiter gera PartialParsing warning, findings nesse arquivo podem ser incompletos
- **Débito deferido conscientemente** — split do IpResolver (322L) NÃO feito (recém-unificado no WO-TP e testado; risco > ganho); o `LoggerInterface` anônimo (McpSdkAdapter::compatLogger) é workaround de compat psr/log v1/v3 mantido de propósito. Constructor injection dos seams do BearerAuth adicionado, setters mantidos como back-compat
- **Excluir do deploy**: `.full-review/`, `.security-hardening*/`, `.phpunit.cache/`, `data/` (runtime state)
- **property_exists() guard** — colunas novas (`admin_user`, `approved_by`, `last_used_at`) podem não existir em DBs pré-migration; usar `property_exists($row, 'col')` antes de acessar
- **`deploy/htaccess-well-known.conf`** — regras RewriteRule a inserir no `.htaccess` da raiz WHMCS (antes das regras WHMCS existentes) para que Claude.ai auto-descubra o OAuth 2.1 via RFC 8414 (`/.well-known/oauth-authorization-server`); sem esse passo, Custom Connector do Claude.ai não consegue descobrir os endpoints
- **Trusted proxy unificado (WO-TP)** — `IpResolver` reusa o IP resolvido pelo WHMCS (`\App::getClientIp()`) e mescla a lista nativa `TrustedProxyIps` (aba Security) ∪ `nt_mcp_trusted_proxies`. Consequências: (i) proxies da lista NATIVA também autorizam `X-Forwarded-Proto` e `NT_MCP_ALLOW_HTTP` no `TlsEnforcer` — liste só proxies próprios na aba Security; (ii) o caminho nativo honra o "Proxy IP Header" (ex.: CF-Connecting-IP), mas o fallback só lê `X-Forwarded-For`; (iii) se a chave nativa não for `TrustedProxyIps` na versão instalada, a unificação vira no-op — observável pelo error_log "X-Forwarded-For present but no trusted proxies configured". `nt_mcp_trusted_proxies` é agora opcional/aditivo
- **Config obrigatória pré-deploy** — `nt_mcp_admin_user` DEVE estar setado antes do deploy (senão 401 fail-closed, ver WO-7); operador também configura `nt_mcp_allowed_ips`, `nt_mcp_cors_origins`, e (opcionais) `nt_mcp_expected_host` (isolamento desenv/prod), `nt_mcp_trusted_proxies` / Trusted Proxies nativo do WHMCS
- **MCP Inspector CLI ecoa a `Authorization` header em texto puro** na linha `npm notice run npx` do stdout — nunca `cat`/exibir saída bruta do Inspector sem antes `grep -v "$TOKEN" | sed "s#$TOKEN#<redacted>#g"`; guardar o token em arquivo `chmod 600` fora de `/tmp` compartilhado, nunca inline no comando exibido
- **`whmcs_get_project` espera `projectid`**, não `project_id` — confirmado em bateria ao vivo 2026-08-26
