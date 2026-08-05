<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentInterface;
use App\Services\Agents\AgentPolicy;
use App\Services\Agents\AgentResult;
use App\Services\Agents\OrchestratorAgent;
use PHPUnit\Framework\TestCase;

/**
 * Cobertura do OrchestratorAgent: agregacao dos results dos agents.
 *
 * Invariantes sob teste:
 *  - agents must not be empty (InvalidArgumentException)
 *  - agent name regex: ^[a-z][a-z0-9_-]{0,63}$ (InvalidArgumentException para fora)
 *  - resultado agregado bem-sucedido = todos success
 *  - se algum agent falha, agregate = failed
 *  - se algum bloqueia mas nenhum falha, agregate = blocked
 *  - mlWriteAutomation do contexto vaza para data['mlWriteAutomation']
 *  - correlationId do contexto vaza para data['correlationId']
 */
final class OrchestratorAgentTest extends TestCase
{
    private const ACCOUNT_ID = 1335;
    private const CORRELATION = 'corr-orchestrator-test';

    private function ctx(): AgentContext
    {
        return new AgentContext(self::ACCOUNT_ID, 'local', self::CORRELATION, false);
    }

    private function policy(): AgentPolicy
    {
        return new AgentPolicy();
    }

    private function newOrchestrator(array $agents): OrchestratorAgent
    {
        return new OrchestratorAgent($agents, $this->policy());
    }

    public function testConstructorRejectsEmptyAgentsList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrchestratorAgent([], $this->policy());
    }

    public function testRunAggregatesAllSuccess(): void
    {
        $orchestrator = $this->newOrchestrator([
            $this->stub('collector', AgentResult::success('collector', 'ok', ['x' => 1])),
            $this->stub('financeiro', AgentResult::success('financeiro', 'ok', ['y' => 2])),
        ]);
        $aggregate = $orchestrator->run($this->ctx());
        $this->assertSame('orquestrador', $aggregate->agent());
        $this->assertSame('success', $aggregate->status());
        $data = $aggregate->data();
        $this->assertSame(self::CORRELATION, $data['correlationId']);
        $this->assertFalse($data['mlWriteAutomation']);
        $this->assertCount(2, $data['results']);
        $this->assertCount(2, $data['results']);
    }

    public function testRunAggregateFailsWhenAnyAgentFails(): void
    {
        $orchestrator = $this->newOrchestrator([
            $this->stub('collector', AgentResult::success('collector', 'ok')),
            $this->stub('financeiro', AgentResult::failed('financeiro', 'boom')),
        ]);
        $aggregate = $orchestrator->run($this->ctx());
        $this->assertSame('failed', $aggregate->status());
    }

    public function testRunAggregateBlockedWhenNoFailuresButAtLeastOneBlocked(): void
    {
        $orchestrator = $this->newOrchestrator([
            $this->stub('collector', AgentResult::success('collector', 'ok')),
            $this->stub('financeiro', AgentResult::blocked('financeiro', 'cost_validation_blocked')),
        ]);
        $aggregate = $orchestrator->run($this->ctx());
        $this->assertSame('blocked', $aggregate->status());
    }

    public function testAgentNameWithInvalidCharactersGetsFailedResult(): void
    {
        $orchestrator = $this->newOrchestrator([
            $this->stub('BadName!', AgentResult::success('BadName!', 'ok')),
        ]);
        $aggregate = $orchestrator->run($this->ctx());
        // agent com nome fora do padrao vira 'failed' / 'agent_name_exception'
        $this->assertSame('failed', $aggregate->status());
        // verifica que o result especifico foi failed
        $data = $aggregate->data();
        $first = $data['results'][0];
        $this->assertSame('failed', $first->status());
        $this->assertSame('agent_name_exception', $first->reason());
    }

    public function testAgentThatThrowsStillProducesFailedResult(): void
    {
        $orchestrator = $this->newOrchestrator([
            $this->stub('boomagent', null, true),  // vai lancar Throwable
            $this->stub('okagent', AgentResult::success('okagent', 'fine')),
        ]);
        $aggregate = $orchestrator->run($this->ctx());
        $this->assertSame('failed', $aggregate->status());
        $data = $aggregate->data();
        $boom = $data['results'][0];
        $this->assertSame('failed', $boom->status());
        $this->assertSame('agent_exception', $boom->reason());
        $ok = $data['results'][1];
        $this->assertSame('success', $ok->status());
    }

    public function testCorrelationAndMlWriteAreReflectedInAggregateData(): void
    {
        $ctx = new AgentContext(self::ACCOUNT_ID, 'staging', self::CORRELATION, true);
        $orchestrator = $this->newOrchestrator([
            $this->stub('collector', AgentResult::success('collector', 'ok')),
        ]);
        $aggregate = $orchestrator->run($ctx);
        $data = $aggregate->data();
        $this->assertSame(self::CORRELATION, $data['correlationId']);
        $this->assertTrue($data['mlWriteAutomation']);
    }

    /**
     * Stub helper: AgentInterface com name, run() retorna o AgentResult passado,
     * ou lanca Throwable se $throw === true.
     */
    private function stub(string $name, AgentResult $result = null, bool $throw = false): AgentInterface
    {
        return new class($name, $result, $throw) implements AgentInterface {
            public function __construct(
                private string $name,
                private ?AgentResult $result,
                private bool $throw,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function run(AgentContext $context): AgentResult
            {
                if ($this->throw) {
                    throw new \RuntimeException('boom!');
                }
                if ($this->result === null) {
                    return AgentResult::success($this->name, 'ok');
                }
                return $this->result;
            }
        };
    }
}
