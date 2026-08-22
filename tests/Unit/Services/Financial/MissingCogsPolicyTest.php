<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\MissingCogsPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\MissingCogsPolicy
 */
final class MissingCogsPolicyTest extends TestCase
{
    public function testMissingCostDoesNotPresentNumericZeroProfitAsReal(): void
    {
        $line = MissingCogsPolicy::lineProfit(
            false,
            80.0,
            0.0,
            0.0,
            0.0,
            5.0,
            100.0
        );

        $this->assertFalse($line['has_cogs']);
        $this->assertNull($line['product_cost']);
        $this->assertNull($line['profit']);
        $this->assertNull($line['margin_pct']);
        $this->assertNotSame(0.0, $line['profit']);
        $this->assertNotSame(0, $line['profit']);
    }

    public function testKnownCostStillComputesProfit(): void
    {
        $line = MissingCogsPolicy::lineProfit(
            true,
            80.0,
            30.0,
            0.0,
            0.0,
            5.0,
            100.0
        );

        $this->assertTrue($line['has_cogs']);
        $this->assertSame(30.0, $line['product_cost']);
        $this->assertSame(45.0, $line['profit']);
        $this->assertSame(45.0, $line['margin_pct']);
    }

    public function testHasRealCogsFalseWhenAnySoldItemLacksCmv(): void
    {
        $this->assertFalse(MissingCogsPolicy::hasRealCogs(18, 351));
        $this->assertFalse(MissingCogsPolicy::hasRealCogs(0, 10));
        $this->assertFalse(MissingCogsPolicy::hasRealCogs(0, 0));
        $this->assertTrue(MissingCogsPolicy::hasRealCogs(10, 0));
    }

    public function testPresentPnLNullsProfitWhenCoverageIncomplete(): void
    {
        $pnl = MissingCogsPolicy::presentPnL(
            [
                'cogs' => 500.0,
                'cogs_source' => 'sku_custos',
                'net_profit' => 1200.0,
                'avg_margin' => 40.0,
            ],
            [
                'cogs' => 500.0,
                'items_com_custo' => 18,
                'items_sem_custo' => 351,
            ]
        );

        $this->assertFalse($pnl['has_real_cogs']);
        $this->assertSame(351, $pnl['items_sem_custo']);
        $this->assertSame(18, $pnl['items_com_custo']);
        $this->assertNull($pnl['net_profit']);
        $this->assertNull($pnl['avg_margin']);
        $this->assertNull($pnl['lucro_conhecido']);
        $this->assertSame('parcial', $pnl['cogs_source']);
        $this->assertSame(500.0, $pnl['cogs']);
    }

    public function testPresentPnLKeepsProfitWhenEveryUnitHasCmv(): void
    {
        $pnl = MissingCogsPolicy::presentPnL(
            [
                'cogs' => 200.0,
                'cogs_source' => 'sku_custos',
                'net_profit' => 630.0,
                'avg_margin' => 63.0,
            ],
            [
                'cogs' => 200.0,
                'items_com_custo' => 12,
                'items_sem_custo' => 0,
            ]
        );

        $this->assertTrue($pnl['has_real_cogs']);
        $this->assertSame(630.0, $pnl['net_profit']);
        $this->assertSame(63.0, $pnl['avg_margin']);
        $this->assertSame(630.0, $pnl['lucro_conhecido']);
        $this->assertSame('sku_custos', $pnl['cogs_source']);
    }

    public function testPresentProfitMarginsDoesNotCallNetProfitLiquidaRealWithoutCmv(): void
    {
        $rows = MissingCogsPolicy::presentProfitMargins(
            [
                [
                    'listing_type' => 'gold_special',
                    'order_count' => 10,
                    'revenue' => 1000.0,
                    'profit' => 400.0,
                    'avg_margin' => 40.0,
                ],
            ],
            false
        );

        $this->assertNull($rows[0]['profit']);
        $this->assertNull($rows[0]['avg_margin']);
        $this->assertSame('sem_cmv', $rows[0]['cogs_status']);
        $this->assertSame('sem CMV', $rows[0]['profit_label']);
        $this->assertSame(1000.0, $rows[0]['revenue']);
    }

    public function testPresentSalesOverTimeProfitNullsDailyPnlWithoutCmv(): void
    {
        $rows = MissingCogsPolicy::presentSalesOverTimeProfit(
            [
                ['date' => '2026-08-21', 'total' => 100.0, 'profit' => 40.0],
            ],
            false
        );

        $this->assertNull($rows[0]['profit']);
        $this->assertSame('sem_cmv', $rows[0]['cogs_status']);
        $this->assertSame(100.0, $rows[0]['total']);
        $this->assertNull(MissingCogsPolicy::presentNetProfit(40.0, false));
        $this->assertSame(40.0, MissingCogsPolicy::presentNetProfit(40.0, true));
    }

    public function testSaleProfitFromItemsIsNdWhenAnyLineLacksCogs(): void
    {
        $sale = MissingCogsPolicy::saleProfitFromItems(
            ['total_amount' => 150.0, 'profit' => 90.0, 'margin_pct' => 60.0, 'product_cost' => 0.0],
            [
                ['has_cogs' => true, 'linked_product' => true, 'profit' => 20.0, 'product_cost' => 10.0],
                ['has_cogs' => false, 'linked_product' => false, 'profit' => 70.0, 'product_cost' => 0.0],
            ]
        );

        $this->assertFalse($sale['has_cogs']);
        $this->assertNull($sale['profit']);
        $this->assertNull($sale['margin_pct']);
        $this->assertSame(1, $sale['items_sem_custo']);
        $this->assertSame(10.0, $sale['product_cost']);
    }

    public function testIsKnownUnitCostRequiresPositiveCost(): void
    {
        $this->assertFalse(MissingCogsPolicy::isKnownUnitCost(0.0, 0.0));
        $this->assertFalse(MissingCogsPolicy::isKnownUnitCost(-1.0, 0.0));
        $this->assertTrue(MissingCogsPolicy::isKnownUnitCost(12.5, 0.0));
        $this->assertTrue(MissingCogsPolicy::isKnownUnitCost(0.0, 8.0));
    }
}
