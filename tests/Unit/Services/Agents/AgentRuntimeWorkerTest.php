<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agents;

use App\Services\Agents\AgentResult;
use App\Services\Agents\AgentRuntimeAccountSourceInterface;
use App\Services\Agents\AgentRuntimeExecutorInterface;
use App\Services\Agents\AgentRuntimeReporterInterface;
use App\Services\Agents\AgentRuntimeWorker;
use App\Services\Pregao\PregaoHojeQueueService;
use PDO;
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

    public function testReportaResultadoFinalDepoisDoRetry(): void
    {
        $source = $this->createMock(AgentRuntimeAccountSourceInterface::class);
        $source->method('activeAccountIds')->willReturn([10]);
        $executor = $this->createMock(AgentRuntimeExecutorInterface::class);
        $attempt = 0;
        $executor->method('execute')->willReturnCallback(
            static function () use (&$attempt): AgentResult {
                $attempt++;
                return $attempt === 1
                    ? AgentResult::failed('orquestrador', 'agent_failed')
                    : AgentResult::success('orquestrador', 'aggregated');
            }
        );
        $reporter = $this->createMock(AgentRuntimeReporterInterface::class);
        $reporter->expects(self::once())
            ->method('report')
            ->with(
                10,
                'agent24x7-20260804T120000Z-0123abcd:10',
                self::callback(static fn (AgentResult $result): bool => $result->status() === 'success'),
                2
            );

        $records = (new AgentRuntimeWorker($source, $executor, $reporter))
            ->runCycle('production', 'agent24x7-20260804T120000Z-0123abcd', 2);

        self::assertSame('success', $records[0]['status']);
        self::assertSame(2, $records[0]['attempts']);
    }

    public function testFalhaDeTelemetriaNaoDerrubaRuntimeNemVazaMensagem(): void
    {
        $source = $this->createMock(AgentRuntimeAccountSourceInterface::class);
        $source->method('activeAccountIds')->willReturn([10]);
        $executor = $this->createMock(AgentRuntimeExecutorInterface::class);
        $executor->method('execute')->willReturn(AgentResult::success('orquestrador', 'aggregated'));
        $reporter = $this->createMock(AgentRuntimeReporterInterface::class);
        $reporter->method('report')->willThrowException(new RuntimeException('token-secret-value'));

        $records = (new AgentRuntimeWorker($source, $executor, $reporter))
            ->runCycle('production', 'cycle-6', 1);

        self::assertSame('success', $records[0]['status']);
        self::assertStringNotContainsString('secret', json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function testHeartbeatObserveQueueLogaFilasAbertasSemFerramentaDeEscrita(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE items (
                account_id INTEGER, ml_item_id TEXT, title TEXT, status TEXT,
                available_quantity INTEGER, sold_quantity INTEGER,
                catalog_product_id TEXT, cost_price REAL, data TEXT
            )'
        );
        $db->exec('CREATE TABLE sku_custos (account_id INTEGER, mlb_id TEXT, custo_produto REAL)');
        $db->exec('CREATE TABLE ml_orders (ml_account_id INTEGER, account_id INTEGER, order_data TEXT)');
        $db->exec(
            "INSERT INTO items (account_id, ml_item_id, title, status, available_quantity, sold_quantity, catalog_product_id, data)
             VALUES (10, 'MLB-GAP', 'gap', 'active', 1, 0, NULL, '{\"pictures\":[1],\"shipping\":{\"free_shipping\":false}}')"
        );

        $source = $this->createMock(AgentRuntimeAccountSourceInterface::class);
        $source->method('activeAccountIds')->willReturn([10]);
        $executor = $this->createMock(AgentRuntimeExecutorInterface::class);
        $executor->expects(self::once())
            ->method('execute')
            ->with(10, 'cycle-q:10', 'production', 'monitor')
            ->willReturn(AgentResult::success('orquestrador', 'aggregated'));

        $records = (new AgentRuntimeWorker(
            $source,
            $executor,
            null,
            new PregaoHojeQueueService($db)
        ))->runCycle('production', 'cycle-q', 1);

        self::assertTrue($records[0]['queues']['refreshed']);
        self::assertContains('ficha', $records[0]['queues']['open']);
        self::assertTrue($records[0]['queues']['apply_blocked']);
        self::assertFalse($records[0]['queues']['ml_write']);
        self::assertSame('local', $records[0]['queues']['source']);
        self::assertNotContains('visits_no_sales', $records[0]['queues']['open']);

        $src = file_get_contents(dirname(__DIR__, 4) . '/app/Services/Agents/AgentRuntimeWorker.php');
        self::assertIsString($src);
        self::assertStringContainsString('observe-queue heartbeat', $src);
        self::assertStringNotContainsString('MercadoLivreClient', $src);
        self::assertStringNotContainsString('->post(', $src);
        self::assertStringNotContainsString('pauseItem', $src);
    }

}
