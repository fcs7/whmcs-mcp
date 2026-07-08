# Catálogo de Tools — NT MCP (73 tools)

> Gerado em 2026-07-08. Fonte de verdade: atributos `#[McpTool(...)]` em
> `src/Tools/*.php`. Contagem: `grep -c '#\[McpTool(' src/Tools/*.php` = **73**.

Este documento lista **todas as 73 tools** uma a uma, com o comando WHMCS que
cada uma invoca, a classe do gate de segurança (WO-2), se está **ligada por
padrão**, e o **nível de risco** — para documentação. Tools de risco removidas
fisicamente em 2026-04 e 2026-07 (ver "Histórico de cortes" abaixo).

---

## Modelo de segurança (gate WO-2)

Cada comando WHMCS é classificado em `LocalApiClient::COMMAND_CLASS`. O gate
decide se a chamada passa:

| Classe | Default | Config para habilitar | Significado |
|--------|---------|-----------------------|-------------|
| `READ` | ✅ sempre on | — | Somente consulta |
| `WRITE` | ✅ **on** | `nt_mcp_enable_write` (on) | Modifica dados reversíveis |
| `DESTRUCTIVE` | ⛔ off | `nt_mcp_enable_destructive` | Irreversível |
| `FINANCIAL` | ⛔ off | `nt_mcp_enable_financial` | Efeito financeiro (fatura) |
| `COST` | ⛔ off | `nt_mcp_enable_cost` | Custo/provisionamento externo |
| `COMMS` | ⛔ off | `nt_mcp_enable_comms` | Envia e-mail ao cliente |

Master switch: `nt_mcp_readonly` (fail-closed) bloqueia **tudo** exceto READ.
Comandos fora do mapa caem em `WRITE` (fail-safe). Impersonação (`adminid`/
`adminusername`) é clampada ao admin do token.

**Sensíveis remanescentes** (mantidas por decisão do dono):
- `whmcs_cancel_order` — cancela pedido (WRITE, default-ON)
- `whmcs_reply_ticket` — responde ticket com e-mail ao cliente (WRITE, default-ON)
- `whmcs_open_ticket` — abre ticket (WRITE, default-ON)
- `whmcs_pending_order` — coloca pedido como pendente (WRITE, default-ON)

Legenda de risco:
- 🟢 **READ** — sem risco
- 🟡 **WRITE administrativa** reversível, baixo impacto
- 🟠 **WRITE de impacto** (default-ON)
- 🔵 **Sensível mas default-DENY** (já exige opt-in explícito no gate)

---

## BillingTools (5) — todas READ 🟢

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 1 | `whmcs_list_invoices` | GetInvoices | READ | on | 🟢 | Lista faturas com filtros |
| 2 | `whmcs_get_invoice` | GetInvoice | READ | on | 🟢 | Detalhes de uma fatura |
| 3 | `whmcs_get_transactions` | GetTransactions | READ | on | 🟢 | Lista transações financeiras |
| 4 | `whmcs_get_credits` | GetCredits | READ | on | 🟢 | Créditos de um cliente |
| 5 | `whmcs_get_pay_methods` | GetPayMethods | READ | on | 🟢 | Métodos de pagamento (PII redigida) |

## ClientTools (12)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 6 | `whmcs_list_clients` | GetClients | READ | on | 🟢 | Lista clientes |
| 7 | `whmcs_get_client` | GetClientsDetails | READ | on | 🟢 | Detalhes de um cliente |
| 8 | `whmcs_create_client` | AddClient | WRITE | on | 🟡 | Cria cliente (customfields JSON) |
| 9 | `whmcs_update_client` | UpdateClient | WRITE | on | 🟡 | Atualiza cliente |
| 10 | `whmcs_get_client_products` | GetClientsProducts | READ | on | 🟢 | Produtos/serviços do cliente |
| 11 | `whmcs_get_client_domains` | GetClientsDomains | READ | on | 🟢 | Domínios do cliente |
| 12 | `whmcs_get_client_invoices` | GetInvoices | READ | on | 🟢 | Faturas do cliente |
| 13 | `whmcs_get_contacts` | GetContacts | READ | on | 🟢 | Contatos/sub-contas |
| 14 | `whmcs_add_contact` | AddContact | WRITE | on | 🟡 | Adiciona contato |
| 15 | `whmcs_update_contact` | UpdateContact | WRITE | on | 🟡 | Atualiza contato |
| 16 | `whmcs_get_client_groups` | GetClientGroups | READ | on | 🟢 | Grupos de clientes |
| 17 | `whmcs_get_clients_addons` | GetClientsAddons | READ | on | 🟢 | Addons contratados |

## CrmTools (8) — gate espelhado em `CapsuleClient::assertWritable()`

