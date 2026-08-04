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
    public ?array $skuCost = null;
    public ?array $itemResult = null;
    public array $calls = [];

    public function __construct()
    {
        $keys = [
            'reputacao', 'reclamacoes', 'atrasos', 'cancelamentos', 'moderacao',
            'catalogo', 'chargeback', 'oauth', 'rate_limit', 'nf_pendente', 'queda_vendas',
        ];
        $risks = [];
        foreach ($keys as $key) {
            $nd = $key === 'nf_pendente';
            $risks[] = [
                'risk_key' => $key, 'label' => $key,
                'value_num' => $nd ? null : 1.0, 'value_text' => $nd ? null : 'ok',
                'limit_num' => $nd ? null : 10.0, 'pct_of_limit' => $nd ? null : 10.0,
                'status' => $nd ? 'nd' : 'verde', 'reason' => 'ok', 'source' => 'unit',
                'meta' => null, 'collected_at' => $nd ? null : '2026-08-03 12:00:00',
            ];
        }
        $this->sentinela = ['semaforo' => 'verde', 'monitored' => 10, 'risks' => $risks];
        $this->ads = [
            'read_only' => true,
            'active_campaigns' => 1,
            'has_campaigns' => true,
            'campaigns' => [['campaign_id' => 'c1', 'status' => 'active']],
            'skus' => [[
                'mlb_id' => 'MLB1', 'gasto' => 10.0, 'impressoes' => 100,
                'cliques' => 10, 'cpc' => 1.0, 'vendas_atribuidas' => 2,
                'acos' => 20.0, 'roas_real' => 5.0, 'roas_objetivo' => 3.0,
                'roas_breakeven' => 2.0, 'roas_escala' => 4.0,
                'margem_liquida_pct' => 50.0, 'has_custo' => true,
                'health' => 0.95, 'semaforo' => 'verde',
            ]],
        ];
        $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
        $pnl = AgentRuntimeFactory::emptyPnL();
        $current = $pnl;
        $current['period'] = [
            'start' => $now->format('Y-m-01'),
            'end' => $now->format('Y-m-t 23:59:59'),
        ];
        $this->financialSummary = [
            'today' => $pnl, 'current_month' => $current, 'previous_month' => $pnl,
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
        return $this->skuCost ?? [
            'account_id' => (string) $accountId,
            'mlb_id' => $mlbId,
            'custo_produto' => '10.00',
        ];
    }

    public function item(int $accountId, string $mlbId): array
    {
        $this->calls[] = ['item', $accountId, $mlbId];
        return $this->itemResult ?? [
            'account_id' => $accountId,
            'mlb_id' => $mlbId,
            'seller_id' => '123456',
            'title' => 'Fonte real',
            'duplicate' => false,
        ];
    }
}
