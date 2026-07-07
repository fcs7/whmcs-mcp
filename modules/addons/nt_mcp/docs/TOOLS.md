# Catálogo de Tools — NT MCP (86 tools)

> Gerado em 2026-07-07. Fonte de verdade: atributos `#[McpTool(...)]` em
> `src/Tools/*.php`. Contagem: `grep -c '#\[McpTool(' src/Tools/*.php` = **86**.

Este documento lista **todas as 86 tools** uma a uma, com o comando WHMCS que
cada uma invoca, a classe do gate de segurança (WO-2), se está **ligada por
padrão**, e o **nível de risco** — para avaliar a necessidade de cada tool e
decidir cortes.

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

**Ponto de atenção:** `WRITE` é **on por padrão**. Tools de classe WRITE que
mexem no serviço do cliente rodam sem opt-in — ver seção "Recomendação de corte".

Legenda de risco:
- 🟢 **READ** — sem risco
- 🟡 **WRITE administrativa** reversível, baixo impacto
- 🟠 **WRITE de impacto ao cliente, default-ON** (não protegida por gate opt-in) — **prioridade de revisão**
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

## DomainTools (9)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 26 | `whmcs_list_domains` | GetClientsDomains | READ | on | 🟢 | Lista domínios |
| 27 | `whmcs_register_domain` | DomainRegister | **COST** | ⛔ off | 🔵 | **Registra domínio — gasta dinheiro no registrar** |
| 28 | `whmcs_renew_domain` | DomainRenew | **COST** | ⛔ off | 🔵 | **Renova domínio — gasta dinheiro** |
| 29 | `whmcs_update_nameservers` | DomainUpdateNameservers | WRITE | on | 🟡 | Atualiza nameservers |
| 30 | `whmcs_domain_get_nameservers` | DomainGetNameservers | READ | on | 🟢 | Obtém nameservers |
| 31 | `whmcs_domain_get_locking_status` | DomainGetLockingStatus | READ | on | 🟢 | Status de bloqueio |
| 32 | `whmcs_domain_get_whois_info` | DomainGetWhoisInfo | READ | on | 🟢 | WHOIS |
| 33 | `whmcs_get_tld_pricing` | GetTLDPricing | READ | on | 🟢 | Preços de TLDs |
| 34 | `whmcs_update_client_domain` | UpdateClientDomain | WRITE | on | 🟡 | Atualiza registro de domínio |

## OrderTools (9)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 35 | `whmcs_list_orders` | GetOrders | READ | on | 🟢 | Lista pedidos |
| 36 | `whmcs_get_order` | GetOrders | READ | on | 🟢 | Detalhes de pedido |
| 37 | `whmcs_accept_order` | AcceptOrder | **COST** | ⛔ off | 🔵 | **Aceita pedido + provisiona serviço** |
| 38 | `whmcs_cancel_order` | CancelOrder | WRITE | **on** | 🟠 | **Cancela pedido — default-ON, sem gate opt-in** |
| 39 | `whmcs_add_order` | AddOrder | **COST** | ⛔ off | 🔵 | Cria pedido (pode provisionar/cobrar) |
| 40 | `whmcs_get_order_statuses` | GetOrderStatuses | READ | on | 🟢 | Lista status de pedidos |
| 41 | `whmcs_get_products` | GetProducts | READ | on | 🟢 | Lista produtos |
| 42 | `whmcs_get_promotions` | GetPromotions | READ | on | 🟢 | Lista promoções |
| 43 | `whmcs_pending_order` | PendingOrder | WRITE | on | 🟡 | Coloca pedido como pendente |

## ProjectManagerTools (10)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 44 | `whmcs_list_projects` | GetProjects | READ | on | 🟢 | Lista projetos |
| 45 | `whmcs_get_project` | GetProject | READ | on | 🟢 | Projeto + tarefas |
| 46 | `whmcs_create_project` | CreateProject | WRITE | on | 🟡 | Cria projeto |
| 47 | `whmcs_update_project` | UpdateProject | WRITE | on | 🟡 | Atualiza projeto |
| 48 | `whmcs_add_project_task` | AddProjectTask | WRITE | on | 🟡 | Adiciona tarefa |
| 49 | `whmcs_update_project_task` | UpdateProjectTask | WRITE | on | 🟡 | Atualiza tarefa |
| 50 | `whmcs_delete_project_task` | DeleteProjectTask | **DESTRUCTIVE** | ⛔ off | 🔵 | **Remove tarefa (irreversível)** |
| 51 | `whmcs_start_task_timer` | StartTaskTimer | WRITE | on | 🟡 | Inicia timer |
| 52 | `whmcs_end_task_timer` | EndTaskTimer | WRITE | on | 🟡 | Para timer |
| 53 | `whmcs_add_project_message` | AddProjectMessage | WRITE | on | 🟡 | Adiciona mensagem |

