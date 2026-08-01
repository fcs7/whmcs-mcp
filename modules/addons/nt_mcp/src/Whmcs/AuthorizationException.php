<?php
// src/Whmcs/AuthorizationException.php
namespace NtMcp\Whmcs;

/**
 * Recusa de AUTORIZAÇÃO: allowlist, classificação ausente, gate de classe
 * desligado, master read-only ou requisito COMMS não atendido.
 *
 * Existe como tipo próprio para que um chamador consiga distinguir "a operação
 * foi NEGADA antes de qualquer efeito" de "a operação falhou e pode ter deixado
 * efeito parcial". `QuoteTools::convertQuoteToInvoice()` depende dessa distinção:
 * uma negação nunca pode ser reportada como conversão parcial.
 *
 * Estende RuntimeException para preservar compatibilidade com os chamadores e
 * testes que já capturavam RuntimeException.
 */
class AuthorizationException extends \RuntimeException
{
}
