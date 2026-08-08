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
        self::assertStringContainsString('$pregaoAccountId = $accountId ?? 0;', $indexSource);
        self::assertStringNotContainsString('http_response_code(403)', $indexSource);

        $snapshotEnd = strpos($source, 'public function events(): void', $snapshotStart);
        self::assertIsInt($snapshotEnd);
        $snapshotSource = substr($source, $snapshotStart, $snapshotEnd - $snapshotStart);
        self::assertStringContainsString('if ($accountId === null)', $snapshotSource);
        self::assertStringContainsString('http_response_code(403)', $snapshotSource);

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

    public function testEventsIncluiResolucaoDaContaNoBoundaryDeFiltroInvalido(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/PregaoController.php');
        self::assertIsString($source);

        $eventsStart = strpos($source, 'public function events(): void');
        $eventsEnd = strpos($source, 'public function stream(): void', $eventsStart);
        self::assertIsInt($eventsStart);
        self::assertIsInt($eventsEnd);
        $events = substr($source, $eventsStart, $eventsEnd - $eventsStart);

        self::assertLessThan(
            strpos($events, '$this->resolveAccountId()'),
            strpos($events, 'try {')
        );
        self::assertStringContainsString('catch (\\InvalidArgumentException)', $events);
        self::assertStringContainsString("\$this->request->getScalar('page')", $events);
    }

    public function testTodosEndpointsConvertemAccountIdArrayEmBadRequest(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Controllers/PregaoController.php');
        self::assertIsString($source);

        foreach (['index', 'snapshot', 'stream', 'ticket'] as $method) {
            $start = strpos($source, "public function {$method}(): void");
            self::assertIsInt($start);
            $window = substr($source, $start, 1800);
            self::assertStringContainsString('$this->resolveAccountIdBoundary(', $window, $method);
            self::assertStringContainsString('if ($accountId === false)', $window, $method);
        }

        $boundary = substr($source, (int) strpos($source, 'private function resolveAccountIdBoundary'));
        self::assertStringContainsString('catch (\\InvalidArgumentException)', $boundary);
        self::assertStringContainsString('http_response_code(400)', $boundary);
        self::assertStringContainsString("\$this->request->getScalar('account_id')", $source);
    }
}
