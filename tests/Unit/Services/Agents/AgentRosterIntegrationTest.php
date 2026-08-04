<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentPolicy;
use App\Services\Agents\AgentRuntimeFactory;
use App\Services\Agents\CollectorAgent;
use App\Services\Agents\CriadorAgent;
use App\Services\Agents\FinanceiroAgent;
use App\Services\Agents\OrchestratorAgent;
use App\Services\Agents\OtimizadorAgent;
use App\Services\Agents\QaAgent;
use App\Services\Agents\SentinelaAgent;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Agents\OrchestratorAgent */
/** @covers \App\Services\Agents\AgentRuntimeFactory */
final class AgentRosterIntegrationTest extends TestCase
{
    use AgentSnapshotFixtures;

    public function testCompoePapeisSomenteComSnapshotsSemEstadoOuOperacoes(): void
    {
        $pnl = AgentRuntimeFactory::emptyPnL();
        $resumo = [
            'today' => $pnl,
            'current_month' => $pnl,
            'previous_month' => $pnl,
            'variations' => [
                'gross_revenue' => 0.0,
                'net_profit' => 0.0,
                'total_orders' => 0.0,
                'avg_margin' => 0.0,
            ],
        ];
        $risks = $this->validRiskGrid();
        $context = $this->context([
            'sentinela_snapshot' => $this->envelope([
                'ok' => true, 'semaforo' => 'verde', 'risks' => $risks, 'monitored' => 10,
            ]),
            'collector_snapshot' => $this->envelope([
                'ok' => true, 'available' => true, 'cached' => true, 'stale' => false, 'api_calls' => 0,
            ]),
            'financeiro_snapshot' => $this->envelope([
                'ok' => true, 'resumo' => $resumo, 'metrics' => AgentRuntimeFactory::emptyMetrics(),
            ]),
            'optimizer_observation_snapshot' => $this->envelope(['recommendations' => [[
                'mlb_id' => 'MLB1', 'kind' => 'ads_roas', 'recommended_roas' => 2.5,
            ]]]),
            'optimizer_cost_snapshot' => $this->envelope(['items' => ['MLB1' => [
                'validated' => true, 'suspicious' => false, 'cost' => 10.0,
            ]]]),
            'creator_request' => ['source_mlb_id' => 'MLB2'],
            'creator_source_snapshot' => $this->envelope([
                'valid' => true, 'duplicate' => false, 'item' => ['id' => 'MLB2'],
            ]),
            'qa_results_snapshot' => $this->envelope(
                ['results' => $this->fullQaResults()],
                10,
                'corr-legacy-snapshot',
                true
            ),
        ]);
        $orchestrator = new OrchestratorAgent([
            new SentinelaAgent(), new CollectorAgent(), new FinanceiroAgent(),
            new OtimizadorAgent(), new CriadorAgent(), new QaAgent(),
        ], new AgentPolicy());

        $result = $orchestrator->run($context);
        $this->assertSame(
            ['sentinela', 'coletor', 'financeiro', 'otimizador', 'criador', 'qa'],
            array_map(static fn ($item): string => $item->agent(), $result->data()['results'])
        );
        $this->assertCount(6, $result->data()['results']);
        foreach ($result->data()['results'] as $agentResult) {
            $this->assertSame('success', $agentResult->status());
            $this->assertFalse($agentResult->stateChanged());
            $this->assertSame([], $agentResult->emittedOps());
        }
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testFalhaDeUmSnapshotNaoImpedeAgentesSeguintes(): void
    {
        $context = $this->context([
            'sentinela_snapshot' => $this->envelope([
                'ok' => true,
                'semaforo' => 'verde',
                'risks' => $this->validRiskGrid(),
                'monitored' => 10,
            ]),
            // collector ausente → failed
            'financeiro_snapshot' => $this->envelope([
                'ok' => true,
                'resumo' => $this->validResumo(),
                'metrics' => $this->validMetrics(),
            ]),
        ]);
        $orchestrator = new OrchestratorAgent([
            new SentinelaAgent(),
            new CollectorAgent(),
            new FinanceiroAgent(),
        ], new AgentPolicy());
        $result = $orchestrator->run($context);
        $this->assertSame(['sentinela', 'coletor', 'financeiro'], array_map(static fn ($item): string => $item->agent(), $result->data()['results']));
        $this->assertSame('success', $result->data()['results'][0]->status());
        $this->assertSame('failed', $result->data()['results'][1]->status());
        $this->assertSame('success', $result->data()['results'][2]->status());
        $this->assertSame('failed', $result->status());
    }

    public function testFactoryCriaRosterNaOrdemEsperada(): void
    {
        $factory = new AgentRuntimeFactory(new AgentRuntimeReadGatewayFake());
        $names = array_map(static fn ($a) => $a->name(), $factory->createRoster());
        $this->assertSame(
            ['sentinela', 'coletor', 'financeiro', 'otimizador', 'criador', 'qa'],
            $names
        );
    }
}
