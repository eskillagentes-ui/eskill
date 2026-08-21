<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AccountXRayCoverageTest extends TestCase
{
    public function testFetchRecentSalesPaginatesOrdersWithSafeCap(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AccountXRayService.php');
        $this->assertStringContainsString('ORDERS_PAGE_SIZE', $src);
        $this->assertStringContainsString('ORDERS_MAX_FETCH', $src);
        $this->assertStringContainsString("'offset'", $src);
        $this->assertStringContainsString("'/orders/search'", $src);
        $this->assertDoesNotMatchRegularExpression(
            "/get\('\/orders\/search'[^;]*'limit'\s*=>\s*100/",
            $src
        );
        $this->assertMatchesRegularExpression('/ORDERS_MAX_FETCH\s*=\s*500/', $src);
        $this->assertMatchesRegularExpression('/ORDERS_PAGE_SIZE\s*=\s*50/', $src);
    }

    public function testCoveragePayloadExposesAnalyzedVsUniverse(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/AccountXRayService.php');
        $this->assertStringContainsString("'analyzed'", $src);
        $this->assertStringContainsString("'universe'", $src);
        $this->assertStringContainsString("'coverage'", $src);
        $this->assertMatchesRegularExpression('/DEFAULT_MAX_ITEMS\s*=\s*400/', $src);
        $this->assertMatchesRegularExpression('/MAX_ITEMS_FULL_ANALYSIS\s*=\s*500/', $src);
        $this->assertStringContainsString('countItemsByStatus', $src);
        $this->assertStringContainsString('loadLocalListingUniverse', $src);
        $this->assertStringContainsString('countLocalUniverseByStatus', $src);
        $this->assertStringNotContainsString('getItemHealth(', $src);
    }

    public function testControllerScopesRunToActiveAccount(): void
    {
        $ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AccountXRayController.php');
        $this->assertStringContainsString('AccountScopeHelper', $ctrl);
        $this->assertStringContainsString('resolveScopedAccountId', $ctrl);
        $start = strpos($ctrl, 'function run(');
        $this->assertNotFalse($start);
        $chunk = substr($ctrl, $start, 900);
        $this->assertStringContainsString('resolveScopedAccountId', $chunk);
    }

    public function testUiDefaultMaxItemsIsFourHundredAndShowsCoverage(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/dashboard/account-xray.php');
        $this->assertStringContainsString('value="400" selected', $src);
        $this->assertStringContainsString('Analisados / universo', $src);
        $this->assertStringContainsString('meta.analyzed', $src);
        $this->assertStringContainsString('meta.universe', $src);

        $ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AccountXRayController.php');
        $this->assertStringContainsString("max_items'] ?? 400", $ctrl);
    }
}
