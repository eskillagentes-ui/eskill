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
        $redis->method('set')->willReturn(true);
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
        self::assertSame('pregao:qa:active:1335', PregaoQaRunService::activeKey(1335));
    }

    public function testStartRunRejectsSecondActiveRunForSameAccount(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('set')
            ->with(PregaoQaRunService::activeKey(1335), self::isType('string'), ['nx', 'ex' => PregaoQaRunService::RUN_TTL_SECONDS])
            ->willReturn(false);
        $redis->expects(self::never())->method('setex');
        $redis->expects(self::never())->method('lPush');

        $service = new PregaoQaRunService($redis, new PregaoQaProof(str_repeat('k', 32)));
        $this->expectException(\DomainException::class);
        $service->startRun(1335, 77);
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
        $terminalState = null;
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('eval')
            ->willReturnCallback(static function (string $lua, array $args, int $keyCount) use (&$terminalState): int {
                self::assertSame(1, $keyCount);
                self::assertSame((string) PregaoQaRunService::EVIDENCE_TTL_SECONDS, $args[6]);
                $terminalState = json_decode((string) $args[7], true, 512, JSON_THROW_ON_ERROR);
                return 1;
            });
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
        self::assertSame('passed', $terminalState['status']);
        self::assertSame(5, $terminalState['sequence']);
        self::assertSame($manifest, $terminalState['manifest']);
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

    public function testStateProgressionUsesSingleAtomicLuaCasWithoutGetSetWindow(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $now->modify('-1 minute')->format(DATE_ATOM),
            'expires_at' => $now->modify('+14 minutes')->format(DATE_ATOM),
        ]);
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::never())->method('get');
        $redis->expects(self::once())->method('eval')
            ->with(
                self::logicalAnd(self::stringContains('cjson.decode'), self::stringContains('SETEX')),
                self::callback(static fn (array $args): bool => $args[0] === PregaoQaRunService::stateKey($runId)),
                1
            )
            ->willReturn(1);

        (new PregaoQaRunService($redis, $proof))->updateState($manifest, [
            'run_id' => $runId,
            'sequence' => 1,
            'step' => 'dashboard',
            'result' => 'running',
            'screenshot' => null,
            'cursor' => null,
            'observed_at' => $now->format(DATE_ATOM),
        ]);
    }

    public function testConcurrentCasLoserCannotOverwriteTheWinningProgression(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $now->modify('-1 minute')->format(DATE_ATOM),
            'expires_at' => $now->modify('+14 minutes')->format(DATE_ATOM),
        ]);
        $redis = $this->createMock(Redis::class);
        $redis->method('eval')->willReturn(0);
        $redis->expects(self::never())->method('get');
        $redis->expects(self::never())->method('setex');

        $this->expectException(\InvalidArgumentException::class);
        (new PregaoQaRunService($redis, $proof))->updateState($manifest, [
            'run_id' => $runId,
            'sequence' => 1,
            'step' => 'dashboard',
            'result' => 'running',
            'screenshot' => null,
            'cursor' => null,
            'observed_at' => $now->format(DATE_ATOM),
        ]);
    }

    public function testRecoveryRepairsTerminalEvidenceIdempotentlyBeforeAckAndActiveRelease(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $raw = json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR);
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $now->modify('-10 minutes')->format(DATE_ATOM),
            'expires_at' => $now->modify('+5 minutes')->format(DATE_ATOM),
        ]);
        $state = [
            'run_id' => $runId,
            'account_id' => 1335,
            'status' => 'passed',
            'sequence' => 5,
            'step' => 'console_http',
            'screenshot_url' => null,
            'cursor' => null,
            'observed_at' => $now->format(DATE_ATOM),
            'updated_at' => $now->format(DATE_ATOM),
            'manifest' => $manifest,
        ];
        $redis = $this->createMock(Redis::class);
        $redis->method('lRange')->willReturn([$raw]);
        $redis->method('get')->willReturnCallback(static function (string $key) use ($runId, $state): string|false {
            return match ($key) {
                PregaoQaRunService::lockKey($runId) => false,
                PregaoQaRunService::stateKey($runId) => json_encode($state, JSON_THROW_ON_ERROR),
                default => false,
            };
        });
        $redis->expects(self::once())->method('lRem')
            ->with(PregaoQaRunService::PENDING_KEY, $raw, 0)
            ->willReturn(1);
        $redis->expects(self::once())->method('eval')
            ->with(self::stringContains("redis.call('GET', KEYS[1])"), [PregaoQaRunService::activeKey(1335), $runId], 1)
            ->willReturn(1);
        $repairs = [];

        $recovered = (new PregaoQaRunService($redis, $proof))->recoverPending(
            static function (array $recoveryManifest, array $recoveryState) use (&$repairs): bool {
                $repairs[] = [$recoveryManifest, $recoveryState];
                return true;
            }
        );

        self::assertSame(0, $recovered, 'terminal não volta para a fila');
        self::assertCount(1, $repairs);
        self::assertSame(5, $repairs[0][1]['sequence'], 'recuperação confirma a etapa terminal, não inventa sequência 6');
    }

    public function testRecoveryKeepsTerminalPendingAndActiveUntilEvidenceIsConfirmed(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $raw = json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR);
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $now->modify('-10 minutes')->format(DATE_ATOM),
            'expires_at' => $now->modify('+5 minutes')->format(DATE_ATOM),
        ]);
        $state = [
            'run_id' => $runId,
            'account_id' => 1335,
            'status' => 'passed',
            'sequence' => 5,
            'step' => 'console_http',
            'observed_at' => $now->format(DATE_ATOM),
            'manifest' => $manifest,
        ];
        $redis = $this->createMock(Redis::class);
        $redis->method('lRange')->willReturn([$raw]);
        $redis->method('get')->willReturnCallback(static function (string $key) use ($runId, $state): string|false {
            return match ($key) {
                PregaoQaRunService::lockKey($runId) => false,
                PregaoQaRunService::stateKey($runId) => json_encode($state, JSON_THROW_ON_ERROR),
                default => false,
            };
        });
        $redis->expects(self::never())->method('lRem');
        $redis->expects(self::never())->method('eval');

        $recovered = (new PregaoQaRunService($redis, $proof))->recoverPending(
            static fn (array $recoveryManifest, array $recoveryState): bool => false
        );

        self::assertSame(0, $recovered);
    }

    public function testConfirmEvidenceAtomicallyBindsReceiptToStateAndExactEvent(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $now->modify('-1 minute')->format(DATE_ATOM),
            'expires_at' => $now->modify('+14 minutes')->format(DATE_ATOM),
        ]);
        $status = $proof->signStatus([
            'running' => true,
            'suite' => 'pregao-live',
            'test' => 'dashboard',
            'result' => 'running',
            'video_url' => null,
            'stream_url' => null,
            'run_id' => $runId,
            'sequence' => 1,
            'step' => 'dashboard',
            'screenshot_url' => null,
            'observed_at' => $now->format(DATE_ATOM),
            'started_at' => $manifest['created_at'],
            'manifest_hash' => $manifest['manifest_hash'],
        ], 1335);
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('eval')
            ->with(
                self::logicalAnd(self::stringContains('receipt_event_id'), self::stringContains('SETEX')),
                self::callback(static fn (array $args): bool => array_slice($args, 0, 3) === [
                    PregaoQaRunService::stateKey($runId),
                    PregaoQaRunService::receiptKey($runId),
                    PregaoQaRunService::latestReceiptKey(1335),
                ]),
                3
            )
            ->willReturn(1);

        self::assertTrue((new PregaoQaRunService($redis, $proof))->confirmEvidence(
            $manifest,
            $status,
            41,
            $now->format(DATE_ATOM)
        ));
    }

    public function testConfirmEvidenceRejectsObservationBeyondManifestExpiryBeforeRedis(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => $now->modify('-1 minute')->format(DATE_ATOM),
            'expires_at' => $now->modify('+15 seconds')->format(DATE_ATOM),
        ]);
        $status = $proof->signStatus([
            'running' => true,
            'suite' => 'pregao-live',
            'test' => 'dashboard',
            'result' => 'running',
            'video_url' => null,
            'stream_url' => null,
            'run_id' => $runId,
            'sequence' => 1,
            'step' => 'dashboard',
            'screenshot_url' => null,
            'observed_at' => $now->modify('+30 seconds')->format(DATE_ATOM),
            'started_at' => $manifest['created_at'],
            'manifest_hash' => $manifest['manifest_hash'],
        ], 1335);
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::never())->method('eval');

        self::assertFalse((new PregaoQaRunService($redis, $proof))->confirmEvidence(
            $manifest,
            $status,
            41,
            $now->format(DATE_ATOM)
        ));
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

    public function testWorkerLockTtlHasMarginBeyondRuntimeAndStopTimeout(): void
    {
        self::assertGreaterThan(180 + 15, PregaoQaRunService::LOCK_TTL_SECONDS);
    }

    public function testRenewLockExtendsOnlyTheTokenOwnedLeaseAtomically(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $token = str_repeat('a', 48);
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('eval')
            ->with(
                self::logicalAnd(self::stringContains('GET'), self::stringContains('EXPIRE')),
                [PregaoQaRunService::lockKey($runId), $token, PregaoQaRunService::LOCK_TTL_SECONDS],
                1
            )
            ->willReturn(1);

        $service = new PregaoQaRunService($redis, new PregaoQaProof(str_repeat('k', 32)));
        self::assertTrue($service->renew($runId, $token));
    }

    public function testReleaseDeletesOnlyTheTokenOwnedWorkerLock(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $token = str_repeat('b', 48);
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('eval')
            ->with(
                self::logicalAnd(self::stringContains('GET'), self::stringContains('DEL')),
                [PregaoQaRunService::lockKey($runId), $token],
                1
            )
            ->willReturn(0);

        (new PregaoQaRunService($redis, new PregaoQaProof(str_repeat('k', 32))))->release($runId, $token);
    }

    public function testReleaseActiveCannotClearAnotherRunForTheAccount(): void
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('eval')
            ->with(
                self::logicalAnd(self::stringContains('GET'), self::stringContains('DEL')),
                [PregaoQaRunService::activeKey(1335), $runId],
                1
            )
            ->willReturn(0);

        (new PregaoQaRunService($redis, new PregaoQaProof(str_repeat('k', 32))))->releaseActive(1335, $runId);
    }
}
