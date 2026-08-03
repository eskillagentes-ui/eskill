<?php

declare(strict_types=1);

namespace App\Services\Agents;

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

        foreach ($this->agents as $agent) {
            $order[] = $agent->name();

            try {
                $agentResult = $agent->run($context);
            } catch (Throwable $e) {
                $agentResult = AgentResult::failed(
                    $agent->name(),
                    $e->getMessage() !== '' ? $e->getMessage() : $e::class
                );
            }

            $results[] = $agentResult;

            if ($agentResult->stateChanged()) {
                $stateChanged = true;
            }

            if ($this->policy->allowsOpEmission($agentResult)) {
                foreach ($agentResult->emittedOps() as $op) {
                    $emittedOps[] = $op;
                }
            }
        }

        return AgentResult::success(
            self::NAME,
            'aggregated',
            [
                'correlationId' => $context->correlationId(),
                'results' => $results,
                'order' => $order,
                'mlWriteAutomation' => $context->mlWriteAutomation(),
            ],
            $stateChanged,
            $emittedOps
        );
    }
}
