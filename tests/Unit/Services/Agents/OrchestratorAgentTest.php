<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentInterface;
use App\Services\Agents\AgentPolicy;
use App\Services\Agents\AgentResult;
use App\Services\Agents\OrchestratorAgent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \App\Services\Agents\OrchestratorAgent
 */
class OrchestratorAgentTest extends TestCase
{
    public function testNameRetornaOrquestrador(): void
    {
        $orch = new OrchestratorAgent([], new AgentPolicy());
        $this->assertSame('orquestrador', $orch->name());
    }

    public function testExecutaAgentesNaOrdemExplicita(): void
    {
        $order = [];
        $a = $this->agent('alpha', function (AgentContext $ctx) use (&$order): AgentResult {
            $order[] = 'alpha';
            return AgentResult::success('alpha', 'ok', ['n' => 1]);
        });
        $b = $this->agent('beta', function (AgentContext $ctx) use (&$order): AgentResult {
            $order[] = 'beta';
            return AgentResult::success('beta', 'ok', ['n' => 2]);
        });

        $orch = new OrchestratorAgent([$b, $a], new AgentPolicy());
        $ctx = new AgentContext(10, 'local', 'corr-order-1', false);
        $result = $orch->run($ctx);

        $this->assertSame(['beta', 'alpha'], $order);
        $this->assertSame('success', $result->status());
        $this->assertSame(['beta', 'alpha'], $result->data()['order']);
        $this->assertCount(2, $result->data()['results']);
        $this->assertSame('beta', $result->data()['results'][0]->agent());
        $this->assertSame('alpha', $result->data()['results'][1]->agent());
    }

    public function testIsolaExcecaoPorAgenteEContinua(): void
    {
        $a = $this->agent('bomba', static function (AgentContext $ctx): AgentResult {
            throw new RuntimeException('segredo-interno-orquestrador');
        });
        $b = $this->agent('sobrevive', static function (AgentContext $ctx): AgentResult {
            return AgentResult::success('sobrevive', 'ainda ok');
        });

        $orch = new OrchestratorAgent([$a, $b], new AgentPolicy());
        $result = $orch->run(new AgentContext(10, 'local', 'corr-iso-1', false));

        $this->assertSame('failed', $result->status());
        /** @var list<AgentResult> $results */
        $results = $result->data()['results'];
        $this->assertSame('failed', $results[0]->status());
        $this->assertSame('bomba', $results[0]->agent());
        $this->assertSame('agent_exception', $results[0]->reason());
        $this->assertStringNotContainsString('segredo-interno', $results[0]->reason());
        $this->assertSame('success', $results[1]->status());
        $this->assertSame('sobrevive', $results[1]->agent());
    }

    public function testIsolaExcecaoDeNameSemVazamentoEContinua(): void
    {
        $invalid = new class implements AgentInterface {
            public function name(): string
            {
                throw new RuntimeException('segredo-no-name');
            }

            public function run(AgentContext $context): AgentResult
            {
                throw new RuntimeException('run-nao-deve-ser-chamado');
            }
        };
        $next = $this->agent('seguinte', static fn (AgentContext $ctx): AgentResult =>
            AgentResult::success('seguinte', 'ok')
        );

        $result = (new OrchestratorAgent([$invalid, $next], new AgentPolicy()))->run(
            new AgentContext(10, 'local', 'corr-name-exception', false)
        );
        /** @var list<AgentResult> $results */
        $results = $result->data()['results'];

        $this->assertSame('failed', $result->status());
        $this->assertSame('unknown-agent-0', $results[0]->agent());
        $this->assertSame('agent_name_exception', $results[0]->reason());
        $this->assertStringNotContainsString('segredo', $results[0]->reason());
        $this->assertSame('success', $results[1]->status());
        $this->assertSame(['unknown-agent-0', 'seguinte'], $result->data()['order']);
    }

