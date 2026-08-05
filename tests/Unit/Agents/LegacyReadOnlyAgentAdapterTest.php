<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\LegacyReadOnlyAgentAdapter;
use App\Services\Agents\SnapshotEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * Helper subclasse concreta com setter para snapshotKey/keys (sem quebrar o
 * contrato final do construtor de LegacyReadOnlyAgentAdapter).
 */
final class TestAdapter extends LegacyReadOnlyAgentAdapter
{
    private string $k = 'snap';
    /** @var list<string> */
    private array $k2 = [];
    private bool $requireShape = false;

    public function setSnapshotKey(string $k): void
    {
        $this->k = $k;
    }

    /** @param list<string> $keys */
    public function setPayloadKeys(array $keys): void
    {
        $this->k2 = $keys;
    }

    public function setRequireShape(bool $v): void
    {
        $this->requireShape = $v;
    }

    public function name(): string
    {
        return 'test-adapter';
    }

    protected function snapshotKey(): string
    {
        return $this->k;
    }

    protected function payloadKeys(): array
    {
        return $this->k2;
    }

    protected function mapPayload(array $payload): AgentResult
    {
        if ($this->requireShape) {
            foreach ($this->k2 as $expectedKey) {
                if (!array_key_exists($expectedKey, $payload)) {
                    return AgentResult::failed($this->name(), 'shape_mismatch');
                }
            }
        }
        return AgentResult::success($this->name(), 'legacy_read_complete', $payload);
    }
}

/**
 * Cobertura do adapter read-only: contrato de envelope + http_status.
 *
 * Invariantes sob teste:
 *  - construtor rejeita argumentos (final + func_num_args() check)
 *  - falha quando snapshot ausente
 *  - falha quando account_id/correlation nao casam
 *  - falha quando envelope tem chaves extras
 *  - sucesso em payload valido
 *  - falha com http_status 4xx/5xx
 *  - falha com status fora de faixa 100-599
 */
final class LegacyReadOnlyAgentAdapterTest extends TestCase
{
    private const ACCOUNT_ID = 1335;
    private const CORRELATION = 'corr-adapter-test';

    private function contextWith(array $metadata): AgentContext
    {
        return new AgentContext(
            self::ACCOUNT_ID,
            'local',
            self::CORRELATION,
            false,
            $metadata
        );
    }

    private function adapter(?string $key = null): TestAdapter
    {
        $a = new TestAdapter();
        if ($key !== null) {
            $a->setSnapshotKey($key);
        }
        return $a;
    }

    public function testConstructorIsFinal(): void
    {
        // Construtor marcado final no design - subclasse nao pode
        // sobrecarregar/injetar dependencias. Guardamos isso no design via
        // ReflectionMethod.
        $reflection = new \ReflectionMethod(LegacyReadOnlyAgentAdapter::class, '__construct');
        $this->assertTrue($reflection->isFinal(), 'LegacyReadOnlyAgentAdapter::__construct must be final');
        $this->assertTrue($reflection->isPublic(), 'LegacyReadOnlyAgentAdapter::__construct must be public');
    }

    public function testFailsWhenSnapshotMissing(): void
    {
        $adapter = $this->adapter('missing_snapshot');
        $result = $adapter->run(new AgentContext(
            self::ACCOUNT_ID,
            'local',
            self::CORRELATION,
            false
        ));
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_legacy_payload', $result->reason());
    }

    public function testFailsWhenAccountIdMismatch(): void
    {
        $envelope = SnapshotEnvelope::wrap(9999, self::CORRELATION, ['x' => 1]);
        $adapter = $this->adapter('present_snapshot');
        $ctx = new AgentContext(
            self::ACCOUNT_ID,
            'local',
            self::CORRELATION,
            false,
            ['present_snapshot' => $envelope]
        );
        $result = $adapter->run($ctx);
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_legacy_payload', $result->reason());
    }

