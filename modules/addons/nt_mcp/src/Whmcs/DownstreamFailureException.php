<?php
// src/Whmcs/DownstreamFailureException.php
namespace NtMcp\Whmcs;

/**
 * Falha vinda de fora (WHMCS, hook, módulo de terceiro, driver de banco) já
 * convertida em contrato PÚBLICO e estável.
 *
 * Existe porque relançar a exceção original entrega texto arbitrário ao
 * chamador MCP: o adapter transforma qualquer `Throwable` em
 * `"Tool execution failed: <mensagem>"`, e essa mensagem pode conter token,
 * CPF/CNPJ, PAN, caminho interno ou fragmento de SQL.
 *
 * A mensagem desta exceção é escrita por nós e carrega apenas o comando e a
 * correlação; a original fica acessível como `getPrevious()` para código
 * interno, mas nunca é serializada para fora nem para o log — de onde só sai
 * classe e fingerprint (ver `Diagnostics`).
 */
class DownstreamFailureException extends \RuntimeException
{
    /**
     * A correlação e o fingerprint da CAUSA viajam como dado estruturado.
     *
     * Sem isso, quem captura esta exceção mais acima só tinha a mensagem — e
     * gerava correlação nova, quebrando a ligação com o diagnóstico que a
     * LocalAPI já havia emitido. Pior: fingerprintar esta wrapper produzia
     * valores diferentes a cada execução, porque a mensagem dela contém uma
     * correlação aleatória. Duas falhas com a mesma causa precisam ter o mesmo
     * fingerprint.
     *
     * `getPrevious()` deliberadamente NÃO é encadeada: `(string)$exception`
     * inclui a cadeia anterior, e qualquer handler ou logger que estringifique
     * a exceção reintroduziria o texto downstream. O que era útil da causa —
     * classe e fingerprint — está aqui, já sanitizado.
     */
    public function __construct(
        string $message,
        public readonly string $correlationId = '',
        public readonly string $causeFingerprint = 'none',
        public readonly string $causeClass = '',
    ) {
        parent::__construct($message);
    }
}
