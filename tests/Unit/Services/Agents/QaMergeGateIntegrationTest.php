<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentPolicy;
use App\Services\Agents\AgentResult;
use App\Services\Agents\OrchestratorAgent;
use App\Services\Agents\QaAgent;
use App\Services\Agents\QaMergeGate;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \App\Services\Agents\QaAgent
 * @covers \App\Services\Agents\QaMergeGate
 * @covers \App\Services\Agents\OrchestratorAgent
 */
class QaMergeGateIntegrationTest extends TestCase
{
    public function testGateAvaliaQaDiretamenteENaoConfiaNoSuccessAgregadoDoOrchestrator(): void
    {
        $qa = new QaAgent([
            'php-lint' => static function (AgentContext $context): AgentResult {
                return AgentResult::failed('php-lint', 'lint failed');
            },
        ]);
        $context = new AgentContext(10, 'local', 'corr-integration-gate', false);
        $aggregate = (new OrchestratorAgent([$qa], new AgentPolicy()))->run($context);
        $gate = new QaMergeGate(['php-lint']);

        $this->assertSame('failed', $aggregate->status());
        $this->assertSame('orquestrador', $aggregate->agent());
        $this->assertSame('failed', $aggregate->data()['results'][0]->status());
        $this->assertSame('qa', $aggregate->data()['results'][0]->agent());

        $this->assertRejected($gate, $aggregate);
        $this->assertRejected($gate, $aggregate->data()['results'][0]);
    }

    private function assertRejected(QaMergeGate $gate, AgentResult $result): void
    {
        $caught = null;
        try {
            $gate->assertPasses($result);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('qa_merge_gate_rejected', $caught->getMessage());
    }
}
