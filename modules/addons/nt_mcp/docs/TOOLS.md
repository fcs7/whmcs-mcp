# Catálogo de Tools — NT MCP (64 tools)

> Atualizado em 2026-08-23. Fonte de verdade: atributos `#[McpTool(...)]` em
> `src/Tools/*.php` + gate mapping em `src/Whmcs/LocalApiClient.php`.
> Contagem verificada: `grep -oh "name: '[a-z_0-9]*'" src/Tools/*.php | sort -u | wc -l` = **64**.

Este documento lista **todas as 64 tools** uma a uma, com o comando WHMCS que
cada uma invoca (ou a origem CRM para tools de CRM), a classe do gate de segurança (WO-2),
se está **ligada por padrão**, e o **nível de risco** — para avaliar a necessidade de cada
tool e decidir cortes.

---

## Modelo de segurança (gate WO-2)

Cada comando WHMCS é classificado em `LocalApiClient::COMMAND_CLASS`. O gate
decide se a chamada passa:

| Classe | Default | Config para habilitar | Significado |
|--------|---------|-----------------------|-------------|
| `READ` | ✅ sempre on | — | Somente consulta |
| `WRITE` | ✅ **on** | `nt_mcp_enable_write` (on) | Modifica dados reversíveis |
| `DESTRUCTIVE` | ⛔ off | `nt_mcp_enable_destructive` | Irreversível (exige confirm=true) |
| `FINANCIAL` | ⛔ off | `nt_mcp_enable_financial` | Efeito financeiro (gera fatura) |
| `CRM-READ` | ✅ sempre on | — | Leitura do schema real do ModulesGarden CRM via `MgCrmRepository` |

Master switch: `nt_mcp_readonly` (fail-closed) bloqueia **tudo** exceto READ.
Todo comando precisa estar na allowlist (`ALLOWED_COMMANDS`) e possuir classificação explícita;
ausência em qualquer uma das duas estruturas nega a chamada.
Impersonação (`adminid`/`adminusername`) é clampada ao admin do token.

**Ponto de atenção:** `WRITE` é **on por padrão**. Tools de classe WRITE que
mexem no serviço do cliente rodam sem opt-in explícito — ver seção "Risco identificado".

Legenda de risco:
- 🟢 **READ** — sem risco
- 🟡 **WRITE administrativa** reversível, baixo impacto
- 🟠 **DESTRUCTIVE ou impacto direto ao cliente** — requer opt-in (gate ou confirm)
- 🔵 **Efeito colateral ortogonal (COMMS)** — adiciona gate independente

---

## BillingTools (5) — todas READ 🟢

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 1 | `whmcs_list_invoices` | GetInvoices | READ | on | 🟢 | fields=lite (default) mantém IDs/valores/datas/status sem identidade ou notas; full é opt-in |
| 2 | `whmcs_get_invoice` | GetInvoice | READ | on | 🟢 | Detalhes de uma fatura |
| 3 | `whmcs_get_transactions` | GetTransactions | READ | on | 🟢 | Lista transações financeiras |
| 4 | `whmcs_get_credits` | GetCredits | READ | on | 🟢 | Créditos de um cliente |
| 5 | `whmcs_get_pay_methods` | GetPayMethods | READ | on | 🟢 | Métodos de pagamento salvos |

