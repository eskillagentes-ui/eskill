<?php

declare(strict_types=1);

namespace App\Services\Agents;

/** Transforma o snapshot read-only do Sentinela. */
final class SentinelaAgent extends LegacyReadOnlyAgentAdapter
{
    public const NAME = 'sentinela';
    private const SNAPSHOT_KEY = 'sentinela_snapshot';

    public function name(): string { return self::NAME; }
    protected function snapshotKey(): string { return self::SNAPSHOT_KEY; }

    /** @return list<string> */
    protected function payloadKeys(): array { return ['semaforo', 'risks', 'monitored']; }

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
            if (!is_array($risk)) {
                return false;
            }
        }

        return true;
    }
}
