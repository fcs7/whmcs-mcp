<?php
// src/Tools/TranslationCatalogTools.php
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
 * Tradução em massa de conteúdo dinâmico do WHMCS (Fase 2: produto e grupo de
 * produto, via `tbldynamic_translations`). Mesmo desenho de `TranslationTools`
 * (Fase 1: e-mail) — o Claude, do lado do cliente MCP, é quem traduz; este
 * addon só lê o texto-fonte PT, valida paridade estrutural (tags Smarty e
 * HTML, quando presentes) e grava a variante EN.
 *
 * Não passa pelo `LocalApiClient`: não existe comando WHMCS de escrita para
 * `tbldynamic_translations`. Autorização via `TranslationGuard` (mesmas flags
 * de gate da Fase 1) e acesso a dado via `DynamicTranslationRepository`, único
 * ponto que toca a tabela.
 *
 * Pré-requisito: "Enable Dynamic Translations" precisa estar LIGADO no WHMCS
 * (`whmcs_translation_status` informa o estado atual). Fluxo recomendado:
 * list/get → set(confirm=false) para conferir o diff → set(confirm=true)
 * reusando o expected_hash devolvido.
 *
 * IMPORTANTE para quem traduzir: preservar toda tag Smarty (`{...}`) e toda
 * tag HTML tal como está no PT; NÃO traduzir as marcas NT-Fiber, NT-Cloud,
 * NTHOSTING, NT-Fone, NT-Móvel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA,
 * CPE e VLAN; e NÃO prometer atendimento 24x7/24/7 nem certificações que o
 * texto original não afirme.
 */
