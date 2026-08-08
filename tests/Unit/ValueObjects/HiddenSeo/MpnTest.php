<?php

declare(strict_types=1);

namespace Tests\Unit\ValueObjects\HiddenSeo;

use App\ValueObjects\HiddenSeo\Evidence;
use App\ValueObjects\HiddenSeo\Mpn;
use App\ValueObjects\HiddenSeo\SuggestionSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\ValueObjects\HiddenSeo\Mpn
 */
class MpnTest extends TestCase
{
    public function testValidCreationTrims(): void
    {
        $ev = new Evidence(SuggestionSource::TITLE, 't', 90);
        $mpn = new Mpn('  ABC-123  ', $ev);
        $this->assertSame('ABC-123', $mpn->value());
        $this->assertTrue($mpn->equals(new Mpn('abc-123', $ev)));
    }

    public function testEmptyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Mpn('  ', new Evidence(SuggestionSource::TITLE, 't', 80));
    }

    public function testTooLongThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Mpn(str_repeat('A1', 31), new Evidence(SuggestionSource::TITLE, 't', 80));
    }
}
