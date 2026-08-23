<?php
// src/Mcp/SecureFileSessionStore.php
declare(strict_types=1);

namespace NtMcp\Mcp;

use Mcp\Server\Session\FileSessionStore;
use Symfony\Component\Uid\Uuid;

/**
 * `FileSessionStore` do SDK com permissões endurecidas, sem tocar no vendor.
 *
 * O store original cria o diretório com 0775 e os arquivos pelo umask (0644
 * observado). Aqui: diretório forçado a 0700 na construção e cada arquivo de
 * sessão a 0600 após a gravação. Falha de chmod NÃO é silenciosa — lança, e a
 * request termina em 500 pela fronteira de output (o SDK ignora o bool de
 * `write()`, então devolver false esconderia o problema).
 */
class SecureFileSessionStore extends FileSessionStore
{
    private readonly string $dir;

    public function __construct(string $directory, int $ttl = 3600)
    {
        parent::__construct($directory, $ttl);
        $this->dir = $directory;

        clearstatcache(true, $directory);
        if ((@fileperms($directory) & 0777) !== 0700 && !@chmod($directory, 0700)) {
            throw new \RuntimeException('Session directory could not be protected (chmod 0700).');
        }
        clearstatcache(true, $directory);
        if ((@fileperms($directory) & 0777) !== 0700) {
            throw new \RuntimeException('Session directory has unexpected permissions.');
        }
    }

    public function write(Uuid $id, string $data): bool
    {
        // O SDK ignora o retorno de Session::save() (bool descartado). Uma
        // falha de escrita aqui (disco cheio, rename cross-device, etc.) NÃO
        // pode virar `false` silencioso: a ferramenta já rodou, a resposta ou
        // a fila de saída se perderia sem sinal nenhum ao cliente. Lança, e a
        // request termina em 500 pela fronteira de output — mesmo princípio
        // do chmod em `protect()`.
        if (!parent::write($id, $data)) {
            throw new \RuntimeException('Session write failed.');
        }
        $this->protect($this->dir . \DIRECTORY_SEPARATOR . $id->toRfc4122());

        return true;
    }

    /** chmod 0600 verificado; qualquer desvio lança (fail-closed, nunca silencioso). */
    protected function protect(string $path): void
    {
        if (!@chmod($path, 0600)) {
            throw new \RuntimeException('Session file could not be protected (chmod 0600).');
        }
        clearstatcache(true, $path);
        if ((@fileperms($path) & 0777) !== 0600) {
            throw new \RuntimeException('Session file has unexpected permissions.');
        }
    }
}
