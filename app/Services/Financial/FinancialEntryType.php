<?php

declare(strict_types=1);

namespace App\Services\Financial;

/**
 * Tipos canônicos de lançamento do livro financeiro.
 * Não inventar nomes ad hoc em outros serviços — usar estas constantes.
 */
final class FinancialEntryType
{
    public const SALE_REVENUE = 'sale_revenue';
    public const SALE_FEE = 'sale_fee';
    public const PAYMENT_FEE = 'payment_fee';

    public const SHIPPING_COST = 'shipping_cost';
    public const SHIPPING_CREDIT = 'shipping_credit';
    public const SHIPPING_PROTECTION = 'shipping_protection';

    public const REFUND = 'refund';
    public const REFUND_REVERSAL = 'refund_reversal';

    public const CHARGEBACK = 'chargeback';
    public const CHARGEBACK_REVERSAL = 'chargeback_reversal';

    public const CLAIM_ADJUSTMENT = 'claim_adjustment';
    public const COMMERCIAL_DISCOUNT = 'commercial_discount';
    public const TAX_WITHHOLDING = 'tax_withholding';

    public const SETTLEMENT_RELEASE = 'settlement_release';
    public const WITHDRAWAL = 'withdrawal';
    public const WITHDRAWAL_REVERSAL = 'withdrawal_reversal';
    public const ADVERTISING_FEE = 'advertising_fee';
    public const ADVERTISING_FEE_REVERSAL = 'advertising_fee_reversal';
    public const PROGRAM_HOLD = 'program_hold';
    public const PROGRAM_HOLD_RELEASE = 'program_hold_release';
    public const MANUAL_ADJUSTMENT = 'manual_adjustment';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SALE_REVENUE,
            self::SALE_FEE,
            self::PAYMENT_FEE,
            self::SHIPPING_COST,
            self::SHIPPING_CREDIT,
            self::SHIPPING_PROTECTION,
            self::REFUND,
            self::REFUND_REVERSAL,
            self::CHARGEBACK,
            self::CHARGEBACK_REVERSAL,
            self::CLAIM_ADJUSTMENT,
            self::COMMERCIAL_DISCOUNT,
            self::TAX_WITHHOLDING,
            self::SETTLEMENT_RELEASE,
            self::WITHDRAWAL,
            self::WITHDRAWAL_REVERSAL,
            self::ADVERTISING_FEE,
            self::ADVERTISING_FEE_REVERSAL,
            self::PROGRAM_HOLD,
            self::PROGRAM_HOLD_RELEASE,
            self::MANUAL_ADJUSTMENT,
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /**
     * Categoria ampla padrão para o tipo.
     */
    public static function defaultCategory(string $entryType): string
    {
        return match ($entryType) {
            self::SALE_REVENUE => FinancialEntryCategory::REVENUE,
            self::SALE_FEE => FinancialEntryCategory::MARKETPLACE_FEE,
            self::PAYMENT_FEE => FinancialEntryCategory::PAYMENT_FEE,
            self::SHIPPING_COST, self::SHIPPING_CREDIT => FinancialEntryCategory::SHIPPING,
            self::SHIPPING_PROTECTION => FinancialEntryCategory::PROTECTION,
            self::REFUND, self::REFUND_REVERSAL => FinancialEntryCategory::REFUND,
            self::CHARGEBACK, self::CHARGEBACK_REVERSAL => FinancialEntryCategory::REFUND,
            self::CLAIM_ADJUSTMENT => FinancialEntryCategory::CLAIM,
            self::COMMERCIAL_DISCOUNT => FinancialEntryCategory::ADJUSTMENT,
            self::TAX_WITHHOLDING => FinancialEntryCategory::TAX,
            self::SETTLEMENT_RELEASE => FinancialEntryCategory::SETTLEMENT,
            self::WITHDRAWAL, self::WITHDRAWAL_REVERSAL => FinancialEntryCategory::WITHDRAWAL,
            self::ADVERTISING_FEE, self::ADVERTISING_FEE_REVERSAL => FinancialEntryCategory::ADVERTISING,
            self::PROGRAM_HOLD, self::PROGRAM_HOLD_RELEASE => FinancialEntryCategory::HOLD,
            self::MANUAL_ADJUSTMENT => FinancialEntryCategory::ADJUSTMENT,
            default => FinancialEntryCategory::ADJUSTMENT,
        };
    }

    /**
     * Direção padrão: credit aumenta resultado do seller; debit reduz.
     */
    public static function defaultDirection(string $entryType): string
    {
        return match ($entryType) {
            self::SALE_REVENUE,
            self::SHIPPING_CREDIT,
            self::SHIPPING_PROTECTION,
            self::REFUND_REVERSAL,
            self::CHARGEBACK_REVERSAL,
            self::SETTLEMENT_RELEASE,
            self::WITHDRAWAL_REVERSAL,
            self::ADVERTISING_FEE_REVERSAL,
            self::PROGRAM_HOLD_RELEASE => 'credit',
            default => 'debit',
        };
    }
}
