<?php

declare(strict_types=1);

namespace NtMcp\Tests\Tools;

use NtMcp\Tools\ProjectManagerTools;
use NtMcp\Whmcs\LocalApiClient;
use PHPUnit\Framework\TestCase;

final class ProjectManagerToolsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function capturedParams(?bool $completed): array
    {
        $captured = [];
        $api = new LocalApiClient('testadmin');
        $api->setCallable(function (string $command, array $params) use (&$captured): array {
            $this->assertSame('GetProjects', $command);
            $captured = $params;
            return ['result' => 'success', 'projects' => []];
        });

        $tools = new ProjectManagerTools($api);
        if ($completed === null) {
            $tools->listProjects();
        } else {
            $tools->listProjects(completed: $completed);
        }

        return $captured;
    }

    public function test_completed_omitted_does_not_filter_projects(): void
    {
        $this->assertArrayNotHasKey('completed', $this->capturedParams(null));
    }

    public function test_completed_false_is_forwarded_as_an_explicit_filter(): void
    {
        $params = $this->capturedParams(false);

        $this->assertArrayHasKey('completed', $params);
        $this->assertFalse($params['completed']);
    }

    public function test_completed_true_is_forwarded_as_an_explicit_filter(): void
    {
        $params = $this->capturedParams(true);

        $this->assertArrayHasKey('completed', $params);
        $this->assertTrue($params['completed']);
    }

    public function test_timer_commands_use_the_parameter_names_expected_by_each_local_api(): void
    {
        $calls = [];
        $api = new LocalApiClient('testadmin');
        $api->setGates(['write' => true]);
        $api->setAdminIdResolver(static fn(string $_username): int => 3);
        $api->setCallable(function (string $command, array $params) use (&$calls): array {
            $calls[] = ['command' => $command, 'params' => $params];
            return ['result' => 'success'];
        });
        $tools = new ProjectManagerTools($api);

        $tools->startTaskTimer(projectid: 1, taskid: 2, adminid: 3);
        $tools->endTaskTimer(projectid: 1, taskid: 2, adminid: 3);

        $this->assertSame('StartTaskTimer', $calls[0]['command']);
        $this->assertSame(
            ['projectid' => 1, 'taskid' => 2, 'adminid' => 3],
            $calls[0]['params']
        );
        $this->assertSame('EndTaskTimer', $calls[1]['command']);
        $this->assertSame(
            ['projectid' => 1, 'timerid' => 2, 'adminid' => 3],
            $calls[1]['params']
        );
        $this->assertArrayNotHasKey('taskid', $calls[1]['params']);
    }
}
