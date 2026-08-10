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

    public function testIngestFromCollectorIsIdempotentPerDay(): void
    {
        putenv('RANK_COLLECTOR_LOCAL=true');
        $_ENV['RANK_COLLECTOR_LOCAL'] = 'true';
        $svc = new RankTrackerService($this->pdo, static fn () => []);
        $payload = [
            'account_id' => 1,
            'mlb_id' => 'MLB111',
            'keyword' => 'guidon alto',
            'position' => 7,
            'page' => 1,
            'page_position' => 7,
            'total_results' => 100,
            'day' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'),
        ];
        $r1 = $svc->ingestFromCollector($payload);
        $r2 = $svc->ingestFromCollector($payload);
        self::assertTrue($r1['ok']);
        self::assertFalse($r1['idempotent']);
        self::assertTrue($r2['ok']);
        self::assertTrue($r2['idempotent']);
        $status = $svc->statusForPregao(1);
        self::assertTrue($status['available']);
        self::assertSame('proxy', $status['position_source']);
    }

    public function testTrendsStatusIsPartialWhenOnlyTrendsCaptures(): void
    {
        $svc = new RankTrackerService($this->pdo, static fn () => []);
        $ref = new \ReflectionClass($svc);
        $persist = $ref->getMethod('persistCapture');
        $persist->setAccessible(true);
        $persist->invoke($svc, 1, 'TREND', 'filtro oleo', [
            'position' => null,
            'page' => null,
            'page_position' => null,
            'total_results' => null,
            'error' => null,
            'position_source' => RankTrackerService::SOURCE_TRENDS,
        ]);
        $status = $svc->statusForPregao(1);
        self::assertTrue($status['available']);
        self::assertTrue($status['partial']);
        self::assertSame('trends', $status['position_source']);
        self::assertStringContainsString('sem posição exata', (string) $status['label']);
    }

    public function testListAssignmentsRespectsMax30(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->pdo->exec(
                "INSERT INTO items (account_id, ml_item_id, title, status, sold_quantity)
                 VALUES (1, 'MLB" . (2000 + $i) . "', 'Peca Teste Numero {$i} Honda Yamaha', 'active', {$i})"
            );
        }
        $svc = new RankTrackerService($this->pdo, static fn () => []);
        $list = $svc->listAssignments(1, 30);
        self::assertLessThanOrEqual(30, count($list));
        self::assertNotEmpty($list);
    }
}