## ClientTools (12)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 6 | `whmcs_list_clients` | GetClients | READ | on | 🟢 | fields=lite (default) mantém ID/data/grupo/status sem identidade; full é opt-in |
| 7 | `whmcs_get_client` | GetClientsDetails | READ | on | 🟢 | fields=lite (default): somente IDs/metadados/status/moeda/stats, sem identidade; full: PII explícita, sem segredos/IP/cartão |
| 8 | `whmcs_create_client` | AddClient | WRITE | on | 🟡 | Cria cliente (customfields JSON); notify_client=true requer COMMS |
| 9 | `whmcs_update_client` | UpdateClient | WRITE | on | 🟡 | Atualiza cliente |
| 10 | `whmcs_get_client_products` | GetClientsProducts | READ | on | 🟢 | Produtos/serviços do cliente |
| 11 | `whmcs_get_client_domains` | GetClientsDomains | READ | on | 🟢 | Domínios do cliente |
| 12 | `whmcs_get_client_invoices` | GetInvoices | READ | on | 🟢 | Mesmo contrato lite/full de list_invoices, filtrado por cliente |
| 13 | `whmcs_get_contacts` | GetContacts | READ | on | 🟢 | Contatos/sub-contas |
| 14 | `whmcs_add_contact` | AddContact | WRITE | on | 🟡 | Adiciona contato |
| 15 | `whmcs_update_contact` | UpdateContact | WRITE | on | 🟡 | Atualiza contato |
| 16 | `whmcs_get_client_groups` | GetClientGroups | READ | on | 🟢 | Grupos de clientes |
| 17 | `whmcs_get_clients_addons` | GetClientsAddons | READ | on | 🟢 | Addons contratados |

## CrmTools (4) — somente leitura do schema real mgCRM2

| # | Tool | Origem | Gate | Default | Risco | Descrição |
|---|------|--------|------|---------|-------|-----------|
| 18 | `whmcs_crm_list_contacts` | MgCrmRepository | CRM-READ | on | 🟢 | Lista recursos (contatos/leads) do CRM mgCRM2 |
| 19 | `whmcs_crm_get_contact` | MgCrmRepository | CRM-READ | on | 🟢 | Obtém recurso (contato/lead) do CRM mgCRM2 |
| 20 | `whmcs_crm_list_followups` | MgCrmRepository | CRM-READ | on | 🟢 | Lista follow-ups com paginação |
| 21 | `whmcs_crm_get_kanban` | MgCrmRepository | CRM-READ | on | 🟢 | Visão Kanban + catálogos de tipos/status |

> **Estado:** as quatro leituras operam sobre o schema real mgCRM2 (`crm_*`) via
> `MgCrmRepository`. Os quatro writes legados foram removidos da descoberta e não têm
> caminho executável; uma futura superfície de escrita exige contrato CRM-3 próprio.

## DomainTools (5)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 22 | `whmcs_list_domains` | GetClientsDomains | READ | on | 🟢 | Lista domínios |
| 23 | `whmcs_domain_get_nameservers` | DomainGetNameservers | READ | on | 🟢 | Obtém nameservers atuais |
| 24 | `whmcs_domain_get_locking_status` | DomainGetLockingStatus | READ | on | 🟢 | Status de bloqueio de transferência |
| 25 | `whmcs_domain_get_whois_info` | DomainGetWhoisInfo | READ | on | 🟢 | Informações WHOIS |
| 26 | `whmcs_get_tld_pricing` | GetTLDPricing | READ | on | 🟢 | Preços de TLDs (omite anos com preço 0, adiciona years_available e sinaliza grace/redemption ausentes) |

## OrderTools (7)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 27 | `whmcs_list_orders` | GetOrders | READ | on | 🟢 | fields=lite (default) remove PII direta do cliente; full é opt-in; line items preservados |
| 28 | `whmcs_get_order` | GetOrders | READ | on | 🟢 | fields=lite (default) remove PII direta do cliente; segredos/IP/fraud dump nunca saem |
| 29 | `whmcs_cancel_order` | CancelOrder | DESTRUCTIVE | ⛔ off | 🟠 | **Cancela pedido — irreversível, exige confirm=true** |
| 30 | `whmcs_pending_order` | PendingOrder | WRITE | on | 🟡 | Coloca pedido em status pendente |
| 31 | `whmcs_get_products` | GetProducts | READ | on | 🟢 | Lista produtos com fields=lite (default), envelope ≤40 KB e cursor `next_limitstart`; `product_url` só com `fields=full` + `include_urls=true`, sempre relativa |
| 32 | `whmcs_get_order_statuses` | GetOrderStatuses | READ | on | 🟢 | Status de pedido configurados + contagem |
| 33 | `whmcs_get_promotions` | GetPromotions | READ | on | 🟢 | Promoções/cupons; filtro opcional por código |

