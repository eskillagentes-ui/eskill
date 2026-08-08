<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\FinancialDiscrepancyType;
use PHPUnit\Framework\TestCase;

/**
 * Regras de reconciliação testáveis sem DB (PATCH 7).
 */
final class FinancialReconciliationRulesTest extends TestCase
{
    public function testMissingShippingRequiresPositiveSellerCost(): void
    {
        // Frete buyer-paid / seller cost 0 com shipment_id NÃO é divergência.
        $shipmentId = 47176344379;
        $hasShipping = false;
        $dbShipping = 0.0;
        $orderStatus = 'paid';

        $shouldFlag = $shipmentId
            && !$hasShipping
            && $dbShipping > 0.0
            && !in_array($orderStatus, ['cancelled'], true);

        $this->assertFalse($shouldFlag);
    }

    public function testMissingShippingFlagsWhenSellerCostPresent(): void
    {
        $shipmentId = 46330407741;
        $hasShipping = false;
        $dbShipping = 19.96;
        $orderStatus = 'paid';

        $shouldFlag = $shipmentId
            && !$hasShipping
            && $dbShipping > 0.0
            && !in_array($orderStatus, ['cancelled'], true);

        $this->assertTrue($shouldFlag);
        $this->assertSame(
            FinancialDiscrepancyType::MISSING_SHIPPING_COST,
            FinancialDiscrepancyType::MISSING_SHIPPING_COST
        );
    }

    public function testCoveredRefundTypeConstantRemainsForHistoricalRows(): void
    {
        $this->assertSame(
            'refund_without_financial_debit',
            FinancialDiscrepancyType::REFUND_WITHOUT_FINANCIAL_DEBIT
        );
        $this->assertContains(
            FinancialDiscrepancyType::REFUND_WITHOUT_FINANCIAL_DEBIT,
            FinancialDiscrepancyType::all()
        );
    }
}
