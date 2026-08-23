<?php
// tests/Whmcs/ResponseNormalizationTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\CustomFieldDirectory;
use NtMcp\Whmcs\ResponseRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Pipeline de saída (`ResponseRedactor::normalizeResponse`) — o contrato que
 * fecha o lote de 2026-08-23: objetos monetários viravam `{}`, datas-zero
 * localizadas passavam, `orderdata` chegava como string, ids ausentes como
 * `""` e coleções vazias sumiam da resposta.
 */
class ResponseNormalizationTest extends TestCase
{
    /** Formatador monetário do WHMCS: estado protegido, valor só por método. */
    private static function money(float $amount, string $formatted): object
    {
        return new class($amount, $formatted) {
            public function __construct(private float $amount, private string $formatted) {}
            public function toNumeric(): float { return $this->amount; }
            public function toPrefixed(): string { return $this->formatted; }
        };
    }

    public function test_money_object_becomes_amount_and_formatted_instead_of_empty_object(): void
    {
        $result = ['result' => 'success', 'income' => ['today' => self::money(60.0, 'R$60,00')]];

        ResponseRedactor::normalizeResponse($result, 'GetStats');

        $this->assertSame(['amount' => 60.0, 'formatted' => 'R$60,00'], $result['income']['today']);
        $this->assertStringNotContainsString('{}', json_encode($result));
    }

    public function test_object_without_usable_method_or_public_property_becomes_null_never_empty_object(): void
    {
        $opaque = new class { private string $hidden = 'x'; };
        $result = ['result' => 'success', 'value' => $opaque];

        ResponseRedactor::normalizeResponse($result, '');

        $this->assertNull($result['value']);
    }

    public function test_json_serializable_and_datetime_objects_use_their_own_representation(): void
    {
        $serializable = new class implements \JsonSerializable {
            public function jsonSerialize(): array { return ['a' => 1]; }
        };
        $result = [
            'result' => 'success',
            'blob'   => $serializable,
            'when'   => new \DateTimeImmutable('2026-08-23T03:04:05+00:00'),
        ];

        ResponseRedactor::normalizeResponse($result, '');

        $this->assertSame(['a' => 1], $result['blob']);
        $this->assertSame('2026-08-23T03:04:05+00:00', $result['when']);
    }

    public function test_object_cycle_does_not_recurse_forever(): void
    {
        $node = new \stdClass();
        $node->name = 'loop';
        $node->self = $node;
        $result = ['result' => 'success', 'node' => $node];

        ResponseRedactor::normalizeResponse($result, '');

        $this->assertSame('loop', $result['node']['name']);
        $this->assertNull($result['node']['self']);
    }

    /**
     * O scrub roda DEPOIS da materialização: antes, um segredo dentro de um
     * objeto (ou dentro de um campo JSON-string) nunca era visitado, porque as
     * duas passadas só desciam em `is_array`.
     */
    public function test_scrub_reaches_secrets_that_only_exist_after_materialisation(): void
    {
        $holder = new \stdClass();
        $holder->password = 'leaked-from-object';
        $holder->keep = 'ok';

        $result = [
            'result'    => 'success',
            'container' => $holder,
            'orderdata' => '{"password":"leaked-from-json-string","plan":"vps"}',
        ];

        ResponseRedactor::normalizeResponse($result, 'GetOrders');

        $this->assertSame(['keep' => 'ok'], $result['container']);
        $this->assertSame(['plan' => 'vps'], $result['orderdata']);
        $this->assertStringNotContainsString('leaked', json_encode($result));
    }

    public function test_orderdata_json_string_is_decoded_and_invalid_json_is_preserved(): void
    {
        $result = ['result' => 'success', 'orderdata' => '[]'];
        ResponseRedactor::normalizeResponse($result, 'GetOrders');
        $this->assertSame([], $result['orderdata']);

        $broken = ['result' => 'success', 'orderdata' => 'not json at all'];
        ResponseRedactor::normalizeResponse($broken, 'GetOrders');
        $this->assertSame('not json at all', $broken['orderdata']);
    }

    /**
     * @dataProvider zeroDateProvider
     */
    public function test_zero_date_sentinels_become_null(string $value): void
    {
        $result = ['result' => 'success', 'validuntil' => $value];

        ResponseRedactor::normalizeResponse($result, 'GetQuotes');

        $this->assertNull($result['validuntil'], "sentinela não reconhecida: {$value}");
    }

    public static function zeroDateProvider(): array
    {
        return [
            'mysql date'      => ['0000-00-00'],
            'mysql datetime'  => ['0000-00-00 00:00:00'],
            'iso-ish'         => ['0000-00-00T00:00:00Z'],
            'localizado br'   => ['00/00/0000'],
            'localizado dash' => ['00-00-0000'],
            'localizado dot'  => ['00.00.0000'],
        ];
    }

