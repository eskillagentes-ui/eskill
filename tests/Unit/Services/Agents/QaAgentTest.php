<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\QaAgent;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Agents\QaAgent */
final class QaAgentTest extends TestCase
{
    public function testConsolidaResultadosDoSnapshotNaOrdem(): void
    {
        $qa = new QaAgent();
        $result = $qa->run($this->context([
            'php-lint' => AgentResult::success('php-lint', 'ok'),
            'phpunit-agents' => AgentResult::success('phpunit-agents', 'ok'),
        ]));

        $this->assertSame('success', $result->status());
        $this->assertSame(['php-lint', 'phpunit-agents'], $result->data()['order']);
        $this->assertSame(['approved' => true, 'reason' => 'approved'], $result->data()['checks']['php-lint']);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @dataProvider invalidSnapshots */
    public function testSnapshotInvalidoFalhaFechado(mixed $snapshot): void
    {
        $metadata = $snapshot === '__missing__' ? [] : ['qa_results_snapshot' => $snapshot];
        $result = (new QaAgent())->run(new AgentContext(1, 'local', 'corr-qa-invalid', false, $metadata));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_qa_results_snapshot', $result->reason());
        $this->assertSame([], $result->data());
    }

    /** @return iterable<string, array{mixed}> */
    public function invalidSnapshots(): iterable
    {
        yield 'ausente' => ['__missing__'];
        yield 'vazio' => [[]];
        yield 'id vazio' => [['' => AgentResult::success('x')]];
        yield 'resultado escalar' => [['lint' => 'success']];
        yield 'array forjado' => [['lint' => ['status' => 'success']]];
    }

    /** @dataProvider rejectedResults */
    public function testReprovaResultadoQueViolaContrato(AgentResult $candidate, string $reason): void
    {
        $result = (new QaAgent())->run($this->context(['contract' => $candidate]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('checks_failed', $result->reason());
        $this->assertSame(['approved' => false, 'reason' => $reason], $result->data()['checks']['contract']);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @return iterable<string, array{AgentResult, string}> */
    public function rejectedResults(): iterable
    {
        yield 'status' => [AgentResult::failed('contract'), 'status_not_success'];
        yield 'nome' => [AgentResult::success('other'), 'agent_mismatch'];
        yield 'estado' => [AgentResult::success('contract', 'ok', [], true), 'state_changed'];
        yield 'ops' => [AgentResult::success('contract', 'ok', [], false, ['unsafe']), 'emitted_ops'];
    }

    public function testIgnoraMetadataDeComandos(): void
    {
        $context = new AgentContext(1, 'local', 'corr-qa-command', false, [
            'commands' => ['php-lint', 'deploy'],
            'qa_results_snapshot' => ['php-lint' => AgentResult::success('php-lint')],
        ]);
        $result = (new QaAgent())->run($context);
        $this->assertSame(['php-lint'], $result->data()['order']);
        $this->assertArrayNotHasKey('commands', $result->data());
    }

    /** @param array<string, AgentResult> $snapshot */
    private function context(array $snapshot): AgentContext
    {
        return new AgentContext(10, 'local', 'corr-qa-snapshot', false, ['qa_results_snapshot' => $snapshot]);
    }
}
