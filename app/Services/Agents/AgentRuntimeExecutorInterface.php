<?php

declare(strict_types=1);

namespace App\Services\Agents;

interface AgentRuntimeExecutorInterface
{
    public function execute(
        int $accountId,
        string $correlationId,
        string $environment,
        string $mode
    ): AgentResult;
}
