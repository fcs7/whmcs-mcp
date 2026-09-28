# Revisão de segurança — NT MCP 2.9.1

Base revisada: `main` em `8dc4ada`. Entrega: branch `fix/production-security`
e PR contra `main`, sem merge nem publicação em produção.

## Problemas corrigidos

| Fronteira | Correção e evidência |
| --- | --- |
| Aprovação OAuth | Código pendente, vínculo ausente/vazio, expirado ou já consumido não gera token. `ApprovalBoundaryTest` e teste MariaDB. |
| Identidade | OAuth não herda administrador global; aprovação e emissão exigem administrador ativo. Autenticação/refresh rejeitam vínculo ausente. Testes de BearerAuth, refresh e painel. |
| XSS no painel | JSON com `JSON_HEX_TAG/AMP/APOS/QUOT` nas decisões; URLs novas rejeitam caracteres HTML/controle, credenciais e fragmentos. Testes das duas decisões, inclusive URLs legadas maliciosas. |
| PKCE e retorno | S256 explícito, challenge/verifier com formato válido, tipos/tamanhos da autorização; cliente e retorno exatos antes de consumir código. Query preexistente e state `0` preservados. |
| Consumo e emissão | Uma transação engloba consumo e os dois inserts; resposta com credenciais só sai após commit. Falha no insert do refresh reverte access token e consumo do código. Teste real com trigger de falha. |
| Revogação concorrente | Emissão/aprovação/revogação bloqueiam registros de clients por id em ordem consistente. Duas conexões MariaDB verificam refresh concorrente com revogação global, individual, por família e remoção de client; nada sobrevive à revogação. |
| Revogação global | Remove também códigos/pedidos pendentes. Botão disponível mesmo sem access tokens ativos, porque refresh tokens podem sobreviver. |
| Migração | Instalação nova inclui `family_id`; falha não é cacheada como sucesso; OAuth retorna 503 se migração falha. Novas tabelas InnoDB; operações transacionais rejeitam outro engine. |
| Painel admin | Sessão ausente, administrador desativado ou consulta falha não podem gerar token estático. Não há fallback para o nome `admin`. |

`OAuthTransaction` serializa apenas operações de autorização/credenciais; chamadas
MCP comuns não recebem esse bloqueio. A lista de clients é limitada pelo cadastro
atual. Não há conversão automática de engine nem mudança de dependências de runtime.

## Verificações concluídas

- PHPUnit em PHP 8.3 com pcntl: **1.829 testes / 5.551 assertions**, sem falhas e sem ignorados.
- Harness reproduzível em [tests/integration](../../modules/addons/nt_mcp/tests/integration/README.md):
  MariaDB 11.4 e Illuminate Database 8.83.27, testes de concorrência/rollback e recusa de MyISAM.
  Docker Compose terminou com código 0.
- Lint de `src`, `tests`, `templates`, `oauth.php` e `nt_mcp.php` em PHP 8.1.
- Auditoria Composer do addon sem advisories ou pacotes abandonados informados.
- Apache isolado com `AllowOverride All`: `composer.lock`, `src/Auth/BearerAuth.php`,
  `vendor/autoload.php`, `tests/bootstrap.php`, `templates/admin/oauth-approve.php`,
  `data/` e `.git/config` retornaram 403, com e sem `mod_rewrite`.
- Revisão das fronteiras existentes de transporte, autenticação, painel, gates da
  LocalAPI/ChipGuard/TranslationGuard, redaction, queries parametrizadas e cache.
  A suíte completa inclui os testes desses controles; isso não equivale a exercitar
  todas as ferramentas contra um WHMCS publicado.
- `git diff --check` sem erros. CodeRabbit e scanners semânticos indisponíveis no ambiente;
  não se atribui a eles uma revisão que não executaram.

Não houve exploração nem mudança em produção. Os testes de banco usam credenciais
sintéticas em uma rede Docker interna sem portas publicadas. O harness não substitui
uma homologação no WHMCS/PHP/banco específicos do destino.

## Procedimento para a futura publicação

1. Programar janela e bloquear temporariamente acesso público ao addon/fluxo OAuth.
   Preservar banco do addon, Activity Log e logs HTTP/PHP para investigação e recuperação.
2. No banco WHMCS, executar a consulta somente de leitura abaixo. As quatro tabelas
   precisam existir e ser InnoDB. Se alguma for MyISAM, planejar sua conversão para
   InnoDB com backup e janela antes de liberar OAuth. A versão corrigida recusa a
   operação quando não consegue garantir transação; não converte tabelas silenciosamente.
3. Publicar os arquivos da versão 2.9.1 aprovada, incluindo as novas classes OAuth,
   mantendo as exclusões de `tests/`, `data/`, arquivos de revisão e desenvolvimento.
   Acionar/verificar o upgrade do addon e o resultado da migração. Conferir hashes dos
   arquivos remotos contra o artefato do commit aprovado, além da versão exibida.
4. No painel, usar **Revogar Todos**: invalida access tokens, refresh tokens e pedidos/códigos
   anteriores. Clientes precisarão de nova autorização. Conferir que as três tabelas
   de credenciais/códigos ficaram vazias antes de solicitar a primeira aprovação nova.
5. Conferir no destino as proteções HTTP de diretórios internos, arquivos auxiliares,
   TLS, CSP, `nosniff`, `no-store`, proxy/IP allowlist e permissões de `data/`.
   Um PHP interno que responde 200 não comprova exposição de seu fonte, mas também
   não satisfaz o bloqueio de acesso esperado. Verificar Apache/Nginx/Plesk efetivos.
6. Em homologação, validar negação sem aprovação, aprovação legítima, refresh,
   revogação e uma ferramenta de leitura permitida. Manter gates de escrita fechados
   até autorização operacional. Só então reabrir acesso público.

```sql
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'mod_nt_mcp_oauth_clients', 'mod_nt_mcp_oauth_codes',
    'mod_nt_mcp_oauth_tokens', 'mod_nt_mcp_oauth_refresh_tokens'
  );
```

Para investigar emissões antigas, correlacionar eventos de aprovação/emissão com
logs HTTP e dados preservados. O Activity Log antigo pode não ter identificadores
suficientes para correlação inequívoca; ausência de correspondência não prova,
isoladamente, exploração ou sua ausência. Não reativar a versão vulnerável para
restaurar compatibilidade com credenciais sem vínculo. Se a homologação falhar,
manter o acesso suspenso enquanto se corrige a causa.
