<?php

declare(strict_types=1);

namespace App\Services\Agents;

use Closure;
use Throwable;

/**
 * Base para adapters read-only sobre payloads heterogêneos de serviços legados.
 */
abstract class LegacyReadOnlyAgentAdapter implements AgentInterface
{
    /** @var Closure(int): array<string, mixed> */
    private Closure $port;

    /**
     * @param callable(int): array<string, mixed> $port
     */
    final public function __construct(callable $port)
    {
        $this->port = Closure::fromCallable($port);
    }

    final public function run(AgentContext $context): AgentResult
    {
        try {
            $payload = ($this->port)($context->accountId());
        } catch (Throwable) {
            return $this->failed('legacy_port_exception');
        }

        if (!is_array($payload)) {
            return $this->failed('invalid_legacy_payload');
        }

        if (array_key_exists('_meta', $payload) && !is_array($payload['_meta'])) {
            return $this->failed('invalid_legacy_payload');
        }

        $httpStatus = $this->failureHttpStatus($payload);
        if ($httpStatus !== null) {
            return $this->failed('legacy_http_' . $httpStatus);
        }

        if (
            !empty($payload['incomplete'])
            || ($payload['error'] ?? null) === 'pagination_incomplete'
            || !empty($payload['_meta']['incomplete'])
        ) {
            return $this->failed('incomplete_legacy_payload');
        }

        if (!array_key_exists('ok', $payload) || !is_bool($payload['ok'])) {
            return $this->failed('invalid_legacy_payload');
        }

        return $this->mapPayload($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    abstract protected function mapPayload(array $payload): AgentResult;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $data
     */
    final protected function success(array $payload, array $data): AgentResult
    {
        $stateChanged = ($payload['state_changed'] ?? null) === true;
        $emittedOps = [];

        if ($stateChanged) {
            $candidateOps = $payload['emitted_ops'] ?? [];
            if (!is_array($candidateOps) || !$this->isStringList($candidateOps)) {
                return $this->failed('invalid_emitted_ops');
            }
            $emittedOps = $candidateOps;
        }

        return AgentResult::success(
            $this->name(),
            'legacy_read_complete',
            $data,
            $stateChanged,
            $emittedOps
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    final protected function failed(string $reason, array $data = []): AgentResult
    {
        return AgentResult::failed($this->name(), $reason, $data);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function failureHttpStatus(array $payload): ?int
    {
        $candidates = [
            $payload['api_status'] ?? null,
            $payload['http_status'] ?? null,
            $payload['status'] ?? null,
            $payload['_meta']['api_status'] ?? null,
            $payload['_meta']['http_status'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_int($candidate) && !(is_string($candidate) && ctype_digit($candidate))) {
                continue;
            }

            $status = (int) $candidate;
            if ($status === 429 || ($status >= 500 && $status <= 599)) {
                return $status;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $value */
    private function isStringList(array $value): bool
    {
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }
}
