<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentResult;
use App\Services\Agents\PregaoAgentRuntimeReporter;
use App\Services\Pregao\PregaoEmitService;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Redis;

/** @covers \App\Services\Agents\PregaoAgentRuntimeReporter */
final class PregaoAgentRuntimeReporterTest extends TestCase
{
    private const CORRELATION = 'agent24x7-20260804T120000Z-0123abcd:1335';

    public function testPublicaSomenteTelemetriaSanitizadaDosAgentesEDoOrquestrador(): void
    {
        $published = [];
        $result = AgentResult::blocked('orquestrador', 'agent_blocked', [
            'correlationId' => self::CORRELATION,
            'results' => [
                AgentResult::success('sentinela', 'legacy_read_complete', ['private' => 'redacted']),
                AgentResult::success('coletor', 'legacy_read_complete'),
                AgentResult::failed('financeiro', 'financeiro_unavailable'),
                AgentResult::blocked('otimizador', 'cost_validation_blocked'),
            ],
            'mlWriteAutomation' => false,
        ]);

        $this->reporter($published)->report(1335, self::CORRELATION, $result, 2);

        self::assertCount(5, $published);
        self::assertSame(['sentinela', 'collector', 'financeiro', 'otimizador', 'orquestrador'], array_column(
            array_column($published, 'payload'),
            'agent'
        ));
        foreach ($published as $event) {
            self::assertSame('agent.status', $event['type']);
            self::assertSame(1335, $event['account_id']);
            self::assertSame(self::CORRELATION, $event['payload']['correlation_id']);
            self::assertSame(2, $event['payload']['attempts']);
            self::assertFalse($event['payload']['state_changed']);
            self::assertFalse($event['payload']['ml_write_automation']);
            self::assertArrayNotHasKey('data', $event['payload']);
            self::assertStringNotContainsString('redacted', json_encode($event, JSON_THROW_ON_ERROR));
        }
    }

    public function testRejeitaMudancaDeEstadoOuOperacaoAntesDePublicar(): void
    {
        $published = [];
        $child = AgentResult::success(
            'sentinela',
            'legacy_read_complete',
            [],
            true,
            ['ml.item.publish']
        );
        $result = AgentResult::success('orquestrador', 'aggregated', [
            'correlationId' => self::CORRELATION,
            'results' => [
                $child,
                AgentResult::success('coletor', 'legacy_read_complete'),
                AgentResult::success('financeiro', 'legacy_read_complete'),
                AgentResult::success('otimizador', 'recommendations_ready'),
            ],
            'mlWriteAutomation' => false,
        ]);

        try {
            $this->reporter($published)->report(1335, self::CORRELATION, $result, 1);
            self::fail('resultado mutante deveria ser rejeitado');
        } catch (InvalidArgumentException) {
            self::assertSame([], $published);
        }
    }

    public function testRejeitaCorrelacaoQueNaoPertenceAContaOuAoResultado(): void
    {
        $published = [];
        $result = AgentResult::success('orquestrador', 'aggregated', [
            'correlationId' => 'agent24x7-20260804T120000Z-0123abcd:9999',
            'results' => [],
            'mlWriteAutomation' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->reporter($published)->report(1335, self::CORRELATION, $result, 1);
    }

    public function testRejeitaReasonArbitrarioSemPublicar(): void
    {
        $published = [];
        $result = AgentResult::failed('orquestrador', 'token_secret_value', [
            'correlationId' => self::CORRELATION,
            'results' => [],
            'mlWriteAutomation' => false,
        ]);

        try {
            $this->reporter($published)->report(1335, self::CORRELATION, $result, 1);
            self::fail('reason arbitrário deveria ser rejeitado');
        } catch (InvalidArgumentException) {
            self::assertSame([], $published);
        }
    }

    public function testRejeitaStatusSuccessComReasonDeFalhaAntesDePublicar(): void
    {
        $published = [];
        $result = AgentResult::success('orquestrador', 'aggregated', [
            'correlationId' => self::CORRELATION,
            'results' => [
                AgentResult::success('sentinela', 'read_only_violation'),
                AgentResult::success('coletor', 'legacy_read_complete'),
                AgentResult::success('financeiro', 'legacy_read_complete'),
                AgentResult::success('otimizador', 'recommendations_ready'),
            ],
            'mlWriteAutomation' => false,
        ]);

        try {
            $this->reporter($published)->report(1335, self::CORRELATION, $result, 1);
            self::fail('status/reason incoerentes deveriam ser rejeitados');
        } catch (InvalidArgumentException) {
            self::assertSame([], $published);
        }
    }

    public function testMapeiaExcecaoTotalDoRuntimeParaFalhaDoOrquestrador(): void
    {
        $published = [];
        $result = AgentResult::failed('agent-runtime', 'runtime_exception');

        $this->reporter($published)->report(1335, self::CORRELATION, $result, 2);

        self::assertCount(1, $published);
        self::assertSame('orquestrador', $published[0]['payload']['agent']);
        self::assertSame('failed', $published[0]['payload']['status']);
        self::assertSame('runtime_exception', $published[0]['payload']['reason']);
    }

    public function testAceitaReasonHttpNao2xxProduzidoPeloAdapter(): void
    {
        $published = [];
        $reporter = $this->reporter($published);
        $correlation = 'agent24x7-20260804T120000Z-0123abcd:1335';
        $result = AgentResult::failed('orquestrador', 'agent_failed', [
            'correlationId' => $correlation,
            'results' => [
                AgentResult::success('sentinela', 'legacy_read_complete'),
                AgentResult::failed('coletor', 'legacy_http_302'),
                AgentResult::success('financeiro', 'legacy_read_complete'),
                AgentResult::success('otimizador', 'recommendations_ready'),
            ],
            'mlWriteAutomation' => false,
        ]);

        $reporter->report(1335, $correlation, $result, 1);

        self::assertSame('legacy_http_302', $published[1]['payload']['reason']);
        self::assertSame('collector', $published[1]['payload']['agent']);
    }

    public function testRejeitaRosterParcialAntesDePublicar(): void
    {
        $published = [];
        $result = AgentResult::success('orquestrador', 'aggregated', [
            'correlationId' => self::CORRELATION,
            'results' => [AgentResult::success('sentinela', 'legacy_read_complete')],
            'mlWriteAutomation' => false,
        ]);

        try {
            $this->reporter($published)->report(1335, self::CORRELATION, $result, 1);
            self::fail('roster parcial deveria ser rejeitado');
        } catch (InvalidArgumentException) {
            self::assertSame([], $published);
        }
    }

    /** @param list<array<string, mixed>> $published */
    private function reporter(array &$published): PregaoAgentRuntimeReporter
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $pdo->method('prepare')->willReturn($statement);

        $redis = $this->createMock(Redis::class);
        $redis->method('publish')->willReturnCallback(
            static function (string $channel, string $json) use (&$published): int {
                self::assertSame(PregaoEmitService::CHANNEL, $channel);
                $published[] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                return 1;
            }
        );

        return new PregaoAgentRuntimeReporter(new PregaoEmitService($pdo, $redis));
    }
}
