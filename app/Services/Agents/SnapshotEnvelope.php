<?php

declare(strict_types=1);

namespace App\Services\Agents;

/**
 * Envelope puro de snapshot: exatamente account_id, correlation_id e payload.
 */
final class SnapshotEnvelope
{
    /** @var list<string> */
    public const KEYS = ['account_id', 'correlation_id', 'payload'];

    /**
     * @param array<string, mixed> $payload
     * @return array{account_id: int, correlation_id: string, payload: array<string, mixed>}
     */
    public static function wrap(
        int $accountId,
        string $correlationId,
        array $payload,
        bool $allowAgentResult = false
    ): array {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('account_id must be positive');
        }
        if (trim($correlationId) === '') {
            throw new \InvalidArgumentException('correlation_id must be non-empty');
        }

        return [
            'account_id' => $accountId,
            'correlation_id' => $correlationId,
            'payload' => PureSnapshot::normalizeArray($payload, $allowAgentResult),
        ];
    }

    /**
     * Extrai o payload se o envelope for válido e corresponder ao contexto.
     * Retorna null em qualquer violação (falha fechada no caller).
     *
     * @return array<string, mixed>|null
     */
    public static function extract(
        mixed $envelope,
        int $accountId,
        string $correlationId,
        bool $allowAgentResult = false
    ): ?array {
        if (!is_array($envelope)) {
            return null;
        }

        $keys = array_keys($envelope);
        sort($keys);
        $expected = self::KEYS;
        sort($expected);
        if ($keys !== $expected) {
            return null;
        }

        if (!is_int($envelope['account_id']) || $envelope['account_id'] <= 0) {
            return null;
        }
        if (!is_string($envelope['correlation_id']) || trim($envelope['correlation_id']) === '') {
            return null;
        }
        if (!is_array($envelope['payload'])) {
            return null;
        }

        if ($envelope['account_id'] !== $accountId) {
            return null;
        }
        if ($envelope['correlation_id'] !== $correlationId) {
            return null;
        }

        try {
            return PureSnapshot::normalizeArray($envelope['payload'], $allowAgentResult);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
