<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaRunService;
use App\Services\Pregao\PregaoQaSessionService;
use PHPUnit\Framework\TestCase;
use Redis;

final class PregaoQaMediaSessionGuardTest extends TestCase
{
    public function testFramePathIsUuidOnlyAndCannotTraversePrivateRoot(): void
    {
        $root = sys_get_temp_dir() . '/pregao-qa-media-' . bin2hex(random_bytes(4));
        mkdir($root, 0700, true);
        try {
            $runId = '123e4567-e89b-42d3-a456-426614174000';
            self::assertSame($root . '/' . $runId . '/latest.png', PregaoQaRunService::framePath($root, $runId));
            foreach (['../etc/passwd', '%2e%2e', $runId . '/../../secret', 'not-a-uuid'] as $hostile) {
                try {
                    PregaoQaRunService::framePath($root, $hostile);
                    self::fail('traversal deveria ser rejeitado');
                } catch (\InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            @rmdir($root);
        }
    }

    public function testRetentionPurgesOnlyExpiredUuidDirectoriesWithoutFollowingSymlinks(): void
    {
        $root = sys_get_temp_dir() . '/pregao-qa-retention-' . bin2hex(random_bytes(4));
        $outside = sys_get_temp_dir() . '/pregao-qa-outside-' . bin2hex(random_bytes(4));
        mkdir($root, 0700, true);
        mkdir($outside, 0700, true);
        $now = 1800000000;
        $stale = '123e4567-e89b-42d3-a456-426614174000';
        $fresh = '223e4567-e89b-42d3-a456-426614174000';
        $unexpected = '323e4567-e89b-42d3-a456-426614174000';
        $linked = '423e4567-e89b-42d3-a456-426614174000';
        foreach ([$stale, $fresh, $unexpected] as $runId) {
            mkdir($root . '/' . $runId, 0700);
        }
        file_put_contents($root . '/' . $stale . '/latest.png', 'stale');
        file_put_contents($root . '/' . $fresh . '/latest.png', 'fresh');
        file_put_contents($root . '/' . $unexpected . '/unexpected.txt', 'preserve');
        file_put_contents($outside . '/sentinel.txt', 'outside');
        symlink($outside, $root . '/' . $linked);
        touch($root . '/' . $stale . '/latest.png', $now - 90000);
        touch($root . '/' . $stale, $now - 90000);
        touch($root . '/' . $unexpected, $now - 90000);
        touch($root . '/' . $fresh . '/latest.png', $now - 60);
        touch($root . '/' . $fresh, $now - 60);

        try {
            self::assertSame([$stale], PregaoQaRunService::purgeExpiredFrames($root, $now));
            self::assertDirectoryDoesNotExist($root . '/' . $stale);
            self::assertFileExists($root . '/' . $fresh . '/latest.png');
            self::assertFileExists($root . '/' . $unexpected . '/unexpected.txt');
            self::assertTrue(is_link($root . '/' . $linked));
            self::assertFileExists($outside . '/sentinel.txt');
        } finally {
            @unlink($root . '/' . $linked);
            @unlink($root . '/' . $fresh . '/latest.png');
            @unlink($root . '/' . $unexpected . '/unexpected.txt');
            @rmdir($root . '/' . $fresh);
            @rmdir($root . '/' . $unexpected);
            @rmdir($root);
            @unlink($outside . '/sentinel.txt');
            @rmdir($outside);
        }
    }

    public function testWorkerSessionIsEphemeralReadOnlyAndDestroyed(): void
    {
        $saved = [];
        $deleted = [];
        $redis = $this->createMock(Redis::class);
        $redis->method('setex')->willReturnCallback(static function (string $key, int $ttl, string $value) use (&$saved): bool {
            $saved = [$key, $ttl, $value];
            return true;
        });
        $redis->method('del')->willReturnCallback(static function (string ...$keys) use (&$deleted): int {
            $deleted = $keys;
            return 1;
        });
        $sessions = new PregaoQaSessionService($redis, 'PHPREDIS_SESSION:');
        $id = $sessions->create(77, 1335, 300);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{48}\z/D', $id);
        self::assertSame('PHPREDIS_SESSION:' . $id, $saved[0]);
        self::assertSame(300, $saved[1]);
        self::assertStringContainsString('qa_read_only|b:1;', $saved[2]);
        self::assertStringContainsString('active_ml_account_id|i:1335;', $saved[2]);
        self::assertStringNotContainsString('password', $saved[2]);

        $sessions->destroy($id);
        self::assertSame(['PHPREDIS_SESSION:' . $id], $deleted);
    }

    public function testGlobalGuardAllowsOnlyDashboardRuntimeReadsObservedInTheRealLayout(): void
    {
        foreach ([
            '/dashboard/pregao',
            '/api/pregao/snapshot?account_id=1335',
            '/api/pregao/events?page=1',
            '/api/pregao/stream',
            '/api/pregao/ticket',
            '/api/menu-items',
            '/api/dashboard/recent-activity',
            '/api/dashboard/recent-documents',
            '/api/dashboard/notifications',
            '/css/dashboard-modern.css?v=1',
            '/css/theme.css?v=1',
            '/css/components.css?v=1',
            '/css/pregao.css?v=7',
            '/js/csrf-helper.js',
            '/js/api-client.js?v=1',
            '/js/ml-integration-preflight.js?v=1',
            '/js/layout-modern-init.js?v=1',
            '/js/dashboard-modern.js?v=1',
            '/js/pregao-chart-layout.js?v=1',
            '/js/pregao-qa.js?v=1',
            '/js/pregao.js?v=45',
            '/js/pregao-events.js?v=1',
        ] as $path) {
            self::assertTrue(\App\Security\PregaoQaReadOnlyGuard::isAllowed('GET', $path), $path);
        }
        self::assertTrue(\App\Security\PregaoQaReadOnlyGuard::isAllowed(
            'HEAD',
            '/qa/frame/123e4567-e89b-42d3-a456-426614174000'
        ));
    }

    public function testGlobalGuardBlocksEveryMutatingMethodAndReadOnlyActionRoutes(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'CONNECT', 'TRACE', 'OPTIONS'] as $method) {
            self::assertFalse(\App\Security\PregaoQaReadOnlyGuard::isAllowed($method, '/dashboard/pregao'), $method);
        }
        foreach ([
            '/api/pregao/qa/run',
            '/api/dashboard/switch-account',
            '/api/notifications/realtime/read-all',
            '/auth/logout',
            '/api/orders',
            '/dashboard/users',
            '/js/../api/orders',
        ] as $path) {
            self::assertFalse(\App\Security\PregaoQaReadOnlyGuard::isAllowed('GET', $path), $path);
        }

        $index = file_get_contents(dirname(__DIR__, 3) . '/public/index.php');
        self::assertIsString($index);
        self::assertStringContainsString("!empty(\$_SESSION['qa_read_only'])", $index);
        self::assertStringContainsString('PregaoQaReadOnlyGuard::enforce', $index);
    }

    public function testPregaoBootHasCspNonceAndLiveFrameSandboxAllowsScriptsWithoutSameOrigin(): void
    {
        $view = file_get_contents(dirname(__DIR__, 3) . '/app/Views/dashboard/pregao.php');
        self::assertIsString($view);
        self::assertMatchesRegularExpression('/<script\\s+nonce="<\\?=\\s*htmlspecialchars\\(/', $view);
        self::assertStringContainsString('sandbox="allow-scripts"', $view);
        self::assertStringNotContainsString('allow-same-origin', $view);
    }
}