    public function test_real_dates_and_free_text_are_untouched(): void
    {
        $result = [
            'result'      => 'success',
            'validuntil'  => '23/08/2026',
            'datecreated' => '2026-08-23',
            'notes'       => '00/00/0000 é o valor que o cliente digitou no formulário',
        ];

        ResponseRedactor::normalizeResponse($result, 'GetQuotes');

        $this->assertSame('23/08/2026', $result['validuntil']);
        $this->assertSame('2026-08-23', $result['datecreated']);
        $this->assertIsString($result['notes']);
    }

    public function test_empty_id_fields_become_null_only_on_allowlisted_keys(): void
    {
        $result = [
            'result'      => 'success',
            'transid'     => '',
            'domainid'    => '',
            'serviceid'   => '',
            'pid'         => '',
            'description' => '',
            'notes'       => '',
        ];

        ResponseRedactor::normalizeResponse($result, '');

        $this->assertNull($result['transid']);
        $this->assertNull($result['domainid']);
        $this->assertNull($result['serviceid']);
        $this->assertNull($result['pid']);
        $this->assertSame('', $result['description'], 'texto livre vazio continua string');
        $this->assertSame('', $result['notes']);
    }

    public function test_missing_collections_are_returned_as_empty_lists(): void
    {
        foreach ([
            'GetContacts'      => 'contacts',
            'GetToDoItems'     => 'todoitems',
            'GetClientGroups'  => 'groups',
            'GetClientsAddons' => 'addons',
        ] as $command => $key) {
            $result = ['result' => 'success', 'totalresults' => 0];
            ResponseRedactor::normalizeResponse($result, $command);
            $this->assertSame([], $result[$key], "{$command} deve devolver {$key}: []");
        }
    }

    public function test_existing_collection_is_never_overwritten(): void
    {
        $result = ['result' => 'success', 'contacts' => ['contact' => [['id' => 1]]]];

        ResponseRedactor::normalizeResponse($result, 'GetContacts');

        $this->assertSame([['id' => 1]], $result['contacts']['contact']);
    }

    public function test_unknown_command_never_invents_collection_keys(): void
    {
        $result = ['result' => 'success'];

        ResponseRedactor::normalizeResponse($result, 'GetClients');

        $this->assertSame(['result' => 'success'], $result);
    }

    public function test_custom_fields_are_labelled_and_unknown_ids_stay_untouched(): void
    {
        $directory = new CustomFieldDirectory();
        $directory->setResolver(static fn() => [
            ['id' => 4, 'fieldname' => 'CPF/CNPJ', 'fieldtype' => 'text'],
        ]);

        $result = [
            'result'       => 'success',
            'customfields' => [
                ['id' => 4, 'value' => '12345678900'],
                ['id' => 99, 'value' => 'sem rótulo'],
            ],
        ];

        ResponseRedactor::stripClientDetails($result, $directory);

        $this->assertSame('CPF/CNPJ', $result['customfields'][0]['name']);
        $this->assertSame('text', $result['customfields'][0]['type']);
        $this->assertArrayNotHasKey('name', $result['customfields'][1]);
        $this->assertSame('sem rótulo', $result['customfields'][1]['value']);
    }

    public function test_product_descriptions_are_summarised_and_truncated(): void
    {
        $long = '<div><p>' . str_repeat('preço competitivo ', 60) . '</p></div>';
        $result = ['products' => ['product' => [
            ['pid' => 1, 'name' => 'NT KVM', 'description' => '<p>Um <b>plano</b>&nbsp;simples</p>'],
            ['pid' => 2, 'name' => 'NT LXC', 'description' => $long],
        ]]];

        ResponseRedactor::summariseProductDescriptions($result);

        $first = $result['products']['product'][0];
        $this->assertArrayNotHasKey('description', $first);
        $this->assertSame('Um plano simples', $first['description_plain']);
        $this->assertArrayNotHasKey('description_truncated', $first);

        $second = $result['products']['product'][1];
        $this->assertTrue($second['description_truncated']);
        $this->assertLessThanOrEqual(
            ResponseRedactor::DESCRIPTION_SUMMARY_LIMIT + 1,
            mb_strlen($second['description_plain'], 'UTF-8')
        );
        $this->assertStringNotContainsString('<', $second['description_plain']);
    }

    public function test_activity_log_noise_is_filtered_and_counted(): void
    {
        $result = ['activity' => ['entry' => [
            ['id' => 1, 'description' => 'Hooks Debug: ClientAreaPage'],
            ['id' => 2, 'description' => 'Admin Login - admin'],
            ['id' => 3, 'description' => '  hooks debug: outro'],
        ]]];

        $removed = ResponseRedactor::filterActivityLogNoise($result);

        $this->assertSame(2, $removed);
        $this->assertCount(1, $result['activity']['entry']);
        $this->assertSame(2, $result['activity']['entry'][0]['id']);
    }

    public function test_activity_log_filter_is_a_no_op_on_an_unexpected_shape(): void
    {
        $result = ['result' => 'success'];

        $this->assertSame(0, ResponseRedactor::filterActivityLogNoise($result));
        $this->assertSame(['result' => 'success'], $result);
    }
}