## ProjectManagerTools (9)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 34 | `whmcs_list_projects` | GetProjects | READ | on | 🟢 | completed omitido = todos; false = incompletos; true = concluídos |
| 35 | `whmcs_get_project` | GetProject | READ | on | 🟢 | Projeto + tarefas |
| 36 | `whmcs_create_project` | CreateProject | WRITE | on | 🟡 | Cria projeto |
| 37 | `whmcs_update_project` | UpdateProject | WRITE | on | 🟡 | Atualiza projeto |
| 38 | `whmcs_add_project_task` | AddProjectTask | WRITE | on | 🟡 | Adiciona tarefa |
| 39 | `whmcs_update_project_task` | UpdateProjectTask | WRITE | on | 🟡 | Atualiza tarefa |
| 40 | `whmcs_start_task_timer` | StartTaskTimer | WRITE | on | 🟡 | Inicia cronômetro |
| 41 | `whmcs_end_task_timer` | EndTaskTimer | WRITE | on | 🟡 | Para cronômetro |
| 42 | `whmcs_add_project_message` | AddProjectMessage | WRITE | on | 🟡 | Adiciona mensagem/comentário |

## QuoteTools (7)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 43 | `whmcs_list_quotes` | GetQuotes | READ | on | 🟢 | fields=lite (default) sem PII direta, client reduzido; shape estável e is_orphan=true sem cliente |
| 44 | `whmcs_get_quote` | GetQuotes | READ | on | 🟢 | Mesmo contrato lite/full e normalização da listagem |
| 45 | `whmcs_create_quote` | CreateQuote | WRITE | on | 🟡 | Cria orçamento |
| 46 | `whmcs_update_quote` | UpdateQuote | WRITE | on | 🟡 | Atualiza orçamento |
| 47 | `whmcs_duplicate_quote` | GetQuotes (read) + CreateQuote (write) | WRITE | on | 🟡 | Duplica cotação com overrides |
| 48 | `whmcs_convert_quote_to_invoice` | AcceptQuote + UpdateInvoice | FINANCIAL | ⛔ off | 🟠 | **Converte cotação em fatura — efeito financeiro, não idempotente** |
| 49 | `whmcs_delete_quote` | DeleteQuote | DESTRUCTIVE | ⛔ off | 🟠 | **Exclui cotação — irreversível, exige confirm=true** |

## ServiceTools (1)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 50 | `whmcs_list_services` | GetClientsProducts | READ | on | 🟢 | Lista serviços de um cliente |

## SupportInfoTools (3) — todas READ 🟢

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 51 | `whmcs_get_support_departments` | GetSupportDepartments | READ | on | 🟢 | Departamentos de suporte |
| 52 | `whmcs_get_support_statuses` | GetSupportStatuses | READ | on | 🟢 | Status de tickets |
| 53 | `whmcs_get_ticket_counts` | GetTicketCounts | READ | on | 🟢 | Contagem de tickets; department_scope_limited indica escopo limitado |

## SystemTools (6)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 54 | `whmcs_get_stats` | GetStats | READ | on | 🟢 | Estatísticas gerais |
| 55 | `whmcs_get_activity_log` | GetActivityLog | READ | on | 🟢 | Log de atividades (filtra Hooks Debug, auto-scan de páginas ruidosas, `scan_capped` se o teto bater) |
| 56 | `whmcs_get_admin_details` | GetAdminDetails | READ | on | 🟢 | Admin autenticado; inclui `system_host` (hostname) para validação de ambiente |
| 57 | `whmcs_get_todo_items` | GetToDoItems | READ | on | 🟢 | Itens To-Do administrativos |
| 58 | `whmcs_update_todo_item` | UpdateToDoItem | WRITE | on | 🟡 | Atualiza item To-Do (interno) |
| 59 | `whmcs_get_currencies` | GetCurrencies | READ | on | 🟢 | Moedas configuradas |

