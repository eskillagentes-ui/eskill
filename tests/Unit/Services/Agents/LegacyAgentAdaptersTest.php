<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentInterface;
use App\Services\Agents\CollectorAgent;
use App\Services\Agents\FinanceiroAgent;
use App\Services\Agents\SentinelaAgent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Aceite Bloco 2 — adapters mínimos sobre portas legadas injetadas.
 *
 * @covers \App\Services\Agents\SentinelaAgent
 * @covers \App\Services\Agents\CollectorAgent
 * @covers \App\Services\Agents\FinanceiroAgent
 */
final class LegacyAgentAdaptersTest extends TestCase
{
    public function testSentinelaImplementaContratoEPreservaPayload(): void
    {
        $receivedAccountId = null;
        $agent = new SentinelaAgent(
            static function (int $accountId) use (&$receivedAccountId): array {
                $receivedAccountId = $accountId;
                return [
                    'ok' => true,
                    'semaforo' => 'amarelo',
                    'risks' => [['risk_key' => 'oauth', 'status' => 'amarelo']],
                    'monitored' => 10,
                ];
            }
        );

        $result = $agent->run($this->context(1335, true));

        $this->assertInstanceOf(AgentInterface::class, $agent);
        $this->assertSame('sentinela', $agent->name());
        $this->assertSame(1335, $receivedAccountId);
        $this->assertSame('success', $result->status());
        $this->assertSame([
            'semaforo' => 'amarelo',
            'risks' => [['risk_key' => 'oauth', 'status' => 'amarelo']],
            'monitored' => 10,
        ], $result->data());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testSentinelaFalhaFechadaQuandoPayloadTentaEmitirOps(): void
    {
        foreach ([null, false, 1, 'true'] as $marker) {
            $payload = [
                'ok' => true,
                'semaforo' => 'verde',
                'risks' => [],
                'monitored' => 10,
                'emitted_ops' => ['op:nao-pode'],
            ];
            if ($marker !== null) {
                $payload['state_changed'] = $marker;
            }

            $result = (new SentinelaAgent(static fn (int $accountId): array => $payload))
                ->run($this->context());

            $this->assertSame('failed', $result->status());
            $this->assertSame('read_only_violation', $result->reason());
            $this->assertFalse($result->stateChanged());
            $this->assertSame([], $result->emittedOps());
        }
    }

    public function testSentinelaFalhaFechadaEmPayloadIncompleto(): void
    {
        $result = (new SentinelaAgent(static fn (int $accountId): array => [
            'ok' => true,
            'semaforo' => 'verde',
            'monitored' => 10,
        ]))->run($this->context());

        $this->assertSame('failed', $result->status());
        $this->assertSame([], $result->data());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testCollectorPreservaFreshnessEContaDeApi(): void
    {
        $receivedAccountId = null;
        $agent = new CollectorAgent(
            static function (int $accountId) use (&$receivedAccountId): array {
                $receivedAccountId = $accountId;
                return [
                    'ok' => true,
                    'available' => true,
                    'cached' => false,
                    'stale' => false,
                    'api_calls' => 4,
                ];
            }
        );

        $result = $agent->run($this->context(77, true));

        $this->assertInstanceOf(AgentInterface::class, $agent);
        $this->assertSame('coletor', $agent->name());
        $this->assertSame(77, $receivedAccountId);
        $this->assertSame('success', $result->status());
        $this->assertSame([
            'available' => true,
            'cached' => false,
            'stale' => false,
            'api_calls' => 4,
        ], $result->data());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testCollectorFalhaFechadaQuandoPayloadTentaMudarEstado(): void
    {
        $result = (new CollectorAgent(static fn (int $accountId): array => [
            'ok' => true,
            'available' => true,
            'cached' => false,
            'stale' => false,
            'api_calls' => 4,
            'state_changed' => true,
            'emitted_ops' => [],
        ]))->run($this->context());

        $this->assertSame('failed', $result->status());
        $this->assertSame('read_only_violation', $result->reason());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testCollectorAceitaSnapshotStaleDisponivelMesmoComOkFalse(): void
    {
        $result = (new CollectorAgent(static fn (int $accountId): array => [
            'ok' => false,
            'available' => true,
            'cached' => true,
            'stale' => true,
            'api_calls' => 4,
        ]))->run($this->context());

        $this->assertSame('success', $result->status());
        $this->assertSame([
            'available' => true,
            'cached' => true,
            'stale' => true,
            'api_calls' => 4,
        ], $result->data());
    }

    public function testCollectorRejeitaOkFalseSemSnapshotCacheadoEStale(): void
    {
        $result = (new CollectorAgent(static fn (int $accountId): array => [
            'ok' => false,
            'available' => true,
            'cached' => false,
            'stale' => false,
            'api_calls' => 4,
        ]))->run($this->context());

        $this->assertSame('failed', $result->status());
        $this->assertSame('collector_unavailable', $result->reason());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testCollectorFalhaQuandoOkFalseEAvailableFalse(): void
    {
        $result = (new CollectorAgent(static fn (int $accountId): array => [
            'ok' => false,
            'available' => false,
            'cached' => false,
            'stale' => true,
            'api_calls' => 4,
        ]))->run($this->context());

        $this->assertSame('failed', $result->status());
        $this->assertSame([
            'available' => false,
            'cached' => false,
            'stale' => true,
            'api_calls' => 4,
        ], $result->data());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testCollectorFalhaFechadaEmPayloadIncompleto(): void
    {
        $result = (new CollectorAgent(static fn (int $accountId): array => [
            'ok' => true,
            'available' => true,
            'cached' => false,
            'stale' => false,
        ]))->run($this->context());

        $this->assertSame('failed', $result->status());
        $this->assertSame([], $result->data());
    }

    public function testFinanceiroPreservaResumoEMetricsSemEmitirPorLeitura(): void
    {
        $receivedAccountId = null;
        $agent = new FinanceiroAgent(
            static function (int $accountId) use (&$receivedAccountId): array {
                $receivedAccountId = $accountId;
                return [
                    'ok' => true,
                    'resumo' => ['periodo' => '30d', 'receita' => 1200.50],
                    'metrics' => ['tacos' => 8.2, 'margem' => 19.4],
                ];
            }
        );

        $result = $agent->run($this->context(91, true));

        $this->assertInstanceOf(AgentInterface::class, $agent);
        $this->assertSame('financeiro', $agent->name());
        $this->assertSame(91, $receivedAccountId);
        $this->assertSame('success', $result->status());
        $this->assertSame([
            'resumo' => ['periodo' => '30d', 'receita' => 1200.50],
            'metrics' => ['tacos' => 8.2, 'margem' => 19.4],
        ], $result->data());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testFinanceiroFalhaFechadaQuandoPayloadTentaMudarEstado(): void
    {
        $result = (new FinanceiroAgent(static fn (int $accountId): array => [
            'ok' => true,
            'resumo' => ['receita' => 100.0],
            'metrics' => ['margem' => 10.0],
            'state_changed' => true,
            'emitted_ops' => ['op:financeiro-state-change'],
        ]))->run($this->context());

        $this->assertSame('failed', $result->status());
        $this->assertSame('read_only_violation', $result->reason());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    public function testFinanceiroFalhaFechadaEmPayloadIncompleto(): void
    {
        $result = (new FinanceiroAgent(static fn (int $accountId): array => [
            'ok' => true,
            'resumo' => [],
        ]))->run($this->context());

        $this->assertSame('failed', $result->status());
        $this->assertSame([], $result->data());
    }

    public function testPayloadMalformadoNaoEscapaComoTypeError(): void
    {
        $result = (new SentinelaAgent(static fn (int $accountId): array => [
            'ok' => true,
            'semaforo' => 'verde',
            'risks' => [],
            'monitored' => 10,
            '_meta' => 'malformed',
        ]))->run($this->context());

        $this->assertSame('failed', $result->status());
        $this->assertSame([], $result->data());
    }

    /**
     * @dataProvider agentWithHttpFailureProvider
     * @param callable(callable(int): array<string, mixed>): AgentInterface $factory
     */
    public function testFalhaFechadaEm4xxOu5xx(callable $factory, string $statusKey, int $status): void
    {
        $agent = $factory(static fn (int $accountId): array => [
            'ok' => true,
            'semaforo' => 'verde',
            'risks' => [],
            'monitored' => 10,
            'available' => true,
            'cached' => true,
            'stale' => true,
            'api_calls' => 1,
            'resumo' => [],
            'metrics' => [],
            $statusKey => $status,
            'state_changed' => true,
            'emitted_ops' => ['op:nao-pode'],
        ]);

        $result = $agent->run($this->context());

        $this->assertSame('failed', $result->status());
        $this->assertSame('legacy_http_' . $status, $result->reason());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /**
     * @return iterable<string, array{0: callable(callable(int): array<string, mixed>): AgentInterface, 1: string, 2: int}>
     */
    public function agentWithHttpFailureProvider(): iterable
    {
        yield 'sentinela 429' => [
            static fn (callable $port): AgentInterface => new SentinelaAgent($port),
            'status',
            429,
        ];
        yield 'sentinela 401' => [
            static fn (callable $port): AgentInterface => new SentinelaAgent($port),
            'status',
            401,
        ];
        yield 'collector 503' => [
            static fn (callable $port): AgentInterface => new CollectorAgent($port),
            'api_status',
            503,
        ];
        yield 'financeiro 500' => [
            static fn (callable $port): AgentInterface => new FinanceiroAgent($port),
            'http_status',
            500,
        ];
    }

    /**
     * @dataProvider agentFactoryProvider
     * @param callable(callable(int): array<string, mixed>): AgentInterface $factory
     */
    public function testConverteExcecaoDaPortaEmFalhaFechada(callable $factory): void
    {
        $agent = $factory(static function (int $accountId): array {
            throw new RuntimeException('legacy unavailable');
        });

        $result = $agent->run($this->context());

        $this->assertSame('failed', $result->status());
        $this->assertSame('legacy_port_exception', $result->reason());
        $this->assertStringNotContainsString('legacy unavailable', $result->reason());
        $this->assertSame([], $result->data());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /**
     * @return iterable<string, array{0: callable(callable(int): array<string, mixed>): AgentInterface}>
     */
    public function agentFactoryProvider(): iterable
    {
        yield 'sentinela' => [static fn (callable $port): AgentInterface => new SentinelaAgent($port)];
        yield 'collector' => [static fn (callable $port): AgentInterface => new CollectorAgent($port)];
        yield 'financeiro' => [static fn (callable $port): AgentInterface => new FinanceiroAgent($port)];
    }

    private function context(int $accountId = 10, bool $mlWriteAutomation = false): AgentContext
    {
        return new AgentContext($accountId, 'staging', 'corr-bloco-2', $mlWriteAutomation);
    }
}
