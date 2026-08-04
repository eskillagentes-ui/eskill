<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentRuntimeFactory;
use App\Services\Agents\QaMergeGate;
use App\Services\Agents\SnapshotEnvelope;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Agents\AgentRuntimeFactory */
final class AgentRuntimeFactoryTest extends TestCase
{
    use AgentSnapshotFixtures;

    public function testBuildContextGeraEnvelopesComAccountECorrelation(): void
    {
        $factory = new AgentRuntimeFactory();
        $ctx = $factory->buildContext(1335, 'corr-factory-1', [
            'environment' => 'local',
            'creator_request' => ['source_mlb_id' => 'MLB999'],
            'creator_source_item' => ['id' => 'MLB999'],
            'optimizer_recommendations' => [[
                'mlb_id' => 'MLB1',
                'kind' => 'ads_roas',
                'recommended_roas' => 2.0,
            ]],
            'qa_results' => $this->fullQaResults(),
        ]);

        $this->assertSame(1335, $ctx->accountId());
        $this->assertSame('corr-factory-1', $ctx->correlationId());

        foreach ([
            'sentinela_snapshot',
            'collector_snapshot',
            'financeiro_snapshot',
            'optimizer_observation_snapshot',
            'optimizer_cost_snapshot',
            'creator_source_snapshot',
            'qa_results_snapshot',
        ] as $key) {
            $this->assertArrayHasKey($key, $ctx->metadata(), $key);
            $envelope = $ctx->metadata()[$key];
            $this->assertSame(1335, $envelope['account_id'], $key);
            $this->assertSame('corr-factory-1', $envelope['correlation_id'], $key);
            $this->assertArrayHasKey('payload', $envelope, $key);
            $this->assertCount(3, $envelope, $key);
        }

        $this->assertSame(['source_mlb_id' => 'MLB999'], $ctx->metadata()['creator_request']);
        $qa = SnapshotEnvelope::extract(
            $ctx->metadata()['qa_results_snapshot'],
            1335,
            'corr-factory-1',
            true
        );
        $this->assertNotNull($qa);
        $this->assertSame(QaMergeGate::REQUIRED_CHECK_IDS, array_keys($qa['results']));
    }

    public function testOrchestratorDaFactoryExecutaSemEstado(): void
    {
        $factory = new AgentRuntimeFactory();
        $ctx = $factory->buildContext(10, 'corr-orch', [
            'optimizer_recommendations' => [[
                'mlb_id' => 'MLB1',
                'kind' => 'ads_roas',
                'recommended_roas' => 2.0,
            ]],
            'creator_request' => ['source_mlb_id' => 'MLB2'],
            'creator_source_item' => ['id' => 'MLB2'],
            'qa_results' => $this->fullQaResults(),
        ]);
        $result = $factory->createOrchestrator()->run($ctx);
        $this->assertSame(
            ['sentinela', 'coletor', 'financeiro', 'otimizador', 'criador', 'qa'],
            $result->data()['order']
        );
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }
}
