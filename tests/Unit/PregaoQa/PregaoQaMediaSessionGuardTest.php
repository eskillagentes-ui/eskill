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

    public function testGlobalGuardAllowsOnlySafeMethodsAndPregaoQaAllowlist(): void
    {
        self::assertTrue(\App\Security\PregaoQaReadOnlyGuard::isAllowed('GET', '/dashboard/pregao'));
        self::assertTrue(\App\Security\PregaoQaReadOnlyGuard::isAllowed('HEAD', '/qa/frame/123e4567-e89b-42d3-a456-426614174000'));
        self::assertTrue(\App\Security\PregaoQaReadOnlyGuard::isAllowed('OPTIONS', '/api/pregao/snapshot'));
        self::assertFalse(\App\Security\PregaoQaReadOnlyGuard::isAllowed('POST', '/api/pregao/qa/run'));
        self::assertFalse(\App\Security\PregaoQaReadOnlyGuard::isAllowed('GET', '/api/orders'));
        self::assertFalse(\App\Security\PregaoQaReadOnlyGuard::isAllowed('GET', '/dashboard/users'));

        $index = file_get_contents(dirname(__DIR__, 3) . '/public/index.php');
        self::assertIsString($index);
        self::assertStringContainsString("!empty(\$_SESSION['qa_read_only'])", $index);
        self::assertStringContainsString('PregaoQaReadOnlyGuard::enforce', $index);
    }
}
