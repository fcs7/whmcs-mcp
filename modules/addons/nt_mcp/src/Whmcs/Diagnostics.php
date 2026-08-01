<?php
// src/Whmcs/Diagnostics.php
namespace NtMcp\Whmcs;

/**
 * Diagnóstico ESTRUTURAL para o `error_log`.
 *
 * A tentativa anterior — logar a mensagem downstream depois de passá-la por um
 * redator de texto — não funciona e não pode funcionar: uma lista de regex
 * nunca cobre todo segredo possível (token, CPF/CNPJ, PAN, senha interna, path,
 * fragmento de SQL, chave de API de um módulo de terceiro). Qualquer regex é
 * incompleta por construção, e o custo do erro é vazar credencial no log.
 *
 * A política aqui é outra: **nenhum texto arbitrário é registrado**. O que vai
 * para o log é só o que nós mesmos controlamos —
 *
 *   - a correlação, que liga esta linha ao Activity Log;
 *   - uma categoria estável (nossa, não da origem);
 *   - o contexto (comando/tabela), que vem de allowlist nossa;
 *   - a CLASSE da exceção, que é um nome de tipo, não conteúdo;
 *   - um fingerprint da mensagem — hash truncado, irreversível.
 *
 * O fingerprint preserva o que o operador realmente precisa: reconhecer que
 * dois incidentes têm a mesma causa, e correlacionar com o que o WHMCS gravou
 * nos próprios logs dele. Sem carregar o texto.
 */
final class Diagnostics
{
    /** Categoria estável do incidente — nunca vem da origem. */
    public const CATEGORY_API_ERROR = 'downstream_api_error';
    public const CATEGORY_API_EXCEPTION = 'downstream_api_exception';
    public const CATEGORY_API_MALFORMED = 'downstream_api_malformed_response';
    public const CATEGORY_DB_EXCEPTION = 'database_exception';
    public const CATEGORY_AUDIT_SINK = 'audit_sink_failure';
    public const CATEGORY_PARTIAL_EFFECT = 'partial_financial_effect';

    /**
     * Fingerprint irreversível e estável de um texto. Truncado: serve para
     * agrupar incidentes idênticos, não para recuperar o conteúdo.
     */
    public static function fingerprint(?string $text): string
    {
        if ($text === null || $text === '') {
            return 'none';
        }

        return substr(hash('sha256', $text), 0, 12);
    }

    /**
     * Escreve uma linha segura no error_log.
     *
     * @param string      $category   uma das CATEGORY_* (nossa)
     * @param string      $context    comando/tabela — origem controlada
     * @param \Throwable|null $e      só a classe e o fingerprint são usados
     * @param string|null $rawText    NUNCA é escrito; só vira fingerprint
     */
    public static function log(
        string $correlationId,
        string $category,
        string $context,
        ?\Throwable $e = null,
        ?string $rawText = null,
    ): void {
        $parts = [
            '[NT-MCP]',
            "[corr:{$correlationId}]",
            "category={$category}",
            'context=' . self::safeToken($context),
        ];

        if ($e !== null) {
            $parts[] = 'exception=' . get_class($e);
            $parts[] = 'fingerprint=' . self::fingerprint($e->getMessage());
        } elseif ($rawText !== null) {
            $parts[] = 'fingerprint=' . self::fingerprint($rawText);
        }

        error_log(implode(' ', $parts));
    }

    /**
     * Mesmo o contexto é higienizado: ele vem das nossas allowlists hoje, mas
     * um chamador futuro poderia passar algo inesperado.
     */
    private static function safeToken(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.\-]/', '', $value) ?? '';

        return $safe === '' ? 'unknown' : substr($safe, 0, 64);
    }
}