## QuoteTools (6)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 54 | `whmcs_list_quotes` | GetQuotes | READ | on | 🟢 | Lista orçamentos |
| 55 | `whmcs_get_quote` | GetQuotes | READ | on | 🟢 | Obtém orçamento |
| 56 | `whmcs_create_quote` | CreateQuote | WRITE | on | 🟡 | Cria orçamento |
| 57 | `whmcs_update_quote` | UpdateQuote | WRITE | on | 🟡 | Atualiza orçamento |
| 58 | `whmcs_send_quote` | SendQuote | **COMMS** | ⛔ off | 🔵 | **Envia orçamento por e-mail ao cliente** |
| 59 | `whmcs_accept_quote` | AcceptQuote | **FINANCIAL** | ⛔ off | 🔵 | **Aceita orçamento — gera fatura** |

## ServiceTools (4)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 60 | `whmcs_list_services` | GetClientsProducts | READ | on | 🟢 | Lista serviços do cliente |
| 61 | `whmcs_suspend_service` | ModuleSuspend | WRITE | **on** | 🟠 | **Suspende serviço do cliente — default-ON, sem gate** |
| 62 | `whmcs_unsuspend_service` | ModuleUnsuspend | WRITE | **on** | 🟠 | **Reativa serviço — default-ON, sem gate** |
| 63 | `whmcs_upgrade_service` | UpgradeProduct | **COST** | ⛔ off | 🔵 | **Upgrade de serviço — muda billing** |

## SupportInfoTools (7) — todas READ 🟢

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 64 | `whmcs_get_support_departments` | GetSupportDepartments | READ | on | 🟢 | Departamentos de suporte |
| 65 | `whmcs_get_support_statuses` | GetSupportStatuses | READ | on | 🟢 | Status de tickets |
| 66 | `whmcs_get_ticket_counts` | GetTicketCounts | READ | on | 🟢 | Contagem de tickets |
| 67 | `whmcs_get_ticket_notes` | GetTicketNotes | READ | on | 🟢 | Notas de ticket |
| 68 | `whmcs_get_ticket_predefined_cats` | GetTicketPredefinedCats | READ | on | 🟢 | Categorias de respostas |
| 69 | `whmcs_get_ticket_predefined_replies` | GetTicketPredefinedReplies | READ | on | 🟢 | Respostas predefinidas |
| 70 | `whmcs_get_ticket_attachment` | GetTicketAttachment | READ | on | 🟢 | Anexo de ticket |

## SystemTools (11)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 71 | `whmcs_get_stats` | GetStats | READ | on | 🟢 | Estatísticas gerais |
| 72 | `whmcs_send_email` | SendEmail | **COMMS** | ⛔ off | 🔵 | **Envia e-mail (template) ao cliente** |
| 73 | `whmcs_get_activity_log` | GetActivityLog | READ | on | 🟢 | Log de atividades |
| 74 | `whmcs_get_admin_details` | GetAdminDetails | READ | on | 🟢 | Admin autenticado |
| 75 | `whmcs_get_currencies` | GetCurrencies | READ | on | 🟢 | Moedas |
| 76 | `whmcs_get_email_templates` | GetEmailTemplates | READ | on | 🟢 | Templates de e-mail |
| 77 | `whmcs_get_payment_methods` | GetPaymentMethods | READ | on | 🟢 | Gateways de pagamento |
| 78 | `whmcs_get_todo_items` | GetToDoItems | READ | on | 🟢 | Itens To-Do |
| 79 | `whmcs_get_todo_statuses` | GetToDoItemStatuses | READ | on | 🟢 | Status To-Do |
| 80 | `whmcs_update_todo_item` | UpdateToDoItem | WRITE | on | 🟡 | Atualiza item To-Do (interno) |
| 81 | `whmcs_log_activity` | LogActivity | WRITE | on | 🟡 | Registra atividade (interno) |

## TicketTools (5)

