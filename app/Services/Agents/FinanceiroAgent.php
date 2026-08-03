<?php

declare(strict_types=1);

namespace App\Services\Agents;

/**
 * Adapter read-only para leituras financeiras legadas.
 */
final class FinanceiroAgent extends LegacyReadOnlyAgentAdapter
{
    public const NAME = 'financeiro';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function mapPayload(array $payload): AgentResult
    {
        if (
            !array_key_exists('resumo', $payload)
            || !is_array($payload['resumo'])
            || !array_key_exists('metrics', $payload)
            || !is_array($payload['metrics'])
        ) {
            return $this->failed('invalid_legacy_payload');
        }

        $data = [
            'resumo' => $payload['resumo'],
            'metrics' => $payload['metrics'],
        ];

        if ($payload['ok'] !== true) {
            return $this->failed('financeiro_unavailable', $data);
        }

        return $this->success($data);
    }
}
