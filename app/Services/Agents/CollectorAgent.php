<?php

declare(strict_types=1);

namespace App\Services\Agents;

/**
 * Adapter read-only para coletores legados do Mercado Livre.
 */
final class CollectorAgent extends LegacyReadOnlyAgentAdapter
{
    public const NAME = 'coletor';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function mapPayload(array $payload): AgentResult
    {
        foreach (['available', 'cached', 'stale'] as $key) {
            if (!array_key_exists($key, $payload) || !is_bool($payload[$key])) {
                return $this->failed('invalid_legacy_payload');
            }
        }

        if (
            !array_key_exists('api_calls', $payload)
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

        if ($payload['ok'] === false && $payload['available'] === false) {
            return $this->failed('collector_unavailable', $data);
        }

        return $this->success($data);
    }
}
