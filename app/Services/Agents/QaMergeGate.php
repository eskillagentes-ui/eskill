<?php

declare(strict_types=1);

namespace App\Services\Agents;

use RuntimeException;

final class QaMergeGate
{
    private const REJECTION_REASON = 'qa_merge_gate_rejected';

    /** @var list<string> */
    public const REQUIRED_CHECK_IDS = [
        'php-lint',
        'phpunit-agents',
        'phpunit-unit',
        'playwright-readonly',
    ];

    public function assertPasses(QaAgent $qa, AgentContext $context): void
    {
        if (!$this->passes($qa->run($context))) {
            throw new RuntimeException(self::REJECTION_REASON);
        }
    }

    private function passes(AgentResult $result): bool
    {
        if ($result->agent() !== QaAgent::NAME
            || $result->status() !== 'success'
            || $result->stateChanged()
            || $result->emittedOps() !== []
        ) {
            return false;
        }

        $data = $result->data();
        if (count($data) !== 2
            || !isset($data['checks'], $data['order'])
            || !is_array($data['checks'])
            || !is_array($data['order'])
            || $data['order'] !== self::REQUIRED_CHECK_IDS
            || array_keys($data['checks']) !== self::REQUIRED_CHECK_IDS
        ) {
            return false;
        }

        foreach (self::REQUIRED_CHECK_IDS as $id) {
            $report = $data['checks'][$id] ?? null;
            if (!is_array($report)
                || count($report) !== 2
                || ($report['approved'] ?? null) !== true
                || ($report['reason'] ?? null) !== 'approved'
            ) {
                return false;
            }
        }

        return true;
    }
}
