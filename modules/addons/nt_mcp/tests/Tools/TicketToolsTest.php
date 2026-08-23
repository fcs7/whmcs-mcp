<?php

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\TicketTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

class TicketToolsTest extends TestCase
{
    /**
     * As tools de ticket são WRITE e o gate WRITE tem default DESLIGADO
     * (rollout read-only), então os testes de comportamento precisam habilitá-lo
     * explicitamente. COMMS fica desligado — é o default de produção.
     */
    private function makeTools(?callable $callable = null, array $gates = ['write' => true]): TicketTools
    {
        $api = new LocalApiClient('testadmin');
        $api->setGates($gates);
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

    public function test_reply_ticket_clamps_adminusername_to_token_admin(): void
    {
        // WO-2 anti-impersonation clamp: LocalApiClient forces adminusername to the
        // admin bound to the current token, regardless of what the caller supplies,
        // to prevent a caller from forging replies as an arbitrary admin.
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->replyTicket(10, 'Hello', adminusername: 'admin1');

        $this->assertSame('testadmin', $capturedParams['adminusername']);
    }

    public function test_reply_ticket_suppresses_email_by_default(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->replyTicket(10, 'Hello');

        $this->assertTrue($capturedParams['noemail'], 'default é NÃO notificar o cliente');
    }

    public function test_open_ticket_suppresses_email_by_default(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->openTicket(1, 'Subject', 'Body');

        $this->assertTrue($capturedParams['noemail'], 'default é NÃO notificar o cliente');
    }

    // ---------------------------------------------------------------
    // COMMS ortogonal: notify_client=true exige WRITE **e** COMMS.
    // ---------------------------------------------------------------

    public function test_reply_ticket_with_notify_client_blocked_when_comms_gate_off(): void
    {
        $called = false;
        $tools = $this->makeTools(function () use (&$called) {
            $called = true;
            return ['result' => 'success'];
        }, ['write' => true, 'comms' => false]);

        try {
            $tools->replyTicket(10, 'Hello', notify_client: true);
            $this->fail('notify_client=true deveria ser bloqueado sem o gate COMMS');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('COMMS gate', $e->getMessage());
        }

        $this->assertFalse($called, 'a chamada não pode chegar à LocalAPI');
    }

    public function test_open_ticket_with_notify_client_blocked_when_comms_gate_off(): void
    {
        $tools = $this->makeTools(null, ['write' => true, 'comms' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('COMMS gate');

        $tools->openTicket(1, 'Subject', 'Body', notify_client: true);
    }

    public function test_reply_ticket_with_notify_client_allowed_when_write_and_comms_on(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        }, ['write' => true, 'comms' => true]);

        $tools->replyTicket(10, 'Hello', notify_client: true);

        $this->assertArrayNotHasKey('noemail', $capturedParams, 'notificação liberada: sem bloqueio de e-mail');
    }

    public function test_notify_client_still_requires_the_primary_write_gate(): void
    {
        // COMMS ligado não substitui a classe primária: WRITE off ⇒ bloqueado.
        $tools = $this->makeTools(null, ['write' => false, 'comms' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('class WRITE disabled');

        $tools->replyTicket(10, 'Hello', notify_client: true);
    }

    public function test_reply_ticket_omits_optional_params_by_default(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->replyTicket(10, 'Hello');

        // adminusername is always injected by LocalApiClient's anti-impersonation
        // clamp (WO-2) for AddTicketReply, even when the caller supplies none.
        // noemail=true is the notification default (notify_client=false).
        $this->assertSame(
            ['ticketid' => 10, 'message' => 'Hello', 'noemail' => true, 'adminusername' => 'testadmin'],
            $capturedParams
        );
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

    public function test_reply_ticket_omits_status_by_default(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->replyTicket(10, 'test');

        $this->assertArrayNotHasKey('status', $capturedParams);
    }

    public function test_reply_ticket_sends_status_when_provided(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->replyTicket(10, 'test', status: 'Answered');

        $this->assertArrayHasKey('status', $capturedParams);
        $this->assertSame('Answered', $capturedParams['status']);
    }

    public function test_update_ticket_sends_flag_zero_to_unflag(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->updateTicket(10, flag: 0);

        $this->assertArrayHasKey('flag', $capturedParams);
        $this->assertSame(0, $capturedParams['flag']);
    }

    public function test_update_ticket_omits_flag_when_null(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success'];
        });

        $tools->updateTicket(10);

        $this->assertArrayNotHasKey('flag', $capturedParams);
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

    public function test_get_ticket_with_tid_sends_ticketnum(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success', 'ticketid' => 1, 'tid' => '084535'];
        });

        $tools->getTicket(tid: '084535');

        $this->assertArrayHasKey('ticketnum', $capturedParams);
        $this->assertSame('084535', $capturedParams['ticketnum']);
        $this->assertArrayNotHasKey('ticketid', $capturedParams);
    }

    public function test_get_ticket_with_ticketid_sends_ticketid(): void
    {
        $capturedParams = null;
        $tools = $this->makeTools(function (string $cmd, array $params) use (&$capturedParams) {
            $capturedParams = $params;
            return ['result' => 'success', 'ticketid' => 30];
        });

        $tools->getTicket(ticketid: 30);

        $this->assertArrayHasKey('ticketid', $capturedParams);
        $this->assertSame(30, $capturedParams['ticketid']);
        $this->assertArrayNotHasKey('ticketnum', $capturedParams);
    }

    public function test_get_ticket_throws_when_neither_ticketid_nor_tid_provided(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exatamente um');

        $tools->getTicket();
    }

    public function test_get_ticket_throws_when_both_ticketid_and_tid_provided(): void
    {
        $tools = $this->makeTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exatamente um');

        $tools->getTicket(ticketid: 30, tid: '084535');
    }

    public function test_list_tickets_adds_display_id_from_tid(): void
    {
        $tools = $this->makeTools(function () {
            return [
                'result' => 'success',
                'tickets' => [
                    'ticket' => [
                        ['id' => 1, 'tid' => '001234', 'subject' => 'Test 1'],
                        ['id' => 2, 'tid' => '001235', 'subject' => 'Test 2'],
                    ]
                ]
            ];
        });

        $json = $tools->listTickets();
        $result = json_decode($json, true);

        $this->assertSame('001234', $result['tickets']['ticket'][0]['display_id']);
        $this->assertSame('001235', $result['tickets']['ticket'][1]['display_id']);
    }

    public function test_get_ticket_adds_display_id_from_tid(): void
    {
        $tools = $this->makeTools(function () {
            return [
                'result' => 'success',
                'ticketid' => 30,
                'tid' => '084535',
                'subject' => 'Test ticket'
            ];
        });

        $json = $tools->getTicket(ticketid: 30);
        $result = json_decode($json, true);

        $this->assertSame('084535', $result['display_id']);
    }

    public function test_list_tickets_omits_display_id_when_tid_missing(): void
    {
        $tools = $this->makeTools(function () {
            return [
                'result' => 'success',
                'tickets' => [
                    'ticket' => [
                        ['id' => 1, 'subject' => 'Test without tid'],
                    ]
                ]
            ];
        });

        $json = $tools->listTickets();
        $result = json_decode($json, true);

        $this->assertArrayNotHasKey('display_id', $result['tickets']['ticket'][0]);
    }
}