| # | Tool | Comando | Gate | Default | Risco | Descrição |
|---|------|---------|------|---------|-------|-----------|
| 82 | `whmcs_list_tickets` | GetTickets | READ | on | 🟢 | Lista tickets |
| 83 | `whmcs_get_ticket` | GetTicket | READ | on | 🟢 | Ticket + histórico |
| 84 | `whmcs_open_ticket` | OpenTicket | WRITE | on | 🟡 | Abre ticket (pode notificar) |
| 85 | `whmcs_reply_ticket` | AddTicketReply | WRITE | **on** | 🟠 | **Responde ticket — envia e-mail ao cliente, default-ON** |
| 86 | `whmcs_update_ticket` | UpdateTicket | WRITE | on | 🟡 | Atualiza ticket |

---

## Resumo por classe de gate

| Gate | Qtde | Default | Tools |
|------|------|---------|-------|
| READ | 49 | on | consultas — sem risco |
| WRITE | 28 | **on** | administrativas reversíveis (dessas, 4 🟠 sensíveis: suspend/unsuspend_service, cancel_order, reply_ticket) |
| COST | 5 | ⛔ off | register/renew_domain, accept_order, add_order, upgrade_service |
| COMMS | 2 | ⛔ off | send_email, send_quote |
| DESTRUCTIVE | 1 | ⛔ off | delete_project_task |
| FINANCIAL | 1 | ⛔ off | accept_quote |
| **Total** | **86** | | |

---

## Recomendação de corte (para aprovação 1-a-1)

Filosofia adotada no projeto: **remover fisicamente** o que não se usa, em vez
de só desativar (já foi feito com 10 tools destrutivas/financeiras). Abaixo, os
candidatos, priorizados por risco real.

### Prioridade ALTA — risco ao cliente E ligadas por padrão (WRITE, sem gate opt-in)

Estas rodam **sem opt-in** — qualquer token com WRITE (o default) as executa.
São as mais perigosas por estarem "ligadas e escondidas":

| Tool | Impacto | Recomendação |
|------|---------|--------------|
| `whmcs_suspend_service` (61) | Derruba o serviço de um cliente | ⬜ remover / ⬜ mover p/ gate COST/DESTRUCTIVE / ⬜ manter |
| `whmcs_unsuspend_service` (62) | Religa serviço | ⬜ remover / ⬜ manter (par do suspend) |
| `whmcs_cancel_order` (38) | Cancela pedido | ⬜ remover / ⬜ mover p/ gate / ⬜ manter |
| `whmcs_reply_ticket` (85) | **Envia e-mail ao cliente** | ⬜ remover / ⬜ reclassificar p/ COMMS / ⬜ manter |

> Sugestão técnica: se mantiver, reclassificar `ModuleSuspend`/`ModuleUnsuspend`/
> `CancelOrder` para uma classe default-DENY (ex.: DESTRUCTIVE) e `AddTicketReply`
> para COMMS, alinhando o gate ao risco real — hoje estão como WRITE default-ON.

### Prioridade MÉDIA — sensíveis mas já protegidas (default-DENY)

Já exigem opt-in explícito (`nt_mcp_enable_*`). Avaliar se são necessárias:

| Tool | Classe | Necessária? |
|------|--------|-------------|
| `whmcs_register_domain` (27) | COST (gasta $) | ⬜ remover / ⬜ manter |
| `whmcs_renew_domain` (28) | COST (gasta $) | ⬜ remover / ⬜ manter |
| `whmcs_accept_order` (37) | COST (provisiona) | ⬜ remover / ⬜ manter |
| `whmcs_add_order` (39) | COST | ⬜ remover / ⬜ manter |
| `whmcs_upgrade_service` (63) | COST (billing) | ⬜ remover / ⬜ manter |
| `whmcs_accept_quote` (59) | FINANCIAL (gera fatura) | ⬜ remover / ⬜ manter |
| `whmcs_delete_project_task` (50) | DESTRUCTIVE | ⬜ remover / ⬜ manter |
| `whmcs_send_email` (72) | COMMS | ⬜ remover / ⬜ manter |
| `whmcs_send_quote` (58) | COMMS | ⬜ remover / ⬜ manter |

### Baixa prioridade

As 24 WRITE administrativas restantes (create/update client, contact, project,
quote, ticket, CRM, timers, todo) são reversíveis e de baixo impacto — manter
salvo se não houver caso de uso.

> **A remoção física NÃO faz parte deste branch** — este catálogo é para você
> decidir 1-a-1. Marque as tools a remover; a remoção (allowlist + classe Tools +
> testes de regressão) vira um branch separado, seguindo o mesmo padrão da
> remoção anterior das 10 tools.
