<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentResult;
use App\Services\Agents\QaAgent;
use App\Services\Agents\QaMergeGate;
use App\Services\Agents\SnapshotEnvelope;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Agents\QaAgent */
final class QaAgentTest extends TestCase
{
    use AgentSnapshotFixtures;

    public function testConsolidaTodosOsChecksObrigatorios(): void
    {
        $result = (new QaAgent())->run($this->context([
            'qa_results_snapshot' => $this->envelope(
                ['results' => $this->fullQaResults()],
                10,
                'corr-legacy-snapshot',
                true
            ),
        ]));
        $this->assertSame('success', $result->status());
        $this->assertSame(QaMergeGate::REQUIRED_CHECK_IDS, $result->data()['order']);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testOnlyOneSuccessEhImpossivel(): void
    {
        $onlyOne = [
            QaMergeGate::REQUIRED_CHECK_IDS[0] => AgentResult::success(
                QaMergeGate::REQUIRED_CHECK_IDS[0],
                'ok'
            ),
        ];
        $result = (new QaAgent())->run($this->context([
            'qa_results_snapshot' => $this->envelope(
                ['results' => $onlyOne],
                10,
                'corr-legacy-snapshot',
                true
            ),
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_qa_results_snapshot', $result->reason());
    }

    public function testConjuntoParcialFalha(): void
    {
        $partial = $this->fullQaResults();
        unset($partial[QaMergeGate::REQUIRED_CHECK_IDS[3]]);
        $result = (new QaAgent())->run($this->context([
            'qa_results_snapshot' => $this->envelope(
                ['results' => $partial],
                10,
                'corr-legacy-snapshot',
                true
            ),
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_qa_results_snapshot', $result->reason());
    }

    public function testConjuntoComExtraFalha(): void
    {
        $extra = $this->fullQaResults();
        $extra['extra-check'] = AgentResult::success('extra-check', 'ok');
        $result = (new QaAgent())->run($this->context([
            'qa_results_snapshot' => $this->envelope(
                ['results' => $extra],
                10,
                'corr-legacy-snapshot',
                true
            ),
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_qa_results_snapshot', $result->reason());
    }

    public function testOrdemDiferenteFalha(): void
    {
        $ids = QaMergeGate::REQUIRED_CHECK_IDS;
        $reversed = array_reverse($ids);
        $results = [];
        foreach ($reversed as $id) {
            $results[$id] = AgentResult::success($id, 'ok');
        }
        $result = (new QaAgent())->run($this->context([
            'qa_results_snapshot' => $this->envelope(
                ['results' => $results],
                10,
                'corr-legacy-snapshot',
                true
            ),
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_qa_results_snapshot', $result->reason());
    }

    public function testOutraContaFalha(): void
    {
        $result = (new QaAgent())->run($this->context([
            'qa_results_snapshot' => $this->envelope(
                ['results' => $this->fullQaResults()],
                99,
                'corr-legacy-snapshot',
                true
            ),
        ], 10));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_qa_results_snapshot', $result->reason());
    }

    public function testOutraCorrelacaoFalha(): void
    {
        $result = (new QaAgent())->run($this->context([
            'qa_results_snapshot' => $this->envelope(
                ['results' => $this->fullQaResults()],
                10,
                'other',
                true
            ),
        ], 10, 'corr-legacy-snapshot'));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_qa_results_snapshot', $result->reason());
    }

    public function testStatusNaoSuccessFalhaChecks(): void
    {
        $qa = $this->fullQaResults();
        $qa[QaMergeGate::REQUIRED_CHECK_IDS[0]] = AgentResult::failed(
            QaMergeGate::REQUIRED_CHECK_IDS[0],
            'nope'
        );
        $result = (new QaAgent())->run($this->context([
            'qa_results_snapshot' => $this->envelope(
                ['results' => $qa],
                10,
                'corr-legacy-snapshot',
                true
            ),
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('checks_failed', $result->reason());
    }

    public function testStateChangedRejeitado(): void
    {
        $qa = $this->fullQaResults();
        $qa[QaMergeGate::REQUIRED_CHECK_IDS[1]] = AgentResult::success(
            QaMergeGate::REQUIRED_CHECK_IDS[1],
            'ok',
            [],
            true
        );
        $result = (new QaAgent())->run($this->context([
            'qa_results_snapshot' => $this->envelope(
                ['results' => $qa],
                10,
                'corr-legacy-snapshot',
                true
            ),
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('checks_failed', $result->reason());
    }

    public function testEmittedOpsRejeitado(): void
    {
        $qa = $this->fullQaResults();
        $qa[QaMergeGate::REQUIRED_CHECK_IDS[2]] = AgentResult::success(
            QaMergeGate::REQUIRED_CHECK_IDS[2],
            'ok',
            [],
            false,
            ['op']
        );
        $result = (new QaAgent())->run($this->context([
            'qa_results_snapshot' => $this->envelope(
                ['results' => $qa],
                10,
                'corr-legacy-snapshot',
                true
            ),
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('checks_failed', $result->reason());
    }
}
