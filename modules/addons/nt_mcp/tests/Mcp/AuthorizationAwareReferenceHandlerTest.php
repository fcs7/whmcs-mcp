<?php

declare(strict_types=1);

namespace NtMcp\Tests\Mcp;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;
use NtMcp\Mcp\AuthorizationAwareReferenceHandler;
use NtMcp\Whmcs\AuthorizationException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #29: recusa de gate precisa chegar ao cliente com o MOTIVO, e não como
 * `-32603 Error while executing tool`.
 *
 * O contrato que estes testes travam é o do `CallToolHandler` do SDK:
 * `ToolCallException` → `CallToolResult::error()` preservando a mensagem;
 * qualquer outro `\Throwable` → erro interno genérico. Portanto o decorator
 * só pode converter a recusa de autorização, nunca uma falha real.
 */
final class AuthorizationAwareReferenceHandlerTest extends TestCase
{
    private function makeInner(?\Throwable $throw, mixed $return = null): ReferenceHandlerInterface
    {
        return new class($throw, $return) implements ReferenceHandlerInterface {
            public function __construct(
                private readonly ?\Throwable $throw,
                private readonly mixed $return,
            ) {}

            public function handle(ElementReference $reference, array $arguments): mixed
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return $this->return;
            }
        };
    }

    private function reference(): ElementReference
    {
        // O decorator não inspeciona a reference — só repassa.
        return $this->createStub(ElementReference::class);
    }

    public function test_authorization_exception_becomes_tool_call_exception_with_message(): void
    {
        $message = "LocalApiClient: command 'AddTicketReply' is blocked "
            . '(write_target_not_allowed: ticketid=30 fora da allowlist de escrita).';
        $handler = new AuthorizationAwareReferenceHandler(
            $this->makeInner(new AuthorizationException($message))
        );

        try {
            $handler->handle($this->reference(), []);
            $this->fail('esperava ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertSame($message, $e->getMessage(), 'o motivo do bloqueio precisa sobreviver');
            $this->assertStringContainsString('write_target_not_allowed', $e->getMessage());
            $this->assertInstanceOf(AuthorizationException::class, $e->getPrevious());
        }
    }

    public function test_other_throwables_pass_through_untouched(): void
    {
        // Uma falha REAL não pode ganhar aparência de recusa tratada: continua
        // subindo como está, para virar -32603 no SDK.
        $original = new \RuntimeException('falha de rede downstream');
        $handler = new AuthorizationAwareReferenceHandler($this->makeInner($original));

        try {
            $handler->handle($this->reference(), []);
            $this->fail('esperava RuntimeException');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(ToolCallException::class, $e);
            $this->assertSame($original, $e);
        }
    }

    public function test_successful_call_is_returned_unchanged(): void
    {
        $handler = new AuthorizationAwareReferenceHandler($this->makeInner(null, '{"result":"success"}'));

        $this->assertSame('{"result":"success"}', $handler->handle($this->reference(), []));
    }
}
