<?php

declare(strict_types=1);

namespace App\Services\Financial;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Formato interno único após normalização de payloads ML/MP/manual.
 */
final readonly class NormalizedFinancialEntry
{
    /**
     * @param array<string, mixed> $rawData
     */
    public function __construct(
        public int $accountId,
        public string $sourceSystem,
        public string $sourceType,
        public string $sourceId,
        public string $sourceDetailId,
        public string $entryType,
        public string $category,
        public string $direction,
        public float $amount,
        public float $signedAmount,
        public DateTimeImmutable $occurredAt,
        public string $status,
        public ?string $orderId = null,
        public ?string $paymentId = null,
        public ?string $shipmentId = null,
        public ?string $claimId = null,
        public ?string $refundId = null,
        public ?string $settlementId = null,
        public ?DateTimeImmutable $releasedAt = null,
        public ?DateTimeImmutable $availableAt = null,
        public ?string $description = null,
        public string $currency = 'BRL',
        public array $rawData = [],
    ) {
        if ($accountId <= 0) {
            throw new InvalidArgumentException('accountId inválido');
        }
        if ($sourceSystem === '' || $sourceType === '' || $sourceId === '') {
            throw new InvalidArgumentException('source_system/type/id obrigatórios');
        }
        if (!FinancialEntryType::isValid($entryType)) {
            throw new InvalidArgumentException('entry_type inválido: ' . $entryType);
        }
        if (!FinancialEntryCategory::isValid($category)) {
            throw new InvalidArgumentException('entry_category inválida: ' . $category);
        }
        if (!in_array($direction, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException('direction deve ser credit|debit');
        }
        if ($amount < 0) {
            throw new InvalidArgumentException('amount deve ser >= 0 (use direction/signed_amount)');
        }
    }

    /**
     * Factory a partir de tipo canônico (preenche category/direction/signedAmount).
     *
     * @param array<string, mixed> $rawData
     */
    public static function fromType(
        int $accountId,
        string $sourceSystem,
        string $sourceType,
        string $sourceId,
        string $entryType,
        float $amount,
        DateTimeImmutable $occurredAt,
        string $sourceDetailId = '',
        ?string $orderId = null,
        ?string $paymentId = null,
        ?string $shipmentId = null,
        ?string $claimId = null,
        ?string $refundId = null,
        ?string $settlementId = null,
        string $status = 'posted',
        ?string $description = null,
        string $currency = 'BRL',
        array $rawData = [],
        ?DateTimeImmutable $releasedAt = null,
        ?DateTimeImmutable $availableAt = null,
        ?string $directionOverride = null,
    ): self {
        $direction = $directionOverride ?? FinancialEntryType::defaultDirection($entryType);
        $abs = round(abs($amount), 2);
        $signed = $direction === 'credit' ? $abs : -1 * $abs;

        return new self(
            accountId: $accountId,
            sourceSystem: $sourceSystem,
            sourceType: $sourceType,
            sourceId: $sourceId,
            sourceDetailId: $sourceDetailId,
            entryType: $entryType,
            category: FinancialEntryType::defaultCategory($entryType),
            direction: $direction,
            amount: $abs,
            signedAmount: $signed,
            occurredAt: $occurredAt,
            status: $status,
            orderId: $orderId,
            paymentId: $paymentId,
            shipmentId: $shipmentId,
            claimId: $claimId,
            refundId: $refundId,
            settlementId: $settlementId,
            releasedAt: $releasedAt,
            availableAt: $availableAt,
            description: $description,
            currency: $currency,
            rawData: $rawData,
        );
    }

    public function payloadHash(): string
    {
        $canonical = [
            'account_id' => $this->accountId,
            'source_system' => $this->sourceSystem,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'source_detail_id' => $this->sourceDetailId,
            'entry_type' => $this->entryType,
            'direction' => $this->direction,
            'amount' => round($this->amount, 2),
            'signed_amount' => round($this->signedAmount, 2),
            'currency' => $this->currency,
            'status' => $this->status,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'order_id' => $this->orderId,
            'payment_id' => $this->paymentId,
            'shipment_id' => $this->shipmentId,
            'claim_id' => $this->claimId,
            'refund_id' => $this->refundId,
            'settlement_id' => $this->settlementId,
            'released_at' => $this->releasedAt?->format('Y-m-d H:i:s'),
            'available_at' => $this->availableAt?->format('Y-m-d H:i:s'),
        ];

        return hash('sha256', (string)json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
