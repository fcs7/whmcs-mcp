<?php
// src/Mcp/RuntimeDirs.php
declare(strict_types=1);

namespace NtMcp\Mcp;

/**
 * Provisionamento dos diretórios de runtime em data/ (cache de discovery,
 * sessões, locks de sessão). Usado na ativação E no upgrade do addon:
 * cria ou endurece para 0700 e confirma diretório + gravável + modo.
 *
 * O `FileSessionStore` do SDK criaria `sessions/` com 0775 (e arquivos pelo
 * umask, tipicamente 0644) — sessões carregam client_info e filas de
 * mensagens; ninguém além do processo PHP deve lê-las.
 */
final class RuntimeDirs
{
    public const SUBDIRS = ['cache', 'sessions', 'session-locks'];
    public const MODE = 0700;

    /** @return string|null Mensagem de erro, ou null se tudo provisionado. */
    public static function provision(string $dataDir): ?string
    {
        $targets = [$dataDir];
        foreach (self::SUBDIRS as $sub) {
            $targets[] = $dataDir . '/' . $sub;
        }
        foreach ($targets as $dir) {
            $error = self::harden($dir);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private static function harden(string $dir): ?string
    {
        if (!is_dir($dir) && !@mkdir($dir, self::MODE, true) && !is_dir($dir)) {
            return 'nao foi possivel criar ' . $dir;
        }
        clearstatcache(true, $dir);
        if ((@fileperms($dir) & 0777) !== self::MODE && !@chmod($dir, self::MODE)) {
            return 'nao foi possivel proteger (chmod 0700) ' . $dir;
        }
        clearstatcache(true, $dir);
        if (!is_dir($dir) || !is_writable($dir)) {
            return 'diretorio nao gravavel: ' . $dir;
        }
        if ((@fileperms($dir) & 0777) !== self::MODE) {
            return 'modo inesperado (esperado 0700) em ' . $dir;
        }

        return null;
    }
}
