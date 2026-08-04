<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Services\Ads\AdsObservationService;
use App\Services\Ads\SkuCustoService;
use App\Services\FinancialService;
use App\Services\Sentinela\Sentinela;
use Throwable;

/**
 * Composition root do runtime capability-safe.
 *
 * Fronteira de I/O: chama somente serviços read-only existentes, normaliza
 * envelopes puros e instancia agentes sem dependências executáveis.
 */
final class AgentRuntimeFactory
{
    private ?Sentinela $sentinela;
    private ?FinancialService $financial;
    private ?AdsObservationService $adsObservation;
    private ?SkuCustoService $skuCustos;

    public function __construct(
        ?Sentinela $sentinela = null,
        ?FinancialService $financial = null,
        ?AdsObservationService $adsObservation = null,
        ?SkuCustoService $skuCustos = null
    ) {
        $this->sentinela = $sentinela;
        $this->financial = $financial;
        $this->adsObservation = $adsObservation;
        $this->skuCustos = $skuCustos;
    }

    /**
     * @param array{
     *   environment?: 'local'|'staging'|'production',
     *   ml_write_automation?: bool,
     *   creator_request?: array{source_mlb_id: string},
     *   creator_source_item?: array<string, mixed>,
     *   optimizer_recommendations?: list<array{mlb_id: string, kind: string, recommended_roas: float|int}>,
     *   qa_results?: array<string, AgentResult>
     * } $options
     */
    public function buildContext(
        int $accountId,
        string $correlationId,
        array $options = []
    ): AgentContext {
        $environment = $options['environment'] ?? 'local';
        $mlWrite = (bool) ($options['ml_write_automation'] ?? false);

        $metadata = [
            'sentinela_snapshot' => $this->buildSentinelaEnvelope($accountId, $correlationId),
            'collector_snapshot' => $this->buildCollectorEnvelope($accountId, $correlationId),
            'financeiro_snapshot' => $this->buildFinanceiroEnvelope($accountId, $correlationId),
        ];

        $recommendations = $options['optimizer_recommendations'] ?? $this->deriveRecommendations($accountId);
        if (is_array($recommendations) && $recommendations !== []) {
            $mlbIds = [];
            foreach ($recommendations as $row) {
                if (is_array($row) && isset($row['mlb_id']) && is_string($row['mlb_id'])) {
                    $mlbIds[] = $row['mlb_id'];
                }
            }
            $metadata['optimizer_observation_snapshot'] = SnapshotEnvelope::wrap(
                $accountId,
                $correlationId,
                ['recommendations' => array_values($recommendations)]
            );
            $metadata['optimizer_cost_snapshot'] = SnapshotEnvelope::wrap(
                $accountId,
                $correlationId,
                ['items' => $this->buildCostItems($accountId, $mlbIds)]
            );
        }

        if (isset($options['creator_request']) && is_array($options['creator_request'])) {
            $metadata['creator_request'] = PureSnapshot::normalizeArray($options['creator_request']);
            $mlbId = (string) ($options['creator_request']['source_mlb_id'] ?? '');
            $item = $options['creator_source_item'] ?? ['id' => $mlbId];
            if (is_array($item)) {
                $metadata['creator_source_snapshot'] = SnapshotEnvelope::wrap(
                    $accountId,
                    $correlationId,
                    [
                        'valid' => true,
                        'duplicate' => false,
                        'item' => $item,
                    ]
                );
            }
        }

        if (isset($options['qa_results']) && is_array($options['qa_results'])) {
            $metadata['qa_results_snapshot'] = SnapshotEnvelope::wrap(
                $accountId,
                $correlationId,
                ['results' => $options['qa_results']],
                true
            );
        }

        return new AgentContext($accountId, $environment, $correlationId, $mlWrite, $metadata);
    }

    /** @return list<AgentInterface> */
    public function createRoster(): array
    {
        return [
            new SentinelaAgent(),
            new CollectorAgent(),
            new FinanceiroAgent(),
            new OtimizadorAgent(),
            new CriadorAgent(),
            new QaAgent(),
        ];
    }

    public function createOrchestrator(): OrchestratorAgent
    {
        return new OrchestratorAgent($this->createRoster(), new AgentPolicy());
    }

