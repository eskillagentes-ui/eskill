<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\BillingChargeIngestionService;
use App\Services\Financial\FeeCommissionService;
use App\Services\Financial\FinancialLedgerService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\BillingChargeIngestionService
 */
final class BillingChargeIngestionServiceTest extends TestCase
{
    public function testBackfillPeriodDryRunMapsPadsAndSkipsOrderLinked(): void
    {
        $fee = $this->createMock(FeeCommissionService::class);
        $fee->method('getBillingDetails')->willReturn([
            'results' => [
                [
                    'order_id' => 999,
                    'detail_id' => 'skip',
                    'detail_sub_type' => 'PADS',
                    'detail_amount' => 10,
                ],
                [
                    'order_id' => null,
                    'detail_id' => 'D-PADS-1',
                    'detail_sub_type' => 'PADS',
                    'detail_amount' => 25.5,
                    'creation_date_time' => '2026-08-01T10:00:00.000-03:00',
                ],
                [
                    'order_id' => null,
                    'detail_id' => 'D-UNK',
                    'detail_sub_type' => 'ZZZZ',
                    'detail_amount' => 5,
                ],
            ],
            'last_id' => null,
        ]);

        $db = $this->createMock(PDO::class);
        $svc = new BillingChargeIngestionService(
            42,
            $db,
            null,
            new FinancialLedgerService($db),
            $fee
        );
        $stats = $svc->backfillPeriod('2026-08-01', ['dry_run' => true]);

        $this->assertSame(3, $stats['lines_scanned']);
        $this->assertSame(2, $stats['lines_without_order']);
        $this->assertSame(1, $stats['lines_mapped']);
        $this->assertSame(1, $stats['entries_unchanged']);
        $this->assertSame(['ZZZZ' => 1], $stats['lines_unmapped_sub_types']);
        $this->assertTrue($stats['dry_run']);
        $this->assertSame([], $stats['errors']);
    }

    public function testBackfillPeriodRangeAggregatesMonths(): void
    {
        $fee = $this->createMock(FeeCommissionService::class);
        $fee->method('getBillingDetails')->willReturn([
            'results' => [],
            'last_id' => null,
        ]);

        $db = $this->createMock(PDO::class);
        $svc = new BillingChargeIngestionService(
            1,
            $db,
            null,
            new FinancialLedgerService($db),
            $fee
        );
        $combined = $svc->backfillPeriodRange('2026-01-01', '2026-03-01', ['dry_run' => true]);

        $this->assertCount(3, $combined['periods']);
        $this->assertSame('2026-01-01', $combined['from']);
        $this->assertSame('2026-03-01', $combined['to']);
    }

    public function testBackfillPeriodRecordsApiError(): void
    {
        $fee = $this->createMock(FeeCommissionService::class);
        $fee->method('getBillingDetails')->willReturn(['error' => 'forbidden']);

        $db = $this->createMock(PDO::class);
        $svc = new BillingChargeIngestionService(
            1,
            $db,
            null,
            new FinancialLedgerService($db),
            $fee
        );
        $stats = $svc->backfillPeriod('2026-08-01', ['dry_run' => true]);

        $this->assertNotEmpty($stats['errors']);
        $this->assertSame('forbidden', $stats['errors'][0]['error']);
    }
}
