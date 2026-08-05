<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Services\Pregao\PregaoEmitService;
use App\Services\Pregao\PregaoAgentStatusService;
use InvalidArgumentException;

/**
 * Publica somente o estado operacional sanitizado dos agentes no Pregão.
 */
final class PregaoAgentRuntimeReporter implements AgentRuntimeReporterInterface
{
    /** @var array<string, string> */
    private const AGENT_MAP = [
        'sentinela' => 'sentinela',
        'coletor' => 'collector',
        'financeiro' => 'financeiro',
        'otimizador' => 'otimizador',
        'orquestrador' => 'orquestrador',
    ];

    /** @var list<string> */
    private const SAFE_REASONS = [
        'aggregated',
        'agent_blocked',
        'agent_exception',
        'agent_failed',
        'collector_unavailable',
        'cost_validation_blocked',
        'financeiro_unavailable',
        'incomplete_legacy_payload',
        'invalid_legacy_payload',
        'invalid_optimizer_cost_snapshot',
        'invalid_optimizer_observation_snapshot',
        'legacy_error',
        'legacy_read_complete',
        'read_only_violation',
        'recommendations_ready',
        'runtime_exception',
        'sentinela_unavailable',
    ];

    private PregaoEmitService $emitter;

    public function __construct(?PregaoEmitService $emitter = null)
    {
        $this->emitter = $emitter ?? new PregaoEmitService();
    }

    public function report(
        int $accountId,
        string $correlationId,
        AgentResult $result,
        int $attempts
    ): void {
        $expectedCorrelation = '/^agent24x7-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}:'
            . preg_quote((string) $accountId, '/') . '$/D';
        if ($accountId <= 0
            || preg_match($expectedCorrelation, $correlationId) !== 1
            || $attempts < 1
            || $attempts > 3
        ) {
            throw new InvalidArgumentException('invalid agent telemetry options');
        }

        if ($result->agent() === 'agent-runtime' && $result->reason() === 'runtime_exception') {
            $this->assertReadOnlyResult($result, true);
            $this->emitPayload(
                $accountId,
                $correlationId,
                'orquestrador',
                'failed',
                'runtime_exception',
                $attempts
            );
            return;
        }

        if ($result->agent() !== 'orquestrador') {
            throw new InvalidArgumentException('invalid aggregate agent telemetry result');
        }

        $data = $result->data();
        if (($data['correlationId'] ?? null) !== $correlationId
            || ($data['mlWriteAutomation'] ?? null) !== false
        ) {
            throw new InvalidArgumentException('invalid aggregate telemetry provenance');
        }
        $children = $data['results'] ?? null;
        if (!is_array($children)
            || array_values($children) !== $children
            || count($children) !== 4
        ) {
            throw new InvalidArgumentException('invalid agent telemetry results');
        }

        $this->assertReadOnlyResult($result, false);
        $validated = [];
        $seen = [];
        foreach ($children as $child) {
            if (!$child instanceof AgentResult) {
                throw new InvalidArgumentException('invalid agent telemetry result');
            }
            $this->assertReadOnlyResult($child, false);
            $sourceAgent = $child->agent();
            if ($sourceAgent === 'orquestrador'
                || !isset(self::AGENT_MAP[$sourceAgent])
                || isset($seen[$sourceAgent])
            ) {
                throw new InvalidArgumentException('invalid child agent telemetry result');
            }
            $seen[$sourceAgent] = true;
            $validated[] = $child;
        }

        foreach ($validated as $child) {
            $this->emitResult($accountId, $correlationId, $child, $attempts);
        }
        $this->emitResult($accountId, $correlationId, $result, $attempts);
    }

    private function assertReadOnlyResult(AgentResult $result, bool $runtimeFailure): void
    {
        if ($result->stateChanged() !== false || $result->emittedOps() !== []) {
            throw new InvalidArgumentException('mutable agent telemetry result');
        }
        if ($runtimeFailure) {
            return;
        }
        if (!isset(self::AGENT_MAP[$result->agent()])
            || !$this->isSafeReason($result->reason())
            || !PregaoAgentStatusService::isStatusReasonCoherent($result->status(), $result->reason())
        ) {
            throw new InvalidArgumentException('unsafe agent telemetry result');
        }
    }

    private function isSafeReason(string $reason): bool
    {
        return in_array($reason, self::SAFE_REASONS, true)
            || preg_match('/^legacy_http_[1345][0-9]{2}$/D', $reason) === 1;
    }

    private function emitResult(
        int $accountId,
        string $correlationId,
        AgentResult $result,
        int $attempts
    ): void {
        $this->emitPayload(
            $accountId,
            $correlationId,
            self::AGENT_MAP[$result->agent()],
            $result->status(),
            $result->reason(),
            $attempts
        );
    }

    private function emitPayload(
        int $accountId,
        string $correlationId,
        string $agent,
        string $status,
        string $reason,
        int $attempts
    ): void {
        $this->emitter->emit('agent.status', [
            'agent' => $agent,
            'status' => $status,
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'attempts' => $attempts,
            'state_changed' => false,
            'ml_write_automation' => false,
        ], $accountId, 'live');
    }
}
