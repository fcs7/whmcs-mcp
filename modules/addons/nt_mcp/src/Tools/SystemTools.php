<?php
// src/Tools/SystemTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\DateNormalizer;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\LocalizedDate;
use NtMcp\Whmcs\ResponseRedactor;
use NtMcp\Whmcs\ToolJson;
use Mcp\Capability\Attribute\McpTool;

class SystemTools
{
    private ?LocalizedDate $localizedDate;

    public function __construct(private readonly LocalApiClient $api, ?LocalizedDate $localizedDate = null)
    {
        $this->localizedDate = $localizedDate;
    }

    private function localizedDate(): LocalizedDate
    {
        return $this->localizedDate ??= new LocalizedDate();
    }

    #[McpTool(name: 'whmcs_get_stats', description: 'Retorna estatísticas gerais do WHMCS (receita, clientes, tickets)')]
    public function getStats(): string
    {
        return ToolJson::encode($this->api->call('GetStats', []));
    }

    /**
     * `GetActivityLog` documenta `date` como "in localised format (eg
     * 01/01/2016)" — não `Y-m-d`. A conversão para o formato da instalação
     * acontece aqui; a ENTRADA é sempre não ambígua (D9).
     *
     * `hide_hook_debug` filtra CLIENT-SIDE as linhas de "Hooks Debug": a API só
     * oferece `description` como filtro positivo, não há como pedir "tudo menos
     * isto". Por isso a paginação do WHMCS é ANTERIOR ao filtro — uma página
     * pode voltar com menos de `limitnum` itens, e `totalresults` continua
     * contando o ruído. `filtered_out` diz quantas linhas saíram desta página.
     *
     * @param string $date Filtro de data. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY não são aceitos por serem ambíguos.
     */
    #[McpTool(name: 'whmcs_get_activity_log', description: 'Obtém log de atividades do sistema. Por padrão descarta as linhas de "Hooks Debug" (ruído de depuração) e informa quantas saíram em filtered_out; a paginação do WHMCS é anterior ao filtro, então a página pode vir com menos itens que limitnum.')]
    public function getActivityLog(int $limitnum = 25, int $limitstart = 0, string $user = '', string $description = '', string $date = '', bool $hide_hook_debug = true): string
    {
        $params = compact('limitnum', 'limitstart');
        if ($user !== '') $params['user'] = $user;
        if ($description !== '') $params['description'] = $description;
        if ($date !== '') {
            $params['date'] = $this->localizedDate()->fromPublicInput($date, 'date');
        }
        $result = $this->api->call('GetActivityLog', $params);
        if ($hide_hook_debug) {
            $result['filtered_out'] = ResponseRedactor::filterActivityLogNoise($result);
        }
        return ToolJson::encode($result);
    }

    #[McpTool(name: 'whmcs_get_admin_details', description: 'Obtém detalhes do administrador autenticado')]
    public function getAdminDetails(): string
    {
        return ToolJson::encode($this->api->call('GetAdminDetails', []));
    }

    #[McpTool(name: 'whmcs_get_todo_items', description: 'Lista itens de tarefas administrativas (To-Do)')]
    public function getToDoItems(string $status = '', int $limitstart = 0, int $limitnum = 25): string
    {
        $params = ['limitstart' => $limitstart, 'limitnum' => $limitnum];
        if ($status !== '') $params['status'] = $status;
        return ToolJson::encode($this->api->call('GetToDoItems', $params));
    }

    /**
     * `duedate` foi REMOVIDO temporariamente desta assinatura.
     *
     * Motivo: o contrato de entrada não pôde ser provado. A documentação oficial
     * tipa `UpdateToDoItem.duedate` como `int` sem formato; a introspecção do
     * WHMCS 8.11.2 de desenvolvimento mostrou que `tbltodolist.duedate` é uma
     * coluna `date NOT NULL` — logo o `int` da doc está errado —, mas o parser
     * que decide o formato ACEITO está em `includes/api/updatetodoitem.php`,
     * ionCube-encoded. E o tipo da coluna não desambigua: nesta mesma instalação
     * `tblquotes.validuntil` também é `date` e a API pede formato LOCALIZADO,
     * enquanto o `duedate` de projeto/tarefa também é `date` e a API pede
     * `Y-m-d`. Sem prova, enviar qualquer um dos dois é chute com efeito de
     * escrita.
     *
     * A tool e o comando `UpdateToDoItem` continuam disponíveis para
     * `status`/`title`/`description`. Reintroduzir `duedate` depende de provar o
     * formato aceito (chamada real controlada em dev ou confirmação da WHMCS).
     */
    #[McpTool(name: 'whmcs_update_todo_item', description: 'Atualiza um item To-Do existente (status, título e descrição). O campo duedate está temporariamente indisponível: o formato aceito pela API do WHMCS não pôde ser comprovado.')]
    public function updateToDoItem(int $itemid, string $status = '', string $title = '', string $description = '', int $adminid = 0): string
    {
        $params = ['itemid' => $itemid];
        if ($status !== '') $params['status'] = $status;
        if ($title !== '') $params['title'] = $title;
        if ($description !== '') $params['description'] = $description;
        if ($adminid > 0) $params['adminid'] = $adminid;
        return ToolJson::encode($this->api->call('UpdateToDoItem', $params));
    }

    #[McpTool(name: 'whmcs_get_currencies', description: 'Lista as moedas configuradas no WHMCS')]
    public function getCurrencies(): string
    {
        return ToolJson::encode($this->api->call('GetCurrencies', []));
    }
}
