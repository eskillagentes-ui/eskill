<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\ProductProfitabilityService;
use App\Services\MercadoLivreClient;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\ProductProfitabilityService
 */
class ProductProfitabilityServiceTest extends TestCase
{
    private function buildService(PDO $db): ProductProfitabilityService
    {
        $ref = new \ReflectionClass(ProductProfitabilityService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $ml = $this->createMock(MercadoLivreClient::class);
        $clientProp = $ref->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($service, $ml);

        $accountIdProp = $ref->getProperty('accountId');
        $accountIdProp->setAccessible(true);
        $accountIdProp->setValue($service, 1);

        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $db);

        return $service;
    }

    public function testCalculateProductRoiWithProfit(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'total_sales' => 2,
            'total_units' => 4,
            'total_revenue' => 400.0,
            'total_fees' => 40.0,
            'total_shipping' => 20.0,
        ]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $result = $this->buildService($db)->calculateProductROI(
            'MLB1',
            50.0,
            '2026-08-01',
            '2026-08-31'
        );

        // cost 50*4=200; expenses 40+20+200=260; profit 400-260=140; ROI 140/200*100=70
        $this->assertSame('MLB1', $result['item_id']);
        $this->assertSame(140.0, $result['financials']['net_profit']);
        $this->assertSame(70.0, $result['metrics']['roi_percentage']);
        $this->assertSame(35.0, $result['metrics']['profit_margin']);
        $this->assertSame(2.0, $result['sales']['avg_units_per_order']);
    }

    public function testCalculateProductRoiZeroSales(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'total_sales' => 0,
            'total_units' => 0,
            'total_revenue' => 0.0,
            'total_fees' => 0.0,
            'total_shipping' => 0.0,
        ]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $result = $this->buildService($db)->calculateProductROI(
            'MLB0',
            10.0,
            '2026-08-01',
            '2026-08-31'
        );

        $this->assertSame(0.0, $result['metrics']['roi_percentage']);
        $this->assertSame(0, $result['metrics']['profit_per_unit']);
        $this->assertSame(0, $result['sales']['total_orders']);
    }
}