| # | Tool | Tabela | Gate | Default | Risco | Descrição |
|---|------|--------|------|---------|-------|-----------|
| 18 | `whmcs_crm_list_contacts` | mod_mgcrm_contacts | READ | on | 🟢 | Lista contatos/leads CRM |
| 19 | `whmcs_crm_get_contact` | mod_mgcrm_contacts | READ | on | 🟢 | Obtém contato CRM |
| 20 | `whmcs_crm_create_lead` | mod_mgcrm_leads | WRITE | on | 🟡 | Cria lead |
| 21 | `whmcs_crm_update_contact` | mod_mgcrm_contacts | WRITE | on | 🟡 | Atualiza contato CRM |
| 22 | `whmcs_crm_add_followup` | mod_mgcrm_followups | WRITE | on | 🟡 | Adiciona follow-up |
| 23 | `whmcs_crm_add_note` | mod_mgcrm_notes | WRITE | on | 🟡 | Adiciona nota |
| 24 | `whmcs_crm_list_followups` | mod_mgcrm_followups | READ | on | 🟢 | Lista follow-ups |
| 25 | `whmcs_crm_get_kanban` | mod_mgcrm_contacts | READ | on | 🟢 | Visão Kanban por estágio |

> ⚠️ Nomes de tabela CRM (`mod_mgcrm_*`) são **placeholders** — verificar no banco real (ver CLAUDE.md).

## DomainTools (5)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 26 | `whmcs_list_domains` | GetClientsDomains | READ | on | 🟢 | Lista domínios |
| 27 | `whmcs_domain_get_nameservers` | DomainGetNameservers | READ | on | 🟢 | Obtém nameservers |
| 28 | `whmcs_domain_get_locking_status` | DomainGetLockingStatus | READ | on | 🟢 | Status de bloqueio |
| 29 | `whmcs_domain_get_whois_info` | DomainGetWhoisInfo | READ | on | 🟢 | WHOIS |
| 30 | `whmcs_get_tld_pricing` | GetTLDPricing | READ | on | 🟢 | Preços de TLDs |

## OrderTools (7)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 31 | `whmcs_list_orders` | GetOrders | READ | on | 🟢 | Lista pedidos |
| 32 | `whmcs_get_order` | GetOrders | READ | on | 🟢 | Detalhes de pedido |
| 33 | `whmcs_cancel_order` | CancelOrder | WRITE | **on** | 🟠 | **Cancela pedido — default-ON** |
| 34 | `whmcs_get_order_statuses` | GetOrderStatuses | READ | on | 🟢 | Lista status de pedidos |
| 35 | `whmcs_get_products` | GetProducts | READ | on | 🟢 | Lista produtos |
| 36 | `whmcs_get_promotions` | GetPromotions | READ | on | 🟢 | Lista promoções |
| 37 | `whmcs_pending_order` | PendingOrder | WRITE | on | 🟡 | Coloca pedido como pendente |

## ProjectManagerTools (9)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 38 | `whmcs_list_projects` | GetProjects | READ | on | 🟢 | Lista projetos |
| 39 | `whmcs_get_project` | GetProject | READ | on | 🟢 | Projeto + tarefas |
| 40 | `whmcs_create_project` | CreateProject | WRITE | on | 🟡 | Cria projeto |
| 41 | `whmcs_update_project` | UpdateProject | WRITE | on | 🟡 | Atualiza projeto |
| 42 | `whmcs_add_project_task` | AddProjectTask | WRITE | on | 🟡 | Adiciona tarefa |
| 43 | `whmcs_update_project_task` | UpdateProjectTask | WRITE | on | 🟡 | Atualiza tarefa |
| 44 | `whmcs_start_task_timer` | StartTaskTimer | WRITE | on | 🟡 | Inicia timer |
| 45 | `whmcs_end_task_timer` | EndTaskTimer | WRITE | on | 🟡 | Para timer |
| 46 | `whmcs_add_project_message` | AddProjectMessage | WRITE | on | 🟡 | Adiciona mensagem |

## QuoteTools (4)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 47 | `whmcs_list_quotes` | GetQuotes | READ | on | 🟢 | Lista orçamentos |
| 48 | `whmcs_get_quote` | GetQuotes | READ | on | 🟢 | Obtém orçamento |
| 49 | `whmcs_create_quote` | CreateQuote | WRITE | on | 🟡 | Cria orçamento |
| 50 | `whmcs_update_quote` | UpdateQuote | WRITE | on | 🟡 | Atualiza orçamento |

## ServiceTools (1)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 51 | `whmcs_list_services` | GetClientsProducts | READ | on | 🟢 | Lista serviços do cliente |

