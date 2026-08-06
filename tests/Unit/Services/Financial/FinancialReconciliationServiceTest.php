<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\FinancialEntryCategory;
use App\Services\Financial\FinancialEntryType;
use App\Services\Financial\FinancialLedgerService;
use App\Services\Financial\FinancialReconciliationService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\FinancialReconciliationService
 */
final class FinancialReconciliationServiceTest extends TestCase
{
    public function testListByOrderFiltersOpenStatus(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (array $params): bool {
                return $params[':a'] === 3
                    && $params[':o'] === 'ORD-1'
                    && $params[':s'] === 'open';
            }))
            ->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            ['discrepancy_type' => 'missing_shipping_cost', 'severity' => 'warning'],
        ]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $svc = new FinancialReconciliationService(3, $db);
        $rows = $svc->listByOrder('ORD-1', 'open');

        $this->assertCount(1, $rows);
        $this->assertSame('missing_shipping_cost', $rows[0]['discrepancy_type']);
    }

    public function testGetOrderReconciliationViewFallsBackToLedger(): void
    {
        $viewStmt = $this->createMock(PDOStatement::class);
        $viewStmt->method('execute')->willThrowException(new \RuntimeException('view missing'));

        $ledgerStmt = $this->createMock(PDOStatement::class);
        $ledgerStmt->method('execute')->willReturn(true);
        $ledgerStmt->method('fetchAll')->willReturn([
            [
                'entry_type' => FinancialEntryType::SALE_REVENUE,
                'entry_category' => FinancialEntryCategory::REVENUE,
                'status' => 'posted',
                'signed_amount' => 100.0,
            ],
            [
                'entry_type' => FinancialEntryType::SALE_FEE,
                'entry_category' => FinancialEntryCategory::MARKETPLACE_FEE,
                'status' => 'posted',
                'signed_amount' => -15.0,
            ],
            [
                'entry_type' => FinancialEntryType::PAYMENT_FEE,
                'entry_category' => FinancialEntryCategory::MARKETPLACE_FEE,
                'status' => 'posted',
                'signed_amount' => -4.5,
            ],
            [
                'entry_type' => FinancialEntryType::SHIPPING_COST,
                'entry_category' => FinancialEntryCategory::SHIPPING,
                'status' => 'posted',
                'signed_amount' => -10.0,
            ],
        ]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($viewStmt, $ledgerStmt): PDOStatement {
            return str_contains($sql, 'vw_order_financial_reconciliation') ? $viewStmt : $ledgerStmt;
        });

        $svc = new FinancialReconciliationService(9, $db, new FinancialLedgerService($db));
        $view = $svc->getOrderReconciliationView('ORD-9');

        $this->assertNotNull($view);
        $this->assertSame(100.0, $view['sale_revenue']);
        $this->assertSame(-19.5, $view['fees']);
        $this->assertSame(70.5, $view['marketplace_net']);
        $this->assertSame(4, $view['entries_count']);
    }

    public function testGetOrderReconciliationViewReturnsNullWhenEmptyLedger(): void
    {
        $viewStmt = $this->createMock(PDOStatement::class);
        $viewStmt->method('execute')->willThrowException(new \RuntimeException('view missing'));

        $ledgerStmt = $this->createMock(PDOStatement::class);
        $ledgerStmt->method('execute')->willReturn(true);
        $ledgerStmt->method('fetchAll')->willReturn([]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($viewStmt, $ledgerStmt): PDOStatement {
            return str_contains($sql, 'vw_order_financial_reconciliation') ? $viewStmt : $ledgerStmt;
        });

        $svc = new FinancialReconciliationService(9, $db, new FinancialLedgerService($db));
        $this->assertNull($svc->getOrderReconciliationView('ORD-EMPTY'));
    }

    public function testReconcilePeriodWithNoOrders(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $svc = new FinancialReconciliationService(1, $db);
        $stats = $svc->reconcilePeriod('2026-08-01', '2026-08-06');

        $this->assertSame(0, $stats['orders_checked']);
        $this->assertSame(0, $stats['discrepancies_upserted']);
    }
}
