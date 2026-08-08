<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HiddenSeo;

use App\Exception\UnsafeOperationException;
use App\Services\HiddenSeo\SafetyGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\HiddenSeo\SafetyGuard
 */
class SafetyGuardTest extends TestCase
{
    public function testDryRunAlwaysSafe(): void
    {
        $g = new SafetyGuard(true, [1335], 500);
        $g->assertCanApply(1335, true, false);
        $this->assertTrue(true);
    }

    public function testForbiddenBlocksApply(): void
    {
        $g = new SafetyGuard(true, [1335], 500);
        $this->expectException(UnsafeOperationException::class);
        $g->assertCanApply(1335, false, true);
    }

    public function testAllowedPassesWithAllowApply(): void
    {
        $g = new SafetyGuard(true, [1335], 500);
        $g->assertCanApply(1336, false, true);
        $this->assertFalse($g->isForbidden(1336));
        $this->assertTrue($g->isForbidden(1335));
    }

    public function testSafeModeOffAllowsWithoutFlagWhenNotForbidden(): void
    {
        $g = new SafetyGuard(false, [1335], 500);
        $g->assertCanApply(1336, false, false);
        $this->assertTrue(true);
    }

    public function testClampLimitRespectsMax(): void
    {
        $g = new SafetyGuard(true, [1335], 100);
        $this->assertSame(100, $g->clampLimit(999));
        $this->assertSame(50, $g->clampLimit(50));
    }
}
