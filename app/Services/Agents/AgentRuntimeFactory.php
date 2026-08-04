<?php

declare(strict_types=1);

namespace App\Services\Agents;

use InvalidArgumentException;
use Throwable;

/**
 * Composition root capability-safe: somente lê pelo gateway estreito,
 * valida respostas fail-closed e entrega snapshots puros aos agentes.
 */
final class AgentRuntimeFactory
{
    private const ALLOWED_OPTIONS = ['environment', 'creator_request'];
    private const ENVIRONMENTS = ['local', 'staging', 'production'];
    private const RISK_KEYS = [
        'reputacao', 'reclamacoes', 'atrasos', 'cancelamentos', 'moderacao',
        'catalogo', 'chargeback', 'oauth', 'rate_limit', 'nf_pendente',
        'queda_vendas',
    ];
    private const RISK_FIELDS = [
        'risk_key', 'label', 'value_num', 'value_text', 'limit_num',
        'pct_of_limit', 'status', 'reason', 'source', 'meta', 'collected_at',
    ];
    private const PNL_KEYS = [
        'total_orders', 'gross_revenue', 'taxes', 'net_revenue', 'cogs',
        'commissions', 'payment_fees', 'fixed_fees', 'shipping_cost', 'discounts',
        'net_profit', 'avg_margin', 'period',
    ];
    private const VARIATION_KEYS = ['gross_revenue', 'net_profit', 'total_orders', 'avg_margin'];
    private const METRICS_KEYS = [
        'total_orders', 'gross_revenue', 'net_profit', 'avg_ticket',
        'avg_margin', 'cost_rate', 'roi',
    ];

    private AgentRuntimeReadGatewayInterface $gateway;

    public function __construct(?AgentRuntimeReadGatewayInterface $gateway = null)
    {
        $this->gateway = $gateway ?? new AgentRuntimeReadGateway();
    }

