<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Services\Sentinela\Sentinela;

/** Transforma o snapshot read-only do Sentinela. */
final class SentinelaAgent extends LegacyReadOnlyAgentAdapter
{
    public const NAME = 'sentinela';
    private const SNAPSHOT_KEY = 'sentinela_snapshot';

    /** @var list<string> */
    private const RISK_FIELDS = [
        'risk_key', 'label', 'value_num', 'value_text', 'limit_num',
        'pct_of_limit', 'status', 'reason', 'source', 'meta', 'collected_at',
    ];

    /** @var list<string> */
    private const RISK_STATUSES = ['verde', 'amarelo', 'vermelho', 'nd'];

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
        return ['semaforo', 'risks', 'monitored'];
    }

    /** @param array<string, mixed> $payload */
    protected function mapPayload(array $payload): AgentResult
    {
        if (
            !array_key_exists('semaforo', $payload)
            || !is_string($payload['semaforo'])
            || !in_array($payload['semaforo'], ['verde', 'amarelo', 'vermelho'], true)
            || !array_key_exists('risks', $payload)
            || !is_array($payload['risks'])
            || !$this->isRiskList($payload['risks'])
            || !array_key_exists('monitored', $payload)
            || !is_int($payload['monitored'])
            || $payload['monitored'] < 0
        ) {
            return $this->failed('invalid_legacy_payload');
        }
        $data = [
            'semaforo' => $payload['semaforo'],
            'risks' => $payload['risks'],
            'monitored' => $payload['monitored'],
        ];

        return $payload['ok'] === true
            ? $this->success($data)
            : $this->failed('sentinela_unavailable', $data);
    }

    /** @param array<array-key, mixed> $value */
    private function isRiskList(array $value): bool
    {
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }
        foreach ($value as $risk) {
            if (!$this->isRisk($risk)) {
                return false;
            }
        }

        return true;
    }

    private function isRisk(mixed $risk): bool
    {
        if (!is_array($risk)) {
            return false;
        }
        $keys = array_keys($risk);
        sort($keys);
        $expected = self::RISK_FIELDS;
        sort($expected);
        if ($keys !== $expected) {
            return false;
        }
        if (!is_string($risk['risk_key'])
            || !in_array($risk['risk_key'], Sentinela::RISK_KEYS, true)
        ) {
            return false;
        }
        if (!is_string($risk['label'])) {
            return false;
        }
        if ($risk['value_num'] !== null && !is_int($risk['value_num']) && !is_float($risk['value_num'])) {
            return false;
        }
        if ($risk['value_text'] !== null && !is_string($risk['value_text'])) {
            return false;
        }
        if ($risk['limit_num'] !== null && !is_int($risk['limit_num']) && !is_float($risk['limit_num'])) {
            return false;
        }
        if ($risk['pct_of_limit'] !== null && !is_int($risk['pct_of_limit']) && !is_float($risk['pct_of_limit'])) {
            return false;
        }
        if (!is_string($risk['status']) || !in_array($risk['status'], self::RISK_STATUSES, true)) {
            return false;
        }
        if ($risk['reason'] !== null && !is_string($risk['reason'])) {
            return false;
        }
        if (!is_string($risk['source'])) {
            return false;
        }
        if ($risk['collected_at'] !== null && !is_string($risk['collected_at'])) {
            return false;
        }
        if ($risk['meta'] !== null) {
            if (!is_array($risk['meta']) || $this->metaHasForbidden($risk['meta'])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $meta */
    private function metaHasForbidden(array $meta): bool
    {
        foreach ($meta as $key => $value) {
            if ($key === 'state_changed' || $key === 'emitted_ops') {
                return true;
            }
            if (is_array($value) && $this->metaHasForbidden($value)) {
                return true;
            }
        }

        return false;
    }
}
