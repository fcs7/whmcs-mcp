<?php
// src/Tools/TranslationContentTools.php
namespace NtMcp\Tools;

use NtMcp\Translation\AnnouncementRepository;
use NtMcp\Translation\KnowledgebaseRepository;
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
 * Tradução em massa de conteúdo dinâmico do WHMCS — Fase 4 (última): base de
 * conhecimento (categoria e artigo) e anúncio. Mesmo desenho das fases
 * anteriores — o Claude, do lado do cliente MCP, é quem traduz; este addon só
 * lê o texto-fonte PT, valida paridade estrutural (tags Smarty e HTML, quando
 * presentes) e grava a variante EN.
 *
 * Modelo de armazenamento (linha-filha, igual `tblemailtemplates`, diferente
 * de `tbldynamic_translations`): a linha original tem `parentid=0`/`catid=0` e
 * `language=''`; a variante é uma linha FILHA, com `parentid=<id>` (anúncio,
 * artigo) ou `catid=<id>` (categoria) e `language=target_language`.
 *
 * DESVIO do plano original (ver
 * `docs/superpowers/specs/2026-09-18-translation-tools-design.md` §2/§3): o
 * plano previa anúncio via LocalAPI (`AddAnnouncement`/`UpdateAnnouncement`).
 * Esses comandos NÃO expõem `parentid`/`language` — não criam variante de
 * idioma. Por isso `whmcs_translation_announcement_set` usa Capsule direto,
 * igual KB e igual às fases anteriores.
 *
 * Não passa pelo `LocalApiClient`. Autorização via `TranslationGuard` (mesmas
 * flags de gate das demais fases); acesso a dado via `AnnouncementRepository`
 * e `KnowledgebaseRepository`, únicos pontos que tocam essas tabelas.
 *
 * IMPORTANTE para quem traduzir: preservar toda tag Smarty (`{...}`) e toda
 * tag HTML tal como está no PT; NÃO traduzir as marcas NT-Fiber, NT-Cloud,
 * NTHOSTING, NT-Fone, NT-Móvel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA,
 * CPE e VLAN; e NÃO prometer atendimento 24x7/24/7 nem certificações que o
 * texto original não afirme.
 */
class TranslationContentTools
{
    private KnowledgebaseRepository $kb;
    private AnnouncementRepository $announcements;
    private TranslationGuard $guard;
    private TranslationBackup $backup;

    public function __construct(
        ?KnowledgebaseRepository $kb = null,
        ?AnnouncementRepository $announcements = null,
        ?TranslationGuard $guard = null,
        ?TranslationBackup $backup = null
    ) {
        $this->kb = $kb ?? new KnowledgebaseRepository();
        $this->announcements = $announcements ?? new AnnouncementRepository();
        $this->guard = $guard ?? new TranslationGuard();
        $this->backup = $backup ?? new TranslationBackup();
    }

    // -----------------------------------------------------------
    // Categoria de KB
    // -----------------------------------------------------------

