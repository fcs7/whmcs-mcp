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
}
