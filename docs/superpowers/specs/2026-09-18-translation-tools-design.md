# Spec — Tools de tradução em massa (nt_mcp)

Data: 2026-09-18. Branch: `feat/translation-tools`.

## 1. Contexto e objetivo

O tema WHMCS 2026 já troca as páginas estáticas para inglês (`?language=english`,
PR #225 do repo do tema) e ganhou "Idioma da conta". O conteúdo que vem do
banco (produtos, grupos, e-mails, base de conhecimento, anúncios etc.)
continua só em português, e hoje só é traduzido manualmente pelo admin.
Cliente com conta marcada como `english` e sem template EN correspondente
recebe e-mail em PT.

Objetivo: um conjunto de tools MCP que listam conteúdo PT sem tradução,
devolvem o texto para tradução e gravam a versão em inglês (EN) depois de
validada. **Quem traduz é o Claude do lado do cliente MCP** — o addon nt_mcp
não chama nenhum provedor de IA, não tem chave de API de LLM e não decide o
texto traduzido; ele só valida, grava e audita o que o chamador manda.

## 2. Decisão de arquitetura: modelo híbrido

A LocalAPI do WHMCS **não tem comando de escrita** para templates de e-mail,
produtos, grupos, KB, config options, custom fields nem para a tabela de
traduções dinâmicas (`tbldynamic_translations`). O único domínio deste plano
com comando de escrita real é anúncio (`AddAnnouncement`/`UpdateAnnouncement`).

Decisão aprovada: modelo híbrido.

- **LocalAPI** onde o comando existe: só anúncios (Fase 4), via
  `LocalApiClient` (`AddAnnouncement`/`UpdateAnnouncement`), mesma allowlist e
  gates que os demais comandos LocalAPI já usam.
- **Repositório Capsule** onde não existe comando: cada domínio (e-mail,
  produto, grupo, config option, custom field, addon de produto, departamento,
  KB) ganha um repositório próprio com **lista fechada de tabelas e colunas**,
  no mesmo padrão de `src/Whmcs/ChipBridge.php`/`ChipGuard.php` e do CRM
  (`src/Crm/CrmSchemaGuard.php` + `src/Crm/CapsuleSchemaProbe.php`).
  **Nenhum método de nenhum repositório recebe nome de tabela ou coluna vindo
  do chamador** — os identificadores de schema são todos constantes internas.

## 3. Mapa das 25 tools em 4 fases

| # | Tool | Tipo | Fase | Backend |
|---|------|------|------|---------|
| 1 | `whmcs_translation_status` | read | 1 | Capsule (`tblemailtemplates` + `tblclients`) |
| 2 | `whmcs_translation_email_list` | read | 1 | Capsule (`tblemailtemplates`) |
| 3 | `whmcs_translation_email_get` | read | 1 | Capsule (`tblemailtemplates`) |
| 4 | `whmcs_translation_email_set` | write | 1 | Capsule (`tblemailtemplates`) |
| 5 | `whmcs_translation_product_list` | read | 2 | Capsule (`tbldynamic_translations`) |
| 6 | `whmcs_translation_product_get` | read | 2 | Capsule (`tbldynamic_translations`) |
| 7 | `whmcs_translation_product_set` | write | 2 | Capsule (`tbldynamic_translations`) |
| 8 | `whmcs_translation_product_group_list` | read | 2 | Capsule (`tbldynamic_translations`) |
| 9 | `whmcs_translation_product_group_set` | write | 2 | Capsule (`tbldynamic_translations`) |
| 10 | `whmcs_translation_configoption_list` | read | 3 | Capsule (`tbldynamic_translations`) |
| 11 | `whmcs_translation_configoption_set` | write | 3 | Capsule (`tbldynamic_translations`) |
| 12 | `whmcs_translation_custom_field_list` | read | 3 | Capsule (`tbldynamic_translations`) |
| 13 | `whmcs_translation_custom_field_set` | write | 3 | Capsule (`tbldynamic_translations`) |
| 14 | `whmcs_translation_product_addon_list` | read | 3 | Capsule (`tbldynamic_translations`) |
| 15 | `whmcs_translation_product_addon_set` | write | 3 | Capsule (`tbldynamic_translations`) |
| 16 | `whmcs_translation_department_list` | read | 3 | Capsule (`tbldynamic_translations`) |
| 17 | `whmcs_translation_department_set` | write | 3 | Capsule (`tbldynamic_translations`) |
| 18 | `whmcs_translation_kb_category_list` | read | 4 | Capsule (linha-filha KB) |
| 19 | `whmcs_translation_kb_category_set` | write | 4 | Capsule (linha-filha KB) |
| 20 | `whmcs_translation_kb_article_list` | read | 4 | Capsule (linha-filha KB) |
| 21 | `whmcs_translation_kb_article_get` | read | 4 | Capsule (linha-filha KB) |
| 22 | `whmcs_translation_kb_article_set` | write | 4 | Capsule (linha-filha KB) |
| 23 | `whmcs_translation_announcement_list` | read | 4 | Capsule (leitura) |
| 24 | `whmcs_translation_announcement_get` | read | 4 | Capsule (leitura) |
| 25 | `whmcs_translation_announcement_set` | write | 4 | LocalAPI (`AddAnnouncement`/`UpdateAnnouncement`) |

Total: 25 tools (4 + 5 + 8 + 8).

**Regra de corte** entre `list+set` e `list+get+set`: texto curto (nome de
produto/grupo/config option/custom field/departamento — cabe na listagem)
usa `list+set`; texto longo (assunto+corpo de e-mail, descrição de
produto/artigo de KB/anúncio) usa `list+get+set`, para não estourar o payload
da listagem com HTML grande.

## 4. Armazenamento por tipo (a confirmar no desenv)

- **E-mail** (`tblemailtemplates`): uma linha por `name`+`language`; o master
  PT tem `language=''`; a variante EN é uma linha irmã com o mesmo `name` e
  `language='english'`.
- **Produto, grupo de produto, config option, custom field, addon de
  produto, departamento** (`tbldynamic_translations`): uma linha por
  `related_type`+`related_id`+`language`+`field`, com `related_type` no
  padrão `product.{id}.name`, `product.{id}.description`,
  `product_group.{id}.name`, e equivalentes para os demais domínios. Exige o
  toggle manual **"Enable Dynamic Translations"** ligado no WHMCS — pré-requisito
  de uma vez só, fora do escopo destas tools; `whmcs_translation_status`
  informa o estado atual (ligado/desligado/desconhecido).
- **KB (categoria e artigo) e anúncio**: linha-filha com `parentid` apontando
  para a linha PT e `language='english'`.

O conjunto exato de colunas de cada tabela é confirmado no desenv no primeiro
uso (ver Seção 10); se alguma coluna esperada faltar, o schema guard responde
`schema_mismatch` em vez de quebrar a query.

## 5. Proteções obrigatórias de todo `_set`

Toda tool `_set` (qualquer fase) aplica as cinco:

1. **Transação, lote tudo-ou-nada** — todos os itens do lote gravam dentro de
   uma única transação; qualquer item inválido faz rollback do lote inteiro.
2. **Hash otimista** (`expected_hash`) — o chamador manda o hash do conteúdo
   EN que ele leu por último; se o conteúdo mudou desde então (edição
   concorrente), o item é recusado sem gravar.
3. **`SELECT … FOR UPDATE` na linha fonte** antes do check-then-insert — evita
   duas gravações concorrentes criarem duas linhas EN duplicadas para a mesma
   linha fonte.
4. **Schema guard fail-closed** antes de qualquer query — tabela ausente →
   `unavailable`; coluna esperada faltando → `schema_mismatch`; erro ao ler
   metadata → `downstream`; nenhuma falha é memorizada/cacheada entre
   chamadas.
5. **Backup do conteúdo EN anterior** — gravado em JSONL **antes** da escrita
   (insert/update), dentro da mesma transação; se o backup falhar, a
   transação inteira sofre rollback (falha de backup = nada gravado). O
   backup fica em `data/translation-backups/` (diretório 0700, arquivo 0600).
   O texto anterior também volta na resposta da tool.

Além das cinco:

- **Gates**: `nt_mcp_readonly` (master switch) + `nt_mcp_enable_write='1'`
  (`TranslationGuard`, mesmo contrato de `ChipGuard::assertWriteAllowed`).
  Recusa é auditada como `DB_BLOCKED`.
- **`confirm=false`** (default): dry-run — valida tudo, não grava, não passa
  pelo gate de escrita.
- **`confirm=true`**: passa pelo gate, valida, grava o lote na transação.
- **Audit**: gravação registra `DB_INSERT`/`DB_UPDATE` só com ids e
  contagens — nunca o conteúdo traduzido.

## 6. Regras de conteúdo

- Nunca escrever na linha fonte (PT) — toda escrita é na linha/variante EN.
- Paridade de tags entre PT e EN, como multiset (contagem, não posição):
  - tags Smarty, regex `\{[^{}]+\}`;
  - nomes de tags HTML.
  Divergência (tag faltando, sobrando ou alterada) é recusada.
- `subject`/`message` (ou campo equivalente) não podem ficar vazios.
- Não traduzir as marcas: **NT-Fiber, NT-Cloud, NTHOSTING, NT-Fone, NT-Móvel,
  BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA, CPE, VLAN** — orientação
  incluída na `description` de cada tool `_set`, para o Claude chamador
  aplicar na tradução antes de submeter.
- Não prometer atendimento **24x7**/**24/7** nem certificações não
  confirmadas.

## 7. Design da Fase 1 (resumo do Entregável 2 do plano)

Escopo: e-mail apenas (as 4 tools da Fase 1).

- Classes novas em `src/Translation/`: `TranslationSchema`,
  `TranslationSchemaGuard` (mesmo contrato de `CrmSchemaGuard`, reusando
  `CapsuleSchemaProbe` como está), `EmailTemplateRepository` (única classe
  que toca `tblemailtemplates`), `TranslationGuard`, `TranslationValidator`,
  `TranslationBackup`.
- `src/Tools/TranslationTools.php` implementa as 4 tools com `#[McpTool]` +
  `#[Schema(additionalProperties:false)]`, saída via `ToolJson::encode()`,
  erros de domínio como `CallToolResult::error()`, sem try/catch.
- Limites: `list` com `limit≤100`; `get` com `ids` de 1 a 10; `set` com
  `items` de 1 a 10 (mesmo teto do `get`, por conta do tamanho do HTML).
- Resposta de dry-run (`confirm=false`) por item: `action` (`insert`|`update`),
  hash atual, lista de campos alterados, `subject` antes/depois, e um trecho
  truncado do corpo (nunca o HTML inteiro).
- `whmcs_translation_status`: contagem `GROUP BY language` de
  `tblemailtemplates` excluindo `type='admin'`; amostra de 3 `subject` de
  masters; contagem de valores distintos de `tblclients.language` (só
  contagem, sem dado de cliente); `dynamic_translations_enabled` lido via
  `\WHMCS\Config\Setting::getValue('EnableTranslations')`, com fallback
  `'unknown'` se a chave não existir.

## 8. Premissa a validar (passo 0 da bateria) — RESOLVIDO 2026-09-18

Este spec assumia: linha master (`language=''`) = PT, e a variante `english`
é a que falta. **Achado real no desenv**: os 92 masters são MISTOS — cerca de
75 são templates padrão de fábrica do WHMCS, já em inglês, e cerca de 17 são
customizados pelo admin, em PT. Já existem 6 linhas `portuguese-br` e 1
`english` no banco. A premissa de idioma-fonte único caiu; a de idioma-ALVO
único também caiu.

Decisão: `EmailTemplateRepository::SOURCE_LANGUAGE` continua fixo em `''`
(o master, seja qual for o idioma do conteúdo dele), mas o idioma-ALVO
deixou de ser uma constante fixa e virou parâmetro `target_language` em toda
tool e todo método do repositório — aceita apenas os literais de
`SUPPORTED_TARGET_LANGUAGES = ['english', 'portuguese-br']`
(`DEFAULT_TARGET_LANGUAGE = 'english'`). Um valor fora da lista é recusado
(`invalid_target_language`) antes de qualquer consulta ao banco. O
mecanismo de upsert por `name` continua o mesmo nos dois sentidos.
`whmcs_translation_email_list` devolve `variants` (idiomas com linha irmã já
existente para aquele `name`) para o chamador decidir, por item, qual sentido
faz sentido: `target_language='portuguese-br'` para os masters padrão (em
inglês) e `target_language='english'` para os masters customizados (em PT) —
nunca traduzir um master que já esteja no idioma-alvo pedido.

## 9. Fora de escopo

- WebMCP (descartado, ver `project-consolidacao-20260918.md`).
- Tradução automática por IA dentro do addon — o addon nunca chama um LLM.
- Tradução de linhas administrativas (`type='admin'` em `tblemailtemplates`,
  e equivalentes em outros domínios).

## 10. Verificação

1. Suíte completa em container (`php:8.3-cli-bookworm`, `-u 1000:1000` +
   `zend.exception_ignore_args=1`).
2. Lint PHP 8.1 (`php:8.1-cli-bookworm`, `php -l` em `src/`).
3. Opengrep direcionado em `src/Translation` e `src/Tools/TranslationTools.php`
   (`--json`, conferindo `results` e `paths.scanned`).
4. Bateria ao vivo no desenv (MCP Inspector CLI, token redigido) confirmando
   schema real e comportamento de cada tool.
5. Produção só com autorização explícita do usuário, em deploy separado.
