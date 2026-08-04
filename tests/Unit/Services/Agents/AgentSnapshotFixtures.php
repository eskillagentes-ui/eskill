<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\AgentRuntimeFactory;
use App\Services\Agents\QaMergeGate;
use App\Services\Agents\SnapshotEnvelope;

trait AgentSnapshotFixtures
{
    /** @param array<string, mixed> $payload */
    protected function envelope(
        array $payload,
        int $accountId = 10,
        string $correlationId = 'corr-legacy-snapshot',
        bool $allowAgentResult = false
    ): array {
        return SnapshotEnvelope::wrap($accountId, $correlationId, $payload, $allowAgentResult);
    }

    /** @param array<string, mixed> $metadata */
    protected function context(
        array $metadata,
        int $accountId = 10,
        string $correlationId = 'corr-legacy-snapshot'
    ): AgentContext {
        return new AgentContext($accountId, 'staging', $correlationId, false, $metadata);
    }

    /** @return array<string, mixed> */
    protected function validRisk(string $key = 'oauth', string $status = 'amarelo'): array
    {
        $configured = [
            'reclamacoes' => [
                'limit' => 2.0,
                'values' => ['verde' => 0.0, 'amarelo' => 1.0, 'vermelho' => 1.6],
            ],
            'atrasos' => [
                'limit' => 15.0,
                'values' => ['verde' => 0.0, 'amarelo' => 7.0, 'vermelho' => 12.0],
            ],
            'cancelamentos' => [
                'limit' => 2.5,
                'values' => ['verde' => 0.0, 'amarelo' => 1.0, 'vermelho' => 2.0],
            ],
        ];
        $pct = match ($status) {
            'vermelho' => 81.0,
            'amarelo' => 50.0,
            'nd' => null,
            default => 10.0,
        };
        $value = $status === 'nd' ? null : 1.0;
        $limit = $status === 'nd' ? null : 10.0;
        if (isset($configured[$key]) && $status !== 'nd') {
            $value = $configured[$key]['values'][$status];
            $limit = $configured[$key]['limit'];
            $pct = round(($value / $limit) * 100.0, 2);
        }

        return [
            'risk_key' => $key,
            'label' => $key,
            'value_num' => $value,
            'value_text' => $status === 'nd' ? null : 'ok',
            'limit_num' => $limit,
            'pct_of_limit' => $pct,
            'status' => $status,
            'reason' => 'test',
            'source' => 'unit',
            'meta' => null,
            'collected_at' => $status === 'nd' ? null : '2026-08-03 12:00:00',
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function validRiskGrid(string $overriddenKey = '', string $status = 'verde'): array
    {
        $keys = [
            'reputacao', 'reclamacoes', 'atrasos', 'cancelamentos', 'moderacao',
            'catalogo', 'chargeback', 'oauth', 'rate_limit', 'nf_pendente', 'queda_vendas',
        ];
        $risks = [];
        foreach ($keys as $key) {
            $riskStatus = $key === 'nf_pendente' ? 'nd' : 'verde';
            if ($key === $overriddenKey) {
                $riskStatus = $status;
            }
            $risks[] = $this->validRisk($key, $riskStatus);
        }
        return $risks;
    }

    /** @return array<string, mixed> */
    protected function validResumo(): array
    {
        $pnl = AgentRuntimeFactory::emptyPnL();

        return [
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
    }

    /** @return array<string, int|float> */
    protected function validMetrics(): array
    {
        return AgentRuntimeFactory::emptyMetrics();
    }

    /** @return array<string, AgentResult> */
    protected function fullQaResults(): array
    {
        $qa = [];
        foreach (QaMergeGate::REQUIRED_CHECK_IDS as $id) {
            $qa[$id] = AgentResult::success($id, 'ok');
        }

        return $qa;
    }
}
