<?php
// src/Tools/TranslationCatalogExtrasTools.php
namespace NtMcp\Tools;

use NtMcp\Translation\DynamicTranslationMap;
use NtMcp\Translation\DynamicTranslationRepository;
use NtMcp\Translation\TranslationBackup;
use NtMcp\Translation\TranslationGuard;
use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\AuditMetadata;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ToolJson;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Tradução em massa de conteúdo dinâmico do WHMCS — Fase 3: custom field,
 * addon de produto e departamento de suporte, via `tbldynamic_translations`.
 * Mesmo desenho de `TranslationCatalogTools` (Fase 2: produto e grupo de
 * produto) — separado em classe própria só para não ultrapassar ~400 linhas
 * naquele arquivo; o contrato (schema guard, gates, backup, validação) é
 * idêntico.
 *
 * `custom_field.{id}.name`/`description` e `product_addon.{id}.name`/`description`
 * estão CONFIRMADOS ao vivo no desenv (linhas reais em `tbldynamic_translations`).
 * `ticket_department.{id}.*` segue a MESMA convenção, mas ainda NÃO tem nenhuma
 * linha gravada no banco real (ver `DynamicTranslationMap`). Use
 * `whmcs_translation_status` para conferir os `related_type` já gravados de
 * fato antes de depender deste literal em produção.
 *
 * Não passa pelo `LocalApiClient`: não existe comando WHMCS de escrita para
 * `tbldynamic_translations`. Autorização via `TranslationGuard` (mesmas flags
 * de gate das demais fases) e acesso a dado via `DynamicTranslationRepository`,
 * único ponto que toca a tabela.
 *
 * Pré-requisito: "Enable Dynamic Translations" precisa estar LIGADO no WHMCS
 * (`whmcs_translation_status` informa o estado atual). Fluxo recomendado:
 * list → set(confirm=false) para conferir o diff → set(confirm=true) reusando
 * o expected_hash devolvido.
 *
 * IMPORTANTE para quem traduzir: preservar toda tag Smarty (`{...}`) e toda
 * tag HTML tal como está no PT; NÃO traduzir as marcas NT-Fiber, NT-Cloud,
 * NTHOSTING, NT-Fone, NT-Móvel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA,
 * CPE e VLAN; e NÃO prometer atendimento 24x7/24/7 nem certificações que o
 * texto original não afirme.
 */
class TranslationCatalogExtrasTools
{
    private DynamicTranslationRepository $dynamic;
    private TranslationGuard $guard;
    private TranslationBackup $backup;

    public function __construct(
        ?DynamicTranslationRepository $dynamic = null,
        ?TranslationGuard $guard = null,
        ?TranslationBackup $backup = null
    ) {
        $this->dynamic = $dynamic ?? new DynamicTranslationRepository();
        $this->guard = $guard ?? new TranslationGuard();
        $this->backup = $backup ?? new TranslationBackup();
    }