    public function testFailsWhenCorrelationMismatch(): void
    {
        $envelope = SnapshotEnvelope::wrap(self::ACCOUNT_ID, 'other-corr', ['x' => 1]);
        $adapter = $this->adapter('present_snapshot');
        $ctx = new AgentContext(
            self::ACCOUNT_ID,
            'local',
            self::CORRELATION,
            false,
            ['present_snapshot' => $envelope]
        );
        $result = $adapter->run($ctx);
        $this->assertSame('failed', $result->status());
    }

    public function testFailsWhenEnvelopeHasExtraTopLevelKeys(): void
    {
        $bad = [
            'account_id' => self::ACCOUNT_ID,
            'correlation_id' => self::CORRELATION,
            'payload' => ['ok' => true],
            'intruder' => 'sneaky',
        ];
        $adapter = $this->adapter('present_snapshot');
        $ctx = new AgentContext(
            self::ACCOUNT_ID,
            'local',
            self::CORRELATION,
            false,
            ['present_snapshot' => $bad]
        );
        $result = $adapter->run($ctx);
        $this->assertSame('failed', $result->status());
        $this->assertSame('invalid_legacy_payload', $result->reason());
    }

    public function testFailsWhenShapeMismatchViaMapPayload(): void
    {
        $envelope = SnapshotEnvelope::wrap(self::ACCOUNT_ID, self::CORRELATION, ['ok' => true]);
        $adapter = $this->adapter('present_snapshot');
        $adapter->setPayloadKeys(['required_field']);
        $adapter->setRequireShape(true);
        $ctx = new AgentContext(
            self::ACCOUNT_ID,
            'local',
            self::CORRELATION,
            false,
            ['present_snapshot' => $envelope]
        );
        $result = $adapter->run($ctx);
        $this->assertSame('failed', $result->status());
        $this->assertSame('shape_mismatch', $result->reason());
    }

    public function testSuccessPath(): void
    {
        // mapPayload espera "value" na allowlist. setPayloadKeys define
        // as chaves reconhecidas pelo contrato (resto vira "extra key").
        $envelope = SnapshotEnvelope::wrap(
            self::ACCOUNT_ID,
            self::CORRELATION,
            ['ok' => true, 'value' => 42]
        );
        $adapter = $this->adapter('present_snapshot');
        $adapter->setPayloadKeys(['value']);
        $ctx = new AgentContext(
            self::ACCOUNT_ID,
            'local',
            self::CORRELATION,
            false,
            ['present_snapshot' => $envelope]
        );
        $result = $adapter->run($ctx);
        $this->assertSame('success', $result->status());
        $this->assertSame('legacy_read_complete', $result->reason());
    }

    public function testFailsOnHttpStatusError(): void
    {
        $envelope = SnapshotEnvelope::wrap(
            self::ACCOUNT_ID,
            self::CORRELATION,
            ['ok' => false, 'http_status' => 503]
        );
        $adapter = $this->adapter('present_snapshot');
        $ctx = new AgentContext(
            self::ACCOUNT_ID,
            'local',
            self::CORRELATION,
            false,
            ['present_snapshot' => $envelope]
        );
        $result = $adapter->run($ctx);
        $this->assertSame('failed', $result->status());
        $this->assertStringContainsString('legacy_http_', $result->reason());
    }

    public function testFailsWhenHttpStatusOutOfRange(): void
    {
        // envelope construido manualmente bypassa validacao de wrap()
        $bad = [
            'account_id' => self::ACCOUNT_ID,
            'correlation_id' => self::CORRELATION,
            'payload' => ['ok' => true, 'http_status' => 999],
        ];
        $adapter = $this->adapter('present_snapshot');
        $ctx = new AgentContext(
            self::ACCOUNT_ID,
            'local',
            self::CORRELATION,
            false,
            ['present_snapshot' => $bad]
        );
        $result = $adapter->run($ctx);
        $this->assertSame('failed', $result->status());
    }
}
