<?php

declare(strict_types=1);

namespace App\Services\Agents;

use DateTimeImmutable;
use DateTimeZone;
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
    private const ADS_SKU_FIELDS = [
        'mlb_id', 'gasto', 'impressoes', 'cliques', 'cpc', 'vendas_atribuidas',
        'acos', 'roas_real', 'roas_objetivo', 'roas_breakeven', 'roas_escala',
        'margem_liquida_pct', 'has_custo', 'health', 'semaforo',
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
            $period = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
            $start = $period->format('Y-m-01');
            $end = $period->format('Y-m-t 23:59:59');
            $summary = $this->gateway->financialDashboardSummary($accountId);
            $metrics = $this->gateway->financialMetrics($accountId, $start, $end);
            if ($this->isValidFinancialSummary($summary, $start, $end)
                && $this->isValidMetrics($metrics)
                && $this->metricsMatchCurrentMonth($metrics, $summary['current_month'])
            ) {
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
            if ($this->hasExactKeys($source, [
                'account_id', 'mlb_id', 'seller_id', 'title', 'duplicate',
            ])
                && $this->matchesAccountId($source['account_id'], $accountId)
                && $source['mlb_id'] === $mlbId
                && self::canonicalPositiveDigits($source['seller_id']) !== null
                && is_string($source['title'])
                && trim($source['title']) !== ''
                && $source['duplicate'] === false
            ) {
                $title = PureSnapshot::normalize(trim($source['title']));
                if (!is_string($title)) {
                    throw new InvalidArgumentException('invalid creator title snapshot');
                }
                $payload = [
                    'valid' => true,
                    'duplicate' => false,
                    'item' => ['id' => $mlbId, 'title' => $title],
                ];
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
                $cost = is_array($row)
                    && $this->matchesAccountId($row['account_id'] ?? null, $accountId)
                    && ($row['mlb_id'] ?? null) === $mlbId
                    && $this->isPositiveCanonicalDecimal($rawCost)
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
        foreach ($dashboard['risks'] as $risk) {
            $normalized = $this->normalizeRisk($risk);
            if ($normalized === null || isset($keys[$normalized['risk_key']])) {
                return null;
            }
            $keys[$normalized['risk_key']] = true;
            $risks[] = $normalized;
        }
        $expectedKeys = self::RISK_KEYS;
        $actualKeys = array_keys($keys);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            return null;
        }
        $monitored = 0;
        foreach ($risks as $risk) {
            if ($risk['risk_key'] !== 'nf_pendente'
                && ($risk['status'] !== 'nd' || $risk['collected_at'] !== null)
            ) {
                $monitored++;
            }
        }
        if ($dashboard['monitored'] !== $monitored
            || $dashboard['semaforo'] !== $this->evaluateSemaforo($risks)
        ) {
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
        if (!is_array($risk) || !$this->hasExactKeys($risk, self::RISK_FIELDS)) {
            return null;
        }
        if (!is_string($risk['risk_key']) || !in_array($risk['risk_key'], self::RISK_KEYS, true)
            || !is_string($risk['label']) || trim($risk['label']) === ''
            || !is_string($risk['status'])
            || !in_array($risk['status'], ['verde', 'amarelo', 'vermelho', 'nd'], true)
            || !is_string($risk['source']) || trim($risk['source']) === ''
            || !$this->riskStatusMatchesPct($risk['status'], $risk['pct_of_limit'])
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
        if (is_array($normalized['meta'])) {
            try {
                $normalized['meta'] = PureSnapshot::normalizeArray($normalized['meta']);
            } catch (InvalidArgumentException) {
                return null;
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
        $seenMlbs = [];
        foreach ($dashboard['skus'] as $sku) {
            if (!$this->isValidAdsSku($sku)) {
                return false;
            }
            if (isset($seenMlbs[$sku['mlb_id']])) {
                return false;
            }
            $seenMlbs[$sku['mlb_id']] = true;
        }
        return $active === $dashboard['active_campaigns'];
    }

    private function isValidAdsSku(mixed $sku): bool
    {
        if (!is_array($sku) || !$this->hasExactKeys($sku, self::ADS_SKU_FIELDS)
            || !is_string($sku['mlb_id'])
            || preg_match('/^MLB[1-9][0-9]*$/D', $sku['mlb_id']) !== 1
            || !$this->isFiniteNumber($sku['gasto']) || (float) $sku['gasto'] < 0
            || !is_int($sku['impressoes']) || $sku['impressoes'] < 0
            || !is_int($sku['cliques']) || $sku['cliques'] < 0 || $sku['cliques'] > $sku['impressoes']
            || !is_int($sku['vendas_atribuidas']) || $sku['vendas_atribuidas'] < 0
            || !is_bool($sku['has_custo'])
            || !is_string($sku['semaforo'])
            || !in_array($sku['semaforo'], ['verde', 'amarelo', 'vermelho', 'nd'], true)
        ) {
            return false;
        }
        foreach (['cpc', 'acos', 'roas_real', 'roas_objetivo', 'roas_breakeven', 'roas_escala', 'margem_liquida_pct', 'health'] as $field) {
            if ($sku[$field] !== null && !$this->isFiniteNumber($sku[$field])) {
                return false;
            }
        }
        foreach (['cpc', 'acos', 'roas_real'] as $field) {
            if ($sku[$field] !== null && (float) $sku[$field] < 0) {
                return false;
            }
        }
        foreach (['roas_objetivo', 'roas_breakeven', 'roas_escala'] as $field) {
            if ($sku[$field] !== null && (float) $sku[$field] <= 0) {
                return false;
            }
        }
        if (($sku['health'] !== null && ((float) $sku['health'] < 0 || (float) $sku['health'] > 1))
            || ($sku['margem_liquida_pct'] !== null && (float) $sku['margem_liquida_pct'] > 100)
            || ((float) $sku['gasto'] === 0.0 && $sku['roas_real'] !== null)
        ) {
            return false;
        }

        $trio = [$sku['roas_breakeven'], $sku['roas_objetivo'], $sku['roas_escala']];
        if ($sku['has_custo'] === false
            && ($sku['margem_liquida_pct'] !== null || $trio !== [null, null, null])
        ) {
            return false;
        }
        if ($sku['margem_liquida_pct'] === null || (float) $sku['margem_liquida_pct'] <= 0) {
            if ($trio !== [null, null, null]) {
                return false;
            }
        } elseif (in_array(null, $trio, true)
            || !$this->approximatelyEqual((float) $sku['roas_breakeven'], 100.0 / (float) $sku['margem_liquida_pct'], 0.00011)
            || !$this->approximatelyEqual((float) $sku['roas_objetivo'], (float) $sku['roas_breakeven'] * 1.5, 0.00011)
            || !$this->approximatelyEqual((float) $sku['roas_escala'], (float) $sku['roas_breakeven'] * 2.0, 0.00011)
        ) {
            return false;
        }

        return $sku['semaforo'] === $this->adsSemaforo(
            $sku['roas_real'] === null ? null : (float) $sku['roas_real'],
            $sku['roas_objetivo'] === null ? null : (float) $sku['roas_objetivo'],
            $sku['roas_breakeven'] === null ? null : (float) $sku['roas_breakeven']
        );
    }

    private function adsSemaforo(?float $real, ?float $objective, ?float $breakeven): string
    {
        if ($real === null) {
            return 'nd';
        }
        if ($objective !== null && $real >= $objective) {
            return 'verde';
        }
        $floor = $breakeven ?? ($objective !== null ? $objective / 1.5 : null);
        if ($floor !== null && $real >= $floor) {
            return 'amarelo';
        }
        return $floor !== null ? 'vermelho' : 'nd';
    }

    /** @param array<string, mixed> $summary */
    private function isValidFinancialSummary(array $summary, string $currentStart, string $currentEnd): bool
    {
        if (!$this->hasExactKeys($summary, ['today', 'current_month', 'previous_month', 'variations'])) {
            return false;
        }
        foreach (['today', 'current_month', 'previous_month'] as $period) {
            if (!$this->isValidPnL($summary[$period])) {
                return false;
            }
        }
        if ($summary['current_month']['period']['start'] !== $currentStart
            || $summary['current_month']['period']['end'] !== $currentEnd
        ) {
            return false;
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
        $start = $this->parseStrictDate($pnl['period']['start']);
        $end = $this->parseStrictDate($pnl['period']['end']);
        return $start !== null && $end !== null && $start <= $end;
    }

    private function parseStrictDate(string $value): ?DateTimeImmutable
    {
        $roundTrip = null;
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) === 1) {
            $format = '!Y-m-d';
            $roundTrip = 'Y-m-d';
        } elseif (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) === 1) {
            $format = '!Y-m-d H:i:s';
            $roundTrip = 'Y-m-d H:i:s';
        } else {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(
            $format,
            $value,
            new DateTimeZone(date_default_timezone_get())
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format($roundTrip) !== $value
        ) {
            return null;
        }
        return $date;
    }

    /** @param array<string, mixed> $metrics @param array<string, mixed> $currentMonth */
    private function metricsMatchCurrentMonth(array $metrics, array $currentMonth): bool
    {
        if ($metrics['total_orders'] !== $currentMonth['total_orders']) {
            return false;
        }
        foreach (['gross_revenue', 'net_profit', 'avg_margin'] as $field) {
            if (!$this->approximatelyEqual((float) $metrics[$field], (float) $currentMonth[$field])) {
                return false;
            }
        }
        return true;
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

    private function isPositiveCanonicalDecimal(mixed $value): bool
    {
        if ($this->isFiniteNumber($value)) {
            return (float) $value > 0;
        }
        return is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1
            && is_finite((float) $value)
            && (float) $value > 0;
    }

    private function matchesAccountId(mixed $value, int $accountId): bool
    {
        return $value === $accountId
            || (is_string($value)
                && preg_match('/^[1-9][0-9]*$/D', $value) === 1
                && $value === (string) $accountId);
    }

    private static function canonicalPositiveDigits(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }
        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            ? $value
            : null;
    }

    /** @param list<array<string, mixed>> $risks */
    private function evaluateSemaforo(array $risks): string
    {
        $worst = 'verde';
        foreach ($risks as $risk) {
            if ($risk['status'] === 'nd') {
                continue;
            }
            $pct = $risk['pct_of_limit'];
            if ($risk['status'] === 'vermelho' || ($pct !== null && (float) $pct > 80.0)) {
                return 'vermelho';
            }
            if ($risk['status'] === 'amarelo' || ($pct !== null && (float) $pct >= 50.0)) {
                $worst = 'amarelo';
            }
        }
        return $worst;
    }

    private function riskStatusMatchesPct(string $status, mixed $pct): bool
    {
        if ($pct === null) {
            return true;
        }
        if (!$this->isFiniteNumber($pct) || (float) $pct < 0) {
            return false;
        }
        $expected = (float) $pct >= 80.0
            ? 'vermelho'
            : ((float) $pct >= 50.0 ? 'amarelo' : 'verde');

        return $status === $expected;
    }

    private function approximatelyEqual(float $left, float $right, float $epsilon = 0.000001): bool
    {
        return abs($left - $right) <= $epsilon * max(1.0, abs($left), abs($right));
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
