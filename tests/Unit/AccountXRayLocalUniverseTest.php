<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AccountGovernanceService;
use App\Services\AccountXRayService;
use PHPUnit\Framework\TestCase;

/**
 * Raio X reads the local items universe for the active account.
 * ML 403 does not blank the catalog or invent TRAVADA.
 */
final class AccountXRayLocalUniverseTest extends TestCase
{
    private function invokePrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    private function createServiceStub(int $accountId = 1335): AccountXRayService
    {
        $ref = new \ReflectionClass(AccountXRayService::class);
        /** @var AccountXRayService $svc */
        $svc = $ref->newInstanceWithoutConstructor();
        $accountProp = $ref->getProperty('accountId');
        $accountProp->setAccessible(true);
        $accountProp->setValue($svc, $accountId);
        $loggerProp = $ref->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($svc, null);
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
            order_data TEXT
        )');
        $db->exec('CREATE TABLE ml_accounts (
            id INTEGER PRIMARY KEY,
            ml_user_id TEXT,
            nickname TEXT,
            email TEXT,
            status TEXT
        )');
        $db->exec("INSERT INTO ml_accounts (id, ml_user_id, nickname, email, status)
                   VALUES (1335, '111', 'FACILYTY', 'a@x', 'active'),
                          (1336, '222', 'FALCAO', 'b@x', 'active')");
        return $db;
    }

    private function bind(AccountXRayService $svc, \PDO $db, $mlClient): void
    {
        $ref = new \ReflectionClass($svc);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($svc, $db);
        $mlProp = $ref->getProperty('mlClient');
        $mlProp->setAccessible(true);
        $mlProp->setValue($svc, $mlClient);
    }

    private function forbiddenClient()
    {
        $mock = $this->getMockBuilder(\App\Services\MercadoLivreClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'getMe', 'getMultiItemDetails', 'getMultiItemVisits', 'getItemHealth'])
            ->getMock();

        $forbidden = [
            'error' => 'access_denied',
            'status' => 403,
            'message' => 'At least one policy returned UNAUTHORIZED.',
        ];
        $mock->method('get')->willReturn($forbidden);
        $mock->method('getMe')->willReturn($forbidden);
        $mock->method('getMultiItemDetails')->willReturn($forbidden);
        $mock->method('getMultiItemVisits')->willReturn($forbidden);
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
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AccountXRayService.php');
        $this->assertStringContainsString('loadLocalListingUniverse', $src);
        $this->assertStringContainsString('fetchLocalRecentSales', $src);
        $this->assertStringContainsString("WHERE account_id = ?", $src);
        $this->assertStringContainsString("['active', 'paused']", $src);
        $this->assertStringContainsString('status IN (', $src);
        $this->assertStringNotContainsString('getItemHealth(', $src);
        $this->assertStringContainsString('isMlHttpFailurePayload', $src);
        $this->assertStringContainsString('_metrics_pending', $src);

