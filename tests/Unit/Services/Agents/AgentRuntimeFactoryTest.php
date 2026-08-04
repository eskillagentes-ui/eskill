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

    public function testCustoDecimalStringComProvenanceExataEhAceito(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-cost-decimal');
        $item = $context->metadata()['optimizer_cost_snapshot']['payload']['items']['MLB1'];
        self::assertTrue($item['validated']);
        self::assertFalse($item['suspicious']);
        self::assertSame(10.0, $item['cost']);
    }

    /** @dataProvider invalidCostProvenance */
    public function testCustoComProvenanceOuDecimalInvalidoFalhaFechado(array $row): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->skuCost = $row;
        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-cost-invalid');
        $item = $context->metadata()['optimizer_cost_snapshot']['payload']['items']['MLB1'];
        self::assertFalse($item['validated']);
        self::assertTrue($item['suspicious']);
        self::assertSame(0.0, $item['cost']);
    }

    public function invalidCostProvenance(): iterable
    {
        yield 'outra conta' => [['account_id' => '11', 'mlb_id' => 'MLB1', 'custo_produto' => '10.00']];
        yield 'outro MLB' => [['account_id' => '10', 'mlb_id' => 'MLB2', 'custo_produto' => '10.00']];
        yield 'expoente' => [['account_id' => '10', 'mlb_id' => 'MLB1', 'custo_produto' => '1e2']];
        yield 'decimal nao canonico' => [['account_id' => '10', 'mlb_id' => 'MLB1', 'custo_produto' => '01.00']];
    }

    /** @dataProvider invalidCreatorProvenance */
    public function testFonteCriadorExigeProvenanceExataENaoDuplicada(array $row): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->itemResult = $row;
        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-source-provenance', [
            'creator_request' => ['source_mlb_id' => 'MLB404'],
        ]);
        $payload = $context->metadata()['creator_source_snapshot']['payload'];
        self::assertFalse($payload['valid']);
        self::assertTrue($payload['duplicate']);
    }

    public function invalidCreatorProvenance(): iterable
    {
        $base = [
            'account_id' => 10, 'mlb_id' => 'MLB404', 'seller_id' => '123456',
            'title' => 'Fonte real', 'duplicate' => false,
        ];
        yield 'outra conta' => [array_replace($base, ['account_id' => 11])];
        yield 'outro MLB' => [array_replace($base, ['mlb_id' => 'MLB405'])];
        yield 'seller invalido' => [array_replace($base, ['seller_id' => 'seller-123'])];
        yield 'duplicado' => [array_replace($base, ['duplicate' => true])];
    }

    public function testSentinelaRejeitaSubsetPctIncoerenteEMonitoredDivergente(): void
    {
        $cases = [];
        $gateway = new AgentRuntimeReadGatewayFake();
        $subset = $gateway->sentinela;
        array_pop($subset['risks']);
        $subset['monitored'] = 9;
        $cases[] = $subset;

        $pct = $gateway->sentinela;
        $pct['risks'][0]['pct_of_limit'] = 81.0;
        $cases[] = $pct;

        $monitored = $gateway->sentinela;
        $monitored['monitored'] = 9;
        $cases[] = $monitored;

        foreach ($cases as $index => $dashboard) {
            $probe = new AgentRuntimeReadGatewayFake();
            $probe->sentinela = $dashboard;
            $context = (new AgentRuntimeFactory($probe))->buildContext(10, 'corr-sentinela-' . $index);
            self::assertFalse($context->metadata()['sentinela_snapshot']['payload']['ok']);
        }
    }

    public function testSentinelaRejeitaStatusIndividualIncoerenteMesmoComSemaforoAgregadoCorreto(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->sentinela['risks'][0]['pct_of_limit'] = 81.0;
        $gateway->sentinela['risks'][0]['status'] = 'verde';
        $gateway->sentinela['semaforo'] = 'vermelho';

        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-risk-status-pct');

        self::assertFalse($context->metadata()['sentinela_snapshot']['payload']['ok']);
    }

    public function testSentinelaRejeitaCampoExtraAntesDeProjetarRisco(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->sentinela['risks'][0]['unexpected_capability'] = ['datetime', 'createFromFormat'];

        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-risk-extra');

        self::assertFalse($context->metadata()['sentinela_snapshot']['payload']['ok']);
    }

    public function testAdsRejeitaSkuVazioMalformadoSemIgnorarLinha(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->ads['skus'][] = [];
        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-ads-malformed');
        self::assertFalse($context->metadata()['collector_snapshot']['payload']['ok']);
        self::assertArrayNotHasKey('optimizer_observation_snapshot', $context->metadata());
    }

    public function testAdsRejeitaHealthForaDoRangeNormalizado(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->ads['skus'][0]['health'] = 99.0;

        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-ads-health');

        self::assertFalse($context->metadata()['collector_snapshot']['payload']['ok']);
        self::assertArrayNotHasKey('optimizer_observation_snapshot', $context->metadata());
    }

    public function testFinanceiroRejeitaMetricsDivergentesDoMesAtual(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->financialMetrics['gross_revenue'] = 0.01;
        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-fin-mismatch');
        self::assertFalse($context->metadata()['financeiro_snapshot']['payload']['ok']);
    }

    public function testFinanceiroRejeitaDataInvalidaMesmoQuandoStrtotimeAceita(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->financialSummary['today']['period']['start'] = '2026-02-30';
        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-fin-date');
        self::assertFalse($context->metadata()['financeiro_snapshot']['payload']['ok']);
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

    public function testFonteCriadorRejeitaTituloCallableAntesDoEnvelope(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->itemResult = [
            'account_id' => 10,
            'mlb_id' => 'MLB404',
            'seller_id' => '123456',
            'title' => 'strlen',
            'duplicate' => false,
        ];
        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-title-callable', [
            'creator_request' => ['source_mlb_id' => 'MLB404'],
        ]);

        self::assertFalse($context->metadata()['creator_source_snapshot']['payload']['valid']);
    }

    public function testSentinelaRejeitaMetaComCallableArraySemQuebrarBuildContext(): void
    {
        $gateway = new AgentRuntimeReadGatewayFake();
        $gateway->sentinela['risks'][0]['meta'] = [
            'capability' => ['datetime', 'createFromFormat'],
        ];
        $context = (new AgentRuntimeFactory($gateway))->buildContext(10, 'corr-meta-callable');

        self::assertFalse($context->metadata()['sentinela_snapshot']['payload']['ok']);
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
            array_map(static fn ($item): string => $item->agent(), $result->data()['results'])
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
