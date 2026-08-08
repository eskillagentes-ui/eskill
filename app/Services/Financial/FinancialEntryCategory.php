<?php

declare(strict_types=1);

namespace App\Services\Financial;

/**
 * Categorias amplas do livro financeiro (agregação / conciliação).
 */
final class FinancialEntryCategory
{
    public const REVENUE = 'revenue';
    public const MARKETPLACE_FEE = 'marketplace_fee';
    public const PAYMENT_FEE = 'payment_fee';
    public const SHIPPING = 'shipping';
    public const REFUND = 'refund';
    public const PROTECTION = 'protection';
    public const CLAIM = 'claim';
    public const TAX = 'tax';
    public const SETTLEMENT = 'settlement';
    public const WITHDRAWAL = 'withdrawal';
    public const ADVERTISING = 'advertising';
    public const HOLD = 'hold';
    public const ADJUSTMENT = 'adjustment';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::REVENUE,
            self::MARKETPLACE_FEE,
            self::PAYMENT_FEE,
            self::SHIPPING,
            self::REFUND,
            self::PROTECTION,
            self::CLAIM,
            self::TAX,
            self::SETTLEMENT,
            self::WITHDRAWAL,
            self::ADVERTISING,
            self::HOLD,
            self::ADJUSTMENT,
        ];
    }

    public static function isValid(string $category): bool
    {
        return in_array($category, self::all(), true);
    }
}
