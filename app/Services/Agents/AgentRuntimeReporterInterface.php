<?php

declare(strict_types=1);

namespace App\Services\Agents;

interface AgentRuntimeReporterInterface
{
    public function report(
        int $accountId,
        string $correlationId,
        AgentResult $result,
        int $attempts
    ): void;
}
