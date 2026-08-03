<?php

declare(strict_types=1);

namespace App\Services\Agents;

use InvalidArgumentException;

/**
 * Contexto imutável compartilhado pelos agentes do runtime.
 */
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

    /**
     * @param array<string, mixed> $metadata
     */
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
            throw new InvalidArgumentException(
                'environment must be one of: ' . implode('|', self::ENVIRONMENTS)
            );
        }

        if (trim($correlationId) === '') {
            throw new InvalidArgumentException('correlationId must be a non-empty string');
        }

        $this->accountId = $accountId;
        $this->environment = $environment;
        $this->correlationId = $correlationId;
        $this->mlWriteAutomation = $mlWriteAutomation;
        $this->metadata = $metadata;
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
        return $this->metadata;
    }
}
