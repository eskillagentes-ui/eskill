<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\OtimizadorAgent;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Agents\OtimizadorAgent */
final class OtimizadorAgentTest extends TestCase
{
    public function testRecomendacaoValidadaPermaneceReadOnly(): void
    {
        $result = (new OtimizadorAgent())->run($this->context(
            ['mlb_id' => 'MLB123', 'kind' => 'ads_roas', 'recommended_roas' => 3.25],
            ['MLB123' => ['validated' => true, 'suspicious' => false, 'cost' => 100.0]]
        ));

        $this->assertSame('success', $result->status());
        $this->assertSame(3.25, $result->data()['recommendations'][0]['recommended_roas']);
        $this->assertTrue($result->data()['recommendations'][0]['actionable']);
        $this->assertFalse($result->data()['recommendations'][0]['blocked']);
        $this->assertTrue($result->data()['read_only']);
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @dataProvider nonActionableCosts */
    public function testCustoAusenteSuspeitoOuNaoValidadoBloqueia(array $items): void
    {
        $result = (new OtimizadorAgent())->run($this->context(
            ['mlb_id' => 'MLB999', 'kind' => 'ads_roas', 'recommended_roas' => 9.99],
            $items
        ));
        $this->assertSame('blocked', $result->status());
        $this->assertSame([
            'mlb_id' => 'MLB999', 'actionable' => false, 'blocked' => true,
            'blocked_reason' => 'cost_not_validated',
        ], $result->data()['recommendations'][0]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function nonActionableCosts(): iterable
    {
        yield 'ausente' => [[]];
        yield 'suspeito' => [['MLB999' => ['validated' => true, 'suspicious' => true, 'cost' => 90.0]]];
        yield 'nao validado' => [['MLB999' => ['validated' => false, 'suspicious' => false, 'cost' => 90.0]]];
        yield 'zero' => [['MLB999' => ['validated' => true, 'suspicious' => false, 'cost' => 0.0]]];
    }

    /** @dataProvider malformedSnapshots */
    public function testSchemasRejeitamCamposExtrasEFormasInvalidas(array $metadata, string $reason): void
    {
        $result = (new OtimizadorAgent())->run(new AgentContext(1, 'local', 'corr-opt-invalid', false, $metadata));
        $this->assertSame('failed', $result->status());
        $this->assertSame($reason, $result->reason());
        $this->assertSame([], $result->data());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public function malformedSnapshots(): iterable
    {
        $recommendation = ['mlb_id' => 'MLB1', 'kind' => 'ads_roas', 'recommended_roas' => 2.0];
        $cost = ['validated' => true, 'suspicious' => false, 'cost' => 10.0];
        yield 'observacao ausente' => [[], 'invalid_optimizer_observation_snapshot'];
        yield 'top-level observacao extra' => [[
            'optimizer_observation_snapshot' => ['recommendations' => [$recommendation], 'status' => 200],
            'optimizer_cost_snapshot' => ['items' => ['MLB1' => $cost]],
        ], 'invalid_optimizer_observation_snapshot'];
        yield 'item observacao extra' => [[
            'optimizer_observation_snapshot' => ['recommendations' => [array_merge($recommendation, ['action' => 'publish'])]],
            'optimizer_cost_snapshot' => ['items' => ['MLB1' => $cost]],
        ], 'invalid_optimizer_observation_snapshot'];
        yield 'top-level custo extra' => [[
            'optimizer_observation_snapshot' => ['recommendations' => [$recommendation]],
            'optimizer_cost_snapshot' => ['items' => ['MLB1' => $cost], 'source' => 'db'],
        ], 'invalid_optimizer_cost_snapshot'];
        yield 'item custo extra' => [[
            'optimizer_observation_snapshot' => ['recommendations' => [$recommendation]],
            'optimizer_cost_snapshot' => ['items' => ['MLB1' => array_merge($cost, ['raw' => 10])]],
        ], 'invalid_optimizer_cost_snapshot'];
        yield 'custo de id nao observado' => [[
            'optimizer_observation_snapshot' => ['recommendations' => [$recommendation]],
            'optimizer_cost_snapshot' => ['items' => ['MLB2' => $cost]],
        ], 'invalid_optimizer_cost_snapshot'];
        yield 'roas operacional' => [[
            'optimizer_observation_snapshot' => ['recommendations' => [array_merge($recommendation, ['kind' => 'publish'])]],
            'optimizer_cost_snapshot' => ['items' => []],
        ], 'invalid_optimizer_observation_snapshot'];
    }

    private function context(array $recommendation, array $items): AgentContext
    {
        return new AgentContext(77, 'staging', 'corr-opt-snapshot', false, [
            'optimizer_observation_snapshot' => ['recommendations' => [$recommendation]],
            'optimizer_cost_snapshot' => ['items' => $items],
        ]);
    }
}
