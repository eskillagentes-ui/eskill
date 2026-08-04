<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\PureSnapshot;
use App\Services\Agents\SnapshotEnvelope;
use PHPUnit\Framework\TestCase;
use stdClass;

/** @covers \App\Services\Agents\PureSnapshot */
/** @covers \App\Services\Agents\SnapshotEnvelope */
/** @covers \App\Services\Agents\AgentResult */
final class PureSnapshotTest extends TestCase
{
    public function testNormalizeCopiaArraySemReferenciasToctou(): void
    {
        $leaf = 'safe';
        $input = ['x' => &$leaf];
        $copy = PureSnapshot::normalizeArray($input);
        $leaf = static function (): void {
        };
        $this->assertSame('safe', $copy['x']);
        $this->assertFalse(is_callable($copy['x']));
    }

    public function testAgentResultDataToctou(): void
    {
        $leaf = 'ok';
        $data = ['nested' => &$leaf];
        $result = AgentResult::success('a', 'r', $data);
        $leaf = static function (): void {
        };
        $this->assertSame('ok', $result->data()['nested']);
        $this->assertFalse(is_callable($result->data()['nested']));
    }

    public function testAgentContextMetadataToctou(): void
    {
        $leaf = 'ok';
        $meta = ['k' => ['v' => &$leaf]];
        $ctx = new AgentContext(1, 'local', 'c1', false, $meta);
        $leaf = static function (): void {
        };
        $this->assertSame('ok', $ctx->metadata()['k']['v']);
        $this->assertFalse(is_callable($ctx->metadata()['k']['v']));
    }

    /** @dataProvider forbiddenValues */
    public function testRejeitaCapabilities(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PureSnapshot::normalize($value);
    }

    /** @return iterable<string, array{0: mixed}> */
    public function forbiddenValues(): iterable
    {
        yield 'closure' => [static function (): void {
        }];
        yield 'object' => [new stdClass()];
        yield 'callable string' => ['strlen'];
        yield 'callable array' => [[$this, 'forbiddenValues']];
        $callableList = [PureSnapshot::class, 'normalize'];
        yield 'callable class method' => [$callableList];
    }

    public function testRejeitaArrayCallableAntesDePercorrer(): void
    {
        $callableArray = [PureSnapshot::class, 'normalize'];
        $this->assertTrue(is_callable($callableArray));
        $this->expectException(\InvalidArgumentException::class);
        PureSnapshot::normalize($callableArray);
    }

    public function testAgentResultRejeitaClosureEmData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AgentResult::success('a', 'r', ['fn' => static function (): void {
        }]);
    }

    public function testEnvelopeExtraiPayloadQuandoIdentidadeBate(): void
    {
        $wrapped = SnapshotEnvelope::wrap(10, 'corr-a', ['ok' => true]);
        $payload = SnapshotEnvelope::extract($wrapped, 10, 'corr-a');
        $this->assertSame(['ok' => true], $payload);
    }

    public function testEnvelopeRejeitaOutraConta(): void
    {
        $wrapped = SnapshotEnvelope::wrap(10, 'corr-a', ['ok' => true]);
        $this->assertNull(SnapshotEnvelope::extract($wrapped, 11, 'corr-a'));
    }

    public function testEnvelopeRejeitaOutraCorrelacao(): void
    {
        $wrapped = SnapshotEnvelope::wrap(10, 'corr-a', ['ok' => true]);
        $this->assertNull(SnapshotEnvelope::extract($wrapped, 10, 'corr-b'));
    }

    public function testEnvelopeRejeitaCampoExtra(): void
    {
        $this->assertNull(SnapshotEnvelope::extract([
            'account_id' => 10,
            'correlation_id' => 'corr-a',
            'payload' => [],
            'extra' => 1,
        ], 10, 'corr-a'));
    }
}
