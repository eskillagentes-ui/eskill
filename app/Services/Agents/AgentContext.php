<?php

declare(strict_types=1);

namespace App\Services\Agents;

use InvalidArgumentException;

/** Contexto imutável compartilhado pelos agentes do runtime. */
final class AgentContext
{
    /** @var list<string> */
    public const ENVIRONMENTS = ['local', 'staging', 'production'];

    private int $accountId;

    /** @var 'local'|'staging'|'production' */
    private string $environment;

    private string $correlationId;
    private bool $mlWriteAutomation;

    /** @var array<string, mixed> */
    private array $metadata;

    /** @param array<string, mixed> $metadata */
    public function __construct(
        int $accountId,
        string $environment,
        string $correlationId,
        bool $mlWriteAutomation,
        array $metadata = []
    ) {
        if ($accountId <= 0) {
            throw new InvalidArgumentException('accountId must be a positive integer');
        }
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('environment must be one of: ' . implode('|', self::ENVIRONMENTS));
        }
        if (trim($correlationId) === '') {
            throw new InvalidArgumentException('correlationId must be a non-empty string');
        }

        $this->accountId = $accountId;
        $this->environment = $environment;
        $this->correlationId = $correlationId;
        $this->mlWriteAutomation = $mlWriteAutomation;
        $this->metadata = self::canonicalizeMetadata($metadata);
    }

    public function accountId(): int
    {
        return $this->accountId;
    }

    /** @return 'local'|'staging'|'production' */
    public function environment(): string
    {
        return $this->environment;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function mlWriteAutomation(): bool
    {
        return $this->mlWriteAutomation;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $out = [];
        foreach ($this->metadata as $key => $value) {
            if ($key === 'qa_results_snapshot') {
                $out[$key] = self::exportQaEnvelope($value);
                continue;
            }
            $out[$key] = PureSnapshot::normalize($value, false);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private static function canonicalizeMetadata(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('metadata keys must be non-empty strings');
            }
            if ($key === 'qa_results_snapshot') {
                $out[$key] = self::canonicalizeQaEnvelope($value);
                continue;
            }
            $out[$key] = PureSnapshot::normalize($value, false);
        }

        return $out;
    }

    private static function canonicalizeQaEnvelope(mixed $value): mixed
    {
        if (!is_array($value)) {
            return PureSnapshot::normalize($value, false);
        }

        if (
            self::hasExactKeys($value, SnapshotEnvelope::KEYS)
            && is_array($value['payload'])
            && self::hasExactKeys($value['payload'], ['results'])
            && is_array($value['payload']['results'])
        ) {
            $results = [];
            foreach ($value['payload']['results'] as $id => $result) {
                if (!is_string($id) || !$result instanceof AgentResult) {
                    throw new InvalidArgumentException('qa results must be AgentResult instances');
                }
                $results[$id] = PureSnapshot::normalize($result, true);
            }

            return [
                'account_id' => $value['account_id'],
                'correlation_id' => $value['correlation_id'],
                'payload' => ['results' => $results],
            ];
        }

        throw new InvalidArgumentException('qa_results_snapshot must be a provenance envelope');
    }

    /** @param array<array-key, mixed> $value @param list<string> $expected */
    private static function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private static function exportQaEnvelope(mixed $value): mixed
    {
        if (!is_array($value)) {
            return PureSnapshot::normalize($value, false);
        }
        $results = [];
        $sourceResults = $value['payload']['results'] ?? [];
        if (!is_array($sourceResults)) {
            return PureSnapshot::normalize($value, false);
        }
        foreach ($sourceResults as $id => $result) {
            $results[$id] = PureSnapshot::normalize($result, true);
        }

        return [
            'account_id' => $value['account_id'],
            'correlation_id' => $value['correlation_id'],
            'payload' => ['results' => $results],
        ];
    }
}
