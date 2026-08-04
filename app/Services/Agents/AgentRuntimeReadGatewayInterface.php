<?php

declare(strict_types=1);

namespace App\Services\Agents;

/** Fronteira mínima das únicas leituras permitidas ao runtime de agentes. */
interface AgentRuntimeReadGatewayInterface
{
    /** @return array<string, mixed> */
    public function sentinelaDashboard(int $accountId): array;

    /** @return array<string, mixed> */
    public function adsDashboard(int $accountId): array;

    /** @return array<string, mixed> */
    public function financialDashboardSummary(int $accountId): array;

    /** @return array<string, mixed> */
    public function financialMetrics(int $accountId, string $startDate, string $endDate): array;

    /** @return array<string, mixed>|null */
    public function skuCostByMlb(int $accountId, string $mlbId): ?array;

    /** @return array<string, mixed> */
    public function item(int $accountId, string $mlbId): array;
}
