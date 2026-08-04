<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentInterface;
use App\Services\Agents\AgentPolicy;
use App\Services\Agents\AgentResult;
use App\Services\Agents\OrchestratorAgent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Aceite Bloco 1 — contratos mínimos do runtime comum.
 *
 * @covers \App\Services\Agents\AgentContext
 * @covers \App\Services\Agents\AgentPolicy
 * @covers \App\Services\Agents\OrchestratorAgent
 */
class AgentRuntimeContractsTest extends TestCase
{
    public function testAccountIdInvalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentContext(0, 'local', 'corr-1', false);
    }

    public function testEnvironmentInvalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentContext(1, 'prod', 'corr-1', false);
    }

    public function testWriteFalseBloqueiaCapabilityWrite(): void
    {
        $policy = new AgentPolicy();
        $ctx = new AgentContext(10, 'staging', 'corr-w0', false);

        $this->assertFalse($policy->allowsMlWrite($ctx, 'ml.price.patch'));
        $this->assertFalse($policy->allowsMlWrite($ctx, 'ml.ads.update'));
        $this->assertFalse($policy->allowsMlWrite($ctx, 'ml.item.publish'));
    }

    public function testWriteTrueEmStagingPermite(): void
    {
        $policy = new AgentPolicy();
        $ctx = new AgentContext(10, 'staging', 'corr-w1', true);

        $this->assertTrue($policy->allowsMlWrite($ctx, 'ml.price.patch'));
    }

    public function testWriteTrueEmProductionContinuaBloqueado(): void
    {
        $policy = new AgentPolicy();
        $ctx = new AgentContext(10, 'production', 'corr-w2', true);

        $this->assertTrue($policy->isFailClosed($ctx));
        $this->assertFalse($policy->allowsMlWrite($ctx, 'ml.price.patch'));
    }

    public function testOpSemMudancaESuprimida(): void
    {
        $policy = new AgentPolicy();
        $context = new AgentContext(10, 'local', 'corr-op0', false);
        $semMudanca = AgentResult::success('coletor', 'noop', [], false, ['op:tick']);

        $this->assertFalse($policy->allowsOpEmission($context, $semMudanca));

        $orch = new OrchestratorAgent([
            $this->agent('coletor', static function (AgentContext $ctx): AgentResult {
                return AgentResult::success('coletor', 'noop', [], false, ['op:tick']);
            }),
        ], $policy);

        $aggregated = $orch->run($context);
        $this->assertSame([], $aggregated->emittedOps());
        $this->assertFalse($aggregated->stateChanged());
    }

    public function testExcecaoEmUmAgenteNaoImpedeOProximo(): void
    {
        $orch = new OrchestratorAgent([
            $this->agent('falha', static function (AgentContext $ctx): AgentResult {
                throw new RuntimeException('falha isolada');
            }),
            $this->agent('seguinte', static function (AgentContext $ctx): AgentResult {
                return AgentResult::success('seguinte', 'ok');
            }),
        ], new AgentPolicy());

        $result = $orch->run(new AgentContext(10, 'local', 'corr-iso', false));
        /** @var list<AgentResult> $results */
        $results = $result->data()['results'];

        $this->assertSame('failed', $results[0]->status());
        $this->assertSame('success', $results[1]->status());
        $this->assertSame('seguinte', $results[1]->agent());
        $this->assertSame('failed', $result->status());
    }

    public function testOrdemPreservada(): void
    {
        $seen = [];
        $orch = new OrchestratorAgent([
            $this->agent('primeiro', function (AgentContext $ctx) use (&$seen): AgentResult {
                $seen[] = 'primeiro';
                return AgentResult::success('primeiro', 'ok');
            }),
            $this->agent('segundo', function (AgentContext $ctx) use (&$seen): AgentResult {
                $seen[] = 'segundo';
                return AgentResult::success('segundo', 'ok');
            }),
            $this->agent('terceiro', function (AgentContext $ctx) use (&$seen): AgentResult {
                $seen[] = 'terceiro';
                return AgentResult::success('terceiro', 'ok');
            }),
        ], new AgentPolicy());

        $result = $orch->run(new AgentContext(10, 'local', 'corr-ord', false));

        $this->assertSame(['primeiro', 'segundo', 'terceiro'], $seen);
        $this->assertSame(['primeiro', 'segundo', 'terceiro'], array_map(static fn ($item): string => $item->agent(), $result->data()['results']));
    }

    /**
     * @param callable(AgentContext): AgentResult $fn
     */
    private function agent(string $name, callable $fn): AgentInterface
    {
        return new class ($name, $fn) implements AgentInterface {
            /** @var callable(AgentContext): AgentResult */
            private $fn;

            public function __construct(
                private string $agentName,
                callable $fn
            ) {
                $this->fn = $fn;
            }

            public function name(): string
            {
                return $this->agentName;
            }

            public function run(AgentContext $context): AgentResult
            {
                return ($this->fn)($context);
            }
        };
    }
}
