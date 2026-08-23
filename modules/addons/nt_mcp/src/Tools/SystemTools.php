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
    /** @var callable|null */
    private $crmProbe;

    public function __construct(private readonly LocalApiClient $api, ?LocalizedDate $localizedDate = null, $crmProbe = null)
    {
        $this->localizedDate = $localizedDate;
        $this->crmProbe = $crmProbe;
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
     * isto". Como a paginação do WHMCS é ANTERIOR ao filtro, esta tool avança
     * páginas automaticamente (até 20 chamadas, sondas em lotes de 200) até
     * completar `limitnum` linhas reais ou esgotar `totalresults`;
     * `pages_scanned` só aparece quando mais de 1 chamada foi necessária.
     * `filtered_out` soma o ruído de TODAS as páginas varridas, não só a
     * primeira. Se o teto bater antes de reunir `limitnum` linhas E ainda
     * houver log pela frente, `scan_capped: true` avisa que o resultado é
     * PARCIAL — não é o fim do log. `totalresults` continua vindo cru do
     * WHMCS (inclui o ruído).
     *
     * @param string $date Filtro de data. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY não são aceitos por serem ambíguos.
     * @param int $limitnum Linhas ÚTEIS desejadas (a paginação do WHMCS é anterior ao filtro; pages_scanned/scan_capped mostram o custo real da varredura).
     */
    #[McpTool(name: 'whmcs_get_activity_log', description: 'Obtém log de atividades do sistema. Por padrão descarta as linhas de "Hooks Debug" (ruído de depuração), avançando páginas automaticamente até reunir limitnum linhas reais; filtered_out soma o ruído de todas as páginas varridas; scan_capped:true avisa quando o teto de chamadas bateu antes de encontrar linhas suficientes. Leituras deste log não são auditadas no próprio log.')]
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
            $filteredOut = ResponseRedactor::filterActivityLogNoise($result);
            $kept = $result['activity']['entry'] ?? [];
            $total = (int) ($result['totalresults'] ?? 0);
            $nextStart = $limitstart + $limitnum;
            $calls = 1;

            // A paginação do WHMCS acontece ANTES do filtro: uma página cheia
            // de "Hooks Debug" volta vazia mesmo havendo linhas reais mais
            // adiante. Medido no desenv: log com 729.249 linhas, quase todas
            // ruído — 20 chamadas de 25 linhas (500 no total) só encontraram
            // 1 linha real. As sondas (2ª chamada em diante) pedem um lote
            // maior que `limitnum` para cobrir mais log por chamada; a
            // PRIMEIRA chamada preserva `limitnum`/`limitstart` do chamador.
            $probeBatch = max($limitnum, 200);

            while (count($kept) < $limitnum && $calls < 20 && $nextStart < $total) {
                $pageParams = $params;
                $pageParams['limitnum'] = $probeBatch;
                $pageParams['limitstart'] = $nextStart;
                $page = $this->api->call('GetActivityLog', $pageParams);
                if (($page['result'] ?? null) === 'error') {
                    break;
                }
                $filteredOut += ResponseRedactor::filterActivityLogNoise($page);
                foreach (($page['activity']['entry'] ?? []) as $entry) {
                    $kept[] = $entry;
                    if (count($kept) >= $limitnum) {
                        break;
                    }
                }
                $calls++;
                $nextStart += $probeBatch;
            }

            $result['activity']['entry'] = $kept;
            $result['filtered_out'] = $filteredOut;
            if ($calls > 1) {
                $result['pages_scanned'] = $calls;
            }
            // Parou no teto de chamadas com ruído ainda pela frente: não é o
            // fim do log, é o scan batendo no limite de segurança. O
            // chamador precisa saber pra não achar que "acabaram as linhas".
            if (count($kept) < $limitnum && $nextStart < $total) {
                $result['scan_capped'] = true;
            }
        }
        return ToolJson::encode($result);
    }

    #[McpTool(name: 'whmcs_get_admin_details', description: 'Obtém detalhes do administrador autenticado')]
    public function getAdminDetails(): string
    {
        $result = $this->api->call('GetAdminDetails', []);

        // Adiciona capabilidades disponíveis
        $result['capabilities'] = [
            'crm' => $this->getCrmCapability(),
        ];

        return ToolJson::encode($result);
    }

    private function getCrmCapability(): string
    {
        if ($this->crmProbe === null) {
            return 'unknown';
        }

        try {
            $available = ($this->crmProbe)();
            return $available ? 'available' : 'unavailable';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }

    #[McpTool(name: 'whmcs_get_todo_items', description: 'Lista itens de tarefas administrativas (To-Do)')]
    public function getToDoItems(string $status = '', int $limitstart = 0, int $limitnum = 25): string
    {
        $params = ['limitstart' => $limitstart, 'limitnum' => $limitnum];
        if ($status !== '') $params['status'] = $status;
        return ToolJson::encode($this->api->call('GetToDoItems', $params));
    }

    /**
     * `duedate` aceita `Y-m-d` ou ISO-8601 — ver `DateNormalizer`. Formato
     * localizado é rejeitado (mesma regra de ProjectManagerTools).
     */
    #[McpTool(name: 'whmcs_update_todo_item', description: 'Atualiza um item To-Do existente (status, título, descrição e duedate). `duedate` aceita YYYY-MM-DD ou ISO-8601 date-time; formato localizado é rejeitado.')]
    public function updateToDoItem(int $itemid, string $status = '', string $title = '', string $description = '', int $adminid = 0, string $duedate = ''): string
    {
        $params = ['itemid' => $itemid];
        if ($status !== '') $params['status'] = $status;
        if ($title !== '') $params['title'] = $title;
        if ($description !== '') $params['description'] = $description;
        if ($adminid > 0) $params['adminid'] = $adminid;
        if ($duedate !== '') $params['duedate'] = DateNormalizer::toWhmcsDate($duedate, 'duedate');
        return ToolJson::encode($this->api->call('UpdateToDoItem', $params));
    }

    #[McpTool(name: 'whmcs_get_currencies', description: 'Lista as moedas configuradas no WHMCS')]
    public function getCurrencies(): string
    {
        return ToolJson::encode($this->api->call('GetCurrencies', []));
    }
}
