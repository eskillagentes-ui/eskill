<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\QaAgent;
use App\Services\Agents\QaMergeGate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \App\Services\Agents\QaMergeGate
 */
class QaMergeGateTest extends TestCase
{
    public function testAceitaResultadoDiretoDoQaComTodosChecksObrigatoriosAprovados(): void
    {
        $qa = new QaAgent([
            'php-lint' => static function (AgentContext $context): AgentResult {
                return AgentResult::success('php-lint', 'ok');
            },
            'phpunit-agents' => static function (AgentContext $context): AgentResult {
                return AgentResult::success('phpunit-agents', 'ok');
            },
        ]);
        $gate = new QaMergeGate(['php-lint', 'phpunit-agents']);

        $gate->assertPasses($qa->run($this->context()));

        $this->assertTrue(true);
    }

    /**
     * @dataProvider invalidRequiredIds
     * @param array<int, mixed> $requiredIds
     */
    public function testRejeitaIdsObrigatoriosInvalidos(array $requiredIds): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QaMergeGate($requiredIds);
    }

    /** @return array<string, array{0: array<int, mixed>}> */
    public function invalidRequiredIds(): array
    {
        return [
            'lista vazia' => [[]],
            'id vazio' => [['']],
            'id em branco' => [['   ']],
            'id nao string' => [[10]],
            'id duplicado' => [['php-lint', 'php-lint']],
        ];
    }

    /** @dataProvider rejectedResults */
    public function testReprovaDeModoGenericoQualquerResultadoInseguro(AgentResult $result): void
    {
        $gate = new QaMergeGate(['php-lint', 'phpunit-agents']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('qa_merge_gate_rejected');

        $gate->assertPasses($result);
    }

    /** @return array<string, array{0: AgentResult}> */
    public function rejectedResults(): array
    {
        $approved = ['approved' => true, 'reason' => 'approved'];
        $valid = $this->payload([
            'php-lint' => $approved,
            'phpunit-agents' => $approved,
        ], ['php-lint', 'phpunit-agents']);

        return [
            'agente nao qa' => [AgentResult::success('orquestrador', 'aggregated', $valid)],
            'status nao success' => [AgentResult::failed('qa', 'checks_failed', $valid)],
            'mudou estado' => [AgentResult::success('qa', 'ok', $valid, true)],
            'emitiu ops' => [AgentResult::success('qa', 'ok', $valid, false, ['op:unsafe'])],
            'payload vazio' => [AgentResult::success('qa', 'ok', [])],
            'checks nao array' => [AgentResult::success('qa', 'ok', ['checks' => 'invalid', 'order' => []])],
            'order nao array' => [AgentResult::success('qa', 'ok', ['checks' => [], 'order' => 'invalid'])],
            'id obrigatorio ausente' => [AgentResult::success('qa', 'ok', $this->payload([
                'php-lint' => $approved,
            ], ['php-lint']))],
            'check reprovado' => [AgentResult::success('qa', 'ok', $this->payload([
                'php-lint' => $approved,
                'phpunit-agents' => ['approved' => false, 'reason' => 'status_not_success'],
            ], ['php-lint', 'phpunit-agents']))],
            'report nao array' => [AgentResult::success('qa', 'ok', $this->payload([
                'php-lint' => $approved,
                'phpunit-agents' => 'invalid',
            ], ['php-lint', 'phpunit-agents']))],
            'approved nao bool' => [AgentResult::success('qa', 'ok', $this->payload([
                'php-lint' => $approved,
                'phpunit-agents' => ['approved' => 1, 'reason' => 'approved'],
            ], ['php-lint', 'phpunit-agents']))],
            'reason ausente' => [AgentResult::success('qa', 'ok', $this->payload([
                'php-lint' => $approved,
                'phpunit-agents' => ['approved' => true],
            ], ['php-lint', 'phpunit-agents']))],
            'campo inesperado' => [AgentResult::success('qa', 'ok', $this->payload([
                'php-lint' => $approved,
                'phpunit-agents' => ['approved' => true, 'reason' => 'approved', 'detail' => 'extra'],
            ], ['php-lint', 'phpunit-agents']))],
            'order nao lista' => [AgentResult::success('qa', 'ok', $this->payload([
                'php-lint' => $approved,
                'phpunit-agents' => $approved,
            ], [1 => 'php-lint', 2 => 'phpunit-agents']))],
            'order diverge de checks' => [AgentResult::success('qa', 'ok', $this->payload([
                'php-lint' => $approved,
                'phpunit-agents' => $approved,
            ], ['phpunit-agents', 'php-lint']))],
            'check adicional reprovado' => [AgentResult::success('qa', 'ok', $this->payload([
                'php-lint' => $approved,
                'phpunit-agents' => $approved,
                'e2e-readonly' => ['approved' => false, 'reason' => 'status_not_success'],
            ], ['php-lint', 'phpunit-agents', 'e2e-readonly']))],
        ];
    }

    /**
     * @param array<mixed, mixed> $checks
     * @param array<mixed, mixed> $order
     * @return array<string, mixed>
     */
    private function payload(array $checks, array $order): array
    {
        return ['checks' => $checks, 'order' => $order];
    }

    private function context(): AgentContext
    {
        return new AgentContext(10, 'local', 'corr-gate', false);
    }
}
