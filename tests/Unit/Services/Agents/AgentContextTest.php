<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Agents\AgentContext
 */
class AgentContextTest extends TestCase
{
    public function testCriaContextoValidoComDefaults(): void
    {
        $ctx = new AgentContext(
            accountId: 10,
            environment: 'local',
            correlationId: 'corr-abc-1',
            mlWriteAutomation: false
        );

        $this->assertSame(10, $ctx->accountId());
        $this->assertSame('local', $ctx->environment());
        $this->assertSame('corr-abc-1', $ctx->correlationId());
        $this->assertFalse($ctx->mlWriteAutomation());
        $this->assertSame([], $ctx->metadata());
    }

    public function testAceitaEnvironmentsPermitidos(): void
    {
        foreach (['local', 'staging', 'production'] as $env) {
            $ctx = new AgentContext(1, $env, 'corr-1', false, ['k' => 'v']);
            $this->assertSame($env, $ctx->environment());
            $this->assertSame(['k' => 'v'], $ctx->metadata());
        }
    }

    public function testRejeitaAccountIdZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentContext(0, 'local', 'corr-1', false);
    }

    public function testRejeitaAccountIdNegativo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentContext(-1, 'local', 'corr-1', false);
    }

    public function testRejeitaEnvironmentInvalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentContext(1, 'prod', 'corr-1', false);
    }

    public function testRejeitaCorrelationIdVazio(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentContext(1, 'local', '', false);
    }

    public function testRejeitaCorrelationIdSomenteEspacos(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentContext(1, 'local', '   ', false);
    }

    public function testMlWriteAutomationTipadoComoBool(): void
    {
        $on = new AgentContext(1, 'staging', 'corr-on', true);
        $off = new AgentContext(1, 'staging', 'corr-off', false);

        $this->assertTrue($on->mlWriteAutomation());
        $this->assertFalse($off->mlWriteAutomation());
    }
}
