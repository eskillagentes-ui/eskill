<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\FinancialForecastService;
use App\Services\Financial\PnlReportService;
use App\Services\MercadoLivreClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\FinancialForecastService
 */
class FinancialForecastServiceTest extends TestCase
{
    private function buildService(?PnlReportService $pnl = null): FinancialForecastService
    {
        $ref = new \ReflectionClass(FinancialForecastService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $ml = $this->createMock(MercadoLivreClient::class);
        $clientProp = $ref->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($service, $ml);

        $accountIdProp = $ref->getProperty('accountId');
        $accountIdProp->setAccessible(true);
        $accountIdProp->setValue($service, 1);

        if ($pnl !== null) {
            $pnlProp = $ref->getProperty('pnlReportServiceInstance');
            $pnlProp->setAccessible(true);
            $pnlProp->setValue($service, $pnl);
        }

        return $service;
    }

    public function testGetFinancialProjectionHighConfidence(): void
    {
        $pnl = $this->createMock(PnlReportService::class);
        $pnl->method('getPnL')->willReturn([
            'total_orders' => 60,
            'gross_revenue' => 3000.0,
            'net_profit' => 900.0,
            'avg_margin' => 30.0,
        ]);

        $result = $this->buildService($pnl)->getFinancialProjection(30);

        $this->assertSame(30, $result['projection_period_days']);
        $this->assertSame(100.0, $result['historical']['daily_avg_revenue']);
        $this->assertSame(3000.0, $result['projected']['revenue']);
        $this->assertSame(900.0, $result['projected']['profit']);
        $this->assertSame('high', $result['confidence']);
    }

    public function testGetFinancialProjectionLowWhenFewOrders(): void
    {
        $pnl = $this->createMock(PnlReportService::class);
        $pnl->method('getPnL')->willReturn([
            'total_orders' => 0,
            'gross_revenue' => 0.0,
            'net_profit' => 0.0,
            'avg_margin' => 0.0,
        ]);

        $result = $this->buildService($pnl)->getFinancialProjection(15);

        $this->assertSame(0.0, $result['projected']['revenue']);
        $this->assertSame('low', $result['confidence']);
    }

    public function testGetFinancialProjectionMediumConfidence(): void
    {
        $pnl = $this->createMock(PnlReportService::class);
        $pnl->method('getPnL')->willReturn([
            'total_orders' => 15,
            'gross_revenue' => 1500.0,
            'net_profit' => 300.0,
            'avg_margin' => 20.0,
        ]);

        $result = $this->buildService($pnl)->getFinancialProjection(10);

        $this->assertSame('medium', $result['confidence']);
        $this->assertSame(500.0, $result['projected']['revenue']);
    }
}
