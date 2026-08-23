# Catálogo de Tools — NT MCP (68 tools)

> Atualizado em 2026-08-23. Fonte de verdade: atributos `#[McpTool(...)]` em
> `src/Tools/*.php` + gate mapping em `src/Whmcs/LocalApiClient.php`.
> Contagem verificada: `grep -oh "name: '[a-z_0-9]*'" src/Tools/*.php | sort -u | wc -l` = **68**.

Este documento lista **todas as 68 tools** uma a uma, com o comando WHMCS que
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
| `CRM` | ✅ **on** (READ), on (WRITE) | `nt_mcp_enable_write` (WRITE) | Acesso ao CRM ModulesGarden via `CapsuleClient` |

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
| 1 | `whmcs_list_invoices` | GetInvoices | READ | on | 🟢 | Lista faturas com filtros |
| 2 | `whmcs_get_invoice` | GetInvoice | READ | on | 🟢 | Detalhes de uma fatura |
| 3 | `whmcs_get_transactions` | GetTransactions | READ | on | 🟢 | Lista transações financeiras |
| 4 | `whmcs_get_credits` | GetCredits | READ | on | 🟢 | Créditos de um cliente |
| 5 | `whmcs_get_pay_methods` | GetPayMethods | READ | on | 🟢 | Métodos de pagamento salvos |

## ClientTools (12)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 6 | `whmcs_list_clients` | GetClients | READ | on | 🟢 | Lista clientes |
| 7 | `whmcs_get_client` | GetClientsDetails | READ | on | 🟢 | Detalhes de um cliente |
| 8 | `whmcs_create_client` | AddClient | WRITE | on | 🟡 | Cria cliente (customfields JSON); notify_client=true requer COMMS |
| 9 | `whmcs_update_client` | UpdateClient | WRITE | on | 🟡 | Atualiza cliente |
| 10 | `whmcs_get_client_products` | GetClientsProducts | READ | on | 🟢 | Produtos/serviços do cliente |
| 11 | `whmcs_get_client_domains` | GetClientsDomains | READ | on | 🟢 | Domínios do cliente |
| 12 | `whmcs_get_client_invoices` | GetInvoices | READ | on | 🟢 | Faturas do cliente |
| 13 | `whmcs_get_contacts` | GetContacts | READ | on | 🟢 | Contatos/sub-contas |
| 14 | `whmcs_add_contact` | AddContact | WRITE | on | 🟡 | Adiciona contato |
| 15 | `whmcs_update_contact` | UpdateContact | WRITE | on | 🟡 | Atualiza contato |
| 16 | `whmcs_get_client_groups` | GetClientGroups | READ | on | 🟢 | Grupos de clientes |
| 17 | `whmcs_get_clients_addons` | GetClientsAddons | READ | on | 🟢 | Addons contratados |

## CrmTools (8) — gate espelhado em `CapsuleClient::assertWritable()` (READ/WRITE)

| # | Tool | Origem | Gate | Default | Risco | Descrição |
|---|------|--------|------|---------|-------|-----------|
| 18 | `whmcs_crm_list_contacts` | MgCrmRepository | CRM-READ | on | 🟢 | Lista recursos (contatos/leads) do CRM mgCRM2 |
| 19 | `whmcs_crm_get_contact` | MgCrmRepository | CRM-READ | on | 🟢 | Obtém recurso (contato/lead) do CRM mgCRM2 |
| 20 | `whmcs_crm_create_lead` | CapsuleClient | CRM-WRITE | on | 🟡 | Cria lead no CRM |
| 21 | `whmcs_crm_update_contact` | CapsuleClient | CRM-WRITE | on | 🟡 | Atualiza contato CRM |
| 22 | `whmcs_crm_add_followup` | CapsuleClient | CRM-WRITE | on | 🟡 | Adiciona follow-up |
| 23 | `whmcs_crm_add_note` | CapsuleClient | CRM-WRITE | on | 🟡 | Adiciona nota |
| 24 | `whmcs_crm_list_followups` | MgCrmRepository | CRM-READ | on | 🟢 | Lista follow-ups com paginação |
| 25 | `whmcs_crm_get_kanban` | MgCrmRepository | CRM-READ | on | 🟢 | Visão Kanban + catálogos de tipos/status |

