<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/** @covers \App\Services\Agents\AgentContext */
final class AgentContextTest extends TestCase
{
    public function testCriaContextoValidoComDefaults(): void
    {
        $ctx = new AgentContext(10, 'local', 'corr-abc-1', false);
        $this->assertSame(10, $ctx->accountId());
        $this->assertSame('local', $ctx->environment());
        $this->assertSame('corr-abc-1', $ctx->correlationId());
        $this->assertFalse($ctx->mlWriteAutomation());
        $this->assertSame([], $ctx->metadata());
    }

    public function testAceitaSnapshotsEscalaresEAgentResultSeguro(): void
    {
        $result = AgentResult::success('lint', 'ok', ['files' => ['a.php']]);
        $ctx = new AgentContext(1, 'staging', 'corr-snapshot', false, [
            'flag' => true,
            'count' => 2,
            'nested' => ['value' => null],
            'qa_results_snapshot' => ['lint' => $result],
        ]);

        $this->assertSame($result, $ctx->metadata()['qa_results_snapshot']['lint']);
    }

    /** @dataProvider impureMetadata */
    public function testRejeitaCapacidadeArbitrariaNaMetadata(array $metadata): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pure snapshot');
        new AgentContext(1, 'local', 'corr-impure', false, $metadata);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function impureMetadata(): iterable
    {
        yield 'closure direta' => [['port' => static fn (): array => []]];
        yield 'objeto arbitrario aninhado' => [['nested' => ['port' => new stdClass()]]];
        yield 'closure dentro de AgentResult' => [[
            'qa_results_snapshot' => [
                'lint' => AgentResult::success('lint', 'ok', ['port' => static fn (): array => []]),
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
