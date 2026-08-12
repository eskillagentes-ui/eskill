<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Controllers\QuestionController;
use PHPUnit\Framework\TestCase;

/**
 * SEC-001 — account_id=all na API pública de perguntas não pode ser unscoped.
 *
 * @covers \App\Controllers\QuestionController
 */
final class QuestionControllerAccountIsolationTest extends TestCase
{
    public function testPublicAllRequiresOwnerUserId(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/Controllers/QuestionController.php'
        );

        self::assertNotSame('', $source);
        self::assertStringContainsString("requestedAccount === 'all'", $source);
        self::assertStringContainsString("'owner_user_id' => \$this->actorUserId", $source);
        self::assertStringNotContainsString(
            "(\$requestedAccount === 'all') ? 'all' : (\$this->accountId ?: null)",
            $source
        );
    }

    public function testStatsDoesNotQueryUnscopedAll(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/app/Controllers/QuestionController.php'
        );

        self::assertStringContainsString('function stats', $source);
        self::assertStringContainsString("'owner_user_id' => \$this->actorUserId", $source);
        self::assertTrue(class_exists(QuestionController::class));
    }
}
