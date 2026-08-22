<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HiddenSeo;

use App\Services\HiddenSeo\ItemLocalMetricsMerger;
use App\Services\HiddenSeo\ItemPerformanceService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\HiddenSeo\ItemLocalMetricsMerger
 * @covers \App\Services\HiddenSeo\ItemPerformanceService::parseVisitsWindowPayload
 */
final class ItemLocalMetricsMergerTest extends TestCase
{
    public function testSuccessfulVisitsWriteBothKeysIncludingZero(): void
    {
        $data = ['title' => 'Peça'];
        $out = ItemLocalMetricsMerger::applyVisits30d($data, ['success' => true, 'visits' => 0]);
        self::assertSame(0, $out['visits_30d']);
        self::assertSame(0, $out['_visits_30d']);
        self::assertArrayHasKey('visits_updated_at', $out);

        $out = ItemLocalMetricsMerger::applyVisits30d($data, ['success' => true, 'visits' => 42]);
        self::assertSame(42, $out['visits_30d']);
        self::assertSame(42, $out['_visits_30d']);
    }

    public function testFailedVisitsDoNotWriteZero(): void
    {
        $data = ['title' => 'Peça', 'performance_score' => 80];
        $out = ItemLocalMetricsMerger::applyVisits30d($data, [
            'success' => false,
            'visits' => null,
            'error' => 'forbidden',
            'status' => 403,
        ]);
        self::assertSame($data, $out);
        self::assertArrayNotHasKey('visits_30d', $out);
        self::assertArrayNotHasKey('_visits_30d', $out);

        $existing = ['visits_30d' => 9, '_visits_30d' => 9];
        $kept = ItemLocalMetricsMerger::applyVisits30d($existing, ['success' => false, 'visits' => 0]);
        self::assertSame(9, $kept['visits_30d']);
        self::assertSame(9, $kept['_visits_30d']);
    }

    public function testFailedPerformanceDoesNotClobberScore(): void
    {
        $data = ['performance_score' => 77];
        $out = ItemLocalMetricsMerger::applyPerformance($data, ['success' => false, 'score' => 0]);
        self::assertSame(77, $out['performance_score']);
    }

    public function testNeedsVisitsWhenKeyMissing(): void
    {
        self::assertTrue(ItemLocalMetricsMerger::needsVisits30d([]));
        self::assertTrue(ItemLocalMetricsMerger::needsVisits30d(['performance_score' => 10]));
        self::assertFalse(ItemLocalMetricsMerger::needsVisits30d([
            'visits_30d' => 3,
            'visits_updated_at' => date('Y-m-d H:i:s'),
        ]));
    }

    public function testParseVisitsWindowPrefersTotalVisits(): void
    {
        $ref = new \ReflectionClass(ItemPerformanceService::class);
        /** @var ItemPerformanceService $svc */
        $svc = $ref->newInstanceWithoutConstructor();
        self::assertSame(17, $svc->parseVisitsWindowPayload([
            'total_visits' => 17,
            'results' => [['date' => '2026-08-01', 'total' => 2]],
        ]));
        self::assertSame(5, $svc->parseVisitsWindowPayload([
            'results' => [
                ['date' => '2026-08-01', 'total' => 2],
                ['date' => '2026-08-02', 'total' => 3],
            ],
        ]));
        self::assertSame(0, $svc->parseVisitsWindowPayload(['total_visits' => 0]));
        self::assertNull($svc->parseVisitsWindowPayload(['error' => 'forbidden']));
    }

    public function testWorkerPersistsVisitKeysAndFailSoft403(): void
    {
        $worker = (string) file_get_contents(dirname(__DIR__, 4) . '/bin/item-performance-sync-worker.php');
        self::assertStringContainsString('visits_30d', $worker);
        self::assertStringContainsString('_visits_30d', $worker);
        self::assertStringContainsString('/visits/time_window', $worker);
        self::assertStringContainsString('ItemLocalMetricsMerger', $worker);
        self::assertStringContainsString('getItemVisits30d', $worker);
        self::assertStringContainsString('sem gravar 0', $worker);
        self::assertStringContainsString('staging.eskill.com.br', $worker);
        self::assertStringContainsString('account_id', $worker);
        self::assertStringNotContainsString('apply-recovery', $worker);
    }
}