## TicketTools (5)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 60 | `whmcs_list_tickets` | GetTickets | READ | on | 🟢 | fields=lite (default) sem identidade/CC/anexos; full é opt-in; default une Open+Customer-Reply |
| 61 | `whmcs_get_ticket` | GetTicket | READ | on | 🟢 | Detalhes e histórico; use ticketid (id interno) OU tid (número exibido); fields=lite (default) remove name/email/cc do ticket e de cada reply/note, fields=full é opt-in |
| 62 | `whmcs_open_ticket` | OpenTicket | WRITE | on | 🟡 | Abre novo ticket; sem clientid exige `allow_guest=true`; notify_client=true requer COMMS |
| 63 | `whmcs_reply_ticket` | AddTicketReply | WRITE | on | 🟡 | Responde ticket; name/email/clientid só em ticket guest; notify_client=true requer COMMS |
| 64 | `whmcs_update_ticket` | UpdateTicket | WRITE | on | 🟡 | Atualiza status/prioridade/dept |

---

## Qual ID usar

Guia rápido para evitar confundir IDs:

| Campo | Fonte | Exemplo | Usar quando | Nunca |
|-------|-------|---------|-----------|-------|
| `clientid` | `tblclients.id` | `5` | Referencing clientes, contatos, invoices | Usar como owner_user_id ou userid |
| `userid` (ticket) | `tblclients.id` (cliente do ticket) | `5` (ou `0` = guest) | Filtrando tickets por cliente | Usar como ticketid ou adminid |
| `id` / `ticketid` | Internal ticket ID | `30` | Chamando `whmcs_get_ticket`, `whmcs_reply_ticket`, `whmcs_update_ticket` | Usar como tid (número exibido) |
| `tid` / `display_id` | Ticket display number | `084535` | Filtrando/referenciando por número visível (#084535) | Usar como ticketid (ID interno) |
| `owner_user_id` / `users[].id` | `tblusers` (admin/staff login) | `3` | Referencing staff/admins em projetos | Nunca usar como clientid ou userid de ticket |

**Em resumo:**
- Tickets usam **dois IDs distintos**: `ticketid` (interno, 30) e `tid` (número, 084535)
- Sempre que uma tool pedir `ticketid`, use o ID interno — nunca o número exibido
- `userid` num ticket **é sempre um `clientid`** (cliente proprietário)

---

## Resumo por classe de gate

| Gate | Qtde | Default | Tools |
|------|------|---------|-------|
| READ | 38 | on | consultas LocalAPI — sem risco |
| WRITE | 19 | **on** | administrativas reversíveis |
| DESTRUCTIVE | 2 | ⛔ off | cancel_order, delete_quote (exigem confirm=true) |
| FINANCIAL | 1 | ⛔ off | convert_quote_to_invoice (não idempotente) |
| CRM-READ | 4 | on | leituras do CRM mgCRM2 (MgCrmRepository) |
| **Total** | **64** | | |

> **Nota:** COMMS é um gate ortogonal acionado por `notify_client=true`; não acrescenta
> tools à contagem. AddClient, OpenTicket e AddTicketReply continuam em suas classes base.

---

## Detalhes de risco e gates ortogonais

### COMMS ortogonal (efeito colateral de notificação)

Três tools permitem `notify_client=true` para enviar e-mail ao cliente.
O default é `notify_client=false` (sem notificação):

| Tool | Comando | Comportamento |
|------|---------|---------------|
| `whmcs_create_client` | AddClient + noemail | Gate COMMS apenas se notify_client=true |
| `whmcs_open_ticket` | OpenTicket + noemail | Gate COMMS apenas se notify_client=true |
| `whmcs_reply_ticket` | AddTicketReply + noemail | Gate COMMS apenas se notify_client=true |

A verificação ocorre centralmente em `LocalApiClient` (não na tool), então nenhuma
refatoração consegue contornar o gate.

### Gate por alvo — allowlist de ids de teste (#14)

Duas configs opcionais em `tblconfiguration` (CSV de inteiros):

| Chave | Alvo checado | Params cobertos |
|-------|--------------|-----------------|
| `nt_mcp_write_allowlist_clientids` | cliente | `clientid`, `userid`; ou dono resolvido antes do gate a partir de `ticketid` (`GetTicket`), `orderid` (`GetOrders id=`), `quoteid` (`GetQuotes quoteid=`) |
| `nt_mcp_write_allowlist_ticketids` | ticket | `ticketid` |

Regras (aplicadas em `LocalApiClient::assertTargetAllowed`, só para comandos não-READ):

- Vazia/ausente = sem restrição (comportamento anterior). As duas listas são independentes (AND).
- Alvo fora da lista → `AuthorizationException` com `write_target_not_allowed` + Activity Log
  `MCP API BLOCKED BY TARGET ALLOWLIST`. O write nunca chega à LocalAPI.
- Sem `clientid`/`userid` explícito, o dono é resolvido ANTES do gate (READ, auditado):
  `ticketid` → `GetTicket.userid`; `orderid` → `GetOrders id=N` (achado 38: `cancel_order`);
  `quoteid` → `GetQuotes quoteid=N` (`delete_quote`, `update_quote`, `convert_quote_to_invoice`).
  Para pedido/cotação o registro devolvido precisa ter `id` igual ao pedido e ser único —
  lookup com erro, vazio, ou com outro id (filtro ignorado) = **negado**.
  Registro **guest/órfão** (userid 0) não tem cliente a checar — para cobrir guests,
  configure também a allowlist de tickets.
- O gate de classe (DESTRUCTIVE/FINANCIAL opt-in) e o `confirm=true` das tools continuam
  valendo ANTES da resolução: classe desligada nega sem fazer lookup.
- Falha de leitura da config ou token não numérico → lista vazia = nega todo alvo (fail-closed, auditado).
- **Não cobertos**: comandos sem nenhum id de alvo (`AddClient`, `CreateQuote` sem `userid`,
  projetos/tarefas/To-Do). Esses seguem só pelo gate de classe.

Complementos na camada de tool (não substituem o gate central):

- `whmcs_open_ticket` sem `clientid` exige `allow_guest=true` explícito (senão `InvalidArgumentException`).
- `whmcs_reply_ticket` só aceita `name`/`email`/`clientid` quando o ticket ainda é guest
  (checado via `GetTicket`); em ticket com cliente vinculado é erro — evita reatribuir a resposta.

### CRM read-only (release cut)

`whmcs_crm_create_lead`, `whmcs_crm_update_contact`, `whmcs_crm_add_followup` e
`whmcs_crm_add_note` foram removidas: apontavam para um schema fictício e não eram
funcionais na instalação mgCRM2. Permanecem somente as quatro leituras reais. A
capability `crm` indica disponibilidade dessas leituras, não autorização de escrita.

---

## Listas vazias — chaves garantidas (#17)

O WHMCS omite a chave da coleção inteira em alguns comandos quando não há resultado
(em outros vem `""`). `ResponseRedactor::GUARANTEED_LIST_KEYS` reinsere como `[]` —
**só** para chaves confirmadas em payload real (chave errada = campo fantasma).

| Comando | Chave garantida | Evidência |
|---------|-----------------|-----------|
| `GetContacts` | `contacts` | payload real desenv (gotcha CLAUDE.md, 2026-08-23) |
| `GetToDoItems` | `todoitems` | payload real desenv (gotcha CLAUDE.md, 2026-08-23) |
| `GetClientGroups` | `groups` | payload real desenv (gotcha CLAUDE.md, 2026-08-23) |
| `GetClientsAddons` | `addons` | payload real desenv (gotcha CLAUDE.md, 2026-08-23) |
| `GetPromotions` | `promotions` | inventário live desenv, 2026-08-23 |
| `GetOrders` | `orders` | inventário live desenv, 2026-08-23 |
| `GetTransactions` | `transactions` | inventário live desenv, 2026-08-23 |
| `GetTickets` | `tickets` | inventário live desenv, 2026-08-23 |
| `GetClients` | `clients` | inventário live desenv, 2026-08-23 |
| `GetProducts` | `products` | inventário live desenv, 2026-08-23 |

Cada linha tem teste próprio em `ResponseRedactorTest` (data provider
`guaranteedListKeys`); a constante e o provider são comparados por reflexão, então
adicionar uma chave sem caso de teste falha a suite.

### Inventário live — resultado (#17, desenv, 2026-08-23)

Cada comando READ do `ALLOWED_COMMANDS` foi chamado com filtro que devolve zero
resultado; os que omitiram a coleção tiveram o NOME da chave confirmado numa
segunda passada com payload não-vazio (nunca por heurística).

**Omitem a chave → entraram na constante:** `GetOrders`, `GetTransactions`,
`GetTickets`, `GetClients`, `GetProducts` (além das 5 já mapeadas).

**Devolvem `[]` corretamente (nada a fazer):** `GetInvoices`, `GetQuotes`,
`GetProjects`, `GetAnnouncements`, `GetSupportDepartments`, `GetSupportStatuses`,
`GetCurrencies`, `GetPaymentMethods`, `GetOrderStatuses`, `GetActivityLog`,
`GetTLDPricing`.

**Devolvem `""` → já cobertos por `EMPTY_STRING_MEANS_EMPTY_LIST`:**
`GetClientsProducts` (`products`), `GetClientsDomains` (`domains`).

**Inconclusivos** — omitiram a chave, mas a instalação de desenv não tem dado
para confirmar o nome com payload não-vazio: `GetTicketPredefinedCats`,
`GetTicketPredefinedReplies`. **Não** foram adicionados: chave com nome
adivinhado cria campo fantasma, que é pior que a ausência. Reconfirmar numa
instalação que tenha categorias/respostas predefinidas cadastradas.

Para os inconclusivos, o cliente deve continuar tratando `!key` e
`key.length === 0` como equivalentes.

## Risco identificado

Nenhuma tool de impacto crítico está desprotegida pela gate no código atual.
Os comandos que afetam serviços cliente (`ModuleSuspend`, `ModuleUnsuspend`,
`DomainRegister`, `DomainRenew`, `UpgradeProduct`, `AcceptOrder`, `AddOrder`)
e comunicação (`SendEmail`, `SendQuote`) **não estão sequer na allowlist**
(foram removidos em T1 junto com a reclassificação de gate WO-2).

Comandos mutáveis presentes têm gates desligados por padrão. Em particular:
- `CancelOrder` e `DeleteQuote` → DESTRUCTIVE (gate opt-in + `confirm=true`)
- `OpenTicket` + `AddTicketReply` → WRITE; `notify_client=true` exige também COMMS

---

## Verificação de integridade

Rodar após qualquer mudança de tools:

```bash
# Contar tools reais no código
grep -oh "name: '[a-z_0-9]*'" src/Tools/*.php | sort -u | wc -l

# Contar na documentação
grep -c "^| [0-9]" docs/TOOLS.md

# Fazer diff do allowlist
diff <(grep "'" src/Whmcs/LocalApiClient.php | grep -o "'[A-Z][A-Za-z]*'" | sort -u) \
     <(grep "| Read command mapping will fail if gate mapping missing here" docs/TOOLS.md)
```

Se contagem mismatch: adicionar/remover tool no arquivo correspondente (`src/Tools/*.php`,
`src/Whmcs/LocalApiClient.php`, `docs/TOOLS.md`).

---

## Configurações por tool (Geral)

### `whmcs_get_client` — custom fields visíveis

| Setting | Tipo | Default | Descrição |
|---------|------|---------|-----------|
| `nt_mcp_client_customfields_visible` | CSV de ints | vazio | IDs de custom fields cliente visíveis em view **full**. Se vazio: nenhum custom field é exposto em full (apenas estrutura vazia `[]`). Format: `"5,9,12,134"` — vírgulas e espaços tolerados, IDs ≤ 0 ignorados. View **lite** sempre omite customfields completamente. |

Exemplo: autorizar custom fields 5 (CPF/CNPJ) e 134 (Inscrição Estadual) em full:
```
nt_mcp_client_customfields_visible = 5,134
```

Resultado: `whmcs_get_client(clientid=123, fields="full")` devolve apenas os customfields com id 5 e 134,
rotulados com nome e tipo do campo. Ids não autorizados são silenciosamente omitidos.
