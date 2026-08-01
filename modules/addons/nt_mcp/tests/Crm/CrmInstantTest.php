<?php

declare(strict_types=1);

namespace NtMcp\Tests\Crm;

use NtMcp\Crm\CrmErrorCode;
use NtMcp\Crm\CrmException;
use NtMcp\Crm\CrmInstant;
use NtMcp\Whmcs\DateNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D9 aplicado ao INSTANTE do follow-up: gramática estrita, timezone
 * obrigatório, offset dentro da política e conversão para UTC.
 */
class CrmInstantTest extends TestCase
{
    #[DataProvider('acceptedProvider')]
    public function test_accepted_values_normalise_to_utc(string $input, string $expected): void
    {
        $this->assertSame($expected, CrmInstant::toUtcMySql($input, 'date'));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function acceptedProvider(): array
    {
        return [
            'Z' => ['2026-08-10T14:30:00Z', '2026-08-10 14:30:00'],
            'offset negativo atravessa o dia' => ['2026-08-10T23:30:00-03:00', '2026-08-11 02:30:00'],
            'offset positivo recua o dia' => ['2026-08-10T00:30:00+02:00', '2026-08-09 22:30:00'],
            'offset extremo permitido' => ['2026-08-10T00:00:00+14:00', '2026-08-09 10:00:00'],
            'offset extremo negativo' => ['2026-08-10T00:00:00-12:00', '2026-08-10 12:00:00'],
            'fração é truncada, não arredondada' => ['2026-08-10T14:30:00.999Z', '2026-08-10 14:30:00'],
            'bissexto real' => ['2028-02-29T12:00:00Z', '2028-02-29 12:00:00'],
            'virada de ano por offset' => ['2026-12-31T23:00:00-05:00', '2027-01-01 04:00:00'],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function test_rejected_values_fail_as_validation(string $input): void
    {
        $this->assertNull(CrmInstant::tryToUtcMySql($input));

        try {
            CrmInstant::toUtcMySql($input, 'date');
            $this->fail('esperava CrmException');
        } catch (CrmException $e) {
            $this->assertSame(CrmErrorCode::Validation, $e->errorCode);
            if ($input !== '') {
                $this->assertStringNotContainsString(
                    $input,
                    $e->getMessage(),
                    'o input não volta na mensagem'
                );
            }
        }
    }

    /** @return array<string, array{0:string}> */
    public static function rejectedProvider(): array
    {
        return [
            'vazio' => [''],
            'só espaço' => ['   '],
            'espaço à esquerda' => [' 2026-08-10T14:30:00Z'],
            'espaço à direita' => ['2026-08-10T14:30:00Z '],
            'newline final' => ["2026-08-10T14:30:00Z\n"],
            'tab interno' => ["2026-08-10T14:30:00\tZ"],
            'sem timezone' => ['2026-08-10T14:30:00'],
            'data simples' => ['2026-08-10'],
            'separador espaço' => ['2026-08-10 14:30:00Z'],
            'sem segundos' => ['2026-08-10T14:30Z'],
            'hora impossível' => ['2026-08-10T24:00:00Z'],
            'minuto impossível' => ['2026-08-10T14:60:00Z'],
            'segundo bissexto' => ['2026-08-10T23:59:60Z'],
            'tudo impossível' => ['2026-08-10T99:99:99+99:99'],
            'data inexistente' => ['2026-02-31T10:00:00Z'],
            'ano não bissexto' => ['2026-02-29T10:00:00Z'],
            'mês zero' => ['2026-00-10T10:00:00Z'],
            'dia zero' => ['2026-08-00T10:00:00Z'],
            'mês treze' => ['2026-13-01T10:00:00Z'],
            'offset acima da política' => ['2026-08-10T00:00:00+15:00'],
            'offset extremo com minuto' => ['2026-08-10T00:00:00+14:30'],
            'offset com minuto impossível' => ['2026-08-10T00:00:00+05:99'],
            'z minúsculo' => ['2026-08-10T14:30:00z'],
            't minúsculo' => ['2026-08-10t14:30:00Z'],
            'offset sem sinal' => ['2026-08-10T14:30:0003:00'],
            'sufixo extra' => ['2026-08-10T14:30:00Z extra'],
            'ano curto' => ['26-08-10T14:30:00Z'],
        ];
    }

    /**
     * A gramática e a política de offset são as MESMAS do perfil público já
     * aprovado; só a saída difere (instante em UTC vs data civil). Este teste
     * existe para que uma mudança futura em um dos dois normalizadores não
     * abra silenciosamente uma divergência de política.
     */
    #[DataProvider('policyParityProvider')]
    public function test_offset_policy_matches_the_public_date_profile(string $input): void
    {
        $this->assertSame(
            DateNormalizer::tryNormalize($input) !== null,
            CrmInstant::tryToUtcMySql($input) !== null,
            'a aceitação do date-time precisa coincidir entre os dois normalizadores'
        );
    }

    /** @return array<string, array{0:string}> */
    public static function policyParityProvider(): array
    {
        $values = [
            '2026-08-10T14:30:00Z',
            '2026-08-10T14:30:00.5Z',
            '2026-08-10T23:30:00-03:00',
            '2026-08-10T00:00:00+14:00',
            '2026-08-10T00:00:00+14:30',
            '2026-08-10T00:00:00+15:00',
            '2026-08-10T99:99:99+99:99',
            '2026-02-31T10:00:00Z',
            '2026-08-10T14:30:00',
            ' 2026-08-10T14:30:00Z',
        ];

        $cases = [];
        foreach ($values as $value) {
            $cases[$value] = [$value];
        }

        return $cases;
    }

    /** O instante não depende do fuso do processo. */
    public function test_result_is_independent_of_the_process_timezone(): void
    {
        $previous = date_default_timezone_get();

        try {
            date_default_timezone_set('America/Sao_Paulo');
            $inSaoPaulo = CrmInstant::toUtcMySql('2026-08-10T14:30:00Z', 'date');

            date_default_timezone_set('Asia/Tokyo');
            $inTokyo = CrmInstant::toUtcMySql('2026-08-10T14:30:00Z', 'date');
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertSame('2026-08-10 14:30:00', $inSaoPaulo);
        $this->assertSame($inSaoPaulo, $inTokyo);
    }
}
