<?php

declare(strict_types=1);

namespace App\Services\Agents;

/** Produz recomendações somente de snapshots de observação e custos. */
final class OtimizadorAgent implements AgentInterface
{
    public const NAME = 'otimizador';

    public function __construct()
    {
        if (func_num_args() !== 0) {
            throw new \InvalidArgumentException('snapshot agents do not accept dependencies');
        }
    }

    public function name(): string { return self::NAME; }

    public function run(AgentContext $context): AgentResult
    {
        $metadata = $context->metadata();
        $observed = $metadata['optimizer_observation_snapshot'] ?? null;
        if (!$this->validObservation($observed)) {
            return AgentResult::failed(self::NAME, 'invalid_optimizer_observation_snapshot');
        }

        $recommendations = [];
        $mlbIds = [];
        foreach ($observed['recommendations'] as $recommendation) {
            $normalized = $this->normalizeRecommendation($recommendation);
            if ($normalized === null || in_array($normalized['mlb_id'], $mlbIds, true)) {
                return AgentResult::failed(self::NAME, 'invalid_optimizer_observation_snapshot');
            }
            $recommendations[] = $normalized;
            $mlbIds[] = $normalized['mlb_id'];
        }

        $costSnapshot = $metadata['optimizer_cost_snapshot'] ?? null;
        if (!$this->validCosts($costSnapshot, $mlbIds)) {
            return AgentResult::failed(self::NAME, 'invalid_optimizer_cost_snapshot');
        }

        $allActionable = true;
        foreach ($recommendations as $index => $recommendation) {
            $mlbId = $recommendation['mlb_id'];
            $cost = $costSnapshot['items'][$mlbId] ?? null;
            $actionable = is_array($cost)
                && $cost['validated'] === true
                && $cost['suspicious'] === false
                && (float) $cost['cost'] > 0.0;
            if (!$actionable) {
                $allActionable = false;
                $recommendations[$index] = [
                    'mlb_id' => $mlbId,
                    'actionable' => false,
                    'blocked' => true,
                    'blocked_reason' => 'cost_not_validated',
                ];
                continue;
            }
            $recommendation['actionable'] = true;
            $recommendation['blocked'] = false;
            $recommendations[$index] = $recommendation;
        }

        $data = ['recommendations' => $recommendations, 'read_only' => true];
        return $allActionable
            ? AgentResult::success(self::NAME, 'recommendations_ready', $data)
            : AgentResult::blocked(self::NAME, 'cost_validation_blocked', $data);
    }

    private function validObservation(mixed $snapshot): bool
    {
        return is_array($snapshot)
            && array_keys($snapshot) === ['recommendations']
            && is_array($snapshot['recommendations'])
            && $snapshot['recommendations'] !== []
            && array_is_list($snapshot['recommendations']);
    }

    /** @param list<string> $mlbIds */
    private function validCosts(mixed $snapshot, array $mlbIds): bool
    {
        if (!is_array($snapshot)
            || array_keys($snapshot) !== ['items']
            || !is_array($snapshot['items'])
        ) {
            return false;
        }
        foreach ($snapshot['items'] as $mlbId => $cost) {
            if (!is_string($mlbId)
                || !in_array($mlbId, $mlbIds, true)
                || !is_array($cost)
                || !$this->hasExactKeys($cost, ['validated', 'suspicious', 'cost'])
                || !is_bool($cost['validated'])
                || !is_bool($cost['suspicious'])
                || (!is_int($cost['cost']) && !is_float($cost['cost']))
                || !is_finite((float) $cost['cost'])
                || (float) $cost['cost'] < 0.0
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return array{mlb_id: string, kind: string, recommended_roas: float}|null */
    private function normalizeRecommendation(mixed $recommendation): ?array
    {
        if (!is_array($recommendation)
            || !$this->hasExactKeys($recommendation, ['kind', 'mlb_id', 'recommended_roas'])
        ) {
            return null;
        }
        $mlbId = $recommendation['mlb_id'];
        $kind = $recommendation['kind'];
        $roas = $recommendation['recommended_roas'];
        if (!is_string($mlbId)
            || preg_match('/^MLB[0-9]+$/', $mlbId) !== 1
            || $kind !== 'ads_roas'
            || (!is_int($roas) && !is_float($roas))
            || !is_finite((float) $roas)
            || (float) $roas <= 0.0
            || (float) $roas > 100.0
        ) {
            return null;
        }

        return ['mlb_id' => $mlbId, 'kind' => $kind, 'recommended_roas' => (float) $roas];
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }
}
