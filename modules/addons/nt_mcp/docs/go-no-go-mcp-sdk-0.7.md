# Go/No-Go — nt_mcp sobre `mcp/sdk` 0.7.1 (branch `t1/mcp-sdk-0.7`)

Data: 2026-08-22. Commits: `60ab9be` (migração), `1dbcc8e` (compat PHP 8.1). **Revisão 2 (mesmo dia, working tree, não commitada):** correções A–H abaixo após review externo.
**Revisão 3 (mesmo dia, working tree, não commitada):** review adversarial do Codex sobre a revisão 2 achou 7 problemas (1 HIGH, 4 MEDIUM, 2 LOW), todos corrigidos:
- `SecureFileSessionStore::write()` devolvia `false` em silêncio quando a escrita subjacente falhava (SDK ignora o bool) → agora lança, mesmo princípio do chmod.
- `FileElementCache`: `stdClass` cíclico (`$a->next = $b; $b->next = $a`) não era detectado por `containsIncompleteClass()` e recursava até esgotar memória → guard com `SplObjectStorage` (corta ciclo) + teto de profundidade.
- `FileElementCache` aceitava um payload sintaticamente válido mas semanticamente errado (ex.: uma string na posição do valor) e só quebrava depois, dentro do SDK, como 500 persistente → novo parâmetro `expectedValueType` (o adapter passa `DiscoveryState::class`); mismatch vira miss limpo, arquivo removido.
- `Server.php` segurava o lock de sessão durante toda a emissão, inclusive SSE — um POST de follow-up (sampling/elicitation via `ClientGateway`, recurso do SDK ainda não usado pelas 64 tools) na mesma sessão não conseguiria o lock e daria 503/timeout em vez de ser atendido → lock liberado ANTES do corpo `text/event-stream` começar a fluir; respostas JSON continuam com o lock preso até depois do emit.
- `SessionLock` não distinguia falha estrutural (mkdir/chmod/fopen quebrados) de contenção real (timeout) — ambas logavam `lock_busy` → `lastFailure()` reporta `open_failed` vs `timeout`; `Server.php` escolhe o contexto certo.
- Teste do lock só provava o estado durante `adapter->handle()`, não durante a emissão do corpo (onde SSE realmente consome/produz mensagens de sessão) → novo `ProbingStream` (PSR-7) prova o lock preso durante corpo JSON e liberado antes do corpo SSE.
- Teste de sucesso aceitava `result.isError` ausente (`?? false`) → agora exige a chave presente.

## Veredito

**GO condicional para produção** — código validado (unit + integração + desenv real) e sem findings de segurança. Condições antes do deploy prod:

1. Bateria autenticada no desenv (itens A1–A6 abaixo) executada com token válido — pendente de aprovação OAuth no admin do desenv.
2. Rotacionar a senha FTP do desenv (vazou em saída de ferramenta 3× — 2026-08-10, 08-12, 08-22).
3. Confirmar versão PHP de produção. Desenv roda **PHP 8.1.34 (EOL 2025-12-31)**; o addon agora é compatível, mas o risco é da plataforma, não do addon. Recomendado: subir prod para 8.2/8.3 no Plesk.
4. Deploy prod **com `vendor/`** (troca de lib) — comando "deploy com vendor/" no CLAUDE.md. A revisão 2 ainda **não foi commitada, nem enviada ao desenv**: a bateria dinâmica abaixo reflete o deploy da revisão 1.
5. `display_errors` em prod DEVE estar desligado: no desenv, um parse error antes do bootstrap imprimiu path absoluto na resposta HTTP (a fronteira de output do `mcp.php` só cobre falhas após o autoload).

## O que mudou

| Antes (`php-mcp/server` 1.1.0) | Depois (`mcp/sdk` 0.7.1) |
|---|---|
| Upstream parado desde jul/2025 | SDK oficial, release 2026-08-10 |
| Protocolo `2024-11-05` | Responde **sempre `2025-11-25`** no initialize (SDK 0.7.1 não negocia para baixo); aceita no header `MCP-Protocol-Version` as 4 versões do enum (`2024-11-05`, `2025-03-26`, `2025-06-18`, `2025-11-25`); inválida → 400. Transporte stateful — não existe modo stateless nem `2026-07-28` nesta versão |
| Cache single-file `mcp_state.json` + `flock` global 5s/503 | Sessão por arquivo 0600 (`SecureFileSessionStore`, TTL 1h, GC 1/20) + `SessionLock` por faixa (64 flocks, 5 s, 503+Retry-After) — paralelismo entre sessões, serialização dentro da mesma; cache de discovery próprio (`FileElementCache`, atômico, 0600, allowlist de 4 classes no `unserialize`) |
| `queueMessageForAll` (storm com múltiplas sessões) | Inexistente |
| `CrmReadBoundary` reescrevendo registry | `#[Schema(additionalProperties:false, minimum…)]` + `CallToolResult::error()` nativos |
| 11 `use PhpMcp\Server\Attributes\McpTool` | `Mcp\Capability\Attribute\McpTool` |

