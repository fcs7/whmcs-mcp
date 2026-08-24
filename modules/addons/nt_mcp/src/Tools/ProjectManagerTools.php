<?php
// src/Tools/ProjectManagerTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\DateNormalizer;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ToolJson;
use Mcp\Capability\Attribute\McpTool;

class ProjectManagerTools
{
    public function __construct(private readonly LocalApiClient $api) {}

    #[McpTool(name: 'whmcs_list_projects', description: 'Lista projetos com filtros opcionais')]
    public function listProjects(
        int $userid = 0,
        string $status = '',
        ?bool $completed = null,
        int $limitnum = 25,
        int $limitstart = 0
    ): string {
        $params = ['limitnum' => $limitnum, 'limitstart' => $limitstart];
        if ($userid > 0) $params['userid'] = $userid;
        if ($status !== '') $params['status'] = $status;
        if ($completed !== null) $params['completed'] = $completed;
        return ToolJson::encode($this->api->call('GetProjects', $params));
    }

    #[McpTool(name: 'whmcs_get_project', description: 'Obtém detalhes completos de um projeto incluindo tarefas')]
    public function getProject(int $projectid): string
    {
        return ToolJson::encode($this->api->call('GetProject', ['projectid' => $projectid]));
    }

    /**
     * @param string $duedate Prazo do projeto. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY nao sao aceitos por serem ambiguos.
     */
    #[McpTool(name: 'whmcs_create_project', description: 'Cria um novo projeto no WHMCS Project Manager')]
    public function createProject(
        string $title,
        int $adminid,
        int $userid = 0,
        string $status = '',
        string $duedate = '',
        string $notes = '',
        string $ticketids = ''
    ): string {
        $params = ['title' => $title, 'adminid' => $adminid];
        if ($userid > 0) $params['userid'] = $userid;
        if ($status !== '') $params['status'] = $status;
        if ($duedate !== '') $params['duedate'] = DateNormalizer::toWhmcsDate($duedate, 'duedate');
        if ($notes !== '') $params['notes'] = $notes;
        if ($ticketids !== '') $params['ticketids'] = $ticketids;
        return ToolJson::encode($this->api->call('CreateProject', $params));
    }

    /**
     * @param string $duedate Prazo do projeto. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY nao sao aceitos por serem ambiguos.
     */
    #[McpTool(name: 'whmcs_update_project', description: 'Atualiza um projeto existente')]
    public function updateProject(
        int $projectid,
        string $title = '',
        string $status = '',
        string $duedate = '',
        ?bool $completed = null
    ): string {
        $params = ['projectid' => $projectid];
        if ($title !== '') $params['title'] = $title;
        if ($status !== '') $params['status'] = $status;
        if ($duedate !== '') $params['duedate'] = DateNormalizer::toWhmcsDate($duedate, 'duedate');
        if ($completed !== null) $params['completed'] = $completed ? 1 : 0;
        return ToolJson::encode($this->api->call('UpdateProject', $params));
    }

    /**
     * @param string $duedate Prazo da tarefa. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY nao sao aceitos por serem ambiguos.
     */
    #[McpTool(name: 'whmcs_add_project_task', description: 'Adiciona uma tarefa a um projeto')]
    public function addProjectTask(
        int $projectid,
        string $task,
        string $duedate,
        int $adminid,
        bool $completed = false
    ): string {
        $params = compact('projectid', 'task', 'adminid');
        $params['duedate'] = DateNormalizer::toWhmcsDate($duedate, 'duedate');
        if ($completed) $params['completed'] = true;
        return ToolJson::encode($this->api->call('AddProjectTask', $params));
    }

    /**
     * @param string $duedate Prazo da tarefa. Aceita YYYY-MM-DD ou ISO-8601 date-time (ex.: 2026-08-10 ou 2026-08-10T00:00:00Z). Formatos localizados como DD/MM/YYYY nao sao aceitos por serem ambiguos.
     */
    #[McpTool(name: 'whmcs_update_project_task', description: 'Atualiza uma tarefa de projeto')]
    public function updateProjectTask(
        int $projectid,
        int $taskid,
        string $task = '',
        string $duedate = '',
        ?bool $completed = null
    ): string {
        $params = ['projectid' => $projectid, 'taskid' => $taskid];
        if ($task !== '') $params['task'] = $task;
        if ($duedate !== '') $params['duedate'] = DateNormalizer::toWhmcsDate($duedate, 'duedate');
        if ($completed !== null) $params['completed'] = $completed ? 1 : 0;
        return ToolJson::encode($this->api->call('UpdateProjectTask', $params));
    }

    #[McpTool(name: 'whmcs_start_task_timer', description: 'Inicia cronômetro de uma tarefa (time tracking)')]
    public function startTaskTimer(int $projectid, int $taskid, int $adminid): string
    {
        return ToolJson::encode($this->api->call('StartTaskTimer', compact('projectid', 'taskid', 'adminid')));
    }

    #[McpTool(name: 'whmcs_end_task_timer', description: 'Para cronômetro de uma tarefa')]
    public function endTaskTimer(int $projectid, int $taskid, int $adminid): string
    {
        return ToolJson::encode($this->api->call('EndTaskTimer', compact('projectid', 'taskid', 'adminid')));
    }

    #[McpTool(name: 'whmcs_add_project_message', description: 'Adiciona mensagem/comentário a um projeto')]
    public function addProjectMessage(int $projectid, string $message, int $adminid): string
    {
        return ToolJson::encode($this->api->call('AddProjectMessage', compact('projectid', 'message', 'adminid')));
    }
}
