<?php

declare(strict_types=1);

namespace NtMcp\Translation;

/**
 * Backup em JSONL do estado ANTERIOR de uma linha de e-mail EN, gravado antes
 * de qualquer INSERT/UPDATE de `EmailTemplateRepository::applyBatch()`.
 *
 * Diretório 0700, arquivo 0600 — mesmo gotcha de `RuntimeDirs`/`SessionLock`:
 * `fopen(..., 'c'|'a')` cria com o umask do processo, não com o modo
 * pretendido, e `fileperms()` sem `clearstatcache()` no meio lê o stat
 * cacheado de ANTES do chmod. Qualquer falha (dir/arquivo não gravável, modo
 * inesperado, escrita incompleta) LANÇA — quem chama (o repositório) faz
 * rollback da transação inteira; nenhum backup parcial é aceitável quando o
 * propósito dele é permitir reverter a escrita.
 */
final class TranslationBackup
{
    private const DIR_MODE = 0700;

    private const FILE_MODE = 0600;

    private string $dataDir;

    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir ?? (__DIR__ . '/../../data');
    }

    /**
     * @param array<string, mixed> $entry Precisa conter `target_language`
     *     (um dos literais suportados pelo repositório chamador) — vira parte
     *     do nome do arquivo, então é validado por formato aqui (defesa em
     *     profundidade; a checagem de literal suportado já aconteceu no
     *     repositório antes de chamar `append()`).
     * @param string $domain Prefixo do arquivo — `'emailtemplates'` (Fase 1,
     *     default, preserva o nome de arquivo já em uso) ou `'dynamic-<kind>'`
     *     (Fase 2+, ex.: `'dynamic-product'`). Mesma validação por formato do
     *     `target_language` — nunca interpolado sem checagem.
     */
    public function append(array $entry, string $domain = 'emailtemplates'): void
    {
        if ($domain === '' || preg_match('/^[a-z_-]+$/', $domain) !== 1) {
            throw new \RuntimeException("translation backup: invalid domain '{$domain}'.");
        }

        $target = (string) ($entry['target_language'] ?? '');
        if ($target === '' || preg_match('/^[a-z-]+$/', $target) !== 1) {
            throw new \RuntimeException("translation backup: invalid target_language '{$target}'.");
        }

        $dir = rtrim($this->dataDir, '/') . '/translation-backups';
        $this->ensureDir($dir);

        $file = $dir . '/' . $domain . '-' . $target . '-' . gmdate('Ymd') . '.jsonl';
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            throw new \RuntimeException('translation backup: failed to encode entry.');
        }

        $isNew = !file_exists($file);

        $handle = @fopen($file, 'a');
        if ($handle === false) {
            throw new \RuntimeException("translation backup: cannot open {$file}.");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("translation backup: cannot lock {$file}.");
            }

            if ($isNew) {
                if (!@chmod($file, self::FILE_MODE)) {
                    throw new \RuntimeException("translation backup: cannot chmod {$file}.");
                }
                clearstatcache(true, $file);
                if ((@fileperms($file) & 0777) !== self::FILE_MODE) {
                    throw new \RuntimeException("translation backup: unexpected mode for {$file}.");
                }
            }

            if (fwrite($handle, $line . "\n") === false) {
                throw new \RuntimeException("translation backup: write failed for {$file}.");
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, self::DIR_MODE, true) && !is_dir($dir)) {
            throw new \RuntimeException("translation backup: cannot create {$dir}.");
        }
        clearstatcache(true, $dir);
        if ((@fileperms($dir) & 0777) !== self::DIR_MODE && !@chmod($dir, self::DIR_MODE)) {
            throw new \RuntimeException("translation backup: cannot chmod {$dir}.");
        }
        clearstatcache(true, $dir);
        if ((@fileperms($dir) & 0777) !== self::DIR_MODE) {
            throw new \RuntimeException("translation backup: unexpected mode for {$dir}.");
        }
    }
}
