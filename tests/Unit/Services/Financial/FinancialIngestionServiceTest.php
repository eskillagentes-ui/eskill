<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\FinancialIngestionService;
use App\Services\Financial\FinancialLedgerService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\FinancialIngestionService
 */
final class FinancialIngestionServiceTest extends TestCase
{
    public function testBackfillOrdersWithEmptyLocalSet(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $svc = new FinancialIngestionService(
            1335,
            $db,
            null,
            new FinancialLedgerService($db)
        );
        $stats = $svc->backfillOrders('2026-08-01', '2026-08-06', [
            'dry_run' => true,
            'fetch_shipping' => false,
            'fetch_refunds' => false,
        ]);

        $this->assertSame(0, $stats['orders_scanned']);
        $this->assertSame(0, $stats['orders_processed']);
        $this->assertSame(0, $stats['discrepancy_count']);
        $this->assertTrue($stats['dry_run']);
    }

    public function testResolveAccountIdWithNumericArg(): void
    {
        $db = $this->createMock(PDO::class);
        $this->assertSame(42, FinancialIngestionService::resolveAccountId('42', $db));
    }

    public function testResolveAccountIdLooksUpNickname(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn('99');

        $db = $this->createMock(PDO::class);
        $db->expects($this->once())->method('prepare')->willReturn($stmt);

        $this->assertSame(99, FinancialIngestionService::resolveAccountId('FACILYTY', $db));
    }

    public function testResolveAccountIdThrowsWhenNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(false);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $this->expectException(\InvalidArgumentException::class);
        FinancialIngestionService::resolveAccountId('missing-nick', $db);
    }
}
