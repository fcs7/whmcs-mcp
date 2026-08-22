# Go/No-Go — nt_mcp sobre `mcp/sdk` 0.7.1 (branch `t1/mcp-sdk-0.7`)

Data: 2026-08-22. Commits: `60ab9be` (migração), `1dbcc8e` (compat PHP 8.1).

## Veredito

**GO condicional para produção** — código validado (unit + integração + desenv real) e sem findings de segurança. Condições antes do deploy prod:

1. Bateria autenticada no desenv (itens A1–A6 abaixo) executada com token válido — pendente de aprovação OAuth no admin do desenv.
2. Rotacionar a senha FTP do desenv (vazou em saída de ferramenta 3× — 2026-08-10, 08-12, 08-22).
3. Confirmar versão PHP de produção. Desenv roda **PHP 8.1.34 (EOL 2025-12-31)**; o addon agora é compatível, mas o risco é da plataforma, não do addon. Recomendado: subir prod para 8.2/8.3 no Plesk.
4. Deploy prod **com `vendor/`** (troca de lib) — comando "deploy com vendor/" no CLAUDE.md.
5. `display_errors` em prod DEVE estar desligado: no desenv, um parse error antes do bootstrap imprimiu path absoluto na resposta HTTP (a fronteira de output do `mcp.php` só cobre falhas após o autoload).

## O que mudou

| Antes (`php-mcp/server` 1.1.0) | Depois (`mcp/sdk` 0.7.1) |
|---|---|
| Upstream parado desde jul/2025 | SDK oficial, release 2026-08-10 |
| Protocolo `2024-11-05` | Negocia `2024-11-05` … `2025-11-25` + era stateless `2026-07-28` |
| Cache single-file `mcp_state.json` + `flock` global 5s/503 | Sessão por arquivo (`data/sessions/`, TTL 1h, GC 1/20); cache de discovery próprio (`FileElementCache`, atômico) |
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
| novo | — | `middleware: []` no transport: CORS/DNS-rebinding do SDK desligados de propósito; CORS/IP/TLS/Bearer continuam no `mcp.php` |

## Verificação estática

- PHPUnit: **1230 testes, 0 falhas** em `php:8.3-cli` (`zend.exception_ignore_args=1`, como php.ini-production). No PHP 8.5 local, 27+2 falhas são ambientais (php -S sob phpunit; `data/cache` criado como root pelo docker).
- Novos: `McpSdkAdapterTest` (13), `FileElementCacheTest` (19), `ServerGuardsTest` (27); `SinkLeakTest` (30) e `McpEndpointHttpTest` (26, fluxo MCP real via `mcp.php`: initialize → 64 tools → tools/call, batch 400, 405/413) portados.
- Contratos CRM preservados e provados: schema fechado, `minimum:1`, `isError:true` em envelope de erro, zero mensagem crua (SQLSTATE/path/segredo) no payload, Activity Log e error_log.
- `composer audit`: sem CVEs. Lint PHP 8.1 (src + vendor runtime): limpo.
- Opengrep (`p/security-audit`, `p/owasp-top-ten`, `p/php`, 75 arquivos): **0 findings**. Risco aceito: `unserialize()` em `FileElementCache` — arquivo escrito só pelo addon, 0600, em `data/` negado por `.htaccess`, conteúdo validado como array.

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
- `DELETE` agora é aceito (encerra sessão via SDK); GET continua 405.
- PHP 8.1 EOL na plataforma; PHPUnit rebaixado para ^10.5 (dev-only) por causa disso.
- Config obrigatória em prod: `nt_mcp_admin_user`, `nt_mcp_allowed_ips`, `nt_mcp_cors_origins`; `display_errors=Off`.
- Rollback: re-upload do commit anterior **com o `vendor/` antigo** (`php-mcp/server`).