    #[McpTool(
        name: 'whmcs_translation_kb_category_list',
        description: 'Lista categorias de base de conhecimento (tblknowledgebasecats) originais (catid=0, '
            . 'language=\'\') com name/description PT COMPLETOS (curtos, sem truncamento), a variante em '
            . 'target_language (ou null) e o target_hash correspondente (\'absent\' quando nao existe). Sem tool de '
            . '"get" separada: o texto cabe na listagem. only_missing=true (padrao) mostra so categorias ainda sem '
            . 'traducao para target_language (somente \'english\' nesta fase). limit ate 100.'
    )]
    #[Schema(additionalProperties: false)]
    public function kbCategoryList(
        bool $only_missing = true,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 50,
        #[Schema(minimum: 0)] int $offset = 0,
        string $target_language = KnowledgebaseRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return self::toolResult($this->kb->listCategories($only_missing, $limit, $offset, $target_language));
    }

    #[McpTool(
        name: 'whmcs_translation_kb_category_set',
        description: 'Grava de 1 a 20 traducoes de categoria de KB (name e description) para target_language '
            . '(somente \'english\' nesta fase). Cada item exige id, name, description e expected_hash (o '
            . 'target_hash devolvido por whmcs_translation_kb_category_list); hash divergente recusa o LOTE inteiro '
            . 'sem gravar nada (transacao unica, tudo ou nada). name tem limite de 255 caracteres. A variante nova '
            . 'copia parentid e hidden da categoria original. confirm=false (padrao) so valida e devolve um preview '
            . '(action insert|update, trecho antes/depois); nada e gravado e o gate de escrita nao e verificado. '
            . 'confirm=true exige o gate WRITE habilitado e grava de fato, com backup do estado anterior. Fluxo '
            . 'recomendado: whmcs_translation_kb_category_list -> set(confirm=false) para revisar o diff -> '
            . 'set(confirm=true) reusando o mesmo expected_hash. NAO traduza marcas de produto (NT-Fiber, NT-Cloud, '
            . 'NTHOSTING, NT-Fone, NT-Movel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA, CPE, VLAN); nao prometa '
            . '24x7/24-7 nem certificacoes que o original nao afirme; nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function kbCategorySet(
        array $items,
        bool $confirm = false,
        string $target_language = KnowledgebaseRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        if (count($items) < 1 || count($items) > 20) {
            return self::error('invalid_items', 'items deve conter de 1 a 20 elementos.');
        }

        if ($confirm === true) {
            $this->guard->assertWriteAllowed('whmcs_translation_kb_category_set');
        }

        $result = $this->kb->applyCategoryBatch($items, $this->backup, !$confirm, $target_language);

        return self::afterApply($result, $confirm, 'whmcs_translation_kb_category_set');
    }

    // -----------------------------------------------------------
    // Artigo de KB
    // -----------------------------------------------------------

    #[McpTool(
        name: 'whmcs_translation_kb_article_list',
        description: 'Lista artigos de base de conhecimento (tblknowledgebase) originais (parentid=0, language=\'\') '
            . 'com title, um excerto de 200 caracteres de article, private e has_target para target_language (somente '
            . '\'english\' nesta fase). Use whmcs_translation_kb_article_get para o texto completo. only_missing=true '
            . '(padrao) mostra so artigos ainda sem traducao. limit ate 100.'
    )]
    #[Schema(additionalProperties: false)]
    public function kbArticleList(
        bool $only_missing = true,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(minimum: 0)] int $offset = 0,
        string $target_language = KnowledgebaseRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return self::toolResult($this->kb->listArticles($only_missing, $limit, $offset, $target_language));
    }

    #[McpTool(
        name: 'whmcs_translation_kb_article_get',
        description: 'Obtem ate 10 artigos de KB (tblknowledgebase) originais COMPLETOS (title/article) mais a '
            . 'variante atual em target_language (ou null) e o target_hash correspondente (\'absent\' quando nao '
            . 'existe). Use o target_hash retornado como expected_hash em whmcs_translation_kb_article_set. Preserve '
            . 'tags HTML tal como estao no original; nao traduza marcas de produto (NT-Fiber, NT-Cloud, NTHOSTING, '
            . 'NT-Fone, NT-Movel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA, CPE, VLAN); nao prometa 24x7/24-7 nem '
            . 'certificacoes que o original nao afirme; nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function kbArticleGet(
        array $ids,
        string $target_language = KnowledgebaseRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return self::toolResult($this->kb->getArticles($ids, $target_language));
    }

    #[McpTool(
        name: 'whmcs_translation_kb_article_set',
        description: 'Grava de 1 a 10 traducoes de artigo de KB (title/article) para target_language (somente '
            . '\'english\' nesta fase). Cada item exige id, title, article e expected_hash (o target_hash devolvido '
            . 'por whmcs_translation_kb_article_get); hash divergente recusa o LOTE inteiro sem gravar nada '
            . '(transacao unica, tudo ou nada). Requer paridade de tags HTML; title tem limite de 255 caracteres. A '
            . 'variante nova copia private e order do artigo original; views/votes/useful comecam zerados. '
            . 'confirm=false (padrao) so valida e devolve um preview (action insert|update, trecho antes/depois); '
            . 'nada e gravado e o gate de escrita nao e verificado. confirm=true exige o gate WRITE habilitado e '
            . 'grava de fato, com backup do estado anterior. Fluxo recomendado: whmcs_translation_kb_article_get -> '
            . 'set(confirm=false) para revisar o diff -> set(confirm=true) reusando o mesmo expected_hash. NAO '
            . 'traduza marcas de produto (NT-Fiber, NT-Cloud, NTHOSTING, NT-Fone, NT-Movel, BackupOn, Nextcloud, '
            . 'Proxmox, KVM, LXC, SLA, CPE, VLAN); nao prometa 24x7/24-7 nem certificacoes que o original nao afirme; '
            . 'nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function kbArticleSet(
        array $items,
        bool $confirm = false,
        string $target_language = KnowledgebaseRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        if (count($items) < 1 || count($items) > 10) {
            return self::error('invalid_items', 'items deve conter de 1 a 10 elementos.');
        }

        if ($confirm === true) {
            $this->guard->assertWriteAllowed('whmcs_translation_kb_article_set');
        }

        $result = $this->kb->applyArticleBatch($items, $this->backup, !$confirm, $target_language);

        return self::afterApply($result, $confirm, 'whmcs_translation_kb_article_set');
    }

    // -----------------------------------------------------------
    // Anúncio
    // -----------------------------------------------------------

    #[McpTool(
        name: 'whmcs_translation_announcement_list',
        description: 'Lista anuncios (tblannouncements) originais (parentid=0, language=\'\') com title, date, '
            . 'published, um excerto de 200 caracteres de announcement e has_target para target_language (somente '
            . '\'english\' nesta fase). Use whmcs_translation_announcement_get para o texto completo. '
            . 'only_missing=true (padrao) mostra so anuncios ainda sem traducao. limit ate 100.'
    )]
    #[Schema(additionalProperties: false)]
    public function announcementList(
        bool $only_missing = true,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(minimum: 0)] int $offset = 0,
        string $target_language = AnnouncementRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return self::toolResult($this->announcements->listAnnouncements($only_missing, $limit, $offset, $target_language));
    }

    #[McpTool(
        name: 'whmcs_translation_announcement_get',
        description: 'Obtem ate 10 anuncios (tblannouncements) originais COMPLETOS (title/announcement/date/'
            . 'published) mais a variante atual em target_language (ou null) e o target_hash correspondente '
            . '(\'absent\' quando nao existe). Use o target_hash retornado como expected_hash em '
            . 'whmcs_translation_announcement_set. Preserve tags HTML tal como estao no original; nao traduza marcas '
            . 'de produto (NT-Fiber, NT-Cloud, NTHOSTING, NT-Fone, NT-Movel, BackupOn, Nextcloud, Proxmox, KVM, LXC, '
            . 'SLA, CPE, VLAN); nao prometa 24x7/24-7 nem certificacoes que o original nao afirme; nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function announcementGet(
        array $ids,
        string $target_language = AnnouncementRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        return self::toolResult($this->announcements->getAnnouncements($ids, $target_language));
    }

    #[McpTool(
        name: 'whmcs_translation_announcement_set',
        description: 'Grava de 1 a 10 traducoes de anuncio (title/announcement) para target_language (somente '
            . '\'english\' nesta fase). Cada item exige id, title, announcement e expected_hash (o target_hash '
            . 'devolvido por whmcs_translation_announcement_get); hash divergente recusa o LOTE inteiro sem gravar '
            . 'nada (transacao unica, tudo ou nada). Requer paridade de tags HTML; title tem limite de 255 '
            . 'caracteres. A variante nova copia date e published do anuncio original. Escreve via Capsule, nao via '
            . 'LocalAPI: AddAnnouncement/UpdateAnnouncement nao expoem parentid/language, entao nao criam variante '
            . 'de idioma. confirm=false (padrao) so valida e devolve um preview (action insert|update, trecho antes/'
            . 'depois); nada e gravado e o gate de escrita nao e verificado. confirm=true exige o gate WRITE '
            . 'habilitado e grava de fato, com backup do estado anterior. Fluxo recomendado: '
            . 'whmcs_translation_announcement_get -> set(confirm=false) para revisar o diff -> set(confirm=true) '
            . 'reusando o mesmo expected_hash. NAO traduza marcas de produto (NT-Fiber, NT-Cloud, NTHOSTING, NT-Fone, '
            . 'NT-Movel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA, CPE, VLAN); nao prometa 24x7/24-7 nem '
            . 'certificacoes que o original nao afirme; nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function announcementSet(
        array $items,
        bool $confirm = false,
        string $target_language = AnnouncementRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        if (count($items) < 1 || count($items) > 10) {
            return self::error('invalid_items', 'items deve conter de 1 a 10 elementos.');
        }

        if ($confirm === true) {
            $this->guard->assertWriteAllowed('whmcs_translation_announcement_set');
        }

        $result = $this->announcements->applyBatch($items, $this->backup, !$confirm, $target_language);

        return self::afterApply($result, $confirm, 'whmcs_translation_announcement_set');
    }

    // -----------------------------------------------------------
    // Compartilhado
    // -----------------------------------------------------------

    /** @param array<string, mixed> $result */
    private static function afterApply(array $result, bool $confirm, string $command): string|CallToolResult
    {
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
