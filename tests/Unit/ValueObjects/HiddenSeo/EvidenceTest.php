<?php

declare(strict_types=1);

namespace Tests\Unit\ValueObjects\HiddenSeo;

use App\ValueObjects\HiddenSeo\Evidence;
use App\ValueObjects\HiddenSeo\SuggestionSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\ValueObjects\HiddenSeo\Evidence
 */
class EvidenceTest extends TestCase
{
    public function testHighAndMediumConfidence(): void
    {
        $high = new Evidence(SuggestionSource::FICHA_TECNICA, 'ficha', 90);
        $this->assertTrue($high->isHighConfidence());
        $this->assertFalse($high->isMediumConfidence());

        $mid = new Evidence(SuggestionSource::TITLE, 'title', 70);
        $this->assertFalse($mid->isHighConfidence());
        $this->assertTrue($mid->isMediumConfidence());
    }

    public function testInvalidConfidenceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Evidence(SuggestionSource::TITLE, 'x', 101);
    }
}
