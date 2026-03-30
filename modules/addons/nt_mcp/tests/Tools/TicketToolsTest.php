<?php

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\TicketTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class TicketToolsTest extends TestCase
{
    private function makeTools(?callable $callable = null): TicketTools
    {
        $api = new LocalApiClient('testadmin');
        $api->setCallable($callable ?? function (string $cmd, array $params) {
            return ['result' => 'success', 'ticketid' => 1];
        });
        return new TicketTools($api);
    }

    public function test_reply_ticket_sends_name_and_email(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->replyTicket(10, 'Hello', name: 'Test', email: 'test@test.com');

        $this->assertSame('Test', $capturedParams['name']);
        $this->assertSame('test@test.com', $capturedParams['email']);
        $this->assertArrayNotHasKey('adminid', $capturedParams);
    }

    public function test_reply_ticket_sends_adminusername(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->replyTicket(10, 'Hello', adminusername: 'admin1');

        $this->assertSame('admin1', $capturedParams['adminusername']);
    }

    public function test_reply_ticket_sends_noemail_when_true(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->replyTicket(10, 'Hello', noemail: true);

        $this->assertTrue($capturedParams['noemail']);
    }

    public function test_reply_ticket_omits_optional_params_by_default(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->replyTicket(10, 'Hello');

        $this->assertSame(['ticketid' => 10, 'message' => 'Hello'], $capturedParams);
    }

    public function test_open_ticket_without_clientid_uses_name_email(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success', 'ticketid' => 1];
        });

        $tools->openTicket(deptid: 1, subject: 'Test', message: 'Msg', name: 'John', email: 'john@test.com');

        $this->assertSame('John', $capturedParams['name']);
        $this->assertSame('john@test.com', $capturedParams['email']);
        $this->assertArrayNotHasKey('clientid', $capturedParams);
    }

    public function test_open_ticket_sends_serviceid(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success', 'ticketid' => 1];
        });

        $tools->openTicket(deptid: 1, subject: 'Test', message: 'Msg', clientid: 5, serviceid: 99);

        $this->assertSame(99, $capturedParams['serviceid']);
    }

    public function test_update_ticket_sends_subject_and_flag(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->updateTicket(10, subject: 'New Subject', flag: 3);

        $this->assertSame('New Subject', $capturedParams['subject']);
        $this->assertSame(3, $capturedParams['flag']);
    }

    public function test_list_tickets_sends_deptid_and_limitstart(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success', 'tickets' => []];
        });

        $tools->listTickets(deptid: 2, limitstart: 50);

        $this->assertSame(2, $capturedParams['deptid']);
        $this->assertSame(50, $capturedParams['limitstart']);
    }
}
