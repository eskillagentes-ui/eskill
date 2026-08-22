<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Controllers\RealTimeNotificationController
 */
final class RealTimeNotificationControllerIsolationTest extends TestCase
{
    public function testUsesSessionActiveAccountNotUserCurrentAccountId(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/Controllers/RealTimeNotificationController.php'
        );
        $this->assertStringContainsString('SessionHelper::getActiveAccountId', $source);
        $this->assertStringNotContainsString("\$user['current_account_id']", $source);
        $this->assertStringNotContainsString("\$user['id']", $source);
        $this->assertSame(6, substr_count($source, 'requireActiveAccountId()'));
    }
}
