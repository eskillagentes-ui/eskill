<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\AccountScopeHelper;
use PHPUnit\Framework\TestCase;

final class AccountScopeHelperTest extends TestCase
{
    public function testConstrainScopesDashboardQueryByAccountId(): void
    {
        $scoped = AccountScopeHelper::constrain('ml_account_id', 1335);

        $this->assertSame(' AND ml_account_id = :account_id', $scoped['sql']);
        $this->assertSame(['account_id' => 1335], $scoped['params']);
    }

    public function testConstrainRefusesUnscopedDashboardQuery(): void
    {
        $empty = AccountScopeHelper::constrain('account_id', null);
        $this->assertSame(' AND 1 = 0', $empty['sql']);
        $this->assertSame([], $empty['params']);

        $zero = AccountScopeHelper::constrain('account_id', 0);
        $this->assertSame(' AND 1 = 0', $zero['sql']);
    }

    public function testAnalyticsDashboardSummaryUsesAccountScopeHelper(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/AnalyticsService.php');
        $this->assertIsString($source);
        $start = strpos($source, 'public function getDashboardSummary');
        $this->assertIsInt($start);
        $method = substr($source, $start, 1800);
        $this->assertStringContainsString('AccountScopeHelper::constrain', $method);
        $this->assertStringContainsString('ml_account_id', $method);
    }
}
