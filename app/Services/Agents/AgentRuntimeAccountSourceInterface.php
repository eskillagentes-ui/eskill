<?php

declare(strict_types=1);

namespace App\Services\Agents;

interface AgentRuntimeAccountSourceInterface
{
    /** @return list<int> */
    public function activeAccountIds(): array;
}