        $ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AccountXRayController.php');
        $this->assertStringContainsString('AccountScopeHelper::activeAccountId', $ctrl);
        $this->assertStringContainsString('resolveScopedAccountId', $ctrl);
    }

    public function testFetchAllItemsUsesLocalUniverseWhenMlReturns403(): void
    {
        $svc = $this->createServiceStub(1335);
        $db = $this->sqliteDb();
        $this->insertItem($db, 1335, 'MLB111', 'Peca FACILYTY');
        $this->insertItem($db, 1335, 'MLB112', 'Outra FACILYTY', ['status' => 'paused']);
        $this->insertItem($db, 1336, 'MLB999', 'Peca FALCAO');
        $this->bind($svc, $db, $this->forbiddenClient());

        $pack = $this->invokePrivate($svc, 'fetchAllItems', [400, true, '111']);

        $this->assertSame('local_items', $pack['source']);
        $this->assertFalse($pack['ml_forbidden']);
        $ids = array_column($pack['items'], 'id');
        $this->assertContains('MLB111', $ids);
        $this->assertContains('MLB112', $ids);
        $this->assertNotContains('MLB999', $ids);
        $this->assertSame(1, $pack['universe']['active']);
        $this->assertSame(1, $pack['universe']['paused']);
        $this->assertSame(2, $pack['universe_total']);
        $this->assertCount(2, $pack['items']);
    }

    public function testFetchAllItemsOn403WithoutLocalStaysEmptyNotTravadaPayload(): void
    {
        $svc = $this->createServiceStub(1335);
        $db = $this->sqliteDb();
        $this->bind($svc, $db, $this->forbiddenClient());

        $pack = $this->invokePrivate($svc, 'fetchAllItems', [400, true, '111']);

        $this->assertSame([], $pack['items']);
        $this->assertTrue($pack['ml_forbidden']);
        $this->assertSame('local_items', $pack['source']);
        $this->assertSame(0, $pack['universe_total']);
    }

    public function testEnrichOn403DoesNotInventZeroVisitsOrCallHealth(): void
    {
        $svc = $this->createServiceStub(1335);
        $db = $this->sqliteDb();
        $this->insertItem($db, 1335, 'MLB111', 'Peca FACILYTY', [
            'performance_score' => 80,
        ]);
        $db->exec("INSERT INTO ml_orders (account_id, status, date_created, order_data) VALUES (
            1335, 'paid', datetime('now'),
            '{\"order_items\":[{\"item\":{\"id\":\"MLB111\"},\"quantity\":2}]}'
        )");
        $this->bind($svc, $db, $this->forbiddenClient());

        $items = $this->invokePrivate($svc, 'loadLocalListingUniverse', [400, true]);
        $enriched = $this->invokePrivate($svc, 'enrichWithMetrics', [$items, '111']);

        $this->assertCount(1, $enriched);
        $this->assertArrayNotHasKey('_visits_30d', $enriched[0]);
        $this->assertArrayNotHasKey('visits_30d', $enriched[0]);
        $this->assertTrue($enriched[0]['_metrics_pending']);
        $this->assertSame(2, $enriched[0]['_sales_30d']);
        $this->assertSame(80, $enriched[0]['_health_score']);

        $gov = new AccountGovernanceService();
        $flags = $gov->calculateFlags($enriched[0], ['total_visits_30d' => 0]);
        $class = $gov->classifyItem($enriched[0], $flags, 50);
        $this->assertSame(AccountGovernanceService::CLASS_COM_VENDA, $class);
        $this->assertNotSame(AccountGovernanceService::CLASS_MORTO, $class);
        $this->assertNotSame(AccountGovernanceService::CLASS_TOXICO, $class);

        $status = $gov->classifyAccount(
            ['account_conv_30d' => 0, 'total_visits_30d' => 0, 'reputation_level' => 'unknown'],
            [['classification' => $class]]
        );
        $this->assertNotSame(AccountGovernanceService::STATUS_TRAVADA, $status);
    }

    public function testResolveScopedAccountIgnoresOtherAccountId(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AccountXRayController.php');
        $this->assertStringContainsString('A body/query account_id that differs', $src);
        $this->assertStringContainsString('return $active;', $src);
        $this->assertStringContainsString('AccountScopeHelper::activeAccountId()', $src);
    }

    public function testSellerFallsBackToLocalOn403(): void
    {
        $svc = $this->createServiceStub(1335);
        $db = $this->sqliteDb();
        $this->bind($svc, $db, $this->forbiddenClient());

        $seller = $this->invokePrivate($svc, 'fetchSellerData', []);
        $this->assertArrayNotHasKey('error', $seller);
        $this->assertSame('111', $seller['seller_id']);
        $this->assertSame('FACILYTY', $seller['nickname']);
        $this->assertSame('local_ml_accounts', $seller['_source']);
    }
}
