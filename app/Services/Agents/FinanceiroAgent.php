<?php

declare(strict_types=1);

namespace App\Services\Agents;

/**
 * Transforma o snapshot read-only financeiro.
 *
 * Schema de resumo/metrics derivado de PnlReportService::getDashboardSummary / getMetrics.
 */
final class FinanceiroAgent extends LegacyReadOnlyAgentAdapter
{
    public const NAME = 'financeiro';
    private const SNAPSHOT_KEY = 'financeiro_snapshot';

    /** @var list<string> */
    private const RESUMO_KEYS = ['today', 'current_month', 'previous_month', 'variations'];

    /** @var list<string> */
    private const PNL_KEYS = [
        'total_orders', 'gross_revenue', 'taxes', 'net_revenue', 'cogs',
        'commissions', 'payment_fees', 'fixed_fees', 'shipping_cost', 'discounts',
        'net_profit', 'avg_margin', 'period',
    ];

    /** @var list<string> */
    private const VARIATION_KEYS = ['gross_revenue', 'net_profit', 'total_orders', 'avg_margin'];

    /** @var list<string> */
    private const METRICS_KEYS = [
        'total_orders', 'gross_revenue', 'net_profit', 'avg_ticket',
        'avg_margin', 'cost_rate', 'roi',
    ];

    public function name(): string
    {
        return self::NAME;
    }

    protected function snapshotKey(): string
    {
        return self::SNAPSHOT_KEY;
    }

    /** @return list<string> */
    protected function payloadKeys(): array
    {
        return ['resumo', 'metrics'];
    }

    /** @param array<string, mixed> $payload */
    protected function mapPayload(array $payload): AgentResult
    {
        if (!array_key_exists('resumo', $payload)
            || !is_array($payload['resumo'])
            || !$this->isResumo($payload['resumo'])
            || !array_key_exists('metrics', $payload)
            || !is_array($payload['metrics'])
            || !$this->isMetrics($payload['metrics'])
        ) {
            return $this->failed('invalid_legacy_payload');
        }
        $data = ['resumo' => $payload['resumo'], 'metrics' => $payload['metrics']];

        return $payload['ok'] === true
            ? $this->success($data)
            : $this->failed('financeiro_unavailable', $data);
    }

    /** @param array<array-key, mixed> $resumo */
    private function isResumo(array $resumo): bool
    {
        $keys = array_keys($resumo);
        sort($keys);
        $expected = self::RESUMO_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            return false;
        }
        foreach (['today', 'current_month', 'previous_month'] as $periodKey) {
            if (!$this->isPnL($resumo[$periodKey])) {
                return false;
            }
        }
        if (!is_array($resumo['variations'])) {
            return false;
        }
        $vKeys = array_keys($resumo['variations']);
        sort($vKeys);
        $vExpected = self::VARIATION_KEYS;
        sort($vExpected);
        if ($vKeys !== $vExpected) {
            return false;
        }
        foreach ($resumo['variations'] as $value) {
            if (!is_int($value) && !is_float($value)) {
                return false;
            }
        }

        return true;
    }

    private function isPnL(mixed $pnl): bool
    {
        if (!is_array($pnl)) {
            return false;
        }
        $keys = array_keys($pnl);
        sort($keys);
        $expected = self::PNL_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            return false;
        }
        if (!is_int($pnl['total_orders'])) {
            return false;
        }
        foreach ([
            'gross_revenue', 'taxes', 'net_revenue', 'cogs', 'commissions',
            'payment_fees', 'fixed_fees', 'shipping_cost', 'discounts',
            'net_profit', 'avg_margin',
        ] as $numeric) {
            if (!is_int($pnl[$numeric]) && !is_float($pnl[$numeric])) {
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

        return true;
    }

    /** @param array<array-key, mixed> $metrics */
    private function isMetrics(array $metrics): bool
    {
        $keys = array_keys($metrics);
        sort($keys);
        $expected = self::METRICS_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            return false;
        }
        if (!is_int($metrics['total_orders'])) {
            return false;
        }
        foreach (['gross_revenue', 'net_profit', 'avg_ticket', 'avg_margin', 'cost_rate', 'roi'] as $numeric) {
            if (!is_int($metrics[$numeric]) && !is_float($metrics[$numeric])) {
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
        $expected = $keys;
        sort($expected);

        return $actual === $expected;
    }
}
