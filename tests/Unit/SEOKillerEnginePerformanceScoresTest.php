<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * SEO Killer health/quality + "sem venda" use local official scores and orders.
 */
final class SEOKillerEnginePerformanceScoresTest extends TestCase
{
    private function invokePrivateMethod(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    private function createEngineStub(): object
    {
        $ref = new \ReflectionClass(\App\Services\AI\SEO\SEOKillerEngine::class);
        return $ref->newInstanceWithoutConstructor();
    }

    private function sqliteDb(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE items (ml_item_id TEXT, account_id INTEGER, sold_quantity INTEGER, data TEXT)');
        $db->exec('CREATE TABLE ml_orders (account_id INTEGER, date_created TEXT, status TEXT, order_data TEXT)');
        $db->exec('CREATE TABLE account_index_metrics (account_id INTEGER, visitas_7d REAL, vendas_7d REAL, updated_at TEXT)');
        $db->exec('CREATE TABLE seo_performance_metrics (account_id INTEGER, item_id TEXT, metric_date TEXT, visits INTEGER, sold_quantity INTEGER)');
        return $db;
    }

    private function bindDb(object $engine, \PDO $db, int $accountId = 9): void
    {
        $dbProp = new \ReflectionProperty($engine, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($engine, $db);
        $accountProp = new \ReflectionProperty($engine, 'accountId');
        $accountProp->setAccessible(true);
        $accountProp->setValue($engine, $accountId);
    }

    public function testEngineDoesNotQueryDeadSeoPerformanceMetricsOrVisitsApi(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AI/SEO/SEOKillerEngine.php');
        $this->assertStringNotContainsString('FROM seo_performance_metrics', $src);
        $this->assertStringNotContainsString('getMultiItemVisits(', $src);
        $this->assertStringContainsString('hydrateOfficialPerformanceFromLocalItems', $src);
        $this->assertStringContainsString("'skip_visits' => true", $src);
        $this->assertStringContainsString('account_index_metrics', $src);
        $this->assertStringContainsString('ml_orders', $src);
        $this->assertStringContainsString('performance_score', $src);
    }

    public function testHydrateOfficialPerformanceFromItemsData(): void
    {
        $engine = $this->createEngineStub();
        $db = $this->sqliteDb();
        $db->exec("INSERT INTO items VALUES ('MLB1', 9, 3, '{\"performance_score\":77,\"performance_level\":\"good\",\"performance_updated_at\":\"2026-08-21 10:00:00\"}')");
        $db->exec("INSERT INTO items VALUES ('MLB2', 9, 0, '{}')");
        $this->bindDb($engine, $db);

        $items = [
            ['id' => 'MLB1', 'title' => 'A', 'sold_quantity' => 0],
            ['id' => 'MLB2', 'title' => 'B'],
        ];
        $out = $this->invokePrivateMethod($engine, 'hydrateOfficialPerformanceFromLocalItems', [$items]);

        $this->assertSame(77, $out[0]['performance_score']);
        $this->assertSame('good', $out[0]['performance_level']);
        $this->assertSame(3, $out[0]['sold_quantity']);
        $this->assertArrayNotHasKey('performance_score', $out[1]);
    }

    public function testCollectOfficialScoreStatsTreatsMissingAsUnknownNotZero(): void
    {
        $engine = $this->createEngineStub();
        $stats = $this->invokePrivateMethod($engine, 'collectOfficialScoreStats', [[
            ['id' => 'MLB1', 'performance_score' => 22],
            ['id' => 'MLB2'],
            ['id' => 'MLB3', 'performance_score' => 80],
            ['id' => 'MLB4', 'performance_score' => 0],
        ]]);

        $this->assertSame([22.0, 80.0, 0.0], $stats['scores']);
        $this->assertSame(1, $stats['unknown']);
        $this->assertSame(2, $stats['low']); // 22 and real 0
        $this->assertSame(['MLB1', 'MLB4'], $stats['low_ids']);
        $this->assertSame(['MLB2'], $stats['unknown_ids']);
    }

    public function testAssembleDiagnosisDoesNotTreatPendingAsOfficialZero(): void
    {
        $engine = $this->createEngineStub();
        $items = array_fill(0, 20, ['id' => 'MLB', 'title' => 'x']);
        $result = $this->invokePrivateMethod($engine, 'assembleDiagnosis', [
            ['health_score' => 0],
            [['impact' => -10, 'severity' => 'high', 'solution' => 'x', 'category' => 'title', 'affected_items' => 1]],
            [],
            $items,
        ]);

        $this->assertFalse($result['official_score']);
        $this->assertSame('pending', $result['performance_status']);
        $this->assertSame(20, $result['performance_unknown']);
        $this->assertSame(90, $result['health_score']);
        $this->assertStringContainsString('pendente', $result['summary']);
        $this->assertStringNotContainsString('Score 0/100', $result['summary']);
    }

    public function testCollectVisitConversionStatsUsesOrdersNotDeadCache(): void
    {
        $engine = $this->createEngineStub();
        $db = $this->sqliteDb();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $db->exec("INSERT INTO items VALUES ('MLB1', 9, 0, '{}')");
        $db->exec("INSERT INTO items VALUES ('MLB2', 9, 0, '{}')");
        $db->exec("INSERT INTO items VALUES ('MLB3', 9, 8, '{}')");
        $order = json_encode(['order_items' => [['item' => ['id' => 'MLB3'], 'quantity' => 2]]], JSON_UNESCAPED_UNICODE);
        $db->exec('INSERT INTO ml_orders VALUES (9, ' . $db->quote($today) . ', \'paid\', ' . $db->quote($order) . ')');
        $db->exec("INSERT INTO account_index_metrics VALUES (9, 1459, 79, '{$today}')");
        $db->exec("INSERT INTO seo_performance_metrics VALUES (9, 'MLB1', '2026-08-06', 0, 0)");
        $db->exec("INSERT INTO seo_performance_metrics VALUES (9, 'MLB2', '2026-08-06', 0, 0)");
        $db->exec("INSERT INTO seo_performance_metrics VALUES (9, 'MLB3', '2026-08-06', 0, 0)");
        $this->bindDb($engine, $db);

        $items = [
            ['id' => 'MLB1', 'status' => 'active', 'sold_quantity' => 0],
            ['id' => 'MLB2', 'status' => 'active', 'sold_quantity' => 0],
            ['id' => 'MLB3', 'status' => 'active', 'sold_quantity' => 8],
            ['id' => 'MLB4', 'status' => 'paused', 'sold_quantity' => 0],
        ];
        $stats = $this->invokePrivateMethod($engine, 'collectVisitConversionStats', [$items]);

        $this->assertTrue($stats['known']);
        $this->assertSame(0, $stats['zero_visits']);
        $this->assertSame('account_index_metrics', $stats['visits_source']);
        $this->assertSame(1459.0, $stats['account_visits']);
        $this->assertSame(2, $stats['no_sales']);
        $this->assertSame(['MLB1', 'MLB2'], $stats['no_sales_sample_ids']);
        $this->assertFalse($stats['account_zero_visits']);
    }

    public function testEvaluateVisitConversionStatsSkipsWhenUnknown(): void
    {
        $engine = $this->createEngineStub();
        $result = $this->invokePrivateMethod($engine, 'evaluateVisitConversionStats', [[
            'known' => false,
            'zero_visits' => 9,
            'no_sales' => 4,
            'zero_sample_ids' => ['MLB1'],
            'no_sales_sample_ids' => ['MLB2'],
        ]]);
        $this->assertEmpty($result['problems']);
    }
}
