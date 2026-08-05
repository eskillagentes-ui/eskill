<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaRunService;
use PHPUnit\Framework\TestCase;
use Redis;

final class PregaoQaRunServiceTest extends TestCase
{
    public function testAdmissionLimitsAreExplicitAndReasonable(): void
    {
        self::assertSame(60, PregaoQaRunService::COOLDOWN_TTL_SECONDS);
        self::assertSame(8, PregaoQaRunService::MAX_QUEUE_DEPTH);
    }

    public function testStartRunCreatesUuidPrivateSignedManifestAndAtomicallyQueuesOnlyReference(): void
    {
        $stored = [];
        $admission = null;
        $enqueue = null;
        $redis = $this->createMock(Redis::class);
        $redis->method('setex')->willReturnCallback(static function (string $key, int $ttl, string $value) use (&$stored): bool {
            $stored[$key] = [$ttl, $value];
            return true;
        });
        $redis->method('eval')->willReturnCallback(
            static function (string $script, array $arguments, int $keyCount) use (&$admission, &$enqueue): int {
                if (str_contains($script, 'LLEN')) {
                    $enqueue = [$script, $arguments, $keyCount];
                    return 1;
                }
                $admission = [$script, $arguments, $keyCount];
                return 1;
            }
        );
        $redis->expects(self::never())->method('lPush');

        $proof = new PregaoQaProof(str_repeat('k', 32));
        $service = new PregaoQaRunService($redis, $proof);
        $run = $service->startRun(1335, 77);

        self::assertMatchesRegularExpression(PregaoQaRunService::RUN_ID_PATTERN, $run['run_id']);
        self::assertSame(1335, $run['account_id']);
        self::assertArrayNotHasKey('signature', $run, 'resposta pública não pode expor assinatura privada');
        self::assertSame([
            PregaoQaRunService::activeKey(1335),
            'pregao:qa:cooldown:account:1335',
            'pregao:qa:cooldown:user:77',
            $run['run_id'],
            PregaoQaRunService::RUN_TTL_SECONDS,
        ], $admission[1]);
        self::assertSame(3, $admission[2]);
        self::assertStringContainsString('EXISTS', $admission[0]);

        self::assertSame(PregaoQaRunService::QUEUE_KEY, $enqueue[1][0]);
        self::assertSame(PregaoQaRunService::PENDING_KEY, $enqueue[1][1]);
        self::assertSame(['run_id' => $run['run_id']], json_decode((string) $enqueue[1][2], true));
        self::assertSame(PregaoQaRunService::MAX_QUEUE_DEPTH, $enqueue[1][3]);
        self::assertSame(2, $enqueue[2]);

        $manifestKey = PregaoQaRunService::manifestKey($run['run_id']);
        self::assertArrayHasKey($manifestKey, $stored);
        $manifest = json_decode($stored[$manifestKey][1], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($proof->verifyManifest($manifest));
        self::assertSame(1335, $manifest['account_id']);
        self::assertSame(77, $manifest['user_id']);
        self::assertSame($run['manifest_hash'], $manifest['manifest_hash']);
        self::assertSame('pregao:qa:active:1335', PregaoQaRunService::activeKey(1335));
    }

    public function testStartRunAtomicallyRejectsActiveOrCoolingAccountUserWithoutPersisting(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('eval')
            ->with(
                self::stringContains('EXISTS'),
                self::callback(static function (array $arguments): bool {
                    return count($arguments) === 5
                        && $arguments[0] === PregaoQaRunService::activeKey(1335)
                        && $arguments[1] === 'pregao:qa:cooldown:account:1335'
                        && $arguments[2] === 'pregao:qa:cooldown:user:77'
                        && is_string($arguments[3])
                        && preg_match(PregaoQaRunService::RUN_ID_PATTERN, $arguments[3]) === 1
                        && $arguments[4] === PregaoQaRunService::RUN_TTL_SECONDS;
                }),
                3
            )
            ->willReturn(0);
        $redis->expects(self::never())->method('setex');
        $redis->expects(self::never())->method('lPush');

        $service = new PregaoQaRunService($redis, new PregaoQaProof(str_repeat('k', 32)));
        $this->expectException(\DomainException::class);
        $service->startRun(1335, 77);
    }

    public function testStartRunFailsClosedWhenAtomicQueueDepthIsFull(): void
    {
        $evalCalls = 0;
        $redis = $this->createMock(Redis::class);
        $redis->method('setex')->willReturn(true);
        $redis->expects(self::once())->method('del')
            ->with(self::isType('string'), self::isType('string'));
        $redis->method('eval')->willReturnCallback(static function (string $script) use (&$evalCalls): int {
            $evalCalls++;
            if (str_contains($script, 'LLEN')) {
                return 0;
            }
            return 1;
        });
        $redis->expects(self::never())->method('lPush');

        $service = new PregaoQaRunService($redis, new PregaoQaProof(str_repeat('k', 32)));
        try {
            $service->startRun(1335, 77);
            self::fail('fila cheia deveria falhar fechada');
        } catch (\RuntimeException $exception) {
            self::assertSame('Falha ao enfileirar QA', $exception->getMessage());
        }
        self::assertSame(3, $evalCalls, 'admission, enqueue e release ativo devem ser atômicos');
    }

    public function testClaimAtomicallyMovesQueueToPendingAndReturnsExactAckReference(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $raw = json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR);
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => '2026-08-05T12:00:00+00:00',
            'expires_at' => '2099-08-05T12:15:00+00:00',
        ]);
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('rPopLPush')
            ->with(PregaoQaRunService::QUEUE_KEY, PregaoQaRunService::PENDING_KEY)
            ->willReturn($raw);
        $redis->expects(self::once())->method('set')
            ->with(PregaoQaRunService::lockKey($runId), self::isType('string'), ['nx', 'ex' => PregaoQaRunService::LOCK_TTL_SECONDS])
            ->willReturn(true);
        $redis->expects(self::once())->method('get')
            ->with(PregaoQaRunService::manifestKey($runId))
            ->willReturn(json_encode($manifest, JSON_THROW_ON_ERROR));

        $claim = (new PregaoQaRunService($redis, $proof))->claimNext();
        self::assertIsArray($claim);
        self::assertSame($raw, $claim['pending_job']);
        self::assertSame($runId, $claim['manifest']['run_id']);
    }

    public function testClaimUsesNxLockAndLeavesPendingUntouchedWhenLockAlreadyExists(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $raw = json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR);
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('rPopLPush')
            ->with(PregaoQaRunService::QUEUE_KEY, PregaoQaRunService::PENDING_KEY)
            ->willReturn($raw);
        $redis->expects(self::once())->method('set')
            ->with(PregaoQaRunService::lockKey($runId), self::isType('string'), ['nx', 'ex' => PregaoQaRunService::LOCK_TTL_SECONDS])
            ->willReturn(false);
        $redis->expects(self::never())->method('get');
        $redis->expects(self::never())->method('lRem');

        $service = new PregaoQaRunService($redis, new PregaoQaProof(str_repeat('k', 32)));
        self::assertNull($service->claimNext());
    }

    public function testAckRemovesOnlyTheClaimedPendingReference(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $raw = json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR);
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('lRem')
            ->with(PregaoQaRunService::PENDING_KEY, $raw, 1)
            ->willReturn(1);
        $service = new PregaoQaRunService($redis, new PregaoQaProof(str_repeat('k', 32)));
        self::assertTrue($service->ackPending($runId, $raw));
    }

    public function testRecoveryNeverRequeuesPendingJobWhileItsNxLockExists(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $raw = json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR);
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('lRange')
            ->with(PregaoQaRunService::PENDING_KEY, 0, -1)
            ->willReturn([$raw]);
        $redis->expects(self::once())->method('get')
            ->with(PregaoQaRunService::lockKey($runId))
            ->willReturn('active-lock-token');
        $redis->expects(self::never())->method('eval');
        $redis->expects(self::never())->method('lPush');
        $redis->expects(self::never())->method('lRem');

        $service = new PregaoQaRunService($redis, new PregaoQaProof(str_repeat('k', 32)));
        self::assertSame(0, $service->recoverPending());
    }

    public function testRecoveryAtomicallyRequeuesCrashPendingWithoutLock(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $raw = json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR);
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => '2026-08-05T12:00:00+00:00',
            'expires_at' => '2099-08-05T12:15:00+00:00',
        ]);
        $redis = $this->createMock(Redis::class);
        $redis->method('lRange')->willReturn([$raw]);
        $redis->method('get')->willReturnCallback(static function (string $key) use ($runId, $manifest): string|false {
            return match ($key) {
                PregaoQaRunService::lockKey($runId) => false,
                PregaoQaRunService::stateKey($runId) => json_encode(['status' => 'running'], JSON_THROW_ON_ERROR),
                PregaoQaRunService::manifestKey($runId) => json_encode($manifest, JSON_THROW_ON_ERROR),
                default => false,
            };
        });
        $redis->expects(self::once())->method('eval')
            ->with(
                self::stringContains('LREM'),
                [PregaoQaRunService::PENDING_KEY, PregaoQaRunService::QUEUE_KEY, PregaoQaRunService::lockKey($runId), $raw],
                3
            )
            ->willReturn(1);

        $service = new PregaoQaRunService($redis, $proof);
        self::assertSame(1, $service->recoverPending());
    }

    public function testTerminalStateRetainsEvidenceForOneDay(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => '2026-08-05T12:00:00+00:00',
            'expires_at' => '2099-08-05T12:15:00+00:00',
        ]);
        $state = json_encode([
            'run_id' => $runId,
            'account_id' => 1335,
            'status' => 'running',
            'sequence' => 4,
        ], JSON_THROW_ON_ERROR);
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('get')
            ->with(PregaoQaRunService::stateKey($runId))
            ->willReturn($state);
        $redis->expects(self::once())->method('setex')
            ->with(PregaoQaRunService::stateKey($runId), PregaoQaRunService::EVIDENCE_TTL_SECONDS, self::isType('string'))
            ->willReturn(true);
        $redis->expects(self::once())->method('expire')
            ->with(PregaoQaRunService::manifestKey($runId), PregaoQaRunService::EVIDENCE_TTL_SECONDS)
            ->willReturn(true);
        $redis->expects(self::once())->method('eval')
            ->with(
                self::stringContains('SETEX'),
                [
                    'pregao:qa:cooldown:account:1335',
                    'pregao:qa:cooldown:user:77',
                    PregaoQaRunService::COOLDOWN_TTL_SECONDS,
                ],
                2
            )
            ->willReturn(1);
        $service = new PregaoQaRunService($redis, $proof);
        $service->updateState($manifest, [
            'run_id' => $runId,
            'sequence' => 5,
            'step' => 'console_http',
            'result' => 'passed',
            'screenshot' => 'latest.png',
            'cursor' => ['x' => 10, 'y' => 20],
            'observed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ]);
    }

    public function testStateProgressionRejectsRepeatedSequence(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => '2026-08-05T12:00:00+00:00',
            'expires_at' => '2099-08-05T12:15:00+00:00',
        ]);
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn(json_encode([
            'run_id' => $runId,
            'account_id' => 1335,
            'status' => 'running',
            'sequence' => 1,
        ], JSON_THROW_ON_ERROR));
        $redis->expects(self::never())->method('setex');
        $service = new PregaoQaRunService($redis, $proof);
        $this->expectException(\InvalidArgumentException::class);
        $service->updateState($manifest, [
            'run_id' => $runId,
            'sequence' => 1,
            'step' => 'dashboard',
            'result' => 'running',
            'screenshot' => 'latest.png',
            'cursor' => ['x' => 10, 'y' => 20],
            'observed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ]);
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