    /**
     * @return array{account_id: int, correlation_id: string, payload: array<string, mixed>}
     */
    private function buildSentinelaEnvelope(int $accountId, string $correlationId): array
    {
        $payload = [
            'ok' => false,
            'semaforo' => 'verde',
            'risks' => [],
            'monitored' => 0,
        ];
        try {
            $sentinela = $this->sentinela ?? new Sentinela();
            $dash = $sentinela->getDashboard($accountId);
            $risks = [];
            foreach ($dash['risks'] ?? [] as $risk) {
                if (!is_array($risk)) {
                    continue;
                }
                $risks[] = [
                    'risk_key' => (string) ($risk['risk_key'] ?? ''),
                    'label' => (string) ($risk['label'] ?? ''),
                    'value_num' => isset($risk['value_num']) ? (is_numeric($risk['value_num']) ? (float) $risk['value_num'] : null) : null,
                    'value_text' => isset($risk['value_text']) ? (is_string($risk['value_text']) ? $risk['value_text'] : null) : null,
                    'limit_num' => isset($risk['limit_num']) ? (is_numeric($risk['limit_num']) ? (float) $risk['limit_num'] : null) : null,
                    'pct_of_limit' => isset($risk['pct_of_limit']) ? (is_numeric($risk['pct_of_limit']) ? (float) $risk['pct_of_limit'] : null) : null,
                    'status' => (string) ($risk['status'] ?? 'nd'),
                    'reason' => isset($risk['reason']) ? (is_string($risk['reason']) ? $risk['reason'] : null) : null,
                    'source' => (string) ($risk['source'] ?? ''),
                    'meta' => is_array($risk['meta'] ?? null) ? $risk['meta'] : null,
                    'collected_at' => isset($risk['collected_at']) ? (is_string($risk['collected_at']) ? $risk['collected_at'] : null) : null,
                ];
            }
            $semaforo = (string) ($dash['semaforo'] ?? 'verde');
            if (!in_array($semaforo, ['verde', 'amarelo', 'vermelho'], true)) {
                $semaforo = 'verde';
            }
            $payload = [
                'ok' => true,
                'semaforo' => $semaforo,
                'risks' => $risks,
                'monitored' => (int) ($dash['monitored'] ?? 0),
            ];
        } catch (Throwable) {
            // falha fechada no snapshot: ok=false
        }

        return SnapshotEnvelope::wrap($accountId, $correlationId, $payload);
    }

    /**
     * @return array{account_id: int, correlation_id: string, payload: array<string, mixed>}
     */
    private function buildCollectorEnvelope(int $accountId, string $correlationId): array
    {
        $payload = [
            'ok' => false,
            'available' => false,
            'cached' => false,
            'stale' => false,
            'api_calls' => 0,
        ];
        try {
            $ads = $this->adsObservation ?? new AdsObservationService();
            $dash = $ads->dashboard($accountId);
            $available = ((int) ($dash['active_campaigns'] ?? 0)) > 0 || (($dash['has_campaigns'] ?? false) === true);
            $payload = [
                'ok' => true,
                'available' => $available,
                'cached' => true,
                'stale' => false,
                'api_calls' => 0,
            ];
        } catch (Throwable) {
            // unavailable
        }

        return SnapshotEnvelope::wrap($accountId, $correlationId, $payload);
    }

    /**
     * @return array{account_id: int, correlation_id: string, payload: array<string, mixed>}
     */
    private function buildFinanceiroEnvelope(int $accountId, string $correlationId): array
    {
        $emptyPnL = self::emptyPnL();
        $payload = [
            'ok' => false,
            'resumo' => [
                'today' => $emptyPnL,
                'current_month' => $emptyPnL,
                'previous_month' => $emptyPnL,
                'variations' => [
                    'gross_revenue' => 0.0,
                    'net_profit' => 0.0,
                    'total_orders' => 0.0,
                    'avg_margin' => 0.0,
                ],
            ],
            'metrics' => self::emptyMetrics(),
        ];
        try {
            $financial = $this->financial ?? new FinancialService($accountId);
            $resumo = $financial->getDashboardSummary();
            $start = date('Y-m-01');
            $end = date('Y-m-t 23:59:59');
            $metrics = $financial->getMetrics($start, $end);
            $payload = [
                'ok' => true,
                'resumo' => [
                    'today' => self::normalizePnL($resumo['today'] ?? []),
                    'current_month' => self::normalizePnL($resumo['current_month'] ?? []),
                    'previous_month' => self::normalizePnL($resumo['previous_month'] ?? []),
                    'variations' => [
                        'gross_revenue' => (float) ($resumo['variations']['gross_revenue'] ?? 0),
                        'net_profit' => (float) ($resumo['variations']['net_profit'] ?? 0),
                        'total_orders' => (float) ($resumo['variations']['total_orders'] ?? 0),
                        'avg_margin' => (float) ($resumo['variations']['avg_margin'] ?? 0),
                    ],
                ],
                'metrics' => [
                    'total_orders' => (int) ($metrics['total_orders'] ?? 0),
                    'gross_revenue' => (float) ($metrics['gross_revenue'] ?? 0),
                    'net_profit' => (float) ($metrics['net_profit'] ?? 0),
                    'avg_ticket' => (float) ($metrics['avg_ticket'] ?? 0),
                    'avg_margin' => (float) ($metrics['avg_margin'] ?? 0),
                    'cost_rate' => (float) ($metrics['cost_rate'] ?? 0),
                    'roi' => (float) ($metrics['roi'] ?? 0),
                ],
            ];
        } catch (Throwable) {
            // ok=false
        }

        return SnapshotEnvelope::wrap($accountId, $correlationId, $payload);
    }

