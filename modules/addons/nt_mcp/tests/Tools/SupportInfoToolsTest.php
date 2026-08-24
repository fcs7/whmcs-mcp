<?php

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\SupportInfoTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class SupportInfoToolsTest extends TestCase
{
    private function makeTools(?callable $callable = null): SupportInfoTools
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable($callable ?? function (string $cmd, array $params) {
            return ['result' => 'success'];
        });
        return new SupportInfoTools($api);
    }

    public function test_get_ticket_counts_adds_department_scope_limited_true_when_filtered(): void
    {
        $tools = $this->makeTools(function () {
            return [
                'result' => 'success',
                'totaltickets' => 100,
                'filteredDepartments' => [1, 2, 3],
                'Open' => 10,
                'Customer-Reply' => 5,
            ];
        });

        $json = $tools->getTicketCounts();
        $result = json_decode($json, true);

        $this->assertTrue($result['department_scope_limited']);
        $this->assertNotEmpty($result['filteredDepartments']);
    }

    public function test_get_ticket_counts_adds_department_scope_limited_false_when_not_filtered(): void
    {
        $tools = $this->makeTools(function () {
            return [
                'result' => 'success',
                'totaltickets' => 100,
                'Open' => 10,
                'Customer-Reply' => 5,
            ];
        });

        $json = $tools->getTicketCounts();
        $result = json_decode($json, true);

        $this->assertFalse($result['department_scope_limited']);
    }

    public function test_get_support_departments_returns_json(): void
    {
        $tools = $this->makeTools(function () {
            return [
                'result' => 'success',
                'departments' => [
                    ['id' => 1, 'name' => 'Sales'],
                    ['id' => 2, 'name' => 'Support'],
                ]
            ];
        });

        $json = $tools->getSupportDepartments();
        $result = json_decode($json, true);

        $this->assertSame('success', $result['result']);
        $this->assertCount(2, $result['departments']);
    }

    public function test_get_support_statuses_returns_json(): void
    {
        $tools = $this->makeTools(function () {
            return [
                'result' => 'success',
                'statuses' => [
                    ['id' => 'Open', 'title' => 'Open'],
                    ['id' => 'Answered', 'title' => 'Answered'],
                ]
            ];
        });

        $json = $tools->getSupportStatuses();
        $result = json_decode($json, true);

        $this->assertSame('success', $result['result']);
        $this->assertCount(2, $result['statuses']);
    }
}
