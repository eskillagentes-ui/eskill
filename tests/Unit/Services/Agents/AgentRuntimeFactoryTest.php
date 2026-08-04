<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentRuntimeFactory;
use App\Services\Agents\AgentRuntimeReadGatewayInterface;
use App\Services\Agents\CriadorAgent;
use App\Services\Agents\QaMergeGate;
use App\Services\Agents\SnapshotEnvelope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** @covers \App\Services\Agents\AgentRuntimeFactory */
final class AgentRuntimeFactoryTest extends TestCase
{
    private array $originalQaEnvironment = [];
    private const QA_VARIABLES = [
        'QA_GATE_PHP_LINT', 'QA_GATE_PHPUNIT_AGENTS',
        'QA_GATE_PHPUNIT_UNIT', 'QA_GATE_PLAYWRIGHT_READONLY',
    ];

    protected function setUp(): void
    {
        foreach (self::QA_VARIABLES as $variable) {
            $this->originalQaEnvironment[$variable] = getenv($variable);
            putenv($variable);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalQaEnvironment as $variable => $value) {
            putenv($value === false ? $variable : $variable . '=' . $value);
        }
    }

    public function testFactoryAceitaNoMaximoGatewayEstreito(): void
    {
        $constructor = (new ReflectionClass(AgentRuntimeFactory::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(1, $constructor->getNumberOfParameters());
        self::assertSame(
            AgentRuntimeReadGatewayInterface::class,
            $constructor->getParameters()[0]->getType()?->getName()
        );
    }

    public function testBuildContextGeraEnvelopesHermeticosComAccountECorrelation(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $context = (new AgentRuntimeFactory($gateway))->buildContext(1335, 'corr-factory-1', [
            'environment' => 'local',
            'creator_request' => ['source_mlb_id' => 'MLB999'],
        ]);

        self::assertSame(1335, $context->accountId());
        self::assertSame('corr-factory-1', $context->correlationId());
        foreach ([
            'sentinela_snapshot', 'collector_snapshot', 'financeiro_snapshot',
            'optimizer_observation_snapshot', 'optimizer_cost_snapshot',
            'creator_source_snapshot',
        ] as $key) {
            self::assertArrayHasKey($key, $context->metadata(), $key);
            $envelope = $context->metadata()[$key];
            self::assertSame(1335, $envelope['account_id'], $key);
            self::assertSame('corr-factory-1', $envelope['correlation_id'], $key);
            self::assertCount(3, $envelope, $key);
        }
        self::assertArrayNotHasKey('qa_results_snapshot', $context->metadata());
        self::assertFalse($context->mlWriteAutomation());
        self::assertContains(['item', 1335, 'MLB999'], $gateway->calls);
    }

    /** @dataProvider prohibitedOptions */
    public function testRejeitaOptionsForaDaAllowlistExata(string $key, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported runtime option');
        (new AgentRuntimeFactory(new AgentRuntimeReadGatewayFake()))
            ->buildContext(10, 'corr-options', [$key => $value]);
    }

    public function prohibitedOptions(): iterable
    {
        yield 'optimizer forged' => ['optimizer_recommendations', []];
        yield 'qa forged' => ['qa_results', []];
        yield 'creator item forged' => ['creator_source_item', ['id' => 'MLB1']];
        yield 'write automation' => ['ml_write_automation', true];
    }

    public function testQaSnapshotEhOmitidoSemAsQuatroEvidenciasExatas(): void
    {
        $this->setQaPassed();
        putenv('QA_GATE_PHPUNIT_UNIT=success');
        $context = (new AgentRuntimeFactory(new AgentRuntimeReadGatewayFake()))
            ->buildContext(10, 'corr-qa-closed');
        self::assertArrayNotHasKey('qa_results_snapshot', $context->metadata());
    }

    public function testQaSnapshotEhCriadoInternamenteAposGateZeroArg(): void
    {
        $this->setQaPassed();
        $context = (new AgentRuntimeFactory(new AgentRuntimeReadGatewayFake()))
            ->buildContext(10, 'corr-qa-passed');
        $payload = SnapshotEnvelope::extract(
            $context->metadata()['qa_results_snapshot'] ?? null,
            10,
            'corr-qa-passed',
            true
        );

        self::assertIsArray($payload);
        self::assertSame(QaMergeGate::REQUIRED_CHECK_IDS, array_keys($payload['results']));
        foreach ($payload['results'] as $id => $result) {
            self::assertSame($id, $result->agent());
            self::assertSame('success', $result->status());
            self::assertFalse($result->stateChanged());
            self::assertSame([], $result->emittedOps());
        }
    }

    /** @dataProvider malformedGateways */
    public function testRespostaVaziaOuMalformadaNuncaViraOkTrue(
        string $snapshot,
        string $property,
        array $value
    ): void {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->{$property} = $value;
        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-malformed');
        self::assertFalse($context->metadata()[$snapshot]['payload']['ok']);
    }

    public function malformedGateways(): iterable
    {
        yield 'sentinela vazio' => ['sentinela_snapshot', 'sentinela', []];
        yield 'sentinela monitored string' => ['sentinela_snapshot', 'sentinela', [
            'semaforo' => 'verde', 'risks' => [], 'monitored' => '1',
        ]];
        yield 'ads vazio' => ['collector_snapshot', 'ads', []];
        yield 'ads sem indicador real' => ['collector_snapshot', 'ads', [
            'read_only' => true, 'active_campaigns' => 0, 'campaigns' => [], 'skus' => [],
        ]];
        yield 'financial summary vazio' => ['financeiro_snapshot', 'financialSummary', []];
        yield 'financial summary incompleto' => ['financeiro_snapshot', 'financialSummary', [
            'today' => AgentRuntimeFactory::emptyPnL(),
            'current_month' => AgentRuntimeFactory::emptyPnL(),
            'variations' => [],
        ]];
        yield 'financial metrics vazio' => ['financeiro_snapshot', 'financialMetrics', []];
        yield 'financial metrics tipo invalido' => ['financeiro_snapshot', 'financialMetrics', [
            'total_orders' => '0', 'gross_revenue' => 0.0, 'net_profit' => 0.0,
            'avg_ticket' => 0.0, 'avg_margin' => 0.0, 'cost_rate' => 0.0, 'roi' => 0.0,
        ]];
    }

    public function testTodaLeituraRecebeContaDaExecucaoSemBindingReutilizavel(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $factory = new AgentRuntimeFactory($gateway);
        $factory->buildContext(11, 'corr-account-11', ['creator_request' => ['source_mlb_id' => 'MLB11']]);
        $factory->buildContext(22, 'corr-account-22', ['creator_request' => ['source_mlb_id' => 'MLB22']]);

        $byAccount = [11 => [], 22 => []];
        foreach ($gateway->calls as $call) {
            $byAccount[$call[1]][] = $call[0];
        }
        foreach ([11, 22] as $accountId) {
            self::assertSame([
                'ads', 'sentinela', 'financial-summary', 'financial-metrics',
                'sku-cost', 'item',
            ], $byAccount[$accountId]);
        }
    }

    public function testFonteCriadorIndisponivelPermaneceBloqueada(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->itemResult = ['error' => 'not_found'];
        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-source-failed', [
            'creator_request' => ['source_mlb_id' => 'MLB404'],
        ]);
        $result = (new CriadorAgent())->run($context);
        self::assertSame('blocked', $result->status());
        self::assertSame('creator_request_blocked', $result->reason());
        self::assertFalse($context->mlWriteAutomation());
    }

    public function testOrchestratorDaFactoryExecutaSemEstado(): void
    {
        $this->setQaPassed();
        $factory = new AgentRuntimeFactory(new AgentRuntimeReadGatewayFake());
        $context = $factory->buildContext(10, 'corr-orch', [
            'creator_request' => ['source_mlb_id' => 'MLB2'],
        ]);
        $result = $factory->createOrchestrator()->run($context);
        self::assertSame(
            ['sentinela', 'coletor', 'financeiro', 'otimizador', 'criador', 'qa'],
            $result->data()['order']
        );
        self::assertSame('success', $result->status());
        self::assertFalse($result->stateChanged());
        self::assertSame([], $result->emittedOps());
    }

    private function setQaPassed(): void
    {
        foreach (self::QA_VARIABLES as $variable) {
            putenv($variable . '=passed');
        }
    }
}
