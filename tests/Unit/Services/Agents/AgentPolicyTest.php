<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentPolicy;
use App\Services\Agents\AgentResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Agents\AgentPolicy
 */
class AgentPolicyTest extends TestCase
{
    private AgentPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AgentPolicy();
    }

    public function testBloqueiaEscritaMlQuandoMlWriteAutomationFalse(): void
    {
        $ctx = new AgentContext(10, 'local', 'corr-1', false);

        $this->assertFalse($this->policy->allowsMlWrite($ctx, 'ml.price.patch'));
        $this->assertFalse($this->policy->allowsMlWrite($ctx, 'ml.ads.update'));
        $this->assertFalse($this->policy->allowsMlWrite($ctx, 'ml.item.publish'));
    }

    public function testPermiteEscritaMlSomenteComFlagTrueForaDeProduction(): void
    {
        $ctx = new AgentContext(10, 'staging', 'corr-2', true);

        $this->assertTrue($this->policy->allowsMlWrite($ctx, 'ml.price.patch'));
    }

    public function testProductionFailClosedBloqueiaEscritaMesmoComFlagTrue(): void
    {
        $ctx = new AgentContext(10, 'production', 'corr-3', true);

        $this->assertTrue($this->policy->isFailClosed($ctx));
        $this->assertFalse($this->policy->allowsMlWrite($ctx, 'ml.price.patch'));
    }

    public function testProductionSempreFailClosed(): void
    {
        $prod = new AgentContext(1, 'production', 'corr-prod', false);
        $local = new AgentContext(1, 'local', 'corr-local', false);
        $staging = new AgentContext(1, 'staging', 'corr-stg', false);

        $this->assertTrue($this->policy->isFailClosed($prod));
        $this->assertFalse($this->policy->isFailClosed($local));
        $this->assertFalse($this->policy->isFailClosed($staging));
    }

    public function testOpSomenteQuandoStateChangedTrueP0(): void
    {
        $changed = AgentResult::success('orquestrador', 'ok', [], true, ['op:tick']);
        $unchanged = AgentResult::success('orquestrador', 'noop', [], false, ['op:tick']);

        $this->assertTrue($this->policy->allowsOpEmission($changed));
        $this->assertFalse($this->policy->allowsOpEmission($unchanged));
    }

    public function testOpBloqueadoQuandoStateChangedFalseMesmoComEmittedOps(): void
    {
        $result = AgentResult::skipped('coletor', 'sem mudanca', [], false, ['op:heartbeat']);

        $this->assertFalse($this->policy->allowsOpEmission($result));
    }

    public function testLeituraMlNaoEhCapabilityDeEscrita(): void
    {
        $ctx = new AgentContext(10, 'local', 'corr-read', false);

        $this->assertTrue($this->policy->allowsMlRead($ctx, 'ml.items.get'));
        $this->assertFalse($this->policy->allowsMlWrite($ctx, 'ml.items.get'));
    }
}
