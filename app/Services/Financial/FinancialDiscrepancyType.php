<?php

declare(strict_types=1);

namespace App\Services\Financial;

/**
 * Tipos canônicos de divergência financeira.
 */
final class FinancialDiscrepancyType
{
    public const MISSING_SALE_FEE = 'missing_sale_fee';
    public const COMMISSION_MISMATCH = 'commission_mismatch';
    public const MISSING_SHIPPING_COST = 'missing_shipping_cost';
    public const SHIPPING_COST_MISMATCH = 'shipping_cost_mismatch';
    public const REFUND_WITHOUT_FINANCIAL_DEBIT = 'refund_without_financial_debit';
    public const REFUND_DEBITED_TWICE = 'refund_debited_twice';
    public const PROTECTION_CREDIT_MISSING = 'protection_credit_missing';
    public const PAYMENT_WITHOUT_ORDER = 'payment_without_order';
    public const ORDER_WITHOUT_PAYMENT = 'order_without_payment';
    public const RELEASED_AMOUNT_MISMATCH = 'released_amount_mismatch';
    public const SETTLEMENT_NOT_RECONCILED = 'settlement_not_reconciled';
    public const CMV_MISSING = 'cmv_missing';
    public const TAX_RATE_MISSING = 'tax_rate_missing';
    public const DUPLICATED_FINANCIAL_ENTRY = 'duplicated_financial_entry';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MISSING_SALE_FEE,
            self::COMMISSION_MISMATCH,
            self::MISSING_SHIPPING_COST,
            self::SHIPPING_COST_MISMATCH,
            self::REFUND_WITHOUT_FINANCIAL_DEBIT,
            self::REFUND_DEBITED_TWICE,
            self::PROTECTION_CREDIT_MISSING,
            self::PAYMENT_WITHOUT_ORDER,
            self::ORDER_WITHOUT_PAYMENT,
            self::RELEASED_AMOUNT_MISMATCH,
            self::SETTLEMENT_NOT_RECONCILED,
            self::CMV_MISSING,
            self::TAX_RATE_MISSING,
            self::DUPLICATED_FINANCIAL_ENTRY,
        ];
    }
}
