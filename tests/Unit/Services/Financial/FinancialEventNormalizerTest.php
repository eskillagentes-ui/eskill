<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\FinancialEntryType;
use App\Services\Financial\FinancialEventNormalizer;
use App\Services\Financial\NormalizedFinancialEntry;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\FinancialEventNormalizer
 * @covers \App\Services\Financial\NormalizedFinancialEntry
 */
final class FinancialEventNormalizerTest extends TestCase
{
    public function testFromOrderExtractsRevenueFeeAndIgnoresBuyerShipping(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $order = [
            'id' => '2000017603192568',
            'status' => 'paid',
            'currency_id' => 'BRL',
            'date_created' => '2026-07-01T12:00:00.000-03:00',
            'total_amount' => 149.93,
            'shipping' => ['id' => 47612581285, 'cost' => 0],
            'order_items' => [
                [
                    'item' => ['id' => 'MLB6526443430'],
                    'sale_fee' => 17.99,
                    'unit_price' => 149.93,
                    'quantity' => 1,
                ],
            ],
            'payments' => [
                [
                    'id' => '123',
                    'status' => 'approved',
                    'fee_details' => [],
                    'marketplace_fee' => 17.99,
                ],
            ],
        ];

        $entries = $normalizer->fromOrder(1335, $order);
        $types = array_map(static fn(NormalizedFinancialEntry $e) => $e->entryType, $entries);

        $this->assertContains(FinancialEntryType::SALE_REVENUE, $types);
        $this->assertContains(FinancialEntryType::SALE_FEE, $types);
        $this->assertNotContains(FinancialEntryType::SHIPPING_COST, $types);

        $revenue = $this->firstOfType($entries, FinancialEntryType::SALE_REVENUE);
        $this->assertSame(149.93, $revenue->amount);
        $this->assertSame('credit', $revenue->direction);
        $this->assertSame(149.93, $revenue->signedAmount);

        $fee = $this->firstOfType($entries, FinancialEntryType::SALE_FEE);
        $this->assertSame(17.99, $fee->amount);
        $this->assertSame('debit', $fee->direction);
        $this->assertSame(-17.99, $fee->signedAmount);
    }

    public function testFromShipmentCostsCreatesSellerShippingDebit(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entries = $normalizer->fromShipmentCosts(
            1335,
            '47612581285',
            ['senders' => [['user_id' => 3058804121, 'cost' => 22.65]]],
            '2000017603192568',
            new DateTimeImmutable('2026-07-01 12:00:00')
        );

        $this->assertCount(1, $entries);
        $this->assertSame(FinancialEntryType::SHIPPING_COST, $entries[0]->entryType);
        $this->assertSame(22.65, $entries[0]->amount);
        $this->assertSame(-22.65, $entries[0]->signedAmount);
        $this->assertSame('seller', $entries[0]->sourceDetailId);
    }

    public function testCoveredRefundDoesNotUsePostedStatus(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entries = $normalizer->fromPaymentRefunds(
            1335,
            ['id' => 'pay1', 'currency_id' => 'BRL', 'order_id' => 'ord1'],
            [['id' => 'ref1', 'amount' => 149.93, 'status' => 'approved', 'date_created' => '2026-07-02T10:00:00Z']],
            'ord1',
            sellerDebited: false
        );

        $this->assertCount(1, $entries);
        $this->assertSame('covered', $entries[0]->status);
        $this->assertSame(FinancialEntryType::REFUND, $entries[0]->entryType);
    }

