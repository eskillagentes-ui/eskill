<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
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
    /** @dataProvider validSnapshots */
    public function testTransformaSnapshotDaChaveEspecifica(string $class, string $key, array $snapshot, array $expected): void
    {
        $agent = new $class();
        $result = $agent->run($this->context([$key => $snapshot]));

        $this->assertSame('success', $result->status());
        $this->assertSame($expected, $result->data());
        $this->assertFalse($result->stateChanged());
        $this->assertSame([], $result->emittedOps());
    }

    /** @return iterable<string, array{class-string, string, array<string, mixed>, array<string, mixed>}> */
    public function validSnapshots(): iterable
    {
        yield 'sentinela' => [SentinelaAgent::class, 'sentinela_snapshot', [
            'ok' => true, 'semaforo' => 'amarelo',
            'risks' => [['risk_key' => 'oauth', 'status' => 'amarelo']], 'monitored' => 10,
        ], [
            'semaforo' => 'amarelo',
            'risks' => [['risk_key' => 'oauth', 'status' => 'amarelo']], 'monitored' => 10,
        ]];
        yield 'collector' => [CollectorAgent::class, 'collector_snapshot', [
            'ok' => true, 'available' => true, 'cached' => false, 'stale' => false, 'api_calls' => 4,
        ], ['available' => true, 'cached' => false, 'stale' => false, 'api_calls' => 4]];
        yield 'financeiro' => [FinanceiroAgent::class, 'financeiro_snapshot', [
            'ok' => true, 'resumo' => ['receita' => 1200.5], 'metrics' => ['margem' => 19.4],
        ], ['resumo' => ['receita' => 1200.5], 'metrics' => ['margem' => 19.4]]];
    }

    public function testCollectorAceitaSnapshotStaleDisponivel(): void
    {
        $result = (new CollectorAgent())->run($this->context(['collector_snapshot' => [
            'ok' => false, 'available' => true, 'cached' => true, 'stale' => true, 'api_calls' => 0,
        ]]));
        $this->assertSame('success', $result->status());
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
        yield 'ausente por chave errada' => [SentinelaAgent::class, 'collector_snapshot', [
            'ok' => true, 'semaforo' => 'verde', 'risks' => [], 'monitored' => 1,
        ], 'invalid_legacy_payload'];
        yield 'campo top-level extra' => [SentinelaAgent::class, 'sentinela_snapshot', [
            'ok' => true, 'semaforo' => 'verde', 'risks' => [], 'monitored' => 1, 'command' => 'publish',
        ], 'invalid_legacy_payload'];
        yield 'meta extra' => [SentinelaAgent::class, 'sentinela_snapshot', [
            'ok' => true, 'semaforo' => 'verde', 'risks' => [], 'monitored' => 1, '_meta' => ['token' => 'x'],
        ], 'invalid_legacy_payload'];
        yield 'muda estado' => [CollectorAgent::class, 'collector_snapshot', [
            'ok' => true, 'available' => true, 'cached' => false, 'stale' => false, 'api_calls' => 1,
            'state_changed' => true,
        ], 'read_only_violation'];
        yield 'emite op' => [FinanceiroAgent::class, 'financeiro_snapshot', [
            'ok' => true, 'resumo' => [], 'metrics' => [], 'emitted_ops' => ['write'],
        ], 'read_only_violation'];
        yield 'http 503' => [CollectorAgent::class, 'collector_snapshot', [
            'ok' => true, 'available' => true, 'cached' => true, 'stale' => true, 'api_calls' => 1,
            'http_status' => 503,
        ], 'legacy_http_503'];
        yield 'status http malformado' => [CollectorAgent::class, 'collector_snapshot', [
            'ok' => true, 'available' => true, 'cached' => true, 'stale' => true, 'api_calls' => 1,
            'http_status' => 'success',
        ], 'invalid_legacy_payload'];
        yield 'incompleto' => [FinanceiroAgent::class, 'financeiro_snapshot', [
            'ok' => true, 'resumo' => [], 'metrics' => [], 'incomplete' => true,
        ], 'incomplete_legacy_payload'];
    }

    private function context(array $metadata): AgentContext
    {
        return new AgentContext(10, 'staging', 'corr-legacy-snapshot', false, $metadata);
    }
}
