<?php

declare(strict_types=1);

namespace NtMcp\Mcp;

use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Registry;

/**
 * Fronteira MCP das QUATRO leituras de CRM — e de mais nada.
 *
 * A revisão fria do CRM-2 reprovou dois comportamentos que nascem da SDK, não
 * do nosso código, e que por isso não podiam ser corrigidos dentro da tool:
 *
 *  1. **Falha vira sucesso.** `Processor::handleToolCall()` só marca
 *     `isError: true` quando a tool LANÇA. Uma tool que devolve normalmente um
 *     envelope `result: error` sai com `isError: false`, e a automação do outro
 *     lado confirma uma leitura que falhou.
 *
 *  2. **Argumento desconhecido é ignorado.** O `SchemaGenerator` publica o
 *     schema sem `additionalProperties`, então `{"type": "lead"}` passa pela
 *     validação, some no `ArgumentPreparer` e a leitura devolve a base inteira
 *     como se nenhum filtro tivesse sido pedido.
 *
 * Deixar a tool LANÇAR resolveria (1) e criaria coisa pior: o formatter da SDK
 * monta o texto com `'Tool execution failed: ' . $e->getMessage()` mais o nome
 * da classe — foi assim que a reprodução da revisão publicou SQLSTATE, senha,
 * path e e-mail. Por isso as tools de CRM **nunca lançam**: elas devolvem
 * sempre o envelope canônico, e é aqui que o envelope de erro vira
 * `isError: true`, sem que nenhuma mensagem crua chegue perto do payload.
 *
 * Tudo neste arquivo é restrito por nome às quatro READ. As quatro WRITE de CRM
 * e as outras 60 tools não são tocadas.
 */
final class CrmReadBoundary
{
    /** As quatro tools cujo contrato esta fronteira governa. */
    public const READ_TOOLS = [
        'whmcs_crm_list_contacts',
        'whmcs_crm_get_contact',
        'whmcs_crm_list_followups',
        'whmcs_crm_get_kanban',
    ];

    /**
     * Endurecimento aplicado ao schema publicado de cada READ.
     *
     * `additionalProperties: false` fecha os nomes legados (`type`, `id`,
     * `contactId`) tanto sozinhos quanto ao lado do nome canônico. `minimum: 1`
     * nos filtros de id garante que apenas ausência/`null` signifique "sem
     * filtro": `0` e negativos passam a ser recusados pelo validador da SDK,
     * antes da invocação, em vez de desligarem o filtro em silêncio.
     *
     * @var array<string, array<string, array<string, int>>>
     */
    private const NUMERIC_BOUNDS = [
        'whmcs_crm_list_contacts' => [
            'type_id' => ['minimum' => 1],
            'status_id' => ['minimum' => 1],
            'limit' => ['minimum' => 1],
            'offset' => ['minimum' => 0],
        ],
        'whmcs_crm_get_contact' => [
            'resource_id' => ['minimum' => 1],
        ],
        'whmcs_crm_list_followups' => [
            'resource_id' => ['minimum' => 1],
            'type_id' => ['minimum' => 1],
            'status_id' => ['minimum' => 1],
            'limit' => ['minimum' => 1],
            'offset' => ['minimum' => 0],
        ],
        'whmcs_crm_get_kanban' => [
            'type_id' => ['minimum' => 1],
            'limit_per_status' => ['minimum' => 1],
        ],
    ];

    /**
     * Reescreve no registry o schema das quatro READ.
     *
     * `registerTool()` substitui a definição existente sem disparar
     * `notifyToolsChanged` (o nome já existe), então isto não provoca o fan-out
     * de mensagens que o adapter passou o CRM-1 inteiro contendo.
     *
     * É idempotente e roda a cada request, inclusive com cache quente — o
     * registry rehidratado do cache traz o schema original, e endurecer em
     * memória mantém o comportamento igual nos dois caminhos.
     */
    public static function hardenSchemas(Registry $registry): void
    {
        foreach (self::READ_TOOLS as $toolName) {
            $definition = $registry->findTool($toolName);

            if ($definition === null) {
                continue;
            }

            $registry->registerTool(new ToolDefinition(
                $definition->getClassName(),
                $definition->getMethodName(),
                $definition->getName(),
                $definition->getDescription(),
                self::hardenSchema($definition->getInputSchema(), $toolName),
            ));
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function hardenSchema(array $schema, string $toolName): array
    {
        $schema['additionalProperties'] = false;

        $properties = $schema['properties'] ?? [];

        foreach (self::NUMERIC_BOUNDS[$toolName] ?? [] as $property => $bounds) {
            if (!is_array($properties[$property] ?? null)) {
                continue;
            }

            foreach ($bounds as $keyword => $value) {
                $properties[$property][$keyword] = $value;
            }
        }

        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * Converte o envelope canônico de erro das READ em erro MCP de verdade.
     *
     * Só age quando TODAS as condições valem: a mensagem responde ao id do
     * request corrente, o request era `tools/call` para uma das quatro READ, e
     * o conteúdo decodifica no NOSSO envelope (`result: error` com
     * `error_code`). Qualquer outra coisa — inclusive um erro que a própria SDK
     * já formatou — passa intocada.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public static function markErrorEnvelopes(array $messages, ?string $toolName, mixed $requestId): array
    {
        if ($toolName === null || !in_array($toolName, self::READ_TOOLS, true)) {
            return $messages;
        }

        foreach ($messages as $index => $message) {
            if (!is_array($message) || ($message['id'] ?? null) !== $requestId) {
                continue;
            }

            if (!isset($message['result']) || !is_array($message['result'])) {
                continue;
            }

            if (!self::isCanonicalFailure($message['result'])) {
                continue;
            }

            $messages[$index]['result']['isError'] = true;
        }

        return $messages;
    }

    /** @param array<string, mixed> $result */
    private static function isCanonicalFailure(array $result): bool
    {
        $text = $result['content'][0]['text'] ?? null;

        if (!is_string($text) || $text === '') {
            return false;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded)
            && ($decoded['result'] ?? null) === 'error'
            && is_string($decoded['error_code'] ?? null)
            && $decoded['error_code'] !== '';
    }

    /**
     * Nome da tool pedida por um `tools/call`, e o id do request.
     *
     * A SDK não devolve essa informação junto da resposta, então ela é lida do
     * input — a mesma fonte que o adapter já consulta para decidir o método.
     *
     * @return array{0:string|null, 1:mixed}
     */
    public static function calledTool(string $input): array
    {
        $decoded = json_decode($input, true);

        if (!is_array($decoded) || ($decoded['method'] ?? null) !== 'tools/call') {
            return [null, null];
        }

        $name = $decoded['params']['name'] ?? null;

        return [is_string($name) ? $name : null, $decoded['id'] ?? null];
    }
}
