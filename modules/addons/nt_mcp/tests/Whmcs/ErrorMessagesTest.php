<?php
// tests/Whmcs/ErrorMessagesTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\ErrorClassifier;
use NtMcp\Whmcs\ErrorMessages;
use PHPUnit\Framework\TestCase;

class ErrorMessagesTest extends TestCase
{
    /**
     * Contrato travado de propósito: código que o classificador sabe emitir e
     * que não tem frase própria volta ao texto genérico — exatamente o problema
     * que este lote fechou. Um código novo em `ErrorClassifier` sem entrada aqui
     * quebra este teste, que é o ponto.
     */
    public function test_every_classifier_code_has_a_human_phrase(): void
    {
        $patterns = (new \ReflectionClass(ErrorClassifier::class))
            ->getReflectionConstant('PATTERNS')->getValue();

        $codes = [];
        foreach ($patterns as $group) {
            foreach ($group as [, $code,]) {
                $codes[$code] = true;
            }
        }

        $this->assertNotEmpty($codes);
        foreach (array_keys($codes) as $code) {
            $this->assertTrue(ErrorMessages::has($code), "código '{$code}' sem frase humana");
        }
    }

    public function test_message_carries_the_phrase_command_and_correlation(): void
    {
        $message = ErrorMessages::build('invoice_not_found', 'GetInvoice', 'deadbeef');

        $this->assertStringContainsString('No invoice exists', $message);
        $this->assertStringContainsString('GetInvoice', $message);
        $this->assertStringContainsString('deadbeef', $message);
        $this->assertStringNotContainsString('did not complete successfully', $message);
    }

    public function test_unknown_code_falls_back_to_the_stable_generic_phrase(): void
    {
        $message = ErrorMessages::build('some_code_we_never_defined', 'GetClients', 'cafe1234');

        $this->assertStringContainsString("The WHMCS API call 'GetClients' did not complete successfully", $message);
        $this->assertStringContainsString('cafe1234', $message);
    }

    public function test_downstream_codes_that_do_not_come_from_the_classifier_are_covered(): void
    {
        foreach (['downstream_error', 'downstream_malformed', 'response_encoding_failed'] as $code) {
            $this->assertTrue(ErrorMessages::has($code), "código '{$code}' sem frase humana");
        }
    }
}
