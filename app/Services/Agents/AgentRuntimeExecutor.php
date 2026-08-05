<?php

declare(strict_types=1);

namespace App\Services\Agents;

use InvalidArgumentException;

final class AgentRuntimeExecutor implements AgentRuntimeExecutorInterface
{
    private AgentRuntimeFactory $factory;

    public function __construct(?AgentRuntimeFactory $factory = null)
    {
        $this->factory = $factory ?? new AgentRuntimeFactory();
    }

    public function execute(
        int $accountId,
        string $correlationId,
        string $environment,
        string $mode
    ): AgentResult {
        if ($mode !== 'monitor') {
            throw new InvalidArgumentException('unsupported worker mode');
        }

        $context = $this->factory->buildContext(
            $accountId,
            $correlationId,
            ['environment' => $environment]
        );

        return $this->factory->createOrchestrator('monitor')->run($context);
    }
}