Tools publicadas: **64** (o "86" do CLAUDE.md antigo estava desatualizado).

## Destino dos fixes de auditoria (Server.php)

| ID | Antes | Agora |
|---|---|---|
| M-02 | Content-Length > 1 MB → 413 | Mantido (guard) **+** `maxBodyBytes: 1 MiB` no transport (default do SDK era 4 MiB) |
| F5 | lock file não abre → 500 | Obsoleto — não há lock global |
| F6 | sem resposta p/ id → -32603 | Obsoleto — SDK responde sempre |
| — | `str_replace('"properties":[]')` | Removido — SDK emite `{}` (verificado) |
| — | geração própria de `Mcp-Session-Id` | SDK (UUID); allowlist de saída do `mcp.php` mantida |
| novo | — | Batch JSON-RPC → 400 `-32600` antes do SDK (100 calls não contam como 1 no rate limit) |
| novo | — | Transport só com `ProtocolVersionMiddleware` (header inválido → 400 `-32602`); CORS/DNS-rebinding do SDK desligados de propósito; CORS/IP/TLS/Bearer continuam no `mcp.php` |
| novo | — | Perfil CORS `POST, DELETE, OPTIONS` no `mcp.php` (preflight de DELETE era 403); OAuth mantém `POST, OPTIONS` |
| novo | — | `nt_mcp_config` version `2.0.0` → `nt_mcp_upgrade()` roda em instalações ativas; `RuntimeDirs::provision()` endurece `data/{cache,sessions,session-locks}` 0700 na ativação e no upgrade |

## Verificação estática

- PHPUnit (revisão 3): **1269 testes, 3958 asserções, 0 falhas, 1 skipped** (pcntl ausente — pré-existente) em `php:8.3-cli` (`zend.exception_ignore_args=1`, `--user` não-root). No PHP 8.5 local os testes HTTP via php -S seguem ambientais (29 falhas, todas em `McpEndpointHttpTest`/`DiagnosticBoundaryTest`, confirmadas ambientais rodando a mesma suíte em docker).
- Novos na revisão 3: `SecureFileSessionStoreTest::write_throws_instead_of_returning_false_when_the_underlying_write_fails`, `FileElementCacheTest::{cyclic_stdclass_is_rejected_without_exhausting_memory, decode_rejects_a_syntactically_valid_but_semantically_wrong_entry_when_a_type_is_expected, corrupted_discovery_cache_self_heals_instead_of_500ing_forever}`, `SessionLockTest::{last_failure_distinguishes_timeout_from_structural_failure, last_failure_resets_to_null_on_a_later_successful_acquire}`, `ServerSessionLockTest::{lock_is_still_held_while_a_json_body_is_being_emitted, lock_is_already_released_before_an_sse_body_starts_streaming}` (via novo `tests/Support/ProbingStream.php`), `McpSdkAdapterTest` isError agora usa `assertArrayHasKey`.
- Novos nesta revisão: `SessionLockTest` (6), `ServerSessionLockTest` (5 — lock segurado durante o handle, DELETE trava, initialize não trava, 503 após espera, header nunca vira path), `RuntimeDirsTest` (3), `SecureFileSessionStoreTest` (3), `FileElementCacheTest` +6 (canário `__wakeup` não dispara, `C:` rejeitado, envelope malformado, grafo exato, round-trip da discovery real, 0600/0700), `McpSdkAdapterTest` +8 (400 para header inválido e para `2026-07-28`, 4 versões aceitas, ausente tolerado, initialize responde `2025-11-25` para qualquer pedida, sessão 0600/dir 0700, DELETE → POST 404, CRM real com port falhando → `isError:true` + `error_code` sem SQLSTATE/path/senha/classe), `McpEndpointHttpTest` +2 (preflight DELETE 204 → DELETE 200 → POST 404; header inválido 400 via `mcp.php` real), `CorsHandlerTest` +2.
- Repro que motivou o lock (docker, SDK puro): duas `Session` no mesmo `FileSessionStore`, ambas hidratam, A grava `response_a`, B grava `response_b` → arquivo só tem `response_b` e o consumo de A devolve a resposta de B. Dir criado 0755, arquivo 0644.
- Novos: `McpSdkAdapterTest` (13), `FileElementCacheTest` (19), `ServerGuardsTest` (27); `SinkLeakTest` (30) e `McpEndpointHttpTest` (26, fluxo MCP real via `mcp.php`: initialize → 64 tools → tools/call, batch 400, 405/413) portados.
- Contratos CRM preservados e provados: schema fechado, `minimum:1`, `isError:true` em envelope de erro, zero mensagem crua (SQLSTATE/path/segredo) no payload, Activity Log e error_log.
- `composer audit`: sem CVEs. Lint PHP 8.1 (`php:8.1-cli`, src + entries + vendor runtime, um arquivo por invocação): limpo.
- Opengrep revisão 3 (`p/security-audit`, `p/owasp-top-ten`, `p/php`; src + mcp.php/nt_mcp.php/oauth.php, vendor/tests/data excluídos): **0 findings**.
- Lint 8.1 (`php:8.1-cli`) e `composer audit` repetidos na revisão 3: limpos. `unserialize()` em `FileElementCache` deixou de ser risco aceito: allowlist fechada (`DiscoveryState`, `ToolReference`, `Tool`, `stdClass` — grafo medido na discovery real), pré-varredura rejeita `O:` fora da lista e qualquer `C:`, envelope validado, rejeição remove o arquivo e registra `element_cache_rejected` no Diagnostics.

