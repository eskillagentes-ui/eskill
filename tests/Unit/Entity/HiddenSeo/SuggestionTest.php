<?php

declare(strict_types=1);

namespace Tests\Unit\Entity\HiddenSeo;

use App\Entity\HiddenSeo\Suggestion;
use App\ValueObjects\HiddenSeo\Evidence;
use App\ValueObjects\HiddenSeo\Line;
use App\ValueObjects\HiddenSeo\Mpn;
use App\ValueObjects\HiddenSeo\SuggestionSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Entity\HiddenSeo\Suggestion
 */
class SuggestionTest extends TestCase
{
    public function testMpnSuggestion(): void
    {
        $ev = new Evidence(SuggestionSource::SELLER_SKU, 'SKU=X', 88);
        $s = Suggestion::forMpn('MLB1', new Mpn('ABC-99', $ev), SuggestionSource::SELLER_SKU);
        $this->assertSame('MPN', $s->attributeId());
        $this->assertSame('ABC-99', $s->newValue());
    }

    public function testLineSuggestion(): void
    {
        $ev = new Evidence(SuggestionSource::TITLE, 'fan', 88);
        $s = Suggestion::forLine('MLB1', new Line('Fan', $ev), SuggestionSource::TITLE);
        $this->assertSame('LINE', $s->attributeId());
        $this->assertSame('Fan', $s->newValue());
    }

    public function testEmptySuggestionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Suggestion(
            'MLB1',
            'MPN',
            new Evidence(SuggestionSource::TITLE, 'x', 80),
            SuggestionSource::TITLE,
            null,
            null
        );
    }
}
