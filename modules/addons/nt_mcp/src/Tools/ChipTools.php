<?php
// src/Tools/ChipTools.php
namespace NtMcp\Tools;

use NtMcp\Whmcs\ActivityEvent;
use NtMcp\Whmcs\AuditMetadata;
use NtMcp\Whmcs\ChipBridge;
use NtMcp\Whmcs\ChipGuard;
use NtMcp\Whmcs\LocalApiClient;
use NtMcp\Whmcs\ToolJson;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Ponte MCP para o addon nt_chips (chips físicos e eSIM da NT-Móvel).
 *
 * Diferente das demais tools, estas NÃO passam pelo `LocalApiClient`: não
 * existe comando LocalAPI para o domínio de chips, então a escrita é direta no
 * repositório do nt_chips. As duas consequências estão tratadas aqui:
 *
 *  - autorização vem do `ChipGuard`, que lê as MESMAS flags de gate;
 *  - erro de domínio vira envelope canônico (`CallToolResult::error`), nunca
 *    exceção crua — o formatter do SDK anexaria a mensagem original, e ela
 *    pode carregar SQLSTATE e path.
 *
 * `lpa_code` e as colunas `pdf_*` nunca saem em resposta nenhuma.
 */
class ChipTools
{
    private ChipBridge $chips;
    private ChipGuard $guard;

    public function __construct(?ChipBridge $chips = null, ?ChipGuard $guard = null)
    {
        $this->chips = $chips ?? new ChipBridge();
        $this->guard = $guard ?? new ChipGuard();
    }

