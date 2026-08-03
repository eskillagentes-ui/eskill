<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\QaAgent;
use App\Services\Agents\QaMergeGate;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \App\Services\Agents\QaAgent
 * @covers \App\Services\Agents\QaMergeGate
 */
final class QaMergeGateIntegrationTest extends TestCase
{
    public function testGateExecutaQaDiretamenteERejeitaFalhaObrigatoria(): void
    {
        $checks = [];
        foreach (QaMergeGate::REQUIRED_CHECK_IDS as $id) {
            $checks[$id] = static fn (AgentContext $context): AgentResult =>
                $id === 'playwright-readonly'
                    ? AgentResult::failed($id, 'failed')
                    : AgentResult::success($id, 'ok');
        }

        $caught = null;
        try {
            (new QaMergeGate())->assertPasses(
                new QaAgent($checks),
                new AgentContext(10, 'local', 'corr-integration-gate', false)
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('qa_merge_gate_rejected', $caught->getMessage());
    }
}
