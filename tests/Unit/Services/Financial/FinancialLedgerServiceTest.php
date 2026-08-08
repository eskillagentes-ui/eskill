<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\FinancialLedgerService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\FinancialLedgerService
 */
final class FinancialLedgerServiceTest extends TestCase
{
    public function testSettlementReleaseDoesNotDuplicateIntoMarketplaceNet(): void
    {
        // Venda simples: receita 29.79, comissão -5.06, frete -7.95 => marketplace_net = 16.78.
        // O settlement_release credita os mesmos 16.78 (dinheiro liberado), mas é caixa,
        // não resultado de venda — não pode duplicar o marketplace_net.
        $rows = [
            ['entry_type' => 'sale_revenue', 'entry_category' => 'revenue', 'status' => 'posted', 'signed_amount' => 29.79],
            ['entry_type' => 'sale_fee', 'entry_category' => 'marketplace_fee', 'status' => 'posted', 'signed_amount' => -5.06],
            ['entry_type' => 'shipping_cost', 'entry_category' => 'shipping', 'status' => 'posted', 'signed_amount' => -7.95],
            ['entry_type' => 'settlement_release', 'entry_category' => 'settlement', 'status' => 'posted', 'signed_amount' => 16.78],
        ];

        $agg = FinancialLedgerService::aggregateEntries($rows);

        $this->assertSame(16.78, $agg['marketplace_net']);
        $this->assertSame(16.78, $agg['settlement_net']);
        $this->assertSame(16.78, $agg['released_amount']);
        $this->assertSame(0.0, $agg['pending_release_amount']);
        $this->assertSame(4, $agg['entries_count']);
    }

    public function testPendingReleaseIsTrackedSeparatelyFromReleasedAmount(): void
    {
        $rows = [
            ['entry_type' => 'sale_revenue', 'entry_category' => 'revenue', 'status' => 'posted', 'signed_amount' => 100.0],
            ['entry_type' => 'settlement_release', 'entry_category' => 'settlement', 'status' => 'pending', 'signed_amount' => 80.0],
        ];

        $agg = FinancialLedgerService::aggregateEntries($rows);

        $this->assertSame(100.0, $agg['marketplace_net'], 'pending settlement não deve afetar P&L');
        $this->assertSame(0.0, $agg['released_amount']);
        $this->assertSame(80.0, $agg['pending_release_amount']);
    }

    public function testCancelledStatusExcludedEverywhereIncludingSettlement(): void
    {
        $rows = [
            ['entry_type' => 'sale_revenue', 'entry_category' => 'revenue', 'status' => 'posted', 'signed_amount' => 50.0],
            ['entry_type' => 'settlement_release', 'entry_category' => 'settlement', 'status' => 'cancelled', 'signed_amount' => 40.0],
        ];

        $agg = FinancialLedgerService::aggregateEntries($rows);

        $this->assertSame(50.0, $agg['marketplace_net']);
        $this->assertSame(0.0, $agg['settlement_net']);
        $this->assertSame(1, $agg['entries_count']);
    }

    public function testWithdrawalCategoryAlsoExcludedFromMarketplaceNet(): void
    {
        $rows = [
            ['entry_type' => 'sale_revenue', 'entry_category' => 'revenue', 'status' => 'posted', 'signed_amount' => 100.0],
            ['entry_type' => 'withdrawal', 'entry_category' => 'withdrawal', 'status' => 'posted', 'signed_amount' => -90.0],
        ];

        $agg = FinancialLedgerService::aggregateEntries($rows);

        $this->assertSame(100.0, $agg['marketplace_net'], 'saque não é despesa operacional');
        $this->assertSame(-90.0, $agg['settlement_net']);
        $this->assertSame(90.0, $agg['withdrawn_amount']);
        $this->assertArrayHasKey('withdrawal', $agg['by_category']);
    }

    public function testProgramHoldExcludedFromMarketplaceNet(): void
    {
        $rows = [
            ['entry_type' => 'sale_revenue', 'entry_category' => 'revenue', 'status' => 'posted', 'signed_amount' => 100.0],
            ['entry_type' => 'program_hold', 'entry_category' => 'hold', 'status' => 'posted', 'signed_amount' => -250.0],
        ];

        $agg = FinancialLedgerService::aggregateEntries($rows);

        $this->assertSame(100.0, $agg['marketplace_net'], 'hold não é despesa operacional');
        $this->assertSame(250.0, $agg['hold_amount']);
    }

    public function testEmptyRowsReturnZeroedAggregate(): void
    {
        $agg = FinancialLedgerService::aggregateEntries([]);

        $this->assertSame(0.0, $agg['marketplace_net']);
        $this->assertSame(0.0, $agg['settlement_net']);
        $this->assertSame(0.0, $agg['withdrawn_amount']);
        $this->assertSame(0.0, $agg['hold_amount']);
        $this->assertSame(0, $agg['entries_count']);
        $this->assertSame([], $agg['by_type']);
        $this->assertSame([], $agg['by_category']);
    }

    public function testAdvertisingFeeReducesMarketplaceNetButNotReleased(): void
    {
        $rows = [
            ['entry_type' => 'sale_revenue', 'entry_category' => 'revenue', 'status' => 'posted', 'signed_amount' => 100.0],
            ['entry_type' => 'settlement_release', 'entry_category' => 'settlement', 'status' => 'posted', 'signed_amount' => 85.0],
            ['entry_type' => 'advertising_fee', 'entry_category' => 'advertising', 'status' => 'posted', 'signed_amount' => -12.5],
        ];

        $agg = FinancialLedgerService::aggregateEntries($rows);

        $this->assertSame(87.5, $agg['marketplace_net'], 'ads reduz P&L operacional');
        $this->assertSame(85.0, $agg['released_amount'], 'ads não altera liberado de settlement');
        $this->assertSame(-12.5, (float)($agg['by_category']['advertising'] ?? 0));
    }

    public function testCoveredChargebackExcludedFromMarketplaceNet(): void
    {
        $rows = [
            ['entry_type' => 'sale_revenue', 'entry_category' => 'revenue', 'status' => 'posted', 'signed_amount' => 50.0],
            ['entry_type' => 'chargeback', 'entry_category' => 'refund', 'status' => 'covered', 'signed_amount' => -50.0],
            ['entry_type' => 'settlement_release', 'entry_category' => 'settlement', 'status' => 'posted', 'signed_amount' => 40.0],
        ];

        $agg = FinancialLedgerService::aggregateEntries($rows);

        $this->assertSame(50.0, $agg['marketplace_net'], 'chargeback covered não derruba P&L');
        $this->assertSame(40.0, $agg['released_amount']);
        $this->assertSame(2, $agg['entries_count'], 'covered não entra no count agregado');
    }
}
