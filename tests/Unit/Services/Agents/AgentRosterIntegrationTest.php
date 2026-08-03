<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentPolicy;
use App\Services\Agents\AgentResult;
use App\Services\Agents\CollectorAgent;
use App\Services\Agents\CriadorAgent;
use App\Services\Agents\FinanceiroAgent;
use App\Services\Agents\OrchestratorAgent;
use App\Services\Agents\OtimizadorAgent;
use App\Services\Agents\QaAgent;
use App\Services\Agents\QaMergeGate;
use App\Services\Agents\SentinelaAgent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Agents\OrchestratorAgent
 * @covers \App\Services\Agents\SentinelaAgent
 * @covers \App\Services\Agents\CollectorAgent
 * @covers \App\Services\Agents\FinanceiroAgent
 * @covers \App\Services\Agents\OtimizadorAgent
 * @covers \App\Services\Agents\CriadorAgent
 * @covers \App\Services\Agents\QaAgent
 */
final class AgentRosterIntegrationTest extends TestCase
{
    public function testCompoeSetePapeisNaOrdemSemEstadoOuOperacoes(): void
    {
        $sentinela = new SentinelaAgent(static function (int $accountId): array {
            return [
                'ok' => true,
                'semaforo' => 'verde',
                'risks' => [],
                'monitored' => 1,
            ];
        });
        $coletor = new CollectorAgent(static function (int $accountId): array {
            return [
                'ok' => true,
                'available' => true,
                'cached' => true,
                'stale' => false,
                'api_calls' => 0,
            ];
        });
        $financeiro = new FinanceiroAgent(static function (int $accountId): array {
            return [
                'ok' => true,
                'resumo' => [],
                'metrics' => [],
            ];
        });
        $otimizador = new OtimizadorAgent(
            static function (int $accountId): array {
                return [
                    'recommendations' => [
                        ['mlb_id' => 'MLB1', 'recommended_roas' => 2.5],
                    ],
                ];
            },
            static function (int $accountId, array $mlbIds): array {
                return [
                    'items' => [
                        'MLB1' => [
                            'validated' => true,
                            'suspicious' => false,
                            'cost' => 10.0,
                        ],
                    ],
                ];
            }
        );
        $criador = new CriadorAgent(
            static function (int $accountId, array $request): array {
                return [
                    'valid' => true,
                    'duplicate' => false,
                    'item' => ['id' => $request['source_mlb_id']],
                ];
            },
            static function (int $accountId, array $request): array {
                return ['draft' => ['id' => 'draft-roster']];
            }
        );
        $qa = new QaAgent([
            'runtime-contracts' => static function (AgentContext $context): AgentResult {
                return AgentResult::success('runtime-contracts', 'ok');
            },
        ]);
        $context = new AgentContext(
            10,
            'local',
            'corr-seven-agents',
            false,
            ['creator_request' => ['source_mlb_id' => 'MLB2']]
        );
        $orchestrator = new OrchestratorAgent(
            [$sentinela, $coletor, $financeiro, $otimizador, $criador, $qa],
            new AgentPolicy()
        );

        $result = $orchestrator->run($context);

        $this->assertSame('orquestrador', $orchestrator->name());
        $this->assertSame(
            ['sentinela', 'coletor', 'financeiro', 'otimizador', 'criador', 'qa'],
            $result->data()['order']
        );
        $this->assertCount(6, $result->data()['results']);
        foreach ($result->data()['results'] as $agentResult) {
            $this->assertInstanceOf(AgentResult::class, $agentResult);
            $this->assertSame('success', $agentResult->status());
            $this->assertFalse($agentResult->stateChanged());
            $this->assertSame([], $agentResult->emittedOps());
        }
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());

        (new QaMergeGate(['runtime-contracts']))->assertPasses($qa->run($context));
        $this->assertTrue(true);
    }
}
