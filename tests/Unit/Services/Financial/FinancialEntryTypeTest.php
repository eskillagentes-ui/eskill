<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\FinancialEntryCategory;
use App\Services\Financial\FinancialEntryType;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\FinancialEntryType
 */
final class FinancialEntryTypeTest extends TestCase
{
    public function testAllReturnsUniqueKnownTypes(): void
    {
        $all = FinancialEntryType::all();

        self::assertNotEmpty($all);
        self::assertSame($all, array_unique($all), 'FinancialEntryType::all() não deve ter duplicados');
        self::assertContains(FinancialEntryType::WITHDRAWAL, $all);
        self::assertContains(FinancialEntryType::WITHDRAWAL_REVERSAL, $all);
        self::assertContains(FinancialEntryType::SETTLEMENT_RELEASE, $all);
    }

    public function testIsValidAcceptsKnownAndRejectsUnknown(): void
    {
        self::assertTrue(FinancialEntryType::isValid(FinancialEntryType::WITHDRAWAL));
        self::assertTrue(FinancialEntryType::isValid(FinancialEntryType::WITHDRAWAL_REVERSAL));
        self::assertFalse(FinancialEntryType::isValid('nao_existe'));
    }

    public function testWithdrawalReversalDefaultsToWithdrawalCategory(): void
    {
        self::assertSame(
            FinancialEntryCategory::WITHDRAWAL,
            FinancialEntryType::defaultCategory(FinancialEntryType::WITHDRAWAL_REVERSAL)
        );
        self::assertSame(
            FinancialEntryCategory::WITHDRAWAL,
            FinancialEntryType::defaultCategory(FinancialEntryType::WITHDRAWAL)
        );
    }

    public function testWithdrawalReversalIsCreditWhileWithdrawalIsDebit(): void
    {
        self::assertSame('credit', FinancialEntryType::defaultDirection(FinancialEntryType::WITHDRAWAL_REVERSAL));
        self::assertSame('debit', FinancialEntryType::defaultDirection(FinancialEntryType::WITHDRAWAL));
    }

    public function testAdvertisingFeeIsDebitAndReversalIsCredit(): void
    {
        self::assertSame(
            FinancialEntryCategory::ADVERTISING,
            FinancialEntryType::defaultCategory(FinancialEntryType::ADVERTISING_FEE)
        );
        self::assertSame(
            FinancialEntryCategory::ADVERTISING,
            FinancialEntryType::defaultCategory(FinancialEntryType::ADVERTISING_FEE_REVERSAL)
        );
        self::assertSame('debit', FinancialEntryType::defaultDirection(FinancialEntryType::ADVERTISING_FEE));
        self::assertSame('credit', FinancialEntryType::defaultDirection(FinancialEntryType::ADVERTISING_FEE_REVERSAL));
    }

    /**
     * Todo tipo precisa resolver para uma categoria válida e conhecida.
     */
    public function testEveryTypeResolvesToValidCategory(): void
    {
        foreach (FinancialEntryType::all() as $type) {
            $category = FinancialEntryType::defaultCategory($type);
            self::assertTrue(
                FinancialEntryCategory::isValid($category),
                "Tipo '{$type}' resolveu para categoria inválida '{$category}'"
            );
        }
    }

    /**
     * Direção precisa ser sempre credit ou debit, sem exceção.
     */
    public function testEveryTypeResolvesToCreditOrDebit(): void
    {
        foreach (FinancialEntryType::all() as $type) {
            $direction = FinancialEntryType::defaultDirection($type);
            self::assertContains($direction, ['credit', 'debit'], "Tipo '{$type}' resolveu direção inválida '{$direction}'");
        }
    }
}
