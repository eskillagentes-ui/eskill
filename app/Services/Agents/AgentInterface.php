<?php

declare(strict_types=1);

namespace App\Services\Agents;

interface AgentInterface
{
    public function name(): string;

    public function run(AgentContext $context): AgentResult;
}
