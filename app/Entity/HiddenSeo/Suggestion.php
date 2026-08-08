<?php

declare(strict_types=1);

namespace App\Entity\HiddenSeo;

use App\ValueObjects\HiddenSeo\Evidence;
use App\ValueObjects\HiddenSeo\Line;
use App\ValueObjects\HiddenSeo\Mpn;
use App\ValueObjects\HiddenSeo\SuggestionSource;

/**
 * Sugestão imutável de Hidden SEO (MPN e/ou LINE).
 */
final class Suggestion
{
    private string $mlItemId;
    private string $attributeId;
    private ?Mpn $mpn;
    private ?Line $line;
    private Evidence $evidence;
    private string $source;
    private ?string $oldValue;

    public function __construct(
        string $mlItemId,
        string $attributeId,
        Evidence $evidence,
        string $source,
        ?Mpn $mpn = null,
        ?Line $line = null,
        ?string $oldValue = null
    ) {
        if ($mpn === null && $line === null) {
            throw new \InvalidArgumentException('Suggestion exige Mpn ou Line');
        }
        if (!SuggestionSource::isValid($source) && $source !== SuggestionSource::HIDDEN_SEO) {
            // HIDDEN_SEO e aliases de evidence_source são aceitos
        }
        $this->mlItemId = $mlItemId;
        $this->attributeId = $attributeId;
        $this->mpn = $mpn;
        $this->line = $line;
        $this->evidence = $evidence;
        $this->source = $source;
        $this->oldValue = $oldValue;
    }

    public static function forMpn(string $mlItemId, Mpn $mpn, string $source, ?string $oldValue = null): self
    {
        return new self($mlItemId, 'MPN', $mpn->evidence(), $source, $mpn, null, $oldValue);
    }

    public static function forLine(string $mlItemId, Line $line, string $source, ?string $oldValue = null): self
    {
        return new self($mlItemId, 'LINE', $line->evidence(), $source, null, $line, $oldValue);
    }

    public function mlItemId(): string
    {
        return $this->mlItemId;
    }

    public function attributeId(): string
    {
        return $this->attributeId;
    }

    public function mpn(): ?Mpn
    {
        return $this->mpn;
    }

    public function line(): ?Line
    {
        return $this->line;
    }

    public function evidence(): Evidence
    {
        return $this->evidence;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function oldValue(): ?string
    {
        return $this->oldValue;
    }

    public function newValue(): string
    {
        if ($this->mpn !== null) {
            return $this->mpn->value();
        }
        /** @var Line $line */
        $line = $this->line;
        return $line->value();
    }
}
