<?php

declare(strict_types=1);

namespace App\Services\Agents;

use InvalidArgumentException;
use Throwable;

final class QaAgent implements AgentInterface
{
    public const NAME = 'qa';

    /** @var array<string, callable> */
    private array $checks;

    /**
     * Cada callable deve respeitar callable(AgentContext): AgentResult. O retorno
     * é validado em runtime para que violações do contrato falhem de modo fechado.
     *
     * @param array<string, callable> $checks
     */
    public function __construct(array $checks)
    {
        if ($checks === []) {
            throw new InvalidArgumentException('checks must not be empty');
        }

        foreach ($checks as $id => $check) {
            if (!is_string($id) || trim($id) === '') {
                throw new InvalidArgumentException('check id must be a non-empty string');
            }

            if (!is_callable($check)) {
                throw new InvalidArgumentException('check must be callable');
            }
        }

        $this->checks = $checks;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(AgentContext $context): AgentResult
    {
        $reports = [];
        $order = [];
        $allApproved = true;

        foreach ($this->checks as $id => $check) {
            $order[] = $id;

            try {
                $candidate = $check($context);
                $reason = $candidate instanceof AgentResult
                    ? $this->rejectionReason($id, $candidate)
                    : 'invalid_result';
            } catch (Throwable $ignored) {
                $reason = 'check_exception';
            }

            $approved = $reason === 'approved';
            $allApproved = $allApproved && $approved;
            $reports[$id] = [
                'approved' => $approved,
                'reason' => $reason,
            ];
        }

        $data = [
            'checks' => $reports,
            'order' => $order,
        ];

        if ($allApproved) {
            return AgentResult::success(self::NAME, 'all_checks_passed', $data);
        }

        return AgentResult::failed(self::NAME, 'checks_failed', $data);
    }

    private function rejectionReason(string $id, AgentResult $result): string
    {
        if ($result->status() !== 'success') {
            return 'status_not_success';
        }

        if ($result->agent() !== $id) {
            return 'agent_mismatch';
        }

        if ($result->stateChanged()) {
            return 'state_changed';
        }

        if ($result->emittedOps() !== []) {
            return 'emitted_ops';
        }

        return 'approved';
    }
}
