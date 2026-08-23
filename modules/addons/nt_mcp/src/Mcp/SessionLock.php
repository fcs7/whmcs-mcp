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
 * de B (reproduzido em docker, ver go/no-go). A lib anterior tinha lock global.
 *
 *  - UM arquivo por sessão. A implementação anterior usava 64 faixas
 *    (`crc32($id) % 64`), o que serializava sessões DIFERENTES que caíssem na
 *    mesma faixa: com o lock segurado pelo request inteiro (incluindo chamadas
 *    à LocalAPI, que levam segundos), um cliente parado bloqueava outro sem
 *    nenhuma relação, até estourar o timeout e devolver 503. Um arquivo por
 *    sessão elimina a colisão sem mudar mais nada do protocolo.
 *  - O valor do header NUNCA vira nome de arquivo: o nome é o SHA-256 hex do
 *    id, então nem `../`, nem byte nulo, nem tamanho arbitrário atravessam.
 *  - `LOCK_NB` em loop com timeout curto: flock bloqueante empilharia workers.
 *    Timeout ou falha de abertura → chamador responde 503 + Retry-After.
 *  - Diretório 0700, arquivos 0600; chmod verificado (fail-closed).
 *  - GC oportunista de arquivos de sessão já expirada (ver `collectGarbage()`).
 *
 * ACEITO por design: requests concorrentes da MESMA sessão continuam
 * serializados pelo request inteiro. É inerente ao store read-modify-write do
 * SDK — é justamente o bug que este lock existe para evitar.
 */
final class SessionLock
{
    public const ACQUIRE_TIMEOUT_MS = 5000;
    private const POLL_US = 10000;

    /** Idade mínima para um arquivo de lock ser considerado abandonado. */
    public const STALE_AFTER_SECONDS = 3600;

    /** Probabilidade do GC: 1 em GC_DIVISOR acquires. */
    private const GC_DIVISOR = 20;

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

    /**
     * Nome de arquivo derivado do id da sessão. SHA-256 e não o valor cru: o id
     * vem de um header controlado pelo cliente e não pode tocar no filesystem.
     */
    public static function fileFor(string $sessionId): string
    {
        return 'sess-' . hash('sha256', $sessionId) . '.lock';
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

        $path = $this->directory . '/' . self::fileFor($sessionId);
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            $this->failureReason = 'open_failed';
            return false;
        }
        // `fopen('c')` cria o arquivo com 0666 & ~umask (0644 no Plesk), então a
        // PRIMEIRA request de cada sessão precisa corrigir o modo. O
        // `clearstatcache()` entre o chmod e a releitura não é zelo: sem ele o
        // `fileperms()` devolve o valor CACHEADO do stat anterior (0644), a
        // verificação falha mesmo com o chmod bem-sucedido, e o request morre
        // com 503 "Session busy" — falso-positivo que aparecia sempre que uma
        // sessão nova caía num arquivo ainda inexistente.
        if (!self::ensureMode($path, 0600)) {
            fclose($handle);
            $this->failureReason = 'open_failed';
            return false;
        }

        $deadline = microtime(true) + ($timeoutMs / 1000);
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;
                // Marca a sessão como VIVA para o GC. Sem isto, `flock` e
                // `fopen('c')` não mexem no mtime e uma sessão de longa duração
                // teria o próprio arquivo de lock apagado embaixo dela.
                @touch($path);
                $this->collectGarbage();

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
     * Remove arquivos de lock de sessões já expiradas, com probabilidade
     * 1/GC_DIVISOR — mesma disciplina do GC de sessões do SDK.
     *
     * Duas condições, ambas necessárias: mtime mais velho que
     * STALE_AFTER_SECONDS (o `touch()` de `acquire()` mantém sessão ativa fora
     * do alcance) E `flock` exclusivo não-bloqueante bem-sucedido (ninguém
     * segurando). Sem a segunda, o unlink poderia derrubar o arquivo de um
     * request em andamento e dois processos passariam a "segurar" inodes
     * diferentes — ou seja, nenhuma exclusão mútua.
     *
     * @return int quantidade removida (usado em teste)
     */
    public function collectGarbage(bool $force = false): int
    {
        if (!$force && random_int(1, self::GC_DIVISOR) !== 1) {
            return 0;
        }

        $files = @glob($this->directory . '/sess-*.lock');
        if ($files === false) {
            return 0;
        }

        $cutoff = time() - self::STALE_AFTER_SECONDS;
        $removed = 0;
        foreach ($files as $file) {
            clearstatcache(true, $file);
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime > $cutoff) {
                continue;
            }
            $handle = @fopen($file, 'c');
            if ($handle === false) {
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                if (@unlink($file)) {
                    $removed++;
                }
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }

        return $removed;
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
