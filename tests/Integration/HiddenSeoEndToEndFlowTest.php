<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\HiddenSeo\HiddenSeoGap;
use App\Entity\HiddenSeo\PendingChange;
use App\Services\HiddenSeo\HiddenSeoSuggester;
use App\Services\HiddenSeo\SafetyGuard;
use App\Exception\UnsafeOperationException;
use PHPUnit\Framework\TestCase;

/**
 * Fluxo E2E em memória (sem ML API / sem MySQL obrigatório).
 *
 * @covers \App\Services\HiddenSeo\HiddenSeoSuggester
 */
class HiddenSeoEndToEndFlowTest extends TestCase
{
    public function testGeneratePreviewIdempotentAndApplyBlocked(): void
    {
        $guard = new SafetyGuard(true, [1335], 500);
        $suggester = new HiddenSeoSuggester(1336, $guard);

        $gap = new HiddenSeoGap(
            'MLBSTG1',
            1336,
            'Pastilha Freio Fan 125 MPN: PF-FAN125-01',
            null,
            null
        );

        $first = $suggester->previewFromFicha($gap, ['seller_sku' => 'PF-FAN125-01']);
        $this->assertNotEmpty($first);

        $second = $suggester->previewFromFicha($gap, ['seller_sku' => 'PF-FAN125-01']);
        $this->assertCount(count($first), $second);

        $pending = [];
        foreach ($first as $s) {
            $p = $suggester->suggestionToPending($s);
            $pending[$s->attributeId()] = $p;
            $this->assertTrue($p->isPending());
            $this->assertSame('hidden_seo', $p->source());
        }

        $approved = $pending[array_key_first($pending)]->approve('tester');
        $this->assertSame(PendingChange::STATUS_APPROVED, $approved->status());

        $dry = $suggester->applyPending(['MLBSTG1'], true, false);
        $this->assertTrue($dry['dry_run']);

        $blocked = new HiddenSeoSuggester(1335, $guard);
        $this->expectException(UnsafeOperationException::class);
        $blocked->applyPending(['MLB1335'], false, true);
    }
}
