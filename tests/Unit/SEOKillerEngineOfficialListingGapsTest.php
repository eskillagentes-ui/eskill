<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Ficha completeness uses official ML listing gaps from local items, not ML GET /items.
 */
final class SEOKillerEngineOfficialListingGapsTest extends TestCase
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
        $db->exec('CREATE TABLE items (
            ml_item_id TEXT,
            account_id INTEGER,
            title TEXT,
            status TEXT,
            available_quantity INTEGER,
            sold_quantity INTEGER,
            catalog_product_id TEXT,
            data TEXT
        )');
        return $db;
    }

    private function bindDb(object $engine, \PDO $db, int $accountId = 1335): void
    {
        $dbProp = new \ReflectionProperty($engine, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($engine, $db);
        $accountProp = new \ReflectionProperty($engine, 'accountId');
        $accountProp->setAccessible(true);
        $accountProp->setValue($engine, $accountId);
    }

    private function item(string $id, array $overrides = []): array
    {
        $base = [
            'id' => $id,
            'ml_item_id' => $id,
            'status' => 'active',
            'title' => 'Peca ' . $id,
            'available_quantity' => 4,
            'pictures' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']],
            'shipping' => ['free_shipping' => true],
            'listing_type_id' => 'gold_pro',
            'catalog_product_id' => null,
            'catalog_listing' => false,
            'performance_score' => 80,
        ];
        return array_merge($base, $overrides);
    }

    public function testSourceUsesLocalItemsAndOfficialGapsNotMyths(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AI/SEO/SEOKillerEngine.php');
        $this->assertStringContainsString('loadLocalListingUniverse', $src);
        $this->assertStringContainsString('collectOfficialListingGapStats', $src);
        $this->assertStringContainsString("status IN ('active', 'paused')", $src);
        $this->assertStringContainsString('WHERE account_id = ?', $src);
        $this->assertStringContainsString('catalog_listing', $src);
        $this->assertStringContainsString('gold_pro', $src);
        $this->assertStringNotContainsString('STATUS_TRAVADA', $src);
        $this->assertStringNotContainsString('MORTO', $src);
        $ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/SEOKillerController.php');
        $this->assertStringContainsString('officialListingCompletenessReport', $ctrl);
        $start = strpos($ctrl, 'function completenessReport');
        $this->assertNotFalse($start);
        $chunk = substr($ctrl, $start, 700);
        $this->assertStringContainsString('officialListingCompletenessReport', $chunk);
        $this->assertStringNotContainsString("listItems(['limit' => 50])", $chunk);
    }

    public function testPhotosBelowThreeCountAsGapThreeIsComplete(): void
    {
        $engine = $this->createEngineStub();
        $stats = $this->invokePrivateMethod($engine, 'collectOfficialListingGapStats', [[
            $this->item('MLB1', ['pictures' => [['id' => 'a'], ['id' => 'b']]]),
            $this->item('MLB2', ['pictures' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]]),
            $this->item('MLB3', ['pictures' => []]),
        ]]);

        $this->assertSame(2, $stats['photos_lt3']);
        $this->assertSame(3, $stats['universe_active']);
        $this->assertContains('MLB1', $stats['pending_ids']);
        $this->assertContains('MLB3', $stats['pending_ids']);
        $this->assertNotContains('MLB2', $stats['pending_ids']);
    }

    public function testStockZeroCatalogClassicShippingAndNotPremium(): void
    {
        $engine = $this->createEngineStub();
        $stats = $this->invokePrivateMethod($engine, 'collectOfficialListingGapStats', [[
            $this->item('MLB1', ['available_quantity' => 0]),
            $this->item('MLB2', [
                'catalog_product_id' => 'MLB123',
                'catalog_listing' => false,
            ]),
            $this->item('MLB3', [
                'catalog_product_id' => 'MLB999',
                'catalog_listing' => true,
            ]),
            $this->item('MLB4', ['shipping' => ['free_shipping' => false]]),
            $this->item('MLB5', ['listing_type_id' => 'gold_special']),
            $this->item('MLB6', ['catalog_product_id' => null, 'catalog_listing' => false]),
            $this->item('MLB7', ['status' => 'paused', 'available_quantity' => 0, 'pictures' => []]),
            $this->item('MLB8', ['performance_score' => null]),
        ]]);

        $this->assertSame(1, $stats['stock_0']);
        $this->assertSame(1, $stats['catalog_not_listing']);
        $this->assertSame(1, $stats['no_free_shipping']);
        $this->assertSame(1, $stats['not_premium']);
        $this->assertSame(1, $stats['performance_pending']);
        $this->assertSame(7, $stats['universe_active']);
        $this->assertSame(1, $stats['universe_paused']);
        $this->assertNotContains('MLB3', $stats['pending_ids']);
        $this->assertNotContains('MLB6', $stats['pending_ids']);
        $this->assertNotContains('MLB7', $stats['pending_ids']);
        $this->assertContains('MLB8', $stats['pending_ids']);
        $this->assertSame(5, $stats['pending_unique']);
    }

    public function testMissingPerformanceIsPendingNotZero(): void
    {
        $engine = $this->createEngineStub();
        $items = [
            $this->item('MLB1', ['performance_score' => 0]),
            $this->item('MLB2'),
        ];
        unset($items[1]['performance_score']);
        $stats = $this->invokePrivateMethod($engine, 'collectOfficialListingGapStats', [$items]);

        $this->assertSame(1, $stats['performance_pending']);
        $this->assertSame(1, $stats['pending_unique']);
        $problems = $this->invokePrivateMethod($engine, 'analyzeOfficialListingGaps', [$items]);
        $pendingProblems = array_filter(
            $problems['problems'],
            static fn(array $p): bool => !empty($p['performance_pending'])
        );
        $this->assertCount(1, $pendingProblems);
        $this->assertSame('pending', array_values($pendingProblems)[0]['severity']);
        $this->assertStringContainsString('pendente', array_values($pendingProblems)[0]['issue']);
        $this->assertStringContainsString('não tratar como 0', array_values($pendingProblems)[0]['issue']);
    }

    public function testLoadLocalListingUniverseScopesAccountAndDropsClosed(): void
    {
        $engine = $this->createEngineStub();
        $db = $this->sqliteDb();
        $payload = static function (array $extra): string {
            $data = array_merge([
                'pictures' => [['id' => '1'], ['id' => '2'], ['id' => '3']],
                'shipping' => ['free_shipping' => true],
                'listing_type_id' => 'gold_pro',
                'performance_score' => 70,
            ], $extra);
            return json_encode($data, JSON_UNESCAPED_UNICODE);
        };
        $db->exec("INSERT INTO items VALUES ('MLB1', 1335, 'A', 'active', 2, 1, NULL, " . $db->quote($payload([])) . ')');
        $db->exec("INSERT INTO items VALUES ('MLB2', 1335, 'B', 'paused', 2, 0, NULL, " . $db->quote($payload([])) . ')');
        $db->exec("INSERT INTO items VALUES ('MLB3', 1335, 'C', 'closed', 0, 9, NULL, " . $db->quote($payload(['pictures' => []])) . ')');
        $db->exec("INSERT INTO items VALUES ('MLB4', 1336, 'D', 'active', 0, 0, NULL, " . $db->quote($payload(['pictures' => []])) . ')');
        $this->bindDb($engine, $db, 1335);

        $items = $this->invokePrivateMethod($engine, 'loadLocalListingUniverse', []);
        $ids = array_map(static fn(array $i): string => (string) ($i['id'] ?? ''), $items);
        sort($ids);

        $this->assertSame(['MLB1', 'MLB2'], $ids);
        $byId = [];
        foreach ($items as $item) {
            $byId[(string) $item['id']] = $item;
        }
        $this->assertSame(2, (int) $byId['MLB1']['available_quantity']);
        $this->assertSame('paused', $byId['MLB2']['status']);
    }

    public function testClassifyItemDescriptionDoesNotInventMissingWhenLocalTextAbsent(): void
    {
        $engine = $this->createEngineStub();
        $stats = $this->invokePrivateMethod($engine, 'collectDescriptionStats', [[$this->item('MLB1')]]);
        $this->assertSame(0, $stats['noDescription']);
        $this->assertSame(0, $stats['shortDescriptions']);
    }

    public function testAssembleDiagnosisExposesFichaPendingUnique(): void
    {
        $engine = $this->createEngineStub();
        $items = [
            $this->item('MLB1', ['pictures' => [['id' => 'a']]]),
            $this->item('MLB2', ['listing_type_id' => 'gold_special']),
            $this->item('MLB3'),
        ];
        $result = $this->invokePrivateMethod($engine, 'assembleDiagnosis', [
            ['health_score' => 0, 'problems' => [], 'opportunities' => []],
            [],
            [],
            $items,
        ]);

        $this->assertSame(3, $result['ficha_gaps']['universe_active']);
        $this->assertSame(2, $result['ficha_gaps']['pending_unique']);
        $this->assertSame(1, $result['ficha_gaps']['photos_lt3']);
        $this->assertSame(1, $result['ficha_gaps']['not_premium']);
    }

    public function testOfficialListingCompletenessReportDoesNotCallMl(): void
    {
        $engine = $this->createEngineStub();
        $db = $this->sqliteDb();
        $data = json_encode([
            'pictures' => [['id' => '1']],
            'shipping' => ['free_shipping' => false],
            'listing_type_id' => 'gold_special',
            'performance_score' => 55,
        ], JSON_UNESCAPED_UNICODE);
        $db->exec("INSERT INTO items VALUES ('MLB1', 1335, 'A', 'active', 3, 0, NULL, " . $db->quote($data) . ')');
        $this->bindDb($engine, $db, 1335);

        $report = $engine->officialListingCompletenessReport();
        $this->assertTrue($report['success']);
        $this->assertSame('local_items', $report['source']);
        $this->assertSame(1335, $report['account_id']);
        $this->assertSame(1, $report['total_items']);
        $this->assertSame(1, $report['pending']);
        $this->assertSame(0, $report['optimized']);
        $this->assertSame(1, $report['ficha_gaps']['photos_lt3']);
        $this->assertSame(1, $report['ficha_gaps']['no_free_shipping']);
        $this->assertSame(1, $report['ficha_gaps']['not_premium']);
    }
}
