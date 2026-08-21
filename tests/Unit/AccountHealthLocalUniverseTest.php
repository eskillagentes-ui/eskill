<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AccountHealthService;
use PHPUnit\Framework\TestCase;

/**
 * Diagnóstico reads the local items universe for the active account.
 * ML 403 does not blank the page or invent TRAVADA.
 */
final class AccountHealthLocalUniverseTest extends TestCase
{
    private function invokePrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    private function createServiceStub(int $accountId = 1335): AccountHealthService
    {
        $ref = new \ReflectionClass(AccountHealthService::class);
        /** @var AccountHealthService $svc */
        $svc = $ref->newInstanceWithoutConstructor();
        foreach (['accountId' => $accountId, 'cachedSellerId' => null, 'cachedUserData' => null,
                  'cachedActiveItemIds' => null, 'cachedTotalActive' => null,
                  'cachedLocalItemsAll' => null, 'cachedLocalUniverse' => null,
                  'mlForbidden' => false, 'cache' => null, 'cachedStaleListings' => null,
                  'categoryRequiredAttrs' => [], 'timings' => []] as $prop => $val) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue($svc, $val);
            }
        }
        return $svc;
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
            price REAL,
            available_quantity INTEGER,
            sold_quantity INTEGER,
            catalog_product_id TEXT,
            thumbnail TEXT,
            permalink TEXT,
            category_id TEXT,
            data TEXT
        )');
        $db->exec('CREATE TABLE ml_orders (
            account_id INTEGER,
            ml_account_id INTEGER,
            status TEXT,
            date_created TEXT,
            total_amount REAL,
            order_data TEXT
        )');
        $db->exec('CREATE TABLE ml_accounts (
            id INTEGER PRIMARY KEY,
            ml_user_id TEXT,
            nickname TEXT,
            email TEXT,
            status TEXT,
            site_id TEXT
        )');
        $db->exec('CREATE TABLE reputation_history (
            id INTEGER PRIMARY KEY,
            account_id INTEGER,
            date TEXT,
            level_id TEXT,
            power_seller_status TEXT,
            total_transactions INTEGER,
            completed_transactions INTEGER,
            cancellations_rate REAL,
            claims_rate REAL,
            delayed_handling_time_rate REAL,
            positive_rating REAL,
            neutral_rating REAL,
            negative_rating REAL,
            data TEXT
        )');
        $db->exec("INSERT INTO ml_accounts (id, ml_user_id, nickname, email, status, site_id)
                   VALUES (1335, '111', 'FACILYTY', 'a@x', 'active', 'MLB'),
                          (1336, '222', 'FALCAO', 'b@x', 'active', 'MLB')");
        return $db;
    }

    private function bind(AccountHealthService $svc, \PDO $db, $mlClient): void
    {
        $ref = new \ReflectionClass($svc);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($svc, $db);
        $mlProp = $ref->getProperty('client');
        $mlProp->setAccessible(true);
        $mlProp->setValue($svc, $mlClient);
    }

    private function forbiddenClient()
    {
        $mock = $this->getMockBuilder(\App\Services\MercadoLivreClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'getMe', 'getSellerId', 'getItemHealth'])
            ->getMock();

        $forbidden = [
            'error' => 'access_denied',
            'status' => 403,
            'message' => 'At least one policy returned UNAUTHORIZED.',
        ];
        $mock->method('get')->willReturn($forbidden);
        $mock->method('getMe')->willReturn($forbidden);
        $mock->method('getSellerId')->willReturn(null);
        $mock->expects($this->never())->method('getItemHealth');

        return $mock;
    }

    private function insertItem(\PDO $db, int $accountId, string $mlb, string $title, array $data = []): void
    {
        $stmt = $db->prepare(
            'INSERT INTO items (ml_item_id, account_id, title, status, price, available_quantity, sold_quantity, category_id, data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $payload = array_merge([
            'id' => $mlb,
            'title' => $title,
            'status' => 'active',
            'price' => 10.5,
            'available_quantity' => 3,
            'performance_score' => 72,
        ], $data);
        $stmt->execute([
            $mlb,
            $accountId,
            $title,
            $payload['status'],
            $payload['price'],
            $payload['available_quantity'],
            $payload['sold_quantity'] ?? 0,
            $payload['category_id'] ?? 'MLB123',
            json_encode($payload),
        ]);
    }

    public function testSourcePrefersLocalItemsAndStopsDeprecatedHealth(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AccountHealthService.php');
        $this->assertStringContainsString('loadLocalListingUniverse', $src);
        $this->assertStringContainsString('fetchLocalRecentSales', $src);
        $this->assertStringContainsString("WHERE account_id = ?", $src);
        $this->assertStringContainsString("['active', 'paused']", $src);
        $this->assertStringContainsString('status IN (', $src);
        $this->assertStringNotContainsString('getItemHealth(', $src);
        $this->assertStringContainsString('isMlHttpFailurePayload', $src);
        $this->assertStringContainsString('PERFORMANCE_PENDING', $src);
        $this->assertStringContainsString('safeMlGet', $src);

        $ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AccountHealthController.php');
        $this->assertStringContainsString('AccountScopeHelper::activeAccountId', $ctrl);
        $this->assertStringContainsString('resolveScopedAccountId', $ctrl);
        $this->assertStringContainsString('A body/query account_id that differs', $ctrl);
    }

    public function testCachedActiveItemsUsesLocalUniverseWhenMlReturns403(): void
    {
        $svc = $this->createServiceStub(1335);
        $db = $this->sqliteDb();
        $this->insertItem($db, 1335, 'MLB111', 'Peca FACILYTY');
        $this->insertItem($db, 1335, 'MLB112', 'Outra FACILYTY', ['status' => 'paused']);
        $this->insertItem($db, 1336, 'MLB999', 'Peca FALCAO');
        $this->bind($svc, $db, $this->forbiddenClient());

        $pack = $this->invokePrivate($svc, 'getCachedActiveItems', []);

        $this->assertSame('local_items', $pack['source']);
        $this->assertFalse($pack['ml_forbidden']);
        $this->assertContains('MLB111', $pack['ids']);
        $this->assertNotContains('MLB999', $pack['ids']);
        $this->assertNotContains('MLB112', $pack['ids']); // paused not in active ids
        $this->assertSame(1, $pack['universe']['active']);
        $this->assertSame(1, $pack['universe']['paused']);
        $this->assertSame(2, $pack['universe_total']);
        $this->assertSame(1, $pack['total']);
    }

    public function testLoadItemDetailsOn403WithoutMixingOtherAccount(): void
    {
        $svc = $this->createServiceStub(1335);
        $db = $this->sqliteDb();
        $this->insertItem($db, 1335, 'MLB111', 'Peca FACILYTY', ['performance_score' => 80]);
        $this->insertItem($db, 1336, 'MLB999', 'Peca FALCAO', ['performance_score' => 10]);
        $this->bind($svc, $db, $this->forbiddenClient());

        $items = $this->invokePrivate($svc, 'loadItemDetails', [['MLB111', 'MLB999']]);
        $ids = array_column($items, 'id');
        $this->assertContains('MLB111', $ids);
        $this->assertNotContains('MLB999', $ids);
        $this->assertSame(80, $items[0]['performance_score']);
    }

    public function test403WithoutLocalStaysEmptyNotTravada(): void
    {
        $svc = $this->createServiceStub(1335);
        $db = $this->sqliteDb();
        $this->bind($svc, $db, $this->forbiddenClient());

        $pack = $this->invokePrivate($svc, 'getCachedActiveItems', []);
        $this->assertSame([], $pack['ids']);
        $this->assertTrue($pack['ml_forbidden']);
        $this->assertSame(0, $pack['universe_total']);
        $this->assertSame('ml_forbidden', $pack['source']);

        $json = json_encode($pack);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('TRAVADA', $json);
        $this->assertStringNotContainsString('MORTO', $json);
    }

    public function testSellerFallsBackToLocalOn403(): void
    {
        $svc = $this->createServiceStub(1335);
        $db = $this->sqliteDb();
        $this->bind($svc, $db, $this->forbiddenClient());

        $sellerId = $this->invokePrivate($svc, 'getCachedSellerId', []);
        $this->assertSame('111', $sellerId);

        $user = $this->invokePrivate($svc, 'getCachedUserData', []);
        $this->assertIsArray($user);
        $this->assertSame('FACILYTY', $user['nickname']);
        $this->assertSame('local_ml_accounts', $user['_source']);
        $this->assertArrayNotHasKey('error', $user);
    }

    public function testMissingPerformanceIsPendingNotTravada(): void
    {
        $svc = $this->createServiceStub(1335);
        $db = $this->sqliteDb();
        $this->bind($svc, $db, $this->forbiddenClient());

        $scores = $this->invokePrivate($svc, 'scoreItemCompetitiveness', [[
            'title' => 'Peca',
            'listing_type_id' => 'gold_special',
            'shipping' => ['free_shipping' => true, 'logistic_type' => 'fulfillment'],
            'sold_quantity' => 2,
        ]]);
        $this->assertSame(7, $scores['health']);
        $this->assertArrayNotHasKey('TRAVADA', $scores);

        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AccountHealthService.php');
        $this->assertStringNotContainsString("'TRAVADA'", $src);
        $this->assertStringNotContainsString('"TRAVADA"', $src);
    }

    public function testLocalOrdersDoNotMixAccounts(): void
    {
        $svc = $this->createServiceStub(1335);
        $db = $this->sqliteDb();
        $this->insertItem($db, 1335, 'MLB111', 'Peca FACILYTY');
        $db->exec("INSERT INTO ml_orders (account_id, status, date_created, total_amount, order_data) VALUES (
            1335, 'paid', datetime('now'), 50,
            '{\"order_items\":[{\"item\":{\"id\":\"MLB111\"},\"quantity\":2}]}'
        )");
        $db->exec("INSERT INTO ml_orders (account_id, status, date_created, total_amount, order_data) VALUES (
            1336, 'paid', datetime('now'), 999,
            '{\"order_items\":[{\"item\":{\"id\":\"MLB999\"},\"quantity\":9}]}'
        )");
        $this->bind($svc, $db, $this->forbiddenClient());

        $map = $this->invokePrivate($svc, 'fetchLocalRecentSales', [['MLB111', 'MLB999']]);
        $this->assertSame(2, $map['MLB111'] ?? 0);
        $this->assertArrayNotHasKey('MLB999', $map);

        $stats = $this->invokePrivate($svc, 'fetchLocalOrderStats', [date('Y-m-d H:i:s', strtotime('-30 days')), null]);
        $this->assertSame(1, $stats['count']);
        $this->assertEquals(50.0, $stats['revenue']);
    }

    public function testAttentionPausedCountComesFromLocalUniverse(): void
    {
        $svc = $this->createServiceStub(1335);
        $db = $this->sqliteDb();
        $this->insertItem($db, 1335, 'MLB111', 'Ativo');
        $this->insertItem($db, 1335, 'MLB112', 'Pausado', ['status' => 'paused']);
        $this->insertItem($db, 1336, 'MLB999', 'Pausado FALCAO', ['status' => 'paused']);
        $this->bind($svc, $db, $this->forbiddenClient());

        $items = $svc->getItemsNeedingAttention();
        $paused = null;
        foreach ($items as $row) {
            if (($row['type'] ?? '') === 'paused_items') {
                $paused = $row;
            }
        }
        $this->assertIsArray($paused);
        $this->assertSame(1, $paused['count']);
        $json = json_encode($items);
        $this->assertStringNotContainsString('TRAVADA', (string) $json);
    }
}
