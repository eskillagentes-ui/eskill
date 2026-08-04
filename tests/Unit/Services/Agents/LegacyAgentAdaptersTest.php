<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\CollectorAgent;
use App\Services\Agents\FinanceiroAgent;
use App\Services\Agents\SentinelaAgent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Agents\LegacyReadOnlyAgentAdapter
 * @covers \App\Services\Agents\SentinelaAgent
 * @covers \App\Services\Agents\CollectorAgent
 * @covers \App\Services\Agents\FinanceiroAgent
 */
final class LegacyAgentAdaptersTest extends TestCase
{
    use AgentSnapshotFixtures;

    /** @dataProvider validSnapshots */
    public function testTransformaSnapshotDaChaveEspecifica(string $class, string $key, array $snapshot, array $expected): void
    {
        $agent = new $class();
        $result = $agent->run($this->context([$key => $this->envelope($snapshot)]));

        $this->assertSame('success', $result->status());
        $this->assertSame($expected, $result->data());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @return iterable<string, array{class-string, string, array<string, mixed>, array<string, mixed>}> */
    public function validSnapshots(): iterable
    {
        $risk = [
            'risk_key' => 'oauth',
            'label' => 'oauth',
            'value_num' => 1.0,
            'value_text' => 'ok',
            'limit_num' => 2.0,
            'pct_of_limit' => 50.0,
            'status' => 'amarelo',
            'reason' => 'test',
            'source' => 'unit',
            'meta' => null,
            'collected_at' => '2026-08-03 12:00:00',
        ];
        $resumo = [
            'today' => \App\Services\Agents\AgentRuntimeFactory::emptyPnL(),
            'current_month' => \App\Services\Agents\AgentRuntimeFactory::emptyPnL(),
            'previous_month' => \App\Services\Agents\AgentRuntimeFactory::emptyPnL(),
            'variations' => [
                'gross_revenue' => 1.5,
                'net_profit' => 2.5,
                'total_orders' => 3.0,
                'avg_margin' => 4.0,
            ],
        ];
        $metrics = \App\Services\Agents\AgentRuntimeFactory::emptyMetrics();

        yield 'sentinela' => [SentinelaAgent::class, 'sentinela_snapshot', [
            'ok' => true, 'semaforo' => 'amarelo', 'risks' => [$risk], 'monitored' => 10,
        ], [
            'semaforo' => 'amarelo', 'risks' => [$risk], 'monitored' => 10,
        ]];
        yield 'collector' => [CollectorAgent::class, 'collector_snapshot', [
            'ok' => true, 'available' => true, 'cached' => false, 'stale' => false, 'api_calls' => 4,
        ], ['available' => true, 'cached' => false, 'stale' => false, 'api_calls' => 4]];
        yield 'financeiro' => [FinanceiroAgent::class, 'financeiro_snapshot', [
            'ok' => true, 'resumo' => $resumo, 'metrics' => $metrics,
        ], ['resumo' => $resumo, 'metrics' => $metrics]];
    }

    public function testCollectorAceitaSnapshotStaleDisponivel(): void
    {
        $result = (new CollectorAgent())->run($this->context(['collector_snapshot' => $this->envelope([
            'ok' => false, 'available' => true, 'cached' => true, 'stale' => true, 'api_calls' => 0,
        ])]));
        $this->assertSame('success', $result->status());
    }

    public function testAccountIdDeOutraContaFalhaFechado(): void
    {
        $payload = [
            'ok' => true, 'available' => true, 'cached' => true, 'stale' => false, 'api_calls' => 1,
        ];
        $result = (new CollectorAgent())->run($this->context([
            'collector_snapshot' => $this->envelope($payload, 99),
        ], 10));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_legacy_payload', $result->reason());
    }

    public function testCorrelationIdDeOutraExecucaoFalhaFechado(): void
    {
        $payload = [
            'ok' => true, 'semaforo' => 'verde', 'risks' => [], 'monitored' => 0,
        ];
        $result = (new SentinelaAgent())->run($this->context([
            'sentinela_snapshot' => $this->envelope($payload, 10, 'other-corr'),
        ], 10, 'corr-legacy-snapshot'));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_legacy_payload', $result->reason());
    }

    /** @dataProvider invalidSnapshots */
    public function testSnapshotInvalidoFalhaFechado(string $class, string $key, mixed $snapshot, string $reason): void
    {
        $result = (new $class())->run($this->context([$key => $snapshot]));
        $this->assertSame('failed', $result->status());
        $this->assertSame($reason, $result->reason());
        $this->assertSame([], $result->data());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @return iterable<string, array{class-string, string, mixed, string}> */
    public function invalidSnapshots(): iterable
    {
        $wrap = static function (array $payload): array {
            return SnapshotEnvelopeHelper::wrap($payload);
        };

        yield 'ausente por chave errada' => [SentinelaAgent::class, 'collector_snapshot', $wrap([
            'ok' => true, 'semaforo' => 'verde', 'risks' => [], 'monitored' => 1,
        ]), 'invalid_legacy_payload'];
        yield 'campo top-level extra' => [SentinelaAgent::class, 'sentinela_snapshot', $wrap([
            'ok' => true, 'semaforo' => 'verde', 'risks' => [], 'monitored' => 1, 'command' => 'publish',
        ]), 'invalid_legacy_payload'];
        yield 'meta extra' => [SentinelaAgent::class, 'sentinela_snapshot', $wrap([
            'ok' => true, 'semaforo' => 'verde', 'risks' => [], 'monitored' => 1, '_meta' => ['token' => 'x'],
        ]), 'invalid_legacy_payload'];
        yield 'muda estado' => [CollectorAgent::class, 'collector_snapshot', $wrap([
            'ok' => true, 'available' => true, 'cached' => false, 'stale' => false, 'api_calls' => 1,
            'state_changed' => true,
        ]), 'read_only_violation'];
        yield 'emite op' => [FinanceiroAgent::class, 'financeiro_snapshot', $wrap([
            'ok' => true,
            'resumo' => [
                'today' => \App\Services\Agents\AgentRuntimeFactory::emptyPnL(),
                'current_month' => \App\Services\Agents\AgentRuntimeFactory::emptyPnL(),
                'previous_month' => \App\Services\Agents\AgentRuntimeFactory::emptyPnL(),
                'variations' => [
                    'gross_revenue' => 0.0, 'net_profit' => 0.0, 'total_orders' => 0.0, 'avg_margin' => 0.0,
                ],
            ],
            'metrics' => \App\Services\Agents\AgentRuntimeFactory::emptyMetrics(),
            'emitted_ops' => ['write'],
        ]), 'read_only_violation'];
        yield 'http 503' => [CollectorAgent::class, 'collector_snapshot', $wrap([
            'ok' => true, 'available' => true, 'cached' => true, 'stale' => true, 'api_calls' => 1,
            'http_status' => 503,
        ]), 'legacy_http_503'];
        yield 'http 101' => [CollectorAgent::class, 'collector_snapshot', $wrap([
            'ok' => true, 'available' => true, 'cached' => true, 'stale' => true, 'api_calls' => 1,
            'http_status' => 101,
        ]), 'legacy_http_101'];
        yield 'http 302' => [CollectorAgent::class, 'collector_snapshot', $wrap([
            'ok' => true, 'available' => true, 'cached' => true, 'stale' => true, 'api_calls' => 1,
            'http_status' => 302,
        ]), 'legacy_http_302'];
        yield 'http 400' => [CollectorAgent::class, 'collector_snapshot', $wrap([
            'ok' => true, 'available' => true, 'cached' => true, 'stale' => true, 'api_calls' => 1,
            'http_status' => 400,
        ]), 'legacy_http_400'];
        yield 'http 500' => [CollectorAgent::class, 'collector_snapshot', $wrap([
            'ok' => true, 'available' => true, 'cached' => true, 'stale' => true, 'api_calls' => 1,
            'http_status' => 500,
        ]), 'legacy_http_500'];
        yield 'error timeout com ok true' => [CollectorAgent::class, 'collector_snapshot', $wrap([
            'ok' => true, 'available' => true, 'cached' => true, 'stale' => true, 'api_calls' => 1,
            'error' => 'timeout',
        ]), 'legacy_error'];
        yield 'status http malformado' => [CollectorAgent::class, 'collector_snapshot', $wrap([
            'ok' => true, 'available' => true, 'cached' => true, 'stale' => true, 'api_calls' => 1,
            'http_status' => 'success',
        ]), 'invalid_legacy_payload'];
        yield 'incompleto' => [FinanceiroAgent::class, 'financeiro_snapshot', $wrap([
            'ok' => true,
            'resumo' => [
                'today' => \App\Services\Agents\AgentRuntimeFactory::emptyPnL(),
                'current_month' => \App\Services\Agents\AgentRuntimeFactory::emptyPnL(),
                'previous_month' => \App\Services\Agents\AgentRuntimeFactory::emptyPnL(),
                'variations' => [
                    'gross_revenue' => 0.0, 'net_profit' => 0.0, 'total_orders' => 0.0, 'avg_margin' => 0.0,
                ],
            ],
            'metrics' => \App\Services\Agents\AgentRuntimeFactory::emptyMetrics(),
            'incomplete' => true,
        ]), 'incomplete_legacy_payload'];
        yield 'resumo arbitrario' => [FinanceiroAgent::class, 'financeiro_snapshot', $wrap([
            'ok' => true, 'resumo' => ['receita' => 1], 'metrics' => \App\Services\Agents\AgentRuntimeFactory::emptyMetrics(),
        ]), 'invalid_legacy_payload'];
        yield 'risk arbitrario' => [SentinelaAgent::class, 'sentinela_snapshot', $wrap([
            'ok' => true, 'semaforo' => 'verde',
            'risks' => [['risk_key' => 'oauth', 'status' => 'amarelo']], 'monitored' => 1,
        ]), 'invalid_legacy_payload'];
        yield 'envelope campo extra' => [CollectorAgent::class, 'collector_snapshot', [
            'account_id' => 10,
            'correlation_id' => 'corr-legacy-snapshot',
            'payload' => [
                'ok' => true, 'available' => true, 'cached' => true, 'stale' => false, 'api_calls' => 1,
            ],
            'extra' => true,
        ], 'invalid_legacy_payload'];
    }
}

/** Helper estático para data providers (sem $this). */
final class SnapshotEnvelopeHelper
{
    /** @param array<string, mixed> $payload */
    public static function wrap(array $payload): array
    {
        return \App\Services\Agents\SnapshotEnvelope::wrap(10, 'corr-legacy-snapshot', $payload);
    }
}