    #[McpTool(
        name: 'whmcs_translation_custom_field_list',
        description: 'Lista campos personalizados de cliente/produto (tblcustomfields) com o texto-fonte PT por '
            . 'campo (name — le a coluna fieldname; description) mais type e relid, has_target e target_hash '
            . '(\'absent\' quando a traducao ainda nao existe) por campo, para target_language (somente \'english\' '
            . 'nesta fase). Campos admin-only (adminonly preenchido) NUNCA aparecem: nao sao visiveis ao cliente e '
            . 'nao fazem sentido traduzir. type filtra por tipo de campo (ex.: \'text\', \'dropdown\'); vazio (padrao) '
            . 'nao filtra. only_missing=true (padrao) mostra so campos com pelo menos um texto nao vazio ainda sem '
            . 'traducao. Requer "Enable Dynamic Translations" ligado no WHMCS (ver whmcs_translation_status). O literal '
            . 'related_type (custom_field.{id}.name/description) esta CONFIRMADO ao vivo no desenv. limit ate 50.'
    )]
    #[Schema(additionalProperties: false)]
    public function customFieldList(
        string $type = '',
        bool $only_missing = true,
        #[Schema(minimum: 1, maximum: 50)] int $limit = 50,
        #[Schema(minimum: 0)] int $offset = 0,
        string $target_language = DynamicTranslationRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return self::toolResult($this->dynamic->listEntities(
            DynamicTranslationMap::KIND_CUSTOM_FIELD,
            null,
            $only_missing,
            $limit,
            $offset,
            $target_language,
            $type === '' ? null : $type
        ));
    }

    #[McpTool(
        name: 'whmcs_translation_custom_field_set',
        description: 'Grava de 1 a 20 traducoes de campo de custom field (name ou description) para target_language '
            . '(somente \'english\' nesta fase). Cada item exige id, field, text e expected_hash (o target_hash '
            . 'devolvido por whmcs_translation_custom_field_list); hash divergente recusa apenas o item, mas o LOTE '
            . 'inteiro nao e gravado (transacao unica, tudo ou nada). Todos os campos tem limite de 255 caracteres. '
            . 'confirm=false (padrao) so valida e devolve um preview (action insert|update, trecho antes/depois); '
            . 'nada e gravado e o gate de escrita nao e verificado. confirm=true exige o gate WRITE habilitado e grava '
            . 'de fato, com backup do estado anterior. Fluxo recomendado: whmcs_translation_custom_field_list -> '
            . 'set(confirm=false) para revisar o diff -> set(confirm=true) reusando o mesmo expected_hash. NAO '
            . 'traduza marcas de produto (NT-Fiber, NT-Cloud, NTHOSTING, NT-Fone, NT-Movel, BackupOn, Nextcloud, '
            . 'Proxmox, KVM, LXC, SLA, CPE, VLAN); nao prometa 24x7/24-7 nem certificacoes que o original nao afirme; '
            . 'nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function customFieldSet(
        array $items,
        bool $confirm = false,
        string $target_language = DynamicTranslationRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return $this->applySet(DynamicTranslationMap::KIND_CUSTOM_FIELD, $items, $confirm, $target_language, 'whmcs_translation_custom_field_set');
    }

    #[McpTool(
        name: 'whmcs_translation_product_addon_list',
        description: 'Lista addons de produto (tbladdons) com o texto-fonte PT COMPLETO por campo (name, '
            . 'description), has_target e target_hash (\'absent\' quando a traducao ainda nao existe) por campo, '
            . 'para target_language (somente \'english\' nesta fase). only_missing=true (padrao) mostra so addons '
            . 'com pelo menos um campo de texto nao vazio ainda sem traducao. Requer "Enable Dynamic Translations" '
            . 'ligado no WHMCS (ver whmcs_translation_status). O literal related_type '
            . '(product_addon.{id}.name/description) esta CONFIRMADO ao vivo no desenv. limit ate 50.'
    )]
    #[Schema(additionalProperties: false)]
    public function productAddonList(
        bool $only_missing = true,
        #[Schema(minimum: 1, maximum: 50)] int $limit = 50,
        #[Schema(minimum: 0)] int $offset = 0,
        string $target_language = DynamicTranslationRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return self::toolResult($this->dynamic->listEntities(
            DynamicTranslationMap::KIND_PRODUCT_ADDON,
            null,
            $only_missing,
            $limit,
            $offset,
            $target_language
        ));
    }

    #[McpTool(
        name: 'whmcs_translation_product_addon_set',
        description: 'Grava de 1 a 20 traducoes de campo de addon de produto (name ou description) para '
            . 'target_language (somente \'english\' nesta fase). Cada item exige id, field, text e expected_hash (o '
            . 'target_hash devolvido por whmcs_translation_product_addon_list); hash divergente recusa apenas o '
            . 'item, mas o LOTE inteiro nao e gravado (transacao unica, tudo ou nada). Requer paridade de tags HTML '
            . 'em description; name tem limite de 255 caracteres. confirm=false (padrao) so valida e devolve um '
            . 'preview (action insert|update, trecho antes/depois); nada e gravado e o gate de escrita nao e '
            . 'verificado. confirm=true exige o gate WRITE habilitado e grava de fato, com backup do estado anterior. '
            . 'Fluxo recomendado: whmcs_translation_product_addon_list -> set(confirm=false) para revisar o diff -> '
            . 'set(confirm=true) reusando o mesmo expected_hash. NAO traduza marcas de produto (NT-Fiber, NT-Cloud, '
            . 'NTHOSTING, NT-Fone, NT-Movel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA, CPE, VLAN); nao prometa '
            . '24x7/24-7 nem certificacoes que o original nao afirme; nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function productAddonSet(
        array $items,
        bool $confirm = false,
        string $target_language = DynamicTranslationRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return $this->applySet(DynamicTranslationMap::KIND_PRODUCT_ADDON, $items, $confirm, $target_language, 'whmcs_translation_product_addon_set');
    }

    #[McpTool(
        name: 'whmcs_translation_department_list',
        description: 'Lista departamentos de suporte (tblticketdepartments) com o texto-fonte PT COMPLETO por campo '
            . '(name, description), has_target e target_hash (\'absent\' quando a traducao ainda nao existe) por '
            . 'campo, para target_language (somente \'english\' nesta fase). only_missing=true (padrao) mostra so '
            . 'departamentos com pelo menos um campo de texto nao vazio ainda sem traducao. Requer "Enable Dynamic '
            . 'Translations" ligado no WHMCS (ver whmcs_translation_status). PENDENTE de confirmacao ao vivo: o '
            . 'literal related_type (ticket_department.{id}.name/description) segue a convencao de product/'
            . 'product_group mas ainda nao foi confirmado contra o banco real. limit ate 50.'
    )]
    #[Schema(additionalProperties: false)]
    public function departmentList(
        bool $only_missing = true,
        #[Schema(minimum: 1, maximum: 50)] int $limit = 50,
        #[Schema(minimum: 0)] int $offset = 0,
        string $target_language = DynamicTranslationRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return self::toolResult($this->dynamic->listEntities(
            DynamicTranslationMap::KIND_TICKET_DEPARTMENT,
            null,
            $only_missing,
            $limit,
            $offset,
            $target_language
        ));
    }

    #[McpTool(
        name: 'whmcs_translation_department_set',
        description: 'Grava de 1 a 20 traducoes de campo de departamento de suporte (name ou description) para '
            . 'target_language (somente \'english\' nesta fase). Cada item exige id, field, text e expected_hash (o '
            . 'target_hash devolvido por whmcs_translation_department_list); hash divergente recusa apenas o item, '
            . 'mas o LOTE inteiro nao e gravado (transacao unica, tudo ou nada). Todos os campos tem limite de 255 '
            . 'caracteres. confirm=false (padrao) so valida e devolve um preview (action insert|update, trecho '
            . 'antes/depois); nada e gravado e o gate de escrita nao e verificado. confirm=true exige o gate WRITE '
            . 'habilitado e grava de fato, com backup do estado anterior. Fluxo recomendado: '
            . 'whmcs_translation_department_list -> set(confirm=false) para revisar o diff -> set(confirm=true) '
            . 'reusando o mesmo expected_hash. NAO traduza marcas de produto (NT-Fiber, NT-Cloud, NTHOSTING, NT-Fone, '
            . 'NT-Movel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA, CPE, VLAN); nao prometa 24x7/24-7 nem '
            . 'certificacoes que o original nao afirme; nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function departmentSet(
        array $items,
        bool $confirm = false,
        string $target_language = DynamicTranslationRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return $this->applySet(DynamicTranslationMap::KIND_TICKET_DEPARTMENT, $items, $confirm, $target_language, 'whmcs_translation_department_set');
    }

    private function applySet(string $kind, array $items, bool $confirm, string $targetLanguage, string $command): string|CallToolResult
    {
        if (count($items) < 1 || count($items) > 20) {
            return self::error('invalid_items', 'items deve conter de 1 a 20 elementos.');
        }

        if ($confirm === true) {
            $this->guard->assertWriteAllowed($command);
        }

        $result = $this->dynamic->applyBatch($kind, $items, $this->backup, !$confirm, $targetLanguage);

        if (($result['result'] ?? null) === 'error') {
            return self::error(
                (string) ($result['error_code'] ?? 'downstream'),
                (string) ($result['message'] ?? ''),
                $result
            );
        }

        if ($confirm === true) {
            foreach ($result['items'] as $item) {
                $event = ($item['action'] ?? null) === 'insert' ? ActivityEvent::DB_INSERT : ActivityEvent::DB_UPDATE;
                LocalApiClient::auditLog(
                    $event,
                    AuditMetadata::ids(['id' => (int) ($item['id'] ?? 0)]),
                    command: $command
                );
            }
        }

        return ToolJson::encode($result);
    }

    /** @param array<string, mixed> $result */
    private static function toolResult(array $result): string|CallToolResult
    {
        if (($result['result'] ?? null) === 'error') {
            return self::error(
                (string) ($result['error_code'] ?? 'downstream'),
                (string) ($result['message'] ?? ''),
                $result
            );
        }

        return ToolJson::encode($result);
    }

    /** @param array<string, mixed> $extra */
    private static function error(string $code, string $message, array $extra = []): CallToolResult
    {
        unset($extra['result'], $extra['error_code'], $extra['message']);

        return CallToolResult::error([new TextContent(ToolJson::encode([
            'result' => 'error',
            'error_code' => $code,
            'message' => $message,
        ] + $extra))]);
    }
}
