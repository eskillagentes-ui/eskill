<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use PHPUnit\Framework\TestCase;

final class PregaoQaControllerRoutesContractTest extends TestCase
{
    public function testRoutesAndControllerExposeAuthenticatedTenantBoundQaEndpoints(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 3) . '/app/Routes/api/pregao.php');
        $controller = file_get_contents(dirname(__DIR__, 3) . '/app/Controllers/PregaoController.php');
        $index = file_get_contents(dirname(__DIR__, 3) . '/public/index.php');
        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertIsString($index);

        self::assertStringContainsString("->post('api/pregao/qa/run'", $routes);
        self::assertStringContainsString("->get('qa/live/{runId}'", $routes);
        self::assertStringContainsString("->get('qa/frame/{runId}'", $routes);
        foreach (['qaRun', 'qaLive', 'qaFrame'] as $method) {
            self::assertStringContainsString("public function {$method}", $controller);
        }
        self::assertStringContainsString('$this->requireAuthJson()', $controller);
        self::assertStringContainsString('$this->resolveAccountIdBoundary()', $controller);
        self::assertStringContainsString('loadAuthorizedRun($runId, $accountId)', $controller);
        self::assertStringContainsString('PregaoQaRunService::readLatestFrame($root, $runId)', $controller);
        self::assertStringContainsString('getimagesizefromstring($frame)', $controller);
        self::assertStringNotContainsString('readfile($frame)', $controller);
        self::assertStringNotContainsString('getimagesize($frame)', $controller);
        self::assertStringContainsString("in_array(\$_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE', 'PATCH'])", $index);
        self::assertStringContainsString('CsrfMiddleware', $index);
    }
}
