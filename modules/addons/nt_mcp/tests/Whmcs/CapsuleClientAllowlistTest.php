<?php
// tests/Whmcs/CapsuleClientAllowlistTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\CapsuleClient;
use PHPUnit\Framework\TestCase;

class CapsuleClientAllowlistTest extends TestCase
{
    private CapsuleClient $client;

    protected function setUp(): void
    {
        $this->client = new CapsuleClient();
    }

    public function test_select_rejects_disallowed_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not permitted');
        $this->client->select('tbladmins');
    }

    public function test_select_rejects_core_whmcs_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not permitted');
        $this->client->select('tblconfiguration');
    }

    public function test_select_allows_crm_contacts_table(): void
    {
        try {
            $this->client->select('mod_mgcrm_contacts');
            // If we get here, validation passed (shouldn't happen without DB though)
        } catch (\InvalidArgumentException $e) {
            $this->fail('Allowlist rejected a permitted table: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Any other error (DB not available) is fine — allowlist validation passed
            $this->assertTrue(true);
        }
    }

    public function test_select_rejects_disallowed_where_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not permitted');
        $this->client->select('mod_mgcrm_contacts', ['password' => 'x']);
    }

    public function test_insert_rejects_disallowed_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not permitted');
        $this->client->insert('mod_mgcrm_contacts', ['id' => 1]);
    }

    public function test_update_rejects_disallowed_data_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not permitted');
        $this->client->update('mod_mgcrm_contacts', ['id' => 1], ['id' => 2]);
    }

    public function test_delete_rejects_empty_where(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('without WHERE');
        $this->client->delete('mod_mgcrm_contacts', []);
    }

    public function test_delete_rejects_disallowed_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not permitted');
        $this->client->delete('tbladmins', ['id' => 1]);
    }
}
