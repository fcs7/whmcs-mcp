<?php
// src/Tools/TranslationTools.php
namespace NtMcp\Tools;

use NtMcp\Translation\EmailTemplateRepository;
use NtMcp\Translation\TranslationBackup;
use NtMcp\Translation\TranslationGuard;
use NtMcp\Translation\TranslationStatusReader;
use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\AuditMetadata;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ToolJson;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Tradução em massa de conteúdo dinâmico do WHMCS (Fase 1: templates de
 * e-mail). O Claude, do lado do cliente MCP, é quem traduz — o addon nunca
 * chama IA; ele só lê o master, valida a paridade estrutural (tags Smarty e
 * HTML) do texto que o chamador devolve e grava a variante EN.
 *
 * Assim como `ChipTools`, este domínio NÃO passa pelo `LocalApiClient`: não
 * existe comando LocalAPI de escrita para `tblemailtemplates`, então a
 * autorização vem de `TranslationGuard` (mesmas flags de gate) e o acesso a
 * dado é via `EmailTemplateRepository`, único ponto que toca a tabela.
 *
 * IMPORTANTE para quem traduzir com esta tool: preservar toda tag Smarty
 * (`{...}`) e toda tag HTML tal como está no PT; NÃO traduzir as marcas
 * NT-Fiber, NT-Cloud, NTHOSTING, NT-Fone, NT-Móvel, BackupOn, Nextcloud,
 * Proxmox, KVM, LXC, SLA, CPE e VLAN; e NÃO prometer atendimento 24x7/24/7 nem
 * certificações que o texto original não afirme. Fluxo recomendado: get →
 * set(confirm=false) para conferir o diff → set(confirm=true) reusando o
 * expected_hash devolvido pelo get.
 */
class TranslationTools
{
    private EmailTemplateRepository $emails;
    private TranslationGuard $guard;
    private TranslationBackup $backup;
    private TranslationStatusReader $statusReader;

    public function __construct(
        ?EmailTemplateRepository $emails = null,
        ?TranslationGuard $guard = null,
        ?TranslationBackup $backup = null,
        ?TranslationStatusReader $statusReader = null
    ) {
        $this->emails = $emails ?? new EmailTemplateRepository();
        $this->guard = $guard ?? new TranslationGuard();
        $this->backup = $backup ?? new TranslationBackup();
        $this->statusReader = $statusReader ?? new TranslationStatusReader();
    }

