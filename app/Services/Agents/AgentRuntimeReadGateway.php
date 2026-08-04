<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Services\Ads\AdsObservationService;
use App\Services\Ads\SkuCustoService;
use App\Services\FinancialService;
use App\Services\ItemService;
use App\Services\Sentinela\Sentinela;

/** Implementação production final, estritamente read-only e account-bound por chamada. */
final class AgentRuntimeReadGateway implements AgentRuntimeReadGatewayInterface
{
    public function sentinelaDashboard(int $accountId): array
    {
        return (new Sentinela())->getDashboard($accountId);
    }

    public function adsDashboard(int $accountId): array
    {
        return (new AdsObservationService())->dashboard($accountId);
    }

    public function financialDashboardSummary(int $accountId): array
    {
        return (new FinancialService($accountId))->getDashboardSummary();
    }

    public function financialMetrics(int $accountId, string $startDate, string $endDate): array
    {
        return (new FinancialService($accountId))->getMetrics($startDate, $endDate);
    }

    public function skuCostByMlb(int $accountId, string $mlbId): ?array
    {
        return (new SkuCustoService())->getByMlb($accountId, $mlbId);
    }

    public function item(int $accountId, string $mlbId): array
    {
        return (new ItemService($accountId))->getItem($mlbId, ['allow_local_cache' => false]);
    }
}
