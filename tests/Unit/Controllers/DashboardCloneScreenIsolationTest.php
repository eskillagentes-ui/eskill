<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Controllers\DashboardController
 */
final class DashboardCloneScreenIsolationTest extends TestCase
{
    public function testCloneScreensDoNotListAllActiveAccounts(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Controllers/DashboardController.php');
        $this->assertStringContainsString('cloneScreenAccounts', $source);
        $this->assertStringContainsString('AccountScopeHelper::activeAccountId', $source);
        $this->assertStringContainsString("status = 'active' AND id = :id", $source);
        $this->assertStringNotContainsString(
            "FROM ml_accounts WHERE status = 'active' ORDER BY nickname, ml_user_id",
            $source
        );
    }
}
