<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\QaAgent;
use App\Services\Agents\QaMergeGate;
use App\Services\Agents\SnapshotEnvelope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \App\Services\Agents\QaAgent
 * @covers \App\Services\Agents\QaMergeGate
 */
final class QaMergeGateIntegrationTest extends TestCase
{
    public function testResultadoQaFabricadoNaoSatisfazGateSemEvidenciaDoProcesso(): void
    {
        $variables = [
            'QA_GATE_PHP_LINT',
            'QA_GATE_PHPUNIT_AGENTS',
            'QA_GATE_PHPUNIT_UNIT',
            'QA_GATE_PLAYWRIGHT_READONLY',
        ];
        $original = [];
        foreach ($variables as $variable) {
            $original[$variable] = getenv($variable);
            putenv($variable);
        }

        try {
            // Migrado para o modelo "snapshot only" do framework Agents 2026-08-04.
            // Antes: new QaAgent($checks) com callable ports injetados.
            // Agora: passar checks via metadata['qa_results_snapshot'] como
            // envelope canônico (account_id/correlation_id/payload).
            $results = [];
            foreach (QaMergeGate::REQUIRED_CHECK_IDS as $id) {
                // Forjamos success para todos os checks - propositalmente
                // vulneraveis - mas o gate precisa de evidencia externa.
                $results[$id] = AgentResult::success($id, 'forged_success');
            }
            // allowAgentResult=true eh essencial: AgentResult instances soh sao
            // permitidas dentro de qa_results_snapshot.results.
            $envelope = SnapshotEnvelope::wrap(
                1,
                'qa-forgery-test',
                ['results' => $results],
                true
            );

            $forgedQaResult = (new QaAgent())->run(
                new AgentContext(1, 'local', 'qa-forgery-test', false, [
                    'qa_results_snapshot' => $envelope,
                ])
            );
            self::assertSame('success', $forgedQaResult->status());

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('qa_merge_gate_rejected');
            (new QaMergeGate())->assertPasses();
        } finally {
            foreach ($original as $variable => $value) {
                putenv($value === false ? $variable : $variable . '=' . $value);
            }
        }
    }
}
