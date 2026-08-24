<?php
namespace NtMcp\Tests\Tools;

use NtMcp\Tools\ChipTools;
use NtMcp\Whmcs\ChipBridge;
use NtMcp\Whmcs\ChipGuard;
use NtMcp\Whmcs\AuthorizationException;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A ponte real fala com `NtChips\ChipRepository` e com a Capsule; aqui ela é
 * substituída por um duplo em memória. O que estes testes provam é o que mora
 * de fato na `ChipTools`: gates, pré-diagnóstico, projeção e o contrato de
 * erro — não o comportamento do nt_chips.
 */
final class ChipToolsTest extends TestCase
{
    private function chip(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 42,
            'iccid' => '8955170000000000001',
            'msisdn' => '11999990000',
            'client_id' => 7,
            'service_id' => null,
            'status' => 'disponivel',
            'tipo' => 'fisico',
            'operadora_id' => 2,
            'play_status' => 'alocado',
            'ativacao_status' => null,
            'arquivado_em' => null,
            'lpa_code' => 'LPA:1$exemplo$SEGREDO',
            'pdf_storage_key' => 'chips/42.pdf.enc',
        ]);
    }

    private function tools(FakeChipBridge $bridge, array $gates = ['write' => true]): ChipTools
    {
        return new ChipTools($bridge, new ChipGuard($gates));
    }

    /** Payload JSON de um retorno de tool, seja string ou CallToolResult. */
    private function payload(string|CallToolResult $result): array
    {
        if ($result instanceof CallToolResult) {
            $json = $result->content[0]->text;
        } else {
            $json = $result;
        }

        return json_decode($json, true);
    }

    private function assertIsToolError(string|CallToolResult $result, string $code): array
    {
        $this->assertInstanceOf(CallToolResult::class, $result, 'erro de domínio precisa marcar isError');
        $this->assertTrue($result->isError);
        $payload = $this->payload($result);
        $this->assertSame($code, $payload['error_code']);

        return $payload;
    }

    // ---------------------------------------------------------------
    // Disponibilidade do addon
    // ---------------------------------------------------------------

    #[Test]
    public function every_tool_fails_closed_when_nt_chips_is_absent(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->available = false;
        $tools = $this->tools($bridge);

        $this->assertIsToolError($tools->find('123'), 'nt_chips_unavailable');
        $this->assertIsToolError($tools->register('123', 2), 'nt_chips_unavailable');
        $this->assertIsToolError($tools->setIccid(42, '123'), 'nt_chips_unavailable');
        $this->assertIsToolError($tools->validatePlay('123'), 'nt_chips_unavailable');
        $this->assertIsToolError($tools->assignService(42, 7, '11999990000', true), 'nt_chips_unavailable');
        $this->assertIsToolError($tools->saveLpa(42, 'LPA:1$x'), 'nt_chips_unavailable');
    }

    // ---------------------------------------------------------------
    // find
    // ---------------------------------------------------------------

    #[Test]
    public function find_without_any_filter_is_refused(): void
    {
        $this->assertIsToolError($this->tools(new FakeChipBridge())->find(), 'missing_filter');
    }

    #[Test]
    public function find_by_iccid_returns_only_public_columns(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->byIccid['8955170000000000001'] = $this->chip();

        $payload = $this->payload($this->tools($bridge)->find('8955170000000000001'));

        $this->assertSame(1, $payload['count']);
        $chip = $payload['chips'][0];
        $this->assertSame(42, $chip['chip_id']);
        $this->assertSame('alocado', $chip['play_status']);
        $this->assertArrayNotHasKey('lpa_code', $chip);
        $this->assertArrayNotHasKey('pdf_storage_key', $chip);
    }

    #[Test]
    public function find_by_service_and_client_use_their_own_lookups(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->byService[7] = [$this->chip(['id' => 1]), $this->chip(['id' => 2])];
        $bridge->byClient[9] = [$this->chip(['id' => 3])];
        $tools = $this->tools($bridge);

        $this->assertSame(2, $this->payload($tools->find('', 7))['count']);
        $this->assertSame(3, $this->payload($tools->find('', 0, 9))['chips'][0]['chip_id']);
    }

    #[Test]
    public function find_is_read_and_needs_no_write_gate(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->byIccid['8955170000000000001'] = $this->chip();

        $payload = $this->payload(
            $this->tools($bridge, ['write' => false, 'readonly' => true])->find('8955170000000000001')
        );

        $this->assertSame('success', $payload['result']);
    }

    // ---------------------------------------------------------------
    // Gates
    // ---------------------------------------------------------------

    #[Test]
    public function write_tools_are_blocked_when_write_gate_is_off(): void
    {
        $bridge = new FakeChipBridge();
        $tools = $this->tools($bridge, ['write' => false]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessageMatches('/class WRITE disabled/');
        $tools->register('8955170000000000002', 2);
    }

    #[Test]
    public function master_readonly_blocks_even_with_write_enabled(): void
    {
        $tools = $this->tools(new FakeChipBridge(), ['write' => true, 'readonly' => true]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessageMatches('/master read-only/');
        $tools->register('8955170000000000002', 2);
    }

    #[Test]
    public function assign_denies_service_owner_outside_the_client_allowlist(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->chips[42] = $this->chip();
        $bridge->owners[7] = 500;
        $tools = $this->tools($bridge, ['write' => true, 'allowlist_clientids' => [1, 2]]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessageMatches('/write_target_not_allowed/');
        $tools->assignService(42, 7, '11999990000', true);
    }

    #[Test]
    public function assign_accepts_service_owner_inside_the_client_allowlist(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->chips[42] = $this->chip();
        $bridge->owners[7] = 500;
        $tools = $this->tools($bridge, ['write' => true, 'allowlist_clientids' => [500]]);

        $payload = $this->payload($tools->assignService(42, 7, '11999990000', true));

        $this->assertSame('success', $payload['result']);
        $this->assertSame(500, $payload['client_id']);
    }

    // ---------------------------------------------------------------
    // register / set_iccid / save_lpa
    // ---------------------------------------------------------------

    #[Test]
    public function register_creates_the_chip_and_returns_its_id(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->nextId = 77;

        $payload = $this->payload($this->tools($bridge)->register('8955170000000000003', 2, 'virtual'));

        $this->assertSame(77, $payload['chip_id']);
        $this->assertSame('virtual', $bridge->created['tipo']);
        $this->assertSame('8955170000000000003', $bridge->created['iccid']);
    }

    #[Test]
    public function register_refuses_duplicate_iccid_and_invalid_tipo(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->byIccid['8955170000000000001'] = $this->chip();
        $tools = $this->tools($bridge);

        $this->assertIsToolError($tools->register('8955170000000000001', 2), 'iccid_duplicate');
        $this->assertIsToolError($tools->register('8955170000000000009', 2, 'esim'), 'invalid_tipo');
        $this->assertNull($bridge->created);
    }

    #[Test]
    public function set_iccid_surfaces_the_repository_refusal(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->chips[42] = $this->chip();
        $bridge->setIccidResult = false;

        $this->assertIsToolError(
            $this->tools($bridge)->setIccid(42, '8955170000000000004'),
            'iccid_not_writable'
        );
    }

    #[Test]
    public function set_iccid_writes_when_the_repository_accepts(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->chips[42] = $this->chip();

        $payload = $this->payload($this->tools($bridge)->setIccid(42, '8955170000000000004'));

        $this->assertSame('success', $payload['result']);
        $this->assertSame(['42' => '8955170000000000004'], $bridge->iccidWrites);
    }

    #[Test]
    public function save_lpa_stores_the_code_without_echoing_it(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->chips[42] = $this->chip();

        $result = $this->tools($bridge)->saveLpa(42, 'LPA:1$exemplo$SEGREDO');
        $payload = $this->payload($result);

        $this->assertSame('success', $payload['result']);
        $this->assertSame('LPA:1$exemplo$SEGREDO', $bridge->lpaWrites[42]);
        $this->assertStringNotContainsString('SEGREDO', (string) $result);
    }

    #[Test]
    public function save_lpa_on_unknown_chip_is_a_domain_error(): void
    {
        $this->assertIsToolError($this->tools(new FakeChipBridge())->saveLpa(999, 'LPA:1$x'), 'chip_not_found');
    }

    // ---------------------------------------------------------------
    // validate_play
    // ---------------------------------------------------------------

    #[Test]
    public function validate_play_stores_the_status_inferred_from_the_payload(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->byIccid['8955170000000000001'] = $this->chip(['play_status' => null]);
        $bridge->playResponse = ['ok' => true, 'payload' => ['situacao' => 'ALOCADO'], 'reason' => null];

        $payload = $this->payload($this->tools($bridge)->validatePlay('8955170000000000001'));

        $this->assertSame('alocado', $payload['play_status']);
        $this->assertSame('alocado', $bridge->playStatusWrites[42]);
    }

    #[Test]
    public function validate_play_never_infers_alocado_from_an_unknown_payload(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->byIccid['8955170000000000001'] = $this->chip(['play_status' => null]);
        $bridge->playResponse = ['ok' => true, 'payload' => ['mensagem' => 'resposta inesperada'], 'reason' => null];

        $payload = $this->payload($this->tools($bridge)->validatePlay('8955170000000000001'));

        $this->assertSame('erro', $payload['play_status']);
    }

    #[Test]
    public function validate_play_reports_an_unavailable_lookup_instead_of_failing(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->byIccid['8955170000000000001'] = $this->chip();
        $bridge->playResponse = ['ok' => false, 'payload' => [], 'reason' => 'corr-123'];

        $payload = $this->assertIsToolError(
            $this->tools($bridge)->validatePlay('8955170000000000001'),
            'play_validation_unavailable'
        );

        $this->assertSame('corr-123', $payload['correlation_id']);
        $this->assertSame([], $bridge->playStatusWrites);
    }

    // ---------------------------------------------------------------
    // assign_service
    // ---------------------------------------------------------------

    #[Test]
    public function assign_requires_confirm_true_and_does_not_touch_the_chip(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->chips[42] = $this->chip();
        $bridge->owners[7] = 500;

        $this->assertIsToolError($this->tools($bridge)->assignService(42, 7, '11999990000'), 'confirm_required');
        $this->assertSame([], $bridge->assignments);
    }

    #[Test]
    public function assign_diagnoses_each_blocking_condition(): void
    {
        $cases = [
            'chip_not_found' => [null, 500],
            'chip_archived' => [$this->chip(['arquivado_em' => '2026-01-01 00:00:00']), 500],
            'chip_not_validated_play' => [$this->chip(['play_status' => 'provisionavel']), 500],
            'chip_missing_msisdn' => [$this->chip(['msisdn' => '']), 500],
            'service_not_found' => [$this->chip(), null],
        ];

        foreach ($cases as $expected => [$chip, $owner]) {
            $bridge = new FakeChipBridge();
            if ($chip !== null) {
                $bridge->chips[42] = $chip;
            }
            if ($owner !== null) {
                $bridge->owners[7] = $owner;
            }

            $this->assertIsToolError($this->tools($bridge)->assignService(42, 7, '', true), $expected);
            $this->assertSame([], $bridge->assignments, "não pode escrever quando o diagnóstico é {$expected}");
        }
    }

    #[Test]
    public function assign_refuses_a_service_already_taken_by_another_chip(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->chips[42] = $this->chip();
        $bridge->owners[7] = 500;
        $bridge->occupant = '8955170000000000999';

        $payload = $this->assertIsToolError(
            $this->tools($bridge)->assignService(42, 7, '11999990000', true),
            'service_occupied_by_iccid'
        );

        $this->assertSame('8955170000000000999', $payload['occupied_by_iccid']);
    }

    #[Test]
    public function assign_is_idempotent_for_the_same_chip_and_service(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->chips[42] = $this->chip();
        $bridge->owners[7] = 500;

        $first = $this->payload($this->tools($bridge)->assignService(42, 7, '11999990000', true));
        $second = $this->payload($this->tools($bridge)->assignService(42, 7, '11999990000', true));

        $this->assertSame('success', $first['result']);
        $this->assertSame('success', $second['result'], 'serviceOccupant exclui o próprio chip');
        $this->assertCount(2, $bridge->assignments);
    }

    #[Test]
    public function assign_reports_a_late_repository_refusal(): void
    {
        $bridge = new FakeChipBridge();
        $bridge->chips[42] = $this->chip();
        $bridge->owners[7] = 500;
        $bridge->assignResult = false;

        $this->assertIsToolError(
            $this->tools($bridge)->assignService(42, 7, '11999990000', true),
            'assign_rejected'
        );
    }
}

/**
 * Duplo em memória da ponte. Sobrescreve tudo que toca nt_chips/Capsule; a
 * projeção continua a real, para que o teste de vazamento valha alguma coisa.
 */
final class FakeChipBridge extends ChipBridge
{
    public bool $available = true;
    public array $chips = [];
    public array $byIccid = [];
    public array $byService = [];
    public array $byClient = [];
    public array $owners = [];
    public ?string $occupant = null;
    public int $nextId = 1;
    public ?array $created = null;
    public bool $setIccidResult = true;
    public bool $assignResult = true;
    public array $iccidWrites = [];
    public array $lpaWrites = [];
    public array $playStatusWrites = [];
    public array $assignments = [];
    public array $playResponse = ['ok' => true, 'payload' => ['status' => 'alocado'], 'reason' => null];

    public function available(): bool
    {
        return $this->available;
    }

    public function find(int $chipId): ?object
    {
        return $this->chips[$chipId] ?? null;
    }

    public function findByIccid(string $iccid): ?object
    {
        return $this->byIccid[$iccid] ?? null;
    }

    public function findByServiceId(int $serviceId): array
    {
        return $this->byService[$serviceId] ?? [];
    }

    public function findByClientId(int $clientId, int $limit = 50): array
    {
        return $this->byClient[$clientId] ?? [];
    }

    public function serviceOwner(int $serviceId): ?int
    {
        return $this->owners[$serviceId] ?? null;
    }

    public function serviceOccupant(int $serviceId, int $exceptChipId = 0): ?string
    {
        return $this->occupant;
    }

    public function create(array $data): int
    {
        $this->created = $data;

        return $this->nextId;
    }

    public function setIccid(int $chipId, string $iccid): bool
    {
        if ($this->setIccidResult) {
            $this->iccidWrites[(string) $chipId] = $iccid;
        }

        return $this->setIccidResult;
    }

    public function setLpaCode(int $chipId, string $lpa): void
    {
        $this->lpaWrites[$chipId] = $lpa;
    }

    public function updatePlayStatus(int $chipId, string $status): bool
    {
        $this->playStatusWrites[$chipId] = $status;

        return true;
    }

    public function assignToService(int $chipId, int $serviceId, string $msisdn = ''): bool
    {
        if ($this->assignResult) {
            $this->assignments[] = [$chipId, $serviceId, $msisdn];
        }

        return $this->assignResult;
    }

    public function consultarStatusIccid(string $iccid): array
    {
        return $this->playResponse;
    }
}
