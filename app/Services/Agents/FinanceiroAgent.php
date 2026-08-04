<?php

declare(strict_types=1);

namespace App\Services\Agents;

/** Transforma o snapshot read-only financeiro. */
final class FinanceiroAgent extends LegacyReadOnlyAgentAdapter
{
    public const NAME = 'financeiro';
    private const SNAPSHOT_KEY = 'financeiro_snapshot';

    public function name(): string { return self::NAME; }
    protected function snapshotKey(): string { return self::SNAPSHOT_KEY; }

    /** @return list<string> */
    protected function payloadKeys(): array { return ['resumo', 'metrics']; }

    /** @param array<string, mixed> $payload */
    protected function mapPayload(array $payload): AgentResult
    {
        if (!array_key_exists('resumo', $payload)
            || !is_array($payload['resumo'])
            || !array_key_exists('metrics', $payload)
            || !is_array($payload['metrics'])
        ) {
            return $this->failed('invalid_legacy_payload');
        }
        $data = ['resumo' => $payload['resumo'], 'metrics' => $payload['metrics']];

        return $payload['ok'] === true
            ? $this->success($data)
            : $this->failed('financeiro_unavailable', $data);
    }
}
