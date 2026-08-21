<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\PnlReportService;
use App\Services\MercadoLivreClient;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\PnlReportService
 */
final class PnlReportServiceTest extends TestCase
{
    private function buildService(PDO $db): PnlReportService
    {
        $ref = new \ReflectionClass(PnlReportService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $clientProp = $ref->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($service, $this->createMock(MercadoLivreClient::class));

        $accountIdProp = $ref->getProperty('accountId');
        $accountIdProp->setAccessible(true);
        $accountIdProp->setValue($service, 1);

        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $db);

        return $service;
    }

    public function testBoundDateTimeRangeExpandsDateOnly(): void
    {
        $svc = $this->buildService($this->createMock(PDO::class));
        $ref = new \ReflectionClass($svc);
        $method = $ref->getMethod('boundDateTimeRange');
        $method->setAccessible(true);

        [$start, $end] = $method->invoke($svc, '2026-08-01', '2026-08-06');

        $this->assertSame('2026-08-01 00:00:00', $start);
        $this->assertSame('2026-08-06 23:59:59', $end);
    }

    public function testGetAdvertisingExpensesNegatesSignedDebit(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(-91.99);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $svc = $this->buildService($db);
        $ref = new \ReflectionClass($svc);
        $method = $ref->getMethod('getAdvertisingExpenses');
        $method->setAccessible(true);

        $expense = $method->invoke($svc, '2026-08-01 00:00:00', '2026-08-06 23:59:59');
        $this->assertSame(91.99, $expense);
    }

    public function testGetPnLAndComparePeriods(): void
    {
        $pnlRowCurrent = [
            'total_orders' => 10,
            'gross_revenue' => 1000.0,
            'subtotal' => 1000.0,
            'commissions' => 100.0,
            'payment_fees' => 20.0,
            'fixed_fees' => 0.0,
            'shipping_cost' => 50.0,
            'discounts' => 0.0,
            'taxes' => 0.0,
            'cogs' => 200.0,
            'net_profit' => 630.0,
            'avg_margin' => 63.0,
            'units_sold' => 12,
        ];
        $pnlRowPrevious = [
            'total_orders' => 5,
            'gross_revenue' => 500.0,
            'subtotal' => 500.0,
            'commissions' => 50.0,
            'payment_fees' => 10.0,
            'fixed_fees' => 0.0,
            'shipping_cost' => 25.0,
            'discounts' => 0.0,
            'taxes' => 0.0,
            'cogs' => 100.0,
            'net_profit' => 315.0,
            'avg_margin' => 63.0,
            'units_sold' => 6,
        ];

        $aggregateCalls = 0;
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $sql) use (&$aggregateCalls, $pnlRowCurrent, $pnlRowPrevious): PDOStatement {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);

            if (str_contains($sql, 'COUNT(*)')) {
                $row = $aggregateCalls === 0 ? $pnlRowCurrent : $pnlRowPrevious;
                $aggregateCalls++;
                $stmt->method('fetch')->willReturn($row);
                return $stmt;
            }
            if (str_contains($sql, 'SUM(signed_amount)')) {
                $stmt->method('fetchColumn')->willReturn(0.0);
                return $stmt;
            }
            // order ids (ledger ops) + cash listByPeriod → vazio
            $stmt->method('fetchAll')->willReturn([]);
            return $stmt;
        });

        $svc = $this->buildService($db);
        $cmp = $svc->comparePeriods('2026-08-01', '2026-08-06', '2026-07-01', '2026-07-31');

        $this->assertSame(1000.0, $cmp['current']['gross_revenue']);
        $this->assertSame(500.0, $cmp['previous']['gross_revenue']);
        $this->assertSame(100.0, $cmp['variations']['gross_revenue']);
        $this->assertSame(100.0, $cmp['variations']['total_orders']);
    }

    public function testGetAccountBalanceWithoutSeller(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $client->method('getSellerId')->willReturn(null);

        $ref = new \ReflectionClass(PnlReportService::class);
        $service = $ref->newInstanceWithoutConstructor();
        $clientProp = $ref->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($service, $client);
        $accountIdProp = $ref->getProperty('accountId');
        $accountIdProp->setAccessible(true);
        $accountIdProp->setValue($service, 1);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $this->createMock(PDO::class));

        $result = $service->getAccountBalance();
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, $result['available_balance']);
    }

    public function testPresentPnLCoverageViaPolicyWhenPartialSkuCustos(): void
    {
        $presented = \App\Services\Financial\MissingCogsPolicy::presentPnL(
            [
                'gross_revenue' => 1000.0,
                'cogs' => 200.0,
                'cogs_source' => 'sku_custos',
                'net_profit' => 630.0,
                'avg_margin' => 63.0,
            ],
            ['cogs' => 200.0, 'items_com_custo' => 5, 'items_sem_custo' => 7]
        );

        $this->assertFalse($presented['has_real_cogs']);
        $this->assertNull($presented['net_profit']);
        $this->assertSame(7, $presented['items_sem_custo']);
    }
}
