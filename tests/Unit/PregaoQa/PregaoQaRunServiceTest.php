<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaRunService;
use PHPUnit\Framework\TestCase;
use Redis;

final class PregaoQaRunServiceTest extends TestCase
{
    public function testStartRunCreatesUuidPrivateSignedManifestAndQueuesOnlyReference(): void
    {
        $stored = [];
        $queued = null;
        $redis = $this->createMock(Redis::class);
        $redis->method('setex')->willReturnCallback(static function (string $key, int $ttl, string $value) use (&$stored): bool {
            $stored[$key] = [$ttl, $value];
            return true;
        });
        $redis->method('lPush')->willReturnCallback(static function (string $key, mixed ...$values) use (&$queued): int {
            $queued = [$key, $values[0] ?? null];
            return 1;
        });

        $proof = new PregaoQaProof(str_repeat('k', 32));
        $service = new PregaoQaRunService($redis, $proof);
        $run = $service->startRun(1335, 77);

        self::assertMatchesRegularExpression(PregaoQaRunService::RUN_ID_PATTERN, $run['run_id']);
        self::assertSame(1335, $run['account_id']);
        self::assertArrayNotHasKey('signature', $run, 'resposta pública não pode expor assinatura privada');
        self::assertSame(PregaoQaRunService::QUEUE_KEY, $queued[0]);
        self::assertSame(['run_id' => $run['run_id']], json_decode((string) $queued[1], true));

        $manifestKey = PregaoQaRunService::manifestKey($run['run_id']);
        self::assertArrayHasKey($manifestKey, $stored);
        $manifest = json_decode($stored[$manifestKey][1], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($proof->verifyManifest($manifest));
        self::assertSame(1335, $manifest['account_id']);
        self::assertSame(77, $manifest['user_id']);
        self::assertSame($run['manifest_hash'], $manifest['manifest_hash']);
    }

    public function testClaimUsesNxLockAndFailsClosedWhenLockAlreadyExists(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('rPop')->with(PregaoQaRunService::QUEUE_KEY)
            ->willReturn(json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR));
        $redis->expects(self::once())->method('set')
            ->with(PregaoQaRunService::lockKey($runId), self::isType('string'), ['nx', 'ex' => 900])
            ->willReturn(false);
        $redis->expects(self::never())->method('get');

        $service = new PregaoQaRunService($redis, new PregaoQaProof(str_repeat('k', 32)));
        self::assertNull($service->claimNext());
    }

    public function testManifestAuthorizationIsTenantBoundAndFailsClosed(): void
    {
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $manifest = $proof->signManifest([
            'run_id' => '123e4567-e89b-42d3-a456-426614174000',
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => '2026-08-05T12:00:00+00:00',
            'expires_at' => '2099-08-05T12:15:00+00:00',
        ]);
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn(json_encode($manifest, JSON_THROW_ON_ERROR));
        $service = new PregaoQaRunService($redis, $proof);

        self::assertNotNull($service->loadAuthorizedRun($manifest['run_id'], 1335));
        self::assertNull($service->loadAuthorizedRun($manifest['run_id'], 9999));
        $manifest['signature'] = str_repeat('0', 64);
        $redis2 = $this->createMock(Redis::class);
        $redis2->method('get')->willReturn(json_encode($manifest, JSON_THROW_ON_ERROR));
        self::assertNull((new PregaoQaRunService($redis2, $proof))->loadAuthorizedRun($manifest['run_id'], 1335));
    }
}
