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

/** @covers \App\Services\Agents\OrchestratorAgent */
final class AgentRosterIntegrationTest extends TestCase
{
    public function testCompoePapeisSomenteComSnapshotsSemEstadoOuOperacoes(): void
    {
        $qa = [];
        foreach (QaMergeGate::REQUIRED_CHECK_IDS as $id) {
            $qa[$id] = AgentResult::success($id, 'ok');
        }
        $context = new AgentContext(10, 'local', 'corr-snapshot-roster', false, [
            'sentinela_snapshot' => [
                'ok' => true, 'semaforo' => 'verde', 'risks' => [], 'monitored' => 1,
            ],
            'collector_snapshot' => [
                'ok' => true, 'available' => true, 'cached' => true, 'stale' => false, 'api_calls' => 0,
            ],
            'financeiro_snapshot' => ['ok' => true, 'resumo' => [], 'metrics' => []],
            'optimizer_observation_snapshot' => ['recommendations' => [[
                'mlb_id' => 'MLB1', 'kind' => 'ads_roas', 'recommended_roas' => 2.5,
            ]]],
            'optimizer_cost_snapshot' => ['items' => ['MLB1' => [
                'validated' => true, 'suspicious' => false, 'cost' => 10.0,
            ]]],
            'creator_request' => ['source_mlb_id' => 'MLB2'],
            'creator_source_snapshot' => [
                'valid' => true, 'duplicate' => false, 'item' => ['id' => 'MLB2'],
            ],
            'qa_results_snapshot' => $qa,
        ]);
        $orchestrator = new OrchestratorAgent([
            new SentinelaAgent(), new CollectorAgent(), new FinanceiroAgent(),
            new OtimizadorAgent(), new CriadorAgent(), new QaAgent(),
        ], new AgentPolicy());

        $result = $orchestrator->run($context);
        $this->assertSame(
            ['sentinela', 'coletor', 'financeiro', 'otimizador', 'criador', 'qa'],
            $result->data()['order']
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
}
