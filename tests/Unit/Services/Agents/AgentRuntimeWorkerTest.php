<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentResult;
use App\Services\Agents\AgentRuntimeAccountSourceInterface;
use App\Services\Agents\AgentRuntimeExecutorInterface;
use App\Services\Agents\AgentRuntimeWorker;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/** @covers \App\Services\Agents\AgentRuntimeWorker */
final class AgentRuntimeWorkerTest extends TestCase
{
    public function testExecutaTodasContasAtivasEmModoMonitor(): void
    {
        $source = $this->createMock(AgentRuntimeAccountSourceInterface::class);
        $source->method('activeAccountIds')->willReturn([10, 20]);
        $executor = $this->createMock(AgentRuntimeExecutorInterface::class);
        $executor->expects(self::exactly(2))
            ->method('execute')
            ->withConsecutive(
                [10, 'cycle-1:10', 'production', 'monitor'],
                [20, 'cycle-1:20', 'production', 'monitor']
            )
            ->willReturn(AgentResult::success('orquestrador', 'aggregated'));

        $records = (new AgentRuntimeWorker($source, $executor))->runCycle('production', 'cycle-1', 2);

        self::assertSame([10, 20], array_column($records, 'accountId'));
        self::assertSame(['success', 'success'], array_column($records, 'status'));
        self::assertSame([1, 1], array_column($records, 'attempts'));
    }

    public function testRepeteFalhaUmaVezComMesmaCorrelacao(): void
    {
        $source = $this->createMock(AgentRuntimeAccountSourceInterface::class);
        $source->method('activeAccountIds')->willReturn([10]);
        $executor = $this->createMock(AgentRuntimeExecutorInterface::class);
        $attempt = 0;
        $executor->method('execute')->willReturnCallback(
            static function (int $accountId, string $correlation, string $environment, string $mode) use (&$attempt): AgentResult {
                self::assertSame([10, 'cycle-2:10', 'production', 'monitor'], [
                    $accountId, $correlation, $environment, $mode,
                ]);
                $attempt++;
                return $attempt === 1
                    ? AgentResult::failed('orquestrador', 'agent_failed')
                    : AgentResult::success('orquestrador', 'aggregated');
            }
        );

        $records = (new AgentRuntimeWorker($source, $executor))->runCycle('production', 'cycle-2', 2);

        self::assertSame(2, $records[0]['attempts']);
        self::assertSame('success', $records[0]['status']);
    }

    public function testIsolaExcecaoSemExporMensagem(): void
    {
        $source = $this->createMock(AgentRuntimeAccountSourceInterface::class);
        $source->method('activeAccountIds')->willReturn([10]);
        $executor = $this->createMock(AgentRuntimeExecutorInterface::class);
        $executor->method('execute')->willThrowException(new RuntimeException('token-secret-value'));

        $records = (new AgentRuntimeWorker($source, $executor))->runCycle('production', 'cycle-3', 1);

        self::assertSame('failed', $records[0]['status']);
        self::assertSame('runtime_exception', $records[0]['reason']);
        self::assertStringNotContainsString('secret', json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function testRejeitaListaDeContasMalformadaAntesDeExecutar(): void
    {
        $source = $this->createMock(AgentRuntimeAccountSourceInterface::class);
        $source->method('activeAccountIds')->willReturn([10, 0]);
        $executor = $this->createMock(AgentRuntimeExecutorInterface::class);
        $executor->expects(self::never())->method('execute');

        $this->expectException(UnexpectedValueException::class);

        (new AgentRuntimeWorker($source, $executor))->runCycle('production', 'cycle-4', 2);
    }
}
