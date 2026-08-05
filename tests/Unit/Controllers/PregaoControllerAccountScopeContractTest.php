<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

final class PregaoControllerAccountScopeContractTest extends TestCase
{
    public function testControllerAutorizaContaERejeitaSseSemEscopo(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Controllers/PregaoController.php');
        self::assertIsString($source);
        self::assertStringContainsString('PregaoAccountAuthorizer', $source);
        self::assertStringContainsString('$this->userService->getUserAccounts()', $source);
        self::assertStringNotContainsString('return (int) $fromQuery;', $source);
        self::assertStringNotContainsString("['error' => \$e->getMessage()]", $source);
        self::assertStringContainsString("['reason' => 'snapshot_exception']", $source);
        $userService = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UserService.php');
        self::assertIsString($userService);
        $accountsStart = strpos($userService, 'public function getUserAccounts(): array');
        $accountsEnd = strpos($userService, '// ==================== PERMISSION', $accountsStart);
        self::assertIsInt($accountsStart);
        self::assertIsInt($accountsEnd);
        $accountsSource = substr($userService, $accountsStart, $accountsEnd - $accountsStart);
        self::assertStringNotContainsString('getMessage()', $accountsSource);
        self::assertStringContainsString("'reason' => 'account_list_unavailable'", $accountsSource);

        $indexStart = strpos($source, 'public function index(): void');
        $snapshotStart = strpos($source, 'public function snapshot(): void');
        self::assertIsInt($indexStart);
        self::assertIsInt($snapshotStart);
        $indexSource = substr($source, $indexStart, $snapshotStart - $indexStart);
        self::assertStringContainsString('if ($accountId === null)', $indexSource);
        self::assertStringContainsString('http_response_code(403)', $indexSource);

        $streamStart = strpos($source, 'public function stream(): void');
        $streamEnd = strpos($source, 'public function ticket(): void');
        self::assertIsInt($streamStart);
        self::assertIsInt($streamEnd);
        $streamSource = substr($source, $streamStart, $streamEnd - $streamStart);
        self::assertStringContainsString('if ($accountId === null)', $streamSource);
        self::assertLessThan(
            strpos($streamSource, 'new PregaoStreamService()'),
            strpos($streamSource, 'if ($accountId === null)')
        );
    }
}