    /**
     * @param array{
     *   environment?: 'local'|'staging'|'production',
     *   creator_request?: array{source_mlb_id: string}
     * } $options
     */
    public function buildContext(int $accountId, string $correlationId, array $options = []): AgentContext
    {
        $this->assertOptions($options);
        $environment = $options['environment'] ?? 'local';
        $adsDashboard = $this->readAdsDashboard($accountId);

        $metadata = [
            'sentinela_snapshot' => $this->buildSentinelaEnvelope($accountId, $correlationId),
            'collector_snapshot' => $this->buildCollectorEnvelope($accountId, $correlationId, $adsDashboard),
            'financeiro_snapshot' => $this->buildFinanceiroEnvelope($accountId, $correlationId),
        ];

        $recommendations = $this->deriveRecommendations($adsDashboard);
        if ($recommendations !== []) {
            $mlbIds = array_column($recommendations, 'mlb_id');
            $metadata['optimizer_observation_snapshot'] = SnapshotEnvelope::wrap(
                $accountId,
                $correlationId,
                ['recommendations' => $recommendations]
            );
            $metadata['optimizer_cost_snapshot'] = SnapshotEnvelope::wrap(
                $accountId,
                $correlationId,
                ['items' => $this->buildCostItems($accountId, $mlbIds)]
            );
        }

        if (isset($options['creator_request'])) {
            $creatorRequest = $options['creator_request'];
            $metadata['creator_request'] = PureSnapshot::normalizeArray($creatorRequest);
            $metadata['creator_source_snapshot'] = $this->buildCreatorEnvelope(
                $accountId,
                $correlationId,
                $creatorRequest['source_mlb_id']
            );
        }

        $qaResults = $this->trustedQaResults();
        if ($qaResults !== null) {
            $metadata['qa_results_snapshot'] = SnapshotEnvelope::wrap(
                $accountId,
                $correlationId,
                ['results' => $qaResults],
                true
            );
        }

        return new AgentContext($accountId, $environment, $correlationId, false, $metadata);
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

    /** @param array<string, mixed> $options */
    private function assertOptions(array $options): void
    {
        foreach (array_keys($options) as $key) {
            if (!is_string($key) || !in_array($key, self::ALLOWED_OPTIONS, true)) {
                throw new InvalidArgumentException('unsupported runtime option');
            }
        }

        if (isset($options['environment'])
            && (!is_string($options['environment'])
                || !in_array($options['environment'], self::ENVIRONMENTS, true))
        ) {
            throw new InvalidArgumentException('invalid runtime environment');
        }

        if (isset($options['creator_request'])) {
            $request = $options['creator_request'];
            if (!is_array($request)
                || array_keys($request) !== ['source_mlb_id']
                || !is_string($request['source_mlb_id'])
                || preg_match('/^MLB[1-9][0-9]*$/', $request['source_mlb_id']) !== 1
            ) {
                throw new InvalidArgumentException('invalid creator_request');
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function readAdsDashboard(int $accountId): ?array
    {
        try {
            $dashboard = $this->gateway->adsDashboard($accountId);
            return $this->isValidAdsDashboard($dashboard) ? $dashboard : null;
        } catch (Throwable $error) {
            $this->logReadFailure('collector', $accountId, $error);
            return null;
        }
    }

    /** @return array{account_id: int, correlation_id: string, payload: array<string, mixed>} */
    private function buildSentinelaEnvelope(int $accountId, string $correlationId): array
    {
        $payload = ['ok' => false, 'semaforo' => 'verde', 'risks' => [], 'monitored' => 0];
        try {
            $dashboard = $this->gateway->sentinelaDashboard($accountId);
            $normalized = $this->normalizeSentinelaDashboard($dashboard);
            if ($normalized !== null) {
                $payload = ['ok' => true] + $normalized;
            }
        } catch (Throwable $error) {
            $this->logReadFailure('sentinela', $accountId, $error);
        }

        return SnapshotEnvelope::wrap($accountId, $correlationId, $payload);
    }

    /** @return array{account_id: int, correlation_id: string, payload: array<string, mixed>} */
    private function buildCollectorEnvelope(
        int $accountId,
        string $correlationId,
        ?array $dashboard
    ): array {
        $payload = [
            'ok' => false, 'available' => false, 'cached' => false,
            'stale' => false, 'api_calls' => 0,
        ];
        if ($dashboard !== null) {
            $payload = [
                'ok' => true,
                'available' => $dashboard['has_campaigns'],
                'cached' => true,
                'stale' => false,
                'api_calls' => 0,
            ];
        }

        return SnapshotEnvelope::wrap($accountId, $correlationId, $payload);
    }

    /** @return array{account_id: int, correlation_id: string, payload: array<string, mixed>} */
    private function buildFinanceiroEnvelope(int $accountId, string $correlationId): array
    {
        $empty = self::emptyPnL();
        $payload = [
            'ok' => false,
            'resumo' => [
                'today' => $empty,
                'current_month' => $empty,
                'previous_month' => $empty,
                'variations' => array_fill_keys(self::VARIATION_KEYS, 0.0),
            ],
            'metrics' => self::emptyMetrics(),
        ];

        try {
            $summary = $this->gateway->financialDashboardSummary($accountId);
            $start = date('Y-m-01');
            $end = date('Y-m-t 23:59:59');
            $metrics = $this->gateway->financialMetrics($accountId, $start, $end);
            if ($this->isValidFinancialSummary($summary) && $this->isValidMetrics($metrics)) {
                $payload = [
                    'ok' => true,
                    'resumo' => [
                        'today' => self::normalizePnL($summary['today']),
                        'current_month' => self::normalizePnL($summary['current_month']),
                        'previous_month' => self::normalizePnL($summary['previous_month']),
                        'variations' => $this->normalizeVariations($summary['variations']),
                    ],
                    'metrics' => $this->normalizeMetrics($metrics),
                ];
            }
        } catch (Throwable $error) {
            $this->logReadFailure('financeiro', $accountId, $error);
        }

        return SnapshotEnvelope::wrap($accountId, $correlationId, $payload);
    }

    /** @return array{account_id: int, correlation_id: string, payload: array<string, mixed>} */
    private function buildCreatorEnvelope(int $accountId, string $correlationId, string $mlbId): array
    {
        $payload = ['valid' => false, 'duplicate' => true, 'item' => ['id' => $mlbId]];
        try {
            $source = $this->gateway->item($accountId, $mlbId);
            if (!isset($source['error']) && ($source['id'] ?? null) === $mlbId) {
                $item = ['id' => $mlbId];
                if (isset($source['title']) && is_string($source['title']) && trim($source['title']) !== '') {
                    $item['title'] = trim($source['title']);
                }
                $payload = ['valid' => true, 'duplicate' => false, 'item' => $item];
            }
        } catch (Throwable $error) {
            $this->logReadFailure('creator-source', $accountId, $error);
        }

        return SnapshotEnvelope::wrap($accountId, $correlationId, $payload);
    }

    /** @param array<string, mixed>|null $dashboard
     * @return list<array{mlb_id: string, kind: string, recommended_roas: float}>
     */
    private function deriveRecommendations(?array $dashboard): array
    {
        if ($dashboard === null) {
            return [];
        }
        $out = [];
        foreach ($dashboard['skus'] as $sku) {
            $mlbId = $sku['mlb_id'] ?? null;
            $roas = $sku['roas_objetivo'] ?? $sku['roas'] ?? null;
            if (!is_string($mlbId)
                || preg_match('/^MLB[1-9][0-9]*$/', $mlbId) !== 1
                || !$this->isFiniteNumber($roas)
                || (float) $roas <= 0
            ) {
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
    }

    /** @param list<string> $mlbIds
     * @return array<string, array{validated: bool, suspicious: bool, cost: float}>
     */
    private function buildCostItems(int $accountId, array $mlbIds): array
    {
        $items = [];
        foreach ($mlbIds as $mlbId) {
            try {
                $row = $this->gateway->skuCostByMlb($accountId, $mlbId);
                $rawCost = is_array($row) ? ($row['custo_produto'] ?? null) : null;
                $cost = $this->isFiniteNumber($rawCost) && (float) $rawCost > 0
                    ? (float) $rawCost
                    : 0.0;
                $items[$mlbId] = [
                    'validated' => $cost > 0,
                    'suspicious' => $cost <= 0,
                    'cost' => $cost,
                ];
            } catch (Throwable $error) {
                $this->logReadFailure('optimizer-cost', $accountId, $error);
                $items[$mlbId] = ['validated' => false, 'suspicious' => true, 'cost' => 0.0];
            }
        }
        return $items;
    }

    /** @return array<string, AgentResult>|null */
    private function trustedQaResults(): ?array
    {
        try {
            (new QaMergeGate())->assertPasses();
        } catch (Throwable) {
            return null;
        }

        $results = [];
        foreach (QaMergeGate::REQUIRED_CHECK_IDS as $id) {
            $results[$id] = AgentResult::success($id, 'trusted_process_evidence');
        }
        return $results;
    }

    /** @param array<string, mixed> $dashboard
     * @return array{semaforo: string, risks: list<array<string, mixed>>, monitored: int}|null
     */
    private function normalizeSentinelaDashboard(array $dashboard): ?array
    {
        if (!isset($dashboard['semaforo'], $dashboard['risks'], $dashboard['monitored'])
            || !is_string($dashboard['semaforo'])
            || !in_array($dashboard['semaforo'], ['verde', 'amarelo', 'vermelho'], true)
            || !is_array($dashboard['risks'])
            || !$this->isList($dashboard['risks'])
            || !is_int($dashboard['monitored'])
            || $dashboard['monitored'] < 1
            || $dashboard['monitored'] > 10
        ) {
            return null;
        }

        $risks = [];
        $keys = [];
        $statuses = [];
        foreach ($dashboard['risks'] as $risk) {
            $normalized = $this->normalizeRisk($risk);
            if ($normalized === null || isset($keys[$normalized['risk_key']])) {
                return null;
            }
            $keys[$normalized['risk_key']] = true;
            $statuses[] = $normalized['status'];
            $risks[] = $normalized;
        }
        if ($risks !== [] && count(array_unique($statuses)) === 1 && $statuses[0] === 'nd') {
            return null;
        }
        $expected = in_array('vermelho', $statuses, true)
            ? 'vermelho'
            : (in_array('amarelo', $statuses, true) ? 'amarelo' : 'verde');
        if ($dashboard['semaforo'] !== $expected) {
            return null;
        }

        return [
            'semaforo' => $dashboard['semaforo'],
            'risks' => $risks,
            'monitored' => $dashboard['monitored'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function normalizeRisk(mixed $risk): ?array
    {
        if (!is_array($risk) || !$this->hasKeys($risk, self::RISK_FIELDS)) {
            return null;
        }
        if (!is_string($risk['risk_key']) || !in_array($risk['risk_key'], self::RISK_KEYS, true)
            || !is_string($risk['label']) || trim($risk['label']) === ''
            || !is_string($risk['status'])
            || !in_array($risk['status'], ['verde', 'amarelo', 'vermelho', 'nd'], true)
            || !is_string($risk['source']) || trim($risk['source']) === ''
        ) {
            return null;
        }
        foreach (['value_num', 'limit_num', 'pct_of_limit'] as $field) {
            if ($risk[$field] !== null && !$this->isFiniteNumber($risk[$field])) {
                return null;
            }
        }
        if (($risk['pct_of_limit'] !== null && (float) $risk['pct_of_limit'] < 0)
            || ($risk['value_text'] !== null && !is_string($risk['value_text']))
            || ($risk['reason'] !== null && !is_string($risk['reason']))
            || ($risk['meta'] !== null && !is_array($risk['meta']))
            || ($risk['collected_at'] !== null
                && (!is_string($risk['collected_at']) || trim($risk['collected_at']) === ''))
        ) {
            return null;
        }

        $normalized = [];
        foreach (self::RISK_FIELDS as $field) {
            $normalized[$field] = $risk[$field];
        }
        foreach (['value_num', 'limit_num', 'pct_of_limit'] as $field) {
            if ($normalized[$field] !== null) {
                $normalized[$field] = (float) $normalized[$field];
            }
        }
        return $normalized;
    }

    /** @param array<string, mixed> $dashboard */
    private function isValidAdsDashboard(array $dashboard): bool
    {
        if (($dashboard['read_only'] ?? null) !== true
            || !isset($dashboard['active_campaigns'], $dashboard['has_campaigns'], $dashboard['campaigns'], $dashboard['skus'])
            || !is_int($dashboard['active_campaigns'])
            || $dashboard['active_campaigns'] < 0
            || !is_bool($dashboard['has_campaigns'])
            || !is_array($dashboard['campaigns'])
            || !$this->isList($dashboard['campaigns'])
            || !is_array($dashboard['skus'])
            || !$this->isList($dashboard['skus'])
            || $dashboard['has_campaigns'] !== ($dashboard['campaigns'] !== [])
            || $dashboard['active_campaigns'] > count($dashboard['campaigns'])
        ) {
            return false;
        }
        $active = 0;
        foreach ($dashboard['campaigns'] as $campaign) {
            if (!is_array($campaign) || !isset($campaign['status']) || !is_string($campaign['status'])) {
                return false;
            }
            if ($campaign['status'] === 'active') {
                $active++;
            }
        }
        foreach ($dashboard['skus'] as $sku) {
            if (!is_array($sku)) {
                return false;
            }
        }
        return $active === $dashboard['active_campaigns'];
    }

    /** @param array<string, mixed> $summary */
    private function isValidFinancialSummary(array $summary): bool
    {
        if (!$this->hasExactKeys($summary, ['today', 'current_month', 'previous_month', 'variations'])) {
            return false;
        }
        foreach (['today', 'current_month', 'previous_month'] as $period) {
            if (!$this->isValidPnL($summary[$period])) {
                return false;
            }
        }
        if (!is_array($summary['variations'])
            || !$this->hasExactKeys($summary['variations'], self::VARIATION_KEYS)
        ) {
            return false;
        }
        foreach ($summary['variations'] as $value) {
            if (!$this->isFiniteNumber($value)) {
                return false;
            }
        }
        return true;
    }

    private function isValidPnL(mixed $pnl): bool
    {
        if (!is_array($pnl) || !$this->hasExactKeys($pnl, self::PNL_KEYS)
            || !is_int($pnl['total_orders']) || $pnl['total_orders'] < 0
        ) {
            return false;
        }
        foreach (array_diff(self::PNL_KEYS, ['total_orders', 'period']) as $field) {
            if (!$this->isFiniteNumber($pnl[$field])) {
                return false;
            }
        }
        foreach (['gross_revenue', 'taxes', 'cogs', 'commissions', 'payment_fees', 'fixed_fees', 'shipping_cost', 'discounts'] as $field) {
            if ((float) $pnl[$field] < 0) {
                return false;
            }
        }
        if (!is_array($pnl['period'])
            || !$this->hasExactKeys($pnl['period'], ['start', 'end'])
            || !is_string($pnl['period']['start'])
            || !is_string($pnl['period']['end'])
        ) {
            return false;
        }
        $start = strtotime($pnl['period']['start']);
        $end = strtotime($pnl['period']['end']);
        return $start !== false && $end !== false && $start <= $end;
    }

    /** @param array<string, mixed> $metrics */
    private function isValidMetrics(array $metrics): bool
    {
        if (!$this->hasExactKeys($metrics, self::METRICS_KEYS)
            || !is_int($metrics['total_orders']) || $metrics['total_orders'] < 0
        ) {
            return false;
        }
        foreach (array_diff(self::METRICS_KEYS, ['total_orders']) as $field) {
            if (!$this->isFiniteNumber($metrics[$field])) {
                return false;
            }
        }
        return (float) $metrics['gross_revenue'] >= 0
            && (float) $metrics['avg_ticket'] >= 0
            && (float) $metrics['cost_rate'] >= 0;
    }

    /** @param array<string, mixed> $variations @return array<string, float> */
    private function normalizeVariations(array $variations): array
    {
        $out = [];
        foreach (self::VARIATION_KEYS as $key) {
            $out[$key] = (float) $variations[$key];
        }
        return $out;
    }

    /** @param array<string, mixed> $metrics @return array<string, int|float> */
    private function normalizeMetrics(array $metrics): array
    {
        return [
            'total_orders' => $metrics['total_orders'],
            'gross_revenue' => (float) $metrics['gross_revenue'],
            'net_profit' => (float) $metrics['net_profit'],
            'avg_ticket' => (float) $metrics['avg_ticket'],
            'avg_margin' => (float) $metrics['avg_margin'],
            'cost_rate' => (float) $metrics['cost_rate'],
            'roi' => (float) $metrics['roi'],
        ];
    }

    private function logReadFailure(string $source, int $accountId, Throwable $error): void
    {
        log_warning('AgentRuntimeFactory: read-only source unavailable', [
            'source' => $source,
            'account_id' => $accountId,
            'exception_class' => $error::class,
        ]);
    }

    private function isFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    /** @param array<array-key, mixed> $value */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function hasKeys(array $value, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function normalizePnL(array $row): array
    {
        return [
            'total_orders' => $row['total_orders'],
            'gross_revenue' => (float) $row['gross_revenue'],
            'taxes' => (float) $row['taxes'],
            'net_revenue' => (float) $row['net_revenue'],
            'cogs' => (float) $row['cogs'],
            'commissions' => (float) $row['commissions'],
            'payment_fees' => (float) $row['payment_fees'],
            'fixed_fees' => (float) $row['fixed_fees'],
            'shipping_cost' => (float) $row['shipping_cost'],
            'discounts' => (float) $row['discounts'],
            'net_profit' => (float) $row['net_profit'],
            'avg_margin' => (float) $row['avg_margin'],
            'period' => ['start' => $row['period']['start'], 'end' => $row['period']['end']],
        ];
    }

    /** @return array<string, mixed> */
    public static function emptyPnL(): array
    {
        return [
            'total_orders' => 0, 'gross_revenue' => 0.0, 'taxes' => 0.0,
            'net_revenue' => 0.0, 'cogs' => 0.0, 'commissions' => 0.0,
            'payment_fees' => 0.0, 'fixed_fees' => 0.0, 'shipping_cost' => 0.0,
            'discounts' => 0.0, 'net_profit' => 0.0, 'avg_margin' => 0.0,
            'period' => ['start' => '1970-01-01', 'end' => '1970-01-01'],
        ];
    }

    /** @return array<string, int|float> */
    public static function emptyMetrics(): array
    {
        return [
            'total_orders' => 0, 'gross_revenue' => 0.0, 'net_profit' => 0.0,
            'avg_ticket' => 0.0, 'avg_margin' => 0.0, 'cost_rate' => 0.0, 'roi' => 0.0,
        ];
    }
}
