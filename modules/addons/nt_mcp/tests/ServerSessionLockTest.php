<?php

declare(strict_types=1);

namespace NtMcp\Tests;

use NtMcp\Mcp\ServerAdapterInterface;
use NtMcp\Mcp\SessionLock;
use NtMcp\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Server::run() serializa requests da mesma sessão via SessionLock e falha
 * fechado (503 + Retry-After) quando não consegue o lock.
 */
final class ServerSessionLockTest extends TestCase
{
    private const SESSION = 'f1d2d2f9-24a8-4f1c-9f3e-0b1c2d3e4f50';
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/nt-srv-' . bin2hex(random_bytes(6));
        Server::setDataDir($this->dataDir);
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['CONTENT_LENGTH'] = '0';
        unset($_SERVER['HTTP_MCP_SESSION_ID']);
    }

    protected function tearDown(): void
    {
        Server::setAdapterFactory(null);
        Server::setDataDir(null);
        unset($_SERVER['HTTP_MCP_SESSION_ID'], $_SERVER['REQUEST_METHOD']);
        array_map(static fn($f) => @unlink($f), glob($this->dataDir . '/session-locks/*') ?: []);
        @rmdir($this->dataDir . '/session-locks');
        @rmdir($this->dataDir);
    }

    /** Adapter que registra se a faixa da sessão estava travada DURANTE o handle. */
    private function probingAdapter(string $dataDir, string $session, ?bool &$lockedDuringHandle): void
    {
        Server::setAdapterFactory(static function () use ($dataDir, $session, &$lockedDuringHandle): ServerAdapterInterface {
            return new class($dataDir, $session, $lockedDuringHandle) implements ServerAdapterInterface {
                public function __construct(private string $dataDir, private string $session, private ?bool &$locked) {}
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $probe = new SessionLock($this->dataDir . '/session-locks');
                    $this->locked = !$probe->acquire($this->session, 20);
                    $probe->release();
                    $f = new Psr17Factory();
                    return $f->createResponse(200)->withBody($f->createStream('{}'));
                }
            };
        });
    }

    #[Test]
    public function request_with_session_header_holds_lock_during_handle_and_releases_after(): void
    {
        $locked = null;
        $this->probingAdapter($this->dataDir, self::SESSION, $locked);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_MCP_SESSION_ID'] = self::SESSION;

        ob_start();
        Server::run('testadmin');
        $out = ob_get_clean();

        $this->assertSame('{}', $out);
        $this->assertTrue($locked, 'faixa da sessão deve estar travada enquanto o adapter roda');
        $after = new SessionLock($this->dataDir . '/session-locks');
        $this->assertTrue($after->acquire(self::SESSION, 20), 'lock deve ser liberado após o emit');
        $after->release();
    }

    #[Test]
    public function delete_also_takes_the_session_lock(): void
    {
        $locked = null;
        $this->probingAdapter($this->dataDir, self::SESSION, $locked);
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['HTTP_MCP_SESSION_ID'] = self::SESSION;

        ob_start();
        Server::run('testadmin');
        ob_end_clean();

        $this->assertTrue($locked);
    }

    #[Test]
    public function initialize_without_session_header_does_not_lock(): void
    {
        $locked = null;
        $this->probingAdapter($this->dataDir, self::SESSION, $locked);
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        Server::run('testadmin');
        ob_end_clean();

        $this->assertFalse($locked);
    }

    #[Test]
    public function held_lock_yields_503_with_retry_after_and_never_reaches_adapter(): void
    {
        $reached = false;
        Server::setAdapterFactory(static function () use (&$reached): ServerAdapterInterface {
            return new class($reached) implements ServerAdapterInterface {
                public function __construct(private bool &$reached) {}
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->reached = true;
                    $f = new Psr17Factory();
                    return $f->createResponse(200);
                }
            };
        });
        $holder = new SessionLock($this->dataDir . '/session-locks');
        $this->assertTrue($holder->acquire(self::SESSION));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_MCP_SESSION_ID'] = self::SESSION;

        // Timeout real é 5 s; aqui basta provar o caminho fechado.
        $start = microtime(true);
        ob_start();
        Server::run('testadmin');
        $out = ob_get_clean();
        $holder->release();

        $this->assertFalse($reached, 'adapter não pode rodar sem o lock');
        $this->assertSame(503, http_response_code());
        $this->assertSame(['error' => 'Session busy; retry'], json_decode($out, true));
        $this->assertGreaterThanOrEqual(4.5, microtime(true) - $start, 'espera pelo lock antes do 503');
    }

    #[Test]
    public function session_header_value_is_not_used_as_path(): void
    {
        $locked = null;
        $this->probingAdapter($this->dataDir, '../../x', $locked);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_MCP_SESSION_ID'] = '../../x';

        ob_start();
        Server::run('testadmin');
        ob_end_clean();

        $this->assertTrue($locked);
        $names = array_map('basename', glob($this->dataDir . '/session-locks/*'));
        $this->assertCount(1, $names);
        $this->assertMatchesRegularExpression('/^sess-[0-9a-f]{64}\.lock$/', $names[0]);
    }

    // ------------------------------------------------------------------
    // (6) O lock precisa estar seguro DURANTE a emissão do corpo, não só
    // durante handle() — é a emissão (echo do body) que, no caminho SSE do
    // SDK, consome/gera mensagens de sessão. Um probe dentro de handle()
    // não prova isso: se o lock fosse liberado logo depois de handle() e
    // antes do corpo ser lido, esse teste continuaria passando sem cobrir a
    // razão de existir do "segura até depois do emit".
    // ------------------------------------------------------------------

    private function adapterWithProbingBody(string $dataDir, string $session, ?bool &$lockedDuringBody, string $contentType): void
    {
        Server::setAdapterFactory(static function () use ($dataDir, $session, &$lockedDuringBody, $contentType): ServerAdapterInterface {
            return new class($dataDir, $session, $lockedDuringBody, $contentType) implements ServerAdapterInterface {
                public function __construct(
                    private string $dataDir,
                    private string $session,
                    private ?bool &$locked,
                    private string $contentType,
                ) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $f = new Psr17Factory();
                    $probe = function () {
                        $lock = new SessionLock($this->dataDir . '/session-locks');
                        $stillLocked = !$lock->acquire($this->session, 20);
                        $lock->release();
                        return $stillLocked;
                    };
                    // Dispara no instante em que Server::emit() lê o corpo, não em handle().
                    $body = new \NtMcp\Tests\Support\ProbingStream('{}', function () use ($probe): void {
                        $this->locked = $probe();
                    });

                    return $f->createResponse(200)
                        ->withHeader('Content-Type', $this->contentType)
                        ->withBody($body);
                }
            };
        });
    }

    #[Test]
    public function lock_is_still_held_while_a_json_body_is_being_emitted(): void
    {
        $lockedDuringBody = null;
        $this->adapterWithProbingBody($this->dataDir, self::SESSION, $lockedDuringBody, 'application/json');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_MCP_SESSION_ID'] = self::SESSION;

        ob_start();
        Server::run('testadmin');
        ob_end_clean();

        $this->assertTrue($lockedDuringBody, 'corpo JSON deve ser emitido com a faixa da sessão ainda travada');
    }

    #[Test]
    public function lock_is_already_released_before_an_sse_body_starts_streaming(): void
    {
        $lockedDuringBody = null;
        $this->adapterWithProbingBody($this->dataDir, self::SESSION, $lockedDuringBody, 'text/event-stream');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_MCP_SESSION_ID'] = self::SESSION;

        ob_start();
        Server::run('testadmin');
        ob_end_clean();

        $this->assertFalse(
            $lockedDuringBody,
            'corpo SSE não pode ser emitido com o lock preso — um POST de follow-up (sampling/elicitation) '
            . 'na mesma sessão precisaria do mesmo lock e travaria (deadlock), não apenas esperar'
        );
    }
}
