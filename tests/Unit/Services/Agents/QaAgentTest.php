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
 * Cobertura do QaAgent no modelo "snapshot only" do framework Agents 2026-08-04.
 *
 * Migrado de callable-ports-injetados (modelo antigo) para envelope canônico
 * passado via metadata['qa_results_snapshot'].
 *
 * @covers \App\Services\Agents\QaAgent
 */
class QaAgentTest extends TestCase
{
    private const ACCOUNT_ID = 10;
    private const CORRELATION = 'corr-qa';

    /**
     * Constroi um envelope canonico com checks pre-computados.
     */
    private function envelopeWith(array $results): array
    {
        return SnapshotEnvelope::wrap(
            self::ACCOUNT_ID,
            self::CORRELATION,
            ['results' => $results],
            true
        );
    }

    private function ctx(array $extra = []): AgentContext
    {
        return new AgentContext(self::ACCOUNT_ID, 'local', self::CORRELATION, false, $extra);
    }

    private function runWithEnvelope(array $results): AgentResult
    {
        $qa = new QaAgent();
        // envelope precisa ter TODOS os REQUIRED_CHECK_IDS para passar validPayload
        $normalized = [
            'php-lint' => AgentResult::success('php-lint', 'ok'),
            'phpunit-agents' => AgentResult::success('phpunit-agents', 'ok'),
            'phpunit-unit' => AgentResult::success('phpunit-unit', 'ok'),
            'playwright-readonly' => AgentResult::success('playwright-readonly', 'ok'),
        ];
        // Aplica override do teste sobre defaults (sobrescreve com o que foi passado)
        foreach ($results as $id => $result) {
            $normalized[$id] = $result;
        }
        return $qa->run($this->ctx(['qa_results_snapshot' => $this->envelopeWith($normalized)]));
    }

    public function testExecutaChecksEmSequenciaDoEnvelopeERetornaSuccessSeguro(): void
    {
        // No modelo snapshot-only, os checks ja foram executados fora do agent.
        // REQUIRED_CHECK_IDS define o conjunto. Mesmo se agente evoluir, ids estao fixos.
        $results = [
            'php-lint' => AgentResult::success('php-lint', 'ok'),
            'phpunit-agents' => AgentResult::success('phpunit-agents', 'ok'),
            'phpunit-unit' => AgentResult::success('phpunit-unit', 'ok'),
            'playwright-readonly' => AgentResult::success('playwright-readonly', 'ok'),
        ];

        $result = $this->runWithEnvelope($results);

        $this->assertSame('qa', $result->agent());
        $this->assertSame('success', $result->status());
        $this->assertSame('all_checks_passed', $result->reason());
        $this->assertSame(
            [
                'checks' => [
                    'php-lint' => ['approved' => true, 'reason' => 'approved'],
                    'phpunit-agents' => ['approved' => true, 'reason' => 'approved'],
                    'phpunit-unit' => ['approved' => true, 'reason' => 'approved'],
                    'playwright-readonly' => ['approved' => true, 'reason' => 'approved'],
                ],
                'order' => ['php-lint', 'phpunit-agents', 'phpunit-unit', 'playwright-readonly'],
            ],
            $result->data()
        );
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testRetornaFailedQuandoEnvelopeAusente(): void
    {
        $qa = new QaAgent();
        $result = $qa->run($this->ctx());

        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_qa_results_snapshot', $result->reason());
    }

    /**
     * @dataProvider invalidEnvelopes
     */
    public function testRetornaFailedParaEnvelopeInvalido(mixed $envelope): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('qa_results_snapshot must be a provenance envelope');
        $this->ctx(['qa_results_snapshot' => $envelope]);
    }

    /** @return array<string, array{0: mixed}> */
    public function invalidEnvelopes(): array
    {
        return [
            'array sem keys estruturados' => [['some' => 'data']],
            'array com keys errados' => [[
                'account_id' => 1, 'wrong_field' => true,
            ]],
            'array sem results no payload' => [[
                'account_id' => 1,
                'correlation_id' => 'corr',
                'payload' => ['wrong_key' => []],
            ]],
        ];
    }