class TranslationCatalogTools
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
        name: 'whmcs_translation_product_list',
        description: 'Lista produtos (tblproducts) com o texto-fonte PT por campo (name, description, tagline, '
            . 'short_description — description vem TRUNCADA a 200 caracteres nesta listagem; use '
            . 'whmcs_translation_product_get para o texto completo; tagline e short_description sao curtos, sem '
            . 'truncamento), has_target e target_hash (\'absent\' quando a tradução ainda nao existe) por campo, para '
            . 'target_language (somente \'english\' nesta fase). gid=0 (padrao) lista todos os grupos; gid>0 filtra '
            . 'por grupo. only_missing=true (padrao) mostra so produtos com pelo menos um campo de texto nao vazio '
            . 'ainda sem traducao. Requer "Enable Dynamic Translations" ligado no WHMCS (ver whmcs_translation_status). '
            . 'limit ate 100.'
    )]
    #[Schema(additionalProperties: false)]
    public function productList(
        #[Schema(minimum: 0)] int $gid = 0,
        bool $only_missing = true,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(minimum: 0)] int $offset = 0,
        string $target_language = DynamicTranslationRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return self::toolResult($this->dynamic->listEntities(
            DynamicTranslationMap::KIND_PRODUCT,
            $gid,
            $only_missing,
            $limit,
            $offset,
            $target_language
        ));
    }

    #[McpTool(
        name: 'whmcs_translation_product_get',
        description: 'Obtem ate 10 produtos (tblproducts) com o texto-fonte PT COMPLETO por campo (name, '
            . 'description, tagline, short_description) mais a traducao atual em target_language (ou null) e o '
            . 'target_hash correspondente '
            . '(\'absent\' quando nao existe). Use o target_hash retornado como expected_hash em '
            . 'whmcs_translation_product_set para evitar sobrescrever uma edicao concorrente. Preserve tags HTML de '
            . 'description tal como estao no PT; nao traduza marcas de produto (NT-Fiber, NT-Cloud, NTHOSTING, '
            . 'NT-Fone, NT-Movel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA, CPE, VLAN); nao prometa 24x7/24-7 nem '
            . 'certificacoes que o original nao afirme; nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function productGet(
        array $ids,
        string $target_language = DynamicTranslationRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return self::toolResult($this->dynamic->getEntities(DynamicTranslationMap::KIND_PRODUCT, $ids, $target_language));
    }

    #[McpTool(
        name: 'whmcs_translation_product_set',
        description: 'Grava de 1 a 20 traducoes de campo de produto (name, description, tagline ou '
            . 'short_description) para target_language (somente \'english\' nesta fase). Cada item exige id, field, '
            . 'text e expected_hash (o target_hash devolvido por whmcs_translation_product_get/_list); hash '
            . 'divergente recusa apenas o item, mas o LOTE inteiro nao e gravado (transacao unica, tudo ou nada). '
            . 'Requer paridade de tags HTML em description; name/tagline/short_description tem limite de 255 '
            . 'caracteres. confirm=false (padrao) so valida e devolve um preview (action '
            . 'insert|update, trecho antes/depois); nada e gravado e o gate de escrita nao e verificado. confirm=true '
            . 'exige o gate WRITE habilitado e grava de fato, com backup do estado anterior. Fluxo recomendado: '
            . 'whmcs_translation_product_get -> set(confirm=false) para revisar o diff -> set(confirm=true) reusando '
            . 'o mesmo expected_hash. NAO traduza marcas de produto (NT-Fiber, NT-Cloud, NTHOSTING, NT-Fone, '
            . 'NT-Movel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA, CPE, VLAN); nao prometa 24x7/24-7 nem '
            . 'certificacoes que o original nao afirme; nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function productSet(
        array $items,
        bool $confirm = false,
        string $target_language = DynamicTranslationRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return $this->applySet(DynamicTranslationMap::KIND_PRODUCT, $items, $confirm, $target_language, 'whmcs_translation_product_set');
    }

    #[McpTool(
        name: 'whmcs_translation_product_group_list',
        description: 'Lista grupos de produto (tblproductgroups) com o texto-fonte PT COMPLETO por campo (name, '
            . 'headline, tagline — todos curtos, sem truncamento), has_target e target_hash (\'absent\' quando a '
            . 'traducao ainda nao existe) por campo, para target_language (somente \'english\' nesta fase). '
            . 'only_missing=true (padrao) mostra so grupos com pelo menos um campo de texto nao vazio ainda sem '
            . 'traducao. Requer "Enable Dynamic Translations" ligado no WHMCS (ver whmcs_translation_status). limit '
            . 'ate 100.'
    )]
    #[Schema(additionalProperties: false)]
    public function productGroupList(
        bool $only_missing = true,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 50,
        #[Schema(minimum: 0)] int $offset = 0,
        string $target_language = DynamicTranslationRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return self::toolResult($this->dynamic->listEntities(
            DynamicTranslationMap::KIND_PRODUCT_GROUP,
            null,
            $only_missing,
            $limit,
            $offset,
            $target_language
        ));
    }

    #[McpTool(
        name: 'whmcs_translation_product_group_set',
        description: 'Grava de 1 a 20 traducoes de campo de grupo de produto (name, headline ou tagline) para '
            . 'target_language (somente \'english\' nesta fase). Cada item exige id, field, text e expected_hash (o '
            . 'target_hash devolvido por whmcs_translation_product_group_list); hash divergente recusa apenas o '
            . 'item, mas o LOTE inteiro nao e gravado (transacao unica, tudo ou nada). Todos os campos tem limite de '
            . '255 caracteres. confirm=false (padrao) so valida e devolve um preview (action insert|update, trecho '
            . 'antes/depois); nada e gravado e o gate de escrita nao e verificado. confirm=true exige o gate WRITE '
            . 'habilitado e grava de fato, com backup do estado anterior. Fluxo recomendado: '
            . 'whmcs_translation_product_group_list -> set(confirm=false) para revisar o diff -> set(confirm=true) '
            . 'reusando o mesmo expected_hash. NAO traduza marcas de produto (NT-Fiber, NT-Cloud, NTHOSTING, '
            . 'NT-Fone, NT-Movel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA, CPE, VLAN); nao prometa 24x7/24-7 nem '
            . 'certificacoes que o original nao afirme; nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function productGroupSet(
        array $items,
        bool $confirm = false,
        string $target_language = DynamicTranslationRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return $this->applySet(DynamicTranslationMap::KIND_PRODUCT_GROUP, $items, $confirm, $target_language, 'whmcs_translation_product_group_set');
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
