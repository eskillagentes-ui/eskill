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
                self::assertNull(PregaoQaRunService::readLatestFrame($root, $hostile));
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

    public function testRetainAndReadLatestFrameUseARegularFileInsideTheRealRoot(): void
    {
        $base = sys_get_temp_dir() . '/pregao-qa-regular-' . bin2hex(random_bytes(4));
        $root = $base . '/private';
        $sourceDirectory = $base . '/source';
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        mkdir($root, 0700, true);
        mkdir($sourceDirectory, 0700);
        $source = $sourceDirectory . '/latest.png';
        file_put_contents($source, 'regular-frame');

        try {
            $retained = PregaoQaRunService::retainLatestFrame($source, $root, $runId);
            self::assertSame($root . '/' . $runId . '/latest.png', $retained);
            self::assertFalse(is_link($retained));
            self::assertSame('regular-frame', PregaoQaRunService::readLatestFrame($root, $runId));
        } finally {
            @unlink($root . '/' . $runId . '/latest.png');
            @rmdir($root . '/' . $runId);
            @unlink($source);
            @rmdir($sourceDirectory);
            @rmdir($root);
            @rmdir($base);
        }
    }

    public function testRetentionRejectsHardlinkedSource(): void
    {
        $base = sys_get_temp_dir() . '/pregao-qa-source-hardlink-' . bin2hex(random_bytes(4));
        $root = $base . '/private';
        $sourceDirectory = $base . '/source';
        $source = $sourceDirectory . '/latest.png';
        $alias = $sourceDirectory . '/alias.png';
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        mkdir($root, 0700, true);
        mkdir($sourceDirectory, 0700);
        file_put_contents($source, 'hardlinked-source');

        try {
            self::assertTrue(link($source, $alias));
            self::assertGreaterThan(1, (int) (lstat($source)['nlink'] ?? 0));
            $this->expectException(\InvalidArgumentException::class);
            PregaoQaRunService::retainLatestFrame($source, $root, $runId);
        } finally {
            @unlink($alias);
            @unlink($source);
            @rmdir($sourceDirectory);
            @rmdir($root . '/' . $runId);
            @rmdir($root);
            @rmdir($base);
        }
    }

    public function testReadRejectsHardlinkedLatestFrame(): void
    {
        $base = sys_get_temp_dir() . '/pregao-qa-latest-hardlink-' . bin2hex(random_bytes(4));
        $root = $base . '/private';
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $directory = $root . '/' . $runId;
        $frame = $directory . '/latest.png';
        $alias = $directory . '/alias.png';
        mkdir($directory, 0700, true);
        file_put_contents($frame, 'hardlinked-latest');

        try {
            self::assertTrue(link($frame, $alias));
            self::assertGreaterThan(1, (int) (lstat($frame)['nlink'] ?? 0));
            self::assertNull(PregaoQaRunService::readLatestFrame($root, $runId));
        } finally {
            @unlink($alias);
            @unlink($frame);
            @rmdir($directory);
            @rmdir($root);
            @rmdir($base);
        }
    }

    public function testOversizedLatestFrameReturnsNullWithoutExhaustingSixteenMegabytes(): void
    {
        $base = sys_get_temp_dir() . '/pregao-qa-oversized-' . bin2hex(random_bytes(4));
        $root = $base . '/private';
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $directory = $root . '/' . $runId;
        $frame = $directory . '/latest.png';
        mkdir($directory, 0700, true);
        $handle = fopen($frame, 'x+b');
        self::assertIsResource($handle);
        self::assertTrue(ftruncate($handle, 64 * 1024 * 1024));
        fclose($handle);

        try {
            self::assertSame(4 * 1024 * 1024, PregaoQaRunService::MAX_FRAME_BYTES);
            [$exitCode, $stdout, $stderr] = $this->runFrameReadInSubprocess($root, $runId);
            self::assertSame(0, $exitCode, $stderr);
            self::assertSame("NULL\n", $stdout);
        } finally {
            @unlink($frame);
            @rmdir($directory);
            @rmdir($root);
            @rmdir($base);
        }
    }

    public function testGrowthAfterValidatedStatIsRejectedWithoutUnboundedAllocation(): void
    {
        $base = sys_get_temp_dir() . '/pregao-qa-growing-' . bin2hex(random_bytes(4));
        $frame = $base . '/latest.png';
        mkdir($base, 0700, true);
        file_put_contents($frame, 'small-frame');
        $servicePath = dirname(__DIR__, 3) . '/app/Services/Pregao/PregaoQaRunService.php';
        $code = 'require ' . var_export($servicePath, true) . ';'
            . '$handle=fopen(' . var_export($frame, true) . ',"rb");'
            . '$validated=fstat($handle);'
            . '$writer=fopen(' . var_export($frame, true) . ',"c+b");'
            . 'ftruncate($writer,64*1024*1024);fclose($writer);'
            . '$method=new ReflectionMethod(\\App\\Services\\Pregao\\PregaoQaRunService::class,"readBoundedVerifiedHandle");'
            . '$result=$method->invoke(null,$handle,$validated);fclose($handle);'
            . 'echo $result===null?"NULL\\n":"BYTES=".strlen($result)."\\n";';

        try {
            [$exitCode, $stdout, $stderr] = $this->runPhpInSubprocess($code);
            self::assertSame(0, $exitCode, $stderr);
            self::assertSame("NULL\n", $stdout);
        } finally {
            @unlink($frame);
            @rmdir($base);
        }
    }

    public function testRootSymlinkIsRejectedForReadAndRetention(): void
    {
        $base = sys_get_temp_dir() . '/pregao-qa-root-link-' . bin2hex(random_bytes(4));
        $outside = $base . '/outside';
        $root = $base . '/private-link';
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        mkdir($outside . '/' . $runId, 0700, true);
        file_put_contents($outside . '/' . $runId . '/latest.png', 'outside');
        symlink($outside, $root);
        $source = $base . '/latest.png';
        file_put_contents($source, 'source');

        try {
            self::assertNull(PregaoQaRunService::readLatestFrame($root, $runId));
            try {
                PregaoQaRunService::retainLatestFrame($source, $root, $runId);
                self::fail('raiz symlink deveria ser rejeitada');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            self::assertSame('outside', file_get_contents($outside . '/' . $runId . '/latest.png'));
        } finally {
            @unlink($root);
            @unlink($source);
            @unlink($outside . '/' . $runId . '/latest.png');
            @rmdir($outside . '/' . $runId);
            @rmdir($outside);
            @rmdir($base);
        }
    }

    public function testRunDirectorySymlinkIsRejectedForReadAndRetention(): void
    {
        $base = sys_get_temp_dir() . '/pregao-qa-run-link-' . bin2hex(random_bytes(4));
        $root = $base . '/private';
        $outside = $base . '/outside';
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        mkdir($root, 0700, true);
        mkdir($outside, 0700);
        file_put_contents($outside . '/latest.png', 'outside');
        symlink($outside, $root . '/' . $runId);
        $source = $base . '/latest.png';
        file_put_contents($source, 'source');

        try {
            self::assertNull(PregaoQaRunService::readLatestFrame($root, $runId));
            try {
                PregaoQaRunService::retainLatestFrame($source, $root, $runId);
                self::fail('diretório de run symlink deveria ser rejeitado');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            self::assertSame('outside', file_get_contents($outside . '/latest.png'));
        } finally {
            @unlink($root . '/' . $runId);
            @unlink($source);
            @unlink($outside . '/latest.png');
            @rmdir($outside);
            @rmdir($root);
            @rmdir($base);
        }
    }

    public function testFrameAndSourceSymlinksAreRejectedAsNonRegularFiles(): void
    {
        $base = sys_get_temp_dir() . '/pregao-qa-file-link-' . bin2hex(random_bytes(4));
        $root = $base . '/private';
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        mkdir($root . '/' . $runId, 0700, true);
        $outside = $base . '/outside.png';
        file_put_contents($outside, 'outside');
        symlink($outside, $root . '/' . $runId . '/latest.png');
        $sourceLink = $base . '/latest.png';
        symlink($outside, $sourceLink);
        $regularSourceDirectory = $base . '/source';
        mkdir($regularSourceDirectory, 0700);
        $regularSource = $regularSourceDirectory . '/latest.png';
        file_put_contents($regularSource, 'replacement');

        try {
            self::assertNull(PregaoQaRunService::readLatestFrame($root, $runId));
            try {
                PregaoQaRunService::retainLatestFrame($sourceLink, $root, $runId);
                self::fail('fonte symlink deveria ser rejeitada');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            try {
                PregaoQaRunService::retainLatestFrame($regularSource, $root, $runId);
                self::fail('destino symlink deveria ser rejeitado');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            self::assertSame('outside', file_get_contents($outside));
        } finally {
            @unlink($sourceLink);
            @unlink($regularSource);
            @rmdir($regularSourceDirectory);
            @unlink($root . '/' . $runId . '/latest.png');
            @unlink($outside);
            @rmdir($root . '/' . $runId);
            @rmdir($root);
            @rmdir($base);
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

    /** @return array{0:int,1:string,2:string} */
    private function runFrameReadInSubprocess(string $root, string $runId): array
    {
        $servicePath = dirname(__DIR__, 3) . '/app/Services/Pregao/PregaoQaRunService.php';
        $code = 'require ' . var_export($servicePath, true) . ';'
            . '$result=\\App\\Services\\Pregao\\PregaoQaRunService::readLatestFrame('
            . var_export($root, true) . ',' . var_export($runId, true) . ');'
            . 'echo $result===null?"NULL\\n":"BYTES=".strlen($result)."\\n";';
        return $this->runPhpInSubprocess($code);
    }

    /** @return array{0:int,1:string,2:string} */
    private function runPhpInSubprocess(string $code): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=16M', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        return [$exitCode, $stdout, $stderr];
    }
}
