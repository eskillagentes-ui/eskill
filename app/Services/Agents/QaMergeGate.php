<?php

declare(strict_types=1);

namespace App\Services\Agents;

use InvalidArgumentException;
use RuntimeException;

final class QaMergeGate
{
    private const REJECTION_REASON = 'qa_merge_gate_rejected';

    /** @var list<string> */
    private array $requiredIds;

    /** @param list<string> $requiredIds */
    public function __construct(array $requiredIds)
    {
        if ($requiredIds === []) {
            throw new InvalidArgumentException('requiredIds must not be empty');
        }

        $validated = [];
        foreach ($requiredIds as $id) {
            if (!is_string($id) || trim($id) === '') {
                throw new InvalidArgumentException('required id must be a non-empty string');
            }

            if (in_array($id, $validated, true)) {
                throw new InvalidArgumentException('required ids must be unique');
            }

            $validated[] = $id;
        }

        $this->requiredIds = $validated;
    }

    public function assertPasses(AgentResult $result): void
    {
        if (!$this->passes($result)) {
            throw new RuntimeException(self::REJECTION_REASON);
        }
    }

    private function passes(AgentResult $result): bool
    {
        if ($result->agent() !== QaAgent::NAME || $result->status() !== 'success') {
            return false;
        }

        if ($result->stateChanged() || $result->emittedOps() !== []) {
            return false;
        }

        $data = $result->data();
        if (
            count($data) !== 2
            || !array_key_exists('checks', $data)
            || !array_key_exists('order', $data)
            || !is_array($data['checks'])
            || !is_array($data['order'])
        ) {
            return false;
        }

        $checks = $data['checks'];
        $order = $data['order'];
        if (!$this->isSequentialList($order) || array_keys($checks) !== $order) {
            return false;
        }

        foreach ($checks as $id => $report) {
            if (!is_string($id) || trim($id) === '' || !is_array($report)) {
                return false;
            }

            if (
                count($report) !== 2
                || !array_key_exists('approved', $report)
                || !array_key_exists('reason', $report)
                || $report['approved'] !== true
                || $report['reason'] !== 'approved'
            ) {
                return false;
            }
        }

        foreach ($this->requiredIds as $requiredId) {
            if (!array_key_exists($requiredId, $checks)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int|string, scalar|array|null> $values */
    private function isSequentialList(array $values): bool
    {
        $expectedKey = 0;
        foreach ($values as $key => $value) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }

        return true;
    }
}
