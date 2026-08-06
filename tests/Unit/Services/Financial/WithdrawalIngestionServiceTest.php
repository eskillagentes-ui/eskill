<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\FinancialLedgerService;
use App\Services\Financial\WithdrawalIngestionService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\WithdrawalIngestionService
 */
final class WithdrawalIngestionServiceTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    private function ledgerWithPeriodRows(array $rows): FinancialLedgerService
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($rows);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        return new FinancialLedgerService($db);
    }

    public function testReconcileCashComputesReleasedNotWithdrawn(): void
    {
        $ledger = $this->ledgerWithPeriodRows([
            [
                'entry_type' => 'settlement_release',
                'entry_category' => 'settlement',
                'status' => 'posted',
                'signed_amount' => 1000.0,
            ],
            [
                'entry_type' => 'settlement_release',
                'entry_category' => 'settlement',
                'status' => 'pending',
                'signed_amount' => 50.0,
            ],
            [
                'entry_type' => 'withdrawal',
                'entry_category' => 'withdrawal',
                'status' => 'posted',
                'signed_amount' => -400.0,
            ],
            [
                'entry_type' => 'program_hold',
                'entry_category' => 'hold',
                'status' => 'posted',
                'signed_amount' => -10.0,
            ],
        ]);

        $svc = new WithdrawalIngestionService(5, $this->createMock(PDO::class), null, $ledger);
        $cash = $svc->reconcileCash('2026-08-01', '2026-08-31');

        $this->assertSame(1000.0, $cash['released_amount']);
        $this->assertSame(400.0, $cash['withdrawn_amount']);
        $this->assertSame(600.0, $cash['released_not_withdrawn']);
        $this->assertNull($cash['note']);
    }

    public function testReconcileCashNotesWhenNoWithdrawals(): void
    {
        $ledger = $this->ledgerWithPeriodRows([
            [
                'entry_type' => 'settlement_release',
                'entry_category' => 'settlement',
                'status' => 'posted',
                'signed_amount' => 100.0,
            ],
        ]);

        $svc = new WithdrawalIngestionService(5, $this->createMock(PDO::class), null, $ledger);
        $cash = $svc->reconcileCash('2026-08-01', '2026-08-31');

        $this->assertSame(100.0, $cash['released_not_withdrawn']);
        $this->assertNotNull($cash['note']);
        $this->assertStringContainsString('Sem saques', (string) $cash['note']);
    }
}
