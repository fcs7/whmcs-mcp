# NT MCP — WHMCS MCP Server Addon

Addon PHP para WHMCS que expõe 54 tools via Model Context Protocol.
Repo: `git@github.com:fcs7/whmcs-mcp.git`

## Commands

```bash
cd modules/addons/nt_mcp
composer install --ignore-platform-req=ext-iconv
./vendor/bin/phpunit --testdox
grep -c '#\[McpTool\]' src/Tools/*.php   # 54 tools total
```

## Architecture

- `nt_mcp.php` — Entry point WHMCS (_config/_activate/_output)
- `mcp.php` — Endpoint HTTP: init.php → BearerAuth → Server::run()
- `src/Server.php` — Bootstrap php-mcp/server, DI via BasicContainer
- `src/Auth/BearerAuth.php` — Bearer token validation (hash_equals)
- `src/Whmcs/LocalApiClient.php` — Wrapper localAPI(), throws RuntimeException on error
- `src/Whmcs/CapsuleClient.php` — Direct DB via Capsule ORM (select/insert/update/delete)
- `src/Tools/*.php` — 9 classes com #[McpTool] para auto-discovery

## Conventions

- Tools: `#[McpTool(name: 'whmcs_*', description: '...')]` — retornam `json_encode(..., JSON_PRETTY_PRINT)`
- LocalAPI tools injetam `LocalApiClient`, CRM tools injetam `CapsuleClient`
- Não usar try/catch nos tools — o framework captura exceções automaticamente
- PHP 8.2+ obrigatório (PHPUnit 11)

## Gotchas

- **CRM table names são placeholders** (`mod_mgcrm_*` em CrmTools.php) — verificar no banco real
- **mcp.php** requer `__DIR__ . '/../../../init.php'` (3 níveis até raiz WHMCS)
- **php-mcp/server API real** difere da documentação web: usar HttpTransportHandler, BasicContainer, ArrayConfigurationRepository
- **ext-iconv** pode não estar habilitada — usar `--ignore-platform-req=ext-iconv` no composer
- **Bearer Token** armazenado em tblconfiguration, gerado na ativação do addon