## Verificação dinâmica no desenv (`https://desenv.ntweb.com.br/modules/addons/nt_mcp/mcp.php`)

Deploy com `vendor/` feito (libs velhas `php-mcp`, `react`, `evenement` removidas; `symfony`/`webmozart` re-resolvidas p/ 8.1).

| # | Teste | Resultado |
|---|---|---|
| 1 | Sem Bearer / token inválido | 401 + `WWW-Authenticate: Bearer resource_metadata=…` ✅ |
| 2 | HTTP puro / `X-Forwarded-Proto` spoof | 301 → https pelo nginx (TlsEnforcer nunca é alcançado em claro) ✅ |
| 3 | IP allowlist | `nt_mcp_allowed_ips` vazio no desenv — não exercitado (config) ⚠️ |
| 4 | Rate limit 70 POST/min | 61× 401 depois 9× 429 ✅ |
| 5 | CORS | `nt_mcp_cors_origins` vazio → `ACAO: *`; preflight 204 com headers MCP ✅ (config: definir `https://claude.ai` em prod) |
| 12 | RFC 8414 `/.well-known/oauth-authorization-server` + registro dinâmico | 200 metadata; 201 client ✅ |
| 9 | Headers de segurança (CSP, HSTS, nosniff, DENY) | presentes ✅ |

Pendentes (precisam de token — auth roda antes dos guards): **A1** GET → 405 `Allow: POST, DELETE`; **A2** body 1 MB+1 → 413; **A3** batch → 400; **A4** CRM `type=lead` → -32602, `resource_id=0` → -32602, erro → `isError:true` sem vazamento; **A5** gates FINANCIAL/readonly; **A6** conector Claude.ai real (initialize → 64 tools → 1 READ → 1 WRITE). Todos têm cobertura equivalente no `McpEndpointHttpTest`/`McpSdkAdapterTest` (root WHMCS simulado).

## Riscos residuais

- `mcp/sdk` é pre-1.0 (0.6→0.7 renomeou classes). Pinado exato; upgrade só em branch dedicada.
- Capabilities anunciadas incluem `prompts`/`resources`/`completions` vazias (antes desligadas). Clientes que chamem `resources/list` recebem lista vazia — inofensivo.
- `DELETE` agora é aceito (encerra sessão via SDK) e consta no preflight CORS; GET continua 405.
- Clientes que só falam `2024-11-05` recebem `2025-11-25` no initialize — a spec permite o servidor responder outra versão e cabe ao cliente desconectar se não suportar. Claude.ai suporta; não testado com clientes legados.
- Lock por faixa (64): sessões distintas podem colidir na mesma faixa e serializar sem necessidade (custo: latência, nunca corretude). Timeout 5 s → 503 + `Retry-After: 1`.
- PHP 8.1 EOL na plataforma; PHPUnit rebaixado para ^10.5 (dev-only) por causa disso.
- Config obrigatória em prod: `nt_mcp_admin_user`, `nt_mcp_allowed_ips`, `nt_mcp_cors_origins`; `display_errors=Off`.
- Rollback: re-upload do commit anterior **com o `vendor/` antigo** (`php-mcp/server`).
