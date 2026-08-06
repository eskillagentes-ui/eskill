<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\FeeCommissionService;
use App\Services\Financial\FinancialLedgerService;
use App\Services\Financial\SettlementIngestionService;
use App\Services\MercadoLivreClient;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\SettlementIngestionService
 */
final class SettlementIngestionServiceTest extends TestCase
{
    public function testBackfillReleasesWithNoPaidOrders(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $fee = $this->createMock(FeeCommissionService::class);
        $fee->expects($this->never())->method('getBillingByOrder');

        $svc = new SettlementIngestionService(
            10,
            $db,
            null,
            new FinancialLedgerService($db),
            $this->createMock(MercadoLivreClient::class),
            $fee
        );

        $stats = $svc->backfillReleases('2026-08-01', '2026-08-06', [
            'dry_run' => true,
            'sleep_us' => 0,
        ]);

        $this->assertSame(0, $stats['orders_scanned']);
        $this->assertSame(0, $stats['orders_with_payment']);
        $this->assertTrue($stats['dry_run']);
    }

    public function testBackfillReleasesSkipsOrdersWithoutPaymentId(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            [
                'ml_order_id' => '2001',
                'order_data' => json_encode(['payments' => [
                    ['id' => 1, 'status' => 'rejected'],
                ]], JSON_THROW_ON_ERROR),
                'date_created' => '2026-08-01 10:00:00',
            ],
        ]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $fee = $this->createMock(FeeCommissionService::class);
        $fee->method('getBillingByOrder')->willReturn(['results' => []]);

        $client = $this->createMock(MercadoLivreClient::class);
        $client->expects($this->never())->method('get');

        $svc = new SettlementIngestionService(
            10,
            $db,
            null,
            new FinancialLedgerService($db),
            $client,
            $fee
        );

        $stats = $svc->backfillReleases('2026-08-01', '2026-08-06', [
            'dry_run' => true,
            'sleep_us' => 0,
        ]);

        $this->assertSame(1, $stats['orders_scanned']);
        $this->assertSame(1, $stats['skipped_no_payment']);
        $this->assertSame(0, $stats['orders_with_payment']);
    }
}