    /**
     * @dataProvider invalidAgentResults
     */
    public function testReprovaResultadoQueViolaContrato(AgentResult $checkResult, string $expectedReason): void
    {
        // Aplicar o AgentResult problematico em TODOS os REQUIRED_CHECK_IDS.
        // O comportamento eh o mesmo para qualquer check_id porque
        // rejectionReason() decide apenas baseado nas props do AgentResult.
        $results = [];
        foreach (QaMergeGate::REQUIRED_CHECK_IDS as $id) {
            $results[$id] = $checkResult;
        }
        $envelope = $this->envelopeWith($results);

        $qa = new QaAgent();
        $result = $qa->run($this->ctx(['qa_results_snapshot' => $envelope]));

        $this->assertSame('failed', $result->status());
        $this->assertSame('checks_failed', $result->reason());

        // O id testado deve estar no report; pegamos o primeiro REQUIRED.
        $firstId = QaMergeGate::REQUIRED_CHECK_IDS[0];
        $this->assertSame(
            ['approved' => false, 'reason' => $expectedReason],
            $result->data()['checks'][$firstId]
        );
    }

    /** @return array<string, array{0: AgentResult, 1: string}> */
    public function invalidAgentResults(): array
    {
        $id = QaMergeGate::REQUIRED_CHECK_IDS[0];
        return [
            'skipped' => [AgentResult::skipped($id, 'skip detail'), 'status_not_success'],
            'blocked' => [AgentResult::blocked($id, 'block detail'), 'status_not_success'],
            'failed' => [AgentResult::failed($id, 'failure detail'), 'status_not_success'],
            'nome divergente' => [AgentResult::success('other', 'ok'), 'agent_mismatch'],
            'mudou estado' => [AgentResult::success($id, 'ok', [], true), 'state_changed'],
            'emitiu ops' => [AgentResult::success($id, 'ok', [], false, ['op:unsafe']), 'emitted_ops'],
        ];
    }

    public function testIgnoraMetadadosExtrasEUsaSomenteChecksFornecidos(): void
    {
        $results = [
            'php-lint' => AgentResult::success('php-lint', 'captured'),
            'phpunit-agents' => AgentResult::success('phpunit-agents', 'captured'),
            'phpunit-unit' => AgentResult::success('phpunit-unit', 'captured'),
            'playwright-readonly' => AgentResult::success('playwright-readonly', 'captured'),
        ];

        $ctx = new AgentContext(
            self::ACCOUNT_ID,
            'local',
            self::CORRELATION,
            false,
            [
                'qa_results_snapshot' => $this->envelopeWith($results),
                'commands' => ['php-lint', 'phpunit-agents', 'e2e-readonly'],
            ]
        );

        $qa = new QaAgent();
        $result = $qa->run($ctx);

        $this->assertSame('success', $result->status());
        $this->assertSame(['php-lint', 'phpunit-agents', 'phpunit-unit', 'playwright-readonly'], $result->data()['order']);
        $this->assertArrayNotHasKey('commands', $result->data());
    }

    public function testImplementacaoNaoPossuiCapacidadesDeShellOuIntegracoesExternas(): void
    {
        $sources = [
            file_get_contents(__DIR__ . '/../../../../app/Services/Agents/QaAgent.php'),
            file_get_contents(__DIR__ . '/../../../../app/Services/Agents/QaMergeGate.php'),
        ];

        foreach ($sources as $source) {
            $this->assertIsString($source);
            foreach (['exec', 'system', 'shell_exec', 'passthru', 'proc_open'] as $function) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\\b' . preg_quote($function, '/') . '\\s*\\(/',
                    $source
                );
            }
            foreach (['git', 'deploy', 'MercadoLivre', 'PDO', 'Database'] as $capability) {
                $this->assertStringNotContainsString($capability, $source);
            }
        }
    }
}