## SupportInfoTools (7) — todas READ 🟢

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 52 | `whmcs_get_support_departments` | GetSupportDepartments | READ | on | 🟢 | Departamentos de suporte |
| 53 | `whmcs_get_support_statuses` | GetSupportStatuses | READ | on | 🟢 | Status de tickets |
| 54 | `whmcs_get_ticket_counts` | GetTicketCounts | READ | on | 🟢 | Contagem de tickets |
| 55 | `whmcs_get_ticket_notes` | GetTicketNotes | READ | on | 🟢 | Notas de ticket |
| 56 | `whmcs_get_ticket_predefined_cats` | GetTicketPredefinedCats | READ | on | 🟢 | Categorias de respostas |
| 57 | `whmcs_get_ticket_predefined_replies` | GetTicketPredefinedReplies | READ | on | 🟢 | Respostas predefinidas |
| 58 | `whmcs_get_ticket_attachment` | GetTicketAttachment | READ | on | 🟢 | Anexo de ticket |

## SystemTools (10)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 59 | `whmcs_get_stats` | GetStats | READ | on | 🟢 | Estatísticas gerais |
| 60 | `whmcs_get_activity_log` | GetActivityLog | READ | on | 🟢 | Log de atividades |
| 61 | `whmcs_get_admin_details` | GetAdminDetails | READ | on | 🟢 | Admin autenticado |
| 62 | `whmcs_get_currencies` | GetCurrencies | READ | on | 🟢 | Moedas |
| 63 | `whmcs_get_email_templates` | GetEmailTemplates | READ | on | 🟢 | Templates de e-mail |
| 64 | `whmcs_get_payment_methods` | GetPaymentMethods | READ | on | 🟢 | Gateways de pagamento |
| 65 | `whmcs_get_todo_items` | GetToDoItems | READ | on | 🟢 | Itens To-Do |
| 66 | `whmcs_get_todo_statuses` | GetToDoItemStatuses | READ | on | 🟢 | Status To-Do |
| 67 | `whmcs_update_todo_item` | UpdateToDoItem | WRITE | on | 🟡 | Atualiza item To-Do (interno) |
| 68 | `whmcs_log_activity` | LogActivity | WRITE | on | 🟡 | Registra atividade (interno) |

## TicketTools (5)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 69 | `whmcs_list_tickets` | GetTickets | READ | on | 🟢 | Lista tickets |
| 70 | `whmcs_get_ticket` | GetTicket | READ | on | 🟢 | Ticket + histórico |
| 71 | `whmcs_open_ticket` | OpenTicket | WRITE | on | 🟡 | Abre ticket (pode notificar) |
| 72 | `whmcs_reply_ticket` | AddTicketReply | WRITE | **on** | 🟠 | **Responde ticket — default-ON** |
| 73 | `whmcs_update_ticket` | UpdateTicket | WRITE | on | 🟡 | Atualiza ticket |

---

## Resumo por classe de gate

| Gate | Qtde | Default | Tools |
|------|------|---------|-------|
| READ | 49 | on | consultas — sem risco |
| WRITE | 24 | **on** | administrativas reversíveis |
| COST | 0 | ⛔ off | removidas fisicamente |
| COMMS | 0 | ⛔ off | removidas fisicamente |
| DESTRUCTIVE | 0 | ⛔ off | removidas fisicamente |
| FINANCIAL | 0 | ⛔ off | removidas fisicamente |
| **Total** | **73** | | |

**Nota:** Classes padrão-DENY (COST, COMMS, DESTRUCTIVE, FINANCIAL) foram
esvaziadas por remoção física de tools sensíveis. O mecanismo de gate WO-2
permanece intacto como defesa em profundidade.

---

## Histórico de cortes

### Corte 2026-04

Remoção de 10 tools destrutivas e financeiras (preexistentes no addon anterior):
- `close_client` (DESTRUCTIVE)
- `delete_order` (DESTRUCTIVE)
- `terminate_service` (DESTRUCTIVE)
- `create_invoice` (FINANCIAL)
- `add_payment` (FINANCIAL)
- `update_invoice` (FINANCIAL)
- `add_credit` (FINANCIAL)
- `add_transaction` (FINANCIAL)
- `update_transaction` (FINANCIAL)
- `add_billable_item` (FINANCIAL)

### Corte 2026-07

Remoção de 13 tools de risco (audit recommendation implementation):
1. `whmcs_suspend_service` (WRITE, default-ON)
2. `whmcs_unsuspend_service` (WRITE, default-ON)
3. `whmcs_upgrade_service` (COST)
4. `whmcs_register_domain` (COST)
5. `whmcs_renew_domain` (COST)
6. `whmcs_update_nameservers` (WRITE)
7. `whmcs_update_client_domain` (WRITE)
8. `whmcs_send_email` (COMMS)
9. `whmcs_send_quote` (COMMS)
10. `whmcs_accept_quote` (FINANCIAL)
11. `whmcs_accept_order` (COST)
12. `whmcs_add_order` (COST)
13. `whmcs_delete_project_task` (DESTRUCTIVE)

---
