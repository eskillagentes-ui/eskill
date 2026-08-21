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
