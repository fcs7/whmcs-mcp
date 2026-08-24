<?php
// src/Mcp/AuthorizationAwareReferenceHandler.php
declare(strict_types=1);

namespace NtMcp\Mcp;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;
use NtMcp\Whmcs\AuthorizationException;

/**
 * Traduz recusa de AUTORIZAÇÃO em erro de tool visível ao cliente (issue #29).
 *
 * O problema: `CallToolHandler` do SDK distingue dois ramos. `ToolCallException`
 * vira `CallToolResult::error()` COM a mensagem original; qualquer outro
 * `\Throwable` vira `-32603 "Error while executing tool"` — mensagem descartada.
 * Como `AuthorizationException` estende `\RuntimeException`, toda negação de
 * gate caía no ramo genérico: o motivo (`write_target_not_allowed`, gate de
 * classe desligado, readonly) existia só no Activity Log do WHMCS, e o cliente
 * recebia um erro indistinguível de falha interna — o LLM não tinha como saber
 * que era política e não bug, e retentava.
 *
 * Por que AQUI e não nas tools: `CLAUDE.md` fixa "não usar try/catch nos tools".
 * Um `catch` por tool mutável seria repetição em ~15 lugares e qualquer tool
 * nova nasceria com o bug de volta. Este decorator é o ponto ÚNICO por onde
 * toda invocação de tool passa (`Builder::setReferenceHandler()`), então cobre
 * o que existe e o que vier.
 *
 * `ToolCallException` do SDK é `final` — daí a tradução por composição em vez
 * de `AuthorizationException extends ToolCallException`.
 *
 * O que este decorator NÃO faz: mudar quem é negado. A decisão continua inteira
 * em `LocalApiClient`; aqui só se troca a embalagem do "não" na saída. Exceções
 * de qualquer outro tipo sobem intactas — uma falha real deve continuar sendo
 * `-32603`, e não ganhar aparência de recusa tratada.
 */
final class AuthorizationAwareReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(private readonly ReferenceHandlerInterface $inner) {}

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        try {
            return $this->inner->handle($reference, $arguments);
        } catch (AuthorizationException $e) {
            // A mensagem já é a de auditoria e não carrega PII: nomeia o comando
            // e o motivo, nunca o payload. Ver LocalApiClient::denyTarget().
            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