    public function testPayloadHashStableForIdempotency(): void
    {
        $a = NormalizedFinancialEntry::fromType(
            accountId: 1335,
            sourceSystem: 'ml',
            sourceType: 'order',
            sourceId: '1',
            entryType: FinancialEntryType::SALE_REVENUE,
            amount: 10.0,
            occurredAt: new DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $b = NormalizedFinancialEntry::fromType(
            accountId: 1335,
            sourceSystem: 'ml',
            sourceType: 'order',
            sourceId: '1',
            entryType: FinancialEntryType::SALE_REVENUE,
            amount: 10.0,
            occurredAt: new DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $this->assertSame($a->payloadHash(), $b->payloadHash());
    }

    public function testFromReleaseMarksPostedWhenStatusReleased(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromRelease(
            1335,
            'ord1',
            'pay1',
            [
                'net_received_amount' => 120.61,
                'money_release_date' => '2026-09-02T10:08:12.000-04:00',
                'money_release_status' => 'released',
                'currency_id' => 'BRL',
            ],
            new DateTimeImmutable('2026-08-05 10:08:11')
        );

        $this->assertNotNull($entry);
        $this->assertSame(FinancialEntryType::SETTLEMENT_RELEASE, $entry->entryType);
        $this->assertSame('posted', $entry->status);
        $this->assertSame('credit', $entry->direction);
        $this->assertSame(120.61, $entry->amount);
        $this->assertSame(120.61, $entry->signedAmount);
        $this->assertNotNull($entry->releasedAt);
        $this->assertSame('2026-09-02', $entry->releasedAt->format('Y-m-d'));
    }

    public function testFromReleaseStaysPendingWithoutReleasedAtWhenNotYetReleased(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromRelease(
            1335,
            'ord1',
            'pay1',
            [
                'net_received_amount' => 104.30,
                // money_release_date aqui é só uma PROJEÇÃO — não deve virar released_at.
                'money_release_date' => '2026-09-01T20:18:13.000-04:00',
                'money_release_status' => 'pending',
                'currency_id' => 'BRL',
            ],
            new DateTimeImmutable('2026-08-04 20:18:10')
        );

        $this->assertNotNull($entry);
        $this->assertSame('pending', $entry->status);
        $this->assertNull($entry->releasedAt, 'released_at não deve ser inferido a partir de data projetada');
    }

    public function testFromReleaseWithUnknownStatusStaysPendingNotFabricated(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromRelease(
            1335,
            'ord1',
            'pay1',
            [
                'net_received_amount' => 30.23,
                'money_release_date' => '2026-07-12T20:22:36.000-04:00',
                'money_release_status' => null,
                'currency_id' => 'BRL',
            ],
            new DateTimeImmutable('2026-07-01 10:44:39')
        );

        $this->assertNotNull($entry);
        $this->assertSame('pending', $entry->status);
        $this->assertNull($entry->releasedAt);
    }

    public function testFromReleaseReturnsNullWithoutFabricatingZeroAmount(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromRelease(
            1335,
            'ord1',
            'pay1',
            ['net_received_amount' => 0, 'money_release_status' => 'released'],
            new DateTimeImmutable('2026-08-01 00:00:00')
        );

        $this->assertNull($entry);
    }

    public function testFromChargebackCreatesDebitEntryWhenStatusChargedBack(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromChargeback(
            1335,
            'ord1',
            'pay1',
            [
                'status' => 'charged_back',
                'transaction_amount' => 149.93,
                'currency_id' => 'BRL',
                'date_last_updated' => '2026-08-10T09:00:00.000-03:00',
            ],
            new DateTimeImmutable('2026-08-10 09:00:00')
        );

        $this->assertNotNull($entry);
        $this->assertSame(FinancialEntryType::CHARGEBACK, $entry->entryType);
        $this->assertSame('debit', $entry->direction);
        $this->assertSame(149.93, $entry->amount);
        $this->assertSame(-149.93, $entry->signedAmount);
        $this->assertSame('posted', $entry->status);
        $this->assertSame('pay1', $entry->paymentId);
        $this->assertSame('ord1', $entry->orderId);
    }

    public function testFromChargebackReturnsNullWhenStatusIsNotChargedBack(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromChargeback(
            1335,
            'ord1',
            'pay1',
            ['status' => 'approved', 'transaction_amount' => 149.93],
            new DateTimeImmutable('2026-08-10 09:00:00')
        );

        $this->assertNull($entry, 'não deve fabricar chargeback quando status não confirma');
    }

    public function testFromChargebackReturnsNullWithoutFabricatingZeroAmount(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromChargeback(
            1335,
            'ord1',
            'pay1',
            ['status' => 'charged_back', 'transaction_amount' => 0],
            new DateTimeImmutable('2026-08-10 09:00:00')
        );

        $this->assertNull($entry);
    }

    public function testFromChargebackReturnsNullWithoutPaymentId(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromChargeback(
            1335,
            'ord1',
            '',
            ['status' => 'charged_back', 'transaction_amount' => 149.93],
            new DateTimeImmutable('2026-08-10 09:00:00')
        );

        $this->assertNull($entry);
    }

    public function testFromBillingChargeCreatesAdvertisingFeeForPadsWithoutOrder(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromBillingCharge(1335, [
            'detail_id' => 66125755610,
            'detail_type' => 'CHARGE',
            'detail_sub_type' => 'PADS',
            'detail_amount' => 50.0,
            'order_id' => null,
            'creation_date_time' => '2026-06-30T05:50:54',
            'transaction_detail' => 'Tarifa por campanha de publicidade de Product Ads',
        ]);

        $this->assertNotNull($entry);
        $this->assertSame(FinancialEntryType::ADVERTISING_FEE, $entry->entryType);
        $this->assertSame('debit', $entry->direction);
        $this->assertSame(50.0, $entry->amount);
        $this->assertSame(-50.0, $entry->signedAmount);
        $this->assertNull($entry->orderId);
    }

    public function testFromBillingChargeCreatesReversalForBpad(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromBillingCharge(1335, [
            'detail_id' => 77,
            'detail_sub_type' => 'BPAD',
            'detail_amount' => 22.0,
            'order_id' => null,
            'creation_date_time' => '2026-07-01T10:00:00',
        ]);

        $this->assertNotNull($entry);
        $this->assertSame(FinancialEntryType::ADVERTISING_FEE_REVERSAL, $entry->entryType);
        $this->assertSame('credit', $entry->direction);
        $this->assertSame(22.0, $entry->signedAmount);
    }

    public function testFromBillingChargeReturnsNullWhenOrderIdPresent(): void
    {
        // CVVML/CVVPRC etc. já estão em sale_fee do pedido — não deve normalizar aqui.
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromBillingCharge(1335, [
            'detail_id' => 1,
            'detail_sub_type' => 'CVVML',
            'detail_amount' => 25.33,
            'order_id' => 2000017183519262,
        ]);

        $this->assertNull($entry, 'linha com order_id não deve gerar lançamento (já capturada via sale_fee)');
    }

    public function testFromBillingChargeReturnsNullForUnmappedSubType(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromBillingCharge(1335, [
            'detail_id' => 1,
            'detail_sub_type' => 'ZZZZ_UNKNOWN',
            'detail_amount' => 250.0,
            'order_id' => null,
        ]);

        $this->assertNull($entry, 'sub_type não mapeado não deve fabricar categoria/direção');
    }

    public function testFromBillingChargeMapsCstpAsProgramHold(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromBillingCharge(1335, [
            'detail_id' => 99,
            'detail_sub_type' => 'CSTP',
            'detail_amount' => 250.0,
            'order_id' => null,
            'creation_date_time' => '2026-07-15T10:00:00',
            'transaction_detail' => 'Taxa debitada do dinheiro como garantia do Programa decola',
        ]);

        $this->assertNotNull($entry);
        $this->assertSame(FinancialEntryType::PROGRAM_HOLD, $entry->entryType);
        $this->assertSame('debit', $entry->direction);
        $this->assertSame(250.0, $entry->amount);
    }

    public function testFromWithdrawalCreatesDebitCashEntry(): void
    {
        $normalizer = new FinancialEventNormalizer();
        $entry = $normalizer->fromWithdrawal(
            1335,
            [
                'id' => 'wd-1',
                'amount' => 500.0,
                'status' => 'approved',
                'type' => 'withdrawal',
                'currency_id' => 'BRL',
                'date_created' => '2026-08-01T12:00:00Z',
            ],
            new DateTimeImmutable('2026-08-01 12:00:00')
        );

        $this->assertNotNull($entry);
        $this->assertSame(FinancialEntryType::WITHDRAWAL, $entry->entryType);
        $this->assertSame('debit', $entry->direction);
        $this->assertSame(-500.0, $entry->signedAmount);
        $this->assertSame('posted', $entry->status);
    }

    public function testPayloadHashDiffersAcrossAccountsForSameSource(): void
    {
        $at = new DateTimeImmutable('2026-08-01 12:00:00');
        $a = NormalizedFinancialEntry::fromType(
            1335,
            'mercadopago',
            'payment',
            'pay-1',
            FinancialEntryType::SALE_REVENUE,
            10.0,
            $at
        );
        $b = NormalizedFinancialEntry::fromType(
            9999,
            'mercadopago',
            'payment',
            'pay-1',
            FinancialEntryType::SALE_REVENUE,
            10.0,
            $at
        );

        $this->assertNotSame($a->payloadHash(), $b->payloadHash());
        $this->assertSame(1335, $a->accountId);
        $this->assertSame(9999, $b->accountId);
    }

    /**
     * @param list<NormalizedFinancialEntry> $entries
     */
    private function firstOfType(array $entries, string $type): NormalizedFinancialEntry
    {
        foreach ($entries as $entry) {
            if ($entry->entryType === $type) {
                return $entry;
            }
        }
        $this->fail('entry type not found: ' . $type);
    }
}
