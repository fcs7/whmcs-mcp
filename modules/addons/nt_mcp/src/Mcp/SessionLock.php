<?php
// src/Mcp/SessionLock.php
declare(strict_types=1);

namespace NtMcp\Mcp;

/**
 * Serializa requests HTTP que carregam o MESMO `Mcp-Session-Id`.
 *
 * O `FileSessionStore` do SDK lê e regrava o JSON inteiro da sessão sem lock:
 * dois requests simultâneos na mesma sessão hidratam o mesmo estado, cada um
 * grava a sua fila de saída e o último `save()` vence — a resposta do outro é
 * perdida e, pior, `consumeOutgoingMessages()` entrega ao cliente A a resposta
 * de B (reproduzido em docker, ver go/no-go). A lib anterior tinha lock global;
 * este é por faixa de sessão, preservando paralelismo entre sessões distintas.
 *
 *  - Faixas fixas (`BUCKETS` arquivos em data/session-locks) — sem crescimento
 *    ilimitado e sem a corrida clássica de apagar lock file em uso.
 *  - O valor do header NUNCA vira nome de arquivo: só o índice da faixa.
 *  - `LOCK_NB` em loop com timeout curto: flock bloqueante empilharia workers.
 *    Timeout ou falha de abertura → chamador responde 503 + Retry-After.
 *  - Diretório 0700, arquivos 0600; chmod verificado (fail-closed).
 */
final class SessionLock
{
    public const BUCKETS = 64;
    public const ACQUIRE_TIMEOUT_MS = 5000;
    private const POLL_US = 10000;

    /** @var resource|null */
    private $handle = null;

    /** Motivo da última falha de acquire(): 'open_failed' (estrutural) ou 'timeout' (contenção real). */
    private ?string $failureReason = null;

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Diagnóstico da última falha de `acquire()`, ou null se a última chamada
     * teve sucesso (ou nenhuma chamada ainda ocorreu). Sem isto, mkdir/chmod/
     * fopen quebrados (ownership errado, disco cheio, permissão) e contenção
     * legítima log(av)am o mesmo `lock_busy` — o operador tentaria "esperar
     * passar" um problema estrutural que nunca se resolve sozinho.
     */
    public function lastFailure(): ?string
    {
        return $this->failureReason;
    }

    public static function bucketFor(string $sessionId): int
    {
        return crc32($sessionId) % self::BUCKETS;
    }

    public function acquire(string $sessionId, int $timeoutMs = self::ACQUIRE_TIMEOUT_MS): bool
    {
        $this->failureReason = null;

        if ($this->handle !== null) {
            $this->failureReason = 'open_failed';
            return false;
        }
        if (!self::ensureDirectory($this->directory)) {
            $this->failureReason = 'open_failed';
            return false;
        }

        $path = $this->directory . '/' . sprintf('bucket-%02d.lock', self::bucketFor($sessionId));
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            $this->failureReason = 'open_failed';
            return false;
        }
        // `fopen('c')` cria o arquivo com 0666 & ~umask (0644 no Plesk), então a
        // PRIMEIRA request de cada faixa precisa corrigir o modo. O
        // `clearstatcache()` entre o chmod e a releitura não é zelo: sem ele o
        // `fileperms()` devolve o valor CACHEADO do stat anterior (0644), a
        // verificação falha mesmo com o chmod bem-sucedido, e o request morre
        // com 503 "Session busy" — falso-positivo que aparecia sempre que uma
        // sessão nova caía numa faixa ainda inexistente.
        if (!self::ensureMode($path, 0600)) {
            fclose($handle);
            $this->failureReason = 'open_failed';
            return false;
        }

        $deadline = microtime(true) + ($timeoutMs / 1000);
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;
                return true;
            }
            usleep(self::POLL_US);
        } while (microtime(true) < $deadline);

        fclose($handle);
        $this->failureReason = 'timeout';
        return false;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * Confirma o modo do arquivo, corrigindo se necessário. Toda leitura de
     * `fileperms()` é precedida de `clearstatcache()` porque o stat do PHP é
     * cacheado por request: reler sem invalidar devolve o modo ANTERIOR ao
     * chmod e transforma um chmod bem-sucedido em falha reportada.
     */
    private static function ensureMode(string $path, int $mode): bool
    {
        clearstatcache(true, $path);
        if ((@fileperms($path) & 0777) === $mode) {
            return true;
        }
        if (!@chmod($path, $mode)) {
            return false;
        }
        clearstatcache(true, $path);

        return (@fileperms($path) & 0777) === $mode;
    }

    private static function ensureDirectory(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        clearstatcache(true, $dir);
        if ((@fileperms($dir) & 0777) !== 0700 && !@chmod($dir, 0700)) {
            return false;
        }
        clearstatcache(true, $dir);

        return is_dir($dir) && is_writable($dir) && (@fileperms($dir) & 0777) === 0700;
    }
}