    #[McpTool(
        name: 'whmcs_translation_status',
        description: 'Panorama de tradução de templates de e-mail: contagem por idioma em tblemailtemplates '
            . '(excluindo type=admin), ate 3 subjects de amostra dos masters, contagem de clientes por '
            . 'tblclients.language e se "Enable Dynamic Translations" esta ligado no WHMCS (unknown quando nao '
            . 'for possivel ler). Informa source_language, target_language padrao e '
            . 'supported_target_languages (idiomas-alvo aceitos pelas demais tools desta fase). O master '
            . '(idioma fonte, language=\'\') NAO tem idioma fixo: no desenv, a maioria dos masters ja esta em '
            . 'ingles (templates padrao do WHMCS) e uma minoria esta em portugues (customizados).'
    )]
    #[Schema(additionalProperties: false)]
    public function status(): string|CallToolResult
    {
        $summary = $this->emails->statusSummary();
        if (($summary['result'] ?? null) === 'error') {
            return self::error(
                (string) ($summary['error_code'] ?? 'downstream'),
                (string) ($summary['message'] ?? ''),
                $summary
            );
        }

        $summary['client_languages'] = $this->statusReader->clientLanguageCounts();
        $summary['dynamic_translations_enabled'] = $this->statusReader->dynamicTranslationsEnabled();
        $summary['dynamic_translations'] = $this->statusReader->dynamicTranslationCounts();

        return ToolJson::encode($summary);
    }

    #[McpTool(
        name: 'whmcs_translation_email_list',
        description: 'Lista templates de e-mail MASTER (idioma fonte, language=\'\', type<>admin) — o master NAO '
            . 'tem idioma fixo: pode estar em ingles (templates padrao do WHMCS) ou em portugues (customizados). '
            . 'Cada item traz variants (idiomas que ja tem linha irmã para aquele name) e has_target (se '
            . 'target_language ja esta em variants). target_language escolhe o idioma-alvo: use \'portuguese-br\' '
            . 'para traduzir os masters padrao (em ingles) e \'english\' para os masters customizados (em '
            . 'portugues); nao traduza um master que ja esteja no idioma-alvo. only_missing=true (padrao) mostra '
            . 'so os que ainda faltam traduzir para target_language. Filtro opcional type. limit ate 100.'
    )]
    #[Schema(additionalProperties: false)]
    public function emailList(
        string $type = '',
        bool $only_missing = true,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(minimum: 0)] int $offset = 0,
        string $target_language = EmailTemplateRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        $result = $this->emails->listMasters($type === '' ? null : $type, $only_missing, $limit, $offset, $target_language);

        if (($result['result'] ?? null) === 'error') {
            return self::error(
                (string) ($result['error_code'] ?? 'downstream'),
                (string) ($result['message'] ?? ''),
                $result
            );
        }

        return ToolJson::encode($result);
    }

    #[McpTool(
        name: 'whmcs_translation_email_get',
        description: 'Obtem ate 10 templates de e-mail MASTER completos (subject/message) — o master pode estar '
            . 'em ingles ou em portugues, conforme o template — mais a variante atual no idioma target_language '
            . '(ou null, se ainda nao existir) e o target_hash correspondente (\'absent\' quando nao existe). '
            . 'Nao traduza um master que ja esteja no idioma-alvo. Use o target_hash retornado como expected_hash '
            . 'em whmcs_translation_email_set para evitar sobrescrever uma edicao concorrente. Preserve tags '
            . 'Smarty {...} e tags HTML tal como estao no master; nao traduza marcas de produto (NT-Fiber, '
            . 'NT-Cloud, NTHOSTING, NT-Fone, NT-Movel, BackupOn, Nextcloud, Proxmox, KVM, LXC, SLA, CPE, VLAN); '
            . 'nao prometa 24x7/24-7 nem certificacoes que o original nao afirme; nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function emailGet(array $ids, string $target_language = EmailTemplateRepository::DEFAULT_TARGET_LANGUAGE): string|CallToolResult
    {
        $result = $this->emails->getPairs($ids, $target_language);

        if (($result['result'] ?? null) === 'error') {
            return self::error(
                (string) ($result['error_code'] ?? 'downstream'),
                (string) ($result['message'] ?? ''),
                $result
            );
        }

        return ToolJson::encode($result);
    }

    #[McpTool(
        name: 'whmcs_translation_email_set',
        description: 'Grava de 1 a 10 traduções de template de e-mail (subject/message) para target_language '
            . '(\'english\' ou \'portuguese-br\'; padrao \'english\'). Nao traduza um master que ja esteja no '
            . 'idioma-alvo. Cada item exige expected_hash (o target_hash devolvido por whmcs_translation_email_get) '
            . 'para evitar sobrescrever edicao concorrente; hash divergente recusa o LOTE inteiro sem gravar nada. '
            . 'Requer paridade de tags Smarty {...} e de tags HTML entre o master e o texto enviado — divergencia '
            . 'recusa o item. confirm=false (padrao) so valida e devolve um preview (action insert|update, campos '
            . 'alterados, trecho do corpo); nada e gravado e o gate de escrita nao e verificado. confirm=true exige '
            . 'o gate WRITE habilitado e grava de fato, em transacao unica para o lote (tudo ou nada), com backup '
            . 'do estado anterior. Fluxo recomendado: whmcs_translation_email_get -> set(confirm=false) para '
            . 'revisar o diff -> set(confirm=true) reusando o mesmo expected_hash e o mesmo target_language. NAO '
            . 'traduza marcas de produto (NT-Fiber, NT-Cloud, NTHOSTING, NT-Fone, NT-Movel, BackupOn, Nextcloud, '
            . 'Proxmox, KVM, LXC, SLA, CPE, VLAN); nao prometa 24x7/24-7 nem certificacoes que o original nao '
            . 'afirme; nao use emoji.'
    )]
    #[Schema(additionalProperties: false)]
    public function emailSet(
        array $items,
        bool $confirm = false,
        string $target_language = EmailTemplateRepository::DEFAULT_TARGET_LANGUAGE
    ): string|CallToolResult {
        if (count($items) < 1 || count($items) > 10) {
            return self::error('invalid_items', 'items deve conter de 1 a 10 elementos.');
        }

        if ($confirm === true) {
            $this->guard->assertWriteAllowed('whmcs_translation_email_set');
        }

        $result = $this->emails->applyBatch($items, $this->backup, !$confirm, $target_language);

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
                    command: 'whmcs_translation_email_set'
                );
            }
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
