<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use App\Services\Agents\SnapshotEnvelope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/** @covers \App\Services\Agents\AgentContext */
final class AgentContextTest extends TestCase
{
    use AgentSnapshotFixtures;

    public function testCriaContextoValidoComDefaults(): void
    {
        $ctx = new AgentContext(10, 'local', 'corr-abc-1', false);
        $this->assertSame(10, $ctx->accountId());
        $this->assertSame('local', $ctx->environment());
        $this->assertSame('corr-abc-1', $ctx->correlationId());
        $this->assertFalse($ctx->mlWriteAutomation());
        $this->assertSame([], $ctx->metadata());
    }

    public function testAceitaSnapshotsEscalaresEAgentResultNoEnvelopeQa(): void
    {
        $result = AgentResult::success('lint', 'ok', ['files' => ['a.php']]);
        $ctx = new AgentContext(1, 'staging', 'corr-snapshot', false, [
            'flag' => true,
            'count' => 2,
            'nested' => ['value' => null],
            'qa_results_snapshot' => SnapshotEnvelope::wrap(
                1,
                'corr-snapshot',
                ['results' => ['lint' => $result]],
                true
            ),
        ]);

        $stored = $ctx->metadata()['qa_results_snapshot']['payload']['results']['lint'];
        $this->assertInstanceOf(AgentResult::class, $stored);
        $this->assertSame(['files' => ['a.php']], $stored->data());
    }

    /** @dataProvider impureMetadata */
    public function testRejeitaCapacidadeArbitrariaNaMetadata(array $metadata): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentContext(1, 'local', 'corr-impure', false, $metadata);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function impureMetadata(): iterable
    {
        yield 'closure direta' => [['port' => static fn (): array => []]];
        yield 'objeto arbitrario aninhado' => [['nested' => ['port' => new stdClass()]]];
    }

    public function testRejeitaClosureDentroDeAgentResultNaCriacao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgentResult::success('lint', 'ok', ['port' => static fn (): array => []]);
    }

    /** @dataProvider qaEnvelopeComCamposExtras */
    public function testRejeitaEnvelopeQaComCamposExtras(array $envelope): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentContext(1, 'local', 'corr-qa-extra', false, [
            'qa_results_snapshot' => $envelope,
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function qaEnvelopeComCamposExtras(): iterable
    {
        $result = AgentResult::success('php-lint', 'ok');
        yield 'campo extra no envelope' => [[
            'account_id' => 1,
            'correlation_id' => 'corr-qa-extra',
            'payload' => ['results' => ['php-lint' => $result]],
            'extra' => true,
        ]];
        yield 'campo extra no payload' => [[
            'account_id' => 1,
            'correlation_id' => 'corr-qa-extra',
            'payload' => [
                'results' => ['php-lint' => $result],
                'extra' => true,
            ],
        ]];
    }

    /** @dataProvider invalidContext */
    public function testRejeitaContextoInvalido(int $accountId, string $environment, string $correlationId): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentContext($accountId, $environment, $correlationId, false);
    }

    /** @return iterable<string, array{int, string, string}> */
    public function invalidContext(): iterable
    {
        yield 'id zero' => [0, 'local', 'corr'];
        yield 'id negativo' => [-1, 'local', 'corr'];
        yield 'ambiente' => [1, 'prod', 'corr'];
        yield 'correlation vazia' => [1, 'local', ''];
        yield 'correlation em branco' => [1, 'local', '   '];
    }
}
