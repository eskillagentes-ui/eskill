<?php

declare(strict_types=1);

namespace App\Services\Agents;

use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

final class AgentRuntimeWorker
{
    private const ENVIRONMENTS = ['local', 'staging', 'production'];

    private AgentRuntimeAccountSourceInterface $accountSource;
    private AgentRuntimeExecutorInterface $executor;

    public function __construct(
        AgentRuntimeAccountSourceInterface $accountSource,
        AgentRuntimeExecutorInterface $executor
    ) {
        $this->accountSource = $accountSource;
        $this->executor = $executor;
    }

    /**
     * @return list<array{
     *   accountId: int,
     *   correlation: string,
     *   status: string,
     *   reason: string,
     *   attempts: int
     * }>
     */
    public function runCycle(string $environment, string $cycleId, int $maxAttempts = 2): array
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/D', $cycleId) !== 1
            || $maxAttempts < 1
            || $maxAttempts > 3
        ) {
            throw new InvalidArgumentException('invalid worker cycle options');
        }

        $accountIds = $this->accountSource->activeAccountIds();
        $this->assertAccountIds($accountIds);
        $records = [];

        foreach ($accountIds as $accountId) {
            $correlationId = $cycleId . ':' . $accountId;
            $attempts = 0;
            do {
                $attempts++;
                try {
                    $result = $this->executor->execute(
                        $accountId,
                        $correlationId,
                        $environment,
                        'monitor'
                    );
                } catch (Throwable) {
                    $result = AgentResult::failed('agent-runtime', 'runtime_exception');
                }
            } while ($result->status() === 'failed' && $attempts < $maxAttempts);

            $records[] = [
                'accountId' => $accountId,
                'correlation' => $correlationId,
                'status' => $result->status(),
                'reason' => $result->reason(),
                'attempts' => $attempts,
            ];
        }

        return $records;
    }

    /** @param array<array-key, mixed> $accountIds */
    private function assertAccountIds(array $accountIds): void
    {
        if ($accountIds !== [] && array_keys($accountIds) !== range(0, count($accountIds) - 1)) {
            throw new UnexpectedValueException('invalid account list');
        }
        if (count($accountIds) > 200) {
            throw new UnexpectedValueException('invalid account list');
        }

        $seen = [];
        foreach ($accountIds as $accountId) {
            if (!is_int($accountId) || $accountId <= 0 || isset($seen[$accountId])) {
                throw new UnexpectedValueException('invalid account list');
            }
            $seen[$accountId] = true;
        }
    }
}
