<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SEO;

use App\Services\Rank\RankTrackerService;
use App\Services\SEO\SeoKpiService;
use PDO;
use PHPUnit\Framework\TestCase;

final class SeoKpiServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE items (
                account_id INT, ml_item_id TEXT, visits INT DEFAULT 70, sold_quantity INT DEFAULT 7
            )'
        );
        $this->pdo->exec("INSERT INTO items VALUES (1, 'MLB555', 70, 7)");
    }

    public function testCaptureBaselineAndList(): void
    {
        $ranks = new RankTrackerService($this->pdo, static fn () => []);
        $svc = new SeoKpiService($this->pdo, $ranks);
        $r = $svc->captureBaseline(1, 'MLB555', 'hidden_seo', ['source' => 'test']);
        self::assertTrue($r['success']);
        self::assertSame('baseline_captured', $r['status']);
        self::assertArrayHasKey('visits_per_day', $r['baseline']);
        $list = $svc->listInterventions(1);
        self::assertCount(1, $list);
        self::assertSame('MLB555', $list[0]['mlb_id']);
    }

    public function testMarkApplied(): void
    {
        $ranks = new RankTrackerService($this->pdo, static fn () => []);
        $svc = new SeoKpiService($this->pdo, $ranks);
        $r = $svc->captureBaseline(1, 'MLB555', 'mpn_fill');
        self::assertTrue($svc->markApplied((int) $r['id']));
        $list = $svc->listInterventions(1);
        self::assertSame('applied', $list[0]['status']);
    }
}
