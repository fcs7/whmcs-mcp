<?php
// src/Whmcs/AuditMetadata.php
namespace NtMcp\Whmcs;

/**
 * Constrói os metadados que o Activity Log pode gravar (D7).
 *
 * O desenho anterior despejava os parâmetros com uma DENYLIST por nome de
 * campo. Isso nunca poderia funcionar: `proposal`, `notes`, `description`,
 * `customernotes`, `adminnotes`, `message` e `name` são texto livre, não estão
 * (nem podem estar) numa lista de campos sensíveis, e foi reproduzido gravando
 * segredo integral — `CreateQuote` com o segredo dentro de `proposal` escreveu
 * a string inteira, byte a byte, num log que qualquer admin do WHMCS lê.
 *
 * A inversão é a única saída: em vez de decidir o que esconder, decide-se o que
 * PODE aparecer. E os nomes dos campos vêm de allowlist ESTÁTICA — nunca de
 * `array_keys()` do payload —, senão um campo novo (ou um nome de campo
 * escolhido pelo chamador) entra no log sem passar por revisão.
 *
 * O que sai daqui:
 *   - `ids`    — valores APENAS de campos de identificador, apenas como inteiro;
 *   - `flags`  — valores APENAS de campos booleanos, apenas como bool;
 *   - `fields` — NOMES de campos enviados, apenas os que estão na allowlist;
 *   - `counts` — tamanho de campos de coleção;
 *   - `unknown_fields` — quantidade de campos fora da allowlist, sem os nomes.
 *
 * Nenhum valor de texto livre atravessa, em nenhuma hipótese.
 */
final class AuditMetadata
{
    /**
     * Campos cujo NOME pode ser registrado. Curada a partir dos parâmetros que
     * as 64 tools realmente enviam. Um campo novo aparece como
     * `unknown_fields` até ser adicionado aqui conscientemente.
     */
    private const KNOWN_FIELDS = [
        'addonid', 'address1', 'address2', 'adminid', 'adminnotes', 'adminusername',
        'catid', 'cc', 'city', 'clientid', 'companyname', 'company', 'completed',
        'confirm', 'contact_id', 'contactid', 'country', 'created', 'currency',
        'currencyid', 'customernotes', 'customfields', 'date', 'datecreated',
        'deptid', 'description', 'domainid', 'duedate', 'email', 'firstname',
        'flag', 'generalemails', 'groupid', 'id', 'ignore_dept_assignments',
        'includeCountsByStatus', 'index', 'invoiceemails', 'invoiceid', 'itemid',
        'language', 'lastmodified', 'lastname', 'limitnum', 'limitstart',
        'lineitems', 'markdown', 'message', 'name', 'noemail', 'note', 'notes',
        'orderby', 'orderid', 'password2', 'paymentmethod', 'phone', 'phonenumber',
        'pid', 'postcode', 'priority', 'productemails', 'projectid', 'proposal',
        'quoteid', 'relatedid', 'search', 'serviceid', 'sorting', 'stage', 'state',
        'status', 'subject', 'supportemails', 'task', 'taskid', 'tax_id', 'taxrate',
        'ticketid', 'ticketids', 'title', 'transid', 'type', 'user', 'userid',
        'validuntil', 'where', 'data',
    ];

    /** Campos cujo VALOR pode ser registrado — e só como inteiro. */
    private const ID_FIELDS = [
        'addonid', 'adminid', 'catid', 'clientid', 'contact_id', 'contactid',
        'currency', 'currencyid', 'deptid', 'domainid', 'groupid', 'id', 'invoiceid',
        'itemid', 'limitnum', 'limitstart', 'orderid', 'pid', 'projectid', 'quoteid',
        'relatedid', 'serviceid', 'taskid', 'ticketid', 'userid',
    ];

    /** Campos cujo VALOR pode ser registrado — e só como booleano. */
    private const FLAG_FIELDS = [
        'completed', 'confirm', 'generalemails', 'ignore_dept_assignments',
        'includeCountsByStatus', 'invoiceemails', 'markdown', 'noemail',
        'productemails', 'supportemails',
    ];

    /** Campos de coleção: registra-se o tamanho, nunca o conteúdo. */
    private const COUNT_FIELDS = ['lineitems', 'customfields', 'ticketids'];

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed> metadados seguros
     */
    public static function forParams(array $params): array
    {
        $ids = [];
        $flags = [];
        $fields = [];
        $counts = [];
        $unknown = 0;

        foreach ($params as $key => $value) {
            $name = is_string($key) ? $key : (string) $key;

            if (!in_array($name, self::KNOWN_FIELDS, true)) {
                $unknown++;
                continue;
            }

            $fields[] = $name;

            if (in_array($name, self::ID_FIELDS, true) && self::isIntish($value)) {
                $ids[$name] = (int) $value;
                continue;
            }

            if (in_array($name, self::FLAG_FIELDS, true) && self::isBoolish($value)) {
                $flags[$name] = self::toBool($value);
                continue;
            }

            if (in_array($name, self::COUNT_FIELDS, true)) {
                $counts[$name] = is_array($value) ? count($value) : (is_string($value) ? strlen($value) : 1);
            }
        }

        sort($fields);

        $metadata = [];
        if ($ids !== []) {
            ksort($ids);
            $metadata['ids'] = $ids;
        }
        if ($flags !== []) {
            ksort($flags);
            $metadata['flags'] = $flags;
        }
        if ($fields !== []) {
            $metadata['fields'] = $fields;
        }
        if ($counts !== []) {
            ksort($counts);
            $metadata['counts'] = $counts;
        }
        if ($unknown > 0) {
            $metadata['unknown_fields'] = $unknown;
        }

        return $metadata;
    }

    /**
     * Metadados de uma mutação no Capsule. `where`/`data` são achatados para os
     * mesmos metadados seguros, sem valores livres.
     *
     * @param array<string, mixed> $where
     * @param array<string, mixed> $data
     */
    public static function forTable(array $where = [], array $data = []): array
    {
        $metadata = [];

        $whereMeta = self::forParams($where);
        if ($whereMeta !== []) {
            $metadata['where'] = $whereMeta;
        }

        $dataMeta = self::forParams($data);
        if ($dataMeta !== []) {
            $metadata['data'] = $dataMeta;
        }

        return $metadata;
    }

    /** Apenas identificadores conhecidos, para logs curtos. */
    public static function ids(array $ids): array
    {
        $safe = [];
        foreach ($ids as $name => $value) {
            if (in_array((string) $name, self::ID_FIELDS, true) && self::isIntish($value)) {
                $safe[(string) $name] = (int) $value;
            }
        }
        ksort($safe);

        return $safe === [] ? [] : ['ids' => $safe];
    }

    private static function isIntish(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1);
    }

    private static function isBoolish(mixed $value): bool
    {
        return is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1';
    }

    private static function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
