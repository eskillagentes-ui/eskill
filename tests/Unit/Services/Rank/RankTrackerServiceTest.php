<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rank;

use App\Services\Rank\RankTrackerService;
use PDO;
use PHPUnit\Framework\TestCase;

final class RankTrackerServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE ml_accounts (id INT PRIMARY KEY, ml_user_id TEXT)'
        );
        $this->pdo->exec("INSERT INTO ml_accounts (id, ml_user_id) VALUES (1, '999')");
        $this->pdo->exec(
            "CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INT,
                ml_item_id TEXT,
                title TEXT,
                status TEXT,
                sold_quantity INT DEFAULT 0
            )"
        );
        $this->pdo->exec(
            "INSERT INTO items (account_id, ml_item_id, title, status, sold_quantity)
             VALUES (1, 'MLB111', 'Guidon Alto Protetor Motos Honda CG', 'active', 10)"
        );
        putenv('RANK_TRACKER_ENABLED=true');
        $_ENV['RANK_TRACKER_ENABLED'] = 'true';
        putenv('RANK_TRACKER_MAX_REQ_PER_MIN=60');
        $_ENV['RANK_TRACKER_MAX_REQ_PER_MIN'] = '60';
        putenv('RANK_TRACKER_CIRCUIT_THRESHOLD=3');
        $_ENV['RANK_TRACKER_CIRCUIT_THRESHOLD'] = '3';
    }

    public function testDeriveKeywordsFromTitle(): void
    {
        $svc = new RankTrackerService($this->pdo, static fn () => []);
        $kws = $svc->deriveKeywords('Guidon Alto Protetor Motos Honda CG 150', 3);
        self::assertNotEmpty($kws);
        self::assertLessThanOrEqual(3, count($kws));
        self::assertStringContainsString('guidon', $kws[0]);
    }

    public function testCollectCapturesPositionViaSearchFn(): void
    {
        $calls = 0;
        $searchFn = function (string $site, string $q, int $limit, int $offset) use (&$calls): array {
            $calls++;
            return [
                'status' => 200,
                'paging' => ['total' => 100],
                'results' => [
                    ['id' => 'MLB000'],
                    ['id' => 'MLB111', 'seller' => ['id' => '999']],
                ],
            ];
        };
        $svc = new RankTrackerService($this->pdo, $searchFn);
        $r = $svc->collect(1);
        self::assertGreaterThan(0, $r['captured']);
        self::assertGreaterThan(0, $calls);
        $latest = $svc->latestCaptures(1, 5);
        self::assertNotEmpty($latest);
        self::assertSame(2, (int) $latest[0]['position']);
    }

    public function testCacheSkipsSameKeywordSameDay(): void
    {
        $calls = 0;
        $searchFn = function () use (&$calls): array {
            $calls++;
            return [
                'status' => 200,
                'paging' => ['total' => 10],
                'results' => [['id' => 'MLB111']],
            ];
        };
        $svc = new RankTrackerService($this->pdo, $searchFn);
        $svc->collect(1);
        $firstCalls = $calls;
        self::assertGreaterThan(0, $firstCalls);
        $r2 = $svc->collect(1);
        self::assertSame($firstCalls, $calls);
        self::assertGreaterThan(0, $r2['cached_skips']);
    }

    public function testCircuitOpensAfterThreeForbidden(): void
    {
        $searchFn = static fn (): array => ['status' => 403, 'error' => 'forbidden'];
        $svc = new RankTrackerService($this->pdo, $searchFn);
        $r = $svc->collect(1);
        self::assertSame('open', $r['circuit']['state'] ?? '');
        self::assertTrue($svc->isCircuitOpen(1));
        $status = $svc->statusForPregao(1);
        self::assertFalse($status['available']);
        self::assertSame('circuit_open', $status['reason']);
    }
}
