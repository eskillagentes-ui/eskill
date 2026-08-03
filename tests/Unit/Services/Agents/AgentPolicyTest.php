<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentPolicy;
use App\Services\Agents\AgentResult;
use InvalidArgumentException;
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
        $context = new AgentContext(10, 'staging', 'corr-op', true);
        $changed = AgentResult::success('orquestrador', 'ok', [], true, ['ml.price.patch']);
        $unchanged = AgentResult::success('orquestrador', 'noop', [], false, ['ml.price.patch']);

        $this->assertTrue($this->policy->allowsOpEmission($context, $changed));
        $this->assertFalse($this->policy->allowsOpEmission($context, $unchanged));
    }

    public function testOpBloqueadoQuandoStateChangedFalseMesmoComEmittedOps(): void
    {
        $result = AgentResult::skipped('coletor', 'sem mudanca', [], false, ['op:heartbeat']);

        $this->assertFalse($this->policy->allowsOpEmission(
            new AgentContext(10, 'staging', 'corr-skipped', true),
            $result
        ));
    }

    public function testOpMlEhBloqueadaSemFlagOuEmProductionOuQuandoResultadoFalha(): void
    {
        $success = AgentResult::success('agente', 'ok', [], true, ['ml.item.publish']);
        $failed = AgentResult::failed('agente', 'erro', [], true, ['ml.item.publish']);

        $this->assertFalse($this->policy->allowsOpEmission(
            new AgentContext(10, 'staging', 'corr-off', false),
            $success
        ));
        $this->assertFalse($this->policy->allowsOpEmission(
            new AgentContext(10, 'production', 'corr-prod-op', true),
            $success
        ));
        $this->assertFalse($this->policy->allowsOpEmission(
            new AgentContext(10, 'staging', 'corr-failed-op', true),
            $failed
        ));
    }

    public function testOpForaDaAllowlistEhBloqueada(): void
    {
        $result = AgentResult::success('agente', 'ok', [], true, ['ml.unknown.write']);

        $this->assertFalse($this->policy->allowsOpEmission(
            new AgentContext(10, 'staging', 'corr-unknown-op', true),
            $result
        ));
    }

    /** @dataProvider emittedOpsInvalidasProvider */
    public function testAgentResultRejeitaEmittedOpsQueNaoSejamListaDeStringsNaoVazias(
        array $emittedOps
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('emittedOps');

        AgentResult::success('agente', 'invalid_ops', [], true, $emittedOps);
    }

    /** @return iterable<string, array{array<int|string, int|string>}> */
    public function emittedOpsInvalidasProvider(): iterable
    {
        yield 'inteiro' => [[123]];
        yield 'string vazia' => [['']];
        yield 'somente espacos' => [['   ']];
        yield 'array associativo' => [['op' => 'ml.price.patch']];
        yield 'indices nao sequenciais' => [[0 => 'ml.price.patch', 2 => 'ml.ads.update']];
    }

    public function testLeituraMlNaoEhCapabilityDeEscrita(): void
    {
        $ctx = new AgentContext(10, 'local', 'corr-read', false);

        $this->assertTrue($this->policy->allowsMlRead($ctx, 'ml.items.get'));
        $this->assertFalse($this->policy->allowsMlWrite($ctx, 'ml.items.get'));
    }
}
