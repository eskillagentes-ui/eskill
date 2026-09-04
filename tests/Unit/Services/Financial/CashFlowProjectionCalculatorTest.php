<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\CashFlowProjectionCalculator;
use App\Services\Financial\FinancialEntryCategory;
use App\Services\Financial\FinancialEntryType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Financial\CashFlowProjectionCalculator */
final class CashFlowProjectionCalculatorTest extends TestCase
{
    private CashFlowProjectionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CashFlowProjectionCalculator();
    }

    public function testBuildAnchorsTodayAndDoesNotDoubleCountPostedSettlementAsPending(): void
    {
        $entries = [
            $this->settlement('pay-1', 'posted', 100.00, '2026-09-02', null, '2026-09-04 09:00:00'),
            // Snapshot antigo da mesma venda: o lançamento posted deve vencer na deduplicação.
            $this->settlement('pay-1', 'pending', 100.00, '2026-09-02', '2026-09-08', '2026-09-03 09:00:00'),
            $this->settlement('pay-2', 'pending', 500.00, '2026-09-03', '2026-09-10', '2026-09-04 09:00:00'),
            [
                'source_system' => 'mp',
                'source_type' => 'movement',
                'source_id' => 'withdraw-1',
                'source_detail_id' => '',
                'entry_type' => FinancialEntryType::WITHDRAWAL,
                'entry_category' => FinancialEntryCategory::WITHDRAWAL,
                'amount' => 50.00,
                'signed_amount' => -50.00,
                'currency' => 'BRL',
                'occurred_at' => '2026-09-03 10:00:00',
                'status' => 'posted',
                'description' => 'Saque',
                'imported_at' => '2026-09-04 09:10:00',
            ],
        ];
        $billing = [
            [
                'group' => 'ML',
                'key' => '2026-10-01',
                'unpaid_amount' => 200.00,
                'debt_expiration_date' => '2026-10-15',
                'currency_id' => 'BRL',
                'period_status' => 'open',
            ],
            // Mesmo documento repetido pela origem não pode duplicar a dívida.
            [
                'group' => 'ML',
                'key' => '2026-10-01',
                'unpaid_amount' => 200.00,
                'debt_expiration_date' => '2026-10-15',
                'currency_id' => 'BRL',
                'period_status' => 'open',
            ],
        ];

        $result = $this->calculator->build(
            1335,
            '2026-09-01',
            '2026-11-30',
            new DateTimeImmutable('2026-09-04 12:00:00', new DateTimeZone('America/Sao_Paulo')),
            [
                'available_balance' => 1000.00,
                'unavailable_balance' => 700.00,
                'total_amount' => 1700.00,
                'currency_id' => 'BRL',
                'source' => 'mp_account_balance',
            ],
            $entries,
            $billing
        );

        $this->assertSame(1000.00, $result['summary']['available']);
        $this->assertSame(['1335'], $result['store_ids']);
        $this->assertCount(4, $result['buckets']);

        $actual = $result['buckets'][0];
        $this->assertSame('actual', $actual['kind']);
        $this->assertSame(100.00, $actual['released']);
        $this->assertSame(0.00, $actual['scheduled_release']);
        $this->assertSame(50.00, $actual['payouts']);
        $this->assertSame(950.00, $actual['opening_balance']);
        $this->assertSame(1000.00, $actual['closing_balance']);
        $this->assertSame('observed', $actual['closing_balance_kind']);
        $this->assertSame(1335, $actual['details']['released'][0]['account_id']);

        $september = $result['buckets'][1];
        $this->assertSame(500.00, $september['scheduled_release']);
        $this->assertNull($september['payouts']);
        $this->assertSame(1500.00, $september['closing_balance']);
        $this->assertSame('partial', $september['completeness']);

        $october = $result['buckets'][2];
        $this->assertSame(200.00, $october['billing_debt']);
        $this->assertSame(1300.00, $october['closing_balance']);
        $this->assertContains('payouts', $october['unknown_fields']);

        $this->assertSame(1300.00, $result['buckets'][3]['closing_balance']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testPendingWithoutFutureDateIsNotFabricatedIntoAVisibleBucket(): void
    {
        $result = $this->calculator->build(
            1335,
            '2026-09-01',
            '2026-09-30',
            new DateTimeImmutable('2026-09-04 12:00:00', new DateTimeZone('America/Sao_Paulo')),
            ['available_balance' => 20.00, 'currency_id' => 'BRL'],
            [$this->settlement('pay-undated', 'pending', 25.15, '2026-09-03', null, '2026-09-04 09:00:00')]
        );

        $this->assertSame(25.15, $result['unplaced_pending_release']);
        $this->assertSame(0.00, $result['buckets'][1]['scheduled_release']);
        $this->assertStringContainsString('não têm uma data futura confiável', implode(' ', $result['warnings']));
    }

    public function testCalculatorUsesCentsForDecimalAggregation(): void
    {
        $result = $this->calculator->build(
            1335,
            '2026-09-01',
            '2026-09-30',
            new DateTimeImmutable('2026-09-04 12:00:00', new DateTimeZone('America/Sao_Paulo')),
            ['available_balance' => 0.00, 'currency_id' => 'BRL'],
            [
                $this->settlement('pay-a', 'pending', 0.10, '2026-09-03', '2026-09-10', '2026-09-04 09:00:00'),
                $this->settlement('pay-b', 'pending', 0.20, '2026-09-03', '2026-09-10', '2026-09-04 09:00:00'),
            ]
        );

        $this->assertSame(0.30, $result['buckets'][1]['scheduled_release']);
        $this->assertSame(0.30, $result['buckets'][1]['closing_balance']);
    }

    public function testIntervalMustIncludeAsOfDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('deve incluir a data de hoje');

        $this->calculator->build(
            1335,
            '2026-08-01',
            '2026-08-31',
            new DateTimeImmutable('2026-09-04 12:00:00', new DateTimeZone('America/Sao_Paulo')),
            ['available_balance' => 10.00, 'currency_id' => 'BRL'],
            []
        );
    }

    /** @return array<string, mixed> */
    private function settlement(
        string $paymentId,
        string $status,
        float $amount,
        string $occurredAt,
        ?string $availableAt,
        string $updatedAt
    ): array {
        return [
            'source_system' => 'mp',
            'source_type' => 'settlement',
            'source_id' => $paymentId,
            'source_detail_id' => '',
            'payment_id' => $paymentId,
            'order_id' => 'order-' . $paymentId,
            'entry_type' => FinancialEntryType::SETTLEMENT_RELEASE,
            'entry_category' => FinancialEntryCategory::SETTLEMENT,
            'amount' => $amount,
            'signed_amount' => $amount,
            'currency' => 'BRL',
            'occurred_at' => $occurredAt . ' 10:00:00',
            'released_at' => $status === 'posted' ? $occurredAt . ' 10:00:00' : null,
            'available_at' => $availableAt === null ? null : $availableAt . ' 10:00:00',
            'status' => $status,
            'description' => 'Liberação MP',
            'imported_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ];
    }
}
