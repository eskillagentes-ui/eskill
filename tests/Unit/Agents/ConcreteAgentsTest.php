<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\CollectorAgent;
use App\Services\Agents\CriadorAgent;
use App\Services\Agents\FinanceiroAgent;
use App\Services\Agents\OtimizadorAgent;
use App\Services\Agents\SentinelaAgent;
use App\Services\Agents\SnapshotEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * Cobertura dos 5 agents concretos (Collector/Criador/Financeiro/Otimizador/
 * Sentinela). Foco em invariantes do contrato "snapshot only", nao em
 * schemas completos de payload (que dependem de PnlReportService e sao
 * melhores testados via integracao).
 *
 * Cada agent implementa o padrao "snapshot only":
 *  - construtor sem args (rejeita dependencias)
 *  - NAME constante em pt-br
 *  - run() le metadata via context->metadata()
 *  - retorna AgentResult consistente (success/blocked/failed)
 */
final class ConcreteAgentsTest extends TestCase
{
    private const ACCOUNT_ID = 1335;
    private const CORRELATION = 'corr-concrete-agents-test';

    private function ctx(array $metadata = []): AgentContext
    {
        return new AgentContext(
            self::ACCOUNT_ID,
            'local',
            self::CORRELATION,
            false,
            $metadata
        );
    }

    /** @var list<string> */
    private const SENTINELA_RISK_KEYS = [
        'reputacao', 'reclamacoes', 'atrasos', 'cancelamentos', 'moderacao',
        'catalogo', 'chargeback', 'oauth', 'rate_limit', 'nf_pendente', 'queda_vendas',
    ];

    /** Gera 11 risks validos (10 coletados + 1 nd) com limits corretos */
    private function validSentinelaRisks(): array
    {
        $risks = [];
        foreach (self::SENTINELA_RISK_KEYS as $key) {
            $limit = match ($key) {
                'reclamacoes' => 2.0,
                'atrasos' => 15.0,
                'cancelamentos' => 2.5,
                default => null,
            };
            $risks[] = [
                'risk_key' => $key,
                'label' => $key,
                'value_num' => 0,
                'value_text' => null,
                'limit_num' => $limit,
                'pct_of_limit' => 0.0,
                'status' => 'verde',
                'reason' => null,
                'source' => 'sentinela',
                'meta' => null,
                'collected_at' => '2026-08-04T00:00:00Z',
            ];
        }
        // nf_pendente precisa ser nd: status=nd + collected_at=null + value=null
        foreach ($risks as $i => $r) {
            if ($r['risk_key'] === 'nf_pendente') {
                $risks[$i]['status'] = 'nd';
                $risks[$i]['collected_at'] = null;
                $risks[$i]['value_num'] = null;
                $risks[$i]['pct_of_limit'] = null;
            }
        }
        return $risks;
    }

    // ===== CollectorAgent =====

    public function testCollectorConstructorRejectsArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectorAgent(fn () => []);
    }

    public function testCollectorFailsWhenSnapshotMissing(): void
    {
        $agent = new CollectorAgent();
        $result = $agent->run($this->ctx([]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('coletor', $result->agent());
    }

    public function testCollectorHandlesMissingFieldsAsInvalid(): void
    {
        // Snapshot presente mas com chave invalida: falha de validacao
        // (porque payloadKeys exige available/cached/stale/api_calls).
        $envelope = SnapshotEnvelope::wrap(
            self::ACCOUNT_ID,
            self::CORRELATION,
            ['ok' => true, 'extra_field' => 'oops']
        );
        $agent = new CollectorAgent();
        $result = $agent->run($this->ctx(['collector_snapshot' => $envelope]));
        $this->assertSame('failed', $result->status());
    }

    // ===== CriadorAgent =====

    public function testCriadorConstructorRejectsArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CriadorAgent('MLB123', 'foo');
    }

    public function testCriadorBlockedWhenRequestMissing(): void
    {
        $agent = new CriadorAgent();
        $result = $agent->run($this->ctx([]));
        $this->assertSame('blocked', $result->status());
        $this->assertSame('creator_request_blocked', $result->reason());
        $this->assertSame('criador', $result->agent());
    }

    public function testCriadorBlockedWhenMlbIdInvalidFormat(): void
    {
        $agent = new CriadorAgent();
        $result = $agent->run($this->ctx([
            'creator_request' => ['source_mlb_id' => 'invalid-format'],
        ]));
        $this->assertSame('blocked', $result->status());
    }

    public function testCriadorFailedWhenSourceSnapshotMissing(): void
    {
        $agent = new CriadorAgent();
        $result = $agent->run($this->ctx([
            'creator_request' => ['source_mlb_id' => 'MLB123456'],
            // sem creator_source_snapshot
        ]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_creator_source_snapshot', $result->reason());
    }

    // ===== FinanceiroAgent =====

    public function testFinanceiroConstructorRejectsArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FinanceiroAgent(fn () => []);
    }

    public function testFinanceiroFailsWhenSnapshotMissing(): void
    {
        $agent = new FinanceiroAgent();
        $result = $agent->run($this->ctx([]));
        $this->assertSame('failed', $result->status());
    }

    public function testFinanceiroFailsWhenPayloadMissingKeys(): void
    {
        $envelope = SnapshotEnvelope::wrap(
            self::ACCOUNT_ID,
            self::CORRELATION,
            ['ok' => true, 'resumo' => ['wrong_key' => 1], 'metrics' => []]
        );
        $agent = new FinanceiroAgent();
        $result = $agent->run($this->ctx(['financeiro_snapshot' => $envelope]));
        $this->assertSame('failed', $result->status());
    }

    // ===== OtimizadorAgent =====

    public function testOtimizadorConstructorRejectsArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OtimizadorAgent(fn () => []);
    }

    public function testOtimizadorFailsWhenObservationMissing(): void
    {
        $agent = new OtimizadorAgent();
        $result = $agent->run($this->ctx([]));
        $this->assertSame('failed', $result->status());
    }

    public function testOtimizadorFailsWhenCostSnapshotMissingButObsPresent(): void
    {
        $observation = SnapshotEnvelope::wrap(
            self::ACCOUNT_ID,
            self::CORRELATION,
            ['recommendations' => [['mlb_id' => 'MLB1', 'kind' => 'x', 'recommended_roas' => 1.0]]]
        );
        $agent = new OtimizadorAgent();
        $result = $agent->run($this->ctx([
            'optimizer_observation_snapshot' => $observation,
        ]));
        $this->assertSame('failed', $result->status());
    }

    public function testOtimizadorFailsWhenCostContainsUnknownMlbId(): void
    {
        $observation = SnapshotEnvelope::wrap(
            self::ACCOUNT_ID,
            self::CORRELATION,
            ['recommendations' => [['mlb_id' => 'MLB1', 'kind' => 'x', 'recommended_roas' => 1.0]]]
        );
        $cost = SnapshotEnvelope::wrap(
            self::ACCOUNT_ID,
            self::CORRELATION,
            ['items' => ['MLB999' => ['validated' => true, 'suspicious' => false, 'cost' => 50.0]]]
        );
        $agent = new OtimizadorAgent();
        $result = $agent->run($this->ctx([
            'optimizer_observation_snapshot' => $observation,
            'optimizer_cost_snapshot' => $cost,
        ]));
        $this->assertSame('failed', $result->status());
    }

    // ===== SentinelaAgent =====

    public function testSentinelaConstructorRejectsArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SentinelaAgent(fn () => []);
    }

    public function testSentinelaFailsWhenSnapshotMissing(): void
    {
        $agent = new SentinelaAgent();
        $result = $agent->run($this->ctx([]));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_legacy_payload', $result->reason());
    }

    public function testSentinelaFailsWhenMonitoredOutOfRange(): void
    {
        $envelope = SnapshotEnvelope::wrap(
            self::ACCOUNT_ID,
            self::CORRELATION,
            [
                'ok' => true,
                'semaforo' => 'verde',
                'risks' => $this->validSentinelaRisks(),
                'monitored' => 50,  // > 10
            ]
        );
        $agent = new SentinelaAgent();
        $result = $agent->run($this->ctx(['sentinela_snapshot' => $envelope]));
        $this->assertSame('failed', $result->status());
    }

    public function testSentinelaFailsWhenSemaforoDoesNotMatchAggregatedStatus(): void
    {
        $envelope = SnapshotEnvelope::wrap(
            self::ACCOUNT_ID,
            self::CORRELATION,
            [
                'ok' => true,
                'semaforo' => 'vermelho',  // incoerente com todos verde
                'risks' => $this->validSentinelaRisks(),
                'monitored' => 10,
            ]
        );
        $agent = new SentinelaAgent();
        $result = $agent->run($this->ctx(['sentinela_snapshot' => $envelope]));
        $this->assertSame('failed', $result->status());
    }

    public function testSentinelaSucceedsWithCompleteSnapshot(): void
    {
        $envelope = SnapshotEnvelope::wrap(
            self::ACCOUNT_ID,
            self::CORRELATION,
            [
                'ok' => true,
                'semaforo' => 'verde',
                'risks' => $this->validSentinelaRisks(),
                'monitored' => 10,
            ]
        );
        $agent = new SentinelaAgent();
        $result = $agent->run($this->ctx(['sentinela_snapshot' => $envelope]));
        $this->assertSame('success', $result->status());
        $this->assertSame('legacy_read_complete', $result->reason());
        $this->assertSame('sentinela', $result->agent());
    }
}
