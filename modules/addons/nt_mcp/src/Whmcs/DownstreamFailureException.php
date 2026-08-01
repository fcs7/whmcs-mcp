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
}
