<?php

declare(strict_types=1);

namespace App\Services\Agents;

/** Transforma o snapshot read-only do coletor. */
final class CollectorAgent extends LegacyReadOnlyAgentAdapter
{
    public const NAME = 'coletor';
    private const SNAPSHOT_KEY = 'collector_snapshot';

    public function name(): string { return self::NAME; }
    protected function snapshotKey(): string { return self::SNAPSHOT_KEY; }

    /** @return list<string> */
    protected function payloadKeys(): array { return ['available', 'cached', 'stale', 'api_calls']; }

    /** @param array<string, mixed> $payload */
    protected function mapPayload(array $payload): AgentResult
    {
        foreach (['available', 'cached', 'stale'] as $key) {
            if (!array_key_exists($key, $payload) || !is_bool($payload[$key])) {
                return $this->failed('invalid_legacy_payload');
            }
        }
        if (!array_key_exists('api_calls', $payload)
            || !is_int($payload['api_calls'])
            || $payload['api_calls'] < 0
        ) {
            return $this->failed('invalid_legacy_payload');
        }
        $data = [
            'available' => $payload['available'],
            'cached' => $payload['cached'],
            'stale' => $payload['stale'],
            'api_calls' => $payload['api_calls'],
        ];
        if ($payload['ok'] === false
            && !($payload['available'] && $payload['cached'] && $payload['stale'])
        ) {
            return $this->failed('collector_unavailable', $data);
        }

        return $this->success($data);
    }
}
