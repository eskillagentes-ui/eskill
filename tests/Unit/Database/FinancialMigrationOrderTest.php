<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

final class FinancialMigrationOrderTest extends TestCase
{
    public function testReconciliationViewRunsAfterFinancialLedgerTable(): void
    {
        $migrationsDirectory = dirname(__DIR__, 3) . '/database/migrations';
        $migrationNames = array_map('basename', glob($migrationsDirectory . '/*') ?: []);
        sort($migrationNames);

        $ledgerIndex = array_search(
            '2026_08_05_create_financial_ledger_entries.sql',
            $migrationNames,
            true
        );
        $viewIndex = array_search(
            '2026_08_05_zz_create_order_financial_reconciliation_view.sql',
            $migrationNames,
            true
        );

        self::assertIsInt($ledgerIndex);
        self::assertIsInt($viewIndex);
        self::assertLessThan($viewIndex, $ledgerIndex);

        $discrepanciesMigration = file_get_contents(
            $migrationsDirectory . '/2026_08_05_create_financial_discrepancies.sql'
        );
        self::assertIsString($discrepanciesMigration);
        self::assertStringNotContainsString('FROM financial_ledger_entries', $discrepanciesMigration);
    }
}
