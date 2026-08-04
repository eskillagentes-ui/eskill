<?php

declare(strict_types=1);

namespace App\Services\Agents;

/** Base para transformar snapshots legados read-only já presentes no contexto. */
abstract class LegacyReadOnlyAgentAdapter implements AgentInterface
{
    final public function __construct()
    {
        if (func_num_args() !== 0) {
            throw new \InvalidArgumentException('snapshot agents do not accept dependencies');
        }
    }

    final public function run(AgentContext $context): AgentResult
    {
        $raw = $context->metadata()[$this->snapshotKey()] ?? null;
        $payload = SnapshotEnvelope::extract(
            $raw,
            $context->accountId(),
            $context->correlationId()
        );
        if ($payload === null || !$this->hasValidEnvelope($payload)) {
            return $this->failed('invalid_legacy_payload');
        }

        $httpStatus = $this->failureHttpStatus($payload);
        if ($httpStatus !== null) {
            return $this->failed('legacy_http_' . $httpStatus);
        }
        if (
            ($payload['incomplete'] ?? false) === true
            || ($payload['error'] ?? null) === 'pagination_incomplete'
            || ($payload['_meta']['incomplete'] ?? false) === true
        ) {
            return $this->failed('incomplete_legacy_payload');
        }
        if ($this->hasDisallowedError($payload)) {
            return $this->failed('legacy_error');
        }
        if ($this->violatesReadOnlyContract($payload)) {
            return $this->failed('read_only_violation');
        }

        return $this->mapPayload($payload);
    }

    abstract protected function snapshotKey(): string;

    /** @return list<string> */
    abstract protected function payloadKeys(): array;

    /** @param array<string, mixed> $payload */
    abstract protected function mapPayload(array $payload): AgentResult;

    /** @param array<string, mixed> $data */
    final protected function success(array $data): AgentResult
    {
        return AgentResult::success($this->name(), 'legacy_read_complete', $data);
    }

    /** @param array<string, mixed> $data */
    final protected function failed(string $reason, array $data = []): AgentResult
    {
        return AgentResult::failed($this->name(), $reason, $data);
    }

    /** @param array<string, mixed> $payload */
    private function hasValidEnvelope(array $payload): bool
    {
        $allowed = array_merge($this->payloadKeys(), [
            'ok', '_meta', 'api_status', 'http_status', 'status', 'incomplete',
            'error', 'state_changed', 'emitted_ops',
        ]);
        if ($this->hasExtraKeys($payload, $allowed)) {
            return false;
        }
        if (!array_key_exists('ok', $payload) || !is_bool($payload['ok'])) {
            return false;
        }
        if (array_key_exists('incomplete', $payload) && !is_bool($payload['incomplete'])) {
            return false;
        }
        if (array_key_exists('error', $payload)
            && $payload['error'] !== null
            && !is_string($payload['error'])
        ) {
            return false;
        }
        foreach (['api_status', 'http_status', 'status'] as $statusKey) {
            if (array_key_exists($statusKey, $payload) && !$this->isHttpStatus($payload[$statusKey])) {
                return false;
            }
        }
        if (array_key_exists('_meta', $payload)) {
            $meta = $payload['_meta'];
            if (!is_array($meta)
                || $this->hasExtraKeys($meta, ['api_status', 'http_status', 'incomplete'])
                || (array_key_exists('incomplete', $meta) && !is_bool($meta['incomplete']))
                || (array_key_exists('api_status', $meta) && !$this->isHttpStatus($meta['api_status']))
                || (array_key_exists('http_status', $meta) && !$this->isHttpStatus($meta['http_status']))
            ) {
                return false;
            }
        }

        return true;
    }

    private function isHttpStatus(mixed $value): bool
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return false;
        }
        $status = (int) $value;

        return $status >= 100 && $status <= 599;
    }

    /** @param array<string, mixed> $payload */
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
            // Somente 2xx representa sucesso HTTP legado.
            if ($status < 200 || $status > 299) {
                return $status;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function hasDisallowedError(array $payload): bool
    {
        if (!array_key_exists('error', $payload)) {
            return false;
        }
        $error = $payload['error'];
        if ($error === null || $error === '') {
            return false;
        }
        if ($error === 'pagination_incomplete') {
            return false;
        }

        return is_string($error);
    }

    /** @param array<string, mixed> $payload */
    private function violatesReadOnlyContract(array $payload): bool
    {
        if (array_key_exists('state_changed', $payload) && $payload['state_changed'] !== false) {
            return true;
        }

        return array_key_exists('emitted_ops', $payload)
            && (!is_array($payload['emitted_ops']) || $payload['emitted_ops'] !== []);
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private function hasExtraKeys(array $value, array $allowed): bool
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return true;
            }
        }

        return false;
    }
}