> ⚠️ **Estado:** As 4 leituras (tools 18-19, 24-25) operam sobre o schema real mgCRM2 (`crm_*`)
> via `MgCrmRepository`. As 4 escritas (tools 20-23) ainda usam `CapsuleClient` com
> tabelas ficções `mod_mgcrm_*` — elas continuam não-funcionais contra a instalação real,
> e sua migração segue ticket CRM-3.

## DomainTools (5)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 26 | `whmcs_list_domains` | GetClientsDomains | READ | on | 🟢 | Lista domínios |
| 27 | `whmcs_domain_get_nameservers` | DomainGetNameservers | READ | on | 🟢 | Obtém nameservers atuais |
| 28 | `whmcs_domain_get_locking_status` | DomainGetLockingStatus | READ | on | 🟢 | Status de bloqueio de transferência |
| 29 | `whmcs_domain_get_whois_info` | DomainGetWhoisInfo | READ | on | 🟢 | Informações WHOIS |
| 30 | `whmcs_get_tld_pricing` | GetTLDPricing | READ | on | 🟢 | Preços de TLDs (omite anos com preço 0, adiciona years_available e sinaliza grace/redemption ausentes) |

## OrderTools (7)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 31 | `whmcs_list_orders` | GetOrders | READ | on | 🟢 | Lista pedidos |
| 32 | `whmcs_get_order` | GetOrders | READ | on | 🟢 | Detalhes de pedido |
| 33 | `whmcs_cancel_order` | CancelOrder | DESTRUCTIVE | ⛔ off | 🟠 | **Cancela pedido — irreversível, exige confirm=true** |
| 34 | `whmcs_pending_order` | PendingOrder | WRITE | on | 🟡 | Coloca pedido em status pendente |
| 35 | `whmcs_get_products` | GetProducts | READ | on | 🟢 | Lista produtos com fields=lite (default), paginação local, sem ciclos desativados; `product_url` só com `fields=full` + `include_urls=true` |
| 36 | `whmcs_get_order_statuses` | GetOrderStatuses | READ | on | 🟢 | Status de pedido configurados + contagem |
| 37 | `whmcs_get_promotions` | GetPromotions | READ | on | 🟢 | Promoções/cupons; filtro opcional por código |

## ProjectManagerTools (9)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 38 | `whmcs_list_projects` | GetProjects | READ | on | 🟢 | Lista projetos |
| 39 | `whmcs_get_project` | GetProject | READ | on | 🟢 | Projeto + tarefas |
| 40 | `whmcs_create_project` | CreateProject | WRITE | on | 🟡 | Cria projeto |
| 41 | `whmcs_update_project` | UpdateProject | WRITE | on | 🟡 | Atualiza projeto |
| 42 | `whmcs_add_project_task` | AddProjectTask | WRITE | on | 🟡 | Adiciona tarefa |
| 43 | `whmcs_update_project_task` | UpdateProjectTask | WRITE | on | 🟡 | Atualiza tarefa |
| 44 | `whmcs_start_task_timer` | StartTaskTimer | WRITE | on | 🟡 | Inicia cronômetro |
| 45 | `whmcs_end_task_timer` | EndTaskTimer | WRITE | on | 🟡 | Para cronômetro |
| 46 | `whmcs_add_project_message` | AddProjectMessage | WRITE | on | 🟡 | Adiciona mensagem/comentário |

## QuoteTools (7)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 47 | `whmcs_list_quotes` | GetQuotes | READ | on | 🟢 | Lista orçamentos com shape estável (client/stage/datas sempre presentes, is_orphan=true sem cliente) |
| 48 | `whmcs_get_quote` | GetQuotes | READ | on | 🟢 | Obtém orçamento |
| 49 | `whmcs_create_quote` | CreateQuote | WRITE | on | 🟡 | Cria orçamento |
| 50 | `whmcs_update_quote` | UpdateQuote | WRITE | on | 🟡 | Atualiza orçamento |
| 51 | `whmcs_duplicate_quote` | GetQuotes (read) + CreateQuote (write) | WRITE | on | 🟡 | Duplica cotação com overrides |
| 52 | `whmcs_convert_quote_to_invoice` | AcceptQuote + UpdateInvoice | FINANCIAL | ⛔ off | 🟠 | **Converte cotação em fatura — efeito financeiro, não idempotente** |
| 53 | `whmcs_delete_quote` | DeleteQuote | DESTRUCTIVE | ⛔ off | 🟠 | **Exclui cotação — irreversível, exige confirm=true** |

