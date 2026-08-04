<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentRuntimeFactory;
use App\Services\Agents\AgentRuntimeReadGatewayInterface;

final class AgentRuntimeReadGatewayFake implements AgentRuntimeReadGatewayInterface
{
    public array $sentinela;
    public array $ads;
    public array $financialSummary;
    public array $financialMetrics;
    public ?array $skuCost = ['custo_produto' => 10.0];
    public array $itemResult = [];
    public array $calls = [];

    public function __construct()
    {
        $this->sentinela = [
            'semaforo' => 'verde',
            'monitored' => 1,
            'risks' => [[
                'risk_key' => 'oauth', 'label' => 'OAuth',
                'value_num' => 1.0, 'value_text' => 'ok',
                'limit_num' => 2.0, 'pct_of_limit' => 50.0,
                'status' => 'verde', 'reason' => 'ok', 'source' => 'unit',
                'meta' => null, 'collected_at' => '2026-08-03 12:00:00',
            ]],
        ];
        $this->ads = [
            'read_only' => true,
            'active_campaigns' => 1,
            'has_campaigns' => true,
            'campaigns' => [['campaign_id' => 'c1', 'status' => 'active']],
            'skus' => [['mlb_id' => 'MLB1', 'roas_objetivo' => 2.0]],
        ];
        $pnl = AgentRuntimeFactory::emptyPnL();
        $this->financialSummary = [
            'today' => $pnl, 'current_month' => $pnl, 'previous_month' => $pnl,
            'variations' => [
                'gross_revenue' => 0.0, 'net_profit' => 0.0,
                'total_orders' => 0.0, 'avg_margin' => 0.0,
            ],
        ];
        $this->financialMetrics = AgentRuntimeFactory::emptyMetrics();
    }

    public function sentinelaDashboard(int $accountId): array
    {
        $this->calls[] = ['sentinela', $accountId];
        return $this->sentinela;
    }

    public function adsDashboard(int $accountId): array
    {
        $this->calls[] = ['ads', $accountId];
        return $this->ads;
    }

    public function financialDashboardSummary(int $accountId): array
    {
        $this->calls[] = ['financial-summary', $accountId];
        return $this->financialSummary;
    }

    public function financialMetrics(int $accountId, string $startDate, string $endDate): array
    {
        $this->calls[] = ['financial-metrics', $accountId, $startDate . '|' . $endDate];
        return $this->financialMetrics;
    }

    public function skuCostByMlb(int $accountId, string $mlbId): ?array
    {
        $this->calls[] = ['sku-cost', $accountId, $mlbId];
        return $this->skuCost;
    }

    public function item(int $accountId, string $mlbId): array
    {
        $this->calls[] = ['item', $accountId, $mlbId];
        return $this->itemResult === [] ? ['id' => $mlbId, 'title' => 'Fonte real'] : $this->itemResult;
    }
}
