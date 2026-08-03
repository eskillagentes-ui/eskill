<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\QaAgent;
use App\Services\Agents\QaMergeGate;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** @covers \App\Services\Agents\QaMergeGate */
final class QaMergeGateTest extends TestCase
{
    public function testExecutaQaDiretamenteComPoliticaFixaECompleta(): void
    {
        $calls = [];
        $checks = [];
        foreach (QaMergeGate::REQUIRED_CHECK_IDS as $id) {
            $checks[$id] = static function (AgentContext $context) use (&$calls, $id): AgentResult {
                $calls[] = $id;
                return AgentResult::success($id, 'ok');
            };
        }

        (new QaMergeGate())->assertPasses(new QaAgent($checks), $this->context());

        $this->assertSame(QaMergeGate::REQUIRED_CHECK_IDS, $calls);
    }

    public function testRejeitaQaComPoliticaReduzida(): void
    {
        $qa = new QaAgent([
            'php-lint' => static fn (AgentContext $context): AgentResult =>
                AgentResult::success('php-lint', 'ok'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('qa_merge_gate_rejected');
        (new QaMergeGate())->assertPasses($qa, $this->context());
    }

    public function testRejeitaCheckObrigatorioReprovado(): void
    {
        $checks = [];
        foreach (QaMergeGate::REQUIRED_CHECK_IDS as $id) {
            $checks[$id] = static fn (AgentContext $context): AgentResult =>
                $id === 'phpunit-agents'
                    ? AgentResult::failed($id, 'failed')
                    : AgentResult::success($id, 'ok');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('qa_merge_gate_rejected');
        (new QaMergeGate())->assertPasses(new QaAgent($checks), $this->context());
    }

    private function context(): AgentContext
    {
        return new AgentContext(10, 'local', 'corr-gate', false);
    }
}
