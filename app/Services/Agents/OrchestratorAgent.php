<?php

declare(strict_types=1);

namespace App\Services\Agents;

use InvalidArgumentException;
use Throwable;

/**
 * Orquestrador mínimo: executa agentes explícitos em ordem, isola falhas,
 * agrega resultados e nunca escreve no Mercado Livre.
 */
final class OrchestratorAgent implements AgentInterface
{
    public const NAME = 'orquestrador';

    /** @var list<AgentInterface> */
    private array $agents;

    private AgentPolicy $policy;

    /**
     * @param list<AgentInterface> $agents
     */
    public function __construct(array $agents, AgentPolicy $policy)
    {
        if ($agents === []) {
            throw new InvalidArgumentException('agents must not be empty');
        }

        $this->agents = array_values($agents);
        $this->policy = $policy;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(AgentContext $context): AgentResult
    {
        $results = [];
        $order = [];
        $emittedOps = [];
        $stateChanged = false;
        $hasBlocked = false;
        $hasFailed = false;

        foreach ($this->agents as $index => $agent) {
            try {
                $agentName = $agent->name();
            } catch (Throwable) {
                $agentName = 'unknown-agent-' . $index;
                $order[] = $agentName;
                $results[] = AgentResult::failed($agentName, 'agent_name_exception');
                $hasFailed = true;
                continue;
            }

            $order[] = $agentName;

            try {
                $agentResult = $agent->run($context);
            } catch (Throwable) {
                $agentResult = AgentResult::failed(
                    $agentName,
                    'agent_exception'
                );
            }

            $results[] = $agentResult;

            if ($agentResult->status() === 'blocked') {
                $hasBlocked = true;
            }

            if ($agentResult->status() === 'failed') {
                $hasFailed = true;
            }

            if ($this->policy->allowsOpEmission($context, $agentResult)) {
                $stateChanged = true;
                foreach ($agentResult->emittedOps() as $op) {
                    $emittedOps[] = $op;
                }
            }
        }

        $data = [
            'correlationId' => $context->correlationId(),
            'results' => $results,
            'order' => $order,
            'mlWriteAutomation' => $context->mlWriteAutomation(),
        ];

        if ($hasFailed) {
            return AgentResult::failed(self::NAME, 'agent_failed', $data);
        }

        if ($hasBlocked) {
            return AgentResult::blocked(self::NAME, 'agent_blocked', $data);
        }

        return AgentResult::success(
            self::NAME,
            'aggregated',
            $data,
            $stateChanged,
            array_values(array_unique($emittedOps))
        );
    }
}
