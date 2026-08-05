<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AutonomousAgentService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RunAgentManualCliTest extends TestCase
{
    public function testWrapperExistsAndAcceptsGuardian(): void
    {
        $this->loadWrapper();

        $this->assertSame('guardian', \runAgentManualAgentFromOptions(['agent' => 'guardian']));
    }

    public function testMissingAgentIsRejected(): void
    {
        $this->loadWrapper();

        $this->expectException(InvalidArgumentException::class);
        \runAgentManualAgentFromOptions([]);
    }

    public function testUnsupportedAgentIsRejected(): void
    {
        $this->loadWrapper();

        $this->expectException(InvalidArgumentException::class);
        \runAgentManualAgentFromOptions(['agent' => 'inexistente']);
    }

    public function testExecutionDelegatesToProtectedAutonomousAgentService(): void
    {
        $this->loadWrapper();

        $service = $this->createMock(AutonomousAgentService::class);
        $service->expects($this->once())
            ->method('runAgent')
            ->with('sniper')
            ->willReturn([
                'success' => false,
                'skipped' => true,
                'agent' => 'sniper',
                'status' => 'paused',
            ]);

        $result = \runAgentManualExecute('sniper', static fn (): AutonomousAgentService => $service);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertSame('paused', $result['status']);
    }

    private function loadWrapper(): void
    {
        $path = dirname(__DIR__, 2) . '/bin/run-agent-manual.php';
        $this->assertFileExists($path);

        if (!defined('RUN_AGENT_MANUAL_TESTING')) {
            define('RUN_AGENT_MANUAL_TESTING', true);
        }

        require_once $path;
    }
}