    #[McpTool(
        name: 'whmcs_chip_find',
        description: 'Requer o addon NT Chips. Busca chips por ICCID, service_id ou client_id (informe ao menos um). '
            . 'Retorna a projeção pública: chip_id, iccid, msisdn, client_id, service_id, status, tipo, '
            . 'operadora_id, play_status e ativacao_status. Nunca retorna código LPA nem dados do PDF do eSIM.'
    )]
    #[Schema(additionalProperties: false)]
    public function find(string $iccid = '', int $service_id = 0, int $client_id = 0): string|CallToolResult
    {
        if (!$this->chips->available()) {
            return self::unavailable();
        }

        $iccid = trim($iccid);
        if ($iccid === '' && $service_id <= 0 && $client_id <= 0) {
            return self::error('missing_filter', 'Informe iccid, service_id ou client_id.');
        }

        if ($iccid !== '') {
            $chip = $this->chips->findByIccid($iccid);
            $chips = $chip === null ? [] : [$chip];
        } elseif ($service_id > 0) {
            $chips = $this->chips->findByServiceId($service_id);
        } else {
            $chips = $this->chips->findByClientId($client_id);
        }

        return ToolJson::encode([
            'result' => 'success',
            'count' => count($chips),
            'chips' => array_map(ChipBridge::project(...), $chips),
        ]);
    }

    #[McpTool(
        name: 'whmcs_chip_register',
        description: 'Requer o addon NT Chips e o gate WRITE. Cadastra um chip no estoque (status inicial disponivel). '
            . 'tipo aceita fisico ou virtual. Um ICCID já cadastrado é recusado com iccid_duplicate.'
    )]
    #[Schema(additionalProperties: false)]
    public function register(
        string $iccid,
        int $operadora_id,
        string $tipo = 'fisico',
        string $msisdn = '',
        string $imsi = ''
    ): string|CallToolResult {
        if (!$this->chips->available()) {
            return self::unavailable();
        }

        $iccid = trim($iccid);
        if ($iccid === '') {
            return self::error('missing_iccid', 'iccid é obrigatório.');
        }
        if ($operadora_id <= 0) {
            return self::error('missing_operadora', 'operadora_id é obrigatório.');
        }
        if (!in_array($tipo, ['fisico', 'virtual'], true)) {
            return self::error('invalid_tipo', "tipo deve ser 'fisico' ou 'virtual'.");
        }

        $metadata = AuditMetadata::ids(['operadora_id' => $operadora_id]);
        $this->guard->assertWriteAllowed('whmcs_chip_register', $metadata);

        if ($this->chips->findByIccid($iccid) !== null) {
            return self::error('iccid_duplicate', 'Já existe chip cadastrado com este ICCID.');
        }

        $chipId = $this->chips->create([
            'iccid' => $iccid,
            'operadora_id' => $operadora_id,
            'tipo' => $tipo,
            'msisdn' => trim($msisdn),
            'imsi' => trim($imsi),
        ]);

        LocalApiClient::auditLog(
            ActivityEvent::DB_INSERT,
            AuditMetadata::ids(['chip_id' => $chipId, 'operadora_id' => $operadora_id]),
            command: 'whmcs_chip_register'
        );

        return ToolJson::encode([
            'result' => 'success',
            'chip_id' => $chipId,
            'iccid' => $iccid,
            'tipo' => $tipo,
            'message' => 'Chip registrado no estoque.',
        ]);
    }

    #[McpTool(
        name: 'whmcs_chip_set_iccid',
        description: 'Requer o addon NT Chips e o gate WRITE. Grava o ICCID num chip físico que ainda não tem um '
            . '(placeholder criado na contratação). O nt_chips só aceita se o chip for físico, ainda não estiver '
            . 'ativado e continuar sem ICCID — caso contrário devolve iccid_not_writable.'
    )]
    #[Schema(additionalProperties: false)]
    public function setIccid(int $chip_id, string $iccid): string|CallToolResult
    {
        if (!$this->chips->available()) {
            return self::unavailable();
        }

        $iccid = trim($iccid);
        if ($chip_id <= 0 || $iccid === '') {
            return self::error('missing_parameter', 'chip_id e iccid são obrigatórios.');
        }

        $metadata = AuditMetadata::ids(['chip_id' => $chip_id]);
        $this->guard->assertWriteAllowed('whmcs_chip_set_iccid', $metadata);

        if ($this->chips->find($chip_id) === null) {
            return self::error('chip_not_found', 'Chip inexistente.', ['chip_id' => $chip_id]);
        }

        if (!$this->chips->setIccid($chip_id, $iccid)) {
            return self::error(
                'iccid_not_writable',
                'ICCID não gravado: o chip precisa ser físico, sem ICCID e sem ativação concluída.',
                ['chip_id' => $chip_id]
            );
        }

        LocalApiClient::auditLog(ActivityEvent::DB_UPDATE, $metadata, command: 'whmcs_chip_set_iccid');

        return ToolJson::encode([
            'result' => 'success',
            'chip_id' => $chip_id,
            'iccid' => $iccid,
            'message' => 'ICCID vinculado ao chip.',
        ]);
    }

    #[McpTool(
        name: 'whmcs_chip_validate_play',
        description: 'Requer o addon NT Chips e o gate WRITE. Valida o ICCID na API da Play Tecnologia e grava o '
            . 'play_status: alocado (linha existe — libera o vínculo com um serviço), provisionavel (ICCID válido '
            . 'mas linha ainda não ativada), invalido (Play não reconhece) ou erro. Exige operadora com suporte '
            . 'Play. Token ausente ou API fora do ar devolve play_validation_unavailable.'
    )]
    #[Schema(additionalProperties: false)]
    public function validatePlay(string $iccid): string|CallToolResult
    {
        if (!$this->chips->available()) {
            return self::unavailable();
        }

        $iccid = trim($iccid);
        if ($iccid === '') {
            return self::error('missing_iccid', 'iccid é obrigatório.');
        }

        $chip = $this->chips->findByIccid($iccid);
        if ($chip === null) {
            return self::error('chip_not_found', 'Nenhum chip cadastrado com este ICCID.');
        }
        $chipId = (int) $chip->id;

        $metadata = AuditMetadata::ids(['chip_id' => $chipId]);
        $this->guard->assertWriteAllowed('whmcs_chip_validate_play', $metadata);

        $lookup = $this->chips->validateAgainstPlay($chipId);
        if (!$lookup['ok']) {
            return self::error(
                'play_validation_unavailable',
                'Consulta à Play indisponível (token ausente ou falha de transporte). '
                    . 'Use o Estoque no admin para validar.',
                ['chip_id' => $chipId, 'correlation_id' => $lookup['reason']]
            );
        }

        $status = $lookup['status'];

        LocalApiClient::auditLog(ActivityEvent::DB_UPDATE, $metadata, command: 'whmcs_chip_validate_play');

        return ToolJson::encode([
            'result' => 'success',
            'chip_id' => $chipId,
            'play_status' => $status,
            'message' => match ($status) {
                'alocado' => 'ICCID alocado na Play: o chip pode ser vinculado a um serviço.',
                'provisionavel' => 'ICCID válido porém ainda não alocado — a linha precisa ser ativada antes.',
                'invalido' => 'A Play não reconhece este ICCID.',
                default => 'A Play devolveu resposta inesperada; status gravado como erro.',
            },
        ]);
    }

    #[McpTool(
        name: 'whmcs_chip_assign_service',
        description: 'Requer o addon NT Chips, o gate WRITE e confirm=true. Vincula um chip a um serviço WHMCS: '
            . 'grava client_id, service_id, msisdn e status=ativo em transação com lock. Exige chip não arquivado, '
            . 'play_status=alocado, msisdn definido (no chip ou no parâmetro) e serviço sem outro chip ativo.'
    )]
    #[Schema(additionalProperties: false)]
    public function assignService(
        int $chip_id,
        int $service_id,
        string $msisdn = '',
        bool $confirm = false
    ): string|CallToolResult {
        if (!$this->chips->available()) {
            return self::unavailable();
        }

        if ($chip_id <= 0 || $service_id <= 0) {
            return self::error('missing_parameter', 'chip_id e service_id são obrigatórios.');
        }

        $metadata = AuditMetadata::ids(['chip_id' => $chip_id, 'service_id' => $service_id]);

        if ($confirm !== true) {
            // A recusa retorna ANTES do guard, então audita aqui — senão a
            // tentativa não deixa rastro nenhum.
            LocalApiClient::auditLog(ActivityEvent::CONFIRM_REQUIRED, $metadata, command: 'whmcs_chip_assign_service');

            return self::error(
                'confirm_required',
                'Vincular chip a serviço altera o serviço do cliente: exige confirm=true.',
                ['chip_id' => $chip_id, 'service_id' => $service_id]
            );
        }

        $this->guard->assertWriteAllowed('whmcs_chip_assign_service', $metadata);

        $owner = $this->chips->serviceOwner($service_id);
        $this->guard->assertClientAllowed('whmcs_chip_assign_service', $owner, $metadata);

        $problem = $this->diagnoseAssignment($chip_id, $service_id, $owner, $msisdn);
        if ($problem !== null) {
            return $problem;
        }

        if (!$this->chips->assignToService($chip_id, $service_id, trim($msisdn))) {
            // Pré-diagnóstico passou mas a transação recusou: outra request
            // ganhou a corrida, ou a operadora do chip não suporta Play.
            return self::error(
                'assign_rejected',
                'O nt_chips recusou o vínculo. Verifique se a operadora do chip suporta Play e se o serviço '
                    . 'continua livre.',
                ['chip_id' => $chip_id, 'service_id' => $service_id]
            );
        }

        LocalApiClient::auditLog(ActivityEvent::DB_UPDATE, $metadata, command: 'whmcs_chip_assign_service');

        $chip = $this->chips->find($chip_id);

        return ToolJson::encode([
            'result' => 'success',
            'chip_id' => $chip_id,
            'service_id' => $service_id,
            'client_id' => $owner,
            'chip' => $chip === null ? null : ChipBridge::project($chip),
            'message' => 'Chip vinculado ao serviço.',
        ]);
    }

    #[McpTool(
        name: 'whmcs_chip_save_lpa',
        description: 'Requer o addon NT Chips e o gate WRITE. Grava o código LPA de ativação de um eSIM. '
            . 'Por segurança a resposta apenas confirma a gravação — o código nunca é devolvido por nenhuma tool.'
    )]
    #[Schema(additionalProperties: false)]
    public function saveLpa(int $chip_id, string $lpa_code): string|CallToolResult
    {
        if (!$this->chips->available()) {
            return self::unavailable();
        }

        $lpa_code = trim($lpa_code);
        if ($chip_id <= 0 || $lpa_code === '') {
            return self::error('missing_parameter', 'chip_id e lpa_code são obrigatórios.');
        }

        $metadata = AuditMetadata::ids(['chip_id' => $chip_id]);
        $this->guard->assertWriteAllowed('whmcs_chip_save_lpa', $metadata);

        $chip = $this->chips->find($chip_id);
        if ($chip === null) {
            return self::error('chip_not_found', 'Chip inexistente.', ['chip_id' => $chip_id]);
        }

        $this->chips->setLpaCode($chip_id, $lpa_code);

        LocalApiClient::auditLog(ActivityEvent::DB_UPDATE, $metadata, command: 'whmcs_chip_save_lpa');

        return ToolJson::encode([
            'result' => 'success',
            'chip_id' => $chip_id,
            'message' => 'Código LPA armazenado.',
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers (sem #[McpTool] — não são invocáveis)
    // ---------------------------------------------------------------

    /**
     * Pré-diagnóstico do vínculo. `assignToService()` devolve só `false` para
     * seis condições diferentes; sem isto o chamador recebe "não deu" e não
     * tem como saber o que corrigir. A corrida entre este check e a transação
     * é coberta pelo lock `FOR UPDATE` interna dela — aqui só se produz a
     * explicação.
     */
    private function diagnoseAssignment(
        int $chipId,
        int $serviceId,
        ?int $owner,
        string $msisdn
    ): ?CallToolResult {
        $ids = ['chip_id' => $chipId, 'service_id' => $serviceId];

        $chip = $this->chips->find($chipId);
        if ($chip === null) {
            return self::error('chip_not_found', 'Chip inexistente.', $ids);
        }
        if (($chip->arquivado_em ?? null) !== null) {
            return self::error('chip_archived', 'Chip arquivado não pode ser vinculado.', $ids);
        }
        if (($chip->play_status ?? null) !== 'alocado') {
            return self::error(
                'chip_not_validated_play',
                'Chip sem validação Play: rode chip_validate_play antes de vincular.',
                $ids + ['play_status' => $chip->play_status ?? null]
            );
        }
        if (trim($msisdn) === '' && trim((string) ($chip->msisdn ?? '')) === '') {
            return self::error(
                'chip_missing_msisdn',
                'Chip sem msisdn: informe o número no parâmetro msisdn.',
                $ids
            );
        }
        if ($owner === null) {
            return self::error('service_not_found', 'Serviço inexistente em tblhosting.', $ids);
        }

        $occupant = $this->chips->serviceOccupant($serviceId, $chipId);
        if ($occupant !== null) {
            return self::error(
                'service_occupied_by_iccid',
                'O serviço já tem outro chip ativo vinculado.',
                $ids + ['occupied_by_iccid' => $occupant]
            );
        }

        return null;
    }

    private static function unavailable(): CallToolResult
    {
        return self::error(
            'nt_chips_unavailable',
            'Addon NT Chips não instalado ou inativo neste WHMCS.'
        );
    }

    /** @param array<string,mixed> $extra */
    private static function error(string $code, string $message, array $extra = []): CallToolResult
    {
        return CallToolResult::error([new TextContent(ToolJson::encode([
            'result' => 'error',
            'error_code' => $code,
            'message' => $message,
        ] + $extra))]);
    }
}
