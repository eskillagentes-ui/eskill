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
        $terminalState = null;
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('eval')
            ->willReturnCallback(static function (string $lua, array $args, int $keyCount) use (&$terminalState): int {
                self::assertSame(3, $keyCount);
                self::assertStringContainsString("SETEX', KEYS[2]", $lua);
                self::assertSame(PregaoQaRunService::cooldownAccountKey(1335), $args[1]);
                self::assertSame(PregaoQaRunService::cooldownUserKey(77), $args[2]);
                self::assertSame((string) PregaoQaRunService::EVIDENCE_TTL_SECONDS, $args[8]);
                self::assertSame('1', $args[10]);
                self::assertSame((string) PregaoQaRunService::COOLDOWN_TTL_SECONDS, $args[11]);
                $terminalState = json_decode((string) $args[9], true, 512, JSON_THROW_ON_ERROR);
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
                self::callback(static fn (array $args): bool => $args[0] === PregaoQaRunService::stateKey($runId)
                    && $args[1] === PregaoQaRunService::cooldownAccountKey(1335)
                    && $args[2] === PregaoQaRunService::cooldownUserKey(77)
                    && $args[10] === '0'),
                3
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
                    PregaoQaRunService::receiptKey($runId, 1),
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

    public function testTerminalMediaAuthorizationOutlivesActiveManifestButExpiresWithEvidence(): void
    {
        $now = time();
        $clock = $now;
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $records = $this->terminalMediaRecords($proof, $now);
        $service = $this->mediaAuthorizationService($records, $clock, $proof);
        $runId = '123e4567-e89b-42d3-a456-426614174000';

        $clock += PregaoQaRunService::RUN_TTL_SECONDS + 1;
        self::assertTrue($service->isMediaAuthorized($runId, 1335));
        self::assertFalse($service->isMediaAuthorized($runId, 9999));

        $clock = $now + PregaoQaRunService::EVIDENCE_TTL_SECONDS;
        self::assertFalse($service->isMediaAuthorized($runId, 1335));
    }

    public function testTerminalMediaAuthorizationRequiresSignedRetainedManifestAndExactReceipt(): void
    {
        $now = time();
        $clock = $now + PregaoQaRunService::RUN_TTL_SECONDS + 1;
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $runId = '123e4567-e89b-42d3-a456-426614174000';

        $records = $this->terminalMediaRecords($proof, $now);
        unset($records[PregaoQaRunService::receiptKey($runId, 5)]);
        self::assertFalse($this->mediaAuthorizationService($records, $clock, $proof)->isMediaAuthorized($runId, 1335));

        $records = $this->terminalMediaRecords($proof, $now);
        $receiptKey = PregaoQaRunService::receiptKey($runId, 5);
        $records[$receiptKey]['value']['event_id'] = 42;
        self::assertFalse($this->mediaAuthorizationService($records, $clock, $proof)->isMediaAuthorized($runId, 1335));

        $records = $this->terminalMediaRecords($proof, $now);
        $stateKey = PregaoQaRunService::stateKey($runId);
        $records[$stateKey]['value']['manifest']['signature'] = str_repeat('0', 64);
        self::assertFalse($this->mediaAuthorizationService($records, $clock, $proof)->isMediaAuthorized($runId, 1335));
    }

    public function testNonTerminalMediaAuthorizationStillRequiresActiveManifest(): void
    {
        $now = time();
        $clock = $now;
        $proof = new PregaoQaProof(str_repeat('k', 32));
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => gmdate(DATE_ATOM, $now - 60),
            'expires_at' => gmdate(DATE_ATOM, $now + PregaoQaRunService::RUN_TTL_SECONDS - 60),
        ]);
        $records = [
            PregaoQaRunService::manifestKey($runId) => [
                'expires_at' => $now + PregaoQaRunService::RUN_TTL_SECONDS,
                'value' => $manifest,
            ],
            PregaoQaRunService::stateKey($runId) => [
                'expires_at' => $now + PregaoQaRunService::EVIDENCE_TTL_SECONDS,
                'value' => [
                    'run_id' => $runId,
                    'account_id' => 1335,
                    'manifest_hash' => $manifest['manifest_hash'],
                    'status' => 'running',
                    'sequence' => 1,
                ],
            ],
        ];
        $service = $this->mediaAuthorizationService($records, $clock, $proof);

        self::assertTrue($service->isMediaAuthorized($runId, 1335));
        $clock += PregaoQaRunService::RUN_TTL_SECONDS + 1;
        self::assertFalse($service->isMediaAuthorized($runId, 1335));
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

    /**
     * @param array<string,array{expires_at:int,value:array<string,mixed>}> $records
     */
    private function mediaAuthorizationService(
        array &$records,
        int &$clock,
        PregaoQaProof $proof
    ): PregaoQaRunService {
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturnCallback(
            static function (string $key) use (&$records, &$clock): string|false {
                $record = $records[$key] ?? null;
                if (!is_array($record) || $record['expires_at'] <= $clock) {
                    return false;
                }
                return json_encode($record['value'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        );
        return new PregaoQaRunService($redis, $proof);
    }

    /**
     * @return array<string,array{expires_at:int,value:array<string,mixed>}>
     */
    private function terminalMediaRecords(PregaoQaProof $proof, int $now): array
    {
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $manifest = $proof->signManifest([
            'run_id' => $runId,
            'account_id' => 1335,
            'user_id' => 77,
            'created_at' => gmdate(DATE_ATOM, $now - 60),
            'expires_at' => gmdate(DATE_ATOM, $now + PregaoQaRunService::RUN_TTL_SECONDS - 60),
        ]);
        $status = $proof->signStatus([
            'running' => false,
            'suite' => 'pregao-live',
            'test' => 'console_http',
            'result' => 'passed',
            'video_url' => null,
            'stream_url' => '/qa/live/' . $runId,
            'run_id' => $runId,
            'sequence' => 5,
            'step' => 'console_http',
            'screenshot_url' => '/qa/frame/' . $runId,
            'observed_at' => gmdate(DATE_ATOM, $now),
            'started_at' => $manifest['created_at'],
            'manifest_hash' => $manifest['manifest_hash'],
        ], 1335);
        $payloadHash = PregaoQaRunService::statusHash($status);
        $eventId = 41;
        $expiresAt = $now + PregaoQaRunService::EVIDENCE_TTL_SECONDS;
        return [
            PregaoQaRunService::manifestKey($runId) => [
                'expires_at' => $now + PregaoQaRunService::RUN_TTL_SECONDS,
                'value' => $manifest,
            ],
            PregaoQaRunService::stateKey($runId) => [
                'expires_at' => $expiresAt,
                'value' => [
                    'run_id' => $runId,
                    'account_id' => 1335,
                    'manifest_hash' => $manifest['manifest_hash'],
                    'status' => 'passed',
                    'sequence' => 5,
                    'step' => 'console_http',
                    'screenshot_url' => '/qa/frame/' . $runId,
                    'cursor' => null,
                    'observed_at' => $status['observed_at'],
                    'updated_at' => $status['observed_at'],
                    'manifest' => $manifest,
                    'receipt_hash' => $payloadHash,
                    'receipt_event_id' => $eventId,
                ],
            ],
            PregaoQaRunService::receiptKey($runId, 5) => [
                'expires_at' => $expiresAt,
                'value' => [
                    'run_id' => $runId,
                    'account_id' => 1335,
                    'manifest_hash' => $manifest['manifest_hash'],
                    'manifest_expires_at' => $manifest['expires_at'],
                    'sequence' => 5,
                    'step' => 'console_http',
                    'result' => 'passed',
                    'observed_at' => $status['observed_at'],
                    'payload_hash' => $payloadHash,
                    'event_id' => $eventId,
                    'event_ts' => $status['observed_at'],
                    'status' => $status,
                ],
            ],
            PregaoQaRunService::latestReceiptKey(1335) => [
                'expires_at' => $expiresAt,
                'value' => [
                    'run_id' => $runId,
                    'sequence' => 5,
                    'event_id' => $eventId,
                    'payload_hash' => $payloadHash,
                    'status_signature' => $status['signature'],
                ],
            ],
        ];
    }
}
