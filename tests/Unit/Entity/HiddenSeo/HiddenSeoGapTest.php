<?php

declare(strict_types=1);

namespace Tests\Unit\Entity\HiddenSeo;

use App\Entity\HiddenSeo\HiddenSeoGap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Entity\HiddenSeo\HiddenSeoGap
 */
class HiddenSeoGapTest extends TestCase
{
    public function testNeedsFlagsAndEmptyStringCountsAsGap(): void
    {
        $gap = new HiddenSeoGap('MLB1', 1336, 'Fan 125', '', '  ', []);
        $this->assertTrue($gap->needsMpn());
        $this->assertTrue($gap->needsLine());
        $this->assertTrue($gap->hasAnyGap());
    }

    public function testFilledHasNoGap(): void
    {
        $gap = new HiddenSeoGap('MLB1', 1336, 'Fan 125', 'PF-1', 'Fan', []);
        $this->assertFalse($gap->needsMpn());
        $this->assertFalse($gap->needsLine());
        $this->assertFalse($gap->hasAnyGap());
    }
}
