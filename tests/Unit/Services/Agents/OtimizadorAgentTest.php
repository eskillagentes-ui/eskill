<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\OtimizadorAgent;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Agents\OtimizadorAgent */
final class OtimizadorAgentTest extends TestCase
{
    use AgentSnapshotFixtures;

    public function testRecomendacaoValidaComCusto(): void
    {
        $result = (new OtimizadorAgent())->run($this->context([
            'optimizer_observation_snapshot' => $this->envelope([
                'recommendations' => [[
                    'mlb_id' => 'MLB1', 'kind' => 'ads_roas', 'recommended_roas' => 2.5,
                ]],
            ]),
            'optimizer_cost_snapshot' => $this->envelope([
                'items' => ['MLB1' => [
                    'validated' => true, 'suspicious' => false, 'cost' => 10.0,
                ]],
            ]),
        ]));
        $this->assertSame('success', $result->status());
        $this->assertTrue($result->data()['recommendations'][0]['actionable']);
        $this->assertFalse($result->stateChanged());
    }

    public function testCustoNaoValidadoBloqueia(): void
    {
        $result = (new OtimizadorAgent())->run($this->context([
            'optimizer_observation_snapshot' => $this->envelope([
                'recommendations' => [[
                    'mlb_id' => 'MLB1', 'kind' => 'ads_roas', 'recommended_roas' => 2.5,
                ]],
            ]),
            'optimizer_cost_snapshot' => $this->envelope([
                'items' => ['MLB1' => [
                    'validated' => false, 'suspicious' => true, 'cost' => 0.0,
                ]],
            ]),
        ]));
        $this->assertSame('blocked', $result->status());
        $this->assertSame('cost_validation_blocked', $result->reason());
    }

    public function testCustosParciaisComIdObservadoAusenteBloqueiaAcao(): void
    {
        $result = (new OtimizadorAgent())->run($this->context([
            'optimizer_observation_snapshot' => $this->envelope([
                'recommendations' => [[
                    'mlb_id' => 'MLB1', 'kind' => 'ads_roas', 'recommended_roas' => 2.5,
                ]],
            ]),
            'optimizer_cost_snapshot' => $this->envelope([
                'items' => [],
            ]),
        ]));
        $this->assertSame('blocked', $result->status());
    }

    public function testCustoComMlbNaoObservadoFalha(): void
    {
        $result = (new OtimizadorAgent())->run($this->context([
            'optimizer_observation_snapshot' => $this->envelope([
                'recommendations' => [[
                    'mlb_id' => 'MLB1', 'kind' => 'ads_roas', 'recommended_roas' => 2.5,
                ]],
            ]),
            'optimizer_cost_snapshot' => $this->envelope([
                'items' => [
                    'MLB1' => ['validated' => true, 'suspicious' => false, 'cost' => 1.0],
                    'MLB9' => ['validated' => true, 'suspicious' => false, 'cost' => 1.0],
                ],
            ]),
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_optimizer_cost_snapshot', $result->reason());
    }

    public function testObservationDeOutraContaFalha(): void
    {
        $result = (new OtimizadorAgent())->run($this->context([
            'optimizer_observation_snapshot' => $this->envelope([
                'recommendations' => [[
                    'mlb_id' => 'MLB1', 'kind' => 'ads_roas', 'recommended_roas' => 2.5,
                ]],
            ], 77),
            'optimizer_cost_snapshot' => $this->envelope([
                'items' => ['MLB1' => [
                    'validated' => true, 'suspicious' => false, 'cost' => 10.0,
                ]],
            ]),
        ], 10));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_optimizer_observation_snapshot', $result->reason());
    }

    public function testCostDeOutraCorrelacaoFalha(): void
    {
        $result = (new OtimizadorAgent())->run($this->context([
            'optimizer_observation_snapshot' => $this->envelope([
                'recommendations' => [[
                    'mlb_id' => 'MLB1', 'kind' => 'ads_roas', 'recommended_roas' => 2.5,
                ]],
            ]),
            'optimizer_cost_snapshot' => $this->envelope([
                'items' => ['MLB1' => [
                    'validated' => true, 'suspicious' => false, 'cost' => 10.0,
                ]],
            ], 10, 'other-corr'),
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_optimizer_cost_snapshot', $result->reason());
    }

    public function testDuplicataNaObservacaoFalha(): void
    {
        $result = (new OtimizadorAgent())->run($this->context([
            'optimizer_observation_snapshot' => $this->envelope([
                'recommendations' => [
                    ['mlb_id' => 'MLB1', 'kind' => 'ads_roas', 'recommended_roas' => 2.5],
                    ['mlb_id' => 'MLB1', 'kind' => 'ads_roas', 'recommended_roas' => 3.0],
                ],
            ]),
            'optimizer_cost_snapshot' => $this->envelope([
                'items' => ['MLB1' => [
                    'validated' => true, 'suspicious' => false, 'cost' => 10.0,
                ]],
            ]),
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_optimizer_observation_snapshot', $result->reason());
    }

    public function testCampoExtraNaRecomendacaoFalha(): void
    {
        $result = (new OtimizadorAgent())->run($this->context([
            'optimizer_observation_snapshot' => $this->envelope([
                'recommendations' => [[
                    'mlb_id' => 'MLB1',
                    'kind' => 'ads_roas',
                    'recommended_roas' => 2.5,
                    'extra' => 1,
                ]],
            ]),
            'optimizer_cost_snapshot' => $this->envelope(['items' => []]),
        ]));
        $this->assertSame('failed', $result->status());
    }
}
