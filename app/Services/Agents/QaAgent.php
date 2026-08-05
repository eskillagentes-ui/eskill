<?php

declare(strict_types=1);

namespace App\Services\Agents;

/** Consolida resultados QA imutáveis já presentes no contexto. */
final class QaAgent implements AgentInterface
{
    public const NAME = 'qa';

    public function __construct()
    {
        if (func_num_args() !== 0) {
            throw new \InvalidArgumentException('snapshot agents do not accept dependencies');
        }
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(AgentContext $context): AgentResult
    {
        $raw = $context->metadata()['qa_results_snapshot'] ?? null;
        $payload = SnapshotEnvelope::extract(
            $raw,
            $context->accountId(),
            $context->correlationId(),
            true
        );
        if ($payload === null || !$this->validPayload($payload)) {
            return AgentResult::failed(self::NAME, 'invalid_qa_results_snapshot');
        }

        /** @var array<string, AgentResult> $snapshot */
        $snapshot = $payload['results'];
        $reports = [];
        $order = [];
        $allApproved = true;
        foreach (QaMergeGate::REQUIRED_CHECK_IDS as $id) {
            $order[] = $id;
            $candidate = $snapshot[$id];
            $reason = $this->rejectionReason($id, $candidate);
            $approved = $reason === 'approved';
            $allApproved = $allApproved && $approved;
            $reports[$id] = ['approved' => $approved, 'reason' => $reason];
        }
        $data = ['checks' => $reports, 'order' => $order];

        return $allApproved
            ? AgentResult::success(self::NAME, 'all_checks_passed', $data)
            : AgentResult::failed(self::NAME, 'checks_failed', $data);
    }

    private function validPayload(mixed $payload): bool
    {
        if (!is_array($payload)
            || array_keys($payload) !== ['results']
            || !is_array($payload['results'])
        ) {
            return false;
        }
        $results = $payload['results'];
        $ids = array_keys($results);
        if ($ids !== QaMergeGate::REQUIRED_CHECK_IDS) {
            return false;
        }
        foreach ($results as $id => $result) {
            if (!is_string($id) || !$result instanceof AgentResult) {
                return false;
            }
        }

        return true;
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
