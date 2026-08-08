<?php

declare(strict_types=1);

namespace App\Entity\HiddenSeo;

/**
 * Gap Hidden SEO de um item ML.
 */
final class HiddenSeoGap
{
    private string $mlItemId;
    private int $sellerId;
    private string $title;
    private ?string $currentMpn;
    private ?string $currentLine;
    /** @var array<string, mixed> */
    private array $rawAttributes;

    /**
     * @param array<string, mixed> $rawAttributes
     */
    public function __construct(
        string $mlItemId,
        int $sellerId,
        string $title,
        ?string $currentMpn,
        ?string $currentLine,
        array $rawAttributes = []
    ) {
        $this->mlItemId = trim($mlItemId);
        $this->sellerId = $sellerId;
        $this->title = $title;
        $this->currentMpn = $this->normalizeOptional($currentMpn);
        $this->currentLine = $this->normalizeOptional($currentLine);
        $this->rawAttributes = $rawAttributes;
    }

    public function mlItemId(): string
    {
        return $this->mlItemId;
    }

    public function sellerId(): int
    {
        return $this->sellerId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function currentMpn(): ?string
    {
        return $this->currentMpn;
    }

    public function currentLine(): ?string
    {
        return $this->currentLine;
    }

    /**
     * @return array<string, mixed>
     */
    public function rawAttributes(): array
    {
        return $this->rawAttributes;
    }

    public function needsMpn(): bool
    {
        return $this->currentMpn === null;
    }

    public function needsLine(): bool
    {
        return $this->currentLine === null;
    }

    public function hasAnyGap(): bool
    {
        return $this->needsMpn() || $this->needsLine();
    }

    private function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
