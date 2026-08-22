<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

final class PricingHistoryIsolationTest extends TestCase
{
    public function testHistoryViewDoesNotFallBackToAccountOne(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/Views/pricing/history.php'
        );
        $this->assertStringContainsString('SessionHelper::getActiveAccountId', $source);
        $this->assertStringNotContainsString('current_account_id', $source);
        $this->assertDoesNotMatchRegularExpression('/\?\?\s*1\b/', $source);
    }
}
