<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Crm\CrmSchema;
use NtMcp\Crm\CrmSchemaGuard;
use NtMcp\Crm\MgCrmRepository;
use NtMcp\Tests\Support\FakeAdminIdentityResolver;
use NtMcp\Tests\Support\FakeCrmQueryPort;
use NtMcp\Tests\Support\FakeCrmSchemaProbe;
use NtMcp\Tools\CrmTools;
use NtMcp\Whmcs\CapsuleClient;
use PHPUnit\Framework\TestCase;

/**
 * Regressão (2026-08-23): as tools LocalAPI usam `limitnum` (nome real do
 * parâmetro WHMCS); as tools de CRM usam `limit` (contrato próprio,
 * `CrmSelect`). Um cliente que manda `limitnum` pro CRM tomava rejeição de
 * schema (`additionalProperties: false`) sem pista do motivo. `limitnum`
 * agora é aceito como sinônimo — quando presente, vence sobre `limit`.
 */
class CrmToolsLimitAliasTest extends TestCase
{
    private function tools(): CrmTools
    {
        $repo = new MgCrmRepository(
            new CrmSchemaGuard(FakeCrmSchemaProbe::healthy()),
            new FakeCrmQueryPort(),
            FakeAdminIdentityResolver::resolvingTo(7),
        );

        return new CrmTools(new CapsuleClient(), $repo);
    }

    public function test_list_contacts_uses_limitnum_when_provided(): void
    {
        $result = json_decode((string) $this->tools()->listContacts(limit: 25, limitnum: 7), true);

        $this->assertSame(7, $result['limit']);
    }

    public function test_list_contacts_falls_back_to_limit_when_limitnum_absent(): void
    {
        $result = json_decode((string) $this->tools()->listContacts(limit: 10), true);

        $this->assertSame(10, $result['limit']);
    }

    public function test_list_followups_uses_limitnum_when_provided(): void
    {
        $repo = new MgCrmRepository(
            new CrmSchemaGuard(FakeCrmSchemaProbe::healthy()),
            $port = new FakeCrmQueryPort(),
            FakeAdminIdentityResolver::resolvingTo(7),
        );
        $port->seed(CrmSchema::TABLE_RESOURCES, [['id' => 1, 'deleted_at' => null]]);
        $tools = new CrmTools(new CapsuleClient(), $repo);

        $result = json_decode(
            (string) $tools->listFollowups(resource_id: 1, limit: 25, limitnum: 5),
            true
        );

        $this->assertSame(5, $result['limit']);
    }
}