    /**
     * @return list<array{mlb_id: string, kind: string, recommended_roas: float}>
     */
    private function deriveRecommendations(int $accountId): array
    {
        try {
            $ads = $this->adsObservation ?? new AdsObservationService();
            $dash = $ads->dashboard($accountId);
            $out = [];
            foreach ($dash['skus'] ?? [] as $sku) {
                if (!is_array($sku)) {
                    continue;
                }
                $mlbId = isset($sku['mlb_id']) ? (string) $sku['mlb_id'] : '';
                $roas = $sku['roas_objetivo'] ?? $sku['roas'] ?? null;
                if ($mlbId === '' || !is_numeric($roas) || (float) $roas <= 0) {
                    continue;
                }
                $out[] = [
                    'mlb_id' => $mlbId,
                    'kind' => 'ads_roas',
                    'recommended_roas' => (float) $roas,
                ];
                if (count($out) >= 20) {
                    break;
                }
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<string> $mlbIds
     * @return array<string, array{validated: bool, suspicious: bool, cost: float}>
     */
    private function buildCostItems(int $accountId, array $mlbIds): array
    {
        $items = [];
        try {
            $custos = $this->skuCustos ?? new SkuCustoService();
            foreach ($mlbIds as $mlbId) {
                $row = $custos->getByMlb($accountId, $mlbId);
                if ($row === null) {
                    $items[$mlbId] = [
                        'validated' => false,
                        'suspicious' => true,
                        'cost' => 0.0,
                    ];
                    continue;
                }
                $cost = (float) ($row['custo_produto'] ?? 0);
                $items[$mlbId] = [
                    'validated' => $cost > 0,
                    'suspicious' => $cost <= 0,
                    'cost' => $cost,
                ];
            }
        } catch (Throwable) {
            foreach ($mlbIds as $mlbId) {
                $items[$mlbId] = [
                    'validated' => false,
                    'suspicious' => true,
                    'cost' => 0.0,
                ];
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizePnL(array $row): array
    {
        $empty = self::emptyPnL();
        $period = is_array($row['period'] ?? null) ? $row['period'] : $empty['period'];

        return [
            'total_orders' => (int) ($row['total_orders'] ?? 0),
            'gross_revenue' => (float) ($row['gross_revenue'] ?? 0),
            'taxes' => (float) ($row['taxes'] ?? 0),
            'net_revenue' => (float) ($row['net_revenue'] ?? 0),
            'cogs' => (float) ($row['cogs'] ?? 0),
            'commissions' => (float) ($row['commissions'] ?? 0),
            'payment_fees' => (float) ($row['payment_fees'] ?? 0),
            'fixed_fees' => (float) ($row['fixed_fees'] ?? 0),
            'shipping_cost' => (float) ($row['shipping_cost'] ?? 0),
            'discounts' => (float) ($row['discounts'] ?? 0),
            'net_profit' => (float) ($row['net_profit'] ?? 0),
            'avg_margin' => (float) ($row['avg_margin'] ?? 0),
            'period' => [
                'start' => (string) ($period['start'] ?? $empty['period']['start']),
                'end' => (string) ($period['end'] ?? $empty['period']['end']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function emptyPnL(): array
    {
        return [
            'total_orders' => 0,
            'gross_revenue' => 0.0,
            'taxes' => 0.0,
            'net_revenue' => 0.0,
            'cogs' => 0.0,
            'commissions' => 0.0,
            'payment_fees' => 0.0,
            'fixed_fees' => 0.0,
            'shipping_cost' => 0.0,
            'discounts' => 0.0,
            'net_profit' => 0.0,
            'avg_margin' => 0.0,
            'period' => [
                'start' => '1970-01-01',
                'end' => '1970-01-01',
            ],
        ];
    }

    /** @return array<string, int|float> */
    public static function emptyMetrics(): array
    {
        return [
            'total_orders' => 0,
            'gross_revenue' => 0.0,
            'net_profit' => 0.0,
            'avg_ticket' => 0.0,
            'avg_margin' => 0.0,
            'cost_rate' => 0.0,
            'roi' => 0.0,
        ];
    }
}
