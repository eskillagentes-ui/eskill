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
        return [
            'risk_key' => $key,
            'label' => $key,
            'value_num' => 1.0,
            'value_text' => 'ok',
            'limit_num' => 2.0,
            'pct_of_limit' => 50.0,
            'status' => $status,
            'reason' => 'test',
            'source' => 'unit',
            'meta' => null,
            'collected_at' => '2026-08-03 12:00:00',
        ];
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
