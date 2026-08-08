<?php

declare(strict_types=1);

namespace App\Entity\HiddenSeo;

/**
 * Mudança pendente (espelha contrato de tech_sheet_suggestions / audit Hidden SEO).
 */
final class PendingChange
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPLIED = 'applied';

    private ?int $id;
    private string $mlItemId;
    private int $accountId;
    private string $attributeId;
    private ?string $oldValue;
    private string $newValue;
    private string $source;
    private string $provenance;
    private int $confidence;
    private string $status;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $reviewedAt;
    private ?string $reviewedBy;

    public function __construct(
        string $mlItemId,
        int $accountId,
        string $attributeId,
        string $newValue,
        string $source,
        string $provenance,
        int $confidence,
        ?string $oldValue = null,
        string $status = self::STATUS_PENDING,
        ?int $id = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $reviewedAt = null,
        ?string $reviewedBy = null
    ) {
        $this->id = $id;
        $this->mlItemId = $mlItemId;
        $this->accountId = $accountId;
        $this->attributeId = $attributeId;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->source = $source;
        $this->provenance = $provenance;
        $this->confidence = $confidence;
        $this->status = $status;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->reviewedAt = $reviewedAt;
        $this->reviewedBy = $reviewedBy;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function approve(?string $reviewedBy = null): self
    {
        return new self(
            $this->mlItemId,
            $this->accountId,
            $this->attributeId,
            $this->newValue,
            $this->source,
            $this->provenance,
            $this->confidence,
            $this->oldValue,
            self::STATUS_APPROVED,
            $this->id,
            $this->createdAt,
            new \DateTimeImmutable(),
            $reviewedBy
        );
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function mlItemId(): string
    {
        return $this->mlItemId;
    }

    public function accountId(): int
    {
        return $this->accountId;
    }

    public function attributeId(): string
    {
        return $this->attributeId;
    }

    public function oldValue(): ?string
    {
        return $this->oldValue;
    }

    public function newValue(): string
    {
        return $this->newValue;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function provenance(): string
    {
        return $this->provenance;
    }

    public function confidence(): int
    {
        return $this->confidence;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function reviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function reviewedBy(): ?string
    {
        return $this->reviewedBy;
    }
}
