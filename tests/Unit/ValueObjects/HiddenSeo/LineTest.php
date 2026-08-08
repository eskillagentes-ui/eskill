<?php

declare(strict_types=1);

namespace Tests\Unit\ValueObjects\HiddenSeo;

use App\ValueObjects\HiddenSeo\Evidence;
use App\ValueObjects\HiddenSeo\Line;
use App\ValueObjects\HiddenSeo\SuggestionSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\ValueObjects\HiddenSeo\Line
 */
class LineTest extends TestCase
{
    public function testValidTwoWords(): void
    {
        $line = new Line('Fan 125', new Evidence(SuggestionSource::TITLE, 't', 88));
        $this->assertSame('Fan 125', $line->value());
    }

    public function testRejectsLongPhrase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Line(
            'Pastilha Freio Dianteira Fan 125 Honda Barato',
            new Evidence(SuggestionSource::TITLE, 't', 70)
        );
    }

    public function testEmptyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Line('', new Evidence(SuggestionSource::FICHA_TECNICA, 'x', 90));
    }

    public function testAcceptsFourWordsMax(): void
    {
        $line = new Line('CG Titan Fan Start', new Evidence(SuggestionSource::TITLE, 't', 70));
        $this->assertSame('CG Titan Fan Start', $line->value());
    }
}
