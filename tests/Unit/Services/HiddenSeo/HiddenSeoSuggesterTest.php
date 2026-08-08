<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HiddenSeo;

use App\Entity\HiddenSeo\HiddenSeoGap;
use App\Exception\UnsafeOperationException;
use App\Services\HiddenSeo\HiddenSeoSuggester;
use App\Services\HiddenSeo\SafetyGuard;
use App\Services\TechSheetService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\HiddenSeo\HiddenSeoSuggester
 */
class HiddenSeoSuggesterTest extends TestCase
{
    public function testPreviewFromFichaGeneratesSuggestions(): void
    {
        $suggester = new HiddenSeoSuggester(1336, new SafetyGuard(true, [1335], 500));
        $gap = new HiddenSeoGap('MLB1', 1336, 'Item', null, null);
        $list = $suggester->previewFromFicha($gap, [
            'line' => 'Biz',
            'mpn' => 'BZ-125-01',
        ]);
        $this->assertCount(2, $list);
        $pending = $suggester->suggestionToPending($list[0]);
        $this->assertTrue($pending->isPending());
        $this->assertSame(1336, $pending->accountId());
    }

    public function testSkipNoGaps(): void
    {
        $suggester = new HiddenSeoSuggester(1336, new SafetyGuard(true, [1335], 500));
        $gap = new HiddenSeoGap('MLB1', 1336, 'Item', 'A-1', 'Fan');
        $this->assertSame([], $suggester->previewFromFicha($gap, ['line' => 'X', 'mpn' => 'Y-1']));
    }

    public function testDryRunApplyNoApi(): void
    {
        $suggester = new HiddenSeoSuggester(1336, new SafetyGuard(true, [1335], 500));
        $result = $suggester->applyPending(['MLB1'], true, false);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, $result['applied']);
    }

    public function testApplyBlockedFor1335(): void
    {
        $suggester = new HiddenSeoSuggester(1335, new SafetyGuard(true, [1335], 500));
        $this->expectException(UnsafeOperationException::class);
        $suggester->applyPending(['MLB1'], false, true);
    }
}
