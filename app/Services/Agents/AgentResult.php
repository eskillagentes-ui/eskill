<?php

declare(strict_types=1);

namespace App\Services\Agents;

use InvalidArgumentException;

/**
 * Resultado tipado de uma execução de agente.
 */
final class AgentResult
{
    /** @var list<string> */
    public const STATUSES = ['success', 'skipped', 'blocked', 'failed'];

    private string $status;

    private string $agent;

    private string $reason;

    /** @var array<string, mixed> */
    private array $data;

    private bool $stateChanged;

    /** @var list<string> */
    private array $emittedOps;

    /**
     * @param array<string, mixed> $data
     * @param list<string> $emittedOps
     */
    private function __construct(
        string $status,
        string $agent,
        string $reason,
        array $data,
        bool $stateChanged,
        array $emittedOps
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(
                'status must be one of: ' . implode('|', self::STATUSES)
            );
        }

        if (trim($agent) === '') {
            throw new InvalidArgumentException('agent must be a non-empty string');
        }

        $this->status = $status;
        $this->agent = $agent;
        $this->reason = $reason;
        $this->data = $data;
        $this->stateChanged = $stateChanged;
        $this->emittedOps = array_values($emittedOps);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $emittedOps
     */
    public static function success(
        string $agent,
        string $reason = '',
        array $data = [],
        bool $stateChanged = false,
        array $emittedOps = []
    ): self {
        return new self('success', $agent, $reason, $data, $stateChanged, $emittedOps);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $emittedOps
     */
    public static function skipped(
        string $agent,
        string $reason = '',
        array $data = [],
        bool $stateChanged = false,
        array $emittedOps = []
    ): self {
        return new self('skipped', $agent, $reason, $data, $stateChanged, $emittedOps);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $emittedOps
     */
    public static function blocked(
        string $agent,
        string $reason = '',
        array $data = [],
        bool $stateChanged = false,
        array $emittedOps = []
    ): self {
        return new self('blocked', $agent, $reason, $data, $stateChanged, $emittedOps);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $emittedOps
     */
    public static function failed(
        string $agent,
        string $reason = '',
        array $data = [],
        bool $stateChanged = false,
        array $emittedOps = []
    ): self {
        return new self('failed', $agent, $reason, $data, $stateChanged, $emittedOps);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function agent(): string
    {
        return $this->agent;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function stateChanged(): bool
    {
        return $this->stateChanged;
    }

    /** @return list<string> */
    public function emittedOps(): array
    {
        return $this->emittedOps;
    }
}