    public function testPreservaCorrelationIdNoAgregado(): void
    {
        $seen = [];
        $a = $this->agent('probe', function (AgentContext $ctx) use (&$seen): AgentResult {
            $seen[] = $ctx->correlationId();
            return AgentResult::skipped('probe', 'noop');
        });

        $orch = new OrchestratorAgent([$a], new AgentPolicy());
        $result = $orch->run(new AgentContext(7, 'staging', 'corr-xyz-99', false));

        $this->assertSame(['corr-xyz-99'], $seen);
        $this->assertSame('corr-xyz-99', $result->data()['correlationId']);
        $this->assertSame('orquestrador', $result->agent());
    }

    public function testNuncaEscreveNoMlMesmoComFlagFalse(): void
    {
        $policy = new AgentPolicy();
        $writeAttempts = 0;
        $a = $this->agent('tentador', function (AgentContext $ctx) use ($policy, &$writeAttempts): AgentResult {
            $writeAttempts++;
            if ($policy->allowsMlWrite($ctx, 'ml.price.patch')) {
                return AgentResult::success('tentador', 'escreveu', [], true, ['op:price']);
            }
            return AgentResult::blocked('tentador', 'ml_write_blocked');
        });

        $orch = new OrchestratorAgent([$a], $policy);
        $result = $orch->run(new AgentContext(10, 'local', 'corr-nowrite', false));

        $this->assertSame(1, $writeAttempts);
        $this->assertFalse($policy->allowsMlWrite(
            new AgentContext(10, 'local', 'corr-nowrite', false),
            'ml.price.patch'
        ));
        /** @var list<AgentResult> $results */
        $results = $result->data()['results'];
        $this->assertSame('blocked', $results[0]->status());
        $this->assertSame('blocked', $result->status());
        $this->assertSame([], $result->emittedOps());
    }

    public function testAgregaOpsSomenteQuandoStateChangedP0(): void
    {
        $a = $this->agent('muda', static function (AgentContext $ctx): AgentResult {
            return AgentResult::success('muda', 'mudou', [], true, ['ml.price.patch']);
        });
        $b = $this->agent('igual', static function (AgentContext $ctx): AgentResult {
            return AgentResult::success('igual', 'igual', [], false, ['ml.ads.update']);
        });

        $orch = new OrchestratorAgent([$a, $b], new AgentPolicy());
        $result = $orch->run(new AgentContext(10, 'staging', 'corr-ops', true));

        $this->assertTrue($result->stateChanged());
        $this->assertSame(['ml.price.patch'], $result->emittedOps());
    }

    public function testSuprimeOpsMlEmProductionMesmoComFlagTrue(): void
    {
        $agent = $this->agent('malicioso', static function (AgentContext $ctx): AgentResult {
            return AgentResult::success(
                'malicioso',
                'tentou',
                [],
                true,
                ['ml.item.publish', 'ml.price.patch']
            );
        });

        $result = (new OrchestratorAgent([$agent], new AgentPolicy()))->run(
            new AgentContext(10, 'production', 'corr-prod-ops', true)
        );

        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testFalhaFilhaNaoPropagaEstadoOuOpsNoAgregado(): void
    {
        $agent = $this->agent('falho', static function (AgentContext $ctx): AgentResult {
            return AgentResult::failed('falho', 'erro', [], true, ['ml.item.publish']);
        });

        $result = (new OrchestratorAgent([$agent], new AgentPolicy()))->run(
            new AgentContext(10, 'staging', 'corr-failed-ops', true)
        );

        $this->assertSame('failed', $result->status());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testListaVaziaRetornaSuccessSemResultados(): void
    {
        $orch = new OrchestratorAgent([], new AgentPolicy());
        $result = $orch->run(new AgentContext(1, 'local', 'corr-empty', false));

        $this->assertSame('success', $result->status());
        $this->assertSame('orquestrador', $result->agent());
        $this->assertSame([], $result->data()['results']);
        $this->assertSame([], $result->data()['order']);
        $this->assertSame('corr-empty', $result->data()['correlationId']);
        $this->assertFalse($result->stateChanged());
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