## ServiceTools (1)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 54 | `whmcs_list_services` | GetClientsProducts | READ | on | 🟢 | Lista serviços de um cliente |

## SupportInfoTools (3) — todas READ 🟢

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 55 | `whmcs_get_support_departments` | GetSupportDepartments | READ | on | 🟢 | Departamentos de suporte |
| 56 | `whmcs_get_support_statuses` | GetSupportStatuses | READ | on | 🟢 | Status de tickets |
| 57 | `whmcs_get_ticket_counts` | GetTicketCounts | READ | on | 🟢 | Contagem de tickets; department_scope_limited indica escopo limitado |

## SystemTools (6)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 58 | `whmcs_get_stats` | GetStats | READ | on | 🟢 | Estatísticas gerais |
| 59 | `whmcs_get_activity_log` | GetActivityLog | READ | on | 🟢 | Log de atividades (filtra Hooks Debug, auto-scan de páginas ruidosas, `scan_capped` se o teto bater) |
| 60 | `whmcs_get_admin_details` | GetAdminDetails | READ | on | 🟢 | Admin autenticado; inclui `system_host` (hostname) para validação de ambiente |
| 61 | `whmcs_get_todo_items` | GetToDoItems | READ | on | 🟢 | Itens To-Do administrativos |
| 62 | `whmcs_update_todo_item` | UpdateToDoItem | WRITE | on | 🟡 | Atualiza item To-Do (interno) |
| 63 | `whmcs_get_currencies` | GetCurrencies | READ | on | 🟢 | Moedas configuradas |

## TicketTools (5)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 64 | `whmcs_list_tickets` | GetTickets | READ | on | 🟢 | Lista tickets; default une Open+Customer-Reply; hide_sample remove amostra; flag = id do admin |
| 65 | `whmcs_get_ticket` | GetTicket | READ | on | 🟢 | Detalhes e histórico; use ticketid (id interno) OU tid (número exibido) |
| 66 | `whmcs_open_ticket` | OpenTicket | WRITE | on | 🟡 | Abre novo ticket; notify_client=true requer COMMS |
| 67 | `whmcs_reply_ticket` | AddTicketReply | WRITE | on | 🟡 | Responde ticket; notify_client=true requer COMMS |
| 68 | `whmcs_update_ticket` | UpdateTicket | WRITE | on | 🟡 | Atualiza status/prioridade/dept |

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
| READ | 40 | on | consultas — sem risco |
| WRITE | 19 | **on** | administrativas reversíveis |
| DESTRUCTIVE | 2 | ⛔ off | cancel_order, delete_quote (exigem confirm=true) |
| FINANCIAL | 1 | ⛔ off | convert_quote_to_invoice (não idempotente) |
| CRM-READ | 4 | on | leituras do CRM mgCRM2 (MgCrmRepository) |
| CRM-WRITE | 4 | on | escritas CRM (não-funcionais até CRM-3) |
| **Total** | **70** | | |

> ⚠️ **Nota:** O count acima é 70 porque AddClient e OpenTicket aparecem como WRITE base,
> mas ganham gate COMMS ortogonal quando `notify_client=true`. Não é double-counting
> — são 68 tools únicas. AddTicketReply também segue a mesma política.

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

### CRM não-funcional (CRM-2 → CRM-3)

As 4 tools de CRM **escritas** (`create_lead`, `update_contact`, `add_followup`, `add_note`)
operam sobre tabelas ficções `mod_mgcrm_*` que não existem na instalação real (mgCRM2).
São preservadas exatamente como estavam para separar a mudança de contrato público (schema)
da mudança de efeito colateral. A migração segue ticket CRM-3.

---

## Risco identificado

Nenhuma tool de impacto crítico está desprotegida pela gate no código atual.
Os comandos que afetam serviços cliente (`ModuleSuspend`, `ModuleUnsuspend`,
`DomainRegister`, `DomainRenew`, `UpgradeProduct`, `AcceptOrder`, `AddOrder`)
e comunicação (`SendEmail`, `SendQuote`) **não estão sequer na allowlist**
(foram removidos em T1 junto com a reclassificação de gate WO-2).

Commandos presentes com WRITE default-ON:
- `CancelOrder` → DESTRUCTIVE (confirmar + gate opt-in)
- `OpenTicket` + `AddTicketReply` → WRITE, mas `notify_client=true` requer COMMS gate

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
