<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CatalogCloneService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \App\Services\CatalogCloneService
 */
final class CatalogCloneAccountScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = ['active_ml_account_id' => 1335];
    }

    public function testOwnSellerQueriesAreScopedToActiveAccount(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/Services/CatalogCloneService.php'
        );
        $this->assertStringContainsString('AccountScopeHelper', $source);
        $this->assertStringContainsString('scopedActiveAccountId', $source);
        $this->assertStringNotContainsString(
            "WHERE status = 'active' ORDER BY id ASC LIMIT 1",
            $source
        );
        $this->assertStringNotContainsString(
            "WHERE status = 'active' ORDER BY id ASC\"",
            $source
        );
    }

    public function testGetAllOwnSellerIdsReturnsEmptyWithoutSession(): void
    {
        $_SESSION = [];
        $ref = new ReflectionClass(CatalogCloneService::class);
        $svc = $ref->newInstanceWithoutConstructor();
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE ml_accounts (id INTEGER, ml_user_id TEXT, status TEXT)');
        $db->exec("INSERT INTO ml_accounts VALUES (1335, '3058804121', 'active')");
        $db->exec("INSERT INTO ml_accounts VALUES (1336, '3227016625', 'active')");
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($svc, $db);

        $method = $ref->getMethod('getAllOwnSellerIds');
        $method->setAccessible(true);
        $this->assertSame([], $method->invoke($svc));

        $_SESSION = ['active_ml_account_id' => 1335];
        $this->assertSame(['3058804121'], $method->invoke($svc));

        $_SESSION = ['active_ml_account_id' => 1336];
        $this->assertSame(['3227016625'], $method->invoke($svc));
    }
}
